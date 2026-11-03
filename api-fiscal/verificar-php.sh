#!/usr/bin/env bash
# Emissor NF-e · verificar-php.sh (v2.9.1)
#
# Diz se o PHP que roda o Emissor está SEGURO ou VULNERÁVEL às falhas críticas de 21/10/2026
# (CVE-2026-109618 e CVE-2026-109619, apelidadas "PHP2Shell"): execução remota de código sem login.
#
# Uso:
#   ./verificar-php.sh                         PHP do servidor (ou o de PHP_BIN=/caminho/php)
#   ./verificar-php.sh --docker <container>    PHP dentro do container (ex.: emissor-nfe-web-1)
#
# Saída: 0 = seguro · 1 = vulnerável · 2 = não foi possível confirmar (confira com o fornecedor do PHP)
# Só lê informações. Não altera nada e não mostra senhas nem chaves.
set -uo pipefail

# versões que trazem a correção (php.net, 21/10/2026)
fixed_for_branch() {
  case "$1" in
    8.2) echo 35 ;; 8.3) echo 36 ;; 8.4) echo 27 ;; 8.5) echo 12 ;; *) echo "" ;;
  esac
}

# pacotes de distribuição com a correção aplicada sem mudar o número da versão (backport)
#   codinome|pacote|versão corrigida|prefixo da versão da distribuição
BACKPORTS='noble|php8.3|8.3.6-0ubuntu0.24.04.13|8.3.6-0ubuntu
jammy|php8.1|8.1.2-1ubuntu2.28|8.1.2-1ubuntu
resolute|php8.5|8.5.4-0ubuntu1.5|8.5.4-0ubuntu
stonking|php8.5|8.5.9-0ubuntu2.1|8.5.9-0ubuntu
bookworm|php8.2|8.2.35-1~deb12u1|8.2.
trixie|php8.4|8.4.27-1~deb13u1|8.4.'

# 0 = corrigido, 1 = vulnerável, 2 = indeterminado (pela versão "oficial" do PHP)
classificar_versao() {
  local v="$1" maj min pat fix
  maj="${v%%.*}"; local rest="${v#*.}"; min="${rest%%.*}"; pat="${rest#*.}"; pat="${pat%%[!0-9]*}"
  [[ "$maj" =~ ^[0-9]+$ && "$min" =~ ^[0-9]+$ && "$pat" =~ ^[0-9]+$ ]] || return 2
  if (( maj < 8 )) || (( maj == 8 && min <= 1 )); then return 1; fi
  fix="$(fixed_for_branch "$maj.$min")"
  if [ -z "$fix" ]; then return 2; fi           # 8.6 ainda em teste, 9.x etc.
  if (( pat >= fix )); then return 0; fi
  return 1
}

if [ "${1:-}" = "--so-versao" ]; then
  classificar_versao "${VERIFICAR_PHP_VERSAO_TESTE:-0}"; exit $?
fi

RUN=()
ONDE="neste servidor"
if [ "${1:-}" = "--docker" ]; then
  [ -n "${2:-}" ] || { echo "Uso: $0 --docker <nome-do-container>" >&2; exit 2; }
  RUN=(docker exec "$2")
  ONDE="no container $2"
  "${RUN[@]}" true 2>/dev/null || { echo "Não consegui acessar o container $2 (ele está rodando? use: docker ps)." >&2; exit 2; }
fi
PHP="${PHP_BIN:-php}"

echo "Emissor NF-e · verificação do PHP (CVE-2026-109618 / CVE-2026-109619) $ONDE"
echo

PHPV="$("${RUN[@]}" "$PHP" -n -r 'echo PHP_VERSION;' 2>/dev/null)"
if [ -z "$PHPV" ]; then
  echo "RESULTADO: NÃO CONFIRMADO — não encontrei o PHP ($PHP) $ONDE."
  exit 2
fi
PHPPATH="$("${RUN[@]}" sh -c "readlink -f \"\$(command -v $PHP)\"" 2>/dev/null)"
echo "PHP encontrado: $PHPV (${PHPPATH:-caminho não identificado})"

classificar_versao "$PHPV"; ST=$?
MOTIVO=""
DICA=""

# Pacote de distribuição? (Ubuntu/Debian corrigem mantendo o número antigo)
PKG=""; PKGV=""; CODENAME=""
if [ -n "$PHPPATH" ] && "${RUN[@]}" sh -c 'command -v dpkg-query' >/dev/null 2>&1; then
  PKG="$("${RUN[@]}" dpkg -S "$PHPPATH" 2>/dev/null | head -1 | cut -d: -f1)"
  if [ -n "$PKG" ]; then
    PKGV="$("${RUN[@]}" dpkg-query -W -f='${Version}' "$PKG" 2>/dev/null)"
    CODENAME="$("${RUN[@]}" sh -c '. /etc/os-release 2>/dev/null; echo "${VERSION_CODENAME:-}"')"
    echo "Pacote: $PKG $PKGV (sistema: ${CODENAME:-?})"
  fi
fi

if [ "$ST" -eq 0 ]; then
  MOTIVO="a versão $PHPV já traz a correção."
