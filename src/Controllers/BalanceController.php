<?php

namespace App\Controllers;

use App\Service\CreditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Saldo para el medidor de la barra superior. SOLO LECTURA.
 *
 * A propósito NO devuelve precios ni movimientos: el cliente ve cuánto le
 * queda, no cuánto cuesta cada envío. La recarga y los precios se gestionan
 * únicamente por consola (bin/credits.php).
 */
class BalanceController
{
    use JsonResponseTrait;

    private CreditService $credits;

    public function __construct(?CreditService $credits = null)
    {
        $this->credits = $credits ?? new CreditService();
    }

    public function show(Request $request, Response $response): Response
    {
        try {
            $account = $this->credits->account();
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['error' => 'No se pudo consultar el saldo'], 500);
        }

        $balance = max(0.0, $account['balance']);
        $reference = $account['reference'];
        // Porcentaje respecto al saldo que quedó tras la última recarga.
        $percent = $reference > 0 ? (int) round(min(100, $balance * 100 / $reference)) : ($balance > 0 ? 100 : 0);

        return $this->jsonResponse($response->withHeader('Cache-Control', 'no-store'), [
            'balance'  => round($balance, 2),
            'currency' => 'COP',
            'percent'  => $percent,
            'low'      => $percent < CreditService::LOW_PERCENT,
        ]);
    }
}
