# Despliegue de los productos por comprobante (Stock Cat)

Fecha: 13/11/2026, 22:21 UTC. Servidor: alias SSH `meowlab`. Autorizado por el operador («Sí, despliégalo»). Decisión: ADR-0014 de
MeowLab (Stock Cat).

## Fuente

- Commit publicado `065c4342fdef30a845dd97eab12573403755ea9f` en `origin/feat/stock-items`, sobre `cd7fb` (release anterior `cotizacion-pdf-20261113T204757Z`).
- Release `stock-items-20261113T222135Z`. Stage desde `git archive`: `app/Http/Controllers/IssuedDocumentController.php`, idéntico en el host a la base
  antes del cambio.

## Alcance

`GET /api/issued-documents?with_items=1` agrega descripción y cantidad de cada línea de facturas, boletas y notas de crédito. Sin el
parámetro, la respuesta no cambia. Sin migraciones, configuración ni secretos.

## Verificación

- Antes: PHPUnit 192 pruebas en contenedor sin red, con solo la falla previa de `FiscalSetupProbeEndpointTest`.
- Corte: salud local y pública 200; `/api/invoice-drafts`, `/api/issued-documents` y `/api/internal/quotes/pdf` sin token 401; inicio 200.

## Rollback

```sh
bash /opt/facturaya-ai/backups/releases/stock-items-20261113T222135Z/rollback.sh
```
