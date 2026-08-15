#!/usr/bin/env bash
set -Eeuo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/common.sh"

require_production_config

read -r -p "Esto mostrará el token administrativo en la terminal. ¿Continuar? [s/N]: " confirmation
if [[ "$confirmation" != "s" && "$confirmation" != "S" ]]; then
    echo "Cancelado."
    exit 0
fi

printf '\nToken de /platform (no lo envíes por chat ni correo):\n'
cat "$project_dir/secrets/platform_admin_token"
printf '\n'
