#!/usr/bin/env bash
# =============================================================================
# deploy.sh - Instala o actualiza Contact Center en Linux.
#
# Stack: Nginx + PHP-FPM (>= 8.2) + MariaDB + Node (solo para compilar el SPA).
# Distros: Rocky / AlmaLinux / RHEL / CentOS Stream 8-10, Fedora,
#          Debian 11+ y Ubuntu 22.04+.
#
# Instalación en una línea (lo primero que hace es clonar el repositorio):
#   curl -fsSL https://raw.githubusercontent.com/henry0295/contact-center/main/deploy.sh | sudo bash
#
# Otras formas:
#   sudo bash deploy.sh                          # install con la IP detectada
#   sudo bash deploy.sh install --host 192.168.101.95 --https self-signed
#   sudo bash deploy.sh install --host cc.midominio.com --https letsencrypt --email yo@midominio.com
#   curl -fsSL <url>/deploy.sh | sudo bash -s -- --https self-signed
#   sudo /var/www/contact-center/deploy.sh update
#
# "install" es idempotente: se puede repetir sin perder datos (.env, BD,
# uploads). Si la carpeta ya tenía una instalación sin git, se mueve a
# <carpeta>.bak-<fecha> y se conservan su .env, storage/ y public/uploads/.
# "update" hace git pull, reinstala dependencias, aplica el esquema,
# recompila el frontend y recarga los servicios.
# =============================================================================
set -Eeuo pipefail

# Todo el script va dentro de cc_main(): con "curl ... | sudo bash" bash lo
# lee completo antes de ejecutar nada, y los comandos hijos (ssh, apt, npm)
# no pueden consumir por error el resto del script desde stdin.
cc_main() {
# Argumentos originales: si git pull trae un deploy.sh nuevo, se relanza con ellos.
CC_ORIG_ARGS=("$@")

# ----------------------------------------------------------------- defaults --
APP_USER=contactcenter
APP_DIR=/var/www/contact-center
REPO_URL="https://github.com/henry0295/contact-center.git"
BRANCH="main"
HOST=""
HTTPS_MODE=none          # none | self-signed | letsencrypt
LE_EMAIL=""
TIMEZONE=""
ADMIN_EMAIL=""
SKIP_FIREWALL=0
NODE_MAJOR=22
# API de voz (TTS + Asterisk). deploy.sh crea el usuario de esta instalación
# y guarda su API key; pide la clave de administrador de esa API (no la guarda).
VOICE_URL="http://indoor-qvmdkkckkpc.dynamic-m.com:8000"
VOICE_URL_SET=0
SKIP_VOICE=0
VOICE_STATUS="sin configurar"
# Proveedor de SMS (IT Cloud). Credenciales por cliente: SMS_EMAIL/SMS_TOKEN
# como variables de entorno o se piden por consola.
SKIP_SMS=0
SMS_STATUS="sin configurar"
# phpMyAdmin en /phpmyadmin/ (se instala por defecto). PMA_ALLOW: lista de
# IPs/CIDR separadas por comas que pueden abrirlo (vacío = cualquiera, con
# autenticación básica + login de phpMyAdmin).
SKIP_PMA=0
PMA_ALLOW=""
CONF_FILE=/etc/contact-center/deploy.conf
BACKUP_DIR=/var/lib/contact-center/backups

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m    %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[aviso] %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31m[error] %s\033[0m\n' "$*" >&2; exit 1; }
trap 'die "Falló la línea $LINENO: $BASH_COMMAND"' ERR

usage() {
  if [[ -f "${BASH_SOURCE[0]:-}" ]]; then
    sed -n '3,/^# ====/p' "${BASH_SOURCE[0]}" | sed '$d; s/^# \{0,1\}//'
  fi
  cat <<'EOF'
Opciones:
  --host <IP|dominio>     Dirección del sitio (por defecto, la IP principal del servidor).
  --https <modo>          none (por defecto) | self-signed | letsencrypt.
  --email <correo>        Correo para Let's Encrypt (obligatorio con letsencrypt).
  --repo <url>            Repositorio git (por defecto git@github.com:henry0295/contact-center.git).
                          Con una URL https:// pública no hace falta deploy key.
  --branch <rama>         Rama a clonar/actualizar (por defecto main).
  --dir <ruta>            Carpeta de la aplicación (por defecto /var/www/contact-center).
  --timezone <zona>       Zona horaria del sistema, p.ej. America/Bogota.
  --admin-email <correo>  Correo del administrador inicial.
  --no-firewall           No tocar firewalld/ufw.
  --voice-url <url>       API de voz (por defecto http://indoor-qvmdkkckkpc.dynamic-m.com:8000).
  --no-voice              No crear el usuario/token de la API de voz.
  --no-sms                No configurar el proveedor de SMS.
  --no-phpmyadmin         No instalar phpMyAdmin (o retirarlo; --phpmyadmin lo reactiva).
  --pma-allow <ips>       Solo estas IPs/CIDR pueden abrir /phpmyadmin/ (ej. 192.168.101.0/24).
                          Por defecto: solo redes privadas. "any" = cualquier IP.
  Variable VOICE_ADMIN_KEY: clave de administrador de la API de voz (si no
  se da, se pide por consola; Enter la omite).
  Variables SMS_EMAIL y SMS_TOKEN: cuenta del cliente en el proveedor de SMS
  (IT Cloud / contacto-virtual.com). Si no se dan, se piden por consola.
EOF
}

# ------------------------------------------------- re-ejecución desde /tmp ---
# git pull puede reescribir este archivo mientras bash lo lee: se ejecuta una
# copia en /tmp.
# (Si llega por tubería, "curl ... | sudo bash", no hay archivo que copiar ni
# riesgo: bash ya lo está leyendo de stdin.)
if [[ -z "${CC_DEPLOY_REEXEC:-}" && -f "${BASH_SOURCE[0]:-}" ]]; then
  TMP_SELF="$(mktemp /tmp/cc-deploy.XXXXXX)"
  cp "${BASH_SOURCE[0]}" "$TMP_SELF"
  CC_DEPLOY_REEXEC=1 exec bash "$TMP_SELF" "$@"
fi
if [[ -n "${CC_DEPLOY_REEXEC:-}" ]]; then
  SELF_TMP="${BASH_SOURCE[0]}"
  trap 'rm -f "$SELF_TMP"' EXIT
fi
# sudo en RHEL no incluye /usr/local/bin (Node y Composer se instalan ahí).
export PATH="/usr/local/bin:$PATH"

# --------------------------------------------------------------- argumentos --
# Sin comando = install (así basta con "sudo bash deploy.sh").
CMD=install
if [[ $# -gt 0 && "$1" != --* && "$1" != -h ]]; then CMD="$1"; shift; fi
case "$CMD" in
  install|update) ;;
  help) usage; exit 0 ;;
  *) usage; die "Comando desconocido: $CMD" ;;
esac

for _a in "$@"; do
  if [[ "$_a" == -h || "$_a" == --help ]]; then usage; exit 0; fi
done
[[ $EUID -eq 0 ]] || die "Ejecuta el script con sudo o como root."

# Ajustes guardados por una instalación previa (update los reutiliza).
# shellcheck disable=SC1090
[[ -r "$CONF_FILE" ]] && . "$CONF_FILE"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --host)        HOST="${2:?Falta el valor de --host}"; shift 2 ;;
    --https)       HTTPS_MODE="${2:?Falta el valor de --https}"; shift 2 ;;
    --email)       LE_EMAIL="${2:?Falta el valor de --email}"; shift 2 ;;
    --repo)        REPO_URL="${2:?Falta el valor de --repo}"; shift 2 ;;
    --branch)      BRANCH="${2:?Falta el valor de --branch}"; shift 2 ;;
    --dir)         APP_DIR="${2:?Falta el valor de --dir}"; shift 2 ;;
    --timezone)    TIMEZONE="${2:?Falta el valor de --timezone}"; shift 2 ;;
    --admin-email) ADMIN_EMAIL="${2:?Falta el valor de --admin-email}"; shift 2 ;;
    --no-firewall) SKIP_FIREWALL=1; shift ;;
    --voice-url)   VOICE_URL="${2:?Falta el valor de --voice-url}"; VOICE_URL_SET=1; shift 2 ;;
    --no-voice)    SKIP_VOICE=1; shift ;;
    --no-sms)      SKIP_SMS=1; shift ;;
    --no-phpmyadmin) SKIP_PMA=1; shift ;;
    --phpmyadmin)  SKIP_PMA=0; shift ;;
    --pma-allow)   PMA_ALLOW="${2:?Falta el valor de --pma-allow}"; shift 2 ;;
    -h|--help)     usage; exit 0 ;;
    *) die "Opción desconocida: $1" ;;
  esac
