<?php

namespace App\Controllers;

use App\Repositories\ContactListRepository;
use App\Repositories\ContactRepository;
use App\Support\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

class ContactController
{
    use JsonResponseTrait;

    private ContactRepository $contacts;
    private ContactListRepository $lists;

    public function __construct(?ContactRepository $contacts = null, ?ContactListRepository $lists = null)
    {
        $this->contacts = $contacts ?? new ContactRepository();
        $this->lists = $lists ?? new ContactListRepository();
    }

    // ---- Contactos ----

    public function index(Request $request, Response $response): Response
    {
        $p = Paginator::fromRequest($request);
        $search = trim($request->getQueryParams()['search'] ?? '') ?: null;

        $result = $this->contacts->paginate($p->perPage, $p->offset, $search);

        return $this->jsonResponse($response, [
            'contacts'   => $result['items'],
            'pagination' => $p->meta($result['total']),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $name = trim($data['name'] ?? '') ?: null;
        $email = trim($data['email'] ?? '') ?: null;
        $phone = trim($data['phone'] ?? '') ?: null;

        if ($email === null && $phone === null) {
            return $this->jsonResponse($response, ['error' => 'Se requiere al menos email o teléfono'], 400);
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonResponse($response, ['error' => 'El email no es válido'], 400);
        }

        $id = $this->contacts->create($name, $email, $phone);

        return $this->jsonResponse($response, [
            'success' => true,
            'contact' => ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone],
        ], 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $deleted = $this->contacts->delete((int) ($args['id'] ?? 0));
        if (!$deleted) {
            return $this->jsonResponse($response, ['error' => 'Contacto no encontrado'], 404);
        }

        return $this->jsonResponse($response, ['success' => true]);
    }

    // ---- Listas ----

    public function indexLists(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, ['lists' => $this->lists->all()]);
    }

    public function createList(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '') ?: null;

        if ($name === '') {
            return $this->jsonResponse($response, ['error' => 'El nombre de la lista es obligatorio'], 400);
        }

        $id = $this->lists->create($name, $description);

        // Permite crear la lista ya con contactos: antes solo se podían añadir
        // importando un CSV, así que una lista hecha a mano quedaba vacía.
        $added = $this->attachContacts($id, $data['contactIds'] ?? []);

        return $this->jsonResponse($response, [
            'success' => true,
            'list'    => [
                'id'           => $id,
                'name'         => $name,
                'description'  => $description,
                'member_count' => $added,
            ],
        ], 201);
    }

    /** Añade contactos existentes a una lista. */
    public function addMembers(Request $request, Response $response, array $args): Response
    {
        $listId = (int) ($args['id'] ?? 0);
        if (!$this->lists->exists($listId)) {
            return $this->jsonResponse($response, ['error' => 'Lista no encontrada'], 404);
        }

        $data = $request->getParsedBody() ?? [];
        $ids = $data['contactIds'] ?? $data['contact_ids'] ?? [];

        if (empty($ids)) {
            return $this->jsonResponse($response, ['error' => 'Indica al menos un contacto.'], 400);
        }

        $added = $this->attachContacts($listId, $ids);

        return $this->jsonResponse($response, [
            'success'  => true,
            'added'    => $added,
            'contacts' => $this->lists->members($listId),
        ]);
    }

    /** Saca un contacto de una lista (no borra el contacto). */
    public function removeMember(Request $request, Response $response, array $args): Response
    {
        $listId = (int) ($args['id'] ?? 0);
        $contactId = (int) ($args['contactId'] ?? 0);

        if (!$this->lists->removeMember($listId, $contactId)) {
            return $this->jsonResponse($response, ['error' => 'El contacto no pertenece a esa lista'], 404);
        }

        return $this->jsonResponse($response, ['success' => true]);
    }

    /**
     * Vincula contactos a una lista ignorando los que no existen.
     * @param mixed $ids
     */
    private function attachContacts(int $listId, $ids): int
    {
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : explode(',', $ids);
        }

