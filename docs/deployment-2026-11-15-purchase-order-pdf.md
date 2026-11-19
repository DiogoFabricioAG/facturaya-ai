# Despliegue del PDF de orden de compra (Reorder Cat)

Fecha: 15/11/2026, 17:04 UTC (12:04 hora de Lima). Servidor: alias SSH `meowlab`. Autorizado por el operador
(«Sí, despliégalo»). Decisión: ADR-0016 de MeowLab (Reorder Cat).

## Fuente

- Commit publicado `4377c8ebaabdeb28c9dcf62f92f9200f98fea48c` en `origin/feat/purchase-order-pdf`, sobre `b9316` (release anterior
  `collections-terms-20261115T011242Z`).
- Release `purchase-order-pdf-20261115T170430Z`. Stage desde `git archive`: `app/Services/InvoicePdfService.php`, `routes/api.php`
  (idénticos en el host a la base antes del cambio) y el nuevo `app/Http/Controllers/InternalPurchaseOrderPdfController.php`.
  `Dockerfile.release` copia también `routes/`.

## Alcance

`POST /api/internal/purchase-orders/pdf` (bearer interno, como la cotización) dibuja la orden de compra con la plantilla única:
número `OC-`, datos del proveedor, líneas con precio o «Por confirmar» y fecha de entrega. No guarda nada. Sin migraciones,
configuración ni secretos.

## Verificación

- Antes: PHPUnit 196 pruebas en contenedor sin red, con solo la falla previa de `FiscalSetupProbeEndpointTest`.
- Corte: salud local y pública 200; `/api/invoice-drafts`, `/api/issued-documents`, `/api/internal/quotes/pdf` y
  `/api/internal/purchase-orders/pdf` sin token 401; inicio 200.

## Rollback

```sh
bash /opt/facturaya-ai/backups/releases/purchase-order-pdf-20261115T170430Z/rollback.sh
```

Borra el controlador nuevo, restaura los dos archivos y vuelve a las imágenes anteriores.
