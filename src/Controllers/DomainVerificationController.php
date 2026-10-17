<?php

namespace App\Controllers;

use App\Repositories\DomainRepository;
use App\Repositories\SenderRepository;
use App\Support\Aws;
use Aws\Ses\SesClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DomainVerificationController
{
    private ?SesClient $sesClient = null;
    private $repo;
    private SenderRepository $senders;

    public function __construct(
        ?DomainRepository $repo = null,
        ?SesClient $ses = null,
        ?SenderRepository $senders = null
    ) {
        $this->repo = $repo ?? new DomainRepository();
        $this->sesClient = $ses;
        $this->senders = $senders ?? new SenderRepository();
    }

    /**
     * Cliente SES perezoso: construirlo cuesta cientos de milisegundos, y los
     * endpoints que solo leen de la base de datos (como listDomains) no deben
     * pagar ese coste.
     */
    private function ses(): SesClient
    {
        if ($this->sesClient === null) {
            $this->sesClient = new SesClient(Aws::clientConfig());
        }

        return $this->sesClient;
    }

    public function verifyDomain(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $domain = $data['domain'] ?? '';

        if (empty($domain)) {
            return $this->jsonResponse($response, ['error' => 'Domain is required'], 400);
        }

        try {
            // Verify domain identity with Amazon SES
            $result = $this->ses()->verifyDomainIdentity([
                'Domain' => $domain
            ]);

            // Get DKIM verification tokens
            $dkimResult = $this->ses()->verifyDomainDkim([
                'Domain' => $domain
            ]);

            $this->repo->upsert(
                $domain,
                $result['VerificationToken'],
                $dkimResult['DkimTokens'],
                'Pending',
                'Pending',
                true
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Domain verification initiated',
                'verificationToken' => $result['VerificationToken'],
                'dkimTokens' => $dkimResult['DkimTokens']
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'error' => 'Failed to verify domain',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function checkDomainStatus(Request $request, Response $response, array $args): Response
    {
        $domain = $args['domain'] ?? '';

        if (empty($domain)) {
            return $this->jsonResponse($response, ['error' => 'Domain is required'], 400);
        }

        try {
            $result = $this->ses()->getIdentityVerificationAttributes([
                'Identities' => [$domain]
            ]);

            $status = $result['VerificationAttributes'][$domain]['VerificationStatus'] ?? 'NotFound';
            $dkimStatus = $this->getDkimStatus($domain);

            $this->repo->updateStatus($domain, $status, $dkimStatus['status'], (bool) $dkimStatus['enabled']);

            return $this->jsonResponse($response, [
                'domain' => $domain,
                'verificationStatus' => $status,
                'dkimStatus' => $dkimStatus
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'error' => 'Failed to check domain status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lista los dominios. Por defecto lee solo de la base de datos (rápido).
     * Con ?sync=1 reconcilia los estados contra SES: un dominio que ya no exista
     * allí pasa a 'NotFound' en vez de seguir mostrándose como verificado.
     */
    public function listDomains(Request $request, Response $response): Response
    {
        $sync = filter_var($request->getQueryParams()['sync'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $synced = false;

        if ($sync) {
            $stored = $this->repo->all();

            if (!empty($stored)) {
                try {
                    $names = array_column($stored, 'domain');
                    $attrs = $this->ses()->getIdentityVerificationAttributes(['Identities' => $names]);
                    $verification = $attrs['VerificationAttributes'] ?? [];
                    $dkim = $this->ses()->getIdentityDkimAttributes(['Identities' => $names])['DkimAttributes'] ?? [];

                    foreach ($stored as $row) {
                        $domain = $row['domain'];
                        $status = $verification[$domain]['VerificationStatus'] ?? 'NotFound';
                        $this->repo->updateStatus(
                            $domain,
                            $status,
                            $dkim[$domain]['DkimVerificationStatus'] ?? null,
                            isset($dkim[$domain]['DkimEnabled']) ? (bool) $dkim[$domain]['DkimEnabled'] : null
                        );

                        // Si el dominio dejó de estar verificado, sus remitentes
                        // tampoco lo están por herencia.
                        if ($status !== 'Success') {
                            $this->downgradeSendersOf($domain);
                        }
                    }
                    $synced = true;
                } catch (\Exception $e) {
                    // Degradado: se devuelve lo que haya en la base de datos.
                }
            } else {
                $synced = true;
            }
        }

        return $this->jsonResponse($response, [
            'domains' => $this->repo->all(),
            'synced'  => $synced,
        ]);
    }

    /**
     * Elimina el dominio: borra la identidad en SES y la fila local.
     * Ojo: al borrar la identidad del dominio, los remitentes que dependían de
     * su verificación dejan de poder enviar.
     */
    public function deleteDomain(Request $request, Response $response, array $args): Response
    {
        $domain = $args['domain'] ?? '';

        if (empty($domain)) {
            return $this->jsonResponse($response, ['error' => 'Se requiere el dominio'], 400);
        }

        $affectedSenders = $this->repo->countSenders($domain);
        $sesWarning = null;

        try {
            $this->ses()->deleteIdentity(['Identity' => $domain]);
        } catch (\Exception $e) {
            // Si la identidad no existe en SES (o AWS falla), se sigue borrando
            // localmente para no dejar la lista desincronizada.
            $sesWarning = $e->getMessage();
        }

        $deleted = $this->repo->delete($domain);

        // Los remitentes de este dominio pierden la verificación que heredaban de
        // él. Dejarlos marcados como "Verificado" hacía que la aplicación
        // mostrara un estado falso y los envíos fallaran sin explicación.
        $this->downgradeSendersOf($domain);

        if (!$deleted && $sesWarning !== null) {
            return $this->jsonResponse($response, [
                'error'   => 'No se pudo eliminar el dominio',
                'message' => $sesWarning,
            ], 500);
        }

        return $this->jsonResponse($response, [
            'success'         => true,
            'message'         => 'Dominio eliminado',
            'domain'          => $domain,
            'affectedSenders' => $affectedSenders,
            'sesWarning'      => $sesWarning,
        ]);
    }

    /**
     * Regenera/vuelve a solicitar los registros DNS del dominio (TXT + DKIM).
     * Útil si se perdieron los valores o se cambió la zona DNS.
     */
    public function reverifyDomain(Request $request, Response $response, array $args): Response
    {
        $domain = $args['domain'] ?? '';

        if (empty($domain)) {
            return $this->jsonResponse($response, ['error' => 'Se requiere el dominio'], 400);
        }

        try {
            $result = $this->ses()->verifyDomainIdentity(['Domain' => $domain]);
            $dkimResult = $this->ses()->verifyDomainDkim(['Domain' => $domain]);

            $this->repo->upsert(
                $domain,
                $result['VerificationToken'],
                $dkimResult['DkimTokens'],
                'Pending',
                'Pending',
                true
            );

            return $this->jsonResponse($response, [
                'success'           => true,
                'message'           => 'Registros DNS regenerados. Publícalos de nuevo en tu DNS.',
                'verificationToken' => $result['VerificationToken'],
                'dkimTokens'        => $dkimResult['DkimTokens'],
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'error'   => 'No se pudieron regenerar los registros',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Revisa en SES el estado real de cada remitente del dominio indicado y lo
     * refleja en la base de datos. Un remitente que solo estaba verificado por
     * herencia del dominio pasa a 'NotFound', y si era el remitente por defecto
     * se le quita esa marca para no dejar configurado un MAIL_FROM inválido.
     */
    private function downgradeSendersOf(string $domain): void
    {
        $senders = $this->senders->forDomain($domain);
        if (empty($senders)) {
            return;
        }

        $emails = array_column($senders, 'email');
        $statuses = [];

        try {
            $attrs = $this->ses()->getIdentityVerificationAttributes(['Identities' => $emails]);
            $statuses = $attrs['VerificationAttributes'] ?? [];
        } catch (\Exception $e) {
            // Sin respuesta de SES se asume que ya no están verificados.
        }

        foreach ($senders as $sender) {
            $email = $sender['email'];
            $status = $statuses[$email]['VerificationStatus'] ?? 'NotFound';

            $this->senders->updateStatus($email, $status);

            if ($status !== 'Success' && !empty($sender['is_default'])) {
                $this->senders->clearDefault($email);
            }
        }
    }

    private function getDkimStatus(string $domain): array
    {
        $result = $this->ses()->getIdentityDkimAttributes([
            'Identities' => [$domain]
        ]);

        return [
            'enabled' => $result['DkimAttributes'][$domain]['DkimEnabled'] ?? false,
            'status' => $result['DkimAttributes'][$domain]['DkimVerificationStatus'] ?? 'NotFound',
            'tokens' => $result['DkimAttributes'][$domain]['DkimTokens'] ?? []
        ];
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}