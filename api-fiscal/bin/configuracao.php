<?php
declare(strict_types=1);
// Configuração do sistema (cifrada no banco) pela linha de comando. Segredos nunca são exibidos.
//   php bin/configuracao.php listar
//   php bin/configuracao.php valor CHAVE              (não aceita segredos)
//   php bin/configuracao.php definir-se-vazio CHAVE VALOR   (usado na instalação; não sobrescreve)
//   php bin/configuracao.php importar                 (copia para o banco o que ainda está no .env)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
use App\Support\{Env, Settings};

$cmd = $argv[1] ?? '';
$defs = Settings::definicoes();
switch ($cmd) {
    case 'listar':
        foreach (Settings::publicView()['itens'] as $i) {
            $v = $i['tipo'] === 'secret' ? ($i['definido'] ? '(definida)' : '(vazia)') : $i['valor'];
            printf("%-28s %-8s %s\n", $i['chave'], $i['origem'], $v);
        }
        break;
    case 'valor':
        $k = $argv[2] ?? '';
        if (!isset($defs[$k]) || $defs[$k][2] === 'secret') { fwrite(STDERR, "Chave inexistente ou secreta.\n"); exit(1); }
        echo Env::get($k, (string) $defs[$k][3]), "\n";
        break;
    case 'definir-se-vazio':
        [$k, $v] = [$argv[2] ?? '', $argv[3] ?? ''];
        if (!isset($defs[$k]) || $defs[$k][2] === 'secret') { fwrite(STDERR, "Chave inexistente ou secreta (use a janela /configuracao).\n"); exit(1); }
        if (Settings::get($k) !== null) { echo "$k já configurado; mantido.\n"; break; }
        $grupo = $defs[$k][0]; $atual = [];
        foreach (Settings::publicView()['itens'] as $i) { if ($i['grupo'] === $grupo && $i['origem'] !== 'padrao' && $i['tipo'] !== 'secret') { $atual[$i['chave']] = $i['valor']; } }
        $atual[$k] = $v;
        Settings::saveGroup($grupo, $atual, 'instalacao');
        echo "$k definido.\n";
        break;
    case 'importar':
        $n = Settings::importarArquivo('linha-de-comando');
        echo $n === [] ? "Nada a importar.\n" : 'Importado: ' . implode(', ', $n) . "\n";
        break;
    default:
        fwrite(STDERR, "Uso: php bin/configuracao.php listar | valor CHAVE | definir-se-vazio CHAVE VALOR | importar\n");
        exit(1);
}
