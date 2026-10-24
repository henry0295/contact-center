<?php

namespace App\Controllers;

use App\Repositories\CampaignRecipientRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\WhatsAppRepository;
use App\Service\CreditService;
use App\Service\InsufficientCreditException;
use App\Service\SendOrchestrator;
use App\Service\WhatsAppService;
use App\Support\Logger;
use App\Support\Sms;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Módulo de WhatsApp: estado de la línea, plantillas, envíos (como campaña),
 * respuestas y el webhook de Gupshup.
 */
class WhatsAppController
{
    use JsonResponseTrait;

    private const OPT_OUT_WORDS = ['salir', 'exit', 'remove', 'stop', 'baja', 'cancelar'];
    private const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];

    private WhatsAppService $wa;
    private WhatsAppRepository $repo;
    private SendOrchestrator $orchestrator;

    public function __construct(?WhatsAppService $wa = null, ?WhatsAppRepository $repo = null, ?SendOrchestrator $orchestrator = null)
    {
        $this->wa = $wa ?? new WhatsAppService();
        $this->repo = $repo ?? new WhatsAppRepository();
        $this->orchestrator = $orchestrator ?? new SendOrchestrator();
    }

    // ----------------------------------------------------------------- línea --

    /**
     * Estado de la línea, cacheado 10 min (la API de Gupshup admite 10
     * peticiones por minuto). La calidad y el límite diario salen de
     * /waba/info, que los da en tiempo real; /ratings solo se consulta como
     * respaldo porque está limitado a 1 petición cada 24 h y depende de que
     * Meta haya enviado un evento.
     */
    public function status(Request $request, Response $response): Response
    {
        $configured = $this->wa->isConfigured();
        $refresh = filter_var($request->getQueryParams()['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $cacheFile = dirname(__DIR__, 2) . '/storage/whatsapp-status.json';
        $cache = is_file($cacheFile) ? (json_decode((string) @file_get_contents($cacheFile), true) ?: []) : [];

        if ($configured && ($refresh || (time() - (int) ($cache['health_at'] ?? 0)) > 600)) {
            try {
                $cache['health'] = $this->wa->health();
            } catch (\Throwable $e) {
                $cache['health'] = ['healthy' => false, 'message' => $e->getMessage()];
            }
            $cache['health_at'] = time();

            try {
                $cache['waba'] = $this->wa->wabaInfo();
            } catch (\Throwable $e) {
                $cache['waba'] = ['message' => $e->getMessage()];
            }
            $cache['waba_at'] = time();

            // Respaldo: solo si /waba/info no trajo calidad y no se consultó
            // /ratings en las últimas 24 h (su límite es 1 petición al día).
            if (($cache['waba']['quality'] ?? null) === null && (time() - (int) ($cache['ratings_at'] ?? 0)) > 86400) {
                try {
                    $cache['ratings'] = $this->wa->ratings();
                } catch (\Throwable $e) {
                    $cache['ratings'] = ['quality' => null, 'limit' => null, 'message' => $e->getMessage()];
                }
                $cache['ratings_at'] = time();
            }
            @file_put_contents($cacheFile, json_encode($cache));
        }

        $link = trim((string) ($_ENV['WHATSAPP_ONBOARDING_LINK'] ?? ''), " \"'");
        $healthy = (bool) ($cache['health']['healthy'] ?? false);
        $rawMessage = (string) ($cache['health']['message'] ?? '');
        $pendingSignup = !$healthy && stripos($rawMessage, 'not live') !== false;
        $isAdmin = $this->isAdmin($request);
        $waba = is_array($cache['waba'] ?? null) ? $cache['waba'] : [];
        $quality = $waba['quality'] ?? $cache['ratings']['quality'] ?? null;
        $limit = $waba['limit'] ?? $cache['ratings']['limit'] ?? null;
        // Avisos de Meta (en inglés) y errores: solo para soporte.
        $notes = $isAdmin ? array_values(array_merge(
            is_array($waba['errors'] ?? null) ? $waba['errors'] : [],
            is_array($waba['notes'] ?? null) ? $waba['notes'] : []
        )) : [];

        return $this->jsonResponse($response, [
            'configured'       => $configured,
            'appId'            => $configured ? WhatsAppService::appId() : null,
            'healthy'          => $healthy,
            // true mientras el número no se haya registrado (app en sandbox).
            'pendingSignup'    => $configured && $pendingSignup,
            'healthMessage'    => $healthy || $rawMessage === '' ? null : self::friendlyHealth($rawMessage),
            // El detalle técnico solo para administradores (soporte).
            'healthDetail'     => $isAdmin && !$healthy && $rawMessage !== '' ? $rawMessage : null,
            'quality'          => $quality,
            'messagingLimit'   => $limit,
            // Datos de la WABA: el nombre visible y el número que ven los clientes.
            'displayName'      => $waba['displayName'] ?? null,
            'phone'            => $waba['phone'] ?? null,
            'canSendMessage'   => $waba['canSendMessage'] ?? null,
            'accountStatus'    => $waba['accountStatus'] ?? null,
            'wabaId'           => $isAdmin ? ($waba['wabaId'] ?? null) : null,
            'lineNotes'        => $notes,
            'checkedAt'        => isset($cache['health_at']) ? date('c', (int) $cache['health_at']) : null,
            // Solo para administradores y solo mientras falte el registro: en
            // cuanto la línea queda activa el enlace ya no sirve y desaparece.
            'onboardingLink'   => $isAdmin && !$healthy && preg_match('#^https://#', $link) ? $link : null,
            // Solo true si Gupshup aceptó el webhook (bin/whatsapp.php subscribe
            // guarda WHATSAPP_WEBHOOK_URL al registrarlo).
            'webhookConfigured' => trim((string) ($_ENV['WHATSAPP_WEBHOOK_SECRET'] ?? '')) !== ''
                && trim((string) ($_ENV['WHATSAPP_WEBHOOK_URL'] ?? ''), " \"'") !== '',
        ]);
    }

    /** Traduce los errores de Gupshup sobre la línea a un texto para el cliente. */
    private static function friendlyHealth(string $raw): string
    {
        $m = strtolower($raw);

        return match (true) {
            str_contains($m, 'not live')                                    => 'Pendiente de registrar el número en WhatsApp.',
            str_contains($m, 'http 401') || str_contains($m, 'token')       => 'La conexión con WhatsApp no es válida. Contacta a tu proveedor.',
            str_contains($m, 'no se pudo conectar')                         => 'No se pudo comprobar la línea (sin conexión con el proveedor). Intenta más tarde.',
            str_contains($m, 'blocked') || str_contains($m, 'restricted')   => 'Meta restringió la línea. Contacta a tu proveedor.',
            default                                                         => 'La línea reporta un problema. Contacta a tu proveedor si persiste.',
        };
    }

    // ------------------------------------------------------ perfil (admin) --

    private const PHOTO_MAX_BYTES = 5 * 1024 * 1024;
    private const PHOTO_MIN_PX = 192;

    public function profile(Request $request, Response $response): Response
    {
        if (!$this->wa->isConfigured()) {
            return $this->jsonResponse($response, ['error' => 'La línea de WhatsApp todavía no está activada.'], 409);
        }
        try {
            return $this->jsonResponse($response, [
                'profile'   => $this->wa->getProfile(),
                'verticals' => WhatsAppService::VERTICALS,
            ]);
        } catch (\Throwable $e) {
            return $this->profileError($response, $e);
        }
    }

    /**
     * body: {about?, description?, address?, email?, website1?, website2?, vertical?}
     * Solo se envían a Meta los campos presentes en la petición.
     */
    public function updateProfile(Request $request, Response $response): Response
    {
        if (!$this->wa->isConfigured()) {
            return $this->jsonResponse($response, ['error' => 'La línea de WhatsApp todavía no está activada.'], 409);
        }
        $d = (array) ($request->getParsedBody() ?? []);
        $val = static fn (string $k): ?string => array_key_exists($k, $d) ? trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', (string) $d[$k]) ?? '') : null;

        $about = $val('about');
        $desc = $val('description');
        $address = $val('address');
        $email = $val('email');
        $web1 = $val('website1');
        $web2 = $val('website2');
        $vertical = $val('vertical');

        $error = null;
        if ($about !== null && ($about === '' || mb_strlen($about) > 139)) {
            $error = 'La información ("Info") es obligatoria y admite hasta 139 caracteres.';
        } elseif ($desc !== null && mb_strlen($desc) > 512) {
            $error = 'La descripción admite hasta 512 caracteres.';
        } elseif ($address !== null && mb_strlen($address) > 256) {
            $error = 'La dirección admite hasta 256 caracteres.';
        } elseif ($email !== null && $email !== '' && (mb_strlen($email) > 128 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $error = 'El email no es válido.';
        } elseif ($vertical !== null && $vertical !== '' && !in_array($vertical, WhatsAppService::VERTICALS, true)) {
            $error = 'Categoría inválida.';
        }
        foreach ([$web1, $web2] as $w) {
            if ($error === null && $w !== null && $w !== '' && (mb_strlen($w) > 256 || !preg_match('#^https?://[^\s<>"]+\.[^\s<>"]+$#i', $w))) {
                $error = 'Los sitios web deben empezar por https:// (o http://).';
            }
        }
        if ($error !== null) {
            return $this->jsonResponse($response, ['error' => $error], 400);
        }

        $fields = array_filter([
            'addLine1'     => $address,
            'desc'         => $desc,
            'profileEmail' => $email,
            'website1'     => $web1,
            'website2'     => $web2,
            'vertical'     => $vertical,
        ], static fn ($v) => $v !== null);

        try {
            if (!empty($fields)) {
                $this->wa->updateProfile($fields);
            }
            if ($about !== null) {
                $this->wa->updateAbout($about);
            }
        } catch (\Throwable $e) {
            return $this->profileError($response, $e);
        }

        Logger::audit()->info('Perfil de WhatsApp actualizado', array_merge(
            Logger::requestContext(),
            ['campos' => array_keys($fields + ($about !== null ? ['about' => 1] : [])), 'por' => $request->getAttribute('user')['email'] ?? null]
        ));

        try {
            $profile = $this->wa->getProfile();
        } catch (\Throwable) {
            $profile = null;
        }

        return $this->jsonResponse($response, ['success' => true, 'message' => 'Perfil actualizado en WhatsApp.', 'profile' => $profile]);
    }

    /** Foto de perfil: multipart "photo", JPG/PNG, mín. 192 px, máx. 5 MB. */
    public function uploadPhoto(Request $request, Response $response): Response
    {
        if (!$this->wa->isConfigured()) {
            return $this->jsonResponse($response, ['error' => 'La línea de WhatsApp todavía no está activada.'], 409);
        }
        $file = $request->getUploadedFiles()['photo'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonResponse($response, ['error' => 'No se recibió ninguna imagen (máx. 5 MB).'], 400);
        }
        if ((int) $file->getSize() > self::PHOTO_MAX_BYTES) {
            return $this->jsonResponse($response, ['error' => 'La imagen supera 5 MB.'], 400);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ccwa_');
        if ($tmp === false) {
            return $this->jsonResponse($response, ['error' => 'No se pudo procesar la imagen.'], 500);
        }
        try {
            $file->moveTo($tmp);
            // Se valida el contenido real, no la extensión.
            $info = @getimagesize($tmp);
            $type = $info[2] ?? null;
            if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                return $this->jsonResponse($response, ['error' => 'Formato no admitido. Usa JPG o PNG.'], 400);
            }
            if (min((int) $info[0], (int) $info[1]) < self::PHOTO_MIN_PX) {
                return $this->jsonResponse($response, ['error' => 'La imagen debe medir al menos 192×192 px (recomendado 640×640).'], 400);
            }
            $this->wa->updatePhoto($tmp, 'perfil.' . ($type === IMAGETYPE_PNG ? 'png' : 'jpg'));
        } catch (\Throwable $e) {
            return $this->profileError($response, $e);
        } finally {
            @unlink($tmp);
        }

        Logger::audit()->info('Foto de perfil de WhatsApp actualizada', array_merge(
            Logger::requestContext(),
            ['por' => $request->getAttribute('user')['email'] ?? null]
        ));

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Foto actualizada. WhatsApp puede tardar unos minutos en mostrarla.',
        ]);
    }

    private function profileError(Response $response, \Throwable $e): Response
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'not live') !== false) {
            return $this->jsonResponse($response, [
                'error'   => 'El perfil se puede editar cuando el número esté registrado en WhatsApp.',
                'notLive' => true,
            ], 409);
        }
        Logger::app()->warning('Error en el perfil de WhatsApp', ['error' => $msg]);

        return $this->jsonResponse($response, ['error' => $msg], 502);
    }

    // ------------------------------------------------------------ plantillas --

    public function templates(Request $request, Response $response): Response
    {
        $only = $request->getQueryParams()['status'] ?? null;
        $only = is_string($only) && $only !== '' ? strtoupper($only) : null;

        return $this->jsonResponse($response, ['templates' => $this->repo->templates($only)]);
    }

    /** Trae de Gupshup todas las plantillas y su estado (admin). */
    public function syncTemplates(Request $request, Response $response): Response
    {
        try {
            $remote = $this->wa->listTemplates();
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 502);
        }

        $n = 0;
        foreach ($remote as $t) {
            $meta = json_decode((string) ($t['containerMeta'] ?? ''), true) ?: [];
            $body = (string) ($meta['data'] ?? $t['data'] ?? '');
            if (($t['elementName'] ?? '') === '' || $body === '') {
                continue;
            }
            $this->repo->upsertTemplate([
                'external_id'   => (string) ($t['id'] ?? ''),
                'element_name'  => (string) $t['elementName'],
                'language'      => (string) ($t['languageCode'] ?? 'es'),
                'category'      => strtoupper((string) ($t['category'] ?? 'MARKETING')),
                'template_type' => strtoupper((string) ($t['templateType'] ?? 'TEXT')),
                'header'        => isset($meta['header']) ? mb_substr((string) $meta['header'], 0, 60) : null,
                'body'          => $body,
                'footer'        => isset($meta['footer']) ? mb_substr((string) $meta['footer'], 0, 60) : null,
                'buttons_json'  => isset($meta['buttons']) ? json_encode($meta['buttons'], JSON_UNESCAPED_UNICODE) : null,
                'example'       => isset($meta['sampleText']) ? (string) $meta['sampleText'] : null,
                'status'        => strtoupper((string) ($t['status'] ?? 'PENDING')),
                'reason'        => isset($t['reason']) ? mb_substr((string) $t['reason'], 0, 500) : null,
            ]);
            $n++;
        }

        return $this->jsonResponse($response, [
            'success'   => true,
            'synced'    => $n,
            'templates' => $this->repo->templates(),
        ]);
    }

    /**
     * Crea una plantilla y la envía a aprobación de Meta (admin).
     * body: {name, category, language, header?, body, footer?, examples[], buttons?[],
     *        auth?: {securityRecommendation, expirationMinutes, buttonText}}
     * Para AUTHENTICATION el texto lo fija Meta: solo se eligen las opciones.
     */
    public function createTemplate(Request $request, Response $response): Response
    {
        $d = (array) ($request->getParsedBody() ?? []);
        $name = strtolower(trim((string) ($d['name'] ?? '')));
        $language = trim((string) ($d['language'] ?? 'es'));

        if (!preg_match('/^[a-z0-9_]{3,180}$/', $name)) {
            return $this->jsonResponse($response, ['error' => 'El nombre solo admite minúsculas, números y guion bajo (p.ej. promo_octubre).'], 400);
        }
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $language)) {
            return $this->jsonResponse($response, ['error' => 'Idioma inválido (p.ej. es, es_CO, en_US).'], 400);
        }
        [$error, $t] = $this->parseTemplateInput($d);
        if ($error !== null) {
            return $this->jsonResponse($response, ['error' => $error], 400);
        }

        try {
            $remote = $this->wa->applyTemplate($t['remote'] + ['elementName' => $name, 'languageCode' => $language]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 502);
        }

        $id = $this->repo->upsertTemplate($t['local'] + [
            'external_id'   => (string) ($remote['id'] ?? ''),
            'element_name'  => $name,
            'language'      => $language,
            'template_type' => 'TEXT',
            'status'        => strtoupper((string) ($remote['status'] ?? 'PENDING')),
        ]);

        Logger::audit()->info('Plantilla de WhatsApp enviada a aprobación', array_merge(
            Logger::requestContext(),
            ['plantilla' => $name, 'categoria' => $t['local']['category'], 'por' => $request->getAttribute('user')['email'] ?? null]
        ));

        return $this->jsonResponse($response, [
            'success'  => true,
            'message'  => 'Plantilla enviada a Meta para aprobación. Suele tardar de minutos a 24 h.',
            'template' => $this->repo->findTemplate($id),
        ], 201);
    }

    /**
     * Edita una plantilla de texto en Meta y la reenvía a revisión (admin).
     * Sirve para corregir una rechazada o actualizar una aprobada (Meta: hasta
     * 10 ediciones en 30 días, una cada 24 h, sin cambiar la categoría).
     */
    public function editTemplate(Request $request, Response $response, array $args): Response
    {
        $tpl = $this->repo->findTemplate((int) ($args['id'] ?? 0));
        if ($tpl === null) {
            return $this->jsonResponse($response, ['error' => 'La plantilla no existe'], 404);
        }
        if (($tpl['external_id'] ?? '') === '') {
            return $this->jsonResponse($response, ['error' => 'Esta plantilla no tiene identificador de Meta: sincroniza primero.'], 409);
        }
        if (($tpl['template_type'] ?? 'TEXT') !== 'TEXT') {
            return $this->jsonResponse($response, ['error' => 'Desde aquí solo se editan plantillas de texto.'], 409);
        }
        if ($tpl['status'] === 'PENDING') {
            return $this->jsonResponse($response, ['error' => 'La plantilla está en revisión: espera la respuesta de Meta antes de editarla.'], 409);
        }

        $d = (array) ($request->getParsedBody() ?? []);
        // Meta no permite cambiar la categoría de una plantilla aprobada.
        if ($tpl['status'] === 'APPROVED') {
            $d['category'] = $tpl['category'];
        }
        [$error, $t] = $this->parseTemplateInput($d);
        if ($error !== null) {
            return $this->jsonResponse($response, ['error' => $error], 400);
        }

        $r = $t['remote'];
        $form = ['content' => $r['content'], 'category' => $r['category'], 'templateType' => 'TEXT', 'example' => $r['example']];
        foreach (['header', 'footer'] as $k) {
            if (!empty($r[$k])) {
                $form[$k] = $r[$k];
            }
        }
        if (!empty($r['header'])) {
            $form['exampleHeader'] = $r['header'];
        }
        if (!empty($r['buttons'])) {
            $form['buttons'] = json_encode($r['buttons'], JSON_UNESCAPED_UNICODE);
        }
        if ($r['category'] === 'AUTHENTICATION') {
            unset($form['header'], $form['footer'], $form['exampleHeader']);
            $form['addSecurityRecommendation'] = !empty($r['addSecurityRecommendation']) ? 'true' : 'false';
            if (!empty($r['codeExpirationMinutes'])) {
                $form['codeExpirationMinutes'] = (string) (int) $r['codeExpirationMinutes'];
            }
        }

        try {
            $this->wa->editTemplate((string) $tpl['external_id'], $form);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 502);
        }
        $this->repo->updateTemplateContent((int) $tpl['id'], $t['local']);

        Logger::audit()->info('Plantilla de WhatsApp editada y reenviada a revisión', array_merge(
            Logger::requestContext(),
            ['plantilla' => $tpl['element_name'], 'estado_anterior' => $tpl['status'], 'por' => $request->getAttribute('user')['email'] ?? null]
        ));

        return $this->jsonResponse($response, [
            'success'  => true,
            'message'  => 'Cambios enviados a Meta. La plantilla queda en revisión hasta que la aprueben.',
            'template' => $this->repo->findTemplate((int) $tpl['id']),
        ]);
    }

    /**
     * Quita la plantilla (admin). Con ?remote=1 también la borra en Meta: es
     * irreversible, afecta a todos sus idiomas y Meta no deja reutilizar el
     * nombre durante 30 días. Sin remote solo se quita de la lista local
     * (reaparece al sincronizar).
     */
    public function deleteTemplate(Request $request, Response $response, array $args): Response
    {
        $tpl = $this->repo->findTemplate((int) ($args['id'] ?? 0));
        if ($tpl === null) {
            return $this->jsonResponse($response, ['error' => 'La plantilla no existe'], 404);
        }
        $remote = filter_var($request->getQueryParams()['remote'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $pending = $this->repo->pendingCampaignsUsing((string) $tpl['element_name']);
        if (!empty($pending)) {
            return $this->jsonResponse($response, [
                'error'     => 'Hay campañas sin terminar que usan esta plantilla ("' . $pending[0]['name'] . '"). Cancélalas o espera a que terminen.',
                'campaigns' => $pending,
            ], 409);
        }

        if (!$remote) {
            $this->repo->deleteTemplate((int) $tpl['id']);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Plantilla quitada de la lista (sigue existiendo en Meta).']);
        }

        try {
            $this->wa->deleteRemoteTemplate((string) $tpl['element_name']);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'does not exist') !== false || stripos($msg, 'Does not exists') !== false) {
                // Ya no estaba en Meta: se limpia la copia local.
                $n = $this->repo->deleteTemplatesByName((string) $tpl['element_name']);

                return $this->jsonResponse($response, ['success' => true, 'deleted' => $n, 'message' => 'La plantilla ya no existía en Meta; se quitó de la lista.']);
            }
            if (stripos($msg, 'sandbox') !== false || stripos($msg, 'not live') !== false) {
                $msg = 'Meta solo permite borrar plantillas cuando el número está registrado (línea en vivo).';
            }

            return $this->jsonResponse($response, ['error' => $msg], 502);
        }
        $n = $this->repo->deleteTemplatesByName((string) $tpl['element_name']);

        Logger::audit()->info('Plantilla de WhatsApp borrada en Meta', array_merge(
            Logger::requestContext(),
            ['plantilla' => $tpl['element_name'], 'versiones' => $n, 'estado' => $tpl['status'], 'por' => $request->getAttribute('user')['email'] ?? null]
        ));

        return $this->jsonResponse($response, [
            'success' => true,
            'deleted' => $n,
            'message' => 'Plantilla borrada en Meta. El nombre "' . $tpl['element_name'] . '" no se podrá reutilizar durante 30 días.',
        ]);
    }

    /** Texto fijo de Meta para autenticación (Meta lo traduce al idioma de la plantilla). */
    private const AUTH_CONTENT = '{{1}} is your verification code.';

    /**
     * Valida los campos de una plantilla y arma lo que se envía a Gupshup y lo
     * que se guarda localmente.
     *
     * @return array{0:?string,1:array{remote:array,local:array}}
     */
    private function parseTemplateInput(array $d): array
    {
        $category = strtoupper(trim((string) ($d['category'] ?? '')));
        if (!in_array($category, self::CATEGORIES, true)) {
            return ['Categoría inválida (MARKETING, UTILITY o AUTHENTICATION).', []];
        }

        if ($category === 'AUTHENTICATION') {
            $a = (array) ($d['auth'] ?? []);
            $security = filter_var($a['securityRecommendation'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $minutes = isset($a['expirationMinutes']) && $a['expirationMinutes'] !== '' && $a['expirationMinutes'] !== null ? (int) $a['expirationMinutes'] : null;
            $btn = trim((string) ($a['buttonText'] ?? 'Copiar código'));
            if ($minutes !== null && ($minutes < 1 || $minutes > 90)) {
                return ['El vencimiento del código debe estar entre 1 y 90 minutos.', []];
            }
            if ($btn === '' || mb_strlen($btn) > 25) {
                return ['El texto del botón admite hasta 25 caracteres.', []];
            }
            $buttons = [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => $btn]];
            $body = '{{1}} es tu código de verificación.' . ($security ? ' Por tu seguridad, no lo compartas.' : '');

            return [null, [
                'remote' => [
                    'category' => 'AUTHENTICATION', 'content' => self::AUTH_CONTENT,
                    'example' => '[123456] is your verification code.', 'buttons' => $buttons,
                    'addSecurityRecommendation' => $security, 'codeExpirationMinutes' => $minutes,
                ],
                'local' => [
                    'category' => 'AUTHENTICATION', 'header' => null, 'body' => $body,
                    'footer' => $minutes !== null ? "Este código vence en {$minutes} minutos." : null,
                    'buttons_json' => json_encode($buttons, JSON_UNESCAPED_UNICODE),
                    'example' => '123456 es tu código de verificación.', 'var_count' => 1,
                ],
            ]];
        }

        $header = trim((string) ($d['header'] ?? ''));
        $body = trim((string) ($d['body'] ?? ''));
        $footer = trim((string) ($d['footer'] ?? ''));
        $examples = array_values(array_map(static fn ($v) => trim((string) $v), (array) ($d['examples'] ?? [])));

        if ($body === '' || mb_strlen($body) > 1024) {
            return ['El cuerpo es obligatorio y admite hasta 1024 caracteres.', []];
        }
        if (mb_strlen($header) > 60 || mb_strlen($footer) > 60) {
            return ['Encabezado y pie admiten hasta 60 caracteres.', []];
        }
        if (preg_match('/\{\{/', $header . $footer)) {
            return ['Las variables solo se permiten en el cuerpo.', []];
        }

        $vars = WhatsAppRepository::countVars($body);
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m);
        $nums = array_unique(array_map('intval', $m[1]));
        sort($nums);
        if ($vars > 0 && $nums !== range(1, $vars)) {
            return ['Las variables deben ser consecutivas: {{1}}, {{2}}, ...', []];
        }
        if (preg_match('/^\s*\{\{|\{\{\s*\d+\s*\}\}[\s.!?¡¿,;:]*$/u', $body)) {
            return ['Meta no permite que el cuerpo empiece o termine con una variable: añade texto antes y después.', []];
        }
        if (preg_match('/\}\}\s*\{\{/', $body)) {
            return ['Meta no permite dos variables seguidas sin texto entre ellas.', []];
        }
        if (count($examples) < $vars || in_array('', array_slice($examples, 0, $vars), true)) {
            return ['Escribe un ejemplo para cada variable (Meta lo usa para revisar la plantilla).', []];
        }

        $buttons = [];
        foreach ((array) ($d['buttons'] ?? []) as $b) {
            $type = strtoupper((string) ($b['type'] ?? ''));
            $text = trim((string) ($b['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 25) {
                return ['Cada botón necesita un texto de hasta 25 caracteres.', []];
            }
            if ($type === 'QUICK_REPLY') {
                $buttons[] = ['type' => 'QUICK_REPLY', 'text' => $text];
            } elseif ($type === 'URL') {
                $url = trim((string) ($b['url'] ?? ''));
                if (!preg_match('#^https://[^\s{}]+\.[^\s{}]+$#', $url)) {
                    return ['El botón de enlace necesita una URL https fija.', []];
                }
                $buttons[] = ['type' => 'URL', 'text' => $text, 'url' => $url, 'buttonValue' => $url, 'suffix' => ''];
            } elseif ($type === 'PHONE_NUMBER') {
                $phone = preg_replace('/[^\d+]/', '', (string) ($b['phone_number'] ?? '')) ?? '';
                if (!preg_match('/^\+\d{8,15}$/', $phone)) {
                    return ['El botón de llamada necesita el número con indicativo, p.ej. +573001234567.', []];
                }
                $buttons[] = ['type' => 'PHONE_NUMBER', 'text' => $text, 'phone_number' => $phone];
            } else {
                return ['Tipo de botón inválido (respuesta rápida, enlace o llamada).', []];
            }
        }
        if (count($buttons) > 3) {
            return ['Máximo 3 botones.', []];
        }
        $types = array_count_values(array_column($buttons, 'type'));
        if (($types['URL'] ?? 0) > 2 || ($types['PHONE_NUMBER'] ?? 0) > 1) {
            return ['Máximo 2 botones de enlace y 1 de llamada.', []];
        }

        // Ejemplo con el formato de Gupshup: las variables se sustituyen por [valor].
        $example = (string) preg_replace_callback(
            '/\{\{\s*(\d+)\s*\}\}/',
            static fn ($mm) => '[' . ($examples[(int) $mm[1] - 1] ?? 'ejemplo') . ']',
            $body
        );

        return [null, [
            'remote' => [
                'category' => $category, 'content' => $body, 'example' => $example,
                'header' => $header !== '' ? $header : null, 'footer' => $footer !== '' ? $footer : null,
                'buttons' => $buttons,
            ],
            'local' => [
                'category' => $category, 'header' => $header !== '' ? $header : null, 'body' => $body,
                'footer' => $footer !== '' ? $footer : null,
                'buttons_json' => $buttons ? json_encode($buttons, JSON_UNESCAPED_UNICODE) : null,
                'example' => $example, 'var_count' => $vars,
            ],
        ]];
    }

    // ---------------------------------------------------------------- envíos --

    public function send(Request $request, Response $response): Response
    {
        return $this->dispatch($request, $response, 'direct', null);
    }

    public function test(Request $request, Response $response): Response
    {
        $to = trim((string) (($request->getParsedBody() ?? [])['to'] ?? ''));
        if ($to === '') {
            return $this->jsonResponse($response, ['error' => 'Indica el número para la prueba.'], 400);
        }

        return $this->dispatch($request, $response, 'test', [$to]);
    }

    private function dispatch(Request $request, Response $response, string $origin, ?array $forceRecipients): Response
    {
        $data = (array) ($request->getParsedBody() ?? []);
        if (!$this->wa->isConfigured()) {
            return $this->jsonResponse($response, ['error' => 'La línea de WhatsApp todavía no está activada.'], 409);
        }

        try {
            $result = $this->orchestrator->dispatch([
                'channel'       => 'whatsapp',
                'name'          => $origin === 'test' ? 'PRUEBA-WHATSAPP-' . date('Y-m-d-H:i:s') : ($data['name'] ?? null),
                'recipients'    => $forceRecipients ?? ($data['recipients'] ?? []),
                'listId'        => $forceRecipients === null && !empty($data['listId']) ? (int) $data['listId'] : null,
                'tags'          => $data['tags'] ?? null,
                'scheduleType'  => $origin === 'test' ? 'now' : ($data['scheduleType'] ?? 'now'),
                'scheduledAt'   => $data['scheduledAt'] ?? null,
                'expiresAt'     => $data['expiresAt'] ?? null,
                'origin'        => $origin,
                'waTemplateId'  => (int) ($data['templateId'] ?? 0),
                'waParams'      => (array) ($data['params'] ?? []),
                'waHeaderMedia' => $data['headerMediaUrl'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse($response, ['error' => $e->getMessage()], $e instanceof InsufficientCreditException ? 402 : 400);
        } catch (\Throwable $e) {
            return $this->internalError($response, 'No se pudo registrar el envío', $e);
        }

        $allFailed = $result['mode'] === 'sent' && (int) ($result['result']['sent'] ?? 0) === 0;
        if ($allFailed) {
            $result['hint'] = $this->firstError((int) $result['campaign']['id']);
        }
        $status = $result['mode'] === 'sent' ? ($allFailed ? 502 : 200) : 202;

        return $this->jsonResponse($response, array_merge($result, ['success' => !$allFailed]), $status);
    }

    private function firstError(int $campaignId): ?string
    {
        foreach ((new CampaignRecipientRepository())->forCampaign($campaignId) as $r) {
            if (($r['status'] ?? '') === 'Failed' && !empty($r['error'])) {
                return (string) $r['error'];
            }
        }

        return null;
    }

    public function fields(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, ['fields' => Sms::availableFields()]);
    }

    public function replies(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($q['perPage'] ?? 25)));
        $result = $this->repo->inbound($page, $perPage, mb_substr(trim((string) ($q['q'] ?? '')), 0, 100));

        return $this->jsonResponse($response, $result + ['page' => $page, 'perPage' => $perPage]);
    }

    // --------------------------------------------------------------- webhook --

    /**
     * Webhook de Gupshup (suscripción v3, formato Meta). Público: lo protege la
     * cabecera X-Gupshup-Webhook-Secret, que se fija al crear la suscripción.
     * Siempre responde 200 ante un payload válido para que Gupshup no reintente
     * sin fin; los errores se registran en el log.
     */
    public function webhook(Request $request, Response $response): Response
    {
        $expected = trim((string) ($_ENV['WHATSAPP_WEBHOOK_SECRET'] ?? ''));
        $given = $request->getHeaderLine('X-Gupshup-Webhook-Secret');
        if (strlen($expected) < 16 || !hash_equals($expected, $given)) {
            Logger::app()->warning('Webhook de WhatsApp rechazado: secreto inválido', [
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
            ]);

            return $this->jsonResponse($response, ['error' => 'Not found'], 404);
        }

        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            $payload = json_decode((string) $request->getBody(), true);
        }
        if (!is_array($payload)) {
            return $this->jsonResponse($response, ['error' => 'Payload inválido'], 400);
        }

        try {
            foreach ((array) ($payload['entry'] ?? []) as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    $field = (string) ($change['field'] ?? '');
                    $value = (array) ($change['value'] ?? []);
                    if ($field === 'message_template_status_update') {
                        $this->onTemplateStatus($value);
                        continue;
                    }
                    foreach ((array) ($value['statuses'] ?? []) as $st) {
                        $this->onStatus((array) $st);
                    }
                    $names = [];
                    foreach ((array) ($value['contacts'] ?? []) as $c) {
                        $names[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
                    }
                    foreach ((array) ($value['messages'] ?? []) as $msg) {
                        $this->onInbound((array) $msg, $names);
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::app()->error('Error procesando el webhook de WhatsApp', ['error' => $e->getMessage()]);
        }

        return $this->jsonResponse($response, ['status' => 'ok']);
    }

    private function onStatus(array $st): void
    {
        $status = strtolower((string) ($st['status'] ?? ''));
        if ($status === '' || $status === 'set-callback') {
            return;
        }
        $r = $this->repo->recipientByExternalId([(string) ($st['gs_id'] ?? ''), (string) ($st['id'] ?? '')]);
        if ($r === null || ($r['channel'] ?? '') !== 'whatsapp') {
            return;
        }

        $recipientId = (int) $r['id'];
        $campaignId = (int) $r['campaign_id'];
        $this->repo->setDeliveryStatus($recipientId, $status);

        // Fallo asíncrono (número sin WhatsApp, plantilla pausada, límite...):
        // no se entregó, así que se marca fallido y se devuelve el cobro.
        if ($status === 'failed' && ($r['status'] ?? '') === 'Sent') {
            $reason = trim((string) ($st['reason'] ?? $st['errors'][0]['title'] ?? $st['errors'][0]['message'] ?? ''));
            $code = (string) ($st['code'] ?? $st['errors'][0]['code'] ?? '');
            $msg = 'WhatsApp no entregó el mensaje' . ($reason !== '' ? ': ' . $reason : '') . ($code !== '' ? " (código $code)" : '');
            (new CampaignRecipientRepository())->markFailed($recipientId, mb_substr($msg, 0, 500));

            $template = $this->repo->findTemplate((int) ($r['wa_template_id'] ?? 0));
            try {
                (new CreditService())->refund(
                    CreditService::priceKey('whatsapp', $template['category'] ?? null),
                    $campaignId,
                    (string) $r['phone'],
                    'WhatsApp no entregado'
                );
            } catch (\Throwable $e) {
                Logger::app()->error('No se pudo reembolsar un WhatsApp fallido', ['campaignId' => $campaignId, 'error' => $e->getMessage()]);
            }
            (new CampaignRepository())->refreshCounts($campaignId);
        }
    }

    private function onInbound(array $msg, array $names): void
    {
        $from = WhatsAppService::normalizePhone((string) ($msg['from'] ?? ''));
        if (strlen($from) < 7) {
            return;
        }
        $type = (string) ($msg['type'] ?? 'text');
        $text = match ($type) {
            'text'        => (string) ($msg['text']['body'] ?? ''),
            'button'      => (string) ($msg['button']['text'] ?? ''),
            'interactive' => (string) ($msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? ''),
            'image', 'video', 'document', 'audio', 'sticker' => '[' . $type . ']' . (isset($msg[$type]['caption']) ? ' ' . $msg[$type]['caption'] : ''),
            'location'    => '[ubicación] ' . ($msg['location']['latitude'] ?? '') . ',' . ($msg['location']['longitude'] ?? ''),
            default       => '[' . $type . ']',
        };
        $text = mb_substr(trim($text), 0, 4000);
        if ($text === '') {
            return;
        }

        $match = $this->repo->matchRecipient($from);
        $optOut = in_array(mb_strtolower(trim($text, " \t\n\r.!¡¿?")), self::OPT_OUT_WORDS, true);

        $isNew = $this->repo->saveInbound([
            'wa_message_id' => isset($msg['id']) ? mb_substr((string) $msg['id'], 0, 128) : null,
            'phone'         => $from,
            'profile_name'  => $names[(string) ($msg['from'] ?? '')] ?? null,
            'message_type'  => mb_substr($type, 0, 20),
            'message'       => $text,
            'campaign_id'   => $match['campaign_id'] ?? null,
            'contact_id'    => $match['contact_id'] ?? null,
            'is_optout'     => $optOut,
        ]);

        if ($isNew && $optOut) {
            $n = $this->repo->unsubscribeByPhone($from);
            Logger::audit()->info('Baja por respuesta de WhatsApp', ['phone' => $from, 'contactos' => $n]);
        }
    }

    private function onTemplateStatus(array $v): void
    {
        $event = strtoupper((string) ($v['event'] ?? ''));
        $key = (string) ($v['message_template_id'] ?? $v['message_template_name'] ?? '');
        if ($event === '' || $key === '') {
            return;
        }
        $n = $this->repo->updateTemplateStatus($key, $event, isset($v['reason']) ? mb_substr((string) $v['reason'], 0, 500) : null);
        if ($n === 0 && !empty($v['message_template_name'])) {
            $this->repo->updateTemplateStatus((string) $v['message_template_name'], $event, $v['reason'] ?? null);
        }
    }

    private function isAdmin(Request $request): bool
    {
        return strtolower((string) ($request->getAttribute('user')['role'] ?? '')) === 'admin';
    }
}
