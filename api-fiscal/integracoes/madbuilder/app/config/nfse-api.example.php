<?php
// Copie para nfse-api.local.php; não versione o arquivo preenchido.
return [
    // MadBuilder no Windows: localhost. No container Docker: host.docker.internal.
    'url' => 'http://host.docker.internal:8081/api/v1',
    'empresas' => [
        'CNPJ_SOMENTE_DIGITOS' => [
            'chave' => 'CHAVE_RESTRITA_A_EMPRESA',
            // Apenas quando usar chave central, informe também o ID da empresa.
            'empresa_id' => '',
        ],
    ],
];
