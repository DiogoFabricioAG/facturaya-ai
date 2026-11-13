# Despliegue: lectura de comprobantes para el portal de MeowLab

Fecha: 13/11/2026 (03:02 UTC). Autorizado por el operador («Sí, despliégala»).
Commit publicado `ba3b46d237d41eca41a0569878008f7c58bfcf73` (rama `feat/portal-lectura`).

## Cambio

- `GET /api/issued-documents`: facturas, boletas y notas de crédito del ambiente SUNAT
  vigente de la empresa del token, con filtros `from`, `to`, `type` (01, 03, 07) y 25
  por página.
- `GET /api/reports/monthly?year=`: ventas aceptadas por mes menos notas de crédito
  aceptadas.
- Sólo lectura, sin cambios de esquema.

## Despliegue

- Release `portal-lectura-20261113T030254Z` en `/opt/facturaya-ai/backups/releases/`, stage desde `git archive`
  del commit (`IssuedDocumentController.php` y `routes/api.php`). Antes del cambio, el
  `routes/api.php` del host, el de la imagen activa y el de git eran idénticos.
- Imágenes `facturaya-ai-app:portal-lectura-20261113T030254Z` y `facturaya-ai-web:portal-lectura-20261113T030254Z` sobre las activas.
- Pruebas antes del despliegue, en contenedor aislado sin red: 175/177; los 2 fallos son
  previos y del entorno de pruebas (`FiscalSetupProbeEndpointTest` y el manifiesto de
  Vite ausente en `CompanyAdministrationTest`). Las 4 pruebas nuevas pasan.

## Verificación

Salud 200 (interna y pública), `/api/invoice-drafts`, `/api/issued-documents` y
`/api/reports/monthly` sin token 401, `/` y `/guias` 200. Con el token de la empresa
activa: 46 comprobantes de producción y 12 meses de resumen.

## Rollback

```bash
bash /opt/facturaya-ai/backups/releases/portal-lectura-20261113T030254Z/rollback.sh
```
