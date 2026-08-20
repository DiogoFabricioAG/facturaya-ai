#!/usr/bin/env bash
set -Eeuo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/common.sh"

require_production_config
compose exec -T app php artisan sunat:sync-ruc "$@"
