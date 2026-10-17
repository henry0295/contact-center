<?php

namespace App\Support;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Validación y materialización de archivos adjuntos para los envíos por SES.
 *
 * Amazon SES acepta adjuntos solo a través de SendRawEmail (mensaje MIME) y
 * limita el tamaño total del mensaje. Como base64 infla el contenido ~37%, el
 * límite de bytes en crudo se mantiene conservador para no pasarse.
 */
class Attachments
{
    /** Tamaño máximo total de adjuntos en crudo (7 MB -> ~9.6 MB en base64). */
    public const MAX_TOTAL_BYTES = 7 * 1024 * 1024;

    /** Tamaño máximo por archivo. */
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;

    public const MAX_FILES = 10;

    /**
     * Extensiones rechazadas: SES no admite ejecutables, y bloquearlos evita
     * convertir la aplicación en un vector de distribución de malware.
     */
    private const BLOCKED_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'cpl', 'dll', 'scr', 'msi', 'msp', 'jar',
        'vbs', 'vbe', 'js', 'jse', 'ws', 'wsf', 'wsc', 'wsh', 'ps1', 'ps1xml',
        'psc1', 'msh', 'msh1', 'scf', 'lnk', 'inf', 'reg', 'app', 'hta', 'pif',
    ];

    /**
     * Convierte los archivos subidos en especificaciones utilizables por
     * PHPMailer: [['path' => rutaTemporal, 'name' => nombreOriginal], ...]
     *
     * @param  array<int,UploadedFileInterface>|UploadedFileInterface|null $files
     * @return array<int,array{path:string,name:string}>
     * @throws \RuntimeException si algún archivo no cumple las validaciones
     */
    public static function fromUploads($files): array
    {
        if ($files === null) {
            return [];
        }

        if ($files instanceof UploadedFileInterface) {
            $files = [$files];
        }

        $files = array_values(array_filter(
            (array) $files,
            static fn ($f): bool => $f instanceof UploadedFileInterface
        ));

        if (count($files) > self::MAX_FILES) {
            throw new \RuntimeException('Máximo ' . self::MAX_FILES . ' archivos adjuntos por envío.');
        }

        $specs = [];
        $total = 0;

        try {
            return self::materialize($files, $specs, $total);
        } catch (\Throwable $e) {
            // Si falla a mitad, se eliminan los temporales ya creados.
            self::cleanup($specs);
            throw $e;
        }
    }

    /**
     * @param  array<int,UploadedFileInterface> $files
     * @param  array<int,array{path:string,name:string}> $specs
     * @return array<int,array{path:string,name:string}>
     */
    private static function materialize(array $files, array &$specs, int &$total): array
    {
        foreach ($files as $file) {
            /** @var UploadedFileInterface $file */
            if ($file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Error al subir "' . $file->getClientFilename() . '".');
            }

            $name = self::sanitizeFilename((string) $file->getClientFilename());
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($ext !== '' && in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                throw new \RuntimeException('El tipo de archivo ".' . $ext . '" no está permitido en adjuntos.');
            }

            $size = (int) $file->getSize();
            if ($size > self::MAX_FILE_BYTES) {
                throw new \RuntimeException(
                    'El archivo "' . $name . '" supera el límite de ' . self::humanSize(self::MAX_FILE_BYTES) . '.'
                );
            }

            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException(
                    'Los adjuntos superan el total permitido de ' . self::humanSize(self::MAX_TOTAL_BYTES) . '.'
                );
            }

            $tmp = tempnam(sys_get_temp_dir(), 'ccatt_');
            if ($tmp === false) {
                throw new \RuntimeException('No se pudo preparar el archivo adjunto en el servidor.');
            }
            $file->moveTo($tmp);

            $specs[] = ['path' => $tmp, 'name' => $name];
        }

        return $specs;
    }

    /** Carpeta de adjuntos persistidos de una campaña. */
    public static function storageDir(int $campaignId): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'attachments'
            . DIRECTORY_SEPARATOR . $campaignId;
    }

    /**
     * Mueve los adjuntos temporales a una carpeta propia de la campaña.
     *
     * Hace falta porque los envíos programados o encolados se procesan en otro
     * proceso (y más tarde): los temporales ya no existirían. El prefijo
     * numérico conserva el orden original y evita colisiones de nombre.
     *
     * @param array<int,array{path:string,name:string}> $specs
     * @return array<int,array{path:string,name:string}>
     */
    public static function persistForCampaign(array $specs, int $campaignId): array
    {
        if (empty($specs)) {
            return [];
        }

        $dir = self::storageDir($campaignId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo guardar los adjuntos de la campaña en el servidor.');
        }

        $out = [];
        foreach (array_values($specs) as $i => $spec) {
            if (empty($spec['path']) || !is_file($spec['path'])) {
                continue;
            }
            $name = self::sanitizeFilename((string) ($spec['name'] ?? 'adjunto'));
            $target = $dir . DIRECTORY_SEPARATOR . sprintf('%02d_%s', $i, $name);

            if (!@rename($spec['path'], $target)) {
                // rename falla entre volúmenes distintos: copiar y borrar.
                if (!@copy($spec['path'], $target)) {
                    throw new \RuntimeException('No se pudo guardar el adjunto "' . $name . '".');
                }
                @unlink($spec['path']);
            }

            $out[] = ['path' => $target, 'name' => $name];
        }

        return $out;
    }

    /**
     * Adjuntos guardados de una campaña, en el orden original.
     * @return array<int,array{path:string,name:string}>
     */
    public static function forCampaign(int $campaignId): array
    {
        $dir = self::storageDir($campaignId);
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        sort($files, SORT_STRING);

        $out = [];
        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }
            // Se quita el prefijo "NN_" que solo servía para ordenar.
            $name = preg_replace('/^\d{2}_/', '', basename($path)) ?? basename($path);
            $out[] = ['path' => $path, 'name' => $name];
        }

        return $out;
    }

    /** Borra la carpeta de adjuntos de una campaña (al eliminarla). */
    public static function purgeCampaign(int $campaignId): void
    {
        $dir = self::storageDir($campaignId);
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** Borra los temporales creados por fromUploads(). */
    public static function cleanup(array $specs): void
    {
        foreach ($specs as $spec) {
            if (!empty($spec['path']) && is_file($spec['path'])) {
                @unlink($spec['path']);
            }
        }
    }

    /** Evita rutas y caracteres problemáticos en el nombre mostrado al destinatario. */
    public static function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w\s.\-()\[\]]+/u', '_', $name) ?? 'adjunto';
        $name = trim($name);

        return $name === '' ? 'adjunto' : $name;
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / 1024) . ' KB';
    }
}
