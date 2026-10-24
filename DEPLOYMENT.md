# Despliegue — Contact Center

Plataforma de centro de contacto: autenticación (JWT) con gestión de usuarios, verificación de identidades en Amazon SES, envío de email (SES) y SMS (IT Cloud Services) individual y masivo, contactos/listas, plantillas y campañas (enviar ahora o programadas).

Esta guía describe cómo instalar y servir la aplicación en el mismo equipo con XAMPP (Windows). No contempla despliegue a servidores remotos ni operaciones de git. Ningún archivo del proyecto contiene secretos reales: las claves AWS y los secretos quedan vacíos y el usuario los completa en su entorno.

## 1. Requisitos

- XAMPP con Apache y MySQL/MariaDB en ejecución.
- PHP 8.2 o superior (el proyecto se probó con PHP 8.3).
- Composer.
- Node.js (incluye npm) para compilar el frontend.

## 2. Instalación de dependencias

Desde la raíz del proyecto:

```
composer install
npm install
```

## 3. Configurar `.env`

Puedes partir de `.env.example`, que ya incluye todas las claves necesarias. Completa los valores reales:

- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: conexión a MySQL/MariaDB.
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION`: credenciales de AWS (SES para email).
- `AWS_SESSION_TOKEN`: solo si usas credenciales temporales de STS (claves que empiezan por `ASIA...`). Déjalo vacío con claves permanentes (`AKIA...`).
- `MAIL_FROM`: remitente de respaldo. **Lo habitual es no usarlo**: el remitente se crea desde el módulo de Verificación sobre un dominio ya verificado y queda marcado como "remitente por defecto" en la base de datos, que tiene prioridad sobre esta variable. `MAIL_FROM` solo se aplica si no hay ningún remitente por defecto. En cualquier caso debe ser una identidad verificada en SES.
- `JWT_SECRET`: secreto para firmar los tokens de sesión. Genera uno largo y aleatorio en producción (p.ej. `openssl rand -hex 32`). `JWT_EXPIRY`: validez del token en segundos (por defecto 86400 = 1 día).
- `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`: datos del administrador inicial que se siembra (ver paso 4b).
- `CORS_ALLOWED_ORIGIN`: origen del SPA (en desarrollo `http://localhost:5173`).
- `VITE_API_URL`: URL base de la API (en desarrollo `http://localhost:8000/api`; en producción con `Alias /api`, usar `/api`).
- `APP_URL`: URL pública del backend. Se usa para construir las URL de las imágenes subidas desde los editores (ver sección 8a). En producción debe apuntar al dominio real con HTTPS.
- `MAIL_FROM_NAME`: nombre visible del remitente (opcional), p.ej. `Contact Center <info@tudominio.com>`.
- `APP_ENV=production` y `APP_DEBUG=false` en producción. Con `APP_ENV=production` el middleware de errores de Slim deja de exponer trazas en las respuestas.

**Importante:** `DB_DATABASE` debe permanecer en `contact_center` para coincidir con `database/schema.sql`. Si lo cambias, edita también el nombre de la base en ese archivo `.sql`.

**Seguridad:** no coloques secretos en archivos versionables. El `.env` del repositorio/entregable conserva las claves AWS vacías; cada entorno las completa localmente.

## 4. Crear la base de datos

Ejecuta el esquema idempotente `database/schema.sql`. Opciones:

- phpMyAdmin: importar/ejecutar el archivo.
- Línea de comandos:

```
mysql -u root < database/schema.sql
```

(o `C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql`). Esto crea la base `contact_center` y todas las tablas si no existen: `users`, `domains`, `senders`, `contacts`, `contact_lists`, `contact_list_members`, `templates`, `campaigns`, `campaign_recipients` y `messages`.

### 4b. Sembrar el administrador inicial

Define `ADMIN_EMAIL` y `ADMIN_PASSWORD` en el `.env` y ejecuta:

```
composer seed-admin
```

Crea (o actualiza) el usuario administrador con la contraseña hasheada. Es idempotente: puedes volver a ejecutarlo para cambiar la contraseña. Con ese usuario inicias sesión en la aplicación.

## 5. Compilar el frontend

```
npm run build
```

