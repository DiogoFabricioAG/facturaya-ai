#!/usr/bin/env bash
set -Eeuo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/common.sh"

require_command docker
require_command curl
require_production_config

cd "$project_dir"
app_domain="$(env_value APP_DOMAIN)"
[[ -n "$app_domain" ]] || die "APP_DOMAIN está vacío en .env.production."

info "Validando Docker Compose"
compose config --quiet

info "Construyendo imágenes"
compose build --pull

info "Iniciando servicios"
compose up -d --remove-orphans

info "Aplicando migraciones y cachés de Laravel"
# `docker compose exec` crea un proceso nuevo y no hereda las variables que el
# entrypoint exportó para php-fpm. Ejecutamos Artisan mediante el mismo
# entrypoint para cargar APP_KEY, DB_PASSWORD y los demás Docker Secrets.
compose exec -T app facturaya-entrypoint php artisan migrate --force
compose exec -T app facturaya-entrypoint php artisan optimize

info "Esperando HTTPS"
healthy=false
for _attempt in $(seq 1 24); do
    if curl --fail --silent --show-error --max-time 10 "https://$app_domain/api/health" >/dev/null 2>&1; then
        healthy=true
        break
    fi
    sleep 5
done

compose ps

if [[ "$healthy" != true ]]; then
    compose logs --tail=80 caddy web app >&2
    die "La aplicación inició, pero HTTPS no respondió. Revisa DNS y que los puertos 80/443 estén abiertos."
fi

echo "Despliegue correcto: https://$app_domain"
