<?php

namespace App\Controllers;

use App\Repositories\DomainRepository;
use App\Repositories\SenderRepository;
use App\Support\Aws;
use Aws\Exception\AwsException;
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

    /**
     * Varias instalaciones pueden compartir la misma cuenta de AWS. Cada una solo
     * debe ver y tocar SUS identidades (las registradas en su base de datos):
     * ninguna acción sobre SES se hace con un dominio que no sea local.
     */
    private function localDomain(string $domain): ?array
    {
        return $domain === '' ? null : $this->repo->findByDomain($domain);
    }

    private static function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain, " \t\n\r\0\x0B."));
    }

    /** ¿Existe ya esta identidad en la cuenta de SES (p.ej. de otra instalación)? */
    private function existsInSes(string $identity): bool
    {
        $attrs = $this->ses()->getIdentityVerificationAttributes(['Identities' => [$identity]]);

        return isset($attrs['VerificationAttributes'][$identity]);
    }

    public static function allowExistingIdentities(): bool
    {
        return filter_var($_ENV['SES_ALLOW_EXISTING_IDENTITIES'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function notLocal(Response $response, string $domain): Response
    {
        return $this->jsonResponse($response, [
            'error' => 'El dominio ' . $domain . ' no está registrado en esta instalación.',
        ], 404);
    }

    public function verifyDomain(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $domain = self::normalizeDomain((string) ($data['domain'] ?? ''));

        if ($domain === '') {
            return $this->jsonResponse($response, ['error' => 'Domain is required'], 400);
        }
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            return $this->jsonResponse($response, ['error' => 'Dominio no válido: ' . $domain], 400);
        }

        // Si el dominio ya existe en la cuenta de AWS pero no en esta
        // instalación, pertenece a otro cliente: registrarlo aquí permitiría
        // luego borrarlo o enviar en su nombre.
        try {
            if ($this->localDomain($domain) === null && !self::allowExistingIdentities() && $this->existsInSes($domain)) {
                return $this->jsonResponse($response, [
                    'error' => 'El dominio ' . $domain . ' ya está registrado en la cuenta de envío por otra instalación. '
                        . 'Si es tuyo (p.ej. reinstalación), pide al administrador de la plataforma que lo habilite.',
                ], 409);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'error'   => 'No se pudo consultar Amazon SES',
                'message' => $e->getMessage(),
            ], 502);
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
        $domain = self::normalizeDomain((string) ($args['domain'] ?? ''));

        if ($domain === '') {
            return $this->jsonResponse($response, ['error' => 'Domain is required'], 400);
        }
        if ($this->localDomain($domain) === null) {
            return $this->notLocal($response, $domain);
        }

        try {
            $result = $this->ses()->getIdentityVerificationAttributes([
                'Identities' => [$domain]
            ]);

            $status = $result['VerificationAttributes'][$domain]['VerificationStatus'] ?? 'NotFound';
            $dkimStatus = $this->getDkimStatus($domain);

            $this->repo->updateStatus($domain, $status, $dkimStatus['status'], (bool) $dkimStatus['enabled']);
            $this->syncMailFrom([$domain]);
            $row = $this->localDomain($domain) ?? [];

            return $this->jsonResponse($response, [
                'domain' => $domain,
                'verificationStatus' => $status,
                'dkimStatus' => $dkimStatus,
                'mailFromDomain' => $row['mail_from_domain'] ?? null,
                'mailFromStatus' => $row['mail_from_status'] ?? null,
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

                    $this->syncMailFrom($names);

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
            'domains' => array_map([$this, 'withMailFromMx'], $this->repo->all()),
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
        $domain = self::normalizeDomain((string) ($args['domain'] ?? ''));

        if ($domain === '') {
            return $this->jsonResponse($response, ['error' => 'Se requiere el dominio'], 400);
        }
        // Nunca se borra en SES un dominio que no es de esta instalación (la
        // cuenta de AWS puede ser compartida con otros clientes).
        if ($this->localDomain($domain) === null) {
            return $this->notLocal($response, $domain);
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
        $domain = self::normalizeDomain((string) ($args['domain'] ?? ''));

        if ($domain === '') {
            return $this->jsonResponse($response, ['error' => 'Se requiere el dominio'], 400);
        }
        if ($this->localDomain($domain) === null) {
            return $this->notLocal($response, $domain);
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

    /** Servidor MX de SES para el MAIL FROM (depende de la región de la cuenta). */
    public static function mailFromMx(): string
    {
        return 'feedback-smtp.' . Aws::region() . '.amazonses.com';
    }

    private function withMailFromMx(array $row): array
    {
        $row['mail_from_mx'] = self::mailFromMx();

        return $row;
    }

    private static function isAccessDenied(\Throwable $e): bool
    {
        return $e instanceof AwsException
            && in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true);
    }

    /**
     * Copia de SES el dominio MAIL FROM y su estado. SES es la fuente de verdad:
     * si allí no está configurado, se borra también aquí. Si el usuario IAM no
     * tiene ses:GetIdentityMailFromDomainAttributes, no se toca nada.
     */
    private function syncMailFrom(array $names): void
    {
        if (empty($names)) {
            return;
        }
        try {
            $attrs = $this->ses()->getIdentityMailFromDomainAttributes(['Identities' => array_values($names)])['MailFromDomainAttributes'] ?? [];
        } catch (\Throwable $e) {
            return;
        }
        foreach ($names as $domain) {
            $mf = strtolower(trim((string) ($attrs[$domain]['MailFromDomain'] ?? '')));
            if ($mf === '') {
                $this->repo->setMailFrom($domain, null, null);
            } else {
                $this->repo->setMailFrom($domain, $mf, (string) ($attrs[$domain]['MailFromDomainStatus'] ?? 'Pending'));
            }
        }
    }

    /**
     * Activa el dominio MAIL FROM personalizado (p.ej. bounce.midominio.com).
     * Body: { "subdomain": "bounce" }. Si SES no encuentra el MX, sigue usando
     * su MAIL FROM por defecto (amazonses.com): nunca se dejan de enviar correos.
     */
    public function setMailFrom(Request $request, Response $response, array $args): Response
    {
        $domain = self::normalizeDomain((string) ($args['domain'] ?? ''));
        if ($this->localDomain($domain) === null) {
            return $this->notLocal($response, $domain);
        }
        $data = (array) $request->getParsedBody();
        $sub = strtolower(trim((string) ($data['subdomain'] ?? 'bounce'), " \t\n\r\0\x0B."));
        if (str_ends_with($sub, '.' . $domain)) {
            $sub = substr($sub, 0, -strlen('.' . $domain));
        }
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (!preg_match('/^' . $label . '(?:\.' . $label . ')*$/', $sub) || in_array($sub, ['www', 'mail', '_dmarc'], true)) {
            return $this->jsonResponse($response, [
                'error' => 'Subdominio no válido. Usa uno que no se use para nada más, p.ej. "bounce".',
            ], 400);
        }
        $mailFrom = $sub . '.' . $domain;
        if (strlen($mailFrom) > 253) {
            return $this->jsonResponse($response, ['error' => 'El dominio MAIL FROM es demasiado largo.'], 400);
        }

        try {
            $this->ses()->setIdentityMailFromDomain([
                'Identity'            => $domain,
                'MailFromDomain'      => $mailFrom,
                'BehaviorOnMXFailure' => 'UseDefaultValue',
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, [
                'error' => self::isAccessDenied($e)
                    ? 'El usuario de AWS de esta instalación no tiene permiso ses:SetIdentityMailFromDomain. Pide a Vozip que lo agregue a la política ContactCenterSES.'
                    : 'No se pudo configurar el dominio MAIL FROM en Amazon SES.',
                'message' => $e->getMessage(),
            ], self::isAccessDenied($e) ? 403 : 502);
        }

        $this->repo->setMailFrom($domain, $mailFrom, 'Pending');

        return $this->jsonResponse($response, [
            'success'        => true,
            'message'        => 'MAIL FROM configurado. Publica los registros MX y TXT de ' . $mailFrom . ' en tu DNS.',
            'mailFromDomain' => $mailFrom,
            'mailFromMx'     => self::mailFromMx(),
        ]);
    }

    /** Desactiva el MAIL FROM personalizado: SES vuelve a usar amazonses.com. */
    public function disableMailFrom(Request $request, Response $response, array $args): Response
    {
        $domain = self::normalizeDomain((string) ($args['domain'] ?? ''));
        if ($this->localDomain($domain) === null) {
            return $this->notLocal($response, $domain);
        }
        try {
            // Sin MailFromDomain, SES quita el dominio personalizado.
            $this->ses()->setIdentityMailFromDomain(['Identity' => $domain]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, [
                'error' => self::isAccessDenied($e)
                    ? 'El usuario de AWS de esta instalación no tiene permiso ses:SetIdentityMailFromDomain.'
                    : 'No se pudo desactivar el dominio MAIL FROM en Amazon SES.',
                'message' => $e->getMessage(),
            ], self::isAccessDenied($e) ? 403 : 502);
        }
        $this->repo->setMailFrom($domain, null, null);

        return $this->jsonResponse($response, ['success' => true, 'message' => 'MAIL FROM personalizado desactivado.']);
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