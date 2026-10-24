<?php

use App\Middleware\JwtMiddleware;
use App\Middleware\RoleMiddleware;
use App\Support\Logger;
use DI\Container;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Error\Renderers\JsonErrorRenderer;
use Slim\Factory\AppFactory;
use Slim\Handlers\ErrorHandler;

// Con el servidor embebido de PHP (php -S ... public/index.php) TODAS las
// peticiones pasan por este script, así que los archivos estáticos (por ejemplo
// las imágenes de /uploads) acabarían en el 404 de Slim. Devolver false le dice
// al servidor que sirva el archivo tal cual. En producción (Apache) de esto ya
// se encarga la condición !-f del .htaccess.
if (PHP_SAPI === 'cli-server') {
    $requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $candidate = __DIR__ . '/' . ltrim(urldecode($requestedPath), '/');
    // realpath + comprobación de prefijo para no servir nada fuera de public/.
    $real = realpath($candidate);
    if ($real !== false && is_file($real) && str_starts_with($real, realpath(__DIR__) . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Create Container
$container = new Container();
AppFactory::setContainer($container);

// Create App
$app = AppFactory::create();

// -------------------------------------------------------------------------
// Rutas PÚBLICAS (sin JWT).
// -------------------------------------------------------------------------
$app->post('/api/auth/login', 'App\Controllers\AuthController:login');
// El refresh es público a propósito: se usa justo cuando el token de acceso ya
// caducó. Su seguridad descansa en el refresh token, que se rota en cada uso.
$app->post('/api/auth/refresh', 'App\Controllers\AuthController:refresh');

// Webhook de Amazon SNS para rebotes y quejas de SES. Es público porque lo
// llama AWS, no un usuario: su autenticidad se comprueba validando la firma
// criptográfica del mensaje dentro del controlador.
$app->post('/api/webhooks/ses', 'App\Controllers\WebhookController:ses');

// Página de baja: la abre el destinatario desde el correo o el SMS, así que no
// puede exigir sesión. Se protege con el token aleatorio de cada contacto.
$app->get('/unsubscribe/{token}', 'App\Controllers\UnsubscribeController:unsubscribe');

// Respuestas de SMS de doble vía: las envía el proveedor (IT Cloud), sin
// sesión. Se protege con el secreto aleatorio SMS_INBOUND_SECRET de la URL.
$app->map(['GET', 'POST'], '/api/sms/inbound/{secret}', 'App\Controllers\SmsReplyController:inbound');

// Webhook de Gupshup (WhatsApp): estados de entrega, respuestas y aprobación
// de plantillas. Se protege con la cabecera X-Gupshup-Webhook-Secret.
$app->post('/api/webhooks/whatsapp', 'App\Controllers\WhatsAppController:webhook');

// Catch-all de preflight CORS: el middleware de app añade las cabeceras.
// Debe quedar fuera del grupo protegido para que OPTIONS no exija token.
$app->options('/api/{routes:.+}', function (Request $req, $res) {
    return $res->withStatus(200);
});

// -------------------------------------------------------------------------
// Rutas PROTEGIDAS por JWT (el middleware se añade al grupo completo).
// -------------------------------------------------------------------------
$app->group('/api', function ($group) {
    // Sesión
    $group->get('/auth/me', 'App\Controllers\AuthController:me');
    $group->post('/auth/logout', 'App\Controllers\AuthController:logout');
    $group->post('/auth/logout-all', 'App\Controllers\AuthController:logoutAll');
    $group->post('/auth/change-password', 'App\Controllers\AuthController:changePassword');

    // Solo administradores: usuarios, configuración de SES (dominios y
    // remitentes), lista de supresión y borrado de campañas. Los operadores
    // envían y consultan, pero no cambian la configuración ni borran historial.
    $group->group('', function ($admin) {
        $admin->get('/users', 'App\Controllers\UserController:index');
        $admin->post('/users', 'App\Controllers\UserController:create');
        $admin->delete('/users/{id}', 'App\Controllers\UserController:delete');
        $admin->post('/users/{id}/reset-password', 'App\Controllers\UserController:resetPassword');

        $admin->post('/domains/verify', 'App\Controllers\DomainVerificationController:verifyDomain');
        $admin->get('/domains/{domain}/status', 'App\Controllers\DomainVerificationController:checkDomainStatus');
        $admin->post('/domains/{domain}/reverify', 'App\Controllers\DomainVerificationController:reverifyDomain');
        $admin->delete('/domains/{domain}', 'App\Controllers\DomainVerificationController:deleteDomain');

        $admin->post('/senders/verify', 'App\Controllers\SenderVerificationController:verifySender');
        $admin->get('/senders/{email}/status', 'App\Controllers\SenderVerificationController:checkSenderStatus');
        $admin->post('/senders/default', 'App\Controllers\SenderVerificationController:setDefaultSender');
        $admin->post('/senders/{email}/resend', 'App\Controllers\SenderVerificationController:resendSenderVerification');
        $admin->delete('/senders/{email}', 'App\Controllers\SenderVerificationController:deleteSender');

        $admin->delete('/suppressions/{email}', 'App\Controllers\ReportController:removeSuppression');

        // WhatsApp: plantillas (las aprueba Meta; crearlas es de administrador).
        $admin->post('/whatsapp/templates', 'App\Controllers\WhatsAppController:createTemplate');
        $admin->post('/whatsapp/templates/sync', 'App\Controllers\WhatsAppController:syncTemplates');
        $admin->delete('/whatsapp/templates/{id}', 'App\Controllers\WhatsAppController:deleteTemplate');
        // WhatsApp: perfil de empresa (lo ven los clientes en WhatsApp).
        $admin->get('/whatsapp/profile', 'App\Controllers\WhatsAppController:profile');
        $admin->post('/whatsapp/profile', 'App\Controllers\WhatsAppController:updateProfile');
        $admin->post('/whatsapp/profile/photo', 'App\Controllers\WhatsAppController:uploadPhoto');
        $admin->delete('/campaigns/{id}', 'App\Controllers\CampaignController:delete');
    })->add(new RoleMiddleware('admin'));

    // Lectura de dominios y remitentes: la usan todos (p.ej. el remitente
    // activo y los emails de respuesta del módulo Email).
    $group->get('/domains', 'App\Controllers\DomainVerificationController:listDomains');
    $group->get('/senders', 'App\Controllers\SenderVerificationController:listVerifiedSenders');

    // Envíos. Todos crean una campaña (ver SendOrchestrator): send y send-bulk
    // son el mismo camino; se mantienen las dos rutas por compatibilidad.
    $group->post('/email/send', 'App\Controllers\EmailController:send');
    $group->post('/email/send-bulk', 'App\Controllers\EmailController:sendBulk');
    $group->post('/email/test', 'App\Controllers\EmailController:test');
    $group->get('/email/fields', 'App\Controllers\EmailController:fields');
    $group->post('/sms/send', 'App\Controllers\SmsController:send');
    $group->post('/sms/send-bulk', 'App\Controllers\SmsController:sendBulk');
    $group->post('/sms/test', 'App\Controllers\SmsController:test');
    $group->post('/sms/estimate', 'App\Controllers\SmsController:estimate');
    $group->get('/sms/fields', 'App\Controllers\SmsController:fields');
    $group->get('/sms/replies', 'App\Controllers\SmsReplyController:list');

    // WhatsApp (Gupshup)
    $group->get('/whatsapp/status', 'App\Controllers\WhatsAppController:status');
    $group->get('/whatsapp/templates', 'App\Controllers\WhatsAppController:templates');
    $group->get('/whatsapp/fields', 'App\Controllers\WhatsAppController:fields');
    $group->post('/whatsapp/send', 'App\Controllers\WhatsAppController:send');
    $group->post('/whatsapp/test', 'App\Controllers\WhatsAppController:test');
    $group->get('/whatsapp/replies', 'App\Controllers\WhatsAppController:replies');

    // Llamadas de voz (TTS + Asterisk, API externa en 192.168.101.92:8000).
    $group->get('/calls/capabilities', 'App\Controllers\CallController:capabilities');
    $group->post('/calls/send', 'App\Controllers\CallController:send');
    $group->post('/calls/test', 'App\Controllers\CallController:test');
    $group->get('/calls/fields', 'App\Controllers\CallController:fields');

    // Contactos
    $group->get('/contacts', 'App\Controllers\ContactController:index');
    $group->post('/contacts', 'App\Controllers\ContactController:create');
    $group->delete('/contacts/{id}', 'App\Controllers\ContactController:delete');
    $group->post('/contacts/import', 'App\Controllers\ContactController:import');

    // Listas de contactos
    $group->get('/lists', 'App\Controllers\ContactController:indexLists');
    $group->post('/lists', 'App\Controllers\ContactController:createList');
    $group->delete('/lists/{id}', 'App\Controllers\ContactController:deleteList');
    $group->get('/lists/{id}/members', 'App\Controllers\ContactController:listMembers');
    $group->post('/lists/{id}/members', 'App\Controllers\ContactController:addMembers');
    $group->delete('/lists/{id}/members/{contactId}', 'App\Controllers\ContactController:removeMember');

    // Plantillas
    $group->get('/templates', 'App\Controllers\TemplateController:index');
    $group->post('/templates', 'App\Controllers\TemplateController:create');
    $group->put('/templates/{id}', 'App\Controllers\TemplateController:update');
    $group->delete('/templates/{id}', 'App\Controllers\TemplateController:delete');

    // Campañas
    $group->get('/campaigns', 'App\Controllers\CampaignController:index');
    $group->get('/campaigns/{id}', 'App\Controllers\CampaignController:show');
    $group->post('/campaigns', 'App\Controllers\CampaignController:create');
    $group->post('/campaigns/{id}/send', 'App\Controllers\CampaignController:sendNow');
    $group->post('/campaigns/{id}/cancel', 'App\Controllers\CampaignController:cancelSchedule');
    $group->get('/campaigns/{id}/export', 'App\Controllers\CampaignController:export');

    // Subida de imágenes para los editores
    $group->post('/uploads/image', 'App\Controllers\UploadController:image');

    // Reportes e historial
    $group->get('/messages', 'App\Controllers\ReportController:messages');
    $group->get('/reports/summary', 'App\Controllers\ReportController:summary');
    $group->get('/suppressions', 'App\Controllers\ReportController:suppressions');
    // Exportación a CSV (Excel).
    $group->get('/reports/export/messages', 'App\Controllers\ReportController:exportMessages');
    $group->get('/reports/export/campaigns', 'App\Controllers\ReportController:exportCampaigns');

    // Dashboard
    $group->get('/dashboard/stats', 'App\Controllers\DashboardController:stats');

    // Saldo (solo lectura). No existe ruta para recargar ni para ver o cambiar
    // precios: eso solo se hace por consola con bin/credits.php.
    $group->get('/balance', 'App\Controllers\BalanceController:show');
})->add(new JwtMiddleware());

// -------------------------------------------------------------------------
// Middleware base. El orden importa: la pila de Slim 4 es LIFO.
// -------------------------------------------------------------------------

// Parseo del cuerpo: Slim 4 NO decodifica JSON por defecto. Sin esto,
// getParsedBody() sería null en los POST con Content-Type: application/json.
$app->addBodyParsingMiddleware();

// Error middleware: en producción (APP_ENV=production) no se exponen trazas.
$displayErrors = ($_ENV['APP_ENV'] ?? 'development') !== 'production';
$errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);

// Forzar JSON también en errores NO capturados y en los 404/405, en vez del
// HTML por defecto de Slim.
/** @var ErrorHandler $defaultHandler */
$defaultHandler = $errorMiddleware->getDefaultErrorHandler();
$defaultHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);
$defaultHandler->forceContentType('application/json');

// Registra en storage/logs/app.log todo error no capturado. Sin esto, en
// producción (APP_DEBUG=false) los fallos desaparecían sin rastro.
$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    \Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app, $defaultHandler) {
    $status = $exception instanceof \Slim\Exception\HttpException
        ? $exception->getCode()
        : 500;

    // Los 404/405 son ruido esperado: se registran como aviso, no como error.
    $level = $status >= 500 ? 'error' : 'warning';

    Logger::app()->log($level, $exception->getMessage(), array_merge(
        Logger::requestContext(),
        [
            'status'    => $status,
            'exception' => get_class($exception),
            'file'      => $exception->getFile() . ':' . $exception->getLine(),
        ]
    ));

    return $defaultHandler($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
});

// Middleware CORS a nivel de app. CRÍTICO: por LIFO, registrarlo DESPUÉS del
// ErrorMiddleware lo deja en la capa más externa, de modo que sus cabeceras se
// aplican también a 404/405/500 y a los 401 del JwtMiddleware.
$corsOrigin = $_ENV['CORS_ALLOWED_ORIGIN'] ?? 'http://localhost:5173';
$app->add(function (Request $request, $handler) use ($corsOrigin) {
    $response = $handler->handle($request);

    return $response
        ->withHeader('Access-Control-Allow-Origin', $corsOrigin)
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});

// Run app
$app->run();
