# Despliegue 2026-11-19: PDF con la etiqueta del cliente según su documento y nombre comercial del emisor

- **Release:** `nombres-padron-pdf-20261119T055023Z`.
- **Commit publicado:** `74972b0ae0099199fe45e32543fb257eca3fe1a0` en `main`, fusión de PR #1 con merge commit. La fusión dejó `main` igual a lo que corre en producción.
- **Autorización:** el operador («Sí, despliega»).

## Qué cambia

- **`InvoicePdfService`:**
  - La etiqueta del cliente es «Razón social» solo para RUC 20, y «Nombre» para DNI y para RUC de persona natural.
  - Si el `trade_name` de la empresa es distinto de su `legal_name`, la cabecera muestra los dos nombres.
- **`CreditNotePdfService`:** el emisor figura como «NOMBRE O RAZON SOCIAL».
- **Sin cambios** de esquema, de rutas ni de XML.

## Procedimiento

1. En `stage/` quedaron los dos archivos del commit publicado. `scoped-files.txt` y `stage.sha256` registran cuáles son.
2. `source-before.tar.gz` guarda la copia del host anterior al cambio.
3. Las imágenes en ejecución se etiquetaron como `facturaya-ai-app:rollback-…` y `facturaya-ai-web:rollback-…`.
4. `Dockerfile.release` construye `facturaya-ai-app:nombres-padron-pdf-20261119T055023Z` sobre la imagen anterior, solo con `stage/app`. La imagen web no cambia.
5. Prueba previa dentro de la imagen nueva: `customerLabel` devuelve «Nombre» para RUC 10 y «Razón social» para RUC 20; `tradeName` devuelve el nombre comercial.
6. `cutover.sh` copia `stage/` al host, cambia `app` y `web`, ejecuta `optimize` y verifica:
   - salud interna y pública: 200;
   - `invoice-drafts` e `issued-documents` sin token: 401;
   - índice: 200;
   - PDF interno de cotización y de orden de compra sin token: 401.

   Resultado: `cutover_verified`.
7. Se generó en memoria, sin guardarla ni enviarla, la representación de F001-00000027 de CREACIONES J&D. La etiqueta del cliente (RUC 10) ahora es «Nombre».

## Pruebas

- `tests/Unit/FiscalPdfPaginationTest.php`: 6/6, incluidas 3 pruebas nuevas.
- La suite completa, corrida en un contenedor temporal, tiene las mismas 2 fallas que la base:
  - `CompanyAdministrationTest::test_visual_workspaces_are_available`, porque falta el manifiesto de Vite;
  - `FiscalSetupProbeEndpointTest`.
- La CI de GitHub no corrió: la cuenta tiene Actions bloqueado por facturación.

## Corrección de datos de CREACIONES J&D (05:57 UTC, autorizada por el operador)

- **Qué cambió:** en la empresa con RUC 10410461248, `legal_name` pasó de «CREACIONES J&D» al nombre registrado en el padrón para ese RUC. `trade_name` quedó en «CREACIONES J&D». Antes del cambio, los dos campos decían «CREACIONES J&D».
- **Cómo se aplicó:** con Eloquent (`forceFill` y `save`), solo si `legal_name` seguía siendo «CREACIONES J&D» y si el padrón devolvía ese mismo RUC.
- **Qué no cambió:** el nombre no forma parte del digest del perfil fiscal (RUC, usuario SOL y certificado), así que `profile_version` sigue igual.
- **Verificación:** la representación de F001-00000027, generada en memoria, muestra «CREACIONES J&D» arriba, el nombre legal debajo y «Nombre» en el cliente.
- **Revertir:** devolver `legal_name` a «CREACIONES J&D».

## Pendiente

- **Representación de comprobantes ya emitidos.** El PDF se arma con los datos actuales de la empresa, así que los comprobantes emitidos antes de esta corrección ahora muestran el nombre legal nuevo, mientras su XML firmado dice «CREACIONES J&D». Conviene que la representación de un comprobante emitido tome el nombre del emisor de su propio XML.
- **Factura de prueba.** Emitir una factura nueva para confirmar el nombre legal en el XML.

## Rollback

```sh
bash /opt/facturaya-ai/backups/releases/nombres-padron-pdf-20261119T055023Z/rollback.sh
```
