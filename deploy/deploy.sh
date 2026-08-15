#!/bin/sh
set -eu

project_dir="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$project_dir"

if [ ! -f .env.production ]; then
    echo "Falta .env.production. Copia .env.production.example y configura el dominio." >&2
    exit 1
fi

for secret_name in app_key db_password platform_admin_token openai_api_key; do
    if [ ! -s "secrets/$secret_name" ]; then
        echo "Falta el secreto secrets/$secret_name. Ejecuta ./deploy/bootstrap-secrets.sh." >&2
        exit 1
    fi
done

docker compose --env-file .env.production build --pull
docker compose --env-file .env.production up -d
docker compose --env-file .env.production exec -T app php artisan migrate --force
docker compose --env-file .env.production exec -T app php artisan optimize
docker compose --env-file .env.production ps

echo "Despliegue terminado. Comprueba localmente: curl --fail http://127.0.0.1:8080/api/health"
