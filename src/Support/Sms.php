<?php

namespace App\Support;

/**
 * Utilidades de SMS: segmentación, campos personalizados y coste.
 *
 * Un SMS no se mide en caracteres sino en SEGMENTOS, y el límite depende del
 * alfabeto: con GSM-7 (texto latino básico) caben 160 caracteres, pero si el
 * mensaje lleva emojis o caracteres fuera de ese alfabeto, el móvil cambia a
 * UCS-2 y solo caben 70. Al concatenar varios segmentos se reservan unos
 * caracteres de cabecera, así que los límites bajan a 153 y 67.
 *
 * Esto importa porque los operadores cobran por segmento, no por mensaje.
 */
class Sms
{
    public const GSM_SINGLE = 160;
    public const GSM_MULTI = 153;
    public const UCS2_SINGLE = 70;
    public const UCS2_MULTI = 67;

    /** Alfabeto GSM 03.38 básico. */
    private const GSM_BASIC =
        // El \$ va escapado: en PHP un byte UTF-8 alto es parte válida de un
        // nombre de variable, así que "$¥..." se interpretaría como interpolación.
        "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Caracteres GSM que ocupan DOS posiciones (secuencia de escape). */
    private const GSM_EXTENDED = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];

    /**
     * Analiza el mensaje y devuelve su coste en segmentos.
     *
     * @return array{encoding:string, length:int, segments:int, perSegment:int, remaining:int}
     */
    public static function analyze(string $text): array
    {
        $isGsm = self::isGsm7($text);
        $length = $isGsm ? self::gsm7Length($text) : mb_strlen($text, 'UTF-8');

        if ($isGsm) {
            $single = self::GSM_SINGLE;
            $multi = self::GSM_MULTI;
        } else {
            $single = self::UCS2_SINGLE;
            $multi = self::UCS2_MULTI;
        }

        if ($length === 0) {
            $segments = 0;
            $perSegment = $single;
        } elseif ($length <= $single) {
            $segments = 1;
            $perSegment = $single;
        } else {
            $segments = (int) ceil($length / $multi);
            $perSegment = $multi;
        }

        $capacity = $segments <= 1 ? $single : $segments * $multi;

        return [
            'encoding'   => $isGsm ? 'GSM-7' : 'UCS-2',
            'length'     => $length,
            'segments'   => $segments,
            'perSegment' => $perSegment,
            'remaining'  => max(0, $capacity - $length),
        ];
    }

    public static function segments(string $text): int
    {
        return self::analyze($text)['segments'];
    }

    /** ¿Cabe todo el texto en el alfabeto GSM-7? */
    public static function isGsm7(string $text): bool
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chars as $char) {
            if (in_array($char, self::GSM_EXTENDED, true)) {
                continue;
            }
            if (mb_strpos(self::GSM_BASIC, $char, 0, 'UTF-8') === false) {
                return false;
            }
        }

        return true;
    }

    /** Longitud contando doble los caracteres de la tabla extendida. */
    private static function gsm7Length(string $text): int
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $length = 0;

        foreach ($chars as $char) {
            $length += in_array($char, self::GSM_EXTENDED, true) ? 2 : 1;
        }

        return $length;
    }

    /**
     * Sustituye los campos personalizados del tipo {{nombre}} con los datos del
     * contacto, y además con las variables propias de este envío (p.ej.
     * {{valor}} en un recordatorio de pago, subidas en el CSV de destinatarios).
     * Los que no tengan valor se dejan vacíos para no enviar literales como
     * "{{nombre}}" al destinatario.
     *
     * `$vars` tiene prioridad sobre los campos del contacto: así una columna
     * del CSV llamada "nombre" sobrescribe el nombre guardado del contacto
     * para ESTE envío, sin modificar el contacto.
     *
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $vars Variables propias del envío (del CSV).
     */
    public static function renderFields(string $text, array $contact, array $vars = []): string
    {
        $values = [
            'nombre'   => $contact['name'] ?? '',
            'email'    => $contact['email'] ?? '',
            'telefono' => $contact['phone'] ?? '',
        ];

        if (!empty($contact['unsubscribe_url'])) {
            $values['url_baja'] = $contact['unsubscribe_url'];
        }

        foreach ($vars as $key => $value) {
            $values[strtolower((string) $key)] = $value;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/iu',
            static function (array $m) use ($values): string {
                $key = strtolower($m[1]);

                return isset($values[$key]) ? (string) $values[$key] : '';
            },
            $text
        );
    }

    /** Campos disponibles para mostrarlos en la interfaz. */
    public static function availableFields(): array
    {
        return [
            ['token' => '{{nombre}}',   'label' => 'Nombre del contacto'],
            ['token' => '{{email}}',    'label' => 'Correo del contacto'],
            ['token' => '{{telefono}}', 'label' => 'Teléfono del contacto'],
            ['token' => '{{url_baja}}', 'label' => 'URL de baja'],
        ];
    }

    /**
     * Resumen de volumen del envío (segmentos y destinatarios).
     *
     * Ya NO incluye importes: los precios son internos (tabla credit_prices,
     * ver CreditService) y el cliente no debe verlos. Se conservan las claves
     * de precio a 0 para no romper a los clientes de la API.
     */
    public static function estimateCost(int $segments, int $recipients): array
    {
        return [
            'segmentsPerMessage' => $segments,
            'recipients'         => $recipients,
            'totalSegments'      => $segments * $recipients,
            'pricePerSegment'    => 0.0,
            'currency'           => '',
            'total'              => 0.0,
            'configured'         => false,
        ];
    }

    /**
     * Valida el remitente alfanumérico. AWS SNS admite hasta 11 caracteres en
     * sender ID alfanumérico; se permite hasta 15 por si se usa un número
     * largo, pero se avisa de la restricción.
     */
    public static function validateSenderId(string $senderId): ?string
    {
        $senderId = trim($senderId);

        if ($senderId === '') {
            return null; // opcional: SNS usará el remitente por defecto de la cuenta
        }
        if (mb_strlen($senderId) > 15) {
            return 'El remitente no puede superar 15 caracteres.';
        }
        if (!preg_match('/^[A-Za-z0-9 +\-]+$/', $senderId)) {
            return 'El remitente solo admite letras, números, espacios, + y -.';
        }

        return null;
    }
}
