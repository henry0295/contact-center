<?php

namespace App\Controllers;

use App\Repositories\TemplateRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TemplateController
{
    use JsonResponseTrait;

    private TemplateRepository $templates;

    public function __construct(?TemplateRepository $templates = null)
    {
        $this->templates = $templates ?? new TemplateRepository();
    }

    public function index(Request $request, Response $response): Response
    {
        $channel = $request->getQueryParams()['channel'] ?? null;
        $channel = in_array($channel, ['email', 'sms'], true) ? $channel : null;

        return $this->jsonResponse($response, ['templates' => $this->templates->all($channel)]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $name = trim($data['name'] ?? '');
        $channel = trim($data['channel'] ?? '');
        $subject = trim($data['subject'] ?? '') ?: null;
        $body = (string) ($data['body'] ?? '');

        if ($name === '' || $body === '') {
            return $this->jsonResponse($response, ['error' => 'Nombre y contenido son obligatorios'], 400);
        }
        if (!in_array($channel, ['email', 'sms'], true)) {
            return $this->jsonResponse($response, ['error' => 'Canal inválido (email o sms)'], 400);
        }

        $id = $this->templates->create(
            $name,
            $channel,
            $channel === 'email' ? $subject : null,
            $body,
            $this->designJson($data)
        );

        return $this->jsonResponse($response, ['success' => true, 'template' => $this->templates->find($id)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->templates->find($id);
        if ($existing === null) {
            return $this->jsonResponse($response, ['error' => 'Plantilla no encontrada'], 404);
        }

        $data = $request->getParsedBody() ?? [];
        $name = trim($data['name'] ?? $existing['name']);
        $subject = trim($data['subject'] ?? '') ?: null;
        $body = (string) ($data['body'] ?? $existing['body']);

        if ($name === '' || $body === '') {
            return $this->jsonResponse($response, ['error' => 'Nombre y contenido son obligatorios'], 400);
        }

        $this->templates->update(
            $id,
            $name,
            $existing['channel'] === 'email' ? $subject : null,
            $body,
            $this->designJson($data) ?? ($existing['design_json'] ?? null)
        );

        return $this->jsonResponse($response, ['success' => true, 'template' => $this->templates->find($id)]);
    }

    /** Normaliza el diseño del editor de bloques (puede llegar como objeto o cadena). */
    private function designJson(array $data): ?string
    {
        $design = $data['designJson'] ?? null;

        if ($design === null || $design === '') {
            return null;
        }

        return is_string($design) ? $design : json_encode($design);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $deleted = $this->templates->delete((int) ($args['id'] ?? 0));
        if (!$deleted) {
            return $this->jsonResponse($response, ['error' => 'Plantilla no encontrada'], 404);
        }

        return $this->jsonResponse($response, ['success' => true]);
    }
}