Genera la carpeta `dist/` con los assets del SPA. Gracias a `publicDir: false` en `vite.config.ts`, `dist/` queda limpio (no se copia el `index.php`/`.htaccess` del front-controller de Slim que vive en `public/`).

**Paso posterior al build (obligatorio):** como `publicDir: false` impide que Vite copie cualquier `.htaccess`, coloca manualmente el archivo `dist/.htaccess` de fallback SPA después de cada build. Su contenido:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteRule ^index\.html$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /index.html [L]
</IfModule>
```

Antes de cada compilación `VITE_API_URL` debe apuntar al origen real de la API, porque Vite inyecta `import.meta.env.*` en tiempo de build.

## 6. Configurar Apache (mod_rewrite)

Activa `mod_rewrite` en Apache (`httpd.conf`) y configura un VirtualHost. Enfoque **autoritativo recomendado**: un único VirtualHost que sirve el SPA desde `dist/` y expone la API bajo `Alias /api` apuntando a `public/`. Así el SPA y la API comparten origen y **CORS no es necesario en producción** (el middleware CORS permanece inofensivo).

```apache
<VirtualHost *:80>
    ServerName contact-center.local
    DocumentRoot "C:/xampp/htdocs/project/dist"

    <Directory "C:/xampp/htdocs/project/dist">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    Alias /api "C:/xampp/htdocs/project/public"
    <Directory "C:/xampp/htdocs/project/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Con este esquema usa `VITE_API_URL=/api` (mismo origen). El `public/.htaccess` reescribe las peticiones no-archivo al front-controller `index.php` de Slim, y el `dist/.htaccess` hace fallback de react-router a `index.html`.

Reinicia Apache tras cambiar la configuración.

### Alternativa: dos puertos (orígenes separados)

Si prefieres separar SPA y API en orígenes distintos (por ejemplo el SPA por Apache y la API en `:8000`), mantén `CORS_ALLOWED_ORIGIN` apuntando al origen del SPA y `VITE_API_URL` a la URL completa de la API (p.ej. `http://localhost:8000/api`). En este modo el CORS del backend sí es necesario.

## 7. SSL (producción real)

En un entorno de producción real sirve la aplicación por HTTPS: configura un VirtualHost `*:443` con el certificado correspondiente y redirige el tráfico `:80` a `:443`. Los ejemplos anteriores usan `:80` solo para entorno local.

## 8. Amazon SES: nota de sandbox

Una cuenta SES nueva está en **sandbox**: solo permite enviar a identidades verificadas y requiere salir del sandbox mediante una solicitud a soporte de AWS. La verificación de dominios y remitentes funciona en sandbox, pero el envío real a terceros requiere solicitar el acceso de producción en SES.

Los **SMS** salen por IT Cloud Services con la cuenta de cada cliente (ver `bin/sms-setup.php`).

## 8a. Adjuntos e imágenes del editor

Los adjuntos se envían vía **SES SendRawEmail** (mensaje MIME construido con PHPMailer), porque la
operación `SendEmail` de SES no los admite. Límites aplicados por `App\Support\Attachments`:

- Máximo 10 archivos por correo.
- 5 MB por archivo y 7 MB en total (en crudo). El margen existe porque base64 infla el contenido
  cerca de un 37% y SES limita el tamaño total del mensaje.
- Se rechazan ejecutables y scripts (`.exe`, `.bat`, `.ps1`, `.js`, etc.).

Las imágenes insertadas en el editor se suben a `public/uploads` y se referencian con una **URL
absoluta** construida con `APP_URL`, porque los clientes de correo suelen bloquear las imágenes
incrustadas en base64. Consecuencias para producción:

- `APP_URL` debe ser la URL pública real del backend (con HTTPS), o los destinatarios no verán las imágenes.
- La carpeta `public/uploads` debe tener permisos de escritura para el usuario del servidor web
  (p.ej. `www-data`).
- `public/uploads/.htaccess` bloquea la ejecución de scripts como defensa en profundidad; el backend
  además valida el contenido real de cada imagen con `getimagesize()`.

## 8b. Campañas programadas (dispatcher)

Las campañas con envío **"Programar"** quedan en estado `Scheduled` con su `scheduled_at`. Un proceso en segundo plano debe ejecutar el dispatcher periódicamente para enviarlas cuando llega su hora:

```
composer dispatch           # equivale a: php bin/dispatch-campaigns.php
```

