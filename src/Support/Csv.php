<?php

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * CSV para Excel en español: separador ";", UTF-8 con BOM y protección contra
 * inyección de fórmulas (celdas que empiezan por = + - @ tab o CR).
 */
final class Csv
{
    /**
     * @param string[] $header
     * @param iterable<array<int,scalar|null>> $rows
     */
    public static function response(Response $response, string $filename, array $header, iterable $rows): Response
    {
        $fh = fopen('php://temp', 'w+b');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_map([self::class, 'cell'], $row), ';', '"', '');
        }
        rewind($fh);
        $response->getBody()->write((string) stream_get_contents($fh));
        fclose($fh);

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'reporte.csv';

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'Sí' : 'No';
        }
        if (is_float($v)) {
            // Decimales con coma, como espera Excel en es-CO.
            return str_replace('.', ',', (string) round($v, 2));
        }
        $s = (string) $v;
        if ($s !== '' && !is_numeric($s) && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $s = "'" . $s;
        }

        return $s;
    }
}
