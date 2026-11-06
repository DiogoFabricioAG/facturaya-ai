#!/usr/bin/env bash
# Run interactively on the VPS: bash /opt/facturaya-ai/deploy/gre-access-check.sh
# Read-only: requests a SUNAT token and probes the GRE API without issuing or saving anything.
set +x
set -euo pipefail
umask 077
container=facturaya-ai-app-1
helper="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/gre-access-check.php"
remote_helper="/tmp/facturaya-gre-access-$$.php"
cleanup() {
    unset client_id client_secret sol_user sol_password
    docker exec "$container" rm -f "$remote_helper" >/dev/null 2>&1 || true
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP
[[ -r /dev/tty && -w /dev/tty ]] || { echo 'Se requiere una terminal interactiva (ssh -t).' >&2; exit 1; }
docker cp "$helper" "$container:$remote_helper" >/dev/null
printf 'Prueba de acceso a la API de guias del RUC 10410461248. No emite ni guarda nada.\n' >/dev/tty
printf 'Deja vacio para usar lo que ya tiene FacturaYa.\n\n' >/dev/tty
IFS= read -r -s -p 'ID API SUNAT (oculto, Enter = guardado): ' client_id </dev/tty; printf '\n' >/dev/tty
client_secret=''
if [[ -n "$client_id" ]]; then IFS= read -r -s -p 'Clave API SUNAT (oculta): ' client_secret </dev/tty; printf '\n' >/dev/tty; fi
IFS= read -r -p 'Usuario SOL sin RUC (Enter = guardado): ' sol_user </dev/tty
sol_password=''
if [[ -n "$sol_user" ]]; then IFS= read -r -s -p 'Clave SOL (oculta): ' sol_password </dev/tty; printf '\n' >/dev/tty; fi
printf '%s\n' "$client_id" "$client_secret" "$sol_user" "$sol_password" |
    docker exec -i "$container" facturaya-entrypoint php "$remote_helper"
