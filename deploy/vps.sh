#!/usr/bin/env bash
set -Eeuo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
command_name="${1:-help}"
shift || true

case "$command_name" in
    prepare) exec "$script_dir/vps-prepare.sh" "$@" ;;
    configure) exec "$script_dir/vps-configure.sh" "$@" ;;
    deploy) exec "$script_dir/deploy.sh" "$@" ;;
    update) exec "$script_dir/update.sh" "$@" ;;
    status) exec "$script_dir/status.sh" "$@" ;;
    logs) exec "$script_dir/logs.sh" "$@" ;;
    backup) exec "$script_dir/backup.sh" "$@" ;;
    admin-token) exec "$script_dir/admin-token.sh" "$@" ;;
    help|-h|--help)
        cat <<'EOF'
FacturaYa AI - administración del VPS

  ./deploy/vps.sh prepare             Instala Docker en Ubuntu/Debian (usa sudo)
  ./deploy/vps.sh configure           Solicita dominio, correo y secretos
  ./deploy/vps.sh deploy              Construye y publica con HTTPS automático
  ./deploy/vps.sh update              Descarga main y vuelve a desplegar
  ./deploy/vps.sh status              Comprueba contenedores y endpoints
  ./deploy/vps.sh logs [servicio]     Muestra logs (all/app/web/database/caddy)
  ./deploy/vps.sh backup              Genera un respaldo cifrado y verificable
  ./deploy/vps.sh admin-token         Muestra el token de /platform tras confirmar

Para seguir logs: ./deploy/vps.sh logs app --follow
EOF
        ;;
    *) echo "Comando desconocido: $command_name" >&2; exit 1 ;;
esac
