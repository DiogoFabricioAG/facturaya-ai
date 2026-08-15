#!/bin/sh
set -eu

project_dir="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
secrets_dir="$project_dir/secrets"

umask 077
mkdir -p "$secrets_dir"

for secret_name in app_key db_password platform_admin_token openai_api_key; do
    if [ -e "$secrets_dir/$secret_name" ]; then
        echo "Ya existe secrets/$secret_name; no se sobrescribió ningún secreto." >&2
        exit 1
    fi
done

printf 'Pega la clave de proyecto de OpenAI (la entrada queda oculta): ' >&2
trap 'stty echo 2>/dev/null || true' EXIT INT HUP TERM
stty -echo
IFS= read -r openai_api_key
stty echo
trap - EXIT INT HUP TERM
printf '\n' >&2

if [ -z "$openai_api_key" ]; then
    echo "La clave de OpenAI es obligatoria porque .env.production usa AI_DOCUMENT_DRIVER=openai." >&2
    exit 1
fi

printf 'base64:%s' "$(openssl rand -base64 32 | tr -d '\n')" > "$secrets_dir/app_key"
openssl rand -hex 32 | tr -d '\n' > "$secrets_dir/db_password"
openssl rand -hex 32 | tr -d '\n' > "$secrets_dir/platform_admin_token"
printf '%s' "$openai_api_key" > "$secrets_dir/openai_api_key"
unset openai_api_key
chmod 600 "$secrets_dir"/*

echo "Secretos creados con permisos 600. No regeneres app_key después de registrar empresas."
