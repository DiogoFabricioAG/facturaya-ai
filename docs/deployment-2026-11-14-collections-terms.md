# Despliegue del saldo y las cuotas de las facturas a crédito (Collections Cat)

Fecha: 15/11/2026, 01:12 UTC (14/11, 20:12 hora de Lima). Servidor: alias SSH `meowlab`. Autorizado por el operador
(«Sí, despliégalo»). Decisión: ADR-0015 de MeowLab (Collections Cat).

## Fuente

- Commit publicado `ed529d12d7307ca32c63b39bdbddea6b31d61dd8` en `origin/feat/collections-terms`, sobre `da9e2` (release anterior
  `stock-items-20261113T222135Z`).
- Release `collections-terms-20261115T011242Z`. Stage desde `git archive`: `app/Http/Controllers/IssuedDocumentController.php`,
  idéntico en el host a la base antes del cambio.

## Alcance

`GET /api/issued-documents` agrega `outstanding_amount` (solo facturas a crédito) e `installments` (`due_date`, `amount`). Las notas de
crédito devuelven `null` y `[]`. Sin migraciones, configuración ni secretos.

## Verificación

- Antes: PHPUnit 193 pruebas en contenedor sin red, con solo la falla previa de `FiscalSetupProbeEndpointTest`.
- Corte: salud local y pública 200; `/api/invoice-drafts`, `/api/issued-documents` y `/api/internal/quotes/pdf` sin token 401; inicio 200.

## Rollback

```sh
bash /opt/facturaya-ai/backups/releases/collections-terms-20261115T011242Z/rollback.sh
```
