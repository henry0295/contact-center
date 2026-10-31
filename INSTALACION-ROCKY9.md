# Instalación en Rocky Linux 9 (producción) — Contact Center

> Este documento corresponde a la versión previa a la conversión SaaS (respaldo del 15/10/2026, carpeta `project-backup-pre-saas`). La versión multi-tenant requerirá pasos adicionales.

Convenciones:

- `<TU_DOMINIO>`, `<IP_SERVIDOR>`, `<TU_USUARIO>`, `<TU_EMAIL>`, `<ZONA_HORARIA>`: sustitúyelos por tus datos.
- **[Recomendación]** marca lo que propone este documento y no exige el código del proyecto.
- Los comandos se ejecutan en el servidor con un usuario con `sudo` (`<TU_USUARIO>`), salvo que se indique "en Windows".
- SELinux queda en modo **Enforcing** (el predeterminado de Rocky). No lo desactives: la §3.2 deja los contextos y permisos que necesita la aplicación.

Arquitectura (un solo dominio, mismo origen; no hace falta CORS):

| URL | Lo atiende | Origen en el código |
|---|---|---|
| `https://<TU_DOMINIO>/` | Nginx sirve el SPA compilado (`dist/`) | `vite build` |
| `https://<TU_DOMINIO>/api/...` | PHP-FPM → `public/index.php` (Slim) | rutas `/api/*` de `public/index.php` |
| `https://<TU_DOMINIO>/unsubscribe/<token>` | PHP-FPM → `public/index.php` | ruta pública `/unsubscribe/{token}`; enlace = `APP_URL + /unsubscribe/` |
| `https://<TU_DOMINIO>/uploads/<archivo>` | Nginx, estático desde `public/uploads/` | `UploadController` arma `APP_URL + /uploads/` |

---

## 1. Requisitos

- **Rocky Linux 9** (9.4 o superior recomendado), instalación mínima o servidor.
- **Hardware [Recomendación]:** 2 vCPU, 4 GB de RAM, 20 GB de disco.
- **Dominio** con registro DNS `A` hacia la IP pública del servidor.
- **Puertos:** entrada 22, 80 y 443. Salida 443 (AWS), **8000 hacia 192.168.101.92** (API de voz, §14), DNS y NTP. MariaDB (3306) no se expone.
- **PHP 8.2 o superior** (`composer.json` exige `^8.2`; se desarrolló con 8.3). **El PHP por defecto de Rocky 9 es más antiguo y no sirve**: hay que habilitar un module stream (§2.2).
- **MariaDB** (no MySQL): `schema.sql` usa `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS`. La 10.5 de Rocky 9 basta; si tienes el stream 10.11, mejor.
- **Extensiones PHP** (de `composer.lock` y del código): `pdo_mysql`, `curl`, `mbstring`, `simplexml`/`dom`/`xml`, `openssl`, `json`, `pcre`, `ctype`, `filter`, `hash`, `iconv`, `zlib`; `opcache` **[Recomendación]**. `intl` no hace falta.

| Paquete Rocky | Aporta |
|---|---|
| `php-common` (dependencia) | curl, json, openssl, zlib, ctype, filter, hash, iconv |
| `php-mysqlnd` (+ `php-pdo`) | pdo_mysql |
| `php-mbstring` | mbstring |
| `php-xml` | simplexml, dom, xml |
| `php-opcache` | opcache |

---

## 2. Paquetes del sistema

### 2.1 Base, EPEL y herramientas

```bash
sudo dnf -y update
sudo dnf install -y dnf-plugins-core epel-release
sudo dnf config-manager --set-enabled crb
sudo dnf install -y nginx mariadb-server git unzip tar rsync curl \
  policycoreutils-python-utils cronie logrotate \
  certbot python3-certbot-nginx
```

`certbot` y `python3-certbot-nginx` vienen de EPEL; `semanage` de `policycoreutils-python-utils`.

### 2.2 PHP 8.2+ (module stream)

```bash
dnf module list php        # mira qué streams ofrece tu versión de Rocky
```

**Opción A — AppStream (preferida, soporte de Rocky):** usa `8.3` si aparece en la lista; si no, `8.2`.

```bash
sudo dnf module reset -y php
sudo dnf module enable -y php:8.3        # o php:8.2
sudo dnf install -y php-fpm php-cli php-mysqlnd php-mbstring php-xml php-opcache
```

**Opción B — Remi (si quieres 8.3 y tu Rocky no lo ofrece):**

```bash
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
sudo dnf module reset -y php
sudo dnf module enable -y php:remi-8.3
sudo dnf install -y php-fpm php-cli php-mysqlnd php-mbstring php-xml php-opcache
```

Comprueba:

```bash
php -v                      # debe ser 8.2.x o 8.3.x
php -m | grep -Ei 'pdo_mysql|curl|mbstring|simplexml|openssl|zlib'
```

### 2.3 MariaDB (opcional: versión 10.11)

```bash
dnf module list mariadb     # si aparece 10.11, puedes usarla ANTES de instalar mariadb-server:
# sudo dnf module enable -y mariadb:10.11 && sudo dnf install -y mariadb-server
```

### 2.4 Composer

No viene en AppStream. Instalador oficial con verificación de firma:

```bash
cd /tmp
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
ACTUAL="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
[ "$EXPECTED" = "$ACTUAL" ] && sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f composer-setup.php
composer --version
```

`/usr/local/bin` no está en el `PATH` de `sudo` en Rocky; por eso Composer se ejecuta siempre como `contactcenter` con `sudo -iu` (shell de login).

### 2.5 Node.js (solo para compilar el frontend)

Vite 5 necesita Node 18 o superior.

```bash
sudo dnf module reset -y nodejs
sudo dnf module enable -y nodejs:22
sudo dnf install -y nodejs npm
node -v && npm -v
```

Alternativa: no instalar Node en el servidor y compilar en Windows (§8).

### 2.6 Zona horaria (obligatorio)

El código compara horas de PHP (`date()`, `time()`) con `NOW()` de MariaDB, y la hora de "Programar" llega del navegador como hora local. Servidor, MariaDB y PHP deben usar la zona de los usuarios, o las campañas programadas saldrán a destiempo y las sesiones caducarán mal.

```bash
sudo timedatectl set-timezone <ZONA_HORARIA>     # p.ej. America/Bogota
timedatectl                                     # "System clock synchronized: yes" (chronyd)
```

### 2.7 PHP para producción

En Rocky, `/etc/php.d/` aplica a la vez a PHP-FPM y a la CLI.

```bash
sudo tee /etc/php.d/99-contact-center.ini > /dev/null <<'EOF'
; Contact Center - producción
expose_php = Off
display_errors = Off
log_errors = On
date.timezone = <ZONA_HORARIA>
memory_limit = 256M
; CSV de contactos 5 MB, adjuntos 5 MB c/u y 7 MB en total, imágenes 3 MB
upload_max_filesize = 8M
post_max_size = 20M
max_file_uploads = 20
; Necesario para las llamadas salientes de la aplicación (APIs de proveedores)
allow_url_fopen = On
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 60
EOF
```

### 2.8 PHP-FPM con el usuario `nginx`

En Rocky, el pool `www` corre como `apache`. Se cambia a `nginx` para que PHP y Nginx compartan usuario **[Recomendación]**:

