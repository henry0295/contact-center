# Instalación en servidor Linux (producción) — Contact Center

> Este documento corresponde a la versión previa a la conversión SaaS (respaldo del 15/10/2026, carpeta `project-backup-pre-saas`). La versión multi-tenant requerirá pasos adicionales.

Convenciones:

- `<TU_DOMINIO>`, `<IP_SERVIDOR>`, `<TU_EMAIL>`, `<ZONA_HORARIA>`, etc.: sustitúyelos por tus datos.
- **[Recomendación]** marca lo que propone este documento y no exige el código del proyecto.
- Los comandos se ejecutan en el servidor salvo que se indique "en Windows".

Arquitectura resultante (un solo dominio, mismo origen; no hace falta CORS):

| URL | Lo atiende | Origen en el código |
|---|---|---|
| `https://<TU_DOMINIO>/` | Nginx sirve el SPA compilado (`dist/`) | `vite build` |
| `https://<TU_DOMINIO>/api/...` | PHP-FPM → `public/index.php` (Slim) | rutas `/api/*` de `public/index.php` |
| `https://<TU_DOMINIO>/unsubscribe/<token>` | PHP-FPM → `public/index.php` | ruta pública `/unsubscribe/{token}`; el enlace se arma como `APP_URL + /unsubscribe/` (`CampaignService::unsubscribeUrl`) |
| `https://<TU_DOMINIO>/uploads/<archivo>` | Nginx, estático desde `public/uploads/` | `UploadController` arma `APP_URL + /uploads/` |

---

## 1. Requisitos

- **Sistema operativo:** Ubuntu Server 24.04 LTS (*supuesto* de este documento). Trae PHP 8.3 (la versión con la que se desarrolló) y MariaDB 10.11.
- **Hardware [Recomendación]:** 2 vCPU, 4 GB de RAM, 20 GB de disco. `npm run build` es lo que más memoria consume. (El README pide 4 núcleos y 8 GB; para esta aplicación es más de lo necesario).
- **Dominio** con un registro DNS `A` apuntando a la IP pública del servidor (lo necesitan HTTPS, el webhook de SNS y los enlaces de baja/imágenes de los correos).
- **Puertos:**
  - Entrada: 22 (SSH), 80 y 443 (HTTP/HTTPS).
  - Salida: 443 (AWS SES/SNS y certificados de firma de SNS), **8000 hacia 192.168.101.92** (API de voz, ver §14), 53 y 123 (DNS/NTP).
  - MariaDB (3306) **no** se expone.
- **Base de datos: MariaDB obligatorio.** `database/schema.sql` usa `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS`, que MySQL 8 no admite.
- **Extensiones PHP** (sacadas de `composer.lock` y del código):

| Extensión | Por qué | Paquete Ubuntu |
|---|---|---|
| `pdo_mysql` | `src/Database/Connection.php` (PDO MySQL) | `php8.3-mysql` |
| `curl` | Guzzle (API de voz) y AWS SDK | `php8.3-curl` |
| `mbstring` | `mb_strlen`/`mb_substr` en el código | `php8.3-mbstring` |
| `simplexml`, `dom`, `xml` | `aws/aws-sdk-php` exige `ext-simplexml` | `php8.3-xml` |
| `openssl` | `aws/aws-php-sns-message-validator` (firma del webhook) | incluida |
| `json`, `pcre`, `ctype`, `filter`, `hash`, `iconv` | AWS SDK, Slim, PHPMailer, phpdotenv | incluidas |
| `zlib` | `gzopen` en `bin/backup-db.php` | incluida |
| `opcache` | rendimiento **[Recomendación]** | `php8.3-opcache` |
| `zip` | acelera `composer install` **[Recomendación]** | `php8.3-zip` |

`intl` no es necesaria: ningún paquete la exige.

---

## 2. Paquetes del sistema

```bash
sudo apt update && sudo apt -y upgrade
sudo apt install -y nginx mariadb-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml \
  php8.3-opcache php8.3-zip \
  composer git unzip rsync certbot python3-certbot-nginx
php -v && php -m | grep -Ei 'pdo_mysql|curl|mbstring|simplexml|openssl|zlib'
```

**Node.js (solo para compilar el frontend).** Opción A, en el servidor con NodeSource (LTS 22) **[Recomendación]**:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x -o /tmp/nodesource_setup.sh
sudo bash /tmp/nodesource_setup.sh && rm /tmp/nodesource_setup.sh
sudo apt install -y nodejs
node -v && npm -v
```

Opción B: no instalar Node en el servidor y compilar en Windows (ver §8).

### 2.1 Zona horaria (obligatorio)

El código mezcla horas de PHP (`date()`, `time()`) con `NOW()` de MariaDB, y la fecha de "Programar" llega del navegador como hora local (`datetime-local`) y se compara con `NOW()`. Si el servidor está en UTC y los usuarios en otra zona, **las campañas programadas salen a destiempo** y las sesiones pueden caducar mal. Servidor, MariaDB y PHP deben usar la zona de los usuarios:

```bash
sudo timedatectl set-timezone <ZONA_HORARIA>     # p.ej. America/Bogota
timedatectl                                     # "System clock synchronized: yes"
sudo systemctl restart mariadb
```

### 2.2 Configuración de PHP para producción

```bash
sudo tee /etc/php/8.3/fpm/conf.d/99-contact-center.ini > /dev/null <<'EOF'
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
; El webhook de SNS confirma la suscripción y valida la firma con file_get_contents()
allow_url_fopen = On
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 60
EOF