El dispatcher procesa todas las campañas `Scheduled` cuya hora ya pasó y actualiza sus estados y contadores. Prográmalo cada minuto:

**Linux (cron):**

```
* * * * * php /ruta/al/proyecto/bin/dispatch-campaigns.php >> /var/log/campaigns.log 2>&1
```

**Windows (Programador de tareas):** crea una tarea básica con repetición cada 1 minuto cuya acción ejecute:

```
C:\xampp\php\php.exe C:\xampp\htdocs\project\bin\dispatch-campaigns.php
```

Si no ejecutas el dispatcher, el "enviar ahora" sigue funcionando, pero las campañas programadas no saldrán hasta que algo invoque el dispatcher.

> Nota sobre volumen: el "enviar ahora" procesa los destinatarios de forma síncrona dentro de la petición. Para envíos muy grandes conviene crear la campaña como programada y dejar que el dispatcher la procese en segundo plano.

## 8c. Saldo (créditos), emails de respuesta y API de voz

- **Saldo:** cada envío descuenta saldo prepago (SMS $27, email $20, llamada $75 por defecto, en la tabla `credit_prices`). Los precios no se envían nunca al frontend y **solo se recarga por consola**: `php bin/credits.php recharge 100000`, `balance`, `history`, `usage`, `prices`, `set-price sms 27` (alias `composer credits -- <comando>`).
- **Emails de respuesta:** varios, en Verificación → Email de respuesta (no requieren verificación SES). Se eligen por envío en el módulo Email.
- **API de voz:** `php bin/voice-setup.php` con la variable `VOICE_ADMIN_KEY` crea el usuario de esta instalación en la API de voz y guarda `VOICE_API_KEY` en el `.env` (por defecto `VOICE_API_URL=http://indoor-qvmdkkckkpc.dynamic-m.com:8000`). En Linux lo hace `deploy.sh`.

## 9. Seguridad

- No versiones secretos. `.env.example` y el `.env` entregado conservan las claves AWS y los secretos vacíos.
- Define un `JWT_SECRET` largo y aleatorio en producción; si se filtra, cualquiera puede falsificar sesiones. Cambia la `ADMIN_PASSWORD` por defecto.
- Todas las rutas `/api` están protegidas por JWT salvo `POST /api/auth/login` y el preflight `OPTIONS`. Las contraseñas se guardan con `password_hash` (bcrypt).
- En producción usa `APP_ENV=production` y `APP_DEBUG=false` para no exponer trazas de error.
- Restringe `CORS_ALLOWED_ORIGIN` al origen real del SPA cuando uses el modo de dos orígenes.

## 10. Desarrollo (no producción)

Para trabajar localmente con recarga en caliente, levanta el backend y el frontend por separado desde la raíz del proyecto, en dos terminales:

```
composer serve     # backend Slim en http://localhost:8000 (sirve /api/*)
npm run dev        # frontend Vite en http://localhost:5173
```

En este modo los valores por defecto de `.env`/`.env.example` aplican: `VITE_API_URL=http://localhost:8000/api` y `CORS_ALLOWED_ORIGIN=http://localhost:5173`. Como son dos orígenes distintos (`:5173` ↔ `:8000`), el CORS del backend es necesario aquí; por eso el middleware CORS y el preflight `OPTIONS` forman parte del camino de desarrollo.

---

# Endurecimiento para producción

Esta sección cubre todo lo añadido para que la aplicación sea defendible en un
entorno real: secretos, usuario de base de datos con permisos mínimos, registro
de actividad, límites de acceso, cola de envíos, rebotes y copias de seguridad.

## 1. Secretos y credenciales

Nunca edites los secretos a mano: hay una herramienta que los genera con un
generador criptográfico y los escribe en el `.env` (dejando `.env.bak` como
respaldo). No los muestra por pantalla a propósito.

```
composer secrets                              # genera lo que falte o sea débil
php bin/generate-secrets.php --all --force     # regenera todo
php bin/generate-secrets.php --jwt             # solo el secreto JWT
```

Genera:

- `JWT_SECRET`: 64 caracteres hexadecimales (equivale a `openssl rand -hex 32`).
  La aplicación **rechaza arrancar la autenticación** si tiene menos de 32.