```bash
sudo sed -i -e 's/^user = apache/user = nginx/' -e 's/^group = apache/group = nginx/' /etc/php-fpm.d/www.conf
grep -E '^(user|group|listen|listen.acl_users) ' /etc/php-fpm.d/www.conf
# esperado: user = nginx, group = nginx, listen = /run/php-fpm/www.sock, listen.acl_users = apache,nginx
  sudo chgrp -R nginx /var/lib/php/session /var/lib/php/wsdlcache /var/lib/php/opcache 2>/dev/null
  sudo systemctl enable --now php-fpm
```

Tras una actualización del paquete `php-fpm`, revisa que `www.conf` siga con `nginx` (si dnf deja un `www.conf.rpmnew`, el tuyo se conserva).

---

## 3. Usuario de sistema, directorios, permisos y SELinux

- `contactcenter`: dueño del código, despliega y compila **[Recomendación]**.
- `nginx`: usuario de Nginx y de PHP-FPM. Solo escribe donde el código lo necesita.

```bash
sudo useradd -m -s /bin/bash contactcenter
sudo mkdir -p /var/www/contact-center
sudo chown contactcenter:nginx /var/www/contact-center
sudo chmod 750 /var/www/contact-center
```

Carpetas en las que escribe la aplicación:

| Carpeta | Quién escribe |
|---|---|
| `storage/logs/` | `App\Support\Logger` (app, mail, audit; rotación diaria, 14 días) |
| `storage/attachments/<id>/` | `Attachments::storageDir()` (adjuntos de campañas programadas/encoladas) |
| `storage/backups/` | `bin/backup-db.php` por defecto (aquí se usa `/var/lib/contact-center/backups`, §12.2) |
| `public/uploads/` | `UploadController` (imágenes de los editores) |

### 3.1 Contextos y booleanos SELinux (una sola vez)

```bash
APP=/var/www/contact-center
sudo semanage fcontext -a -t httpd_sys_content_t    "$APP(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "$APP/storage(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "$APP/public/uploads(/.*)?"
# PHP-FPM necesita conexiones salientes: AWS SES, Gupshup, IT Cloud y la API de voz (puerto 8000).
sudo setsebool -P httpd_can_network_connect on
```

Sin `httpd_can_network_connect`, los envíos de email/SMS, el webhook y las llamadas fallan con errores de conexión aunque la red funcione.

### 3.2 Bloque de permisos (se repite tras cada despliegue)

```bash
APP=/var/www/contact-center
sudo mkdir -p $APP/storage/logs $APP/storage/attachments $APP/storage/backups $APP/public/uploads
sudo chown -R contactcenter:nginx $APP
sudo chmod -R u=rwX,g=rX,o= $APP
sudo chown -R nginx:nginx $APP/storage $APP/public/uploads
sudo chmod -R u=rwX,g=rwX,o= $APP/storage $APP/public/uploads
[ -f $APP/.env ] && sudo chmod 640 $APP/.env
sudo restorecon -R $APP
```

El `.env` queda en la raíz del proyecto, fuera de lo que sirve Nginx.

---

## 4. Subir el código e instalar dependencias PHP

### Opción A: git

El respaldo no es un repositorio git. Si lo publicas en uno:

```bash
sudo -iu contactcenter git clone <URL_DEL_REPOSITORIO> /var/www/contact-center
```

### Opción B: desde Windows (tar + scp, incluidos en Windows 10/11)

En Windows (PowerShell):

```powershell
cd C:\xampp\htdocs\project-backup-pre-saas
tar -czf "$env:TEMP\contact-center.tgz" --exclude=node_modules --exclude=vendor --exclude=dist `
  --exclude=.env --exclude=.env.bak --exclude=storage --exclude=public/uploads `
  --exclude=.agents --exclude=.bolt --exclude=voice-samples .
scp "$env:TEMP\contact-center.tgz" <TU_USUARIO>@<IP_SERVIDOR>:/tmp/
Remove-Item "$env:TEMP\contact-center.tgz"
```

En el servidor:

```bash
sudo -u contactcenter tar -xzf /tmp/contact-center.tgz -C /var/www/contact-center
rm /tmp/contact-center.tgz
```

### Dependencias PHP

```bash
sudo -iu contactcenter bash -c "cd /var/www/contact-center && composer install --no-dev --optimize-autoloader --no-interaction"
```

---

## 5. Configuración `.env` de producción

```bash
sudo -iu contactcenter
cd /var/www/contact-center
cp .env.example .env
```

`.env.example` trae valores de **desarrollo** (`localhost:8000` / `localhost:5173`). Si se quedan así, el navegador intenta hacer el login contra `localhost:8000` del PC del usuario y falla con `NetworkError` / "Solicitud CORS sin éxito". Fíjalos ya (sustituye `<TU_DOMINIO_O_IP>`; con dominio y HTTPS: `https://<TU_DOMINIO>`; por IP sin HTTPS: `http://192.168.101.95`):

```bash
sed -i \
  -e 's|^VITE_API_URL=.*|VITE_API_URL=/api|' \
  -e 's|^APP_URL=.*|APP_URL=https://<TU_DOMINIO_O_IP>|' \
  -e 's|^CORS_ALLOWED_ORIGIN=.*|CORS_ALLOWED_ORIGIN=https://<TU_DOMINIO_O_IP>|' \
  -e 's|^APP_ENV=.*|APP_ENV=production|' .env
# Ejemplo por IP, sin HTTPS:
#   -e 's|^APP_URL=.*|APP_URL=http://192.168.101.95|' \
#   -e 's|^CORS_ALLOWED_ORIGIN=.*|CORS_ALLOWED_ORIGIN=http://192.168.101.95|'
grep -E '^(VITE_API_URL|APP_URL|CORS_ALLOWED_ORIGIN|APP_ENV)=' .env
vi .env          # resto de variables (o nano: sudo dnf install -y nano)
```

`VITE_API_URL` siempre es `/api` (mismo origen: Nginx pasa `/api/` a PHP-FPM). Nunca `http://<IP>:8000/api`: en producción nada escucha en el puerto 8000.

`.env.example` **no incluye** las variables de la API de voz: añádelas al final. Valores con espacios van entre comillas, p.ej. `MAIL_FROM_NAME="Contact Center"`.