sudo tee /etc/php/8.3/cli/conf.d/99-contact-center.ini > /dev/null <<'EOF'
date.timezone = <ZONA_HORARIA>
EOF

sudo systemctl restart php8.3-fpm
```

Los límites de subida derivan de `ContactController` (5 MB), `Attachments` (5 MB por archivo, 7 MB total, 10 archivos) y `UploadController` (3 MB); los valores exactos son **[Recomendación]**.

---

## 3. Usuario de sistema y directorios

- `contactcenter`: dueño del código, despliega y compila. **[Recomendación]**
- `www-data`: usuario de PHP-FPM y Nginx (predeterminado en Ubuntu). Solo escribe donde el código lo necesita.

```bash
sudo adduser --disabled-password --gecos "" contactcenter
sudo mkdir -p /var/www/contact-center
sudo chown contactcenter:www-data /var/www/contact-center
sudo chmod 750 /var/www/contact-center
```

Carpetas en las que escribe la aplicación (según el código):

| Carpeta | Quién escribe |
|---|---|
| `storage/logs/` | `App\Support\Logger` (app, mail, audit; rotación diaria y 14 días de retención) |
| `storage/attachments/<id>/` | `Attachments::storageDir()`, adjuntos de campañas programadas/encoladas |
| `storage/backups/` | `bin/backup-db.php` por defecto (aquí se usa `/var/backups/contact-center`, ver §12.2) |
| `public/uploads/` | `UploadController`, imágenes de los editores |

### 3.1 Bloque de permisos (se repite tras cada despliegue)

```bash
APP=/var/www/contact-center
sudo mkdir -p $APP/storage/logs $APP/storage/attachments $APP/storage/backups $APP/public/uploads
sudo chown -R contactcenter:www-data $APP
sudo chmod -R u=rwX,g=rX,o= $APP
sudo chown -R www-data:www-data $APP/storage $APP/public/uploads
sudo chmod -R u=rwX,g=rwX,o= $APP/storage $APP/public/uploads
[ -f $APP/.env ] && sudo chown contactcenter:www-data $APP/.env && sudo chmod 640 $APP/.env
```

El `.env` queda en la raíz del proyecto, fuera de lo que sirve Nginx (`dist/` y rutas concretas de `public/`).

---

## 4. Subir el código e instalar dependencias PHP

### Opción A: git

El respaldo no es un repositorio git. Si lo publicas en uno:

```bash
sudo -iu contactcenter
git clone <URL_DEL_REPOSITORIO> /var/www/contact-center
```

### Opción B: desde Windows (tar + scp, incluidos en Windows 10/11)

En Windows (PowerShell):

```powershell
cd C:\xampp\htdocs\project-backup-pre-saas
tar -czf "$env:TEMP\contact-center.tgz" --exclude=node_modules --exclude=vendor --exclude=dist `
  --exclude=.env --exclude=.env.bak --exclude=storage --exclude=public/uploads `
  --exclude=.agents --exclude=.bolt --exclude=voice-samples .
scp "$env:TEMP\contact-center.tgz" contactcenter@<IP_SERVIDOR>:/tmp/
Remove-Item "$env:TEMP\contact-center.tgz"
```

En el servidor:

```bash
sudo -iu contactcenter
tar -xzf /tmp/contact-center.tgz -C /var/www/contact-center && rm /tmp/contact-center.tgz
```

### Dependencias PHP

Como `contactcenter`:

```bash
cd /var/www/contact-center
composer install --no-dev --optimize-autoloader --no-interaction
```

---

## 5. Configuración `.env` de producción

```bash
cd /var/www/contact-center
cp .env.example .env
# .env.example trae valores de desarrollo (localhost:8000 / localhost:5173); fíjalos ya.
# Sustituye <TU_DOMINIO_O_IP> (por IP sin HTTPS: http://192.168.101.95).
sed -i \
  -e 's|^VITE_API_URL=.*|VITE_API_URL=/api|' \
  -e 's|^APP_URL=.*|APP_URL=https://<TU_DOMINIO_O_IP>|' \
  -e 's|^CORS_ALLOWED_ORIGIN=.*|CORS_ALLOWED_ORIGIN=https://<TU_DOMINIO_O_IP>|' .env
