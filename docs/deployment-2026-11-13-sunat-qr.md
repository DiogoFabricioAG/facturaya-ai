# Despliegue del código QR de SUNAT en la representación impresa

Fecha: 13/11/2026, 20:08 UTC (15:08 en Lima). Servidor: alias SSH `meowlab`. Autorizado por el operador («Sí, despliégalo»).

## Fuente

- Commit publicado `8c343e16c470291252b413e7c737ccb8bd6ae822` en `origin/feat/sunat-qr`, sobre `f33ef` (base del release anterior, `portal-empresas-20261113T040115Z`).
- Release `sunat-qr-20261113T200717Z`. Stage desde `git archive` del commit: `app/Services/QrCodeEncoder.php`, `app/Services/SunatQrPayload.php`,
  `app/Services/InvoicePdfService.php` y `app/Services/CreditNotePdfService.php`. Antes del cambio, los dos generadores del host
  coincidían byte a byte con la base.
- Norma y diseño: `docs/representacion-impresa-qr.md`.

## Alcance

QR nivel Q en facturas, boletas y notas de crédito (descarga web y operaciones fiscales), con el contenido del anexo N.° 6,
numeral 6.4.3, leído del XML firmado. Sin migraciones, assets, configuración ni secretos. Los PDF de operaciones fiscales ya
guardados conservan el formato anterior; las descargas web se generan al momento con el QR.

## Imágenes

- Activas: `facturaya-ai-app:sunat-qr-20261113T200717Z` y `facturaya-ai-web:sunat-qr-20261113T200717Z`. La app copia el stage y regenera el autoload; web no cambia.
- Rollback: `facturaya-ai-app|web:rollback-sunat-qr-20261113T200717Z` (= `portal-empresas-20261113T040115Z`).

## Verificación

- Antes: PHPUnit 188/189 en contenedor sin red (la falla es la previa de `FiscalSetupProbeEndpointTest`); QR decodificados con
  OpenCV; los 48 XML de producción producen los 10 campos con formato válido (solo lectura, sin red).
- Imagen nueva sin red: sintaxis PHP de los cuatro archivos y autoload de `QrCodeEncoder`.
- Corte: salud local y pública 200; `/api/invoice-drafts` y `/api/issued-documents` sin token 401; inicio 200; app y web corriendo.
- PDF reales generados en producción sin guardarlos en la aplicación (F001-00000026, la última boleta B001 y la última nota de
  crédito aceptadas): los tres QR se leen con 10 campos, RUC del emisor, tipo, serie, número, montos, fecha y valor resumen
  correctos; en F001-00000026, IGV 183.06, total 1200.00 y el mismo valor resumen que su PDF anterior. Copias en `verify/`
  del directorio del release (permiso 700).

## Rollback

```sh
bash /opt/facturaya-ai/backups/releases/sunat-qr-20261113T200717Z/rollback.sh
```

Restaura imágenes y fuente del host. No hay esquema que revertir.