- `ADMIN_PASSWORD`: 24 caracteres. Aplícala con `composer seed-admin`.
- `DB_PASSWORD`: 28 caracteres. Aplícala con `composer db-user`.

## 2. Usuario de base de datos con permisos mínimos

La aplicación **no debe usar `root`**. Hay un script que crea un usuario
dedicado con permisos solo de lectura y escritura de datos sobre su base:

```
composer db-user
php bin/setup-db-user.php --admin-user=root --admin-password=TU_CLAVE
```

Concede únicamente `SELECT, INSERT, UPDATE, DELETE` sobre `contact_center`. **No
tiene `DROP`, `GRANT` ni `FILE`**, así que un compromiso de la aplicación no da
control del servidor de base de datos.

> Por eso las migraciones se aplican con un usuario administrativo:
> `mysql -u root < database/schema.sql`. El usuario de la aplicación no puede
> crear ni alterar tablas, y eso es intencionado.

## 3. Permisos de AWS necesarios

El usuario IAM necesita exactamente estas acciones:

| Acción | Para qué |
|---|---|
| `ses:SendRawEmail` | Envío de email (con adjuntos) |
| `ses:VerifyDomainIdentity`, `ses:VerifyDomainDkim`, `ses:VerifyEmailIdentity` | Verificación de dominios y remitentes |
| `ses:GetIdentityVerificationAttributes`, `ses:GetIdentityDkimAttributes` | Consulta de estado |
| `ses:ListIdentities`, `ses:DeleteIdentity` | Listado y borrado de identidades |
| `ses:GetSendQuota` | Detección del modo sandbox |
| `polly:SynthesizeSpeech` | Módulo de Audio (locuciones) |

Si falta `polly:SynthesizeSpeech`, el módulo de Audio responde con un mensaje
que lo indica explícitamente en lugar de fallar en silencio.

## 4. Registro de actividad (logs)

Tres canales con rotación diaria y 14 días de retención en `storage/logs`:

| Canal | Qué registra |
|---|---|
| `app-AAAA-MM-DD.log` | Arranque, errores no capturados, inicios y cierres de sesión, bloqueos por intentos |
| `mail-AAAA-MM-DD.log` | Cada envío de email y SMS con su resultado, rebotes y quejas |
| `audit-AAAA-MM-DD.log` | Alta y baja de usuarios, cambios de contraseña, accesos denegados por rol |

Con `APP_ENV=production` el nivel mínimo es `info` y los errores **ya no se
muestran en la respuesta HTTP**, pero sí quedan en el log. La carpeta está fuera
de `public/` y además tiene un `.htaccess` que la bloquea.

## 5. Protección del acceso

**Límite de intentos de login.** Ventana deslizante de 15 minutos con dos topes
simultáneos: 5 fallos por cuenta y 20 por IP. Al superarlo responde `429` con la
cabecera `Retry-After`. Los últimos intentos avisan de cuántos quedan.

**Sesiones revocables.** Cada token lleva un identificador (`jti`) registrado en
la tabla `sessions`. Eso permite lo que un JWT por sí solo no puede:

- `POST /api/auth/logout` cierra la sesión actual de verdad.
- `POST /api/auth/logout-all` cierra todas las del usuario.
- Cambiar la contraseña revoca automáticamente las demás sesiones.

**Refresh token.** El token de acceso dura 1 día (`JWT_EXPIRY`) y el refresh 14
(`JWT_REFRESH_EXPIRY`). El refresh **se rota en cada uso**: si alguien roba uno,
solo sirve una vez y el uso duplicado queda invalidado. El frontend renueva de
forma transparente.

**Roles.** `admin` gestiona usuarios; `operator` usa los módulos de envío sin
poder administrar cuentas. Lo impone el backend (`RoleMiddleware`), no el
navegador: la interfaz solo oculta lo que no corresponde.

**Proxy inverso.** Si la aplicación va detrás de Nginx o un balanceador, pon
`TRUST_PROXY=true` para que el límite por IP lea `X-Forwarded-For`. Si no, se
ignora esa cabecera a propósito: si no hay proxy, es falsificable.

## 6. Cola de envíos

Un envío masivo tarda alrededor de un segundo por destinatario (SES limita los
envíos por segundo), así que por encima de **100 destinatarios** la petición ya
no se procesa en línea: se crea una campaña, se encola y responde `202`. El
proceso en segundo plano la va enviando.