nano .env
```

`.env.example` **no incluye** las variables de la API de voz. Añádelas al final (ver tabla). Valores con espacios van entre comillas (phpdotenv falla sin ellas), p.ej. `MAIL_FROM_NAME="Contact Center"`.

| Variable | Para qué sirve (código que la lee) | Valor recomendado |
|---|---|---|
| `DB_HOST` | Conexión PDO; `setup-db-user` y `backup-db` | `localhost` |
| `DB_DATABASE` | Nombre de la BD. `schema.sql` lo tiene fijo | `contact_center` (no cambiar) |
| `DB_USERNAME` | Usuario de la app (no puede ser `root`, lo rechaza `setup-db-user`) | `cc_app` |
| `DB_PASSWORD` | Contraseña del usuario de la app | generada por `composer secrets` |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | Credenciales IAM para SES y SNS (`Support/Aws.php`) | las del usuario IAM de §13.1 |
| `AWS_REGION` | Región de SES/SNS | la de tus identidades SES, p.ej. `us-east-1` |
| `AWS_SESSION_TOKEN` | Solo para credenciales temporales STS (`ASIA...`) | vacío |
| `MAIL_FROM` | Remitente de respaldo si no hay remitente por defecto en la app (`EmailService`) | vacío o una identidad verificada |
| `MAIL_FROM_NAME` | Nombre visible del remitente | `"Contact Center"` |
| `APP_ENV` | `production`: oculta trazas en respuestas y sube el nivel de log a `info` | `production` |
| `APP_DEBUG` | El código no la lee (ver Notas) | `false` |
| `APP_URL` | Base de los enlaces de baja y de las imágenes subidas; debe ser accesible desde Internet | `https://<TU_DOMINIO>` (sin `/api`, sin `/` final) |
| `CORS_ALLOWED_ORIGIN` | Cabecera `Access-Control-Allow-Origin` (`public/index.php`) | `https://<TU_DOMINIO>` |
| `VITE_API_URL` | URL de la API **inyectada al compilar** el frontend (`src/services/api.ts`) | `/api` (no dejarla vacía) |
| `JWT_SECRET` | Firma HS256 de los tokens; menos de 32 caracteres = la autenticación no arranca | generado por `composer secrets` (64 hex) |
| `JWT_EXPIRY` | Validez del token de acceso, en segundos | `86400` |
| `JWT_REFRESH_EXPIRY` | Validez del refresh token, en segundos | `1209600` |
| `ADMIN_NAME` / `ADMIN_EMAIL` | Datos del admin inicial (`seed-admin`) | tu nombre / tu correo |
| `ADMIN_PASSWORD` | Contraseña del admin inicial (solo la usa `seed-admin`) | generada por `composer secrets` |
| `SNS_VERIFY_SIGNATURE` | Valida la firma de las notificaciones de SNS en el webhook | `true` (siempre) |
| `TRUST_PROXY` | Leer la IP del cliente de `X-Forwarded-For` (límite de intentos de login) | `false` (ver nota) |
| `SMS_PRICE_PER_SEGMENT` | Precio estimado por segmento de SMS; `0` oculta el importe | según tarifa SNS del país |
| `SMS_PRICE_CURRENCY` | Moneda del estimado | `USD` |
| `VOICE_API_URL` | Base de la API de voz (`POST tts-call`, `GET call-status/{id}`) | `http://192.168.101.92:8000` |
| `VOICE_API_KEY` | API Key enviada en la cabecera `X-API-Key` | la que te entregue el administrador de la API de voz |
| `VOICE_CALL_THROTTLE_MS` | Pausa entre llamadas de una misma campaña | `1500` |

`TRUST_PROXY`: con Nginx y PHP-FPM en el mismo servidor, `REMOTE_ADDR` ya es la IP real del cliente; déjalo en `false`. Ponlo en `true` solo si hay otro proxy o balanceador delante, y configúralo para que **reemplace** `X-Forwarded-For`: el código toma el primer valor de esa cabecera, y si el proxy solo lo añade al final, el cliente puede falsearlo.

Generar los secretos (escribe `JWT_SECRET`, `ADMIN_PASSWORD` y `DB_PASSWORD` en el `.env` sin mostrarlos y deja una copia en `.env.bak`):

```bash
cd /var/www/contact-center
php bin/generate-secrets.php        # = composer secrets
shred -u .env.bak                   # la copia también contiene secretos
```

Luego aplica el bloque de permisos (§3.1) para dejar el `.env` en `640 contactcenter:www-data`.

---

## 6. Base de datos

```bash
sudo mysql_secure_installation
```

Respuestas sugeridas **[Recomendación]**: mantener la autenticación `unix_socket` de root, borrar los usuarios anónimos, prohibir el login remoto de root, borrar la base `test` y recargar privilegios. Los pasos siguientes usan `sudo mysql`, que entra como root por `unix_socket` sin contraseña.

Comprueba que MariaDB solo escucha en local:

```bash
grep -R "^bind-address" /etc/mysql/mariadb.conf.d/     # 127.0.0.1
```

Crear la base y las tablas. El script es idempotente y crea `contact_center` si no existe:

```bash
sudo sh -c 'mysql < /var/www/contact-center/database/schema.sql'
```

Crear el usuario de mínimos privilegios (ruta absoluta: la carpeta es `750` y tu usuario no puede hacer `cd` en ella):

```bash
sudo php /var/www/contact-center/bin/setup-db-user.php      # = composer db-user, ejecutado como root del sistema
```

Qué hace `bin/setup-db-user.php`:

- Lee `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` del `.env`. Se niega a continuar si el usuario es `root` o la contraseña está vacía.
- Se conecta como `root` (por defecto sin contraseña; con `sudo` funciona por `unix_socket`). Si tu root tiene contraseña, añade `--admin-user=root --admin-password=<CLAVE>`.
- Crea o actualiza `cc_app@localhost` y `cc_app@127.0.0.1` y concede **solo** `SELECT, INSERT, UPDATE, DELETE` sobre `contact_center.*` (sin `DROP`, `GRANT` ni `FILE`).
- Prueba la conexión con esas credenciales contando `users`. Por eso hay que importar el esquema antes.