| Variable | Para qué sirve | Valor recomendado |
|---|---|---|
| `DB_HOST` | Conexión PDO; `setup-db-user` y `backup-db` | `localhost` |
| `DB_DATABASE` | Nombre de la BD (fijo en `schema.sql`) | `contact_center` (no cambiar) |
| `DB_USERNAME` | Usuario de la app (`setup-db-user` rechaza `root`) | `cc_app` |
| `DB_PASSWORD` | Contraseña del usuario de la app | generada por `composer secrets` |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | Credenciales IAM para SES | las del usuario IAM de §13.1 |
| `AWS_REGION` | Región de SES | la de tus identidades SES, p.ej. `us-east-1` |
| `AWS_SESSION_TOKEN` | Solo credenciales temporales STS (`ASIA...`) | vacío |
| `MAIL_FROM` | Remitente de respaldo si no hay remitente por defecto en la app | vacío o una identidad verificada |
| `MAIL_FROM_NAME` | Nombre visible del remitente | `"Contact Center"` |
| `APP_ENV` | `production` oculta trazas y sube el nivel de log a `info` | `production` |
| `APP_DEBUG` | El código no la lee | `false` |
| `APP_URL` | Base de enlaces de baja e imágenes subidas; accesible desde Internet | `https://<TU_DOMINIO>` (sin `/api`, sin `/` final) |
| `CORS_ALLOWED_ORIGIN` | Cabecera `Access-Control-Allow-Origin` | `https://<TU_DOMINIO>` |
| `VITE_API_URL` | URL de la API **inyectada al compilar** el frontend | `/api` (no dejarla vacía) |
| `JWT_SECRET` | Firma HS256; con menos de 32 caracteres la autenticación no arranca | generado por `composer secrets` |
| `JWT_EXPIRY` | Validez del token de acceso (s) | `86400` |
| `JWT_REFRESH_EXPIRY` | Validez del refresh token (s) | `1209600` |
| `ADMIN_NAME` / `ADMIN_EMAIL` | Admin inicial (`seed-admin`) | tu nombre / tu correo |
| `ADMIN_PASSWORD` | Contraseña del admin inicial | generada por `composer secrets` |
| `TRUST_PROXY` | Leer la IP de `X-Forwarded-For` (límite de login) | `false` con Nginx en el mismo servidor |
| `SMS_PRICE_PER_SEGMENT` | Precio estimado por segmento SMS; `0` lo oculta | según tarifa del proveedor |
| `SMS_PRICE_CURRENCY` | Moneda del estimado | `USD` |
| `VOICE_API_URL` | Base de la API de voz | `http://192.168.101.92:8000` |
| `VOICE_API_KEY` | Cabecera `X-API-Key` hacia la API de voz | la que entregue el administrador de esa API |
| `VOICE_CALL_THROTTLE_MS` | Pausa entre llamadas de una campaña | `1500` |

`TRUST_PROXY=true` solo si hay otro proxy o balanceador delante que **reemplace** `X-Forwarded-For`: el código toma el primer valor y, si el proxy solo lo añade, el cliente puede falsear su IP.

Generar los secretos (escribe `JWT_SECRET`, `ADMIN_PASSWORD` y `DB_PASSWORD` sin mostrarlos; deja `.env.bak`):

```bash
php bin/generate-secrets.php        # = composer secrets
shred -u .env.bak                   # la copia también contiene secretos
exit
```

Aplica el bloque de permisos (§3.2).

---

## 6. Base de datos

```bash
sudo tee /etc/my.cnf.d/99-contact-center.cnf > /dev/null <<'EOF'
[mysqld]
bind-address = 127.0.0.1
EOF
sudo systemctl enable --now mariadb
sudo mysql_secure_installation
```

En Rocky, MariaDB escucha en todas las interfaces si no se fija `bind-address`. Respuestas sugeridas para `mysql_secure_installation` **[Recomendación]**: mantener la autenticación `unix_socket` de root, borrar usuarios anónimos, prohibir el login remoto de root, borrar `test` y recargar privilegios. Los pasos siguientes usan `sudo mysql` (root por `unix_socket`).

```bash
sudo mysql -e "SELECT VERSION(), @@time_zone, @@system_time_zone, @@bind_address"
sudo sh -c 'mysql < /var/www/contact-center/database/schema.sql'   # idempotente, crea contact_center
sudo php /var/www/contact-center/bin/setup-db-user.php             # = composer db-user
# esperado: "Conexión verificada con el usuario de la aplicación (usuarios en BD: 0)."
```

La redirección `<` va dentro de `sudo sh -c` y los scripts se llaman con ruta absoluta porque la carpeta es `750 contactcenter:nginx` (§3): con `sudo mysql < ...` o `cd /var/www/contact-center` como `<TU_USUARIO>` sale `Permission denied` / `Could not open input file` y no se crean ni el esquema ni `cc_app`.

Qué hace `bin/setup-db-user.php`:

- Lee `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` del `.env`; se niega si el usuario es `root` o la contraseña está vacía.
- Se conecta como `root` sin contraseña (con `sudo` entra por `unix_socket`). Si tu root tiene contraseña: `--admin-user=root --admin-password=<CLAVE>`.
- Crea o actualiza `cc_app@localhost` y `cc_app@127.0.0.1` con **solo** `SELECT, INSERT, UPDATE, DELETE` sobre `contact_center.*`.
- Prueba la conexión contando `users`, por eso el esquema va antes.

El usuario de la app no puede crear ni alterar tablas: el esquema siempre se aplica con `sudo mysql`.

**Migrar datos desde XAMPP (opcional):** en Windows, `C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction contact_center > contact_center.sql`; súbelo con `scp`, ejecuta `sudo mysql contact_center < contact_center.sql` y vuelve a aplicar `schema.sql`. Los `.sql.gz` de `storage/backups/` del respaldo también sirven: `gunzip -c <ARCHIVO>.sql.gz | sudo mysql contact_center`.

---

## 7. Administrador inicial

```bash
sudo -u nginx php /var/www/contact-center/bin/seed-admin.php     # = composer seed-admin
# esperado: "Admin sembrado/actualizado: <ADMIN_EMAIL>"
sudo mysql -e "SELECT id, email, role FROM contact_center.users"
```

Crea o actualiza el usuario `admin` con `ADMIN_EMAIL` / `ADMIN_PASSWORD` (bcrypt). Es idempotente. Se ejecuta como `nginx` para que los logs que cree queden con el dueño correcto. **[Recomendación]** Tras el primer login, cambia la contraseña en la app y vacía `ADMIN_PASSWORD` en el `.env`.

---

## 8. Compilar el frontend

Vite inyecta `VITE_API_URL` al compilar; si la cambias, recompila. Se pasa `/api` explícitamente para no depender del `.env`.

**Nunca subas el `dist/` que ya trae el respaldo**: está compilado con `http://localhost:8000/api` y el login falla siempre.

Opción A, en el servidor:

```bash
sudo -iu contactcenter bash -c "cd /var/www/contact-center && rm -rf dist && npm ci && VITE_API_URL=/api npm run build"
```

Compila siempre como `contactcenter`, nunca como `root`. Si ya compilaste como root, `rm` da "Permiso denegado" y se sigue sirviendo el bundle viejo. Arréglalo así:

```bash
sudo rm -rf /var/www/contact-center/dist /var/www/contact-center/node_modules
sudo chown -R contactcenter:nginx /var/www/contact-center
```

Opción B, en Windows:

```powershell
cd C:\xampp\htdocs\project-backup-pre-saas
$env:VITE_API_URL = "/api"; npm ci; npm run build; Remove-Item Env:VITE_API_URL
tar -czf "$env:TEMP\dist.tgz" -C dist .
scp "$env:TEMP\dist.tgz" <TU_USUARIO>@<IP_SERVIDOR>:/tmp/
```

```bash
sudo -u contactcenter bash -c "rm -rf /var/www/contact-center/dist && mkdir /var/www/contact-center/dist && tar -xzf /tmp/dist.tgz -C /var/www/contact-center/dist"
rm /tmp/dist.tgz
```

Comprueba el bundle (con cualquiera de las dos opciones). **No debe imprimir nada**; si imprime una URL (`http://localhost:8000/api`, `http://<IP>:8000/api`...), corrige `VITE_API_URL` y recompila:

```bash
sudo grep -rhoE 'https?://[a-zA-Z0-9.:-]+/api' /var/www/contact-center/dist/assets/ | sort -u
```

Con Nginx no hace falta el `dist/.htaccess` de `DEPLOYMENT.md` (es para Apache). Aplica el bloque de permisos (§3.2). Tras recompilar, recarga el navegador con Ctrl+F5.