done

# Validaciones (los valores acaban en configuraciones de Nginx y en sed).
[[ "$APP_DIR" =~ ^/[A-Za-z0-9._/-]+$ && "$APP_DIR" != "/" ]] || die "--dir no válido: $APP_DIR"
APP_DIR="${APP_DIR%/}"
[[ "$HTTPS_MODE" =~ ^(none|self-signed|letsencrypt)$ ]] || die "--https debe ser none, self-signed o letsencrypt"
if [[ "$CMD" == install && -z "$HOST" ]]; then
  # Sin --host: IP principal del servidor (la de la ruta por defecto).
  HOST="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<NF; i++) if ($i == "src") {print $(i+1); exit}}')"
  [[ -n "$HOST" ]] || HOST="$(hostname -I 2>/dev/null | awk '{print $1}')"
  [[ -n "$HOST" ]] || die "No se pudo detectar la IP del servidor; usa --host <IP o dominio>"
  printf 'Usando la IP detectada: %s (cámbiala con --host)\n' "$HOST"
fi
if [[ -n "$HOST" ]]; then
  [[ "$HOST" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || die "--host no válido: $HOST"
fi
is_ip() { [[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; }
EMAIL_RE='^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
if [[ "$HTTPS_MODE" == letsencrypt ]]; then
  if is_ip "$HOST"; then die "Let's Encrypt no emite certificados para IPs; usa --https self-signed"; fi
  [[ "$LE_EMAIL" =~ $EMAIL_RE ]] || die "--email no válido"
fi
if [[ -n "$ADMIN_EMAIL" ]]; then
  [[ "$ADMIN_EMAIL" =~ $EMAIL_RE ]] || die "--admin-email no válido"
fi
[[ "$REPO_URL" != *"'"* && "$BRANCH" != *"'"* ]] || die "--repo/--branch no pueden contener comillas"
[[ -z "$BRANCH" || "$BRANCH" =~ ^[A-Za-z0-9._/-]+$ ]] || die "--branch no válido"
if [[ -n "$PMA_ALLOW" && "$PMA_ALLOW" != any && ! "$PMA_ALLOW" =~ ^[0-9./:a-fA-F]+(,[0-9./:a-fA-F]+)*$ ]]; then
  die "--pma-allow no válido (ej. 192.168.101.0/24,10.0.0.5): $PMA_ALLOW"
fi
VOICE_URL="${VOICE_URL%/}"
[[ "$VOICE_URL" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$ ]] || die "--voice-url no válida: $VOICE_URL"

# ------------------------------------------------------------ utilidades -----
as_app() { runuser -l "$APP_USER" -c "$1"; }
version_ge() { printf '%s\n%s\n' "$2" "$1" | sort -V -C; }   # $1 >= $2
selinux_on() { command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; }

set_env() {   # set_env CLAVE VALOR  (crea o reemplaza la línea en .env)
  local file="$APP_DIR/.env" key="$1" val="$2"
  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$file"
  else
    printf '%s=%s\n' "$key" "$val" >> "$file"
  fi
}
ensure_env() {  # añade la clave solo si no existe
  grep -q "^$1=" "$APP_DIR/.env" || printf '%s=%s\n' "$1" "$2" >> "$APP_DIR/.env"
}

# ------------------------------------------------------------- detección -----
detect_os() {
  [[ -r /etc/os-release ]] || die "No se encuentra /etc/os-release"
  # shellcheck disable=SC1091
  . /etc/os-release
  OS_ID="${ID:-linux}"
  OS_VER="${VERSION_ID:-0}"
  OS_CODENAME="${VERSION_CODENAME:-}"
  if command -v dnf >/dev/null 2>&1; then
    FAMILY=rhel
    WEB_USER=nginx
    EL_MAJOR="${OS_VER%%.*}"
    PHP_BIN=/usr/bin/php
    PHP_FPM_SERVICE=php-fpm
    PHP_SOCK=/run/php-fpm/www.sock
  elif command -v apt-get >/dev/null 2>&1; then
    FAMILY=debian
    WEB_USER=www-data
  else
    die "Distribución no soportada (se necesita dnf o apt-get)."
  fi
  ok "Sistema: ${PRETTY_NAME:-$OS_ID $OS_VER} (familia $FAMILY)"
}

# -------------------------------------------------------------- paquetes -----
install_packages_rhel() {
  log "Instalando paquetes con dnf"
  dnf -y install dnf-plugins-core >/dev/null 2>&1 || true
  dnf -y install nginx mariadb-server mariadb git unzip tar xz curl rsync openssl cronie logrotate
  selinux_on && dnf -y install policycoreutils-python-utils

  # PHP >= 8.2: AppStream (module stream) o, si no existe, Remi.
  if [[ "$OS_ID" != fedora ]] && dnf -q module list php >/dev/null 2>&1; then
    local streams pick=""
    streams="$(dnf -q module list php 2>/dev/null | awk '$1=="php"{print $2}')"
    for s in 8.4 8.3 8.2; do
      if grep -qx "$s" <<<"$streams"; then pick="$s"; break; fi
    done
    if [[ -z "$pick" ]]; then
      log "AppStream no ofrece PHP >= 8.2: se añade el repositorio Remi"
      dnf -y install epel-release 2>/dev/null \
        || dnf -y install "https://dl.fedoraproject.org/pub/epel/epel-release-latest-${EL_MAJOR}.noarch.rpm"
      dnf -y install "https://rpms.remirepo.net/enterprise/remi-release-${EL_MAJOR}.rpm"
      pick="remi-8.3"
    fi
    dnf -y module reset php
    dnf -y module enable "php:${pick}"
  fi
  dnf -y install php-fpm php-cli php-mysqlnd php-mbstring php-xml php-opcache php-pdo php-process
  # Si había un PHP más viejo instalado, lo lleva a la versión del stream.
  if ! version_ge "$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" 8.2; then
    dnf -y distro-sync 'php*'
  fi
}

install_packages_debian() {
  log "Instalando paquetes con apt"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y ca-certificates curl gnupg git unzip tar xz-utils rsync openssl cron logrotate \
    nginx mariadb-server mariadb-client

  local cand
  cand="$(apt-cache policy php-cli 2>/dev/null | awk '/Candidate:/{print $2}' | sed -E 's/^[0-9]+://; s/\+.*//')"
  if [[ -n "$cand" && "$cand" != "(none)" ]] && version_ge "$cand" 8.2; then
    PHPV="$cand"
  else
    PHPV=8.3
    log "La distro trae PHP ${cand:-desconocido}: se añade un repositorio con PHP $PHPV"
    if [[ "$OS_ID" == ubuntu ]]; then
      apt-get install -y software-properties-common
      add-apt-repository -y ppa:ondrej/php
    else
      [[ -n "$OS_CODENAME" ]] || die "No se pudo determinar el codename de Debian"
      curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/deb.sury.org-php.gpg
      echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${OS_CODENAME} main" \
        > /etc/apt/sources.list.d/sury-php.list
    fi
    apt-get update -y
  fi
  apt-get install -y "php${PHPV}-fpm" "php${PHPV}-cli" "php${PHPV}-mysql" "php${PHPV}-mbstring" \
    "php${PHPV}-xml" "php${PHPV}-curl" "php${PHPV}-zip" "php${PHPV}-opcache"
  PHP_BIN="/usr/bin/php${PHPV}"
  PHP_FPM_SERVICE="php${PHPV}-fpm"
  PHP_SOCK="/run/php/php${PHPV}-fpm.sock"
  update-alternatives --set php "$PHP_BIN" >/dev/null 2>&1 || true
}

# Resuelve PHP_BIN/servicio/socket en "update" sin reinstalar paquetes.
resolve_php_debian() {
  local v
  for v in 8.4 8.3 8.2; do
    if [[ -x "/usr/bin/php$v" && -e "/lib/systemd/system/php$v-fpm.service" ]]; then
      PHPV="$v"; PHP_BIN="/usr/bin/php$v"; PHP_FPM_SERVICE="php$v-fpm"; PHP_SOCK="/run/php/php$v-fpm.sock"
      return
    fi
  done
  die "No se encontró PHP-FPM >= 8.2; ejecuta primero: sudo ./deploy.sh install --host <IP>"
}

ensure_node() {
  if command -v node >/dev/null 2>&1; then
    local major
    major="$(node -p 'process.versions.node.split(".")[0]')"
    if (( major >= 18 )); then ok "Node $(node -v) ya instalado"; return; fi
  fi
  log "Instalando Node.js ${NODE_MAJOR} (binarios oficiales en /usr/local/lib/nodejs)"
  local arch tmp file base="https://nodejs.org/dist/latest-v${NODE_MAJOR}.x"
  case "$(uname -m)" in
    x86_64) arch=x64 ;;
    aarch64|arm64) arch=arm64 ;;
    *) die "Arquitectura no soportada para Node: $(uname -m)" ;;
  esac
  tmp="$(mktemp -d)"
  curl -fsSL "$base/SHASUMS256.txt" -o "$tmp/SHASUMS256.txt"
  file="$(awk -v suf="-linux-${arch}.tar.xz" 'substr($2, length($2)-length(suf)+1) == suf {print $2; exit}' "$tmp/SHASUMS256.txt")"
  [[ -n "$file" ]] || die "No se encontró el binario de Node para linux-${arch}"
  curl -fsSL "$base/$file" -o "$tmp/$file"
  (cd "$tmp" && grep " ${file}\$" SHASUMS256.txt | sha256sum -c - >/dev/null) || die "Checksum de Node incorrecto"
  rm -rf /usr/local/lib/nodejs
  mkdir -p /usr/local/lib/nodejs
  tar -xJf "$tmp/$file" -C /usr/local/lib/nodejs --strip-components=1
  ln -sf /usr/local/lib/nodejs/bin/node /usr/local/lib/nodejs/bin/npm /usr/local/lib/nodejs/bin/npx /usr/local/bin/
  rm -rf "$tmp"
  hash -r
  ok "Node $(/usr/local/bin/node -v)"
}

ensure_composer() {
  COMPOSER_BIN="$(command -v composer 2>/dev/null || true)"
  if [[ -n "$COMPOSER_BIN" ]]; then ok "Composer ya instalado ($COMPOSER_BIN)"; return; fi
  log "Instalando Composer"
  local tmp expected actual
  tmp="$(mktemp -d)"
  curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
  expected="$(curl -fsSL https://composer.github.io/installer.sig)"
  actual="$("$PHP_BIN" -r "echo hash_file('sha384', '$tmp/composer-setup.php');")"
  [[ "$expected" == "$actual" ]] || die "Firma del instalador de Composer incorrecta"
  "$PHP_BIN" "$tmp/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
  rm -rf "$tmp"
  COMPOSER_BIN=/usr/local/bin/composer
}

# ------------------------------------------------------- zona horaria/PHP ----
configure_timezone() {
  if [[ -n "$TIMEZONE" ]]; then
    timedatectl set-timezone "$TIMEZONE" || die "Zona horaria no válida: $TIMEZONE"
  fi
  SYS_TZ="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
  if [[ -z "$SYS_TZ" ]]; then
    SYS_TZ="$(readlink -f /etc/localtime 2>/dev/null | sed -n 's|.*/zoneinfo/||p')"
  fi
  SYS_TZ="${SYS_TZ:-UTC}"
  ok "Zona horaria: $SYS_TZ (PHP y MariaDB deben coincidir)"
}

configure_php() {
  log "Configurando PHP y PHP-FPM"
  local ini
  ini="$(cat <<EOF
; Gestionado por deploy.sh (Contact Center)
expose_php = Off
display_errors = Off
log_errors = On
date.timezone = ${SYS_TZ}
memory_limit = 256M
upload_max_filesize = 8M
post_max_size = 20M
max_file_uploads = 20
allow_url_fopen = On
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 60
EOF
)"
  if [[ "$FAMILY" == rhel ]]; then
    printf '%s\n' "$ini" > /etc/php.d/99-contact-center.ini
    # El pool www corre como apache por defecto: se pasa a nginx.
    sed -i -e 's/^user = apache/user = nginx/' -e 's/^group = apache/group = nginx/' /etc/php-fpm.d/www.conf
    chgrp -R nginx /var/lib/php/session /var/lib/php/wsdlcache /var/lib/php/opcache 2>/dev/null || true
  else
    printf '%s\n' "$ini" > "/etc/php/${PHPV}/fpm/conf.d/99-contact-center.ini"
    printf '%s\n' "$ini" > "/etc/php/${PHPV}/cli/conf.d/99-contact-center.ini"
  fi
  systemctl enable "$PHP_FPM_SERVICE" >/dev/null
  systemctl restart "$PHP_FPM_SERVICE"
  ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') ($PHP_FPM_SERVICE)"
}