Con esos permisos el usuario de la app no puede crear ni alterar tablas: el esquema siempre se aplica con `sudo mysql`.

### 6.1 Migrar datos desde XAMPP (opcional)

En Windows: `C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction contact_center > contact_center.sql`. Súbelo con `scp` y luego, en el servidor, ejecuta `sudo mysql contact_center < contact_center.sql` y vuelve a aplicar `schema.sql`. También sirven los `.sql.gz` de `storage/backups/` del respaldo: `gunzip -c <ARCHIVO>.sql.gz | sudo mysql contact_center`. Copia `public/uploads/` si quieres conservar las imágenes de correos ya enviados.

---

## 7. Administrador inicial

```bash
sudo -u www-data php /var/www/contact-center/bin/seed-admin.php     # = composer seed-admin
```

Crea o actualiza el usuario `admin` con `ADMIN_EMAIL` / `ADMIN_PASSWORD`, guardando la contraseña con bcrypt. Es idempotente. Se ejecuta como `www-data` para que, si algo escribe en `storage/logs`, los archivos queden con el dueño correcto. **[Recomendación]** Después del primer login, cambia la contraseña desde la aplicación y vacía `ADMIN_PASSWORD` en el `.env`.

---

## 8. Compilar el frontend

Vite inyecta `VITE_API_URL` **al compilar**. Si cambias esa variable, hay que recompilar.

**Nunca subas el `dist/` que ya trae el respaldo**: está compilado con `http://localhost:8000/api`.

Opción A, en el servidor (como `contactcenter`):

```bash
cd /var/www/contact-center
npm ci
VITE_API_URL=/api npm run build          # vite build -> dist/
# No debe imprimir nada (ninguna URL absoluta a la API):
grep -rhoE 'https?://[a-zA-Z0-9.:-]+/api' dist/assets/ | sort -u
```

Opción B, en Windows. La variable de entorno tiene prioridad sobre el `.env` local:

```powershell
cd C:\xampp\htdocs\project-backup-pre-saas
$env:VITE_API_URL = "/api"; npm ci; npm run build; Remove-Item Env:VITE_API_URL
tar -czf "$env:TEMP\dist.tgz" -C dist .
scp "$env:TEMP\dist.tgz" contactcenter@<IP_SERVIDOR>:/tmp/
```

```bash
rm -rf /var/www/contact-center/dist && mkdir /var/www/contact-center/dist
tar -xzf /tmp/dist.tgz -C /var/www/contact-center/dist && rm /tmp/dist.tgz
```

Con Nginx **no** hace falta el `dist/.htaccess` que menciona `DEPLOYMENT.md` (es para Apache): el fallback del SPA lo hace `try_files`.

Aplica de nuevo el bloque de permisos (§3.1).

---

## 9. Nginx

`/etc/nginx/sites-available/contact-center`:

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

    # API Slim: las rutas ya incluyen el prefijo /api, se pasan tal cual al front controller.
    location ^~ /api/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/contact-center/public/index.php;
        fastcgi_param SCRIPT_NAME     /index.php;
        fastcgi_param DOCUMENT_ROOT   /var/www/contact-center/public;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        # Un envío de hasta 100 destinatarios se procesa dentro de la petición
        # (set_time_limit(60 + 2*n), hasta ~260 s). Con el valor por defecto (60 s) daría 504.
        fastcgi_read_timeout 300s;
    }

    # Enlace de baja de los correos/SMS: ruta de Slim fuera de /api.
    location ^~ /unsubscribe/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/contact-center/public/index.php;
        fastcgi_param SCRIPT_NAME     /index.php;
        fastcgi_param DOCUMENT_ROOT   /var/www/contact-center/public;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
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

Solo se exponen `dist/`, `public/uploads/` y el front controller. `.env`, `vendor/`, `src/`, `bin/`, `database/`, `storage/` y `config/` quedan fuera del alcance web. Dentro de los `location` no se usa `add_header`, para no anular las cabeceras de seguridad del bloque `server` (`expires` no las afecta).

```bash
sudo ln -s /etc/nginx/sites-available/contact-center /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

**Alternativa Apache:** sirve `dist/` como `DocumentRoot` con el `.htaccess` de fallback de `DEPLOYMENT.md`, pero además tienes que enrutar `/api/` y `/unsubscribe/` a `public/index.php` y `/uploads/` a `public/uploads/`. El `Alias /api` de `DEPLOYMENT.md`, por sí solo, rompe los enlaces de baja (ver Notas).

---

## 10. HTTPS (certbot)

```bash
sudo certbot --nginx -d <TU_DOMINIO> -m <TU_EMAIL> --agree-tos --redirect
sudo systemctl list-timers | grep certbot      # renovación automática (certbot.timer)
sudo certbot renew --dry-run
```

Certbot añade `listen 443 ssl` al mismo bloque y la redirección 80 → 443. **[Recomendación]** Cuando HTTPS funcione, añade HSTS dentro del bloque `server` de 443 y recarga Nginx:

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
```

---

## 11. Firewall y endurecimiento

```bash
sudo ufw allow OpenSSH
sudo ufw allow "Nginx Full"
sudo ufw enable
sudo ufw status verbose
```