---

## 9. Nginx

En Rocky no hay `sites-available`: los sitios van en `/etc/nginx/conf.d/`. Crea `/etc/nginx/conf.d/contact-center.conf`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name <TU_DOMINIO>;

    root /var/www/contact-center/dist;
    index index.html;

    # CSV 5 MB, adjuntos 7 MB en total, imágenes 3 MB (+ margen multipart).
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

    # API Slim: las rutas ya incluyen /api, se pasan tal cual al front controller.
    location ^~ /api/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/contact-center/public/index.php;
        fastcgi_param SCRIPT_NAME     /index.php;
        fastcgi_param DOCUMENT_ROOT   /var/www/contact-center/public;
        fastcgi_pass unix:/run/php-fpm/www.sock;
        # Envíos de hasta 100 destinatarios se procesan dentro de la petición
        # (set_time_limit(60 + 2*n), hasta ~260 s). Con 60 s (por defecto) daría 504.
        fastcgi_read_timeout 300s;
    }

    # Enlace de baja de correos/SMS: ruta de Slim fuera de /api.
    location ^~ /unsubscribe/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/contact-center/public/index.php;
        fastcgi_param SCRIPT_NAME     /index.php;
        fastcgi_param DOCUMENT_ROOT   /var/www/contact-center/public;
        fastcgi_pass unix:/run/php-fpm/www.sock;
    }

    # Imágenes de los editores (APP_URL/uploads/...). Solo estáticos, nunca PHP.
    location ^~ /uploads/ {
        alias /var/www/contact-center/public/uploads/;
        location ~ /\. { deny all; }
        location ~* \.(php\d?|phtml|phar|phps|pl|py|cgi|sh)$ { deny all; }
        expires 30d;
        access_log off;
    }

    # Assets con hash de Vite.
    location ^~ /assets/ {
        expires 1y;
        try_files $uri =404;
    }

    # index.html sin caché para que cada despliegue se vea al instante.
    location = /index.html {
        expires -1;
    }

    # SPA (react-router): toda ruta desconocida devuelve index.html.
    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ /\. { deny all; }
}
```

Solo se exponen `dist/`, `public/uploads/` y el front controller; `.env`, `vendor/`, `src/`, `bin/`, `database/`, `storage/` y `config/` quedan fuera. No hay `add_header` dentro de los `location` para no anular las cabeceras del bloque `server`.

El `server` por defecto de `/etc/nginx/nginx.conf` (`server_name _`) puede quedarse: atiende solo lo que no sea `<TU_DOMINIO>`. Si accedes por IP, sigue §11.1 (paso 2).

```bash
sudo nginx -t && sudo systemctl enable --now nginx
```

---

## 10. Firewall

```bash
sudo systemctl enable --now firewalld
sudo firewall-cmd --permanent --add-service=http --add-service=https
sudo firewall-cmd --reload
sudo firewall-cmd --list-services        # ssh http https (y cockpit/dhcpv6-client si venían)
```

- `ssh` ya viene permitido. No se abre `mysql`.
- **[Recomendación]** Si no usas Cockpit: `sudo firewall-cmd --permanent --remove-service=cockpit && sudo firewall-cmd --reload`.
- **[Recomendación]** fail2ban (EPEL) para SSH:

```bash
sudo dnf install -y fail2ban fail2ban-firewalld
sudo tee /etc/fail2ban/jail.local > /dev/null <<'EOF'
[sshd]
enabled = true
backend = systemd
EOF
sudo systemctl enable --now fail2ban
```

---

## 11. HTTPS (certbot)

Requiere un dominio público. Si accedes por IP, ve a §11.1.

```bash
sudo certbot --nginx -d <TU_DOMINIO> -m <TU_EMAIL> --agree-tos --redirect
sudo systemctl enable --now certbot-renew.timer     # en Rocky la renovación NO se activa sola
sudo certbot renew --dry-run
```

**[Recomendación]** Con HTTPS funcionando, añade en el bloque `server` de 443 y recarga Nginx:

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
```

### 11.1 Variante sin dominio (acceso por IP, HTTP)

Si el servidor se usa por IP privada (p.ej. `http://192.168.101.95`), certbot no sirve: Let's Encrypt no emite certificados para IPs privadas. Cambios respecto a la guía:

1. **`.env` (§5):** `VITE_API_URL=/api`, `APP_URL=http://192.168.101.95` y `CORS_ALLOWED_ORIGIN=http://192.168.101.95`. Recompila el frontend (§8).
2. **Nginx (§9):** en `contact-center.conf` cambia las líneas `listen` y `server_name`, y quita `default_server` del `server` por defecto de `nginx.conf` (si no, puede responder la página de bienvenida de Nginx o un 404 en `/login`):

   ```nginx
   listen 80 default_server;
   listen [::]:80 default_server;
   server_name 192.168.101.95 _;
   ```

   ```bash
   sudo sed -i 's/ default_server//' /etc/nginx/nginx.conf
   sudo nginx -t && sudo systemctl reload nginx
   ```

3. **Firewall (§10):** basta `--add-service=http`.
4. **Omite §11** (certbot y HSTS).
5. **Verificación (§15):** usa `http://192.168.101.95/...` en lugar de `https://<TU_DOMINIO>/...`; el `301` a HTTPS no aplica.
6. Los enlaces de baja/imágenes de los correos necesitan una URL accesible desde Internet; por IP privada no funcionan fuera de la LAN.

**Aviso de Firefox "Campos de contraseña presentes en una página insegura (http://)":** es esperado al servir el login por HTTP; no impide entrar. La contraseña viaja sin cifrar por la red, así que **[Recomendación]** usa HTTPS también en la LAN, con un certificado de tu CA interna o uno autofirmado (el navegador pedirá aceptarlo una vez):

```bash
sudo mkdir -p /etc/pki/contact-center
sudo openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
  -keyout /etc/pki/contact-center/cc.key -out /etc/pki/contact-center/cc.crt \
  -subj "/CN=192.168.101.95" -addext "subjectAltName=IP:192.168.101.95"
sudo chmod 600 /etc/pki/contact-center/cc.key
sudo restorecon -R /etc/pki/contact-center
```

En `contact-center.conf`, el `server` de §9 pasa a escuchar en 443 y se añade otro que redirige el 80:

```nginx
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name 192.168.101.95 _;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name 192.168.101.95 _;
    ssl_certificate     /etc/pki/contact-center/cc.crt;
    ssl_certificate_key /etc/pki/contact-center/cc.key;
    # ...resto del bloque de §9 (root, location /api/, /unsubscribe/, /uploads/, etc.)...
}
```

```bash
sudo firewall-cmd --permanent --add-service=https && sudo firewall-cmd --reload
sudo nginx -t && sudo systemctl reload nginx
```

Con HTTPS, `APP_URL` y `CORS_ALLOWED_ORIGIN` pasan a `https://192.168.101.95` (`VITE_API_URL` sigue en `/api`). Recarga PHP-FPM (`sudo systemctl reload php-fpm`) tras editar el `.env`.

---

## 12. Tareas programadas

### 12.1 Dispatcher (obligatorio)

`bin/dispatch-campaigns.php` (= `composer dispatch`), en cada pasada:

1. Devuelve a la cola los trabajos colgados más de 30 min.
2. Lanza las campañas `Scheduled` vencidas.
3. Consume hasta 5 trabajos de `jobs` (envíos de más de 100 destinatarios, o más de 15 llamadas).
4. Consulta el estado de las llamadas originadas hace 20 s o más; tras 10 min sin estado final las da por no contestadas.
5. Poda sesiones, intentos de login y trabajos antiguos.

Frecuencia: **cada minuto**. El resultado de una llamada aparece entre ~20 s y ~1:20 min después de marcar. Una campaña grande ocupa la pasada hasta terminar (~1 s por email, 1,5 s entre llamadas). El script tiene su propio `flock` y omite la pasada si otra sigue en marcha.

**Opción preferida: systemd service + timer** (como `nginx`, que lee el `.env` y escribe los logs):

```bash
sudo tee /etc/systemd/system/contact-center-dispatch.service > /dev/null <<'EOF'
[Unit]
Description=Contact Center - campañas programadas, cola de envíos y estado de llamadas
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
User=nginx
Group=nginx
WorkingDirectory=/var/www/contact-center
ExecStart=/usr/bin/php /var/www/contact-center/bin/dispatch-campaigns.php
EOF

sudo tee /etc/systemd/system/contact-center-dispatch.timer > /dev/null <<'EOF'
[Unit]
Description=Ejecuta el dispatcher de Contact Center cada minuto

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Unit=contact-center-dispatch.service

[Install]
WantedBy=timers.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now contact-center-dispatch.timer
journalctl -u contact-center-dispatch.service -n 20 --no-pager
```

systemd no lanza una segunda instancia mientras la anterior siga activa.

**Alternativa: cron** (usa solo una de las dos):

```bash
sudo systemctl enable --now crond
sudo tee /etc/cron.d/contact-center > /dev/null <<'EOF'
* * * * * nginx cd /var/www/contact-center && /usr/bin/flock -n /run/lock/contact-center-dispatch.lock /usr/bin/php bin/dispatch-campaigns.php >> storage/logs/dispatcher.log 2>&1
EOF

sudo tee /etc/logrotate.d/contact-center > /dev/null <<'EOF'
/var/www/contact-center/storage/logs/dispatcher.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
    su nginx nginx
}
EOF
```

### 12.2 Copias de seguridad

`bin/backup-db.php` (= `composer backup`) hace `mysqldump --single-transaction --routines --events`, comprime en `.sql.gz`, guarda en `storage/backups/` (o `--out=`) y conserva las últimas `--keep` (7 por defecto). Necesita un usuario administrativo (`cc_app` no sirve); por defecto usa `root` sin contraseña, que como root del sistema entra por `unix_socket`. En Rocky, `/usr/bin/mysqldump` lo instala el paquete `mariadb`.

**[Recomendación]** Fuera del árbol web, diario a las 02:30, 14 copias (Rocky no tiene `/var/backups`):

```bash
sudo install -d -m 700 -o root -g root /var/lib/contact-center/backups

sudo tee /etc/systemd/system/contact-center-backup.service > /dev/null <<'EOF'
[Unit]
Description=Contact Center - copia de seguridad de la base de datos
After=mariadb.service

[Service]
Type=oneshot
WorkingDirectory=/var/www/contact-center
ExecStart=/usr/bin/php /var/www/contact-center/bin/backup-db.php --keep=14 --out=/var/lib/contact-center/backups
EOF

sudo tee /etc/systemd/system/contact-center-backup.timer > /dev/null <<'EOF'
[Unit]
Description=Copia diaria de Contact Center

[Timer]
OnCalendar=*-*-* 02:30:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now contact-center-backup.timer
sudo systemctl start contact-center-backup.service && sudo ls -lh /var/lib/contact-center/backups
```

El volcado no incluye archivos. **[Recomendación]** Respalda también `.env`, `public/uploads/` y `storage/attachments/`, y copia todo fuera del servidor.

Prueba de restauración:

```bash
sudo mysql -e "CREATE DATABASE cc_restore_test"
sudo bash -c "gunzip -c /var/lib/contact-center/backups/<ARCHIVO>.sql.gz | mysql cc_restore_test"
sudo mysql -e "SELECT COUNT(*) FROM cc_restore_test.users; DROP DATABASE cc_restore_test;"
```

---

## 13. AWS (SES)

### 13.1 Usuario IAM con permisos mínimos

Acciones que el código usa realmente:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "ses:SendRawEmail",
        "ses:VerifyDomainIdentity",
        "ses:VerifyDomainDkim",
        "ses:VerifyEmailIdentity",
        "ses:GetIdentityVerificationAttributes",
        "ses:GetIdentityDkimAttributes",
        "ses:ListIdentities",
        "ses:DeleteIdentity"
      ],
      "Resource": "*"
    }
  ]
}
```

Crea claves permanentes (`AKIA...`) y ponlas en `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`. No hace falta `polly:*` (módulo Audio eliminado).

### 13.2 SES

1. Módulo **Verificación** de la app: verifica el dominio y crea en tu DNS los registros TXT y DKIM (CNAME) que muestra.
2. Crea el remitente (y el de respuesta/Reply-To si lo usas) sobre el dominio verificado y márcalo como predeterminado; tiene prioridad sobre `MAIL_FROM`.
3. **Producción:** la cuenta de SES de Vozip ya está fuera del sandbox en **us-east-1** (N. Virginia): 50.000 correos/día, 14/s, a cualquier destinatario sin verificarlo. Deja `AWS_REGION=us-east-1`; en otra región la cuenta sigue en sandbox y habría que pedir producción allí (*Account dashboard* → *Request production access*).

### 13.3 Estados de entrega: entregas, rebotes y rechazos

Flujo: SES (configuration set del cliente) → EventBridge (regla que solo deja pasar ese configuration set) → cola SQS del cliente → el dispatcher la lee cada minuto. Solo conexiones salientes: no hace falta dominio público.

- **Entregado:** el destinatario queda como entregado.
- **Rebote permanente:** el destinatario pasa a fallido y la dirección entra en **Supresiones**, así que no se le vuelve a enviar. **No se reembolsa**, porque SES sí envió el correo.
- **Rebote temporal** (buzón lleno, por ejemplo): se anota, pero la dirección no se bloquea.
- **Rechazado por SES:** el destinatario pasa a fallido.

Se activa una vez por cliente, con credenciales de AWS de administrador de Vozip. El comando las pide por la terminal (el secret no se muestra) y no se guardan:

```bash
APP=/var/www/contact-center
sudo -u contactcenter php $APP/bin/ses-events.php setup --name=clientexyz
sudo systemctl reload php-fpm
sudo -u nginx php $APP/bin/ses-events.php status
```

El comando `setup` crea o reutiliza estos recursos:
- el configuration set `cc-<nombre>`;
- la cola cifrada `cc-ses-events-<nombre>`, que guarda los eventos 14 días si el servidor está apagado;
- la regla `cc-ses-events-<nombre>`;
- el permiso `cc-ses-events` en el usuario IAM de la instalación, que solo deja leer su propia cola.

Permisos que necesitan las credenciales de administrador: `sts:GetCallerIdentity`, `ses:CreateConfigurationSet`, `ses:CreateConfigurationSetEventDestination`, `ses:UpdateConfigurationSetEventDestination`, `sqs:CreateQueue`, `sqs:GetQueueUrl`, `sqs:GetQueueAttributes`, `sqs:SetQueueAttributes`, `events:PutRule`, `events:PutTargets` e `iam:PutUserPolicy`.

**Costo:** prácticamente cero. Los eventos de SES entran gratis a EventBridge, y SQS incluye un millón de peticiones gratis al mes; con mucho volumen son unos pocos dólares al mes. La tasa de rebotes debe quedar por debajo del 5 % y la de quejas por debajo del 0,1 %. Las dos aparecen en Reportes y en la consola de SES (*Reputation metrics*).

---

## 14. API de voz (módulo Llamadas)

La API de voz (TTS + Asterisk, `/opt/apivoz`) se publica en **`http://indoor-qvmdkkckkpc.dynamic-m.com:8000`** (valor por defecto de `VOICE_API_URL`; en la LAN también responde en `192.168.101.92:8000`).