elif [ -n "$PKG" ]; then
  base="${PKG%-*}"   # php8.3-cli -> php8.3
  linha="$(printf '%s\n' "$BACKPORTS" | awk -F'|' -v c="$CODENAME" -v p="$base" '$1==c && $2==p')"
  if [ -n "$linha" ]; then
    fixv="$(echo "$linha" | cut -d'|' -f3)"; pref="$(echo "$linha" | cut -d'|' -f4)"
    if [[ "$PKGV" == "$pref"* ]]; then
      if "${RUN[@]}" dpkg --compare-versions "$PKGV" ge "$fixv"; then
        ST=0; MOTIVO="o pacote $PKG $PKGV da distribuição já traz a correção (versão corrigida: $fixv)."
      else
        ST=1; MOTIVO="o pacote $PKG $PKGV é anterior à correção ($fixv)."
        DICA="sudo apt update && sudo apt upgrade   (depois rode ./stop.sh && ./start.sh)"
      fi
    fi
  elif [[ "$PKGV" =~ (ubuntu|deb[0-9]+u) && ! "$PKGV" =~ (sury|clp) ]]; then
    ST=2; MOTIVO="pacote da distribuição ($PKG $PKGV) fora da nossa tabela: a correção pode ter vindo por backport."
    DICA="Confira o aviso de segurança da sua distribuição para CVE-2026-109618 e CVE-2026-109619 e rode 'apt upgrade'."
  fi
elif "${RUN[@]}" sh -c 'command -v rpm' >/dev/null 2>&1 && [ -n "$PHPPATH" ] && "${RUN[@]}" rpm -qf "$PHPPATH" >/dev/null 2>&1; then
  if [ "$ST" -eq 1 ]; then
    ST=2; MOTIVO="PHP instalado por RPM: a distribuição pode ter aplicado a correção sem mudar a versão."
    DICA="Rode 'dnf update php\\*' e confira o aviso de segurança do fornecedor (CVE-2026-109618/109619)."
  fi
fi

if [ "$ST" -eq 1 ] && [ -z "$MOTIVO" ]; then
  MOTIVO="a versão $PHPV é anterior à correção (8.2.35, 8.3.36, 8.4.27 ou 8.5.12)."
fi
if [ "$ST" -eq 1 ] && [ -z "$DICA" ]; then
  if [ ${#RUN[@]} -gt 0 ]; then
    DICA="Atualize a imagem: na pasta do docker-compose.yml rode 'docker compose build --pull --no-cache && docker compose up -d --force-recreate' (a imagem do pacote 2.9.1 já vem fixada em php:8.3.36)."
  else
    DICA="Atualize o PHP para 8.3.36 / 8.4.27 / 8.5.12 / 8.2.35 (ou o pacote corrigido da sua distribuição: 'sudo apt update && sudo apt upgrade') e reinicie com ./stop.sh && ./start.sh."
  fi
fi
if [ "$ST" -eq 2 ] && [ -z "$MOTIVO" ]; then
  MOTIVO="versão $PHPV fora da tabela de versões conhecidas."
fi

# Travas da v2.9.1 no servidor do Emissor em execução (informativo)
echo
CMD=""
if [ ${#RUN[@]} -gt 0 ]; then
  CMD="$("${RUN[@]}" sh -c 'tr "\0" " " < /proc/1/cmdline' 2>/dev/null)"
else
  AQUI="$(cd "$(dirname "$0")" && pwd -P)"
  for p in /proc/[0-9]*; do
    c="$(tr '\0' ' ' < "$p/cmdline" 2>/dev/null)" || continue
    case "$c" in
      *" -S "*"public/index.php"*)
        # prefere o servidor desta pasta (pode haver mais de uma instalação no mesmo servidor)
        if [ "$(readlink -f "$p/cwd" 2>/dev/null)" = "$AQUI" ]; then CMD="$c"; break; fi
        [ -z "$CMD" ] && CMD="$c" ;;
    esac
  done
fi
if [ -n "$CMD" ] && [[ "$CMD" == *" -S "* ]]; then
  ok() { [[ "$CMD" == *"$1"* ]] && echo "  [ok]    $2" || echo "  [falta] $2"; }
  echo "Servidor do Emissor em execução — travas extras da 2.9.1:"
  ok "enable_post_data_reading=0" "PHP não interpreta envios multipart (todos os ataques conhecidos usam multipart)"
  ok "session.upload_progress.enabled=0" "progresso de upload de sessão desligado"
  [[ "$CMD" == *"enable_post_data_reading=0"* && "$CMD" == *"session.upload_progress.enabled=0"* ]] \
    || echo "  -> atualize para o pacote 2.9.1 e reinicie (./stop.sh && ./start.sh) para ligar as travas."
else
  echo "Servidor do Emissor não encontrado em execução $ONDE (travas da 2.9.1 não verificadas)."
fi

# Outros PHP no mesmo servidor (Apache mod_php, PHP-FPM) também precisam de atualização
if [ ${#RUN[@]} -eq 0 ] && command -v dpkg-query >/dev/null 2>&1; then
  outros="$(dpkg-query -W -f='${db:Status-Abbrev} ${Package} ${Version}\n' 'libapache2-mod-php*' 'php*-fpm' 'php*-cgi' 2>/dev/null | awk '$1=="ii"{print "  - "$2" "$3}')"
  if [ -n "$outros" ]; then
    echo
    echo "Atenção: há outros PHP neste servidor (sites, painéis). Eles também precisam do PHP corrigido:"
    echo "$outros"
  fi
fi

echo
case "$ST" in
  0) echo "RESULTADO: SEGURO — $MOTIVO" ;;
  1) echo "RESULTADO: VULNERÁVEL — $MOTIVO"; echo "O que fazer: $DICA" ;;
  *) echo "RESULTADO: NÃO CONFIRMADO — $MOTIVO"; [ -n "$DICA" ] && echo "O que fazer: $DICA" ;;
esac
exit "$ST"
