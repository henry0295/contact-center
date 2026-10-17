#!/usr/bin/env bash
# =============================================================================
# deploy.sh - Instala o actualiza Contact Center en Linux.
#
# Stack: Nginx + PHP-FPM (>= 8.2) + MariaDB + Node (solo para compilar el SPA).
# Distros: Rocky / AlmaLinux / RHEL / CentOS Stream 8-10, Fedora,
#          Debian 11+ y Ubuntu 22.04+.
#
# Uso:
#   sudo ./deploy.sh install --host 192.168.101.95
#   sudo ./deploy.sh install --host 192.168.101.95 --https self-signed
#   sudo ./deploy.sh install --host cc.midominio.com --https letsencrypt --email yo@midominio.com
#   sudo ./deploy.sh install --host 10.0.0.5 --repo https://github.com/<usuario>/<repo>.git
#   sudo ./deploy.sh update
#
# "install" es idempotente: se puede repetir sin perder datos (.env, BD,
# uploads). "update" trae el código nuevo, reinstala dependencias, aplica el
# esquema, recompila el frontend y recarga los servicios.
# =============================================================================
set -Eeuo pipefail

# ----------------------------------------------------------------- defaults --
APP_USER=contactcenter
APP_DIR=/var/www/contact-center
REPO_URL=""
BRANCH=""
HOST=""
HTTPS_MODE=none          # none | self-signed | letsencrypt
LE_EMAIL=""
TIMEZONE=""
ADMIN_EMAIL=""
SKIP_FIREWALL=0
NODE_MAJOR=22
CONF_FILE=/etc/contact-center/deploy.conf
BACKUP_DIR=/var/lib/contact-center/backups

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m    %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[aviso] %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31m[error] %s\033[0m\n' "$*" >&2; exit 1; }
trap 'die "Falló la línea $LINENO: $BASH_COMMAND"' ERR

usage() {
  sed -n '2,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
  cat <<'EOF'
Opciones:
  --host <IP|dominio>     Dirección pública del sitio (obligatoria en install).
  --https <modo>          none (por defecto) | self-signed | letsencrypt.
  --email <correo>        Correo para Let's Encrypt (obligatorio con letsencrypt).
  --repo <url>            Repositorio git a clonar si el código aún no está en --dir.
  --branch <rama>         Rama a clonar/actualizar.
  --dir <ruta>            Carpeta de la aplicación (por defecto /var/www/contact-center).
  --timezone <zona>       Zona horaria del sistema, p.ej. America/Bogota.
  --admin-email <correo>  Correo del administrador inicial.
  --no-firewall           No tocar firewalld/ufw.
EOF
}

# ------------------------------------------------- re-ejecución desde /tmp ---
# git pull puede reescribir este archivo mientras bash lo lee: se ejecuta una
# copia en /tmp.
if [[ -z "${CC_DEPLOY_REEXEC:-}" ]]; then
  SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  TMP_SELF="$(mktemp /tmp/cc-deploy.XXXXXX)"
  cp "${BASH_SOURCE[0]}" "$TMP_SELF"
  CC_DEPLOY_REEXEC=1 CC_DEPLOY_SRC="$SELF_DIR" exec bash "$TMP_SELF" "$@"
fi
SCRIPT_DIR="${CC_DEPLOY_SRC}"
SELF_TMP="${BASH_SOURCE[0]}"
trap 'rm -f "$SELF_TMP"' EXIT
# sudo en RHEL no incluye /usr/local/bin (Node y Composer se instalan ahí).
export PATH="/usr/local/bin:$PATH"

# --------------------------------------------------------------- argumentos --
CMD="${1:-}"
[[ $# -gt 0 ]] && shift
case "$CMD" in
  install|update) ;;
  -h|--help|help|"") usage; exit 0 ;;
  *) usage; die "Comando desconocido: $CMD" ;;
esac

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
    -h|--help)     usage; exit 0 ;;
    *) die "Opción desconocida: $1" ;;
  esac
done

# Validaciones (los valores acaban en configuraciones de Nginx y en sed).
[[ "$APP_DIR" =~ ^/[A-Za-z0-9._/-]+$ && "$APP_DIR" != "/" ]] || die "--dir no válido: $APP_DIR"
APP_DIR="${APP_DIR%/}"
[[ "$HTTPS_MODE" =~ ^(none|self-signed|letsencrypt)$ ]] || die "--https debe ser none, self-signed o letsencrypt"
if [[ "$CMD" == install ]]; then
  [[ -n "$HOST" ]] || die "install necesita --host <IP o dominio>"
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
  log "Usuario $APP_USER y carpeta $APP_DIR"
  id "$APP_USER" >/dev/null 2>&1 || useradd -m -s /bin/bash "$APP_USER"
  usermod -aG "$WEB_USER" "$APP_USER"
  mkdir -p "$APP_DIR"
  chown "$APP_USER:$WEB_USER" "$APP_DIR"
  chmod 750 "$APP_DIR"
}

