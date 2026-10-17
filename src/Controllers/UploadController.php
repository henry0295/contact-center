<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Subida de imágenes para el editor de mensajes y de plantillas.
 *
 * Las imágenes se guardan en public/uploads y se referencian con una URL
 * absoluta (APP_URL). Es el enfoque correcto para email: los clientes de correo
 * suelen bloquear o descartar las imágenes incrustadas en base64, mientras que
 * una URL pública se carga con normalidad.
 */
class UploadController
{
    use JsonResponseTrait;

    private const MAX_BYTES = 3 * 1024 * 1024;

    /** Solo mapas de bits seguros para email. Se valida el contenido, no el nombre. */
    private const ALLOWED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    public function image(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();
        /** @var UploadedFileInterface|null $file */
        $file = $files['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonResponse($response, ['error' => 'No se recibió ninguna imagen'], 400);
        }
        if ((int) $file->getSize() > self::MAX_BYTES) {
            return $this->jsonResponse($response, ['error' => 'La imagen supera el límite de 3 MB'], 400);
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->jsonResponse($response, ['error' => 'No se pudo preparar la carpeta de subidas'], 500);
        }

        // Se guarda en un temporal para inspeccionar el contenido real antes de
        // publicarlo: así un .php renombrado a .jpg no llega al directorio web.
        $tmp = tempnam(sys_get_temp_dir(), 'ccimg_');
        if ($tmp === false) {
            return $this->jsonResponse($response, ['error' => 'No se pudo procesar la imagen'], 500);
        }

        try {
            $file->moveTo($tmp);

            $info = @getimagesize($tmp);
            $type = $info[2] ?? null;

            if ($type === null || !isset(self::ALLOWED[$type])) {
                return $this->jsonResponse($response, [
                    'error' => 'Formato no admitido. Usa JPG, PNG, GIF o WEBP.',
                ], 400);
            }

            $ext = self::ALLOWED[$type];
            $name = bin2hex(random_bytes(16)) . '.' . $ext;

            if (!rename($tmp, $dir . '/' . $name)) {
                return $this->jsonResponse($response, ['error' => 'No se pudo guardar la imagen'], 500);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'url'     => $this->publicUrl($name),
                'name'    => $name,
            ], 201);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function publicUrl(string $name): string
    {
        $base = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');

        return $base . '/uploads/' . $name;
    }
}