        $added = 0;
        foreach ((array) $ids as $raw) {
            $contactId = (int) $raw;
            if ($contactId <= 0 || $this->contacts->find($contactId) === null) {
                continue;
            }
            $this->lists->addMember($listId, $contactId);
            $added++;
        }

        return $added;
    }

    public function deleteList(Request $request, Response $response, array $args): Response
    {
        $deleted = $this->lists->delete((int) ($args['id'] ?? 0));
        if (!$deleted) {
            return $this->jsonResponse($response, ['error' => 'Lista no encontrada'], 404);
        }

        return $this->jsonResponse($response, ['success' => true]);
    }

    public function listMembers(Request $request, Response $response, array $args): Response
    {
        return $this->jsonResponse($response, ['contacts' => $this->lists->members((int) ($args['id'] ?? 0))]);
    }

    // ---- Importación CSV ----
    // multipart/form-data con 'file' (CSV con cabecera name,email,phone) y 'listId'.
    public function import(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();
        /** @var UploadedFileInterface|null $file */
        $file = $files['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonResponse($response, ['error' => 'No se recibió un archivo CSV válido'], 400);
        }
        if ($file->getSize() !== null && $file->getSize() > 5 * 1024 * 1024) {
            return $this->jsonResponse($response, ['error' => 'El archivo supera el límite de 5 MB'], 400);
        }

        $params = $request->getParsedBody() ?? [];
        $listId = !empty($params['listId']) ? (int) $params['listId'] : null;
        if ($listId !== null && !$this->lists->exists($listId)) {
            return $this->jsonResponse($response, ['error' => 'La lista indicada no existe'], 400);
        }

        $stream = $file->getStream()->detach();
        if (!is_resource($stream)) {
            return $this->jsonResponse($response, ['error' => 'No se pudo leer el archivo'], 400);
        }

        $imported = 0;
        $skipped = 0;
        $header = null;
        $seen = [];

        // Detecta el separador: Excel en configuración regional española exporta
        // con punto y coma en lugar de coma.
        $firstLine = fgets($stream);
        if ($firstLine === false) {
            fclose($stream);

            return $this->jsonResponse($response, ['error' => 'El archivo CSV está vacío'], 400);
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($stream);

        while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
            // Fila vacía (saltos de línea al final del archivo).
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }

            // Primera fila = cabecera (name,email,phone en cualquier orden).
            if ($header === null) {
                $header = array_map(static function ($h): string {
                    $h = (string) $h;
                    // Se quitan los BOM UTF-8 que añade Excel (se usa un bucle
                    // porque algunas herramientas escriben más de uno): sin esto
                    // la primera columna se llamaría "\xEF\xBB\xBFname" y la
                    // importación ignoraría ese campo silenciosamente.
                    while (str_starts_with($h, "\xEF\xBB\xBF")) {
                        $h = substr($h, 3);
                    }

                    return strtolower(trim($h, " \t\n\r\0\x0B\"'"));
                }, $row);
                continue;
            }

            $record = [];
            foreach ($header as $i => $col) {
                $record[$col] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            $name = ($record['name'] ?? '') ?: null;
            $email = ($record['email'] ?? '') ?: null;
            $phone = ($record['phone'] ?? '') ?: null;

            if (($email === null && $phone === null) || ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $skipped++;
                continue;
            }

            // Evita duplicados dentro del propio archivo y contra lo ya guardado:
            // antes, reimportar el mismo CSV creaba copias de cada contacto.
            $key = strtolower($email ?? $phone ?? '');
            if (isset($seen[$key])) {
                $skipped++;
                continue;
            }
            $seen[$key] = true;

            $existing = $this->contacts->findByEmailOrPhone($email, $phone);
            if ($existing !== null) {
                // Ya existe: no se duplica, pero sí se añade a la lista destino.
                if ($listId !== null) {
                    $this->lists->addMember($listId, (int) $existing['id']);
                }
                $skipped++;
                continue;
            }

            $contactId = $this->contacts->create($name, $email, $phone);
            if ($listId !== null) {
                $this->lists->addMember($listId, $contactId);
            }
            $imported++;
        }

        fclose($stream);

        return $this->jsonResponse($response, [
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
        ]);
    }
}
