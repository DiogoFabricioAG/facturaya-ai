# Caddyfile vivo en el VPS — 2026-11-20

Registro de sincronización entre el archivo activo en el VPS (`/opt/facturaya-ai/docker/caddy/Caddyfile`) y [`docker/caddy/Caddyfile.vps`](../docker/caddy/Caddyfile.vps).

## Bloques servidos hoy

| Dominio / ruta | Upstream (contenedor y puerto) | Notas |
| --- | --- | --- |
| `{$APP_DOMAIN}` (`/internal/manolo/*`) | `manolo-bridge:3000` | Bridge privado de Manolo |
| `{$APP_DOMAIN}` (resto) | `web:80` | Frontend de facturación |
| `meowlab.tech`, `www.meowlab.tech` (`/debt-hub/api/*`) | `{$DEBT_HUB_API_UPSTREAM}` (IP privada Docker en el VPS) | API de demo debt-hub |
| `meowlab.tech`, `www.meowlab.tech` (`/debt-hub/*`) | `hub-deuda-staging:80` | Frontend de demo debt-hub |
| `meowlab.tech`, `www.meowlab.tech` (resto) | `meowlab-web:80` | Landing pública de MeowLab |
| `app.meowlab.tech` | `meowlab-app:8080` | Portal web (el portal agrega CSP y cabeceras desde `web/public/_headers`) |
| `radar.meowlab.tech` | `radar-pilot-web:80` | Piloto web de RADAR |

## Qué difería respecto al repositorio

Antes de esta actualización, `docker/caddy/Caddyfile.vps` tenía tres desvíos frente al archivo vivo en `/opt/facturaya-ai/docker/caddy/Caddyfile`:

1. **`meowlab.tech` / `www.meowlab.tech`:** faltaban los bloques de `/debt-hub` y `/debt-hub/api/*` (`hub-deuda-staging:80` y `{$DEBT_HUB_API_UPSTREAM}`, además de la redirección `@demo_www` a `https://meowlab.tech`).
2. **`app.meowlab.tech`:** apuntaba al contenedor antiguo `meowlab-platform-dashboard-1:8082` con cabeceras duplicadas; el vivo apunta a `meowlab-app:8080` con `-Server`.
3. **`radar.meowlab.tech`:** el bloque completo (`radar-pilot-web:80`) existía en el VPS y no estaba versionado en el repositorio.

En la copia versionada se reemplazó la IP privada literal del upstream de `/debt-hub/api/*` por la variable `{$DEBT_HUB_API_UPSTREAM}` para no exponer direcciones internas en git. No había tokens ni claves básicas en el archivo.

## Cómo validar la sintaxis

```bash
docker run --rm \
  -e TLS_EMAIL=ops@example.com \
  -e APP_DOMAIN=app.example.com \
  -e DEBT_HUB_API_UPSTREAM=127.0.0.1:8000 \
  -v "$(pwd)/docker/caddy/Caddyfile.vps:/etc/caddy/Caddyfile:ro" \
  caddy:2-alpine caddy validate --config /etc/caddy/Caddyfile
```

Variables requeridas por `caddy validate`: `TLS_EMAIL`, `APP_DOMAIN` y `DEBT_HUB_API_UPSTREAM`.

## Cómo comprobar en el futuro que el VPS y el repo siguen iguales

Desde la raíz del repo `facturaya-ai`, normalizando la variable del upstream de `/debt-hub/api/*` y finales de línea CRLF del bloque `radar.meowlab.tech`:

```bash
ssh meowlab "cat /opt/facturaya-ai/docker/caddy/Caddyfile" \
  | tr -d '\r' \
  | diff -u docker/caddy/Caddyfile.vps -
```

La única diferencia esperada es la línea de `reverse_proxy {$DEBT_HUB_API_UPSTREAM}` frente a la IP privada del contenedor en el VPS.
