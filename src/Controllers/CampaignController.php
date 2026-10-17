<?php

namespace App\Controllers;

use App\Repositories\CampaignRecipientRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\TemplateRepository;
use App\Service\CampaignService;
use App\Service\SendOrchestrator;
use App\Support\Attachments;
use App\Support\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Gestión de campañas. Ya no hay un módulo "Campañas" en la interfaz: las
 * campañas se crean desde los compositores de SMS y email, y se consultan en
 * Reportes. Este controlador cubre el listado, el detalle y las acciones sobre
 * una campaña ya creada.
 */
class CampaignController
{
    use JsonResponseTrait;

    private CampaignRepository $campaigns;
    private CampaignRecipientRepository $recipients;
    private TemplateRepository $templates;
    private CampaignService $service;
    private SendOrchestrator $orchestrator;

    public function __construct(
        ?CampaignRepository $campaigns = null,
        ?CampaignRecipientRepository $recipients = null,
        ?TemplateRepository $templates = null,
        ?CampaignService $service = null,
        ?SendOrchestrator $orchestrator = null
    ) {
        $this->campaigns = $campaigns ?? new CampaignRepository();
        $this->recipients = $recipients ?? new CampaignRecipientRepository();
        $this->templates = $templates ?? new TemplateRepository();
        $this->service = $service ?? new CampaignService();
        $this->orchestrator = $orchestrator ?? new SendOrchestrator();
    }

    public function index(Request $request, Response $response): Response
    {
        $p = Paginator::fromRequest($request);
        $q = $request->getQueryParams();
        $status = trim($q['status'] ?? '') ?: null;
        $channel = trim($q['channel'] ?? '') ?: null;
        $origin = trim($q['origin'] ?? '') ?: null;

        $result = $this->campaigns->paginate($p->perPage, $p->offset, $status, $channel, $origin);

        return $this->jsonResponse($response, [
            'campaigns'  => $result['items'],
            'pagination' => $p->meta($result['total']),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->campaigns->find((int) ($args['id'] ?? 0));
        if ($campaign === null) {
            return $this->jsonResponse($response, ['error' => 'Campaña no encontrada'], 404);
        }

        return $this->jsonResponse($response, [
            'campaign'    => $campaign,
            'recipients'  => $this->recipients->forCampaign((int) $campaign['id']),
            'attachments' => array_map(
                static fn (array $a): array => ['name' => $a['name']],
                Attachments::forCampaign((int) $campaign['id'])
            ),
        ]);
    }

    /**
     * Creación explícita de campaña (API). Delega en el mismo orquestador que
     * los compositores para que haya un único camino de envío.
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $subject = trim((string) ($data['subject'] ?? '')) ?: null;
        $body = (string) ($data['body'] ?? '');
        $templateId = !empty($data['templateId']) ? (int) $data['templateId'] : null;

        // Con plantilla, el contenido manda la plantilla.
        if ($templateId !== null) {
            $tpl = $this->templates->find($templateId);
            if ($tpl === null) {
                return $this->jsonResponse($response, ['error' => 'La plantilla indicada no existe'], 400);
            }
            $subject = $tpl['subject'];
            $body = (string) $tpl['body'];
        }

        try {
            $result = $this->orchestrator->dispatch([
                'channel'      => trim((string) ($data['channel'] ?? '')),
                'name'         => $data['name'] ?? null,
                'subject'      => $subject,
                'body'         => $body,
                'recipients'   => $data['recipients'] ?? [],
                'listId'       => !empty($data['listId']) ? (int) $data['listId'] : null,
                'senderId'     => $data['senderId'] ?? null,
                'tags'         => $data['tags'] ?? null,
                'scheduleType' => $data['scheduleType'] ?? 'now',
                'scheduledAt'  => $data['scheduledAt'] ?? null,
                'expiresAt'    => $data['expiresAt'] ?? null,
                'templateId'   => $templateId,
                'origin'       => 'campaign',
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 400);
        }

        return $this->jsonResponse($response, array_merge($result, ['success' => true]), 201);
    }

    /** Fuerza el envío inmediato de una campaña (p.ej. una Draft/Scheduled). */
    public function sendNow(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->campaigns->find((int) ($args['id'] ?? 0));
        if ($campaign === null) {
            return $this->jsonResponse($response, ['error' => 'Campaña no encontrada'], 404);
        }

        @set_time_limit(60 + (int) $campaign['total_recipients'] * 2);
        $result = $this->service->process($campaign);

        return $this->jsonResponse($response, [
            'success'  => true,
            'campaign' => $this->campaigns->find((int) $campaign['id']),
            'result'   => $result,
        ]);
    }

    /** Cancela la programación de una campaña que aún no se ha enviado. */
    public function cancelSchedule(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $campaign = $this->campaigns->find($id);

        if ($campaign === null) {
            return $this->jsonResponse($response, ['error' => 'Campaña no encontrada'], 404);
        }
        if ($campaign['status'] !== 'Scheduled') {
            return $this->jsonResponse($response, [
                'error' => 'Solo se puede cancelar una campaña en estado Programada. Esta está en "'
                    . $campaign['status'] . '".',
            ], 409);
        }

        $this->campaigns->cancelSchedule($id);

        return $this->jsonResponse($response, [
            'success'  => true,
            'message'  => 'Programación cancelada. La campaña quedó como borrador.',
            'campaign' => $this->campaigns->find($id),
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $deleted = $this->campaigns->delete($id);
        if (!$deleted) {
            return $this->jsonResponse($response, ['error' => 'Campaña no encontrada'], 404);
        }

        // Los adjuntos guardados dejarían de tener dueño: se borran con ella.
        Attachments::purgeCampaign($id);

        return $this->jsonResponse($response, ['success' => true]);
    }
}
