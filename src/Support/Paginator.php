<?php

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Normaliza los parámetros de paginación de la petición.
 *
 * Sin paginación, los listados devolvían la tabla completa: con decenas de
 * miles de contactos eso agota la memoria de PHP y bloquea el navegador.
 */
class Paginator
{
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 100;

    public int $page;
    public int $perPage;
    public int $offset;

    public function __construct(int $page, int $perPage)
    {
        $this->page = max(1, $page);
        $this->perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        $this->offset = ($this->page - 1) * $this->perPage;
    }

    public static function fromRequest(Request $request): self
    {
        $q = $request->getQueryParams();

        return new self(
            (int) ($q['page'] ?? 1),
            (int) ($q['perPage'] ?? self::DEFAULT_PER_PAGE)
        );
    }

    /** Metadatos para que el cliente pinte los controles de paginación. */
    public function meta(int $total): array
    {
        $totalPages = $this->perPage > 0 ? (int) ceil($total / $this->perPage) : 1;

        return [
            'page'       => $this->page,
            'perPage'    => $this->perPage,
            'total'      => $total,
            'totalPages' => max(1, $totalPages),
            'hasMore'    => $this->page < $totalPages,
        ];
    }
}
