<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\ApiException;

/** Acumulador de erros de validação por campo. */
final class Errors
{
    /** @var list<array{campo:string, mensagem:string}> */
    private array $list = [];

    public function add(string $field, string $msg): void
    {
        $this->list[] = ['campo' => $field, 'mensagem' => $msg];
    }

    public function any(): bool
    {
        return $this->list !== [];
    }

    /** @return list<array{campo:string, mensagem:string}> */
    public function all(): array
    {
        return $this->list;
    }

    public function throwIfAny(): void
    {
        if ($this->list !== []) {
            throw ApiException::validation($this->list);
        }
    }
}