- MariaDB escucha en `127.0.0.1` y no tiene regla en ufw, así que no se expone.
- La salida queda permitida (política por defecto de ufw), que es lo que necesitan AWS y la API de voz.
- **[Recomendación]** fail2ban para SSH: `sudo apt install -y fail2ban` (la jaula `sshd` viene activa en Ubuntu).
- PHP: `display_errors=Off`, `expose_php=Off` y OPcache ya quedaron configurados en §2.2.

---

## 12. Tareas programadas

### 12.1 Dispatcher (obligatorio)

`bin/dispatch-campaigns.php` (= `composer dispatch`) hace en cada pasada:

1. Devuelve a la cola los trabajos colgados más de 30 min.
2. Lanza las campañas `Scheduled` vencidas.
3. Consume hasta 5 trabajos de la tabla `jobs`: envíos de más de 100 destinatarios, o de más de 15 en llamadas.
4. Consulta el estado de las llamadas originadas hace 20 s o más; si una sigue sin estado final tras 10 min, la da por no contestada.
5. Mantenimiento: poda sesiones, intentos de login y trabajos antiguos.

Frecuencia: **cada minuto**. Consecuencias:

- El resultado de una llamada aparece entre ~20 s y ~1:20 min después de marcarla.
- Una campaña grande ocupa la pasada hasta terminar (~1 s por destinatario en email, 1,5 s entre llamadas). Mientras tanto no se procesan otras campañas ni se consultan llamadas.
- El script tiene su propio bloqueo (`flock` en `/tmp/contact-center-dispatch.lock`) y omite la pasada si ya hay otra en marcha.

Se ejecuta como `www-data`, que necesita leer el `.env` y los adjuntos y escribir los logs.

**Opción preferida: systemd service + timer.**

```bash
sudo tee /etc/systemd/system/contact-center-dispatch.service > /dev/null <<'EOF'
[Unit]
Description=Contact Center - campañas programadas, cola de envíos y estado de llamadas
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
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

systemd no arranca una segunda instancia mientras la anterior siga activa, así que las pasadas no se solapan.

**Alternativa: cron** (usa solo una de las dos opciones):

```bash
sudo tee /etc/cron.d/contact-center > /dev/null <<'EOF'
* * * * * www-data cd /var/www/contact-center && /usr/bin/flock -n /run/lock/contact-center-dispatch.lock /usr/bin/php bin/dispatch-campaigns.php >> storage/logs/dispatcher.log 2>&1
EOF

sudo tee /etc/logrotate.d/contact-center > /dev/null <<'EOF'
/var/www/contact-center/storage/logs/dispatcher.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
    su www-data www-data
}
EOF
```

### 12.2 Copias de seguridad

`bin/backup-db.php` (= `composer backup`):

- Hace `mysqldump --single-transaction --routines --events` y lo comprime en `.sql.gz`.
- Guarda por defecto en `storage/backups/` (o en `--out=`) y conserva las últimas `--keep` copias (7 por defecto).
- Necesita un usuario administrativo: `cc_app` no sirve. Por defecto usa `root` sin contraseña, así que, ejecutado como root del sistema, entra por `unix_socket` sin pasar contraseñas.

**[Recomendación]** Guardar fuera del árbol web y diario a las 02:30 con 14 copias:

```bash
sudo install -d -m 700 -o root -g root /var/backups/contact-center

sudo tee /etc/systemd/system/contact-center-backup.service > /dev/null <<'EOF'
[Unit]
Description=Contact Center - copia de seguridad de la base de datos
After=mariadb.service

[Service]
Type=oneshot
WorkingDirectory=/var/www/contact-center
ExecStart=/usr/bin/php /var/www/contact-center/bin/backup-db.php --keep=14 --out=/var/backups/contact-center
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
sudo systemctl start contact-center-backup.service && ls -lh /var/backups/contact-center
```

Alternativa cron: `30 2 * * * root /usr/bin/php /var/www/contact-center/bin/backup-db.php --keep=14 --out=/var/backups/contact-center >> /var/log/contact-center-backup.log 2>&1` en `/etc/cron.d/contact-center`.

El volcado no incluye archivos. **[Recomendación]** Respalda también `.env`, `public/uploads/` y `storage/attachments/`, y copia todo fuera del servidor.

Prueba de restauración (hazla de vez en cuando):

```bash
sudo mysql -e "CREATE DATABASE cc_restore_test"
gunzip -c /var/backups/contact-center/<ARCHIVO>.sql.gz | sudo mysql cc_restore_test
sudo mysql -e "SELECT COUNT(*) FROM cc_restore_test.users; DROP DATABASE cc_restore_test;"
```

---

## 13. AWS (SES y SNS)

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
        "ses:SetIdentityMailFromDomain",
        "ses:GetIdentityMailFromDomainAttributes",
        "ses:ListIdentities",
        "ses:DeleteIdentity",
        "sns:Publish"
      ],
      "Resource": "*"
    }
  ]
}
```

Crea claves de acceso permanentes (`AKIA...`) para ese usuario y ponlas en `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`. No hace falta `polly:*` (módulo Audio eliminado).

### 13.2 SES

