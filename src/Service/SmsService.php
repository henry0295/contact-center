<?php

namespace App\Service;

use App\Support\Aws;
use App\Support\Logger;
use Aws\Sns\SnsClient;

/**
 * Envío de SMS real vía Amazon SNS (Publish a un número de teléfono E.164).
 */
class SmsService
{
    private SnsClient $sns;

    public function __construct(?SnsClient $sns = null)
    {
        $this->sns = $sns ?? new SnsClient(Aws::clientConfig());
    }

    /**
     * @param string|null $senderId Remitente alfanumérico visible en el móvil.
     *   Solo se aplica en los países donde el operador lo admite; donde no,
     *   SNS lo ignora y usa un número propio (no es un error).
     */
    public function send(string $phone, string $message, ?string $senderId = null): void
    {
        $attributes = [
            // Transactional da prioridad de entrega y mejor fiabilidad. Las
            // campañas comerciales deberían ir como Promotional, pero SNS
            // aplica ese tipo por cuenta; aquí se prioriza la entrega.
            'AWS.SNS.SMS.SMSType' => ['DataType' => 'String', 'StringValue' => 'Transactional'],
        ];

        $senderId = $senderId === null ? '' : trim($senderId);
        if ($senderId !== '') {
            $attributes['AWS.SNS.SMS.SenderID'] = ['DataType' => 'String', 'StringValue' => $senderId];
        }

        try {
            $result = $this->sns->publish([
                'PhoneNumber'       => $phone,
                'Message'           => $message,
                'MessageAttributes' => $attributes,
            ]);

            Logger::mail()->info('SMS enviado', [
                'to'        => $phone,
                'sender'    => $senderId !== '' ? $senderId : null,
                'messageId' => $result['MessageId'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Logger::mail()->error('Fallo al enviar SMS', [
                'to'    => $phone,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
