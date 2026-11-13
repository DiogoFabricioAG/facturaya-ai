# Despliegue: consulta interna de RUC para el portal de MeowLab

Fecha: 13/11/2026 (04:01 UTC). Autorizado por el operador («Sí, despliégala»).
Commit publicado `f33ef392ceffdd3bfbec061b9d6a921b1149cb1e` (rama `feat/portal-empresas`).

## Cambio

- `GET /api/internal/taxpayers/{ruc}` (ADR-0010 de MeowLab): lee el padrón compartido
  (caché `sunat_taxpayers` y proveedores) sin pasar por una empresa ni crear clientes.
  Protegida por `RequireCompanyProfileBridge` (bearer `FISCAL_PROFILE_BRIDGE_TOKEN`).
- `CustomerLookupService` usa el mismo padrón (`TaxpayerDirectory`); mismo
  comportamiento hacia las empresas.
- Sin cambios de esquema.

## Despliegue

- Release `portal-empresas-20261113T040115Z` en `/opt/facturaya-ai/backups/releases/`, stage desde `git archive`
  del commit (5 archivos). Antes del cambio, `CustomerLookupService.php` y
  `routes/api.php` del host, de la imagen activa y de git (`9039d`) eran idénticos.
- Imágenes `facturaya-ai-app:portal-empresas-20261113T040115Z` y `facturaya-ai-web:portal-empresas-20261113T040115Z` sobre las activas.
- Pruebas antes del despliegue, en contenedor aislado sin red: 179 pasan; los 2 fallos
  son previos (`CompanyAdministrationTest`, `FiscalSetupProbeEndpointTest`; fallan
  igual en `9039d`). Las 4 pruebas nuevas pasan.

## Verificación

Salud 200 (interna y pública), `/api/invoice-drafts` y `/api/issued-documents` sin
token 401, `/` 200, `/api/internal/taxpayers/20131312955` pública sin token 401 y con
el bearer interno 200.

## Rollback

```bash
bash /opt/facturaya-ai/backups/releases/portal-empresas-20261113T040115Z/rollback.sh
```