1. En la app, módulo **Verificación**: verifica el dominio. La app pide la verificación a SES y muestra los registros TXT y DKIM (CNAME) que hay que crear en tu DNS.
2. Crea el remitente (y, si lo usas, el de respuesta/Reply-To) sobre el dominio verificado y márcalo como predeterminado. Tiene prioridad sobre `MAIL_FROM`.
3. **Salir del sandbox:** consola SES (misma región que `AWS_REGION`) → *Account dashboard* → *Request production access*. En sandbox solo se puede enviar a direcciones verificadas, a 1 mensaje/s.

### 13.3 Rebotes y quejas (webhook)

1. SNS → crea un tema estándar (p.ej. `ses-eventos`) en la misma región.
2. Crea una suscripción **HTTPS** a `https://<TU_DOMINIO>/api/webhooks/ses`. **No** actives *raw message delivery*: el controlador espera el sobre SNS completo.
3. La app confirma la suscripción sola: visita la `SubscribeURL`, solo si es de `amazonaws.com`. Comprueba que la suscripción figure como *Confirmed* y que `storage/logs/app-*.log` muestre "Suscripción SNS confirmada".
4. SES → *Identities* → tu identidad → *Notifications* → asigna el tema a **Bounce** y **Complaint**.

Con `SNS_VERIFY_SIGNATURE=true`, un mensaje sin firma válida recibe `403`. Los rebotes permanentes y las quejas pasan a la lista de supresión (Reportes → Supresiones).

### 13.4 SMS (SNS)

En la consola SNS → *Text messaging (SMS)*: sale del sandbox de SMS (mientras tanto solo llega a números verificados), fija el límite de gasto mensual y, según el país, configura un sender ID o un número de origen. Los destinos van en formato E.164 (`+57...`).

---

## 14. API de voz (módulo Llamadas)

La API de voz (TTS + Asterisk) es un servicio externo en **192.168.101.92:8000, una IP privada**. El servidor de producción necesita ruta de red hasta ella: misma LAN o VPN/túnel con esa red. **Si no la tiene, el módulo Llamadas no funcionará.** El resto de la aplicación no se ve afectada. Si el servidor está en la nube, monta antes una VPN site-to-site o un túnel hacia esa red y ajusta `VOICE_API_URL` a la dirección alcanzable.

```bash
nc -zv 192.168.101.92 8000
curl -sS -o /dev/null -w "%{http_code}\n" -H "X-API-Key: <VOICE_API_KEY>" \
  http://192.168.101.92:8000/call-status/prueba
```

Cualquier código HTTP indica que hay conectividad. Un timeout o `Connection refused` indica que no hay ruta o que el servicio está caído. Si hay firewall en la red de la API de voz, debe permitir la IP del servidor de producción.

**Actualización:** la API de voz también se publica en `http://indoor-qvmdkkckkpc.dynamic-m.com:8000` (valor por defecto de `VOICE_API_URL` en `deploy.sh`), así que no hace falta VPN. `deploy.sh` crea el usuario y el token de esta instalación pidiendo la clave de administrador de la API de voz (o con `VOICE_ADMIN_KEY='<clave>'`), y no la guarda. A mano: `sudo -iu contactcenter bash -c "cd /var/www/contact-center && VOICE_ADMIN_KEY='<clave>' php bin/voice-setup.php"`.

### 14.1 Saldo (créditos) y emails de respuesta

Cada envío descuenta saldo prepago (SMS $27, email $20, llamada $75; precios internos, nunca visibles en la app). El saldo **solo se recarga por consola**:

```bash
sudo -u www-data php /var/www/contact-center/bin/credits.php recharge 100000 --note="Factura 123"
sudo -u www-data php /var/www/contact-center/bin/credits.php balance
sudo -u www-data php /var/www/contact-center/bin/credits.php history --limit=20
sudo -u www-data php /var/www/contact-center/bin/credits.php set-price sms 27
```

Los emails de respuesta (varios, de cualquier dominio, sin verificación SES) se agregan en Verificación → Email de respuesta y se eligen por envío en el módulo Email. Detalle completo en `INSTALACION-ROCKY9.md` §14.

---

## 15. Verificación post-instalación

- [ ] `systemctl is-active nginx php8.3-fpm mariadb` → tres veces `active`.
- [ ] `curl -i https://<TU_DOMINIO>/api/auth/me` → `401` con `{"error":"No autorizado","message":"Token no proporcionado"}`, lo que confirma que Slim responde.
- [ ] `curl -i https://<TU_DOMINIO>/unsubscribe/prueba` → `400` "Enlace no válido" del backend (no el HTML de React).
- [ ] `curl -I https://<TU_DOMINIO>/.env` → `403`.
- [ ] `curl -I http://<TU_DOMINIO>` → `301` a HTTPS.
- [ ] En el navegador: `https://<TU_DOMINIO>/login`, entra con el admin, recarga la página en una ruta interna (p.ej. `/reports`) y cambia la contraseña.
- [ ] Verificación: dominio y remitente en estado verificado.
- [ ] Envío de prueba controlado: botón de prueba del módulo Email (`/api/email/test`) a una dirección tuya. Luego `sudo tail /var/www/contact-center/storage/logs/mail-$(date +%F).log`.
- [ ] Una imagen insertada en el editor carga desde `https://<TU_DOMINIO>/uploads/...`.
- [ ] Llamadas: el módulo no muestra el aviso "no está disponible" (`/api/calls/capabilities` → `configured: true`). Haz una llamada de prueba a tu número y espera ~1 min el resultado.
- [ ] Dispatcher: `systemctl list-timers | grep contact-center` y `journalctl -u contact-center-dispatch.service -n 5` ("No hay campañas programadas pendientes.", "Cola de trabajos vacía."). Programa una campaña de prueba a +2 min y comprueba que sale a su hora.
- [ ] Copia: hay un `.sql.gz` en `/var/backups/contact-center` y la restauración de prueba funciona.
- [ ] SNS: suscripción *Confirmed*.

