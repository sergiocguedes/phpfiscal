# Trechos de app/service/PainelApiService.php

Métodos completos extraídos do fonte atual. No editor de ação que já contém a assinatura, copie apenas o corpo. Não duplique declarações.

## postNfse — linha de referência 86

```php
    public static function postNfse($endpoint, $payload, $timeout = 120)
    {
        return NfseApiPhpService::dispatch($endpoint, $payload, (int) $timeout);
    }
```