La cola vive en la tabla `jobs`, con reserva atómica, reintentos con espera
exponencial (hasta 3) y recuperación de trabajos que quedaron colgados por una
caída del proceso. No hace falta Redis ni SQS.

## 7. Proceso en segundo plano (obligatorio)

Sin él **las campañas programadas no salen nunca** y la cola no se procesa.
Hace tres cosas por pasada: lanzar campañas vencidas, consumir la cola y
mantenimiento (poda de sesiones, intentos de login y trabajos antiguos).

**Linux (cron), cada minuto:**

```
* * * * * php /ruta/al/proyecto/bin/dispatch-campaigns.php >> /var/log/campaigns.log 2>&1
```

**Windows (Programador de tareas).** Hay un script preparado en
`bin/dispatch-task.cmd`. Regístralo una sola vez en PowerShell como
administrador:

```powershell
$accion = New-ScheduledTaskAction -Execute "C:\xampp\htdocs\project\bin\dispatch-task.cmd"
$inicio = New-ScheduledTaskTrigger -Once -At (Get-Date) `
            -RepetitionInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "ContactCenter-Dispatcher" `
  -Action $accion -Trigger $inicio `
  -Description "Procesa campanas programadas y la cola de envios"
```

Lleva un bloqueo de archivo: si una pasada tarda más de un minuto, la siguiente
se omite en lugar de enviar los correos dos veces.

## 8. Rebotes y quejas (reputación de envío)

AWS vigila la tasa de rebotes y quejas: si sigues enviando a direcciones que
rebotan, acaba **suspendiendo** el envío. La aplicación ya no usa Amazon SNS,
así que los rebotes no se registran solos: revisa las métricas de reputación
en la consola de SES (rebotes < 5 %, quejas < 0,1 %).

Las bajas de los destinatarios se consultan y liberan en **Reportes → Supresiones**.

> `SNS_VERIFY_SIGNATURE` debe quedarse en `true`. La ruta es pública porque la
> llama AWS, y la firma criptográfica es lo único que impide que cualquiera
> envenene la lista de supresión. Solo se desactiva para pruebas locales.

## 9. Copias de seguridad

```
composer backup
php bin/backup-db.php --keep=14 --out=D:/backups --user=root --password=TU_CLAVE
```

Genera un volcado comprimido en `storage/backups` con `--single-transaction`
(no bloquea la base), conserva las últimas `--keep` copias y pasa la contraseña
por variable de entorno, no por la línea de comandos.

Progámalo a diario igual que el dispatcher. **Comprueba de vez en cuando que una
copia restaura de verdad**: una copia sin probar no es una copia.

## 10. Lista de comprobación antes de abrir al público

- [ ] `composer secrets` y aplicar con `composer seed-admin` y `composer db-user`
- [ ] `DB_USERNAME` distinto de `root`
- [ ] `APP_ENV=production` y `APP_DEBUG=false`
- [ ] `APP_URL`, `VITE_API_URL` y `CORS_ALLOWED_ORIGIN` con el dominio real
- [ ] HTTPS activo (el token viaja en cada petición)
- [ ] `npm run build` y colocar el `.htaccess` de `dist/`
- [ ] Dispatcher programado y comprobado en el log
- [ ] Copia de seguridad programada y una restauración probada
- [ ] Acceso de producción de SES solicitado (salir del sandbox)
- [ ] Remitente verificado y marcado como predeterminado
- [ ] Permisos de AWS revisados (incluido `polly:SynthesizeSpeech` si usas Audio)

## 11. Qué sigue fuera de alcance

Conviene tenerlo claro para no dar por hecho algo que no está:

- **Llamadas telefónicas salientes.** El módulo de Audio genera locuciones con
  Amazon Polly, pero marcar números exige un proveedor de telefonía (Amazon
  Connect, Asterisk o Twilio). La interfaz lo advierte en lugar de prometerlo.
- **Pruebas automatizadas.** PHPUnit está instalado pero no hay suite. Todo lo
  anterior se verificó ejecutándolo a mano.
- **Autenticación en dos pasos** y recuperación de contraseña por correo. Hoy el
  restablecimiento lo hace un administrador desde la propia aplicación.
