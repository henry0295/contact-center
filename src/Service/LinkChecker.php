<?php

namespace App\Service;

use App\Database\Connection;
use PDO;

/**
 * Revisión de los links de un email antes de enviarlo.
 *
 * Solo analiza las URL (nunca las visita: abrirlas desde el servidor
 * permitiría escanear redes internas y haría lento el envío).
 *
 *  - error   (bloquea): esquemas peligrosos, links a una IP, usuario/clave en
 *                       la URL, texto visible que muestra otra dirección.
 *  - warning (avisa):   http sin cifrar, acortadores públicos, dominio ajeno al
 *                       remitente, dominios internacionales (posible imitación),
 *                       puertos raros, links relativos, demasiados links o
 *                       correo casi solo imagen.
 *
 * LINK_CHECK_MODE=warn convierte los errores en avisos. LINK_ALLOWED_DOMAINS y
 * LINK_SHORTENERS_EXTRA (separados por comas) ajustan las listas.
 */
class LinkChecker
{
    public const MAX_LINKS = 15;

    /** Acortadores públicos más usados en spam/phishing. */
    private const SHORTENERS = [
        'bit.ly', 'bitly.com', 'tinyurl.com', 'cutt.ly', 'is.gd', 'v.gd', 'ow.ly', 't.co', 'goo.gl', 'rebrand.ly',
        'shorturl.at', 'rb.gy', 'tiny.cc', 'buff.ly', 's.id', 't.ly', 'bl.ink', 'short.io', 'lnkd.in', 'shorte.st',
        'adf.ly', 'bit.do', 'clck.ru', 'qrco.de', 'urlz.fr', 'acortar.link', 'shre.ink', 'n9.cl', 'u.to', 'tr.ee',
    ];

    /** Variables que la app sustituye por links propios (baja). */
    private const SAFE_TOKENS = ['url_baja', 'unsubscribe_url', 'link_baja'];

    private const COMMON_TLDS = [
        'com', 'co', 'net', 'org', 'gov', 'edu', 'mil', 'int', 'io', 'info', 'biz', 'app', 'online', 'site', 'store',
        'shop', 'xyz', 'me', 'us', 'mx', 'es', 'ar', 'pe', 'cl', 'ec', 've', 'br', 'uy', 'py', 'bo', 'pa', 'cr', 'do',
        'gt', 'hn', 'sv', 'ni', 'cu', 'pr', 'uk', 'de', 'fr', 'it', 'pt', 'ca', 'eu', 'ai', 'tech', 'link', 'click',
    ];

    private const BLOCKED_SCHEMES = ['javascript', 'data', 'vbscript', 'file', 'blob', 'filesystem'];

    /** @var string[] dominios de confianza (remitente, verificados, APP_URL, configurados) */
    private array $trusted;
    private ?string $appHost;
    private bool $strict;
    /** @var string[] */
    private array $shorteners;

    /**
     * @param string[] $trustedDomains
     * @param string[] $extraShorteners
     */
    public function __construct(array $trustedDomains = [], ?string $appHost = null, bool $strict = true, array $extraShorteners = [])
    {
        $this->trusted = array_values(array_unique(array_filter(array_map([self::class, 'normalizeHost'], $trustedDomains))));
        $this->appHost = $appHost !== null && $appHost !== '' ? self::normalizeHost($appHost) : null;
        $this->strict = $strict;
        $this->shorteners = array_values(array_unique(array_merge(self::SHORTENERS, array_filter(array_map([self::class, 'normalizeHost'], $extraShorteners)))));
    }

    /** Construye el revisor con la configuración de esta instalación. */
    public static function fromEnvironment(?PDO $pdo = null): self
    {
        $env = static fn (string $k): string => trim((string) ($_ENV[$k] ?? ''), " \"'");
        $list = static fn (string $k): array => array_values(array_filter(array_map('trim', explode(',', $env($k)))));

        $trusted = $list('LINK_ALLOWED_DOMAINS');
        $mailFrom = $env('MAIL_FROM');
        if (str_contains($mailFrom, '@')) {
            $trusted[] = substr(strrchr($mailFrom, '@'), 1);
        }
        $appHost = parse_url($env('APP_URL'), PHP_URL_HOST) ?: null;
        if ($appHost) {
            $trusted[] = $appHost;
        }
        try {
            $pdo ??= Connection::get();
            foreach ($pdo->query("SELECT domain FROM domains WHERE verification_status = 'Success'")->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $trusted[] = (string) $d;
            }
            foreach ($pdo->query("SELECT email FROM senders WHERE verification_status = 'Success'")->fetchAll(PDO::FETCH_COLUMN) as $e) {
                if (str_contains((string) $e, '@')) {
                    $trusted[] = substr(strrchr((string) $e, '@'), 1);
                }
            }
        } catch (\Throwable) {
            // Sin BD se revisa igual, solo sin la lista de dominios verificados.
        }

        return new self($trusted, $appHost, strtolower($env('LINK_CHECK_MODE')) !== 'warn', $list('LINK_SHORTENERS_EXTRA'));
    }

