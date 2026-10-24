-- schema.sql — Base de datos del Contact Center (verificación SES).
-- IMPORTANTE: el nombre 'contact_center' debe coincidir con DB_DATABASE del .env.
-- Si cambias DB_DATABASE, actualiza también el nombre en este archivo.
CREATE DATABASE IF NOT EXISTS `contact_center`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `contact_center`;

CREATE TABLE IF NOT EXISTS `domains` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain` VARCHAR(255) NOT NULL,
    `verification_token` VARCHAR(255) NULL,
    `verification_status` VARCHAR(32) NOT NULL DEFAULT 'Pending',
    `dkim_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `dkim_status` VARCHAR(32) NULL,
    `dkim_tokens` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_domains_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- is_default marca el remitente por defecto (el MAIL_FROM usado en los envíos).
-- Solo uno debe tenerlo a 1; el repositorio se encarga de mantener esa invariante.
CREATE TABLE IF NOT EXISTS `senders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `verification_status` VARCHAR(32) NOT NULL DEFAULT 'Pending',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_senders_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migración para instalaciones que ya tenían la tabla `senders` sin is_default.
ALTER TABLE `senders` ADD COLUMN IF NOT EXISTS `is_default` TINYINT(1) NOT NULL DEFAULT 0;

-- Un remitente puede ser el que aparece en "De" ('sender', el de siempre) o
-- el que recibe las respuestas vía la cabecera Reply-To ('reply'). Son dos
-- roles independientes: cada uno tiene su propio "por defecto". Igual que
-- hacen 360NRS/Brevo con "Email remitente" vs "Email de respuesta".
ALTER TABLE `senders` ADD COLUMN IF NOT EXISTS `type` VARCHAR(16) NOT NULL DEFAULT 'sender';
ALTER TABLE `senders` ADD INDEX IF NOT EXISTS `idx_senders_type` (`type`);

-- =====================================================================
-- Plataforma completa: usuarios (auth), contactos/listas, plantillas,
-- campañas y log de mensajes. Todo idempotente (CREATE TABLE IF NOT EXISTS).
-- =====================================================================

-- Usuarios para autenticación JWT. password_hash via password_hash() (bcrypt).
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` VARCHAR(32) NOT NULL DEFAULT 'admin',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contactos (destinatarios reutilizables).
CREATE TABLE IF NOT EXISTS `contacts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(64) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contacts_email` (`email`),
    KEY `idx_contacts_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Listas de contactos reutilizables para envíos masivos / campañas.
CREATE TABLE IF NOT EXISTS `contact_lists` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relación N:M contactos <-> listas.
CREATE TABLE IF NOT EXISTS `contact_list_members` (
    `list_id` INT UNSIGNED NOT NULL,
    `contact_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`list_id`, `contact_id`),
    KEY `idx_clm_contact` (`contact_id`),
    CONSTRAINT `fk_clm_list` FOREIGN KEY (`list_id`) REFERENCES `contact_lists` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_clm_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plantillas de mensaje. channel: 'email' | 'sms'. subject NULL para SMS.
