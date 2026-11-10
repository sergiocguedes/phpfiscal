<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Tributos\TabelaCClassTrib;

/*
 * Tabela local de cClassTrib do IBS/CBS (global, vale para todas as empresas). Uso do administrador da API.
 *
 *   php bin/cclasstrib.php                                 situação da tabela (versão, data, total)
 *   php bin/cclasstrib.php importar arquivo.csv [versao]   planilha oficial (aba cClassTrib salva como CSV) ou JSON
 *   php bin/cclasstrib.php svrs certificado.pfx [versao]   baixa da API Conformidade Fácil (SVRS) com certificado
 *                                                          ICP-Brasil; a senha é pedida no terminal ou lida de CFF_PFX_SENHA
 *
 * A importação substitui a tabela inteira (a anterior fica em storage/rtc/cclasstrib-anterior.json) e é recusada
 * por completo se alguma linha estiver inválida.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$cmd = $argv[1] ?? null;
try {
    if ($cmd === null) {
        $i = TabelaCClassTrib::info();
        echo $i['carregada'] ? "Versão {$i['versao']} · {$i['total']} cClassTrib · atualizada em {$i['atualizado_em']} ({$i['origem']})\n"
            : "Tabela não carregada. Use: php bin/cclasstrib.php importar arquivo.csv\n";
        exit(0);
    }
    if ($cmd === 'importar' && isset($argv[2])) {
        $linhas = TabelaCClassTrib::lerArquivo($argv[2]);
        $r = TabelaCClassTrib::importarLinhas($linhas, 'arquivo ' . basename($argv[2]), $argv[3] ?? null);
    } elseif ($cmd === 'svrs' && isset($argv[2])) {
        $senha = getenv('CFF_PFX_SENHA');
        if ($senha === false) {
            echo 'Senha do certificado: ';
            system('stty -echo 2>/dev/null');
            $senha = rtrim((string) fgets(STDIN), "\r\n");
            system('stty echo 2>/dev/null');
            echo "\n";
        }
        $linhas = TabelaCClassTrib::baixarSvrs($argv[2], $senha);
        $r = TabelaCClassTrib::importarLinhas($linhas, 'SVRS Conformidade Fácil', $argv[3] ?? date('Y-m-d'));
    } else {
        fwrite(STDERR, "Uso: php bin/cclasstrib.php [importar arquivo.csv|json [versao] | svrs certificado.pfx [versao]]\n");
        exit(1);
    }
    printf("Tabela atualizada: %d cClassTrib (%d incluídos, %d alterados, %d removidos)\n", $r['total'], $r['incluidos'], $r['alterados'], $r['removidos']);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
