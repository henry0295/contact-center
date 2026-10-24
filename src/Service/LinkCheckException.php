<?php

namespace App\Service;

/**
 * El email tiene links bloqueados, o avisos sin confirmar. Lleva el detalle
 * para que la interfaz muestre qué corregir.
 */
class LinkCheckException extends \InvalidArgumentException
{
    /**
     * @param array{errors:array,warnings:array,rows?:array} $result
     */
    public function __construct(string $message, private array $result, private bool $needsConfirmation)
    {
        parent::__construct($message);
    }

    public function result(): array
    {
        return $this->result;
    }

    /** true = solo hay avisos: se puede enviar si el usuario los acepta. */
    public function needsConfirmation(): bool
    {
        return $this->needsConfirmation;
    }
}
