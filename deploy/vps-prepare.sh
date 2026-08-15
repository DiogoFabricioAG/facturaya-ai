#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    exec sudo bash "$0" "$@"
fi

[[ -r /etc/os-release ]] || { echo "No se pudo detectar la distribución Linux." >&2; exit 1; }
. /etc/os-release

case "${ID:-}" in
    ubuntu)
        docker_distribution="ubuntu"
        docker_codename="${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}"
        ;;
    debian)
        docker_distribution="debian"
        docker_codename="${VERSION_CODENAME:-}"
        ;;
    *)
        echo "Este instalador admite Ubuntu y Debian oficiales. Detectado: ${ID:-desconocido}." >&2
        exit 1
        ;;
esac

[[ -n "$docker_codename" ]] || { echo "No se pudo detectar el nombre de la versión." >&2; exit 1; }

echo "Preparando dependencias para $PRETTY_NAME..."
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl git openssl

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL "https://download.docker.com/linux/$docker_distribution/gpg" -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    cat > /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/$docker_distribution
Suites: $docker_codename
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

systemctl enable --now docker
docker compose version

target_user="${SUDO_USER:-}"
if [[ -n "$target_user" && "$target_user" != "root" ]]; then
    usermod -aG docker "$target_user"
    echo "Se agregó '$target_user' al grupo docker. Cierra la sesión SSH y vuelve a entrar antes de desplegar."
fi

echo "VPS preparado. No se modificaron reglas SSH ni se habilitó/deshabilitó el firewall."
