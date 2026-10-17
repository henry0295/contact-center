<?php

/**
 * Procesador en segundo plano. Hace tres cosas en cada pasada:
 *
 *   1. Lanza las campañas programadas cuya hora ya llegó.
 *   2. Consume la cola de trabajos (envíos masivos grandes encolados).
 *   3. Tareas de mantenimiento (poda de sesiones, intentos de login y trabajos).
 *
 *   composer dispatch
 *   php bin/dispatch-campaigns.php
 *
 * Ejemplo cron (cada minuto):
 *   * * * * * php /ruta/al/proyecto/bin/dispatch-campaigns.php >> /var/log/campaigns.log 2>&1
 *
 * En Windows, usar bin/dispatch-task.cmd con el Programador de tareas.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Repositories\CampaignRepository;
use App\Repositories\JobRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\SessionRepository;
use App\Service\CampaignService;
use App\Support\Logger;

// Sin límite de tiempo: una campaña grande tarda ~1 s por destinatario por el
// límite de envíos por segundo de SES.
@set_time_limit(0);

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

/** Cuántos trabajos se consumen por pasada. */
const JOBS_PER_RUN = 5;

function out(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

// Bloqueo entre ejecuciones: con el cron cada minuto y una campaña que tarda
// varios minutos, dos procesos simultáneos podrían enviar el mismo correo dos
// veces. Si ya hay una ejecución en marcha, esta termina sin hacer nada.
$lockPath = sys_get_temp_dir() . '/contact-center-dispatch.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "ERROR: no se pudo abrir el archivo de bloqueo {$lockPath}\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    out('Ya hay un dispatcher en ejecución; se omite esta pasada.');
    fclose($lock);
    exit(0);
}

$exitCode = 0;

try {
    $campaigns = new CampaignRepository();
    $jobs = new JobRepository();
    $service = new CampaignService();

    // --- 0) Trabajos que quedaron colgados por una caída del proceso ---
    $released = $jobs->releaseStale(30);
    if ($released > 0) {
        out("Se devolvieron {$released} trabajo(s) colgados a la cola.");
    }

    // --- 1) Campañas programadas vencidas ---
    $due = $campaigns->dueScheduled();
    if (empty($due)) {
        out('No hay campañas programadas pendientes.');
    }
    foreach ($due as $campaign) {
        out("Procesando campaña #{$campaign['id']} ({$campaign['name']})...");
        try {
            $result = $service->process($campaign);
            out("  -> enviados: {$result['sent']}, fallidos: {$result['failed']}, estado: {$result['status']}");
        } catch (\Throwable $e) {
            Logger::app()->error('Fallo al procesar campaña programada', [
                'campaignId' => $campaign['id'],
                'error'      => $e->getMessage(),
            ]);
            out("  -> ERROR: {$e->getMessage()}");
        }
    }

    // --- 2) Cola de trabajos ---
    $claimed = $jobs->claim(JOBS_PER_RUN);
    if (empty($claimed)) {
        out('Cola de trabajos vacía.');
    }
    foreach ($claimed as $job) {
        $id = (int) $job['id'];
        out("Trabajo #{$id} ({$job['type']}, intento {$job['attempts']}/{$job['max_attempts']})...");

        try {
            switch ($job['type']) {
                case JobRepository::TYPE_CAMPAIGN:
                    $campaignId = (int) ($job['payload']['campaignId'] ?? 0);
                    $campaign = $campaigns->find($campaignId);
                    if ($campaign === null) {
                        throw new \RuntimeException("La campaña #{$campaignId} no existe.");
                    }
                    $result = $service->process($campaign);
                    out("  -> enviados: {$result['sent']}, fallidos: {$result['failed']}, estado: {$result['status']}");
                    break;

                default:
                    throw new \RuntimeException("Tipo de trabajo desconocido: {$job['type']}");
            }

            $jobs->complete($id);
            out("  -> completado");
        } catch (\Throwable $e) {
            $jobs->fail($id, $e->getMessage());
            Logger::app()->error('Fallo al procesar un trabajo de la cola', [
                'jobId' => $id,
                'type'  => $job['type'],
                'error' => $e->getMessage(),
            ]);
            out("  -> ERROR: {$e->getMessage()} (se reintentará si quedan intentos)");
        }
    }

    // --- 2.5) Llamadas de voz originadas que ya es hora de consultar ---
    // Las llamadas (channel = 'call') no resuelven su resultado al originarse:
    // Asterisk tarda en marcar y la persona en contestar. Se consulta su
    // estado en la API de voz externa 20s después de haberlas originado.
    $voiceResult = $service->resolveCallStatuses();
    if ($voiceResult['resolved'] > 0 || $voiceResult['stillDialing'] > 0) {
        out("Llamadas de voz: {$voiceResult['resolved']} resueltas, {$voiceResult['stillDialing']} aún marcando.");
    }

    // --- 3) Mantenimiento ---
    $prunedSessions = (new SessionRepository())->prune();
    (new LoginAttemptRepository())->prune(7);
    $prunedJobs = $jobs->prune(30);
    if ($prunedSessions > 0 || $prunedJobs > 0) {
        out("Mantenimiento: {$prunedSessions} sesión(es) y {$prunedJobs} trabajo(s) antiguos eliminados.");
    }

    $pending = $jobs->pendingCount();
    if ($pending > 0) {
        out("Quedan {$pending} trabajo(s) en cola para la siguiente pasada.");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR en el dispatcher: ' . $e->getMessage() . "\n");
    Logger::app()->error('Fallo general del dispatcher', ['error' => $e->getMessage()]);
    $exitCode = 1;
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

exit($exitCode);
