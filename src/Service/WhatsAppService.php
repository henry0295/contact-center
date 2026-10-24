<?php

namespace App\Service;

use App\Support\Logger;
use GuzzleHttp\Client;

/**
 * WhatsApp por la Partner API de Gupshup, con el TOKEN DE LA APP de esta
 * instalación (GUPSHUP_APP_ID / GUPSHUP_APP_TOKEN). Ese token solo sirve para
 * la línea de este cliente: las credenciales de partner (que administran a
 * todos los clientes) nunca se guardan aquí; solo las usa bin/whatsapp.php
 * por consola, al activar la línea.
 *
 * Endpoints (https://partner.gupshup.io):
 *   POST /partner/app/{appId}/v3/message      enviar plantilla (formato Meta)
 *   GET  /partner/app/{appId}/templates       listar plantillas y su estado
 *   POST /partner/app/{appId}/templates       solicitar una plantilla nueva
 *   GET  /partner/app/{appId}/health          estado de la línea
 *   GET  /partner/app/{appId}/ratings         calidad y límite de mensajería
 */
class WhatsAppService
{
    public const BASE_URL = 'https://partner.gupshup.io';

    private ?Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http;
    }

    public static function appId(): string
    {
        return trim((string) ($_ENV['GUPSHUP_APP_ID'] ?? ''));
    }

    private static function appToken(): string
    {
        return trim((string) ($_ENV['GUPSHUP_APP_TOKEN'] ?? ''));
    }

    public function isConfigured(): bool
    {
        return self::appId() !== '' && self::appToken() !== '';
    }

    /**
     * Número en formato internacional sin "+": prefijo de país + número.
     * Un celular colombiano de 10 dígitos (3xx...) recibe el 57.
     */
    public static function normalizePhone(string $phone): string
    {
        return SmsService::normalizePhone($phone);
    }

    /**
     * Envía una plantilla aprobada.
     *
     * @param string[] $bodyParams valores de {{1}}..{{n}} del cuerpo, en orden
     * @param array{type:string,link:string}|null $headerMedia imagen/video/documento de cabecera
     * @return string id del mensaje en Gupshup (se casa con los eventos del webhook)
     */
    public function sendTemplate(string $phone, string $templateName, string $language, array $bodyParams = [], ?array $headerMedia = null, bool $otp = false): string
    {
        $this->assertConfigured();

        $components = [];
        if ($headerMedia !== null && !empty($headerMedia['link'])) {
            $type = in_array($headerMedia['type'], ['image', 'video', 'document'], true) ? $headerMedia['type'] : 'image';
            $components[] = ['type' => 'header', 'parameters' => [['type' => $type, $type => ['link' => $headerMedia['link']]]]];
        }
        if (!empty($bodyParams)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    // WhatsApp rechaza parámetros vacíos o con saltos de línea / tabs.
                    static fn ($v) => ['type' => 'text', 'text' => self::cleanParam((string) $v)],
                    array_values($bodyParams)
                ),
            ];
        }

        // Autenticación: el código va también en el botón "Copiar código".
        if ($otp && !empty($bodyParams)) {
            $components[] = [
                'type' => 'button', 'sub_type' => 'url', 'index' => '0',
                'parameters' => [['type' => 'text', 'text' => self::cleanParam((string) array_values($bodyParams)[0])]],
            ];
        }

        $template = ['name' => $templateName, 'language' => ['policy' => 'deterministic', 'code' => $language]];
        if (!empty($components)) {
            $template['components'] = $components;
        }

        $to = self::normalizePhone($phone);
        [$code, $data, $raw] = $this->request('POST', '/v3/message', [
            'json' => [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
                'type'              => 'template',
                'template'          => $template,
            ],
        ]);

        $id = $data['messages'][0]['id'] ?? null;
        if ($code >= 400 || !is_string($id) || $id === '') {
            $msg = self::errorMessage($data, $raw, $code);
            Logger::mail()->error('WhatsApp rechazado', ['to' => $to, 'template' => $templateName, 'http' => $code, 'error' => $msg]);
            throw new \RuntimeException($msg);
        }

        Logger::mail()->info('WhatsApp enviado', ['to' => $to, 'template' => $templateName, 'id' => $id]);

        return $id;
    }

    /** @return array<int,array<string,mixed>> plantillas tal como las devuelve Gupshup */
    public function listTemplates(): array
    {
        $this->assertConfigured();
        $all = [];
        for ($page = 1; $page <= 20; $page++) {
            [$code, $data, $raw] = $this->request('GET', '/templates', ['query' => ['pageNo' => $page, 'pageSize' => 100]]);
            if ($code >= 400) {
                throw new \RuntimeException(self::errorMessage($data, $raw, $code));
            }
            $batch = $data['templates'] ?? [];
            if (!is_array($batch) || empty($batch)) {
                break;
            }
            array_push($all, ...$batch);
            if (count($batch) < 100) {
                break;
            }
        }

        return $all;
    }

    /**
     * Solicita una plantilla nueva (queda PENDING hasta que Meta la apruebe).
     *
     * @param array{elementName:string,languageCode:string,category:string,content:string,example:string,
     *              header?:?string,footer?:?string,buttons?:?array} $t
     * @return array<string,mixed> plantilla creada (id, status...)
     */
    public function applyTemplate(array $t): array
    {
        $this->assertConfigured();
        $form = [
            'elementName'                 => $t['elementName'],
            'languageCode'                => $t['languageCode'],
            'category'                    => $t['category'],
            'templateType'                => 'TEXT',
            'vertical'                    => 'Contact Center',
            'content'                     => $t['content'],
            'example'                     => $t['example'],
            'enableSample'                => 'true',
            'allowTemplateCategoryChange' => 'false',
        ];
        if (!empty($t['header'])) {
            $form['header'] = $t['header'];
            $form['exampleHeader'] = $t['header'];
        }
        if (!empty($t['footer'])) {
            $form['footer'] = $t['footer'];
        }
        if (!empty($t['buttons'])) {
            $form['buttons'] = json_encode($t['buttons'], JSON_UNESCAPED_UNICODE);
        }
        // Autenticación: texto fijo de Meta; solo opciones de seguridad y vencimiento.
        if (($t['category'] ?? '') === 'AUTHENTICATION') {
            unset($form['header'], $form['exampleHeader'], $form['footer']);
            $form['addSecurityRecommendation'] = !empty($t['addSecurityRecommendation']) ? 'true' : 'false';
            if (!empty($t['codeExpirationMinutes'])) {
                $form['codeExpirationMinutes'] = (string) (int) $t['codeExpirationMinutes'];
            }
        }

        [$code, $data, $raw] = $this->request('POST', '/templates', ['form_params' => $form]);
        if ($code >= 400 || ($data['status'] ?? '') !== 'success') {
            throw new \RuntimeException(self::errorMessage($data, $raw, $code));
        }

        return is_array($data['template'] ?? null) ? $data['template'] : [];
    }

    /**
     * Edita una plantilla existente (vuelve a revisión de Meta). Meta permite
     * editar las aprobadas hasta 10 veces en 30 días y una vez cada 24 h, sin
     * cambiar la categoría.
     *
     * @param array<string,string> $form content, category, templateType, example, header, footer, buttons
     */
    public function editTemplate(string $templateId, array $form): void
    {
        $this->assertConfigured();
        [$code, $data, $raw] = $this->request('PUT', '/templates/' . rawurlencode($templateId), [
            'form_params' => $form + ['enableSample' => 'true'],
        ]);
        if ($code >= 400 || ($data['status'] ?? '') === 'error') {
            throw new \RuntimeException(self::errorMessage($data, $raw, $code));
        }
    }

    /**
     * Borra la plantilla en Meta (todas sus versiones de idioma). Irreversible;
     * Meta no deja reutilizar el nombre durante 30 días. No funciona con la
     * app en sandbox.
     */
    public function deleteRemoteTemplate(string $elementName): void
    {
        $this->assertConfigured();
        [$code, $data, $raw] = $this->request('DELETE', '/template/' . rawurlencode($elementName));
        if ($code >= 400 || ($data['status'] ?? '') === 'error') {
            throw new \RuntimeException(self::errorMessage($data, $raw, $code));
        }
    }

    /** @return array{healthy:bool,message:?string} */
    public function health(): array
    {
        $this->assertConfigured();
        [$code, $data, $raw] = $this->request('GET', '/health');
        $healthy = $code < 400 && in_array((string) ($data['healthy'] ?? ''), ['true', '1'], true);

        return ['healthy' => $healthy, 'message' => $healthy ? null : self::errorMessage($data, $raw, $code)];
    }

    /** @return array{quality:?string,limit:?string,message:?string} */
    public function ratings(): array
    {
        $this->assertConfigured();
        [$code, $data, $raw] = $this->request('GET', '/ratings');
        if ($code >= 400) {
            return ['quality' => null, 'limit' => null, 'message' => self::errorMessage($data, $raw, $code)];
        }

        return [
            'quality' => isset($data['phoneQuality']) ? (string) $data['phoneQuality'] : null,
            'limit'   => isset($data['currentLimit']) ? (string) $data['currentLimit'] : null,
            'message' => isset($data['message']) && !isset($data['phoneQuality']) ? (string) $data['message'] : null,
        ];
    }

    // ------------------------------------------------------ perfil de empresa --
    // Solo funciona con la app en vivo (número registrado). Endpoints:
    //   GET/PUT /business/profile        dirección, descripción, email, webs, categoría
    //   GET/PUT /business/profile/about  texto "Info" bajo el nombre
    //   GET/PUT /business/profile/photo  foto de perfil (multipart "image")

    /** Categorías que admite Meta para el perfil. */
    public const VERTICALS = [
        'OTHER', 'AUTO', 'BEAUTY', 'APPAREL', 'EDU', 'ENTERTAIN', 'EVENT_PLAN', 'FINANCE', 'GROCERY',
        'GOVT', 'HOTEL', 'HEALTH', 'NONPROFIT', 'PROF_SERVICES', 'RETAIL', 'TRAVEL', 'RESTAURANT',
    ];

    /**
     * @return array{address:?string,description:?string,email:?string,website1:?string,website2:?string,
     *               vertical:?string,about:?string,photoUrl:?string}
     */
    public function getProfile(): array
    {
        $this->assertConfigured();
        [$code, $data, $raw] = $this->request('GET', '/business/profile/');
        if ($code >= 400 || ($data['status'] ?? '') === 'error') {
            throw new \RuntimeException(self::errorMessage($data, $raw, $code));
        }
        $p = is_array($data['profile'] ?? null) ? $data['profile'] : [];
        $str = static fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        // "Info" y foto vienen de endpoints aparte; si fallan no se pierde el resto.
        $about = null;
        [$c2, $d2] = $this->request('GET', '/business/profile/about');
        if ($c2 < 400) {
            $about = $str($d2['about']['message'] ?? $d2['about'] ?? null);
        }
        $photo = null;
        [$c3, $d3] = $this->request('GET', '/business/profile/photo');
        if ($c3 < 400) {
            $url = $str($d3['message'] ?? $d3['url'] ?? null);
            $photo = $url !== null && preg_match('#^https://#i', $url) ? $url : null;
        }

        return [
            'address'     => $str($p['address'] ?? $p['addLine1'] ?? null),
            'description' => $str($p['desc'] ?? null),
            'email'       => $str($p['profileEmail'] ?? null),
            'website1'    => $str($p['website1'] ?? null),
            'website2'    => $str($p['website2'] ?? null),
            'vertical'    => $str($p['vertical'] ?? null),
            'about'       => $about,
            'photoUrl'    => $photo,
        ];
    }

    /** @param array<string,string> $fields claves de Gupshup: addLine1, desc, profileEmail, website1, website2, vertical */
    public function updateProfile(array $fields): void
    {
        $this->assertConfigured();
        [$code, $data, $raw] = $this->request('PUT', '/business/profile', ['form_params' => $fields]);
        if ($code >= 400 || ($data['status'] ?? '') === 'error') {
            throw new \RuntimeException(self::errorMessage($data, $raw, $code));
        }
    }

    public function updateAbout(string $about): void
    {
        $this->assertConfigured();
        [$code, $data, $raw] = $this->request('PUT', '/business/profile/about', ['form_params' => ['about' => $about]]);
        if ($code >= 400 || ($data['status'] ?? '') === 'error') {
            throw new \RuntimeException(self::errorMessage($data, $raw, $code));
        }
    }

    public function updatePhoto(string $path, string $filename): void
    {
        $this->assertConfigured();
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('No se pudo leer la imagen.');
        }
        [$code, $data, $raw] = $this->request('PUT', '/business/profile/photo', [
            'multipart' => [['name' => 'image', 'contents' => $bytes, 'filename' => $filename]],
        ]);
        if ($code >= 400 || ($data['status'] ?? '') === 'error') {
            throw new \RuntimeException(self::errorMessage($data, $raw, $code));
        }
    }

    // ------------------------------------------------------------- interno --

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'La línea de WhatsApp no está activada (faltan GUPSHUP_APP_ID / GUPSHUP_APP_TOKEN). '
                . 'Vozip la activa en el servidor con bin/whatsapp.php activate --name=<cliente>.'
            );
        }
    }

    /** @return array{0:int,1:array,2:string} */
    private function request(string $method, string $path, array $options = []): array
    {
        $url = rtrim((string) ($_ENV['GUPSHUP_BASE_URL'] ?? '') ?: self::BASE_URL, '/')
            . '/partner/app/' . rawurlencode(self::appId()) . $path;
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => self::appToken(), 'Accept' => 'application/json'];
        $options['http_errors'] = false;

        try {
            $res = $this->http()->request($method, $url, $options);
        } catch (\Throwable $e) {
            throw new \RuntimeException('No se pudo conectar con Gupshup: ' . $e->getMessage(), 0, $e);
        }
        $raw = (string) $res->getBody();
        $data = json_decode($raw, true);

        return [$res->getStatusCode(), is_array($data) ? $data : [], $raw];
    }

    private static function errorMessage(array $data, string $raw, int $code): string
    {
        $msg = $data['message'] ?? $data['error']['message'] ?? $data['error'] ?? null;
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        $msg = trim((string) ($msg ?? ''));
        if ($msg === '') {
            $msg = $raw !== '' ? mb_substr(strip_tags($raw), 0, 200) : 'sin respuesta';
        }
        if ($code === 401) {
            $msg .= ' (token de la app inválido: reactiva la línea con bin/whatsapp.php)';
        }

        return 'WhatsApp (HTTP ' . $code . '): ' . $msg;
    }

    private static function cleanParam(string $v): string
    {
        $v = trim(preg_replace('/[\t\r\n]+/', ' ', $v) ?? '');
        $v = preg_replace('/ {5,}/', '    ', $v) ?? $v;

        return $v === '' ? '-' : mb_substr($v, 0, 1024);
    }

    private function http(): Client
    {
        return $this->http ??= new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }
}
