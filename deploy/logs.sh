#!/usr/bin/env bash
set -Eeuo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/common.sh"

require_command docker
require_production_config

service="${1:-all}"
follow="${2:-}"
args=(--tail="${LOG_LINES:-100}")

if [[ "$follow" == "--follow" || "$follow" == "-f" ]]; then
    args+=(--follow)
fi

case "$service" in
    all) compose logs "${args[@]}" ;;
    app|web|database|caddy) compose logs "${args[@]}" "$service" ;;
    *) die "Servicio inválido. Usa: all, app, web, database o caddy." ;;
esac
