# Despliegue 2026-11-20: el PDF de un comprobante emitido toma el emisor de su XML firmado

- **Release:** `emisor-xml-pdf-20261121T010714Z` (01:07 UTC del 21/11, 20:07 del 20/11 en Lima).
- **Commit publicado:** `f1c05` en `main`, merge commit de PR #3.
  Hash completo en `/opt/facturaya-ai/backups/releases/emisor-xml-pdf-20261121T010714Z/commit.txt`.
- **Autorización:** el operador («Sí, despliega»).
- **Base:** release `nombres-padron-pdf-20261119T055023Z`, commit `74972b0`. Antes de preparar la release se verificó que los dos archivos del host coinciden por sha256 con ese commit.

## Qué cambia

- **`InvoicePdfService::issuerFromXml()`:** lee del XML UBL firmado la razón social (`PartyLegalEntity/RegistrationName`) y el nombre comercial (`PartyName/Name`) del emisor. `render()` usa esos valores y solo vuelve a los datos actuales de la empresa si el XML no existe o no trae el emisor.
- **`CreditNotePdfService`:** usa el mismo método. Si hay nombre comercial, la cabecera lo muestra en «NOMBRE COMERCIAL:».
- Así, los comprobantes emitidos antes de la corrección del `legal_name` de CREACIONES J&D (acta del 19/11) se representan con el nombre que realmente se envió a SUNAT.
- **Sin cambios** de esquema, de rutas ni de XML.

## Procedimiento

1. **Preparación:**
   - `stage/` tiene `app/Services/InvoicePdfService.php` y `app/Services/CreditNotePdfService.php` del commit publicado. `scoped-files.txt` y `stage.sha256` los registran.
   - `source-before.tar.gz` guarda la copia del host.
   - Las imágenes en ejecución se etiquetaron `facturaya-ai-app:rollback-…` y `facturaya-ai-web:rollback-…`.
   - `Dockerfile.release` construye la imagen nueva sobre la anterior, solo con `stage/app`. La imagen web no cambia.
2. **Prueba previa en la imagen nueva:** `InvoicePdfService::issuerFromXml` existe con el autoloader cargado. `CreditNotePdfService` lo llama desde `InvoicePdfService`.
3. **`cutover.sh`:**
   - Comprobaciones:
     - salud interna y pública: 200;
     - `invoice-drafts` e `issued-documents` sin token: 401;
     - índice: 200;
     - PDF interno de cotización y de orden de compra sin token: 401.
   - Resultado: `cutover_verified`.
4. **Prueba funcional de solo lectura:** `issuerFromXml` aplicado al XML almacenado de F001-25, F001-26 y F001-27 devuelve `legal_name` «CREACIONES J&D», el nombre con que se emitieron. No se guardó ni se envió nada.

## Pruebas

- `tests/Unit/FiscalPdfIssuerFromXmlTest.php`: nueva.
- `FiscalSetupProbeEndpointTest` ahora pasa (espera `schema_version` 2 con certificado vinculado).
- La suite completa en un contenedor temporal solo falla en `CompanyAdministrationTest::test_visual_workspaces_are_available`, porque falta el manifiesto de Vite (falla conocida).
- La CI de GitHub no corrió: Actions está bloqueado por facturación de la cuenta.

## Pendiente

- **Factura de prueba.** Emitir una factura nueva para confirmar que el XML y el PDF salen con el nombre legal corregido.

## Rollback

```sh
bash /opt/facturaya-ai/backups/releases/emisor-xml-pdf-20261121T010714Z/rollback.sh
```
