#!/usr/bin/env bash
set -Eeuo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/common.sh"

require_command docker
require_command curl
require_production_config

app_domain="$(env_value APP_DOMAIN)"

info "Contenedores"
compose ps

info "Salud interna"
curl --fail --silent --show-error http://127.0.0.1:8080/api/health
printf '\n'

info "Salud pública"
curl --fail --silent --show-error --max-time 15 "https://$app_domain/api/health"
printf '\n'
