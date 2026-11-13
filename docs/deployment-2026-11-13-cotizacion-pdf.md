# Despliegue del PDF de cotización de Sales Cat

Fecha: 13/11/2026, 20:48 UTC. Servidor: alias SSH `meowlab`. Autorizado por el operador («Sí, despliégalo»). Decisión:
ADR-0013 de MeowLab (plantilla única y cotización en PDF).

## Fuente

- Commit publicado `cd7fbe5ee9dbd053adb583451f1b633933965ca3` en `origin/feat/cotizacion-pdf`, sobre `8c343` (release anterior `sunat-qr-20261113T200717Z`).
- Release `cotizacion-pdf-20261113T204757Z`. Stage desde `git archive`: `app/Services/InvoicePdfService.php`,
  `app/Http/Controllers/InternalQuotePdfController.php` y `routes/api.php`. Antes del cambio, el generador y las rutas del
  host coincidían byte a byte con la base.

## Alcance

`POST /api/internal/quotes/pdf` con el bearer interno: dibuja la cotización con la plantilla de la factura, marcada «NO ES UN
COMPROBANTE DE PAGO», sin QR ni leyenda de SUNAT. FacturaYa no guarda nada; el bridge de MeowLab guarda el PDF. Sin migraciones,
assets, configuración ni secretos.

## Verificación

- Antes: PHPUnit 190/191 en contenedor sin red (la falla es la previa de `FiscalSetupProbeEndpointTest`).
- Corte: salud local y pública 200; `/api/invoice-drafts` y `/api/issued-documents` sin token 401; inicio 200; la ruta nueva sin
  token 401.
- Desde el bridge de producción con su token interno: cotización ficticia, HTTP 200, `application/pdf`, PDF válido (sin guardar).
- De punta a punta: la cotización N.° 1 de CREACIONES J&D se generó por `quote_pdf` y quedó guardada en el bridge.

## Rollback

```sh
bash /opt/facturaya-ai/backups/releases/cotizacion-pdf-20261113T204757Z/rollback.sh
```
