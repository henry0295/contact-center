<?php

/**
 * Estados de entrega de los emails (entregas, rebotes y rechazos de SES).
 *
 *   SES (configuration set de esta instalación) -> EventBridge -> cola SQS
 *   El dispatcher lee la cola cada minuto (solo conexiones salientes).
 *
 * Lo ejecuta Vozip UNA vez por cliente, con credenciales de AWS de
 * administrador que se pasan por entorno y NO se guardan:
 *
 *   export AWS_ADMIN_ACCESS_KEY_ID='AKIA...'
 *   read -rs AWS_ADMIN_SECRET_ACCESS_KEY; export AWS_ADMIN_SECRET_ACCESS_KEY
 *   php bin/ses-events.php setup --name=clientexyz
 *   php bin/ses-events.php status          (no necesita credenciales de administrador)
 *
 * setup crea (o reutiliza): el configuration set cc-<nombre>, la cola
 * cc-ses-events-<nombre>, la regla de EventBridge que solo deja pasar los
 * eventos de ese configuration set, y un permiso para que el usuario IAM de
 * esta instalación (AWS_ACCESS_KEY_ID del .env) lea SOLO su cola. En el .env
 * quedan SES_CONFIGURATION_SET y SES_EVENTS_QUEUE_URL.
 *
 * Salida: 0 = ok, 2 = faltan datos, 1 = error de AWS.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use App\Support\EnvFile;
use Aws\EventBridge\EventBridgeClient;
use Aws\Exception\AwsException;
use Aws\Iam\IamClient;
use Aws\SesV2\SesV2Client;
use Aws\Sqs\SqsClient;
use Aws\Sts\StsClient;

function out(string $m): void { fwrite(STDOUT, $m . "\n"); }
function fail(string $m, int $code = 1): never { fwrite(STDERR, 'ERROR: ' . $m . "\n"); exit($code); }

$command = 'help';
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } elseif ($command === 'help') {
        $command = $arg;
    }
}

try {
    $env = new EnvFile(isset($opts['env-file']) && $opts['env-file'] !== true ? (string) $opts['env-file'] : null);
} catch (\Throwable $e) {
    fail($e->getMessage());
}
$val = static fn (string $k): string => trim((string) $env->get($k), " \"'");

$region = $val('AWS_REGION') ?: 'us-east-1';
$clientCreds = array_filter([
    'key' => $val('AWS_ACCESS_KEY_ID'), 'secret' => $val('AWS_SECRET_ACCESS_KEY'), 'token' => $val('AWS_SESSION_TOKEN') ?: null,
]);
$cfg = static fn (array $creds): array => ['version' => 'latest', 'region' => $region, 'credentials' => $creds, 'http' => ['timeout' => 20, 'connect_timeout' => 10]];

function awsMsg(\Throwable $e): string
{
    return $e instanceof AwsException ? ($e->getAwsErrorCode() . ': ' . ($e->getAwsErrorMessage() ?: $e->getMessage())) : $e->getMessage();
}

switch ($command) {
    case 'setup':
        $name = strtolower(trim((string) ($opts['name'] ?? '')));
        $name = trim((string) preg_replace('/[^a-z0-9\-]+/', '-', $name), '-');
        if ($name === '' || strlen($name) > 40) {
            fail('Indica el nombre del cliente: --name=clientexyz (letras, números y guiones, máx. 40).', 2);
        }
        $adminKey = trim((string) getenv('AWS_ADMIN_ACCESS_KEY_ID'));
        $adminSecret = trim((string) getenv('AWS_ADMIN_SECRET_ACCESS_KEY'));
        if ($adminKey === '' || $adminSecret === '') {
            fail('Faltan AWS_ADMIN_ACCESS_KEY_ID y AWS_ADMIN_SECRET_ACCESS_KEY en el entorno (no se guardan en el servidor).', 2);
        }
        if (empty($clientCreds['key']) || empty($clientCreds['secret'])) {
            fail('El .env no tiene AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY de esta instalación.', 2);
        }
        $admin = array_filter(['key' => $adminKey, 'secret' => $adminSecret, 'token' => trim((string) getenv('AWS_ADMIN_SESSION_TOKEN')) ?: null]);

        $set = 'cc-' . $name;
        $queueName = 'cc-ses-events-' . $name;
        $ruleName = 'cc-ses-events-' . $name;

        try {
            $account = (string) (new StsClient($cfg($admin)))->getCallerIdentity()['Account'];
            $clientArn = (string) (new StsClient($cfg($clientCreds)))->getCallerIdentity()['Arn'];
        } catch (\Throwable $e) {
            fail('No se pudieron validar las credenciales de AWS: ' . awsMsg($e));
        }
        if (!str_contains($clientArn, ':' . $account . ':')) {
            fail("Las credenciales del .env ({$clientArn}) no son de la misma cuenta de AWS que las de administrador ({$account}).", 2);
        }
        out("Cuenta de AWS {$account}, región {$region}, usuario de la instalación {$clientArn}");

        $ses = new SesV2Client($cfg($admin));
        $sqs = new SqsClient($cfg($admin));
        $events = new EventBridgeClient($cfg($admin));

        try {
            // 1) Configuration set
            try {
                $ses->createConfigurationSet([
                    'ConfigurationSetName' => $set,
                    'ReputationOptions'    => ['ReputationMetricsEnabled' => true],
                    'SendingOptions'       => ['SendingEnabled' => true],
                ]);
                out("Configuration set {$set} creado.");
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                    throw $e;
                }
                out("Configuration set {$set} ya existía (se reutiliza).");
            }

            // 2) Cola SQS (cifrada, retiene 14 días si el servidor está apagado)
            try {
                $queueUrl = (string) $sqs->createQueue(['QueueName' => $queueName, 'Attributes' => [
                    'MessageRetentionPeriod' => '1209600', 'VisibilityTimeout' => '60', 'SqsManagedSseEnabled' => 'true',
                ]])['QueueUrl'];
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'QueueAlreadyExists' && $e->getAwsErrorCode() !== 'QueueNameExists') {
                    throw $e;
                }
                $queueUrl = (string) $sqs->getQueueUrl(['QueueName' => $queueName])['QueueUrl'];
            }
            $queueArn = (string) $sqs->getQueueAttributes(['QueueUrl' => $queueUrl, 'AttributeNames' => ['QueueArn']])['Attributes']['QueueArn'];
            out("Cola {$queueName} lista.");

            // 3) Regla de EventBridge: solo los eventos de ESTE configuration set
            $ruleArn = (string) $events->putRule([
                'Name'         => $ruleName,
                'Description'  => 'Contact Center: estados de email de ' . $set,
                'State'        => 'ENABLED',
                'EventPattern' => json_encode([
                    'source' => ['aws.ses'],
                    'detail' => [
                        'eventType' => ['Delivery', 'Bounce', 'Reject'],
                        'mail'      => ['tags' => ['ses:configuration-set' => [$set]]],
                    ],
                ]),
            ])['RuleArn'];

            // 4) Solo esa regla puede escribir en la cola
            $sqs->setQueueAttributes(['QueueUrl' => $queueUrl, 'Attributes' => ['Policy' => json_encode([
                'Version'   => '2012-10-17',
                'Statement' => [[
                    'Sid' => 'EventBridgeSesEvents', 'Effect' => 'Allow',
                    'Principal' => ['Service' => 'events.amazonaws.com'],
                    'Action' => 'sqs:SendMessage', 'Resource' => $queueArn,
                    'Condition' => ['ArnEquals' => ['aws:SourceArn' => $ruleArn]],
                ]],
            ])]]);
            $events->putTargets(['Rule' => $ruleName, 'Targets' => [['Id' => 'sqs', 'Arn' => $queueArn]]]);
            out("Regla {$ruleName} -> cola configurada.");

            // 5) SES publica entregas, rebotes y rechazos en EventBridge
            $dest = [
                'Enabled'                => true,
                'MatchingEventTypes'     => ['DELIVERY', 'BOUNCE', 'REJECT'],
                'EventBridgeDestination' => ['EventBusArn' => "arn:aws:events:{$region}:{$account}:event-bus/default"],
            ];
            try {
                $ses->createConfigurationSetEventDestination(['ConfigurationSetName' => $set, 'EventDestinationName' => 'cc-eventbridge', 'EventDestination' => $dest]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                    throw $e;
                }
                $ses->updateConfigurationSetEventDestination(['ConfigurationSetName' => $set, 'EventDestinationName' => 'cc-eventbridge', 'EventDestination' => $dest]);
            }
            out('Eventos de SES (entrega, rebote, rechazo) -> EventBridge activados.');

            // 6) El usuario de la instalación solo puede leer SU cola
            if (preg_match('#:user/(?:.*/)?([A-Za-z0-9+=,.@_\-]+)$#', $clientArn, $um)) {
                (new IamClient($cfg($admin)))->putUserPolicy([
                    'UserName'       => $um[1],
                    'PolicyName'     => 'cc-ses-events',
                    'PolicyDocument' => json_encode([
                        'Version'   => '2012-10-17',
                        'Statement' => [
                            ['Effect' => 'Allow', 'Action' => ['sqs:ReceiveMessage', 'sqs:DeleteMessage', 'sqs:GetQueueAttributes'], 'Resource' => $queueArn],
                            ['Effect' => 'Allow', 'Action' => ['ses:SendRawEmail', 'ses:SendEmail'], 'Resource' => "arn:aws:ses:{$region}:{$account}:configuration-set/{$set}"],
                        ],
                    ]),
                ]);
                out("Permiso de lectura de la cola dado al usuario IAM {$um[1]}.");
            } else {
                out("Aviso: {$clientArn} no es un usuario IAM; dale a mano sqs:ReceiveMessage, sqs:DeleteMessage y sqs:GetQueueAttributes sobre {$queueArn}.");
            }
        } catch (\Throwable $e) {
            fail('AWS rechazó la configuración: ' . awsMsg($e));
        }

        $env->set('SES_CONFIGURATION_SET', $set);
        $env->set('SES_EVENTS_QUEUE_URL', $queueUrl);
        $env->save();
        @unlink($env->path() . '.bak');
        out('Guardado en el .env: SES_CONFIGURATION_SET y SES_EVENTS_QUEUE_URL.');

        // Comprobación con las credenciales de la instalación (IAM tarda unos segundos).
        $clientSqs = new SqsClient($cfg($clientCreds));
        for ($i = 0; $i < 6; $i++) {
            try {
                $clientSqs->getQueueAttributes(['QueueUrl' => $queueUrl, 'AttributeNames' => ['ApproximateNumberOfMessages']]);
                out('Comprobado: esta instalación puede leer su cola.');
                out('');
                out('Listo. Recarga PHP (sudo systemctl reload php-fpm): los próximos emails reportarán entrega y rebotes.');
                exit(0);
            } catch (\Throwable $e) {
                sleep(5);
            }
        }
        out('Aviso: aún no se pudo leer la cola con las credenciales del .env (los permisos de IAM pueden tardar). Prueba en un minuto: php bin/ses-events.php status');
        exit(0);

    case 'status':
        $set = $val('SES_CONFIGURATION_SET');
        $queueUrl = $val('SES_EVENTS_QUEUE_URL');
        if ($set === '' || $queueUrl === '') {
            out('Estados de email: NO configurados (ejecuta setup).');
            exit(2);
        }
        out("Configuration set: {$set}");
        out("Cola: {$queueUrl}");
        try {
            $a = (new SqsClient($cfg($clientCreds)))->getQueueAttributes(['QueueUrl' => $queueUrl, 'AttributeNames' => ['ApproximateNumberOfMessages']])['Attributes'];
            out('Eventos pendientes en la cola: ' . (int) ($a['ApproximateNumberOfMessages'] ?? 0) . ' (el dispatcher los lee cada minuto).');
        } catch (\Throwable $e) {
            fail('No se pudo leer la cola con las credenciales del .env: ' . awsMsg($e));
        }
        $state = @json_decode((string) @file_get_contents(dirname(__DIR__) . '/storage/ses-events.json'), true);
        out('Último evento procesado: ' . (!empty($state['last_event_at']) ? date('Y-m-d H:i:s', (int) $state['last_event_at']) : 'ninguno todavía'));
        exit(0);

    default:
        out('Uso: php bin/ses-events.php setup --name=<cliente> | status');
        out('setup requiere AWS_ADMIN_ACCESS_KEY_ID y AWS_ADMIN_SECRET_ACCESS_KEY en el entorno (no se guardan).');
        exit($command === 'help' ? 0 : 2);
}
