<?php

namespace App\Service;

/**
 * Saldo insuficiente para un envío. Extiende InvalidArgumentException para
 * que los controladores existentes la devuelvan como error de validación
 * (con su mensaje en español) sin cambios; los que quieran distinguirla
 * pueden responder 402.
 */
class InsufficientCreditException extends \InvalidArgumentException
{
}
