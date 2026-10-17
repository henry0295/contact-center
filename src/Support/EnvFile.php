<?php

namespace App\Support;

/**
 * Edición segura del archivo .env preservando comentarios, orden y formato.
 * Se usa desde las utilidades de consola (generación de secretos).
 */
class EnvFile
{
    private string $path;
    /** @var string[] */
    private array $lines;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 2) . '/.env';

        if (!is_file($this->path)) {
            throw new \RuntimeException("No existe el archivo {$this->path}. Cópialo desde .env.example.");
        }

        $content = file_get_contents($this->path);
        if ($content === false) {
            throw new \RuntimeException("No se pudo leer {$this->path}.");
        }

        $this->lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    }

    public function get(string $key): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^' . preg_quote($key, '/') . '\s*=(.*)$/', $line, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /** Actualiza la clave si existe; si no, la añade al final. */
    public function set(string $key, string $value): void
    {
        $replacement = $key . '=' . $value;

        foreach ($this->lines as $i => $line) {
            if (preg_match('/^' . preg_quote($key, '/') . '\s*=/', $line)) {
                $this->lines[$i] = $replacement;

                return;
            }
        }

        if (end($this->lines) !== '') {
            $this->lines[] = '';
        }
        $this->lines[] = $replacement;
    }

    public function save(): void
    {
        // Copia de seguridad antes de sobrescribir, por si algo sale mal.
        @copy($this->path, $this->path . '.bak');

        if (file_put_contents($this->path, implode(PHP_EOL, $this->lines)) === false) {
            throw new \RuntimeException("No se pudo escribir {$this->path}.");
        }
    }

    public function path(): string
    {
        return $this->path;
    }
}
