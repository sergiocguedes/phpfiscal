<?php

declare(strict_types=1);

namespace App\Auth;

/** Quem está fazendo a requisição (sessão de usuário ou chave de API). */
final class Principal
{
    public const VIA_SESSION = 'sessao';
    public const VIA_API_KEY = 'chave_api';
    /** Sessão do Portal do Contador (link de uso único gerado pelo sistema da empresa). */
    public const VIA_PORTAL = 'portal';

    public function __construct(
        
        public readonly ?string $tenantId,
        public readonly string $via,
        public readonly string $role,
        public readonly ?string $userId = null,
        public readonly ?string $apiKeyId = null,
        /** hash sha256 do token de sessão (id interno da sessão) */
        public readonly ?string $sessionHash = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $session = null,
        /** usuário da sessão tem MFA (TOTP) ativo */
        public readonly bool $mfaAtivo = false,
        /** admin ou operador; null não tem acesso à gestão central. */
        public readonly ?string $acessoCentral = null,
    ) {
    }

    public function forTenant(string $id): self
    {
        return new self($id,$this->via,$this->role,$this->userId,$this->apiKeyId,$this->sessionHash,$this->session,$this->mfaAtivo,$this->acessoCentral);
    }

    public function isOwner(): bool
    {
        return $this->role === UserStore::ROLE_OWNER;
    }

    /**
     * Pode cancelar NF-e/NFC-e, enviar CC-e e inutilizar numeração: owner ou fiscal.
     * Chaves de API têm papel owner (credencial administrativa do tenant), então também podem.
     * Papel desconhecido (registro corrompido/antigo) => não pode (seguro por padrão).
     */
    public function canFiscal(): bool
    {
        return $this->role === UserStore::ROLE_OWNER || $this->role === UserStore::ROLE_FISCAL;
    }

    

    public function actor(): string
    {
        return match ($this->via) {
            self::VIA_SESSION => 'user:' . $this->userId,
            self::VIA_PORTAL => 'portal:' . $this->userId,
            default => 'api-key:' . $this->apiKeyId,
        };
    }
}
