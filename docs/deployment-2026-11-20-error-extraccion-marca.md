# Despliegue 2026-11-20: error 422 de extracción legible y marca MeowLab en las vistas

- **Release:** `error-extraccion-marca-20261121T025707Z` (02:57 UTC del 21/11, 21:57 del 20/11 en Lima).
- **Commit publicado:** `bfe72` en `main`, merge commit de PR #6.
  Hash completo en `/opt/facturaya-ai/backups/releases/error-extraccion-marca-20261121T025707Z/commit.txt`.
- **Autorización:** el operador («Despliega lo de facturación»).
- **Base:** release `emisor-xml-pdf-20261121T010714Z`, commit `f1c05`.
  - Los cuatro archivos del alcance coincidían en el host, por sha256, con ese commit.
  - Entre `f1c05` y `bfe72` solo cambian estos archivos y `docker/caddy/Caddyfile.vps`.
  - `Caddyfile.vps` no se despliega: es la copia versionada del Caddyfile vivo (PR #5).

## Qué cambia

- **`InvoiceDraftController`:** cuando la extracción de un borrador falla, la API responde 422 con:
  - `data.error = {code, message}`, con códigos estables: `missing_items`, `missing_item_description`, `invalid_item_quantity`, `missing_item_price`, `invalid_currency`, `invalid_payment_terms` y `extraction_failed`;
  - un mensaje corto para la dueña;
  - `raw_extraction` guardado en el borrador;
  - un log `invoice_draft_extraction_validation_failed` con las reglas que fallaron, sin datos personales.
- **Vistas Blade** (`platform/companies`, `invoices/create` y `despatches/index`): «FacturaYa AI» pasa a «MeowLab · Invoice Cat».
- **Sin cambios** de esquema, rutas ni XML.
- **El lado del Worker** (meowlab PR #37: `safeMessage` lee `data.error` de códigos conocidos) **no está desplegado**.
  - El Worker de producción sale de una rama de Tender Cat sin fusionar.
  - Mientras tanto, el Worker sigue mostrando su mensaje genérico. La API nueva es compatible con él.

## Procedimiento

1. **Preparación de `stage/`:**
   - contiene el controlador y las tres vistas del commit publicado;
   - `scoped-files.txt` y `stage.sha256` los registran;
   - `source-before.tar.gz` guarda la copia del host.
2. **Imágenes:**
   - las imágenes en ejecución se etiquetaron `rollback-…`;
   - `Dockerfile.release` copia `stage/app` y, por primera vez, `stage/resources` sobre la imagen anterior;
   - la imagen web no cambia;
   - se comprobó que los cuatro archivos de la imagen nueva coinciden por sha256 con `stage/`.
3. **`cutover.sh`:** salud interna y pública 200; `invoice-drafts` e `issued-documents` sin token 401; índice 200; PDF interno de cotización y de orden de compra sin token 401. Resultado: `cutover_verified`.
4. **Verificación sin escribir datos:**
   - `/` muestra el título «Emitir comprobante · MeowLab» y `/guias`, «Guías de remisión · MeowLab»;
   - «facturaya» aparece 0 veces en el índice;
   - `extractionErrorPayload`, invocado por reflexión dentro del contenedor, devuelve `{"code":"missing_item_price","message":"Falta el precio de \"polos\"."}` para un precio faltante y `extraction_failed` para un error no de validación.

## Pruebas

Suite completa en contenedor temporal: 205 pruebas. Solo falla `CompanyAdministrationTest::test_visual_workspaces_are_available`, porque falta el manifiesto de Vite (tarea `facturaya-test-vite-manifest-20261120`).

## Rollback

```sh
bash /opt/facturaya-ai/backups/releases/error-extraccion-marca-20261121T025707Z/rollback.sh
```