fetch_code() {
  log "Obteniendo el código"
  if [[ -d "$APP_DIR/.git" ]]; then
    chown -R "$APP_USER:$WEB_USER" "$APP_DIR"
    if [[ -n "$BRANCH" ]]; then
      as_app "git -C '$APP_DIR' fetch --prune origin && git -C '$APP_DIR' checkout '$BRANCH'"
    fi
    if ! as_app "git -C '$APP_DIR' pull --ff-only"; then
      # Repos privados: el usuario $APP_USER necesita credenciales (deploy key o token).
      [[ "$CMD" == install ]] || die "git pull falló. Configura credenciales de git para $APP_USER (ver README)."
      warn "git pull falló; se usa el código que ya hay en $APP_DIR"
    fi
    ok "git: $(as_app "git -C '$APP_DIR' log -1 --format='%h %s'")"
  elif [[ -n "$REPO_URL" ]]; then
    if [[ -n "$(ls -A "$APP_DIR" 2>/dev/null)" ]]; then
      die "$APP_DIR no está vacío y no es un repositorio git; vacíalo o usa otra --dir"
    fi
    as_app "git clone ${BRANCH:+--branch '$BRANCH'} '$REPO_URL' '$APP_DIR'"
  elif [[ "$SCRIPT_DIR" != "$APP_DIR" && -f "$SCRIPT_DIR/composer.json" ]]; then
    ok "Copiando desde $SCRIPT_DIR"
    rsync -a \
      --exclude '.git/' --exclude 'node_modules/' --exclude 'vendor/' --exclude 'dist/' \
      --exclude '/.env' --exclude '/.env.bak' --exclude '/storage/' --exclude '/public/uploads/' \
      --exclude '.agents/' --exclude '.bolt/' \
      "$SCRIPT_DIR/" "$APP_DIR/"
  elif [[ -f "$APP_DIR/composer.json" ]]; then
    ok "El código ya está en $APP_DIR"
  else
    die "No hay código en $APP_DIR. Usa --repo <url> o ejecuta el script desde una copia del proyecto."
  fi
  [[ -f "$APP_DIR/composer.json" ]] || die "$APP_DIR no contiene el proyecto (falta composer.json)"
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
  if [[ -n "$HOST" ]]; then
    set_env APP_URL "${scheme}://${HOST}"
    set_env CORS_ALLOWED_ORIGIN "${scheme}://${HOST}"
  fi
  [[ -n "$ADMIN_EMAIL" ]] && set_env ADMIN_EMAIL "$ADMIN_EMAIL"
  ensure_env VOICE_API_URL ""
  ensure_env VOICE_API_KEY ""
  ensure_env VOICE_CALL_THROTTLE_MS 1500

  chown "$APP_USER:$WEB_USER" "$APP_DIR/.env"
  chmod 640 "$APP_DIR/.env"
  # Rellena JWT_SECRET, ADMIN_PASSWORD y DB_PASSWORD si faltan o son débiles.
  as_app "cd '$APP_DIR' && '$PHP_BIN' bin/generate-secrets.php"
  if [[ -f "$APP_DIR/.env.bak" ]]; then shred -u "$APP_DIR/.env.bak" 2>/dev/null || rm -f "$APP_DIR/.env.bak"; fi
  grep -E '^(APP_URL|CORS_ALLOWED_ORIGIN|VITE_API_URL|ADMIN_EMAIL)=' "$APP_DIR/.env" | sed 's/^/    /'
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

    # CSV 5 MB, adjuntos 7 MB, imágenes 3 MB (+ margen multipart).
    client_max_body_size 20m;

    server_tokens off;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;

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
  local conf
  if [[ "$FAMILY" == rhel ]]; then
    conf=/etc/nginx/conf.d/contact-center.conf
    # Que nuestro server sea el default (acceso por IP).
    sed -i 's/ default_server//' /etc/nginx/nginx.conf
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
 Contraseña:     sudo grep ^ADMIN_PASSWORD= ${APP_DIR}/.env
 (cámbiala tras el primer login y vacía ADMIN_PASSWORD en el .env)

 Pendiente de completar en ${APP_DIR}/.env (después: sudo systemctl reload ${PHP_FPM_SERVICE}):
   AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_REGION, MAIL_FROM,
   VOICE_API_URL, VOICE_API_KEY
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
  if [[ "$FAMILY" == rhel ]]; then install_packages_rhel; else install_packages_debian; fi
  ensure_node
  ensure_composer
  configure_timezone
  configure_php
  setup_user_and_dir
  fetch_code
  install_php_deps
  configure_env
  fix_perms
  configure_selinux_booleans
  setup_database
  build_frontend
  fix_perms
  configure_nginx
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
  install_php_deps
  configure_env
  fix_perms
  log "Aplicando esquema (idempotente)"
  mysql < "$APP_DIR/database/schema.sql"
  build_frontend
  fix_perms
  configure_timers
  save_conf
  systemctl reload "$PHP_FPM_SERVICE" || systemctl restart "$PHP_FPM_SERVICE"
  nginx -t && systemctl reload nginx
  [[ -n "$HOST" ]] && smoke_test
  log "Actualización completada"
fi
