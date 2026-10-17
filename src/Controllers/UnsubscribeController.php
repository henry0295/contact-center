<?php

namespace App\Controllers;

use App\Repositories\ContactRepository;
use App\Repositories\SuppressionRepository;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Página pública de baja. Es el destino de {{url_baja}}.
 *
 * Es pública a propósito (el destinatario no tiene cuenta en la aplicación) y
 * su seguridad descansa en el token: 32 caracteres aleatorios por contacto, sin
 * exponer el identificador interno ni permitir enumerar contactos.
 *
 * Devuelve HTML, no JSON: lo abre una persona desde su cliente de correo.
 */
class UnsubscribeController
{
    private ContactRepository $contacts;
    private SuppressionRepository $suppressions;

    public function __construct(
        ?ContactRepository $contacts = null,
        ?SuppressionRepository $suppressions = null
    ) {
        $this->contacts = $contacts ?? new ContactRepository();
        $this->suppressions = $suppressions ?? new SuppressionRepository();
    }

    public function unsubscribe(Request $request, Response $response, array $args): Response
    {
        $token = preg_replace('/[^a-f0-9]/i', '', (string) ($args['token'] ?? '')) ?? '';

        if (strlen($token) !== 32) {
            return $this->html($response, 'Enlace no válido', 'Este enlace de baja no es correcto. '
                . 'Comprueba que lo copiaste completo.', false, 400);
        }

        $contact = $this->contacts->unsubscribeByToken($token);

        if ($contact === null) {
            return $this->html($response, 'Enlace no válido', 'No encontramos este enlace de baja. '
                . 'Puede que ya se haya eliminado el contacto.', false, 404);
        }

        // Además de marcar la baja, se añade a supresión para que ningún envío
        // futuro (ni siquiera uno con la dirección escrita a mano) le llegue.
        if (!empty($contact['email'])) {
            try {
                $this->suppressions->add((string) $contact['email'], 'unsubscribe', 'Baja solicitada por el destinatario');
            } catch (\Throwable $e) {
                Logger::app()->warning('No se pudo añadir a supresión tras la baja', [
                    'contactId' => $contact['id'] ?? null,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Logger::app()->info('Baja de contacto', ['contactId' => $contact['id'] ?? null]);

        return $this->html(
            $response,
            'Baja confirmada',
            'Hemos dado de baja a <strong>' . htmlspecialchars(
                (string) ($contact['email'] ?: $contact['phone'] ?: 'este contacto'),
                ENT_QUOTES,
                'UTF-8'
            ) . '</strong>. No volverás a recibir nuestros mensajes.',
            true
        );
    }

    private function html(
        Response $response,
        string $title,
        string $message,
        bool $ok,
        int $status = 200
    ): Response {
        $accent = $ok ? '#16a34a' : '#dc2626';
        $icon = $ok ? '&#10003;' : '&#33;';
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $page = <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$safeTitle}</title>
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:#f8fafc; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; color:#0f172a; }
  .card { background:#fff; max-width:28rem; width:calc(100% - 2rem); padding:2rem; border-radius:0.75rem;
          box-shadow:0 10px 25px rgba(15,23,42,.08); text-align:center; }
  .badge { width:3rem; height:3rem; border-radius:999px; display:flex; align-items:center; justify-content:center;
           margin:0 auto 1rem; font-size:1.5rem; color:#fff; background:{$accent}; }
  h1 { font-size:1.25rem; margin:0 0 .5rem; }
  p { margin:0; line-height:1.6; color:#475569; }
</style>
</head>
<body>
  <main class="card">
    <div class="badge" aria-hidden="true">{$icon}</div>
    <h1>{$safeTitle}</h1>
    <p>{$message}</p>
  </main>
</body>
</html>
HTML;

        $response->getBody()->write($page);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }
}
