# Condición de pago de facturas

Backend y web publicados el 06/11/2026 UTC en `https://facturas.meowlab.tech`, con migración aditiva aplicada. Flujo de borradores tradicional; no corrige facturas ya emitidas. Evidencia operativa y rollback: [despliegue crédito/GRE](deployment-2026-11-06-credit-gre.md).

`POST /api/invoice-drafts/import` y `PUT /api/invoice-drafts/{id}` admiten:

```json
{
  "payment_method": "credito",
  "outstanding_amount": "12630.00",
  "installments": [{ "due_date": "2026-12-05", "amount": "12630.00" }]
}
```

Los mismos campos se devuelven en `InvoiceDraftResource`. En multipart se usan `installments[0][due_date]` y `installments[0][amount]`. Moneda: `currency` del borrador; no se convierte moneda ni se admite otra moneda por cuota.

- `contado`: saldo `null`, cuotas `[]`. Por compatibilidad, una importación antigua sin campos sigue siendo contado. La web exige seleccionar la condición; Manolo debe preguntarla cuando falte.
- `credito`: solo factura `01` en este corte; saldo positivo no mayor al total; entre 1 y 12 cuotas positivas, ordenadas por vencimiento, sin fechas repetidas y posteriores a la emisión. La suma decimal exacta debe coincidir con el saldo.
- Saldo y montos se envían como cadenas decimales, hasta dos decimales. No se infieren anticipos, detracciones, retenciones ni vencimientos desde el texto de productos. El usuario confirma el saldo neto pendiente real.
- Un PUT sin campos de pago conserva los anteriores. Para pasar a contado debe enviar explícitamente `payment_method: contado`, `outstanding_amount: null`, `installments: []`.
- Una edición inválida revierte metadatos y partidas en la misma transacción. Se vuelve a validar antes de asignar un correlativo y antes de construir el XML Greenter.
- Greenter genera `Credito`, saldo y `Cuota001...` con moneda/fecha. El PDF descargable añade una página con el cronograma. El XML de simulación es demostrativo y no es UBL fiscal.
- La migración aditiva asigna contado a datos antiguos; no modifica XML, CDR ni facturas aceptadas. No implementa nota tipo 13 ni regularización de comprobantes anteriores.
- No extender estas condiciones a `/fiscal-previews` o `/fiscal-operations/issue`: son contratos separados, fuera de este corte.

Pruebas: `tests/Feature/InvoicePaymentTermsTest.php`, SQLite en memoria, extracción simulada, XML real construido localmente sin firma ni envío a SUNAT. Incluyen cuota única, anticipo y dos cuotas, persistencia, validación de montos/fechas, rollback y bloqueo previo al correlativo.