Dónde están los logs:

| Log | Ruta |
|---|---|
| Aplicación (errores, login, arranque) | `/var/www/contact-center/storage/logs/app-AAAA-MM-DD.log` |
| Envíos email/SMS/llamadas, rebotes | `/var/www/contact-center/storage/logs/mail-AAAA-MM-DD.log` |
| Auditoría (usuarios, accesos denegados) | `/var/www/contact-center/storage/logs/audit-AAAA-MM-DD.log` |
| Dispatcher | `journalctl -u contact-center-dispatch.service` (o `storage/logs/dispatcher.log` con cron) |
| Nginx | `/var/log/nginx/contact-center.access.log`, `/var/log/nginx/contact-center.error.log` |
| PHP-FPM | `/var/log/php8.3-fpm.log` |
| MariaDB | `journalctl -u mariadb` |

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

# 3) Traer el código nuevo (git pull como contactcenter, o el tgz de §4 extraído en /tmp/cc-new)
sudo -u contactcenter git -C $APP pull
#   alternativa tgz:
# sudo -u contactcenter mkdir -p /tmp/cc-new && sudo -u contactcenter tar -xzf /tmp/contact-center.tgz -C /tmp/cc-new
# sudo -u contactcenter rsync -a --delete --exclude=.env --exclude=storage/ --exclude=public/uploads/ \
#     --exclude=vendor/ --exclude=node_modules/ --exclude=dist/ /tmp/cc-new/ $APP/ && rm -rf /tmp/cc-new

# 4) Dependencias y frontend (como contactcenter)
sudo -iu contactcenter bash -c "cd $APP && composer install --no-dev --optimize-autoloader --no-interaction && npm ci && VITE_API_URL=/api npm run build"

# 5) Esquema (idempotente) y permisos
sudo sh -c "mysql < $APP/database/schema.sql"
#   ...repite el bloque de permisos de §3.1...

# 6) Recargar PHP (limpia OPcache) y reanudar el dispatcher
sudo systemctl reload php8.3-fpm
sudo systemctl start contact-center-dispatch.timer
```

Después, repasa los puntos clave de §15. Revisa también si `.env.example` trae variables nuevas.

**Rollback:**

```bash
sudo systemctl stop contact-center-dispatch.timer
sudo rsync -a --delete --exclude=.env --exclude=storage/ --exclude=public/uploads/ --exclude=node_modules/ \
  /var/www/contact-center.prev/ /var/www/contact-center/
#   ...bloque de permisos de §3.1...
sudo systemctl reload php8.3-fpm && sudo systemctl start contact-center-dispatch.timer
```

`schema.sql` solo añade tablas, columnas e índices, así que la versión anterior suele funcionar con el esquema nuevo. Si no es así, restaura la copia del paso 2: `gunzip -c <ARCHIVO>.sql.gz | sudo mysql contact_center`. Se pierden los datos posteriores a esa copia.

---

## 17. Solución de problemas

| Síntoma | Causa probable / qué revisar |
|---|---|
| **502 Bad Gateway** | PHP-FPM caído o socket distinto: `systemctl status php8.3-fpm`, `ls /run/php/`; ver `contact-center.error.log` y `/var/log/php8.3-fpm.log`. |
| **504** al enviar | Envío síncrono largo: `fastcgi_read_timeout 300s` en `/api/`. Para envíos grandes, programa la campaña. |
| **500** en toda la API | `.env` ilegible para `www-data` (§3.1), falta `vendor/` (`composer install`), credenciales de BD erróneas o `JWT_SECRET` ausente o corto. Revisa `storage/logs/app-*.log` y el error log de Nginx (los fallos al cargar el `.env` ocurren antes de que exista el logger). |
| El login llama a `localhost:8000` o la página queda en blanco | Frontend compilado con otro `VITE_API_URL`: pon `/api` y recompila (§8). |
| **Errores CORS** | Solo pueden aparecer si SPA y API están en orígenes distintos. `CORS_ALLOWED_ORIGIN` debe ser el origen exacto (`https://<TU_DOMINIO>`, sin `/` final). |
| **401** justo después de iniciar sesión, o sesiones que caducan antes de tiempo | Zonas horarias distintas entre PHP y MariaDB (`sessions.expires_at` se guarda con `date()` de PHP y se compara con `NOW()`), o reloj sin sincronizar: revisa §2.1 y `timedatectl`. Cambiar `JWT_SECRET` invalida todas las sesiones (es normal). |
| **429** en el login | Límite de 5 fallos por cuenta y 20 por IP en 15 min. Si hay un proxy delante sin `TRUST_PROXY`, todos comparten la IP del proxy. |
| No se escriben logs o falla la subida de imágenes | Permisos de `storage/` y `public/uploads/` (§3.1). El logger se desactiva en silencio si no puede escribir. |
| Campañas programadas no salen o salen a otra hora | Timer inactivo (`systemctl list-timers`) o zona horaria (§2.1). |
| Email rechazado: "sandbox" / *Email address is not verified* | SES en sandbox o remitente sin verificar en la región de `AWS_REGION` (§13.2). |
| La suscripción SNS no se confirma o el webhook da 403 | `allow_url_fopen=On`, salida HTTPS permitida, URL exacta `/api/webhooks/ses`, *raw message delivery* desactivado. |
| "No se pudo conectar con la API de llamadas de voz" | Sin ruta hacia `VOICE_API_URL` (§14) o `VOICE_API_KEY` incorrecta (401/403 de la API). |
| Las imágenes no se ven en los correos recibidos | `APP_URL` no es la URL pública HTTPS o `/uploads/` no está mapeado (§9). |