# ------------------------------------------------------- usuario y código ----
setup_user_and_dir() {
  log "Permisos de $APP_DIR para $APP_USER y $WEB_USER"
  id "$APP_USER" >/dev/null 2>&1 || useradd -m -s /bin/bash "$APP_USER"
  usermod -aG "$WEB_USER" "$APP_USER"
  chown -R "$APP_USER:$WEB_USER" "$APP_DIR"
  chmod 750 "$APP_DIR"
}

# Paso 0 de install: git + ssh y el usuario de la app, antes de cualquier
# otra cosa, para poder clonar.
bootstrap_git() {
  log "Paso 1: git, ssh y usuario $APP_USER"
  if [[ "$FAMILY" == rhel ]]; then
    dnf -y install git openssh-clients curl tar rsync
  else
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y git openssh-client curl ca-certificates tar rsync
  fi
  id "$APP_USER" >/dev/null 2>&1 || useradd -m -s /bin/bash "$APP_USER"
}

is_ssh_url() { [[ "$REPO_URL" == git@* || "$REPO_URL" == ssh://* ]]; }

# Huella oficial de la clave ed25519 de github.com (docs.github.com,
# "GitHub's SSH key fingerprints").
GITHUB_ED25519_FP="SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU"

# Deploy key SSH para el usuario de la app (repos privados).
ensure_repo_access() {
  if as_app "GIT_TERMINAL_PROMPT=0 git ls-remote '$REPO_URL' >/dev/null 2>&1"; then
    ok "Acceso al repositorio OK ($REPO_URL)"
    return 0
  fi
  if ! is_ssh_url; then
    # Repo privado en GitHub: se pasa a SSH con deploy key.
    if [[ "$REPO_URL" =~ ^https://github\.com/([A-Za-z0-9._-]+/[A-Za-z0-9._-]+)$ ]]; then
      REPO_URL="git@github.com:${BASH_REMATCH[1]%.git}.git"
      warn "No hay acceso público al repositorio; se usará SSH con deploy key ($REPO_URL)"
    else
      die "No se puede leer $REPO_URL (¿URL correcta? ¿repo privado?). Para privados usa git@github.com:..."
    fi
  fi

  local home key known
  home="$(getent passwd "$APP_USER" | cut -d: -f6)"
  key="$home/.ssh/id_ed25519"
  known="$home/.ssh/known_hosts"
  install -d -m 700 -o "$APP_USER" -g "$APP_USER" "$home/.ssh"

  if [[ "$REPO_URL" == *github.com* ]] && ! grep -q '^github.com ' "$known" 2>/dev/null; then
    local tmpk
    tmpk="$(mktemp)"
    ssh-keyscan -t ed25519 github.com > "$tmpk" 2>/dev/null
    [[ "$(ssh-keygen -lf "$tmpk" | awk '{print $2}')" == "$GITHUB_ED25519_FP" ]] \
      || { rm -f "$tmpk"; die "La huella SSH de github.com no coincide con la oficial; se aborta."; }
    cat "$tmpk" >> "$known"
    rm -f "$tmpk"
    chown "$APP_USER:$APP_USER" "$known"
    chmod 644 "$known"
  fi

  if [[ ! -f "$key" ]]; then
    as_app "ssh-keygen -q -t ed25519 -N '' -C 'contact-center deploy $(hostname)' -f ~/.ssh/id_ed25519"
  fi

  local repo_path="${REPO_URL#*github.com}"
  repo_path="${repo_path#[:/]}"
  repo_path="${repo_path%.git}"
  while true; do
    cat <<EOF

-----------------------------------------------------------------------------
 El repositorio es privado: agrega esta clave como *Deploy key* (solo lectura)
   https://github.com/${repo_path}/settings/keys/new
   Title: $(hostname)   |   NO marques "Allow write access"

$(cat "$key.pub")
-----------------------------------------------------------------------------
EOF
    if ! { : </dev/tty; } 2>/dev/null; then
      die "Agrega la deploy key y vuelve a ejecutar el script."
    fi
    read -r -p "Pulsa Enter cuando la hayas agregado (o Ctrl+C para salir)... " _ </dev/tty
    if as_app "GIT_TERMINAL_PROMPT=0 git ls-remote '$REPO_URL' >/dev/null 2>&1"; then
      ok "Acceso al repositorio OK"
      return 0
    fi
    warn "Todavía no hay acceso. Revisa que la clave esté en el repo correcto."
  done
}

# Si git pull trajo un deploy.sh distinto del que se está ejecutando, se
# relanza el nuevo: si no, los pasos nuevos (p.ej. la API de voz) no se
# ejecutarían hasta el siguiente update. Una sola vez (CC_DEPLOY_RELAUNCHED).
reexec_if_script_changed() {
  [[ -z "${CC_DEPLOY_RELAUNCHED:-}" && -n "${SELF_TMP:-}" && -f "$SELF_TMP" ]] || return 0
  [[ -f "$APP_DIR/deploy.sh" ]] || return 0
  if ! cmp -s "$SELF_TMP" "$APP_DIR/deploy.sh"; then
    log "deploy.sh se actualizó con git pull: se relanza la versión nueva"
    rm -f "$SELF_TMP"
    exec env -u CC_DEPLOY_REEXEC CC_DEPLOY_RELAUNCHED=1 bash "$APP_DIR/deploy.sh" "${CC_ORIG_ARGS[@]}"
  fi
}

# Mueve una instalación previa sin git y conserva sus datos.
OLD_INSTALL=""
move_aside_old_install() {
  OLD_INSTALL="${APP_DIR}.bak-$(date +%Y%m%d-%H%M%S)"
  warn "$APP_DIR existe y no es un repositorio git: se mueve a $OLD_INSTALL"
  mv "$APP_DIR" "$OLD_INSTALL"
}

restore_old_data() {
  [[ -n "$OLD_INSTALL" ]] || return 0
  log "Recuperando .env, storage/ y public/uploads/ de $OLD_INSTALL"
  if [[ -f "$OLD_INSTALL/.env" ]]; then cp -a "$OLD_INSTALL/.env" "$APP_DIR/.env"; ok ".env conservado"; fi
  if [[ -d "$OLD_INSTALL/storage" ]]; then rsync -a "$OLD_INSTALL/storage/" "$APP_DIR/storage/"; fi
  if [[ -d "$OLD_INSTALL/public/uploads" ]]; then rsync -a "$OLD_INSTALL/public/uploads/" "$APP_DIR/public/uploads/"; fi
  ok "Cuando todo funcione puedes borrar: rm -rf $OLD_INSTALL"
}

fetch_code() {
  log "Paso 2: código desde $REPO_URL (rama $BRANCH)"
  if [[ -d "$APP_DIR/.git" ]]; then
    chown -R "$APP_USER" "$APP_DIR"
    ensure_repo_access
    as_app "git -C '$APP_DIR' fetch --prune origin && git -C '$APP_DIR' checkout '$BRANCH' && git -C '$APP_DIR' pull --ff-only origin '$BRANCH'"
  else
    ensure_repo_access
    if [[ -e "$APP_DIR" && -n "$(ls -A "$APP_DIR" 2>/dev/null)" ]]; then
      move_aside_old_install
    fi
    mkdir -p "$APP_DIR"
    chown "$APP_USER" "$APP_DIR"
    as_app "git clone --branch '$BRANCH' '$REPO_URL' '$APP_DIR'"
    restore_old_data
  fi
  [[ -f "$APP_DIR/composer.json" ]] || die "$APP_DIR no contiene el proyecto (falta composer.json)"
  ok "git: $(as_app "git -C '$APP_DIR' log -1 --format='%h %s'")"
}

install_php_deps() {
  log "Dependencias PHP (composer install --no-dev)"
  chown -R "$APP_USER:$WEB_USER" "$APP_DIR"
  as_app "cd '$APP_DIR' && '$PHP_BIN' '$COMPOSER_BIN' install --no-dev --optimize-autoloader --no-interaction"
}

# --------------------------------------------------------------------- .env --
configure_env() {
  log "Configurando .env"
  local scheme=http
  [[ "$HTTPS_MODE" != none ]] && scheme=https
  if [[ ! -f "$APP_DIR/.env" ]]; then
    install -o "$APP_USER" -g "$WEB_USER" -m 640 "$APP_DIR/.env.example" "$APP_DIR/.env"
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env MAIL_FROM_NAME '"Contact Center"'
    ok ".env creado desde .env.example"
  fi
  # Mismo origen: Nginx pasa /api/ a PHP-FPM. Nunca una URL absoluta.
  set_env VITE_API_URL /api
  # Sesiones más cortas: si siguen con los valores por defecto antiguos
  # (24 h / 14 días) se pasan a 2 h / 7 días. Un valor personalizado se respeta.
  if grep -qx 'JWT_EXPIRY=86400' "$APP_DIR/.env"; then set_env JWT_EXPIRY 7200; fi
  if grep -qx 'JWT_REFRESH_EXPIRY=1209600' "$APP_DIR/.env"; then set_env JWT_REFRESH_EXPIRY 604800; fi
  if [[ -n "$HOST" ]]; then
    set_env APP_URL "${scheme}://${HOST}"
    set_env CORS_ALLOWED_ORIGIN "${scheme}://${HOST}"
  fi
  [[ -n "$ADMIN_EMAIL" ]] && set_env ADMIN_EMAIL "$ADMIN_EMAIL"
  ensure_env VOICE_API_URL "$VOICE_URL"
  ensure_env VOICE_API_KEY ""
  ensure_env VOICE_CALL_THROTTLE_MS 1500
  # Si quedó vacía (instalaciones previas), se pone la del servidor de voz.
  if [[ -z "$(grep -E '^VOICE_API_URL=' "$APP_DIR/.env" | cut -d= -f2-)" ]]; then
    set_env VOICE_API_URL "$VOICE_URL"
  fi
  if [[ "$VOICE_URL_SET" == 1 ]]; then set_env VOICE_API_URL "$VOICE_URL"; fi
  # Secreto de la URL donde el proveedor de SMS entrega las respuestas (doble vía).
  ensure_env SMS_INBOUND_SECRET ""
  if [[ -z "$(grep -E '^SMS_INBOUND_SECRET=' "$APP_DIR/.env" | cut -d= -f2-)" ]]; then
    set_env SMS_INBOUND_SECRET "$(openssl rand -hex 24)"
  fi

  chown "$APP_USER:$WEB_USER" "$APP_DIR/.env"
  chmod 640 "$APP_DIR/.env"
  # Rellena JWT_SECRET, ADMIN_PASSWORD y DB_PASSWORD si faltan o son débiles.
  as_app "cd '$APP_DIR' && '$PHP_BIN' bin/generate-secrets.php"
  if [[ -f "$APP_DIR/.env.bak" ]]; then shred -u "$APP_DIR/.env.bak" 2>/dev/null || rm -f "$APP_DIR/.env.bak"; fi
  grep -E '^(APP_URL|CORS_ALLOWED_ORIGIN|VITE_API_URL|ADMIN_EMAIL)=' "$APP_DIR/.env" | sed 's/^/    /'
}

# ------------------------------------------------------------ API de voz ----
# Ejecuta bin/voice-setup.php como el usuario de la app. La clave de
# administrador viaja en un archivo temporal 600 (no en la línea de comandos,
# que se ve con ps) y se borra al terminar.
run_voice_setup() {
  local key="$1" tmp rc=0
  tmp="$(mktemp)"
  chmod 600 "$tmp"
  printf '%s' "$key" > "$tmp"
  chown "$APP_USER" "$tmp"
  as_app "cd '$APP_DIR' && VOICE_ADMIN_KEY=\"\$(cat '$tmp')\" '$PHP_BIN' bin/voice-setup.php" || rc=$?
  rm -f "$tmp"
  return "$rc"
}

configure_voice() {
  if [[ "$SKIP_VOICE" == 1 ]]; then VOICE_STATUS="omitida (--no-voice)"; return 0; fi
  log "API de voz: usuario y token de esta instalación"

  # 1) Sin clave de admin: vale si ya hay una key válida o un usuario guardado.
  local rc=0
  run_voice_setup "" || rc=$?
  if [[ $rc == 0 ]]; then VOICE_STATUS="configurada"; return 0; fi
  if [[ $rc != 2 ]]; then
    warn "No se pudo configurar la API de voz (ver el error arriba). Las llamadas no funcionarán hasta corregirlo."
    VOICE_STATUS="ERROR: revisa VOICE_API_URL y la conectividad; luego: sudo $APP_DIR/deploy.sh update"
    return 0
  fi

  # 2) Hace falta la clave de administrador de la API de voz.
  local key="${VOICE_ADMIN_KEY:-}" attempt
  for attempt in 1 2 3; do
    if [[ -z "$key" ]]; then
      if ! { : </dev/tty; } 2>/dev/null; then break; fi
      printf '\nClave de administrador de la API de voz (ADMIN_SECRET_KEY de /opt/apivoz/.env).\n' >/dev/tty
      read -r -s -p "Escríbela (no se muestra; Enter para omitir): " key </dev/tty || key=""
      printf '\n' >/dev/tty
      [[ -n "$key" ]] || break
    fi
    rc=0
    run_voice_setup "$key" || rc=$?
    key=""
    if [[ $rc == 0 ]]; then VOICE_STATUS="configurada (usuario nuevo creado)"; return 0; fi
    if [[ $rc != 2 ]]; then break; fi
    warn "Clave rechazada (intento $attempt de 3)."
  done

  warn "API de voz sin token: las llamadas no funcionarán todavía."
  VOICE_STATUS="pendiente: ejecuta  sudo VOICE_ADMIN_KEY='<clave>' $APP_DIR/deploy.sh update"
  return 0
}

# ------------------------------------------------------ proveedor de SMS ----
# IT Cloud Services (contacto-virtual.com). Cada cliente tiene su propia
# cuenta: se piden su email y su token (creado en la plataforma del
# proveedor, opción Configuraciones). Viajan a bin/sms-setup.php por un
# archivo temporal 600, no por la línea de comandos.
run_sms_setup() {
  local email="$1" token="$2" extra="${3:-}" tmp rc=0
  tmp="$(mktemp)"
  chmod 600 "$tmp"
  printf '%s\n%s\n' "$email" "$token" > "$tmp"
  chown "$APP_USER" "$tmp"
  as_app "cd '$APP_DIR' && SMS_SETUP_EMAIL=\"\$(sed -n 1p '$tmp')\" SMS_SETUP_TOKEN=\"\$(sed -n 2p '$tmp')\" '$PHP_BIN' bin/sms-setup.php $extra" || rc=$?
  rm -f "$tmp"
  return "$rc"
}

configure_sms() {
  if [[ "$SKIP_SMS" == 1 ]]; then SMS_STATUS="omitido (--no-sms)"; return 0; fi
  log "Proveedor de SMS (IT Cloud Services): email y token de este cliente"

  # 1) ¿Ya configurado y válido? (o credenciales dadas por variables)
  local rc=0
  run_sms_setup "${SMS_EMAIL:-}" "${SMS_TOKEN:-}" || rc=$?
  if [[ $rc == 0 ]]; then SMS_STATUS="configurado"; return 0; fi
  if [[ $rc != 2 ]]; then
    warn "No se pudo validar el proveedor de SMS (ver el error arriba)."
    SMS_STATUS="ERROR de conexión con contacto-virtual.com; luego: sudo $APP_DIR/deploy.sh update"
    return 0
  fi

  # 2) Pedirlas por la terminal.
  local email="" token="" attempt ans
  for attempt in 1 2 3; do
    if ! { : </dev/tty; } 2>/dev/null; then break; fi
    printf '\nCuenta del proveedor de SMS (IT Cloud Services / contacto-virtual.com).\n' >/dev/tty
    read -r -p "Email de la cuenta (Enter para omitir): " email </dev/tty || email=""
    [[ -n "$email" ]] || break
    read -r -s -p "Token de envío (Configuraciones en la plataforma; no se muestra): " token </dev/tty || token=""
    printf '\n' >/dev/tty
    [[ -n "$token" ]] || break
    rc=0
    run_sms_setup "$email" "$token" || rc=$?
    if [[ $rc == 0 ]]; then SMS_STATUS="configurado"; return 0; fi
    if [[ $rc != 2 ]]; then break; fi
    read -r -p "¿Guardar estas credenciales sin validar (cuenta administradora)? [s/N]: " ans </dev/tty || ans=""
    if [[ "$ans" =~ ^[sS]$ ]]; then
      rc=0
      run_sms_setup "$email" "$token" --no-check || rc=$?
      if [[ $rc == 0 ]]; then SMS_STATUS="configurado (sin validar)"; return 0; fi
    fi
    warn "Credenciales no válidas (intento $attempt de 3)."
  done
  token=""

  warn "Proveedor de SMS sin configurar: los SMS no saldrán todavía."
  SMS_STATUS="pendiente: sudo SMS_EMAIL='<email>' SMS_TOKEN='<token>' $APP_DIR/deploy.sh update"
  return 0
}

sms_inbound_url() {
  local scheme=http secret
  [[ "$HTTPS_MODE" != none ]] && scheme=https
  secret="$(grep -E '^SMS_INBOUND_SECRET=' "$APP_DIR/.env" | cut -d= -f2-)"
  printf '%s://%s/api/sms/inbound/%s' "$scheme" "$HOST" "$secret"
}

# IP pública con la que sale este servidor: el proveedor de SMS debe
# registrarla en su firewall (avisar al comercial).
public_ip() {
  curl -fsS -m 5 https://api.ipify.org 2>/dev/null || curl -fsS -m 5 https://ifconfig.me 2>/dev/null || echo "desconocida"
}

# --------------------------------------------------------------- permisos ----
fix_perms() {
  mkdir -p "$APP_DIR/storage/logs" "$APP_DIR/storage/attachments" "$APP_DIR/storage/backups" "$APP_DIR/public/uploads"
  chown -R "$APP_USER:$WEB_USER" "$APP_DIR"
  chmod -R u=rwX,g=rX,o= "$APP_DIR"
  chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/storage" "$APP_DIR/public/uploads"
  chmod -R u=rwX,g=rwX,o= "$APP_DIR/storage" "$APP_DIR/public/uploads"
  [[ -f "$APP_DIR/.env" ]] && chmod 640 "$APP_DIR/.env"
  if selinux_on; then
    local t
    for t in "httpd_sys_content_t:$APP_DIR(/.*)?" \
             "httpd_sys_rw_content_t:$APP_DIR/storage(/.*)?" \
             "httpd_sys_rw_content_t:$APP_DIR/public/uploads(/.*)?"; do
      semanage fcontext -a -t "${t%%:*}" "${t#*:}" 2>/dev/null \
        || semanage fcontext -m -t "${t%%:*}" "${t#*:}"
    done
    restorecon -R "$APP_DIR"
  fi
}

configure_selinux_booleans() {
  selinux_on || return 0
  log "SELinux: permitir conexiones salientes de PHP-FPM (AWS, API de voz)"
  setsebool -P httpd_can_network_connect on
  setsebool -P httpd_can_network_connect_db on
}

# --------------------------------------------------------------- MariaDB -----
setup_database() {
  log "MariaDB: esquema, usuario de la aplicación y administrador"
  local cnf
  if [[ "$FAMILY" == rhel ]]; then cnf=/etc/my.cnf.d/99-contact-center.cnf
  else cnf=/etc/mysql/mariadb.conf.d/99-contact-center.cnf; fi
  if [[ ! -f "$cnf" ]]; then
    printf '[mysqld]\nbind-address = 127.0.0.1\n' > "$cnf"
  fi
  systemctl enable mariadb >/dev/null
  systemctl restart mariadb

  # Endurecimiento básico (equivalente no interactivo de mysql_secure_installation).
  mysql -e "DROP USER IF EXISTS ''@'localhost'; DROP DATABASE IF EXISTS test;" || true

  mysql < "$APP_DIR/database/schema.sql"
  ok "Esquema aplicado (contact_center)"
  (cd "$APP_DIR" && "$PHP_BIN" bin/setup-db-user.php)
  seed_admin_if_missing
}

seed_admin_if_missing() {
  local admins
  admins="$(mysql -N -e "SELECT COUNT(*) FROM contact_center.users WHERE role='admin'" 2>/dev/null || echo 0)"
  if [[ "$admins" == 0 ]]; then
    (cd "$APP_DIR" && runuser -u "$WEB_USER" -- "$PHP_BIN" bin/seed-admin.php)
    ADMIN_SEEDED=1
  else
    ok "Ya existe un administrador; no se modifica su contraseña"
  fi
}

# --------------------------------------------------------------- frontend ----
build_frontend() {
  log "Compilando el frontend (VITE_API_URL=/api)"
  rm -rf "$APP_DIR/dist"
  chown -R "$APP_USER:$WEB_USER" "$APP_DIR"
  as_app "cd '$APP_DIR' && npm ci --no-audit --no-fund && VITE_API_URL=/api npm run build"
  local bad
  bad="$(grep -rhoE 'https?://[a-zA-Z0-9.:-]+/api' "$APP_DIR/dist/assets/" 2>/dev/null | sort -u || true)"
  [[ -z "$bad" ]] || die "El bundle contiene una URL absoluta de API ($bad). Revisa VITE_API_URL."
  ok "Bundle correcto: el login llama a /api del mismo origen"
}

# ------------------------------------------------------------------ Nginx ----
nginx_locations() {
  cat <<EOF
    root ${APP_DIR}/dist;
    index index.html;
    include ${PMA_SNIPPET_DIR}/*.conf;

    # CSV 5 MB, adjuntos 7 MB, imágenes 3 MB (+ margen multipart).
    client_max_body_size 20m;

    server_tokens off;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
    # CSP: solo scripts propios (bloquea XSS por scripts inyectados). Estilos
    # en línea e imágenes remotas se permiten porque los usan el editor de
    # plantillas y los correos.
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https: http:; font-src 'self' data:; connect-src 'self'; frame-src 'self' about: blob: data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'" always;
$(if [[ "$HTTPS_MODE" == letsencrypt ]]; then echo '    add_header Strict-Transport-Security "max-age=31536000" always;'; fi)

    access_log /var/log/nginx/contact-center.access.log;
    error_log  /var/log/nginx/contact-center.error.log;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    # API Slim: las rutas ya incluyen /api y se pasan tal cual al front controller.
    location ^~ /api/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${APP_DIR}/public/index.php;
        fastcgi_param SCRIPT_NAME     /index.php;
        fastcgi_param DOCUMENT_ROOT   ${APP_DIR}/public;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_read_timeout 300s;
    }

    # Respuestas SMS (doble vía): el secreto va en la URL, así que no se
    # escribe en el access log.
    location ^~ /api/sms/inbound/ {
        access_log off;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${APP_DIR}/public/index.php;
        fastcgi_param SCRIPT_NAME     /index.php;
        fastcgi_param DOCUMENT_ROOT   ${APP_DIR}/public;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # Enlaces de baja de correos/SMS (ruta de Slim fuera de /api).
    location ^~ /unsubscribe/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${APP_DIR}/public/index.php;
        fastcgi_param SCRIPT_NAME     /index.php;
        fastcgi_param DOCUMENT_ROOT   ${APP_DIR}/public;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # Imágenes de los editores: solo estáticos, nunca PHP.
    location ^~ /uploads/ {
        alias ${APP_DIR}/public/uploads/;
        location ~ /\. { deny all; }
        location ~* \.(php\d?|phtml|phar|phps|pl|py|cgi|sh)\$ { deny all; }
        expires 30d;
        access_log off;
    }

    location ^~ /assets/ {
        expires 1y;
        try_files \$uri =404;
    }

    location = /index.html {
        expires -1;
    }

    # SPA (react-router).
    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location ~ /\. { deny all; }
EOF
}

self_signed_cert() {
  local dir
  if [[ "$FAMILY" == rhel ]]; then dir=/etc/pki/contact-center; else dir=/etc/ssl/contact-center; fi
  SSL_CRT="$dir/cc.crt"; SSL_KEY="$dir/cc.key"
  mkdir -p "$dir"
  if [[ ! -f "$SSL_CRT" ]]; then
    log "Generando certificado autofirmado para $HOST"
    local san="DNS:$HOST"
    is_ip "$HOST" && san="IP:$HOST"
    openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
      -keyout "$SSL_KEY" -out "$SSL_CRT" -subj "/CN=$HOST" -addext "subjectAltName=$san" 2>/dev/null
    chmod 600 "$SSL_KEY"
  fi
  selinux_on && restorecon -R "$dir"
  return 0
}

configure_nginx() {
  log "Configurando Nginx para $HOST (HTTPS: $HTTPS_MODE)"
  install -d -m 755 "$PMA_SNIPPET_DIR"   # snippets incluidos por el sitio (phpMyAdmin)
  local conf
  if [[ "$FAMILY" == rhel ]]; then
    conf=/etc/nginx/conf.d/contact-center.conf
    # Que nuestro server sea el default (acceso por IP): se quita
    # default_server del nginx.conf y de otros sitios (p.ej. una instalación manual previa).
    sed -i 's/ default_server//' /etc/nginx/nginx.conf
    local f
    for f in /etc/nginx/conf.d/*.conf; do
      [[ -f "$f" && "$f" != "$conf" ]] || continue
      if grep -q 'default_server' "$f"; then
        warn "Se quita default_server de $f (copia en $f.bak-deploy)"
        sed -i.bak-deploy 's/ default_server//' "$f"
      fi
    done
  else
    conf=/etc/nginx/sites-available/contact-center
    ln -sf "$conf" /etc/nginx/sites-enabled/contact-center
    rm -f /etc/nginx/sites-enabled/default
  fi

  if [[ "$HTTPS_MODE" == self-signed ]]; then
    self_signed_cert
    {
      cat <<EOF
# Gestionado por deploy.sh (Contact Center)
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${HOST} _;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name ${HOST} _;
    ssl_certificate     ${SSL_CRT};
    ssl_certificate_key ${SSL_KEY};
    ssl_protocols TLSv1.2 TLSv1.3;

EOF
      nginx_locations
      echo "}"
    } > "$conf"
  else
    {
      cat <<EOF
# Gestionado por deploy.sh (Contact Center)
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${HOST} _;

EOF
      nginx_locations
      echo "}"
    } > "$conf"
  fi

  nginx -t
  systemctl enable nginx >/dev/null
  systemctl restart nginx

  if [[ "$HTTPS_MODE" == letsencrypt ]]; then
    log "Let's Encrypt (certbot)"
    if [[ "$FAMILY" == rhel ]]; then
      [[ "$OS_ID" != fedora ]] && { dnf -y install epel-release 2>/dev/null \
        || dnf -y install "https://dl.fedoraproject.org/pub/epel/epel-release-latest-${EL_MAJOR}.noarch.rpm"; }
      dnf -y install certbot python3-certbot-nginx
    else
      apt-get install -y certbot python3-certbot-nginx
    fi
    certbot --nginx -n -d "$HOST" -m "$LE_EMAIL" --agree-tos --redirect --keep-until-expiring
    systemctl enable --now certbot-renew.timer 2>/dev/null || systemctl enable --now certbot.timer 2>/dev/null || true
  fi
}

configure_firewall() {
  [[ "$SKIP_FIREWALL" == 1 ]] && return 0
  if systemctl is-active --quiet firewalld 2>/dev/null; then
    log "firewalld: abriendo HTTP/HTTPS"
    firewall-cmd --permanent --add-service=http >/dev/null
    [[ "$HTTPS_MODE" != none ]] && firewall-cmd --permanent --add-service=https >/dev/null
    firewall-cmd --reload >/dev/null
  elif command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    log "ufw: abriendo 80/443"
    ufw allow 80/tcp >/dev/null
    [[ "$HTTPS_MODE" != none ]] && ufw allow 443/tcp >/dev/null
  else
    warn "No hay firewalld ni ufw activos; no se configuró el firewall."
  fi
  return 0
}

# ------------------------------------------------------------ phpMyAdmin ----
# phpMyAdmin oficial (tarball verificado con SHA-256) en /phpmyadmin/, con:
#   - Autenticación HTTP básica de Nginx delante (usuario/clave propios).
#   - Usuario de BD propio (cc_dbadmin), sin acceso a las tablas de créditos:
#     el saldo y los precios solo se tocan por consola (bin/credits.php).
#   - root no puede entrar por web (AllowRoot = false; además usa unix_socket).
# Credenciales en /etc/contact-center/phpmyadmin.txt (solo root).
PMA_DIR=/usr/share/phpmyadmin-cc
PMA_TMP=/var/lib/phpmyadmin-cc/tmp
PMA_CREDS=/etc/contact-center/phpmyadmin.txt
PMA_SNIPPET_DIR=/etc/nginx/contact-center.d
PMA_STATUS="sin instalar"

nginx_site_conf() {
  if [[ "$FAMILY" == rhel ]]; then echo /etc/nginx/conf.d/contact-center.conf
  else echo /etc/nginx/sites-available/contact-center; fi
}

# Instalaciones previas: añade el include de snippets al sitio si no lo tiene.
ensure_nginx_snippet_include() {
  local conf
  conf="$(nginx_site_conf)"
  install -d -m 755 "$PMA_SNIPPET_DIR"
  [[ -f "$conf" ]] || return 0
  if ! grep -q "include ${PMA_SNIPPET_DIR}/\*.conf;" "$conf"; then
    cp -a "$conf" "$conf.bak-deploy"
    sed -i "s|^\(\s*\)index index.html;|&\n\1include ${PMA_SNIPPET_DIR}/*.conf;|" "$conf"
    ok "Añadido 'include ${PMA_SNIPPET_DIR}/*.conf' a $conf (copia en $conf.bak-deploy)"
  fi
}

install_phpmyadmin_files() {
  local version tmp file base
  version="$(curl -fsSL -m 20 https://www.phpmyadmin.net/home_page/version.txt | head -n1 | tr -d '[:space:]')"
  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { warn "No se pudo obtener la versión de phpMyAdmin"; return 1; }
  if [[ -f "$PMA_DIR/.cc-version" && "$(cat "$PMA_DIR/.cc-version")" == "$version" ]]; then
    ok "phpMyAdmin $version ya instalado"
    return 0
  fi
  log "Descargando phpMyAdmin $version"
  tmp="$(mktemp -d)"
  file="phpMyAdmin-${version}-all-languages.tar.xz"
  base="https://files.phpmyadmin.net/phpMyAdmin/${version}"
  curl -fsSL -m 120 "$base/$file" -o "$tmp/$file"
  curl -fsSL -m 20 "$base/$file.sha256" -o "$tmp/$file.sha256"
  (cd "$tmp" && sha256sum -c "$file.sha256" >/dev/null) || { rm -rf "$tmp"; warn "Checksum de phpMyAdmin incorrecto"; return 1; }
  mkdir -p "$tmp/x"
  tar -xJf "$tmp/$file" -C "$tmp/x" --strip-components=1
  rm -rf "$PMA_DIR"
  mv "$tmp/x" "$PMA_DIR"
  rm -rf "$tmp" "$PMA_DIR/setup"          # el asistente de configuración web no se usa
  echo "$version" > "$PMA_DIR/.cc-version"
  ok "phpMyAdmin $version en $PMA_DIR"
}

# Permisos del usuario de BD de phpMyAdmin: todo sobre contact_center salvo
# las tablas de créditos (sin acceso). Se recalcula en cada update para
# incluir tablas nuevas.
grant_pma_db_user() {
  local user="$1" pass="$2" t grants=""
  mysql -e "CREATE USER IF NOT EXISTS '${user}'@'localhost' IDENTIFIED BY '${pass}';
            ALTER USER '${user}'@'localhost' IDENTIFIED BY '${pass}';
            REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${user}'@'localhost';"
  while read -r t; do
    [[ -n "$t" ]] || continue
    grants+="GRANT ALL PRIVILEGES ON \`contact_center\`.\`${t}\` TO '${user}'@'localhost';"
  done < <(mysql -N -e "SELECT table_name FROM information_schema.tables
                         WHERE table_schema = 'contact_center' AND table_name NOT LIKE 'credit\_%'")
  [[ -n "$grants" ]] && mysql -e "$grants"
  mysql -e "FLUSH PRIVILEGES;"
}

configure_phpmyadmin() {
  local snippet="$PMA_SNIPPET_DIR/phpmyadmin.conf"
  if [[ "$SKIP_PMA" == 1 ]]; then
    if [[ -f "$snippet" ]]; then rm -f "$snippet"; nginx -t && systemctl reload nginx; fi
    PMA_STATUS="no instalado (--no-phpmyadmin)"
    return 0
  fi
  log "phpMyAdmin (acceso web en /phpmyadmin/)"
  ensure_nginx_snippet_include
  if ! install_phpmyadmin_files; then
    PMA_STATUS="ERROR al descargar; reintenta con: sudo $APP_DIR/deploy.sh update"
    return 0
  fi

  # Credenciales: se generan una vez y se reutilizan.
  install -d -m 700 /etc/contact-center
  if [[ ! -f "$PMA_CREDS" ]]; then
    {
      echo "# phpMyAdmin de Contact Center (generado por deploy.sh). Solo root."
      echo "PMA_WEB_USER=ccadmin"
      echo "PMA_WEB_PASS=$(openssl rand -hex 12)"
      echo "PMA_DB_USER=cc_dbadmin"
      echo "PMA_DB_PASS=$(openssl rand -hex 16)"
      echo "PMA_BLOWFISH=$(openssl rand -hex 32)"
    } > "$PMA_CREDS"
  fi
  chmod 600 "$PMA_CREDS"
  local PMA_WEB_USER PMA_WEB_PASS PMA_DB_USER PMA_DB_PASS PMA_BLOWFISH
  # shellcheck disable=SC1090
  . "$PMA_CREDS"

  grant_pma_db_user "$PMA_DB_USER" "$PMA_DB_PASS"

  install -d -m 770 -o "$WEB_USER" -g "$WEB_USER" "$PMA_TMP"
  cat > "$PMA_DIR/config.inc.php" <<EOF
<?php
// Generado por deploy.sh (Contact Center). No editar: se regenera en cada update.
declare(strict_types=1);
\$cfg['blowfish_secret'] = hex2bin('${PMA_BLOWFISH}');
\$i = 1;
\$cfg['Servers'][\$i]['auth_type'] = 'cookie';
\$cfg['Servers'][\$i]['host'] = 'localhost';
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;
\$cfg['Servers'][\$i]['AllowRoot'] = false;
\$cfg['Servers'][\$i]['only_db'] = 'contact_center';
\$cfg['TempDir'] = '${PMA_TMP}';
\$cfg['UploadDir'] = '';
\$cfg['SaveDir'] = '';
\$cfg['VersionCheck'] = false;
\$cfg['LoginCookieValidity'] = 3600;
\$cfg['ShowServerInfo'] = false;
\$cfg['PmaNoRelation_DisableWarning'] = true;
EOF
  chown -R root:root "$PMA_DIR"
  chown "root:$WEB_USER" "$PMA_DIR/config.inc.php"
  chmod 640 "$PMA_DIR/config.inc.php"
  find "$PMA_DIR" -type d -exec chmod 755 {} +

  # Autenticación básica de Nginx (primera barrera, antes del login de phpMyAdmin).
  printf '%s:%s\n' "$PMA_WEB_USER" "$(openssl passwd -apr1 -stdin <<<"$PMA_WEB_PASS")" > /etc/nginx/cc-phpmyadmin.htpasswd
  chown "root:$WEB_USER" /etc/nginx/cc-phpmyadmin.htpasswd
  chmod 640 /etc/nginx/cc-phpmyadmin.htpasswd

  # Por defecto solo redes privadas (LAN/VPN). "--pma-allow any" lo abre a todos.
  local allow_rules="" effective="${PMA_ALLOW:-127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16}"
  if [[ "$effective" != any ]]; then
    local cidr
    IFS=',' read -ra _cidrs <<<"$effective"
    for cidr in "${_cidrs[@]}"; do allow_rules+="        allow ${cidr};"$'\n'; done
    allow_rules+="        deny all;"$'\n'
  fi

  cat > "$snippet" <<EOF
# Gestionado por deploy.sh (Contact Center): phpMyAdmin.
location = /phpmyadmin { return 301 /phpmyadmin/; }
location ^~ /phpmyadmin/ {
${allow_rules}        # Cabeceras propias: la CSP de la app (sin scripts en línea) rompería
        # phpMyAdmin; estas reemplazan a las del bloque server.
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header Referrer-Policy "no-referrer" always;
        add_header Content-Security-Policy "frame-ancestors 'self'; object-src 'none'; base-uri 'self'" always;
        auth_basic "phpMyAdmin";
        auth_basic_user_file /etc/nginx/cc-phpmyadmin.htpasswd;
        alias ${PMA_DIR}/;
        index index.php;

        location ~ ^/phpmyadmin/(libraries|templates|vendor|src|sql|setup|doc/html/_sources)/ { deny all; }
        location ~ /\. { deny all; }

        location ~ ^/phpmyadmin/(.+\.php)\$ {
            alias ${PMA_DIR}/\$1;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME ${PMA_DIR}/\$1;
            fastcgi_pass unix:${PHP_SOCK};
            fastcgi_read_timeout 300s;
        }
}
EOF

  if selinux_on; then
    semanage fcontext -a -t httpd_sys_content_t "${PMA_DIR}(/.*)?" 2>/dev/null \
      || semanage fcontext -m -t httpd_sys_content_t "${PMA_DIR}(/.*)?"
    semanage fcontext -a -t httpd_sys_rw_content_t "/var/lib/phpmyadmin-cc(/.*)?" 2>/dev/null \
      || semanage fcontext -m -t httpd_sys_rw_content_t "/var/lib/phpmyadmin-cc(/.*)?"
    restorecon -R "$PMA_DIR" /var/lib/phpmyadmin-cc /etc/nginx/cc-phpmyadmin.htpasswd "$PMA_SNIPPET_DIR"
  fi

  if nginx -t 2>/dev/null; then
    systemctl reload nginx
    PMA_STATUS="instalado"
  else
    nginx -t || true
    rm -f "$snippet"
    warn "La configuración de phpMyAdmin no pasó 'nginx -t'; se retiró."
    PMA_STATUS="ERROR en la configuración de Nginx"
  fi
}

# -------------------------------------------------------- tareas (systemd) ---
configure_timers() {
  log "Tareas programadas (dispatcher cada minuto, backup diario 02:30)"
  install -d -m 700 -o root -g root "$BACKUP_DIR"

  cat > /etc/systemd/system/contact-center-dispatch.service <<EOF
[Unit]
Description=Contact Center - campañas programadas, cola de envíos y estado de llamadas
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
User=${WEB_USER}
Group=${WEB_USER}
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} ${APP_DIR}/bin/dispatch-campaigns.php
EOF

  cat > /etc/systemd/system/contact-center-dispatch.timer <<'EOF'
[Unit]
Description=Ejecuta el dispatcher de Contact Center cada minuto

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Unit=contact-center-dispatch.service

[Install]
WantedBy=timers.target
EOF

  cat > /etc/systemd/system/contact-center-backup.service <<EOF
[Unit]
Description=Contact Center - copia de seguridad de la base de datos
After=mariadb.service

[Service]
Type=oneshot
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} ${APP_DIR}/bin/backup-db.php --keep=14 --out=${BACKUP_DIR}
EOF

  cat > /etc/systemd/system/contact-center-backup.timer <<'EOF'
[Unit]
Description=Copia diaria de Contact Center

[Timer]
OnCalendar=*-*-* 02:30:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

  systemctl daemon-reload
  systemctl enable --now contact-center-dispatch.timer contact-center-backup.timer >/dev/null
}

save_conf() {
  install -d -m 700 /etc/contact-center
  {
    echo "# Generado por deploy.sh; lo usa 'deploy.sh update'."
    printf 'APP_DIR=%q\nAPP_USER=%q\nHOST=%q\nHTTPS_MODE=%q\n' "$APP_DIR" "$APP_USER" "$HOST" "$HTTPS_MODE"
    if [[ -n "$BRANCH" ]]; then printf 'BRANCH=%q\n' "$BRANCH"; fi
    if [[ -n "$LE_EMAIL" ]]; then printf 'LE_EMAIL=%q\n' "$LE_EMAIL"; fi
    printf 'SKIP_PMA=%q\nPMA_ALLOW=%q\n' "$SKIP_PMA" "$PMA_ALLOW"
  } > "$CONF_FILE"
  chmod 600 "$CONF_FILE"
}

smoke_test() {
  log "Verificación"
  local scheme=http code
  [[ "$HTTPS_MODE" != none ]] && scheme=https
  code="$(curl -ksL -o /dev/null -w '%{http_code}' \
    --resolve "${HOST}:80:127.0.0.1" --resolve "${HOST}:443:127.0.0.1" \
    "${scheme}://${HOST}/api/auth/me" || true)"
  if [[ "$code" == 401 ]]; then
    ok "API OK: /api/auth/me responde 401 (sin token), como se espera"
  else
    warn "/api/auth/me respondió '$code' (se esperaba 401). Revisa:"
    warn "  tail -n 50 /var/log/nginx/contact-center.error.log"
    warn "  tail -n 50 $APP_DIR/storage/logs/app-\$(date +%F).log"
  fi
  local s
  for s in nginx "$PHP_FPM_SERVICE" mariadb contact-center-dispatch.timer; do
    printf '    %-32s %s\n' "$s" "$(systemctl is-active "$s" 2>/dev/null || true)"
  done
}

summary() {
  local scheme=http
  [[ "$HTTPS_MODE" != none ]] && scheme=https
  local admin
  admin="$(grep -E '^ADMIN_EMAIL=' "$APP_DIR/.env" | cut -d= -f2-)"
  cat <<EOF

=============================================================================
 Contact Center listo: ${scheme}://${HOST}/login
 Usuario admin:  ${admin}
 Contraseña:     $(if [[ "${ADMIN_SEEDED:-0}" == 1 ]]; then echo "sudo grep ^ADMIN_PASSWORD= ${APP_DIR}/.env"; else echo "la que ya tenías (el admin existía y no se modificó)"; fi)
 (cámbiala tras el primer login y vacía ADMIN_PASSWORD en el .env)

 API de voz:     ${VOICE_STATUS}
 SMS (IT Cloud): ${SMS_STATUS}
   IP pública de este servidor: $(public_ip)
   -> pídele al comercial del proveedor que la autorice en su firewall.
   URL para recibir respuestas (SMS doble vía), dásela también al proveedor:
     $(sms_inbound_url)
   (debe ser accesible desde Internet; con una IP privada no llegarán respuestas)

 phpMyAdmin:     ${PMA_STATUS}$(if [[ "$PMA_STATUS" == instalado ]]; then printf '\n   %s://%s/phpmyadmin/\n   Credenciales (acceso web y usuario de BD):  sudo cat %s' "$scheme" "$HOST" "$PMA_CREDS"; fi)

 Saldo (créditos): solo se recarga por consola:
   sudo -u ${WEB_USER} ${PHP_BIN} ${APP_DIR}/bin/credits.php recharge 100000
   sudo -u ${WEB_USER} ${PHP_BIN} ${APP_DIR}/bin/credits.php balance    (también: history, usage, prices, set-price)

 Pendiente de completar en ${APP_DIR}/.env (después: sudo systemctl reload ${PHP_FPM_SERVICE}):
   AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_REGION, MAIL_FROM
 Actualizar más adelante:  sudo ${APP_DIR}/deploy.sh update
=============================================================================
EOF
  if [[ "$HTTPS_MODE" == none ]]; then
    warn "Sitio en HTTP: el navegador avisará de 'contraseña en página insegura'. Usa --https self-signed o letsencrypt."
  fi
}

# ------------------------------------------------------------------ main -----
detect_os

if [[ "$CMD" == install ]]; then
  # 1-2) Lo primero: clonar el repositorio.
  bootstrap_git
  fetch_code
  reexec_if_script_changed
  # 3) Paquetes del sistema y resto de la instalación.
  log "Paso 3: paquetes del sistema"
  if [[ "$FAMILY" == rhel ]]; then install_packages_rhel; else install_packages_debian; fi
  ensure_node
  ensure_composer
  configure_timezone
  configure_php
  setup_user_and_dir
  install_php_deps
  configure_env
  configure_voice
  configure_sms
  fix_perms
  configure_selinux_booleans
  setup_database
  build_frontend
  fix_perms
  configure_nginx
  configure_phpmyadmin
  configure_firewall
  configure_timers
  save_conf
  systemctl restart "$PHP_FPM_SERVICE"
  smoke_test
  summary
else
  [[ -d "$APP_DIR" && -f "$APP_DIR/.env" ]] || die "No hay instalación en $APP_DIR; usa primero: sudo ./deploy.sh install --host <IP>"
  if [[ "$FAMILY" == debian ]]; then resolve_php_debian; fi
  ensure_node
  ensure_composer
  fetch_code
  reexec_if_script_changed
  install_php_deps
  configure_env
  configure_voice
  ok "API de voz: $VOICE_STATUS"
  configure_sms
  ok "SMS: $SMS_STATUS"
  if [[ -n "$HOST" ]]; then ok "URL de respuestas SMS (doble vía) para el proveedor: $(sms_inbound_url)"; fi
  fix_perms
  log "Aplicando esquema (idempotente)"
  mysql < "$APP_DIR/database/schema.sql"
  build_frontend
  fix_perms
  # Se regenera la configuración de Nginx para aplicar las cabeceras de
  # seguridad y rutas nuevas (los certificados existentes se reutilizan).
  if [[ -n "$HOST" ]]; then configure_nginx; fi
  configure_phpmyadmin
  if [[ "$PMA_STATUS" == instalado && -n "$HOST" ]]; then
    ok "phpMyAdmin: $([[ "$HTTPS_MODE" != none ]] && echo https || echo http)://${HOST}/phpmyadmin/  (credenciales: sudo cat $PMA_CREDS)"
  else
    ok "phpMyAdmin: $PMA_STATUS"
  fi
  configure_timers
  save_conf
  systemctl reload "$PHP_FPM_SERVICE" || systemctl restart "$PHP_FPM_SERVICE"
  nginx -t && systemctl reload nginx
  [[ -n "$HOST" ]] && smoke_test
  log "Actualización completada"
fi

}  # fin de cc_main

cc_main "$@" </dev/null
