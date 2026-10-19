<?php

namespace App\Controllers;

use App\Repositories\SmsReplyRepository;
use App\Service\SmsService;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * SMS de doble vía.
 *
 * inbound(): lo llama el PROVEEDOR (IT Cloud) cuando un destinatario responde:
 *   GET /api/sms/inbound/<SMS_INBOUND_SECRET>?mensaje=...&celular=...
 *   Es público (el proveedor no tiene sesión). Lo protege el secreto
 *   aleatorio de la URL, que solo conocen el servidor y el proveedor.
 *
 * list(): listado para el módulo SMS (con sesión).
 */
class SmsReplyController
{
    use JsonResponseTrait;

    /** Palabras con las que el destinatario pide no recibir más mensajes. */
    private const OPT_OUT_WORDS = ['salir', 'exit', 'remove', 'stop', 'baja', 'cancelar'];

    private SmsReplyRepository $replies;

    public function __construct(?SmsReplyRepository $replies = null)
    {
        $this->replies = $replies ?? new SmsReplyRepository();
    }

    public function inbound(Request $request, Response $response, array $args): Response
    {
        $expected = trim((string) ($_ENV['SMS_INBOUND_SECRET'] ?? ''));
        $given = (string) ($args['secret'] ?? '');
        if (strlen($expected) < 16 || !hash_equals($expected, $given)) {
            Logger::app()->warning('Respuesta SMS rechazada: secreto inválido', ['ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null]);

            return $this->text($response, 'Not found', 404);
        }

        // El proveedor envía por GET; se acepta también POST (form o JSON).
        $params = array_merge($request->getQueryParams(), (array) ($request->getParsedBody() ?? []));
        $message = trim((string) ($params['mensaje'] ?? $params['message'] ?? ''));
        $phone = SmsService::normalizePhone((string) ($params['celular'] ?? $params['cellphone'] ?? ''));

        if ($message === '' || strlen($phone) < 7 || strlen($phone) > 15) {
            return $this->text($response, 'Parametros invalidos', 400);
        }
        $message = mb_substr($message, 0, 2000);

        try {
            if ($this->replies->isDuplicate($phone, $message)) {
                return $this->text($response, 'OK');
            }

            $match = $this->replies->matchRecipient($phone);
            $normalized = mb_strtolower(trim($message, " \t\n\r.!¡¿?"));
            $optOut = in_array($normalized, self::OPT_OUT_WORDS, true);

            $this->replies->create($phone, $message, $match['campaign_id'] ?? null, $match['contact_id'] ?? null, $optOut);

            if ($optOut) {
                $n = $this->replies->unsubscribeByPhone($phone);
                Logger::audit()->info('Baja por respuesta SMS', ['phone' => $phone, 'contactos' => $n]);
            }

            Logger::app()->info('Respuesta SMS recibida', ['phone' => $phone, 'campaignId' => $match['campaign_id'] ?? null]);
        } catch (\Throwable $e) {
            Logger::app()->error('No se pudo guardar una respuesta SMS', ['error' => $e->getMessage()]);

            return $this->text($response, 'Error', 500);
        }

        return $this->text($response, 'OK');
    }

    public function list(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($q['perPage'] ?? 25)));
        $campaignId = !empty($q['campaignId']) ? (int) $q['campaignId'] : null;
        $search = mb_substr(trim((string) ($q['q'] ?? '')), 0, 100);

        $result = $this->replies->paginate($page, $perPage, $campaignId, $search);

        return $this->jsonResponse($response, [
            'replies' => $result['replies'],
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => $perPage,
        ]);
    }

    private function text(Response $response, string $body, int $status = 200): Response
    {
        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withStatus($status);
    }
}
