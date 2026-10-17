<?php

namespace App\Controllers;

use App\Repositories\DomainRepository;
use App\Repositories\SenderRepository;
use App\Support\Aws;
use Aws\Ses\SesClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SenderVerificationController
{
    use JsonResponseTrait;

    private ?SesClient $sesClient = null;
    private $repo;
    private $domains;

    public function __construct(
        ?SenderRepository $repo = null,
        ?DomainRepository $domains = null,
        ?SesClient $ses = null
    ) {
        $this->repo = $repo ?? new SenderRepository();
        $this->domains = $domains ?? new DomainRepository();
        $this->sesClient = $ses;
    }

    /**
     * Cliente SES perezoso: construirlo cuesta cientos de milisegundos y los
     * endpoints que solo leen de la base de datos no deben pagar ese coste.
     */
    private function ses(): SesClient
    {
        if ($this->sesClient === null) {
            $this->sesClient = new SesClient(Aws::clientConfig());
        }

        return $this->sesClient;
    }

    /**
     * Crea un remitente. Acepta dos formas:
     *  - {"localPart": "notificaciones", "domain": "midominio.com"}  (recomendada)
     *  - {"email": "notificaciones@midominio.com"}
     *
     * Si el dominio ya está verificado en SES, el remitente HEREDA esa
     * verificación y queda usable de inmediato (SES no exige confirmar cada
     * dirección de un dominio verificado). Si no, se envía el correo de
     * confirmación individual.
     *
     * Además, el remitente se marca como remitente por defecto (el MAIL_FROM
     * de los envíos) cuando se pide con setAsDefault o cuando no hay ninguno.
     */
    public function verifySender(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $email = trim($data['email'] ?? '');
        $localPart = trim($data['localPart'] ?? '');
        $domain = strtolower(trim($data['domain'] ?? ''));
        // 'sender' (aparece en "De", el caso normal) o 'reply' (recibe las
        // respuestas vía Reply-To). Cualquier otro valor se trata como 'sender'.
        $type = ($data['type'] ?? 'sender') === 'reply' ? 'reply' : 'sender';

        // Forma recomendada: parte local + dominio verificado.
        if ($email === '' && $localPart !== '' && $domain !== '') {
            $localPart = ltrim($localPart, '@');
            if (!preg_match('/^[A-Za-z0-9._%+\-]+$/', $localPart)) {
                return $this->jsonResponse($response, [
                    'error' => 'El nombre del remitente solo admite letras, números y . _ % + -',
                ], 400);
            }
            $email = $localPart . '@' . $domain;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonResponse($response, ['error' => 'Se requiere un correo de remitente válido'], 400);
        }

        $existing = $this->repo->findByEmail($email);
        if ($existing !== null && ($existing['type'] ?? 'sender') !== $type) {
            return $this->jsonResponse($response, [
                'error' => $type === 'reply'
                    ? 'Ese correo ya está registrado como remitente; usa otro para respuestas.'
                    : 'Ese correo ya está registrado como email de respuesta.',
            ], 409);
        }

        // Email de respuesta: la cabecera Reply-To NO necesita verificarse en
        // SES (solo el remitente "De"), así que puede ser cualquier dirección
        // (gmail, otro dominio...) y se pueden registrar varios. Queda usable
        // al instante y se elige uno por envío en el módulo Email.
        if ($type === 'reply') {
            try {
                $this->repo->upsert($email, 'Success', 'reply');
                $setAsDefault = !empty($data['setAsDefault']) || !$this->repo->hasDefault('reply');
                if ($setAsDefault) {
                    $this->repo->setDefault($email);
                }

                return $this->jsonResponse($response, [
                    'success'        => true,
                    'message'        => 'Email de respuesta ' . $email . ' agregado.',
                    'email'          => $email,
                    'type'           => 'reply',
                    'domainVerified' => false,
                    'isDefault'      => $setAsDefault,
                ]);
            } catch (\Throwable $e) {
                return $this->jsonResponse($response, [
                    'error'   => 'No se pudo guardar el email de respuesta',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        $emailDomain = strtolower(substr($email, strpos($email, '@') + 1));
        $domainVerified = $this->isDomainVerified($emailDomain);

        try {
            if ($domainVerified) {
                // Hereda la verificación del dominio: no hace falta confirmar la dirección.
                $this->repo->upsert($email, 'Success', $type);
                $message = 'Remitente creado y verificado mediante el dominio ' . $emailDomain . '.';
            } else {
                $this->ses()->verifyEmailIdentity(['EmailAddress' => $email]);
                $this->repo->upsert($email, 'Pending', $type);
                $message = 'El dominio no está verificado todavía, así que se envió un correo de confirmación a ' . $email . '.';
            }

            // Remitente por defecto (MAIL_FROM, o Reply-To si es tipo 'reply'):
            // explícito o el primero de su tipo que exista.
            $setAsDefault = !empty($data['setAsDefault']) || !$this->repo->hasDefault($type);
            if ($setAsDefault) {
                $this->repo->setDefault($email);
            }

            return $this->jsonResponse($response, [
                'success'        => true,
                'message'        => $message,
                'email'          => $email,
                'type'           => $type,
                'domainVerified' => $domainVerified,
                'isDefault'      => $setAsDefault,
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'error'   => 'No se pudo crear el remitente',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /** Marca un remitente existente como el remitente por defecto (MAIL_FROM). */
    public function setDefaultSender(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $email = trim($data['email'] ?? '');

        if ($email === '') {
            return $this->jsonResponse($response, ['error' => 'Se requiere el correo del remitente'], 400);
        }

        $sender = $this->repo->findByEmail($email);
        if ($sender === null) {
            return $this->jsonResponse($response, ['error' => 'El remitente no existe'], 404);
        }

        $this->repo->setDefault($email);

        return $this->jsonResponse($response, [
            'success'  => true,
            'message'  => 'Remitente por defecto actualizado',
            'email'    => $email,
            'verified' => $sender['verification_status'] === 'Success',
        ]);
    }

    /**
     * Elimina el remitente: borra la identidad en SES (si existe como identidad
     * propia) y la fila local. Si era el remitente por defecto, se promueve
     * automáticamente otro remitente verificado para no quedar sin MAIL_FROM.
     */
    public function deleteSender(Request $request, Response $response, array $args): Response
    {
        $email = $args['email'] ?? '';

        if (empty($email)) {
            return $this->jsonResponse($response, ['error' => 'Se requiere el correo del remitente'], 400);
        }

        $sender = $this->repo->findByEmail($email);
        if ($sender === null) {
            return $this->jsonResponse($response, ['error' => 'El remitente no existe'], 404);
        }

        $wasDefault = (bool) $sender['is_default'];

        // Los emails de respuesta no son identidades de SES: no hay nada que borrar allí.
        if (($sender['type'] ?? 'sender') !== 'reply') {
            try {
                // Un remitente que hereda la verificación de su dominio puede no
                // existir como identidad propia en SES: el fallo es inofensivo.
                $this->ses()->deleteIdentity(['Identity' => $email]);
            } catch (\Exception $e) {
                // Se continúa con el borrado local.
            }
        }

        $this->repo->delete($email);

        // Reasignar el remitente por defecto (de su mismo tipo) si hacía falta.
        $newDefault = null;
        if ($wasDefault) {
            $candidate = $this->repo->firstVerified(null, $sender['type'] ?? 'sender');
            if ($candidate !== null) {
                $this->repo->setDefault($candidate['email']);
                $newDefault = $candidate['email'];
            }
        }

        return $this->jsonResponse($response, [
            'success'    => true,
            'message'    => 'Remitente eliminado',
            'email'      => $email,
            'wasDefault' => $wasDefault,
            'newDefault' => $newDefault,
        ]);
    }

    /** Reenvía el correo de confirmación de un remitente pendiente. */
    public function resendSenderVerification(Request $request, Response $response, array $args): Response
    {
        $email = $args['email'] ?? '';

        if (empty($email)) {
            return $this->jsonResponse($response, ['error' => 'Se requiere el correo del remitente'], 400);
        }

        $row = $this->repo->findByEmail($email);
        if ($row === null) {
            return $this->jsonResponse($response, ['error' => 'El remitente no existe'], 404);
        }
        if (($row['type'] ?? 'sender') === 'reply') {
            $this->repo->updateStatus($email, 'Success');

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Los emails de respuesta no necesitan verificación.',
                'email'   => $email,
            ]);
        }

        $emailDomain = strtolower(substr($email, strpos($email, '@') + 1));
        if ($this->isDomainVerified($emailDomain)) {
            // No hace falta confirmar: hereda la verificación del dominio.
            $this->repo->updateStatus($email, 'Success');

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'No hace falta confirmar: el remitente ya está verificado por su dominio.',
                'email'   => $email,
            ]);
        }

        try {
            $this->ses()->verifyEmailIdentity(['EmailAddress' => $email]);
            $this->repo->updateStatus($email, 'Pending');

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Correo de confirmación reenviado a ' . $email . '.',
                'email'   => $email,
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'error'   => 'No se pudo reenviar la verificación',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function checkSenderStatus(Request $request, Response $response, array $args): Response
    {
        $email = $args['email'] ?? '';

        if (empty($email)) {
            return $this->jsonResponse($response, ['error' => 'Email address is required'], 400);
        }

        $row = $this->repo->findByEmail($email);
        if ($row !== null && ($row['type'] ?? 'sender') === 'reply') {
            // Reply-To: no se verifica en SES, siempre utilizable.
            $this->repo->updateStatus($email, 'Success');

            return $this->jsonResponse($response, ['email' => $email, 'verificationStatus' => 'Success']);
        }

        try {
            $result = $this->ses()->getIdentityVerificationAttributes([
                'Identities' => [$email]
            ]);

            $status = $result['VerificationAttributes'][$email]['VerificationStatus'] ?? 'NotFound';

            // Una dirección de un dominio verificado puede no existir como
            // identidad propia en SES; en ese caso hereda el estado del dominio.
            if ($status === 'NotFound') {
                $emailDomain = strtolower(substr($email, strpos($email, '@') + 1));
                if ($this->isDomainVerified($emailDomain)) {
                    $status = 'Success';
                }
            }

            $this->repo->updateStatus($email, $status);

            return $this->jsonResponse($response, [
                'email' => $email,
                'verificationStatus' => $status
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'error' => 'Failed to check email status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lista los remitentes. Por defecto responde SOLO con lo guardado en la base
     * de datos, que es instantáneo: consultar SES en cada carga de página añadía
     * más de un segundo de espera al usuario.
     *
     * Con ?sync=1 se sincroniza contra SES antes de responder (lo usa el botón
     * de refrescar del módulo de Verificación).
     */
    public function listVerifiedSenders(Request $request, Response $response): Response
    {
        $sync = filter_var($request->getQueryParams()['sync'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $typeFilter = $request->getQueryParams()['type'] ?? null;
        $typeFilter = in_array($typeFilter, ['sender', 'reply'], true) ? $typeFilter : null;
        $synced = false;

        if ($sync) {
            // SES es la autoridad del estado: si responde, su estado sobrescribe el
            // local vía upsert. Si falla (p.ej. claves AWS inválidas) se omite sin
            // romper y se devuelve la lista persistida en BD.
            try {
                $result = $this->ses()->listIdentities([
                    'IdentityType' => 'EmailAddress'
                ]);

                $identities = $result['Identities'] ?? [];

                if (!empty($identities)) {
                    $verificationAttributes = $this->ses()->getIdentityVerificationAttributes([
                        'Identities' => $identities
                    ]);

                    foreach ($identities as $email) {
                        $existing = $this->repo->findByEmail($email);
                        if ($existing !== null && ($existing['type'] ?? 'sender') === 'reply') {
                            continue; // Reply-To no depende de SES.
                        }
                        $statusSES = $verificationAttributes['VerificationAttributes'][$email]['VerificationStatus'] ?? 'Unknown';
                        $this->repo->upsert($email, $statusSES);
                    }
                }

                // Reconciliación: un remitente guardado localmente puede haber
                // dejado de ser válido en SES (por ejemplo si se eliminó su
                // dominio). Sin este paso la aplicación seguía mostrándolo como
                // "Verificado" y los envíos fallaban sin explicación.
                $this->reconcileLocalSenders($identities);

                $synced = true;
            } catch (\Exception $e) {
                // Degradado: se omite SES y se devuelve la lista de BD.
            }
        }

        return $this->jsonResponse($response, [
            'senders' => $this->repo->all($typeFilter),
            'synced'  => $synced,
        ]);
    }

    /**
     * Marca como no verificados los remitentes locales que ya no respalda SES:
     * ni existen como identidad propia verificada, ni su dominio está verificado.
     * Si alguno era el remitente por defecto, se le quita esa marca.
     *
     * @param string[] $sesIdentities identidades de tipo email que devolvió SES
     */
    private function reconcileLocalSenders(array $sesIdentities): void
    {
        $sesIdentities = array_map('strtolower', $sesIdentities);
        $domainCache = [];

        foreach ($this->repo->all() as $sender) {
            $email = $sender['email'];

            // Reply-To no depende de SES: no se reconcilia contra sus identidades.
            if (($sender['type'] ?? 'sender') === 'reply') {
                continue;
            }

            // Si SES la reconoce como identidad propia, el upsert previo ya puso
            // su estado real: no hay nada que reconciliar.
            if (in_array(strtolower($email), $sesIdentities, true)) {
                continue;
            }

            $domain = strtolower(substr($email, strpos($email, '@') + 1));
            if (!array_key_exists($domain, $domainCache)) {
                $domainCache[$domain] = $this->isDomainVerified($domain);
            }

            $status = $domainCache[$domain] ? 'Success' : 'NotFound';

            if ($sender['verification_status'] !== $status) {
                $this->repo->updateStatus($email, $status);
            }
            if ($status !== 'Success' && !empty($sender['is_default'])) {
                $this->repo->clearDefault($email);
            }
        }

        // Si la reconciliación dejó a algún tipo sin remitente por defecto, se
        // promueve otro que sí esté verificado: así los envíos siguen pudiendo
        // salir en lugar de fallar por falta de MAIL_FROM. El Reply-To es
        // opcional, pero si había uno configurado se intenta mantener vivo.
        foreach (['sender', 'reply'] as $type) {
            if (!$this->repo->hasDefault($type)) {
                $candidate = $this->repo->firstVerified(null, $type);
                if ($candidate !== null) {
                    $this->repo->setDefault($candidate['email']);
                }
            }
        }
    }

    /**
     * ¿Está el dominio verificado? Consulta SES y, si falla, cae al estado
     * guardado en la tabla domains.
     */
    private function isDomainVerified(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        try {
            $attrs = $this->ses()->getIdentityVerificationAttributes([
                'Identities' => [$domain]
            ]);

            $status = $attrs['VerificationAttributes'][$domain]['VerificationStatus'] ?? null;
            if ($status !== null) {
                return $status === 'Success';
            }
        } catch (\Exception $e) {
            // Degradado a la BD.
        }

        $row = $this->domains->findByDomain($domain);

        return $row !== null && $row['verification_status'] === 'Success';
    }
}