**`deploy.sh` crea solo el usuario y el token** de esta instalación:

1. Si `VOICE_API_KEY` ya es válida (`GET /auth/me` = 200), no hace nada.
2. Si hay `VOICE_API_USER`/`VOICE_API_PASSWORD` en el `.env`, recupera la key con `POST /auth/login`.
3. Si no, pide la **clave de administrador** de la API de voz (`ADMIN_SECRET_KEY` de `/opt/apivoz/.env`), registra el usuario `contact-center-<host>-xxxx` con `POST /auth/register` y guarda `VOICE_API_KEY`, `VOICE_API_USER` y `VOICE_API_PASSWORD` en el `.env`. La clave de administrador no se guarda en ningún sitio.

Con `curl ... | sudo bash` la clave se pide por la terminal (no se ve al escribir; Enter la omite). También se puede pasar sin preguntas:

```bash
curl -fsSL https://raw.githubusercontent.com/henry0295/contact-center/main/deploy.sh | sudo VOICE_ADMIN_KEY='<clave>' bash
# Más tarde, si se omitió:
sudo VOICE_ADMIN_KEY='<clave>' /var/www/contact-center/deploy.sh update
# A mano (como el usuario de la app):
sudo -iu contactcenter bash -c "cd /var/www/contact-center && VOICE_ADMIN_KEY='<clave>' php bin/voice-setup.php"
```

Otra URL: `--voice-url http://host:8000`. Para no tocar la API de voz: `--no-voice`. Tras cambiar el `.env`, `sudo systemctl reload php-fpm`.

Prueba de conectividad (cualquier código HTTP indica que llega):

```bash
curl -sS http://indoor-qvmdkkckkpc.dynamic-m.com:8000/        # {"status":"API OK",...}
```

Si funciona aquí pero la app dice que no conecta, revisa el booleano SELinux `httpd_can_network_connect` (§3.1).

### 14.0 Proveedor de SMS (IT Cloud Services)

Los SMS salen por **IT Cloud Services** (`contacto-virtual.com`). Cada cliente usa **su propia cuenta**: `deploy.sh` pide el **email** y el **token** de envío (se crea en la plataforma del proveedor, opción *Configuraciones*), los valida con la consulta de saldo del proveedor y los guarda en el `.env` (`SMS_PROVIDER=itcloud`, `ITCLOUD_SMS_EMAIL`, `ITCLOUD_SMS_TOKEN`).

```bash
# Sin preguntas:
curl -fsSL https://raw.githubusercontent.com/henry0295/contact-center/main/deploy.sh | sudo SMS_EMAIL='cliente@empresa.com' SMS_TOKEN='<token>' VOICE_ADMIN_KEY='<clave>' bash
# Configurar o cambiar más tarde:
sudo SMS_EMAIL='cliente@empresa.com' SMS_TOKEN='<token>' /var/www/contact-center/deploy.sh update
```

- El proveedor solo acepta envíos desde IPs autorizadas: el resumen final de `deploy.sh` muestra la **IP pública** del servidor; pásala al comercial del proveedor.
- Mensajes de hasta 757 caracteres. Los números se normalizan a prefijo de país + número (`3001234567` → `573001234567`).
- El campo "Remitente" se oculta en el módulo SMS: este proveedor envía con el remitente de la cuenta.
- **SMS doble vía:** en el compositor, "Permitir respuestas" envía con `type_send=2via`. Las respuestas llegan a `https://<host>/api/sms/inbound/<SMS_INBOUND_SECRET>` (`deploy.sh` genera el secreto y muestra la URL completa al final): **informa esa URL al proveedor**. Se ven en SMS → Respuestas, asociadas a la campaña; quien responde SALIR/STOP/BAJA queda dado de baja. La URL debe ser accesible desde Internet (con una IP privada no llegan respuestas). Si el secreto se filtra, bórralo del `.env`, ejecuta `deploy.sh update` y envía la URL nueva al proveedor.
- Las cuentas con perfil administrador no admiten la consulta de saldo: `deploy.sh` ofrece guardarlas sin validar.

### 14.0.1 phpMyAdmin

`deploy.sh` instala phpMyAdmin (versión oficial, verificada con SHA-256) en **`/phpmyadmin/`** del mismo sitio:

- Dos barreras: autenticación básica de Nginx y luego el login de phpMyAdmin. Ambas credenciales se generan una vez y quedan en `/etc/contact-center/phpmyadmin.txt` (solo root): `sudo cat /etc/contact-center/phpmyadmin.txt`.
- Usuario de BD propio `cc_dbadmin`, solo sobre `contact_center` y **sin acceso a las tablas de créditos** (`credit_*`): el saldo y los precios siguen siendo solo por consola. `root` no puede entrar por web.
- Por defecto solo se abre desde redes privadas (10/8, 172.16/12, 192.168/16). Restringir más: `--pma-allow 192.168.101.0/24,10.0.0.5`; abrirlo a cualquier IP: `--pma-allow any` (no recomendado). No instalarlo (o retirarlo): `--no-phpmyadmin`; volver a activarlo: `--phpmyadmin`. Las opciones se recuerdan en los siguientes `update`.
- Por HTTP las contraseñas viajan sin cifrar: usa `--https self-signed` o restringe por IP.

### 14.1 Saldo (créditos) y precios

Cada envío se descuenta de un saldo prepago en pesos: **SMS $27, email $20, llamada de voz $75** (valores iniciales). Reglas:

- Antes de enviar se comprueba que el saldo cubra todo el lote; si no, el envío se rechaza (HTTP 402).
- Se cobra por mensaje enviado y por llamada originada (aunque no contesten). Si un envío falla, se reembolsa. Si el saldo se acaba a mitad de una campaña programada, el resto se marca "Saldo insuficiente" y no se cobra.
- El saldo se ve en la barra superior. **Los precios nunca llegan al navegador** y **no hay ninguna pantalla ni endpoint para recargar**: solo por consola.

```bash
APP=/var/www/contact-center
sudo -u nginx php $APP/bin/credits.php recharge 100000 --note="Factura 123"
sudo -u nginx php $APP/bin/credits.php balance
sudo -u nginx php $APP/bin/credits.php history --limit=20
sudo -u nginx php $APP/bin/credits.php usage --since=2026-10-01
sudo -u nginx php $APP/bin/credits.php prices
sudo -u nginx php $APP/bin/credits.php set-price sms 27      # sms | email | call
sudo -u nginx php $APP/bin/credits.php adjust -5000 --note="Corrección"
```

(En Debian/Ubuntu el usuario es `www-data`.) Los valores van sin puntos de miles (`100000`, no `100.000`).

### 14.1.1 WhatsApp (Gupshup)

