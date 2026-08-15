#!/usr/bin/env bash
set -Eeuo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/common.sh"

require_command openssl

if [[ -e "$production_env" ]]; then
    die ".env.production ya existe; no se sobrescribió. Elimínalo manualmente solo si deseas reiniciar la configuración."
fi

read -r -p "Dominio sin https:// (ej. facturas.midominio.com): " app_domain
if ! [[ "$app_domain" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || [[ "$app_domain" != *.* ]] || [[ "$app_domain" == *..* ]]; then
    die "El dominio no tiene un formato válido."
fi

read -r -p "Correo para avisos del certificado TLS: " tls_email
if ! [[ "$tls_email" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; then
    die "El correo no tiene un formato válido."
fi

tmp_env="$(mktemp "$project_dir/.env.production.tmp.XXXXXX")"
trap 'rm -f "$tmp_env"' EXIT

sed \
    -e "s|^APP_URL=.*|APP_URL=https://$app_domain|" \
    -e "s|^APP_DOMAIN=.*|APP_DOMAIN=$app_domain|" \
    -e "s|^TLS_EMAIL=.*|TLS_EMAIL=$tls_email|" \
    -e "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"$tls_email\"|" \
    "$project_dir/.env.production.example" > "$tmp_env"

chmod 600 "$tmp_env"
"$project_dir/deploy/bootstrap-secrets.sh"

mv "$tmp_env" "$production_env"
trap - EXIT

info "Configuración creada"
echo "Dominio: https://$app_domain"
echo "Siguiente paso: confirma que el DNS apunte al VPS y ejecuta ./deploy/vps.sh deploy"
echo "Para entrar luego a /platform: ./deploy/vps.sh admin-token"
