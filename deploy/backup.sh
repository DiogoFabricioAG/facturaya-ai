#!/usr/bin/env bash
set -Eeuo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/common.sh"

require_command docker
require_command git
require_command openssl
require_command tar
require_production_config

backup_dir="$project_dir/backups"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
archive="$backup_dir/facturaya-$timestamp.tar.gz.enc"
work_dir="$(mktemp -d)"
backup_complete=false

cleanup() {
    rm -rf -- "$work_dir"
    if [[ "$backup_complete" != true ]]; then
        rm -f -- "$archive"
    fi
}
trap cleanup EXIT

mkdir -p "$backup_dir"
chmod 700 "$backup_dir"
umask 077

read -r -s -p "Contraseña para cifrar el respaldo: " backup_passphrase
printf '\n'
read -r -s -p "Repite la contraseña: " backup_passphrase_confirmation
printf '\n'

[[ ${#backup_passphrase} -ge 16 ]] || die "Usa una contraseña de al menos 16 caracteres."
[[ "$backup_passphrase" == "$backup_passphrase_confirmation" ]] || die "Las contraseñas no coinciden."
unset backup_passphrase_confirmation

info "Exportando PostgreSQL"
compose exec -T database pg_dump -U facturaya -d facturaya -Fc > "$work_dir/database.dump"

info "Exportando XML, CDR y certificados cifrados"
compose exec -T app tar -C /var/www/html/storage -czf - . > "$work_dir/app-storage.tar.gz"

mkdir -p "$work_dir/configuration"
cp "$production_env" "$work_dir/configuration/.env.production"
cp -R "$project_dir/secrets" "$work_dir/configuration/secrets"
git -C "$project_dir" rev-parse HEAD > "$work_dir/git-commit.txt"

info "Cifrando respaldo"
tar -C "$work_dir" -czf - . \
    | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -pass fd:3 3<<<"$backup_passphrase" \
    > "$archive"
chmod 600 "$archive"

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass fd:3 3<<<"$backup_passphrase" -in "$archive" \
    | tar -tzf - >/dev/null
unset backup_passphrase
backup_complete=true

echo "Respaldo cifrado y verificado: $archive"
echo "Guárdalo fuera del VPS. Sin su contraseña no podrá recuperarse."
