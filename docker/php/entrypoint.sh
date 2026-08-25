#!/bin/sh
set -eu

load_secret() {
    variable_name="$1"
    secret_path="$2"
    required="$3"

    if [ -z "$secret_path" ] || [ ! -r "$secret_path" ]; then
        if [ "$required" = "required" ]; then
            echo "No se puede leer el secreto requerido para $variable_name." >&2
            exit 1
        fi

        return 0
    fi

    secret_value="$(cat "$secret_path")"

    if [ -z "$secret_value" ]; then
        if [ "$required" = "required" ]; then
            echo "El secreto requerido para $variable_name está vacío." >&2
            exit 1
        fi

        return 0
    fi

    export "$variable_name=$secret_value"
}

load_secret APP_KEY "${APP_KEY_FILE:-}" required
load_secret DB_PASSWORD "${DB_PASSWORD_FILE:-}" required
load_secret PLATFORM_ADMIN_TOKEN "${PLATFORM_ADMIN_TOKEN_FILE:-}" required
load_secret OPENAI_API_KEY "${OPENAI_API_KEY_FILE:-}" optional
load_secret DNI_LOOKUP_API_TOKEN "${DNI_LOOKUP_API_TOKEN_FILE:-}" optional

if [ "${AI_DOCUMENT_DRIVER:-demo}" = "openai" ] && [ -z "${OPENAI_API_KEY:-}" ]; then
    echo "AI_DOCUMENT_DRIVER=openai requiere el secreto OPENAI_API_KEY." >&2
    exit 1
fi

mkdir -p \
    storage/app/private \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