WhatsApp sale por la **Partner API de Gupshup**. Cada cliente tiene su propia línea (una "app" de Gupshup). En el `.env` solo queda el **token de la app de ese cliente** (`GUPSHUP_APP_ID`, `GUPSHUP_APP_TOKEN`). Las **credenciales de partner de Vozip** las pide el comando por la terminal (el secret no se muestra) y **nunca se guardan en el servidor**.

```bash
APP=/var/www/contact-center
# Crea la app (o reutiliza GUPSHUP_APP_ID), genera el enlace de registro, guarda el
# token, crea WHATSAPP_WEBHOOK_SECRET y registra el webhook si APP_URL es https.
# Pide el email de partner y el client secret de Gupshup:
sudo -u contactcenter php $APP/bin/whatsapp.php activate --name=ClienteXYZ
sudo systemctl reload php-fpm
```

- El cliente abre el **enlace de registro** (válido 5 días; también aparece en WhatsApp → Línea para administradores), entra con su Facebook, elige el portafolio y verifica el número.
- Otros comandos (los de partner también piden las credenciales): `link [--regenerate]`, `token`, `subscribe` (webhook) y `status` (solo token de la app: `sudo -u nginx php $APP/bin/whatsapp.php status`).
- Con `deploy.sh`: si se exportan `GUPSHUP_PARTNER_EMAIL`/`GUPSHUP_PARTNER_SECRET`, activa la línea solo (`--wa-name <nombre>`, por defecto el hostname; `--no-whatsapp` lo omite).
- **Webhook** (estados de entrega, respuestas, aprobación de plantillas): `<APP_URL>/api/webhooks/whatsapp`, autenticado con la cabecera `X-Gupshup-Webhook-Secret`. Requiere **dominio público con https y certificado válido** (Gupshup rechaza IPs y certificados autofirmados); sin eso los envíos salen pero no llegan estados ni respuestas. Tras configurar el dominio: `whatsapp.php subscribe`.
- **Dominio sin abrir el puerto 80** (DNS en cPanel, p.ej. Dongee): crea en el Zone Editor el registro A `cc.midominio.com → <IP pública>` y un API token en cPanel → Seguridad → Manage API Tokens. Luego:

  ```bash
  sudo $APP/deploy.sh update --host cc.midominio.com --https letsencrypt-dns --email soporte@midominio.com
  sudo -u contactcenter php $APP/bin/whatsapp.php subscribe
  ```

  Si la red del cliente no deja salir al puerto 2083 de cPanel, usa `--dns duckdns`: crea en www.duckdns.org un subdominio (p.ej. `vozip-unicuces`), y en el DNS del dominio un CNAME `_acme-challenge.cc` → `vozip-unicuces.duckdns.org`. `deploy.sh` pide el subdominio y el token de DuckDNS y solo usa el 443.

  Con `--dns cpanel` (por defecto), `deploy.sh` pide el servidor de cPanel (p.ej. `midominio.com`, puerto 2083 por defecto), el usuario y el token; valida con un registro TXT (acme.sh), instala el certificado en Nginx y lo renueva solo (`contact-center-acme.timer`). Solo se necesita el 443 abierto hacia el servidor. El token queda en `/root/.acme.sh/account.conf` (solo root).
- **Plantillas**: solo se envían las aprobadas por Meta. El administrador las crea (texto, hasta 3 botones) o las sincroniza en WhatsApp → Plantillas.
- **Precios** por categoría de plantilla: marketing $90, utilidad $20, autenticación $20 (`credits.php set-price whatsapp_marketing 90`, `whatsapp_utility`, `whatsapp_authentication`). Si WhatsApp informa que no entregó el mensaje, se reembolsa.
- Quien responda **SALIR / STOP / BAJA** queda dado de baja.

### 14.2 Emails de respuesta (Reply-To)

En **Verificación → Email de respuesta** se pueden agregar varias direcciones (de cualquier dominio; no necesitan verificación en SES porque solo van en la cabecera Reply-To). En el **módulo Email** hay un selector para elegir cuál usar en cada envío, o "Sin email de respuesta". Las campañas programadas conservan la elección.

---

## 15. Verificación post-instalación

- [ ] `systemctl is-active nginx php-fpm mariadb` → `active` ×3.
- [ ] `getenforce` → `Enforcing`, y `sudo ausearch -m avc -ts recent` sin denegaciones de `nginx`/`php-fpm`.
- [ ] `curl -i https://<TU_DOMINIO>/api/auth/me` → `401` con `"Token no proporcionado"` (Slim responde).
- [ ] `curl -i https://<TU_DOMINIO>/unsubscribe/prueba` → `400` "Enlace no válido" del backend.
- [ ] `curl -I https://<TU_DOMINIO>/.env` → `403`.
- [ ] `curl -I http://<TU_DOMINIO>` → `301` a HTTPS.
- [ ] Navegador: `https://<TU_DOMINIO>/login`, entra con el admin, recarga en `/reports` y cambia la contraseña.
- [ ] Dominio y remitente verificados en el módulo Verificación.
- [ ] Envío de prueba del módulo Email a una dirección tuya; luego `sudo tail /var/www/contact-center/storage/logs/mail-$(date +%F).log`.
- [ ] Una imagen del editor carga desde `https://<TU_DOMINIO>/uploads/...`.
- [ ] Llamadas: `/api/calls/capabilities` → `configured: true`; llamada de prueba a tu número y resultado en ~1 min.
- [ ] Dispatcher: `systemctl list-timers | grep contact-center` y `journalctl -u contact-center-dispatch.service -n 5`. Programa una campaña a +2 min y comprueba que sale a su hora.
- [ ] Hay un `.sql.gz` en `/var/lib/contact-center/backups` y la restauración de prueba funciona.

Logs:

| Log | Ruta |
|---|---|
| Aplicación | `/var/www/contact-center/storage/logs/app-AAAA-MM-DD.log` |
| Envíos email/SMS/llamadas, rebotes | `/var/www/contact-center/storage/logs/mail-AAAA-MM-DD.log` |
| Auditoría | `/var/www/contact-center/storage/logs/audit-AAAA-MM-DD.log` |
| Dispatcher | `journalctl -u contact-center-dispatch.service` (o `storage/logs/dispatcher.log` con cron) |
| Nginx | `/var/log/nginx/contact-center.access.log`, `/var/log/nginx/contact-center.error.log` |
| PHP-FPM | `/var/log/php-fpm/error.log`, `/var/log/php-fpm/www-error.log` |
| MariaDB | `/var/log/mariadb/mariadb.log`, `journalctl -u mariadb` |
| SELinux | `sudo ausearch -m avc -ts recent` (`/var/log/audit/audit.log`) |

---

## 16. Actualizaciones y rollback

