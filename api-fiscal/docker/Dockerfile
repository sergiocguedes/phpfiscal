# Emissor NF-e 2.9.1 — imagem de execução (PHP 8.3 CLI + extensões do pacote).
# Base FIXADA na versão corrigida das falhas CVE-2026-109618 / CVE-2026-109619 (PHP 8.3.36, 21/10/2026),
# pelo digest, para que um "build" futuro não troque a base sem você saber.
# O código NÃO vai na imagem: fica na pasta do pacote (bind mount em /app).
FROM php:8.3.36-cli-bookworm@sha256:ea81db1403c3df3c3d01cc6c0aeb5f7bd5a0fe6e16665fe3e3236c6113e2117f AS fiscal-cli

# Trava: o build falha se a base não tiver a correção
RUN php -r 'exit(version_compare(PHP_VERSION, "8.3.36", ">=") ? 0 : 1);' \
 || (echo "PHP da imagem base sem a correção de 21/10/2026" >&2; exit 1)

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libonig-dev libxml2-dev libcurl4-openssl-dev libpq-dev curl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd zip bcmath mbstring soap curl pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# php.ini endurecido (valores aplicados na inicialização, antes de qualquer requisição)
COPY php-seguranca.ini /usr/local/etc/php/conf.d/zz-emissor-seguranca.ini

# Usuário fixo (nunca root), dono de storage/ run/ e .env no host
RUN groupadd -g 10096 emissor && useradd -u 10096 -g 10096 -M -d /app -s /usr/sbin/nologin emissor

ENV TZ=America/Sao_Paulo
WORKDIR /app
USER emissor

# Produção: mesmo PHP/extensões, atendido por PHP-FPM atrás do Nginx.
FROM php:8.3.36-fpm-bookworm AS fiscal-fpm
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng16-16 libjpeg62-turbo libfreetype6 libzip4 libonig5 libxml2 libcurl4 libpq5 \
 && rm -rf /var/lib/apt/lists/* \
 && groupadd -g 10096 emissor \
 && useradd -u 10096 -g 10096 -M -d /app -s /usr/sbin/nologin emissor
COPY --from=fiscal-cli /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=fiscal-cli /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY fpm.conf /usr/local/etc/php-fpm.d/zz-fiscal.conf
ENV TZ=America/Sao_Paulo
WORKDIR /app
USER emissor
CMD ["php-fpm", "-F"]
