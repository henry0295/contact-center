<?php

namespace App\Service;

use App\Repositories\WhatsAppRepository;
use App\Support\Logger;

/**
 * Mantiene al día el estado de las plantillas de WhatsApp (en revisión,
 * aprobada, rechazada...) contra Meta, a través de Gupshup.
 *
 * Meta solo avisa de un cambio de estado por el webhook
 * message_template_status_update, que necesita un dominio https público. Para
 * que el estado se actualice igual sin webhook, el dispatcher llama a
 * syncIfDue() en cada pasada: consulta a Meta cada 5 minutos mientras haya
 * alguna plantilla en revisión y una vez por hora el resto del tiempo, para
 * detectar también las que Meta pausa o desactiva después de aprobarlas.
 */
class WaTemplateSyncService
{
    /** Cada cuánto se consulta a Meta si hay plantillas en revisión. */
    private const INTERVAL_PENDING = 300;

    /** Cada cuánto se reconcilia cuando ninguna está en revisión. */
    private const INTERVAL_IDLE = 3600;

    /** Estados que todavía pueden cambiar por decisión de Meta. */
    private const NOT_FINAL = ['PENDING', 'IN_APPEAL', 'PENDING_DELETION'];

    /** Equivalencias: Gupshup y Meta usan varios nombres para lo mismo. */
    private const SYNONYMS = [
        'ACTIVE'                 => 'APPROVED',
        'ENABLED'                => 'APPROVED',
        'ACTIVE_QUALITY_PENDING' => 'APPROVED',
        'FLAGGED'                => 'PAUSED',
        'PENDING_APPROVAL'       => 'PENDING',
        'SUBMITTED'              => 'PENDING',
    ];

    private WhatsAppService $wa;
    private WhatsAppRepository $repo;

    public function __construct(?WhatsAppService $wa = null, ?WhatsAppRepository $repo = null)
    {
        $this->wa = $wa ?? new WhatsAppService();
        $this->repo = $repo ?? new WhatsAppRepository();
    }

    public function isConfigured(): bool
    {
        return $this->wa->isConfigured();
    }

    /** Marca de tiempo de la última consulta a Meta, o null si nunca se hizo. */
    public function lastSyncAt(): ?int
    {
        $at = (int) (json_decode((string) @file_get_contents($this->stampPath()), true)['at'] ?? 0);

        return $at > 0 ? $at : null;
    }

    /**
     * Sincroniza solo si toca según el intervalo. Devuelve null si no tocaba.
     *
     * @return array{synced:int,changes:array<int,array<string,?string>>,at:int}|null
     */
    public function syncIfDue(): ?array
    {
        $last = $this->lastSyncAt();
        if ($last !== null) {
            $pending = $this->repo->countByStatus(self::NOT_FINAL) > 0;
            if (time() - $last < ($pending ? self::INTERVAL_PENDING : self::INTERVAL_IDLE)) {
                return null;
            }
        }

        return $this->sync();
    }

    /**
     * Trae de Meta todas las plantillas y su estado, y devuelve los cambios
     * detectados respecto a lo que había en la base.
     *
     * @return array{synced:int,changes:array<int,array<string,?string>>,at:int}
     */
    public function sync(): array
    {
        $remote = $this->wa->listTemplates();

        $before = [];
        foreach ($this->repo->templates() as $row) {
            $before[self::key((string) $row['element_name'], (string) $row['language'])] = (string) $row['status'];
        }

        $synced = 0;
        $changes = [];
        foreach ($remote as $t) {
            if (!is_array($t)) {
                continue;
            }
            $meta = json_decode((string) ($t['containerMeta'] ?? ''), true) ?: [];
            $name = trim((string) ($t['elementName'] ?? ''));
            $body = (string) ($meta['data'] ?? $t['data'] ?? '');
            if ($name === '' || $body === '') {
                continue;
            }
            $language = (string) ($t['languageCode'] ?? 'es');
            $status = self::normalizeStatus((string) ($t['status'] ?? 'PENDING'));
            $reason = self::reasonOf($t, $meta);

            $this->repo->upsertTemplate([
                'external_id'   => (string) ($t['id'] ?? ''),
                'element_name'  => $name,
                'language'      => $language,
                'category'      => strtoupper((string) ($t['category'] ?? 'MARKETING')),
                'template_type' => strtoupper((string) ($t['templateType'] ?? 'TEXT')),
                'header'        => isset($meta['header']) ? mb_substr((string) $meta['header'], 0, 60) : null,
                'body'          => $body,
                'footer'        => isset($meta['footer']) ? mb_substr((string) $meta['footer'], 0, 60) : null,
                'buttons_json'  => isset($meta['buttons']) ? json_encode($meta['buttons'], JSON_UNESCAPED_UNICODE) : null,
                'example'       => isset($meta['sampleText']) ? (string) $meta['sampleText'] : null,
                'status'        => $status,
                'reason'        => $reason,
            ]);
            $synced++;

            $prev = $before[self::key($name, $language)] ?? null;
            if ($prev !== null && $prev !== $status) {
                $changes[] = ['name' => $name, 'language' => $language, 'from' => $prev, 'to' => $status, 'reason' => $reason];
            }
        }

        $at = time();
        @file_put_contents($this->stampPath(), json_encode(['at' => $at, 'synced' => $synced]));

        foreach ($changes as $c) {
            $context = ['plantilla' => $c['name'], 'idioma' => $c['language'], 'antes' => $c['from'], 'ahora' => $c['to'], 'motivo' => $c['reason']];
            if (in_array($c['to'], ['REJECTED', 'PAUSED', 'DISABLED', 'FAILED', 'LIMIT_EXCEEDED'], true)) {
                Logger::app()->warning('Meta cambió el estado de una plantilla de WhatsApp', $context);
            } else {
                Logger::app()->info('Nuevo estado de una plantilla de WhatsApp', $context);
            }
        }

        return ['synced' => $synced, 'changes' => $changes, 'at' => $at];
    }

    private static function key(string $name, string $language): string
    {
        return mb_strtolower($name . '|' . $language);
    }

    /**
     * Normaliza el estado y resuelve los sinónimos de Gupshup y Meta. También
     * la usa el webhook, que recibe el cambio como "event".
     */
    public static function normalizeStatus(string $raw): string
    {
        $s = strtoupper(trim($raw));
        $s = preg_replace('/[^A-Z0-9]+/', '_', $s) ?? $s;
        $s = trim($s, '_');
        if ($s === '') {
            return 'PENDING';
        }

        return mb_substr(self::SYNONYMS[$s] ?? $s, 0, 20);
    }

    /**
     * Motivo del rechazo o de la pausa. Gupshup lo pone en distintas claves
     * según el caso, así que se prueban todas.
     *
     * @param array<string,mixed> $t
     * @param array<string,mixed> $meta
     */
    private static function reasonOf(array $t, array $meta): ?string
    {
        foreach ([$t['reason'] ?? null, $t['rejectedReason'] ?? null, $t['retryReason'] ?? null, $meta['reason'] ?? null] as $r) {
            if (is_scalar($r)) {
                $r = trim((string) $r);
                // Gupshup devuelve "NONE" cuando no hay motivo.
                if ($r !== '' && strcasecmp($r, 'NONE') !== 0 && strcasecmp($r, 'null') !== 0) {
                    return mb_substr($r, 0, 500);
                }
            }
        }

        return null;
    }

    private function stampPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/whatsapp-templates-sync.json';
    }
}