    /**
     * @return array{links:array<int,array{url:string,text:?string,status:string}>,
     *               errors:array<int,array{url:string,text:?string,rule:string,message:string}>,
     *               warnings:array<int,array{url:string,text:?string,rule:string,message:string}>}
     */
    public function check(string $html): array
    {
        [$anchors, $plain, $textLength, $hasImages] = $this->extract($html);

        $links = [];
        $errors = [];
        $warnings = [];
        $seen = [];
        $count = 0;

        foreach (array_merge($anchors, array_map(static fn ($u) => ['url' => $u, 'text' => null], $plain)) as $a) {
            $url = trim(html_entity_decode((string) $a['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = $a['text'] !== null ? trim(preg_replace('/\s+/u', ' ', (string) $a['text']) ?? '') : null;
            if ($url === '' || str_starts_with($url, '#')) {
                continue;
            }
            $key = $url . "\0" . $text;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $issues = $this->checkOne($url, $text);
            if ($issues === null) {
                continue; // mailto:, tel:, variables de la app...
            }
            $count++;
            $status = 'ok';
            foreach ($issues as $i) {
                $issue = ['url' => mb_substr($url, 0, 300), 'text' => $text !== null ? mb_substr($text, 0, 120) : null] + $i;
                if ($i['severity'] === 'error' && $this->strict) {
                    $errors[] = self::strip($issue);
                    $status = 'error';
                } else {
                    $warnings[] = self::strip($issue);
                    $status = $status === 'error' ? 'error' : 'warning';
                }
            }
            $links[] = ['url' => mb_substr($url, 0, 300), 'text' => $text !== null ? mb_substr($text, 0, 120) : null, 'status' => $status];
        }

        if ($count > self::MAX_LINKS) {
            $warnings[] = ['url' => '', 'text' => null, 'rule' => 'too_many', 'message' => "El correo tiene {$count} links: más de " . self::MAX_LINKS . ' suele marcarse como spam.'];
        }
        if ($count > 0 && $hasImages && $textLength < 80) {
            $warnings[] = ['url' => '', 'text' => null, 'rule' => 'image_only', 'message' => 'El correo es casi solo imágenes con links: añade texto, los filtros de spam penalizan este formato.'];
        }

        return ['links' => $links, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** true si el HTML tiene links con variables (p.ej. href="{{link_pago}}") que dependen de cada fila. */
    public static function hasVariableLinks(string $html): bool
    {
        return (bool) preg_match('/href\s*=\s*["\']?[^"\'>]*\{\{/i', $html);
    }

    // ------------------------------------------------------------ interno --

    /** @return array<int,array{rule:string,message:string,severity:string}>|null null = no es un link a revisar */
    private function checkOne(string $url, ?string $text): ?array
    {
        // Variable sin resolver: la de baja es de la app; las demás se revisan por fila.
        if (preg_match('/^\{\{\s*([a-z0-9_]+)\s*\}\}$/i', $url, $m)) {
            return null;
        }
        if (preg_match('/\{\{\s*(' . implode('|', self::SAFE_TOKENS) . ')\s*\}\}/i', $url)) {
            return null;
        }

        // Caracteres de control o espacios ocultos que algunos usan para disfrazar URLs.
        $clean = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? $url;
        $scheme = strtolower((string) (parse_url($clean, PHP_URL_SCHEME) ?? ''));
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $clean, $sm)) {
            $scheme = strtolower($sm[1]);
        }

        if (in_array($scheme, ['mailto', 'tel', 'sms'], true)) {
            return null;
        }
        if (in_array($scheme, self::BLOCKED_SCHEMES, true)) {
            return [self::issue('dangerous_scheme', "Tipo de link no permitido ({$scheme}:). Puede ejecutar código o descargar archivos.", 'error')];
        }

        $issues = [];
        if ($scheme === '' && preg_match('/^www\./i', $clean)) {
            $clean = 'http://' . $clean;
            $scheme = 'http';
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            if ($scheme === '') {
                if (str_contains($clean, '{{')) {
                    return null; // se revisa por fila
                }

                return [self::issue('relative', 'Link incompleto o relativo: en un correo no funcionará. Usa la dirección completa con https://.', 'warning')];
            }

            return [self::issue('unusual_scheme', "Tipo de link poco habitual ({$scheme}:).", 'warning')];
        }

        $parts = parse_url($clean);
        $host = isset($parts['host']) ? self::normalizeHost($parts['host']) : '';
        if ($host === '') {
            return [self::issue('malformed', 'La dirección del link no es válida.', 'error')];
        }
        $isApp = $this->appHost !== null && $host === $this->appHost;

        if (isset($parts['user']) || isset($parts['pass'])) {
            $issues[] = self::issue('userinfo', 'El link incluye usuario o "@" antes del dominio: es una técnica de suplantación. El destino real es ' . $host . '.', 'error');
        }
        if (!$isApp && self::isIp($host)) {
            $issues[] = self::issue('ip_address', 'El link apunta a una dirección IP en vez de a un dominio: es una señal típica de phishing.', 'error');
        }

        // Texto visible con aspecto de dirección que no coincide con el destino.
        if ($text !== null && $text !== '') {
            $shown = self::hostInText($text);
            if ($shown !== null && !self::sameSite($shown, $host)) {
                $issues[] = self::issue('deceptive_text', "El texto muestra «{$shown}» pero el link lleva a «{$host}». Los filtros lo bloquean como phishing.", 'error');
            }
        }

        if ($scheme === 'http' && !$isApp) {
            $issues[] = self::issue('insecure', 'El link no usa https: Gmail y Outlook lo marcan como inseguro.', 'warning');
        }
        if ($this->isShortener($host)) {
            $issues[] = self::issue('shortener', "Acortador público ({$host}): muy asociado a spam. Usa el link completo o un acortador con tu dominio.", 'warning');
        }
        if (str_contains($host, 'xn--') || preg_match('/[^\x20-\x7E]/', $host)) {
            $issues[] = self::issue('idn', "El dominio usa caracteres internacionales ({$host}): puede imitar a otro dominio.", 'warning');
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            $issues[] = self::issue('port', 'El link usa un puerto poco habitual (:' . (int) $parts['port'] . ').', 'warning');
        }
        if (!empty($this->trusted) && !self::isIp($host) && !$this->isShortener($host) && !$this->isTrusted($host)) {
            $issues[] = self::issue('foreign_domain', "Dominio distinto al del remitente ({$host}). Si es tuyo, agrégalo a LINK_ALLOWED_DOMAINS.", 'warning');
        }

        return $issues;
    }

    /**
     * @return array{0:array<int,array{url:string,text:?string}>,1:string[],2:int,3:bool}
     *         anchors, URLs en texto plano, longitud del texto visible, tiene imágenes
     */
    private function extract(string $html): array
    {
        if (!preg_match('/<[a-z!\/]/i', $html)) {
            return [[], self::urlsInText($html), mb_strlen(trim($html)), false];
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $anchors = [];
        foreach (['a', 'area'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $el) {
                /** @var \DOMElement $el */
                if ($el->hasAttribute('href')) {
                    $anchors[] = ['url' => $el->getAttribute('href'), 'text' => $tag === 'a' ? $el->textContent : null];
                }
            }
        }
        $hasImages = $doc->getElementsByTagName('img')->length > 0;

        // Texto visible fuera de los <a>, para URLs escritas sin enlace.
        foreach (iterator_to_array($doc->getElementsByTagName('a')) as $a) {
            $a->parentNode?->removeChild($a);
        }
        foreach (['style', 'script', 'head', 'title'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) {
                $el->parentNode?->removeChild($el);
            }
        }
        $text = (string) $doc->textContent;

        return [$anchors, self::urlsInText($text), mb_strlen(trim(preg_replace('/\s+/u', ' ', $text) ?? '')), $hasImages];
    }

    /** @return string[] */
    private static function urlsInText(string $text): array
    {
        preg_match_all('#\b(?:https?://|www\.)[^\s<>"\'()]+#iu', $text, $m);

        return array_map(static fn ($u) => rtrim($u, '.,;:!?'), $m[0]);
    }

    /** Dominio que aparenta el texto visible ("https://banco.com", "www.banco.com", "banco.com/pagos"). */
    private static function hostInText(string $text): ?string
    {
        $t = trim($text);
        if (mb_strlen($t) > 200) {
            return null;
        }
        if (!preg_match('#^(https?://|www\.)?((?:www\.)?[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.([a-z]{2,24}))(?::\d+)?(?:[/?\#]\S*)?$#iu', $t, $m)) {
            return null;
        }
        // Sin "https://" ni "www." solo cuenta si termina en un dominio habitual:
        // así "factura.pdf" o "reporte.xlsx" no se toman por direcciones.
        if (($m[1] ?? '') === '' && !in_array(strtolower($m[3]), self::COMMON_TLDS, true)) {
            return null;
        }

        return self::normalizeHost($m[2]);
    }

    private static function sameSite(string $a, string $b): bool
    {
        return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
    }

    private function isTrusted(string $host): bool
    {
        foreach ($this->trusted as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }

        return false;
    }

    private function isShortener(string $host): bool
    {
        foreach ($this->shorteners as $s) {
            if ($host === $s || str_ends_with($host, '.' . $s)) {
                return true;
            }
        }

        return false;
    }

    private static function isIp(string $host): bool
    {
        $h = trim($host, '[]');
        if (filter_var($h, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Formas ofuscadas: decimal (3232235777), hexadecimal (0xC0A80101), octal (0300.0250...).
        return (bool) preg_match('/^(?:\d{8,10}|0x[0-9a-f]+|(?:0[0-7]*\.){1,3}0?[0-7]*|(?:\d+\.){1,2}\d+)$/i', $h);
    }

    public static function normalizeHost(string $host): string
    {
        $h = strtolower(trim($host, " \t\n\r\0\x0B.[]"));
        $h = (string) preg_replace('/^www\./', '', $h);

        return $h;
    }

    private static function issue(string $rule, string $message, string $severity): array
    {
        return ['rule' => $rule, 'message' => $message, 'severity' => $severity];
    }

    private static function strip(array $issue): array
    {
        unset($issue['severity']);

        return $issue;
    }
}