```bash
APP=/var/www/contact-center

# 1) Pausar el dispatcher y esperar a que termine la pasada en curso
sudo systemctl stop contact-center-dispatch.timer
while systemctl is-active --quiet contact-center-dispatch.service; do sleep 5; done

# 2) Respaldo de BD y copia del código actual (para rollback)
sudo systemctl start contact-center-backup.service
sudo rsync -a --delete --exclude=node_modules $APP/ /var/www/contact-center.prev/

# 3) Código nuevo: git pull...
sudo -u contactcenter git -C $APP pull
#   ...o el tgz de §4 subido a /tmp:
# sudo -u contactcenter mkdir -p /tmp/cc-new && sudo -u contactcenter tar -xzf /tmp/contact-center.tgz -C /tmp/cc-new
# sudo -u contactcenter rsync -a --delete --exclude=.env --exclude=storage/ --exclude=public/uploads/ \
#     --exclude=vendor/ --exclude=node_modules/ --exclude=dist/ /tmp/cc-new/ $APP/
# sudo rm -rf /tmp/cc-new /tmp/contact-center.tgz

# 4) Dependencias y frontend
sudo -iu contactcenter bash -c "cd $APP && composer install --no-dev --optimize-autoloader --no-interaction && npm ci && VITE_API_URL=/api npm run build"

# 5) Esquema (idempotente) y permisos + SELinux
sudo sh -c "mysql < $APP/database/schema.sql"
#   ...repite el bloque de §3.2 (incluye restorecon)...

# 6) Recargar PHP (limpia OPcache) y reanudar el dispatcher
sudo systemctl reload php-fpm
sudo systemctl start contact-center-dispatch.timer
```

Repasa los puntos clave de §15 y revisa si `.env.example` trae variables nuevas.

**Rollback:**

```bash
sudo systemctl stop contact-center-dispatch.timer
sudo rsync -a --delete --exclude=.env --exclude=storage/ --exclude=public/uploads/ --exclude=node_modules/ \
  /var/www/contact-center.prev/ /var/www/contact-center/
#   ...bloque de §3.2...
sudo systemctl reload php-fpm && sudo systemctl start contact-center-dispatch.timer
```

`schema.sql` solo añade tablas, columnas e índices, así que la versión anterior suele funcionar con el esquema nuevo. Si no, restaura la copia del paso 2 (`gunzip -c <ARCHIVO>.sql.gz | sudo mysql contact_center`); se pierden los datos posteriores.

**Actualizaciones del sistema:** `sudo dnf -y update` respeta los module streams habilitados (PHP, Node, MariaDB). Tras actualizar `php-fpm`, comprueba `user = nginx` en `www.conf` y el grupo de `/var/lib/php/session` (§2.8).

---

## 17. Solución de problemas

| Síntoma | Causa probable / qué revisar |
|---|---|
| `composer install` exige PHP `^8.2` | Quedó el PHP por defecto de Rocky: habilita el stream de §2.2 y reinstala los paquetes `php-*`. |
| **502 Bad Gateway** | PHP-FPM caído o socket distinto: `systemctl status php-fpm`, `ls -l /run/php-fpm/`; `listen.acl_users` debe incluir `nginx`. Ver `contact-center.error.log`. |
| **403** de Nginx con permisos Unix correctos | Contexto SELinux: `ls -Z /var/www/contact-center` (debe ser `httpd_sys_content_t`) y `sudo restorecon -R /var/www/contact-center`. |
| **500**, logs o subidas que no se escriben | `storage/` y `public/uploads/` sin `httpd_sys_rw_content_t` o sin dueño `nginx` (§3.1–3.2). El logger se desactiva en silencio si no puede escribir. Revisa `ausearch -m avc`. |
| Email/SMS fallan con error de conexión, el webhook no confirma, "No se pudo conectar con la API de llamadas" | Booleano `httpd_can_network_connect` apagado (`getsebool httpd_can_network_connect`), sin ruta a la API de voz (§14) o `VOICE_API_KEY` errónea. |
| **504** al enviar | `fastcgi_read_timeout 300s` en `/api/`; para envíos grandes, programa la campaña. |
| **500** en toda la API | `.env` ilegible para `nginx`, falta `vendor/`, credenciales de BD erróneas o `JWT_SECRET` ausente/corto. Revisa `app-*.log` y `/var/log/php-fpm/www-error.log`. |
| El login llama a `localhost:8000` o la página queda en blanco | Frontend compilado con otro `VITE_API_URL`: pon `/api` y recompila (§8). |
| Firefox: `NetworkError when attempting to fetch resource.` al iniciar sesión (con `curl` el login sí responde) | El bundle se compiló con una URL absoluta (`http://localhost:8000/api`, `https://` sin certificado, un dominio sin DNS...): la petición no llega a Nginx. En F12 → Red mira la URL de `login`; debe ser `http://<IP_o_dominio>/api/auth/login`. Pon `VITE_API_URL=/api`, recompila (§8), comprueba el bundle con el `grep` de §8 y recarga con Ctrl+F5. |
| Consola: `Solicitud de origen cruzado bloqueada ... http://<IP>:8000/api/auth/login (Razón: Solicitud CORS sin éxito). Código de estado: (null)` | Igual que la fila anterior: el bundle apunta al puerto 8000, donde no escucha nada (`(null)` = no hubo respuesta; no es un problema de `CORS_ALLOWED_ORIGIN`). Pon `VITE_API_URL=/api`, recompila (§8) y Ctrl+F5. |
| Firefox: "Campos de contraseña presentes en una página insegura (http://)" | Aviso esperado al servir por HTTP; no bloquea el login. Para quitarlo, HTTPS (§11 o §11.1). |
| **Errores CORS** | Solo con SPA y API en orígenes distintos; `CORS_ALLOWED_ORIGIN` = origen exacto sin `/` final. |
| **401** justo tras iniciar sesión o sesiones que caducan antes | Zona horaria distinta entre PHP y MariaDB o reloj sin sincronizar (§2.6). Cambiar `JWT_SECRET` invalida todas las sesiones. |
| **429** en el login | 5 fallos por cuenta / 20 por IP en 15 min. |
| Campañas programadas no salen o salen a otra hora | Timer inactivo (`systemctl list-timers`) o zona horaria (§2.6). |
| Email rechazado: *Email address is not verified* | Remitente (o su dominio) sin verificar en la región de `AWS_REGION`, o `AWS_REGION` distinta de us-east-1 (§13.2). |
| El certificado caducó | `certbot-renew.timer` no habilitado (§11). |
| Las imágenes no se ven en los correos | `APP_URL` no es la URL pública HTTPS o `/uploads/` no está mapeado (§9). |

---

## Notas

Diferencias de Rocky 9 frente a la guía de Ubuntu (`INSTALACION-LINUX.md`):

- Paquetes con `dnf` y module streams; el PHP por defecto no cumple `^8.2`. Composer se instala con el instalador oficial y certbot/fail2ban vienen de EPEL.
- PHP-FPM corre como `apache` por defecto; aquí se pasa a `nginx`. Socket en `/run/php-fpm/www.sock`; configuración en `/etc/php.d/` y `/etc/php-fpm.d/www.conf`.
- SELinux Enforcing: contextos `httpd_sys_content_t` / `httpd_sys_rw_content_t` y booleano `httpd_can_network_connect`.
- `firewalld` en lugar de `ufw`; sitios Nginx en `conf.d/`; MariaDB sin `bind-address` escucha en todas las interfaces; `certbot-renew.timer` hay que habilitarlo; copias en `/var/lib/contact-center/backups`.

Las discrepancias entre `DEPLOYMENT.md`/`README.md` y el código (rutas `/unsubscribe` y `/uploads` con `Alias /api`, Polly, `ses:GetSendQuota`, variables `VOICE_*` ausentes de `.env.example`, `APP_DEBUG` sin uso, `TRUST_PROXY`, zona horaria, timeout de Nginx, etc.) son las mismas y están en la sección "Notas y discrepancias" de `INSTALACION-LINUX.md`.
