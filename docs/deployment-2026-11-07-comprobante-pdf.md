# Despliegue nuevo formato de facturas y boletas

Fecha: 07/11/2026, 01:35 UTC (06/11/2026, 20:35 en Lima). Servidor: alias SSH `meowlab`. URL: `https://facturas.meowlab.tech`.

## Fuente

- Commit publicado `8a1f6b39ab082ad242298ae8cf289f9b2100e8ee` en `origin/feat/comprobante-pdf`, sobre `feat/gre-web`.
- Release `comprobante-pdf-20261107T013353Z`. El stage salió de `git archive` del commit: `app/Services/InvoicePdfService.php` y `app/Services/FiscalOperationService.php`. Se eliminó `app/Services/FiscalInvoicePdfService.php`, que ya no existe en el commit. `stage.sha256` se verificó en el servidor.
- Antes del cambio, la fuente de `/opt/facturaya-ai` coincidía con la imagen activa `gre-web-20261106T222909Z` en los tres archivos.

## Alcance

Un solo generador de la representación impresa para facturas y boletas, tanto en la descarga web (`/api/invoices/{id}/files/pdf`) como en operaciones fiscales (Manolo). Incluye importe en letras, leyenda de representación impresa, estado SUNAT, valor resumen y consulta de validez. Las notas de crédito conservan su formato. Sin migraciones, assets, configuración ni secretos.

Los PDF de operaciones fiscales que ya estaban guardados conservan el formato anterior; las descargas web se generan al momento con el formato nuevo.

## Imágenes

- Activas: `facturaya-ai-app:comprobante-pdf-20261107T013353Z` y `facturaya-ai-web:comprobante-pdf-20261107T013353Z`, sobre las anteriores. La app copia el stage, borra el generador eliminado y regenera el autoload; web no cambia.
- Rollback: `facturaya-ai-app|web:rollback-comprobante-pdf-20261107T013353Z` (= `gre-web-20261106T222909Z`).

## Verificación

- Sin red, sobre la imagen: sintaxis PHP, clase eliminada fuera del autoload y PDF de prueba con importe en letras.
- Tras el cambio: salud local y pública HTTP 200; `/api/invoice-drafts` sin token HTTP 401; inicio y `/guias` HTTP 200; `FiscalOperationService` se resuelve; `web` saludable; sin errores en logs; el bridge de Manolo alcanza FacturaYa.
- PDF real de `F001-00000021` generado en producción sin guardarlo en la aplicación: una página con el formato nuevo, sus datos y su valor resumen. Copia en `F001-00000021-nuevo-formato.pdf` del directorio del release.
- Pruebas antes del despliegue, en contenedor aislado sin red: suite 174/175. El fallo es previo y ajeno: `FiscalSetupProbeEndpointTest` espera `technical_evidence.schema_version` 1 y recibe 2.

## Respaldo y recuperación

Directorio privado: `/opt/facturaya-ai/backups/releases/comprobante-pdf-20261107T013353Z`. Contiene stage, manifiestos, `source-before.tar.gz`, `cutover.sh`, `cutover.log` y `database-before.dump.enc` (AES-256/PBKDF2 con la clave de aplicación; verificado con `pg_restore --list`, 25 tablas con datos).

```sh
bash /opt/facturaya-ai/backups/releases/comprobante-pdf-20261107T013353Z/rollback.sh
```

El rollback restaura imágenes y fuente del host. No hay esquema que revertir y la base nunca se restaura automáticamente.