---

## Notas y discrepancias (DEPLOYMENT.md / README / .env.example frente al código)

Manda el código. Este documento ya aplica las correcciones.

1. **Rutas web con el `Alias /api` de DEPLOYMENT.md.** El código arma los enlaces de baja como `APP_URL/unsubscribe/<token>`, una ruta de Slim **fuera** de `/api`, y las imágenes como `APP_URL/uploads/...`. Con `DocumentRoot dist` + `Alias /api → public`:
   - con `APP_URL=https://dominio`, ambas rutas caen en el SPA (baja rota, imágenes rotas);
   - con `APP_URL=https://dominio/api`, las imágenes funcionan pero la baja da 404, porque Slim recibe `/api/unsubscribe/...`.

   Aquí se enrutan `/api/` y `/unsubscribe/` a PHP y `/uploads/` a `public/uploads/`, con `APP_URL` en la raíz del dominio.
2. **Módulo Audio/Polly eliminado.** DEPLOYMENT.md todavía pide `polly:SynthesizeSpeech` (tabla IAM y checklist) y dice que las llamadas salientes están "fuera de alcance". `.env.example` menciona "Polly para audio". El código ya no usa Polly, `/audio` redirige a `/calls` y las llamadas funcionan vía la API de voz externa. `schema.sql` aún crea la tabla `audio_messages`, que no se usa (inofensiva).
3. **`ses:GetSendQuota`** figura en DEPLOYMENT.md pero el código no la invoca; se omite de la política IAM.
4. **`.env.example` no trae `VOICE_API_URL`, `VOICE_API_KEY` ni `VOICE_CALL_THROTTLE_MS`**, que el código sí lee. Hay que añadirlas a mano.
5. **`APP_DEBUG` no la lee ningún archivo.** Solo `APP_ENV=production` oculta las trazas y ajusta el nivel de log.
6. **`TRUST_PROXY`:** DEPLOYMENT.md indica ponerlo en `true` detrás de Nginx. Con Nginx + PHP-FPM en el mismo host no hace falta, y activarlo permitiría falsear la IP (el código toma el primer valor de `X-Forwarded-For`) y saltarse el límite de intentos de login.
7. **Contraseña de la copia:** DEPLOYMENT.md dice que no viaja por línea de comandos. `mysqldump` la recibe por `MYSQL_PWD`, pero `--password=` sí queda visible en los argumentos del propio proceso PHP. Aquí se evita usando root por `unix_socket`.
8. **Dispatcher en cron:** el ejemplo de DEPLOYMENT.md (y del propio script) escribe en `/var/log/campaigns.log`, donde `www-data` no puede escribir y que no tiene rotación. Aquí se usa journald o `storage/logs/dispatcher.log` con logrotate.
9. **Zona horaria:** DEPLOYMENT.md no la menciona. Es necesaria porque el código compara horas de PHP con `NOW()` de MariaDB y las fechas programadas son hora local.
10. **Timeout de la petición:** DEPLOYMENT.md no lo cubre. Los envíos síncronos de hasta 100 destinatarios pueden durar ~260 s; en Nginx hace falta `fastcgi_read_timeout`.
11. **`dist/.htaccess`** (DEPLOYMENT.md §5) solo aplica a Apache.
12. **Rutas públicas:** DEPLOYMENT.md dice que todo `/api` exige JWT salvo login y `OPTIONS`. También son públicas `POST /api/auth/refresh`, `POST /api/webhooks/ses` y `GET /unsubscribe/{token}`.
13. **Lista de tablas** en DEPLOYMENT.md §4: le faltan `login_attempts`, `sessions`, `jobs`, `suppressions` y `audio_messages`.
14. **README.md desactualizado:** pide MySQL ≥ 8.0 (el esquema exige MariaDB), menciona `php artisan migrate`, variables con otros nombres (`DB_NAME`, `DB_USER`, `AWS_ACCESS_KEY`…), el puerto 3000, permisos de Lambda/S3 y `npm run test`. Nada de eso aplica.
15. **Guzzle** se usa directamente en `VoiceCallService`, pero no está declarado en `composer.json`; llega como dependencia de `aws/aws-sdk-php`. Hoy funciona; conviene declararlo si se actualiza el SDK.
