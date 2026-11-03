<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Erro "esperado" que pode ser exposto ao cliente com segurança.
 * Qualquer outra exceção vira 500 genérico.
 */
class ApiException extends \RuntimeException
{
    /** @var array<mixed> */
    public array $details;
    public string $errorCode;
    /** @var array<string,string> */
    public array $headers;

    /**
     * @param array<mixed> $details
     * @param array<string,string> $headers
     */
    public function __construct(int $status, string $errorCode, string $message, array $details = [], array $headers = [])
    {
        parent::__construct($message, $status);
        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->headers = $headers;
    }

    public function status(): int
    {
        return $this->getCode();
    }

    /** @param array<mixed> $details */
    public static function validation(array $details, string $message = 'Dados de entrada inválidos'): self
    {
        return new self(422, 'validacao', $message, $details);
    }
}
