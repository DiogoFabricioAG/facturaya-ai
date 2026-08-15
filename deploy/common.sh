#!/usr/bin/env bash
set -Eeuo pipefail

project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
production_env="$project_dir/.env.production"
compose_files=(-f "$project_dir/compose.yaml" -f "$project_dir/compose.vps.yaml")

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

info() {
    printf '\n==> %s\n' "$*"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Falta el comando '$1'."
}

require_production_config() {
    [[ -f "$production_env" ]] || die "Falta .env.production. Ejecuta ./deploy/vps.sh configure."

    local secret_name
    for secret_name in app_key db_password platform_admin_token openai_api_key; do
        [[ -s "$project_dir/secrets/$secret_name" ]] || die "Falta secrets/$secret_name. Ejecuta ./deploy/vps.sh configure."
    done
}

env_value() {
    local key="$1"
    sed -n "s/^${key}=//p" "$production_env" | tail -n 1 | sed 's/^"//; s/"$//'
}

compose() {
    docker compose --env-file "$production_env" "${compose_files[@]}" "$@"
}
