<?php

namespace App\Service;

use App\Repositories\SenderRepository;
use App\Repositories\SuppressionRepository;
use App\Support\Aws;
use App\Support\Logger;
use Aws\Ses\SesClient;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envío de email real vía Amazon SES.
 *
 * Se usa SendRawEmail con un mensaje MIME construido por PHPMailer, porque la
 * operación SendEmail de SES NO admite archivos adjuntos. PHPMailer solo arma
 * el MIME (no abre SMTP): el transporte lo hace SES.
 *
 * El remitente ("De") se resuelve en este orden:
 *   1. El parámetro $from explícito.
 *   2. El remitente tipo 'sender' marcado como "por defecto" en la tabla
 *      senders (lo que se configura en el módulo de Verificación).
 *   3. MAIL_FROM del .env (respaldo).
 * En todos los casos debe ser una identidad verificada en SES (o pertenecer a
 * un dominio verificado).
 *
 * El Reply-To es un rol INDEPENDIENTE y OPCIONAL (tipo 'reply' en la misma
 * tabla): si hay uno marcado como "por defecto", las respuestas del
 * destinatario van ahí en vez de a la dirección de "De". Es el mismo patrón
 * que "Email remitente" vs "Email de respuesta" en plataformas como 360NRS o
 * Brevo. Sin uno configurado, el comportamiento es el normal de cualquier
 * correo: las respuestas van al remitente.
 */
class EmailService
{
    private SesClient $ses;
    private ?SenderRepository $senders;
    private ?SuppressionRepository $suppressions;

    public function __construct(
        ?SesClient $ses = null,
        ?SenderRepository $senders = null,
        ?SuppressionRepository $suppressions = null
    ) {
        $this->ses = $ses ?? new SesClient(Aws::clientConfig());
        $this->senders = $senders;
        $this->suppressions = $suppressions;
    }

    /** Comprobación tolerante a fallos: si la BD no responde, no bloquea el envío. */
    private function isSuppressed(string $email): bool
    {
        try {
            $repo = $this->suppressions ?? new SuppressionRepository();

            return $repo->isSuppressed($email);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Remitente efectivo que se usará en los envíos. */
    public function resolveFrom(?string $from = null): string
    {
        if ($from !== null && $from !== '') {
            return $from;
        }

        try {
            $repo = $this->senders ?? new SenderRepository();
            $default = $repo->getDefault('sender');
            if ($default !== null && !empty($default['email'])) {
                return $default['email'];
            }
        } catch (\Throwable $e) {
            // Si la BD no está disponible se cae al .env sin romper el envío.
        }

        return $_ENV['MAIL_FROM'] ?? '';
    }

    /**
     * Dirección a la que deben ir las respuestas (cabecera Reply-To), si hay
     * una configurada. A diferencia del remitente, es opcional: sin ella las
     * respuestas simplemente van a la dirección de "De" (el comportamiento
     * normal de cualquier correo).
     */
    public function resolveReplyTo(?string $replyTo = null): ?string
    {
        if ($replyTo !== null && $replyTo !== '') {
            return $replyTo;
        }

        try {
            $repo = $this->senders ?? new SenderRepository();
            $default = $repo->getDefault('reply');
            if ($default !== null && !empty($default['email'])) {
                return $default['email'];
            }
        } catch (\Throwable $e) {
            // Sin Reply-To no se rompe el envío: es opcional.
        }

        return null;
    }

    /**
     * @param array<int,array{path:string,name:string}> $attachments
     * @param string|null $replyTo Si se omite, se usa el remitente de respuesta
     *   por defecto configurado en Verificación (si hay uno). Puede quedar sin
     *   valor: en ese caso las respuestas van a la dirección de "De", como de
     *   costumbre.
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        ?string $from = null,
        array $attachments = [],
        ?string $replyTo = null
    ): void {
        // La supresión se comprueba PRIMERO: depende solo del destinatario, y
        // así el mensaje de error apunta a la causa real en lugar de quedar
        // tapado por un problema de configuración del remitente.
        //
        // Reincidir con rebotes permanentes o quejas degrada la reputación de
        // la cuenta en AWS y puede acabar en la suspensión del envío.
        if ($this->isSuppressed($to)) {
            throw new \RuntimeException(
                'La dirección ' . $to . ' está en la lista de supresión por un rebote permanente '
                . 'o una queja previa. Revísala en Reportes antes de volver a enviarle.'
            );
        }

        $from = $this->resolveFrom($from);

        if ($from === '') {
            throw new \RuntimeException(
                'No hay remitente configurado. Crea un remitente en el módulo de Verificación '
                . '(sobre un dominio verificado) o define MAIL_FROM en el .env.'
            );
        }

        $replyTo = $this->resolveReplyTo($replyTo);
        $raw = $this->buildMime($from, $to, $subject, $body, $attachments, $replyTo);

        try {
            $result = $this->ses->sendRawEmail([
                'RawMessage' => ['Data' => $raw],
            ]);

            Logger::mail()->info('Email enviado', [
                'to'          => $to,
                'from'        => $from,
                'replyTo'     => $replyTo,
                'subject'     => $subject,
                'attachments' => count($attachments),
                'messageId'   => $result['MessageId'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Logger::mail()->error('Fallo al enviar email', [
                'to'      => $to,
                'from'    => $from,
                'subject' => $subject,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Construye el mensaje MIME (multipart/alternative + adjuntos) con PHPMailer.
     *
     * @param array<int,array{path:string,name:string}> $attachments
     */
    private function buildMime(
        string $from,
        string $to,
        string $subject,
        string $body,
        array $attachments,
        ?string $replyTo = null
    ): string {
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;

        $fromName = $_ENV['MAIL_FROM_NAME'] ?? '';
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);

        if ($replyTo !== null && $replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $body;
        $mail->AltBody = $this->toPlainText($body);

        foreach ($attachments as $attachment) {
            if (empty($attachment['path']) || !is_file($attachment['path'])) {
                continue;
            }
            $mail->addAttachment($attachment['path'], $attachment['name'] ?? '');
        }

        // preSend() arma el mensaje sin enviarlo; el envío lo hace SES.
        $mail->preSend();

        return $mail->getSentMIMEMessage();
    }

    /** Alternativa en texto plano legible a partir del HTML. */
    private function toPlainText(string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/h[1-6]|\/li|\/tr)\s*\/?>/i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
