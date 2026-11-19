# Despliegue pantalla web de guías y motivo de SUNAT

Fecha: 06/11/2026, 22:31 UTC (17:31 en Lima). Servidor: alias SSH `meowlab`. URL: `https://facturas.meowlab.tech/guias`.

## Fuente

- Commit publicado `e17d27ac37b2c7ebf4e7734f1794f0fc062df151` en `origin/feat/gre-web`, sobre `b8fc1`, que versiona el corte anterior tomado del contenedor activo.
- Release `gre-web-20261106T222909Z`. El stage salió de `git archive` del commit; los assets se compilaron con el árbol limpio e igual al commit. `stage.sha256` se verificó en el servidor.
- Antes del cambio se comprobó que la fuente de `/opt/facturaya-ai` coincidía con la imagen activa en todos los archivos del alcance.

## Alcance

Pantalla `/guias`, `GET /api/despatch-invoices`, unidades del catálogo 03, número de factura y destinatario en las respuestas de guías, y registro del código y mensaje de SUNAT (`send_failed` / `unknown`). Sin migraciones ni cambios de configuración, secretos, base de datos, proxy o redes.

## Imágenes

- Activas: `facturaya-ai-app:gre-web-20261106T222909Z` y `facturaya-ai-web:gre-web-20261106T222909Z`, construidas con `Dockerfile.release` sobre las anteriores, copiando solo el stage.
- Rollback: `facturaya-ai-app|web:rollback-gre-web-20261106T222909Z` (= `credit-gre-20261106T042556Z`).

## Verificación

- Sin red, sobre la imagen: sintaxis PHP de los archivos modificados, manifest con `despatches.js`, vista presente y `GreSendException` en el autoload.
- Tras el cambio: salud local y pública HTTP 200; `/api/despatches` y `/api/despatch-invoices` sin token HTTP 401; `/guias` local y pública HTTP 200 con su asset; inicio HTTP 200 con el selector de pago y el enlace a guías; ruta `despatch-invoices` registrada; `web` saludable; sin errores en logs; el bridge de Manolo alcanza la salud de FacturaYa.
- Pruebas antes del despliegue, en contenedor aislado sin red: 13/13 de guías. Suite completa 173/174; el fallo es previo y ajeno: `FiscalSetupProbeEndpointTest` espera `technical_evidence.schema_version` 1 y recibe 2.
- Flujo de la pantalla probado de punta a punta en una copia desechable con la empresa demo (driver simulado): revisar, emitir, consultar estado, XML/CDR, vehículo M1 y con conductor, vista móvil.
- No se emitieron documentos reales ni se llamó a SUNAT durante el despliegue.

## Respaldo y recuperación

Directorio privado: `/opt/facturaya-ai/backups/releases/gre-web-20261106T222909Z`. Contiene stage, manifiestos, `source-before.tar.gz`, `cutover.sh`, `cutover.log` y `database-before.dump.enc`. El dump está cifrado con AES-256/PBKDF2 usando la clave de aplicación como contraseña y se verificó con `pg_restore --list` (25 tablas con datos), sin mostrar datos.

```sh
bash /opt/facturaya-ai/backups/releases/gre-web-20261106T222909Z/rollback.sh
```

El rollback restaura imágenes y fuente del host; no hay esquema que revertir y la base nunca se restaura automáticamente.

## Diagnóstico de la guía T001-1

- Estado `unknown`, sin ticket ni código. La versión anterior descartaba la respuesta de SUNAT: `Api::sendXml` devolvió un resultado sin éxito y solo se registró `RuntimeException`. El motivo exacto no es recuperable.
- Se descartó la autorización: con las credenciales guardadas, SUNAT entrega token con acceso a `/v1/contribuyente/gem`. Se comprobó solo pidiendo el token, sin enviar nada.
- El XML firmado guardado no muestra errores evidentes: ubigeos 150132 y 090402 válidos, indicador M1/L con modalidad privada y factura relacionada `F001-00000021`.
- Desde este despliegue, el próximo envío registra el código y mensaje de SUNAT.

## Pendientes

- Manolo desplegado (`36b78`) trata `send_failed` como resultado incierto; mostrar el motivo depende de publicar `ac564` en meowlab.
- La T001-1 consumió el correlativo 1: la siguiente guía real será T001-2. Los PDF de trabajo entregados con T001-00000001 a 06 no corresponden a guías registradas en SUNAT.
- Sin PDF/QR de guía en FacturaYa. `feat/gre-web` no está fusionada en `main`.

## Seguimiento 06/11/2026 22:40 UTC: correlativo T001-1 liberado

- El operador consultó en SUNAT SOL (GRE emitidas, GRE Remitente, serie T001, número 00000001): sin resultados.
- Con guardas transaccionales (guía `unknown`, número 1, sin ticket, próximo número 2, ninguna otra guía numerada), la guía `01m6sc9mh8yv926nk4v0sct838` pasó a `send_failed`, código `SOL_SIN_REGISTRO`, sin correlativo y conservando su XML. El próximo número T001 de producción volvió a 1.
- La siguiente guía emitida será T001-1. Estado anterior y posterior: `t001-1-before.json` y `t001-1-after.json` en el directorio del release.
- Si SUNAT rechazara la nueva T001-1 como ya registrada, el envío queda `unknown` con ese mensaje y no se reenvía.

## Seguimiento 06/11/2026 23:12 UTC: causa del rechazo y segunda liberación

- El primer envío con la versión nueva (guía `01m6sgmvzdxaf1xv4hss1k6a05`, 22:46 UTC) quedó `send_failed` con `HTTP_401` en la fase de envío: SUNAT entregaba token, pero `/v1/contribuyente/gem` respondía `{"status":401,"message":"Unauthorized"}` a cualquier llamada.
- Causa: el usuario SOL secundario configurado no tenía las opciones de Guía de Remisión Electrónica. El operador se las asignó alrededor de las 22:50 UTC y SUNAT empezó a aceptarlas recién a las 23:11 UTC. La credencial API existente (alcance Web) no fue la causa: funciona tras el cambio.
- Se descartaron reloj (esperas de hasta 45 s), red (Francia y Perú), estado del RUC (activo y habido) y host. Herramienta usada: `deploy/gre-access-check.sh`, de solo lectura (ticket inexistente y envío vacío; ahora responden 422 de validación).
- Con las mismas guardas, esa guía quedó sin correlativo (sigue `send_failed`, con XML) y el próximo número T001 volvió a 1. Estado: `t001-1-b-before.json` y `t001-1-b-after.json`.
- Mejora pendiente: ante 401/403 el mensaje dice "corrige el dato", cuando el problema es de permisos en SUNAT y no de la guía.