-- design_json guarda el estado del editor de bloques (GrapesJS) para poder
-- reabrir la plantilla y seguir editándola; `body` guarda el HTML ya exportado.
CREATE TABLE IF NOT EXISTS `templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `channel` VARCHAR(16) NOT NULL,
    `subject` VARCHAR(255) NULL,
    `body` TEXT NOT NULL,
    `design_json` LONGTEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_templates_channel` (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migración para instalaciones que ya tenían la tabla `templates`.
ALTER TABLE `templates` ADD COLUMN IF NOT EXISTS `design_json` LONGTEXT NULL;

-- Campañas. channel: 'email' | 'sms'. status: Draft|Scheduled|Sending|Completed|Failed.
CREATE TABLE IF NOT EXISTS `campaigns` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `channel` VARCHAR(16) NOT NULL,
    `template_id` INT UNSIGNED NULL,
    `subject` VARCHAR(255) NULL,
    `body` TEXT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'Draft',
    `scheduled_at` DATETIME NULL,
    `total_recipients` INT NOT NULL DEFAULT 0,
    `sent_count` INT NOT NULL DEFAULT 0,
    `failed_count` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_campaigns_status` (`status`),
    KEY `idx_campaigns_scheduled` (`scheduled_at`),
    CONSTRAINT `fk_campaigns_template` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Destinatarios de cada campaña con estado individual.
CREATE TABLE IF NOT EXISTS `campaign_recipients` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` INT UNSIGNED NOT NULL,
    `contact_id` INT UNSIGNED NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(64) NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'Pending',
    `error` TEXT NULL,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_cr_campaign` (`campaign_id`),
    KEY `idx_cr_status` (`status`),
    CONSTRAINT `fk_cr_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de mensajes individuales (y de campaña) para métricas del Dashboard.
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel` VARCHAR(16) NOT NULL,
    `recipient` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) NULL,
    `status` VARCHAR(16) NOT NULL,
    `error` TEXT NULL,
    `campaign_id` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_messages_channel` (`channel`),
    KEY `idx_messages_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Endurecimiento para producción: control de intentos de login,
-- revocación de sesiones, cola de envíos y supresión de destinatarios.
-- =====================================================================

-- Intentos de login, para limitar la fuerza bruta por IP y por cuenta.
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip` VARCHAR(45) NOT NULL,
    `email` VARCHAR(255) NULL,
    `successful` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_la_ip_time` (`ip`, `created_at`),
    KEY `idx_la_email_time` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesiones emitidas. Permite cerrar sesión de verdad (revocar un JWT concreto)
-- y sostener los refresh token, que un JWT por sí solo no puede revocar.
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `jti` CHAR(32) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `refresh_token_hash` CHAR(64) NULL,
    `revoked` TINYINT(1) NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `refresh_expires_at` DATETIME NULL,
    `ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sessions_jti` (`jti`),
    KEY `idx_sessions_user` (`user_id`),
    KEY `idx_sessions_refresh` (`refresh_token_hash`),
    KEY `idx_sessions_expires` (`expires_at`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cola de trabajos de envío: permite procesar campañas y envíos masivos en
-- segundo plano por lotes, sin el límite de tiempo de una petición HTTP.
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(32) NOT NULL,
    `payload` LONGTEXT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'Pending',
    `attempts` INT NOT NULL DEFAULT 0,
    `max_attempts` INT NOT NULL DEFAULT 3,
    `last_error` TEXT NULL,
    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reserved_at` DATETIME NULL,
    `finished_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_jobs_claim` (`status`, `available_at`),
    KEY `idx_jobs_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lista de supresión: direcciones que rebotaron o se quejaron. Volver a
-- enviarles daña la reputación de envío de la cuenta en AWS.
CREATE TABLE IF NOT EXISTS `suppressions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `reason` VARCHAR(32) NOT NULL,
    `detail` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_suppressions_email` (`email`),
    KEY `idx_suppressions_reason` (`reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Biblioteca de audios generados con Amazon Polly (módulo de Audio).
CREATE TABLE IF NOT EXISTS `audio_messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `text` TEXT NOT NULL,
    `voice_id` VARCHAR(64) NOT NULL,
    `language_code` VARCHAR(16) NULL,
    `engine` VARCHAR(16) NOT NULL DEFAULT 'standard',
    `file_name` VARCHAR(255) NOT NULL,
    `duration_seconds` DECIMAL(8,2) NULL,
    `size_bytes` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audio_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índice que faltaba: las métricas de Reportes agrupan por campaña.
ALTER TABLE `messages` ADD INDEX IF NOT EXISTS `idx_messages_campaign` (`campaign_id`);
ALTER TABLE `messages` ADD INDEX IF NOT EXISTS `idx_messages_status` (`status`);

-- Identificador del proceso que reservó el trabajo. Permite reclamar lotes de
-- forma atómica sin SKIP LOCKED (no disponible en MariaDB 10.4).
ALTER TABLE `jobs` ADD COLUMN IF NOT EXISTS `worker` VARCHAR(64) NULL;
ALTER TABLE `jobs` ADD INDEX IF NOT EXISTS `idx_jobs_worker` (`worker`);

-- =====================================================================
-- Unificación: TODO envío (individual o masivo) se registra como campaña.
-- Campos específicos del compositor de SMS y métricas de coste.
-- =====================================================================

-- Remitente visible del SMS (sender ID alfanumérico, máx. 15 caracteres).
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `sender_id` VARCHAR(15) NULL;
-- Fecha tras la cual ya no tiene sentido entregar el mensaje.
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `expires_at` DATETIME NULL;
-- Etiquetas libres para clasificar y filtrar.
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `tags` VARCHAR(255) NULL;
-- Segmentos de SMS por mensaje (160 caracteres GSM-7 / 70 UCS-2 por segmento).
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `segments` INT NOT NULL DEFAULT 1;
-- Coste estimado al lanzar la campaña (informativo, con el precio del .env).
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `cost_estimate` DECIMAL(12,4) NULL;
-- Distingue un envío puntual de una campaña planificada, para los informes.
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `origin` VARCHAR(16) NOT NULL DEFAULT 'campaign';
ALTER TABLE `campaigns` ADD INDEX IF NOT EXISTS `idx_campaigns_origin` (`origin`);

-- Token de baja por contacto: permite una URL de baja única y verificable sin
-- exponer el identificador interno.
ALTER TABLE `contacts` ADD COLUMN IF NOT EXISTS `unsubscribe_token` CHAR(32) NULL;
ALTER TABLE `contacts` ADD COLUMN IF NOT EXISTS `unsubscribed_at` DATETIME NULL;
ALTER TABLE `contacts` ADD UNIQUE INDEX IF NOT EXISTS `uq_contacts_unsub` (`unsubscribe_token`);

-- Variables propias de ESTE envío (p.ej. "valor" de una cuota, "fecha_pago"),
-- distintas de los datos del contacto. Un recordatorio de pago no es un dato
-- del contacto (cambia cada vez); viven aquí, no en `contacts`.
ALTER TABLE `campaign_recipients` ADD COLUMN IF NOT EXISTS `vars_json` TEXT NULL;

-- =====================================================================
-- Canal de llamadas de voz (channel = 'call'): consume la API externa de
-- TTS/Asterisk ya desplegada en otro servidor (no la administra esta app).
-- Una llamada se origina y Asterisk la marca; el resultado (contestada,
-- ocupado, sin respuesta) se conoce más tarde, no en la misma petición HTTP.
-- Por eso cada destinatario guarda el id de llamada de esa API y cuándo se
-- originó, para que el proceso en segundo plano pueda consultarlo después.
-- El estado intermedio 'Dialing' (en campaign_recipients.status) indica que
-- la llamada ya se originó pero su resultado todavía no se conoce.
-- =====================================================================
ALTER TABLE `campaign_recipients` ADD COLUMN IF NOT EXISTS `call_id` VARCHAR(120) NULL;
ALTER TABLE `campaign_recipients` ADD COLUMN IF NOT EXISTS `dialed_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `campaign_recipients` ADD INDEX IF NOT EXISTS `idx_cr_call_id` (`call_id`);

-- Voz a usar en la locución (F=femenina, M=masculina). Solo aplica a 'call'.
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `voice_gender` CHAR(1) NULL DEFAULT 'F';

-- =====================================================================
-- Email de respuesta elegido en el compositor. NULL = usar el de respuesta
-- por defecto (comportamiento anterior); '' = sin Reply-To (las respuestas
-- van al remitente); una dirección = esa dirección.
-- =====================================================================
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `reply_to` VARCHAR(255) NULL;

-- =====================================================================
-- Créditos (saldo prepago en pesos colombianos).
--
-- * El saldo SOLO se recarga por consola: bin/credits.php. Ningún endpoint
--   HTTP lo modifica, salvo el descuento automático de cada envío.
-- * Los precios por canal viven aquí y nunca se envían al frontend.
-- * Cada movimiento queda en credit_transactions (libro mayor).
-- =====================================================================

-- Una sola fila (id = 1). gauge_reference = saldo justo después de la última
-- recarga: el medidor de la barra superior muestra el saldo relativo a ella.
CREATE TABLE IF NOT EXISTS `credit_account` (
    `id` TINYINT UNSIGNED NOT NULL,
    `balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `gauge_reference` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `credit_account` (`id`, `balance`, `gauge_reference`) VALUES (1, 0, 0);

-- Precio unitario por canal (pesos). Se cambian con: bin/credits.php set-price
CREATE TABLE IF NOT EXISTS `credit_prices` (
    `channel` VARCHAR(16) NOT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores iniciales; INSERT IGNORE no pisa precios ya cambiados por consola.
INSERT IGNORE INTO `credit_prices` (`channel`, `price`) VALUES
    ('sms', 27.00),
    ('email', 20.00),
    ('call', 75.00);

-- Libro de movimientos. type: recharge | debit | refund | adjust.
-- amount es positivo para recarga/reembolso y negativo para débito.
CREATE TABLE IF NOT EXISTS `credit_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(16) NOT NULL,
    `channel` VARCHAR(16) NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `amount` DECIMAL(14,2) NOT NULL,
    `balance_after` DECIMAL(14,2) NOT NULL,
    `campaign_id` INT UNSIGNED NULL,
    `recipient` VARCHAR(255) NULL,
    `note` VARCHAR(255) NULL,
    `actor` VARCHAR(64) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ct_type_time` (`type`, `created_at`),
    KEY `idx_ct_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SMS de doble vía (proveedor IT Cloud). sms_type: '1via' (solo envío) o
-- '2via' (el destinatario puede responder). Las respuestas llegan a
-- /api/sms/inbound/<SMS_INBOUND_SECRET> y se guardan en sms_replies.
-- =====================================================================
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `sms_type` VARCHAR(8) NULL;

CREATE TABLE IF NOT EXISTS `sms_replies` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `phone` VARCHAR(32) NOT NULL,
    `message` TEXT NOT NULL,
    `campaign_id` INT UNSIGNED NULL,
    `contact_id` INT UNSIGNED NULL,
    `is_optout` TINYINT(1) NOT NULL DEFAULT 0,
    `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sr_received` (`received_at`),
    KEY `idx_sr_campaign` (`campaign_id`),
    KEY `idx_sr_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- WhatsApp (Gupshup Partner API, channel = 'whatsapp').
-- Los masivos solo pueden usar plantillas aprobadas por Meta. La línea de
-- cada instalación es una "app" de Gupshup (GUPSHUP_APP_ID en el .env).
-- =====================================================================

-- Plantillas sincronizadas con Gupshup/Meta.
CREATE TABLE IF NOT EXISTS `whatsapp_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `external_id` VARCHAR(64) NULL,
    `element_name` VARCHAR(180) NOT NULL,
    `language` VARCHAR(16) NOT NULL DEFAULT 'es',
    `category` VARCHAR(20) NOT NULL,
    `template_type` VARCHAR(20) NOT NULL DEFAULT 'TEXT',
    `header` VARCHAR(60) NULL,
    `body` TEXT NOT NULL,
    `footer` VARCHAR(60) NULL,
    `buttons_json` TEXT NULL,
    `example` TEXT NULL,
    `var_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    `reason` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wat_name_lang` (`element_name`, `language`),
    KEY `idx_wat_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campaña de WhatsApp: plantilla usada y valores de sus variables {{1}}..{{n}}
-- (cada valor puede llevar campos {{nombre}} que se resuelven por contacto).
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `wa_template_id` INT UNSIGNED NULL;
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `wa_params_json` TEXT NULL;

-- Id del mensaje en el proveedor (Gupshup) para casar los estados del webhook,
-- y último estado de entrega reportado (sent, delivered, read, failed).
ALTER TABLE `campaign_recipients` ADD COLUMN IF NOT EXISTS `external_id` VARCHAR(128) NULL;
ALTER TABLE `campaign_recipients` ADD COLUMN IF NOT EXISTS `delivery_status` VARCHAR(16) NULL;
ALTER TABLE `campaign_recipients` ADD INDEX IF NOT EXISTS `idx_cr_external_id` (`external_id`);

-- Mensajes entrantes (respuestas de los destinatarios).
CREATE TABLE IF NOT EXISTS `whatsapp_inbound` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `wa_message_id` VARCHAR(128) NULL,
    `phone` VARCHAR(32) NOT NULL,
    `profile_name` VARCHAR(255) NULL,
    `message_type` VARCHAR(20) NOT NULL DEFAULT 'text',
    `message` TEXT NOT NULL,
    `campaign_id` INT UNSIGNED NULL,
    `contact_id` INT UNSIGNED NULL,
    `is_optout` TINYINT(1) NOT NULL DEFAULT 0,
    `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wai_msgid` (`wa_message_id`),
    KEY `idx_wai_received` (`received_at`),
    KEY `idx_wai_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Precios de WhatsApp por categoría de plantilla (pesos). Cambiar con:
--   php bin/credits.php set-price whatsapp_marketing 90
-- Usuario (email) que creó la campaña, para filtrar en Reportes.
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `created_by` VARCHAR(255) NULL;
ALTER TABLE `campaigns` ADD INDEX IF NOT EXISTS `idx_campaigns_created_by` (`created_by`);
ALTER TABLE `credit_transactions` ADD INDEX IF NOT EXISTS `idx_ct_created` (`created_at`);
ALTER TABLE `campaigns` ADD INDEX IF NOT EXISTS `idx_campaigns_created` (`created_at`);

-- Las claves de WhatsApp superan 16 caracteres: se amplía la columna.
ALTER TABLE `credit_prices` MODIFY `channel` VARCHAR(32) NOT NULL;
ALTER TABLE `credit_transactions` MODIFY `channel` VARCHAR(32) NULL;
INSERT IGNORE INTO `credit_prices` (`channel`, `price`) VALUES
    ('whatsapp_marketing', 90.00),
    ('whatsapp_utility', 20.00),
    ('whatsapp_authentication', 20.00);
