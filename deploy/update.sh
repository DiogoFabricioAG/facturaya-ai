#!/usr/bin/env bash
set -Eeuo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/common.sh"

cd "$project_dir"
require_command git

if ! git diff --quiet || ! git diff --cached --quiet; then
    die "Hay cambios locales en archivos versionados. No se actualizó para evitar sobrescribirlos."
fi

info "Descargando cambios"
git pull --ff-only

exec "$project_dir/deploy/deploy.sh"
