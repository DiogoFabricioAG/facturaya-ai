# Vista previa fiscal no emisora (FacturaYa)

Estado: **implementación aditiva; migración escrita pero no aplicada**. Complementa el contrato [SPEC-0002](https://github.com/meowlaboratoy/meowlab-specs/blob/main/specs/0002-contrato-fiscal-v1.md) y el plan R3. No emite, no contacta SUNAT y no consume correlativos.

## Ruta

`POST /api/fiscal-previews`, dentro del middleware `company.auth`. La empresa sale del token `fya_...`; el cuerpo no lleva `tenant_id`, `company_id` ni actor.

Archivo: `routes/api.php` → `FiscalPreviewController::store`.

## Request

Los importes viajan **solo como cadenas** (nunca números JSON). Escala máxima: 2 decimales en montos y 3 en cantidades; hasta 12 dígitos enteros.

```json
{
  "contract_version": "1",
  "operation_id": "01J9Z6...",
  "idempotency_key": "01J9Z6...",
  "draft_revision": 1,
  "document_type": "01",
  "issue_date": "2026-09-26",
  "currency": "PEN",
  "tax_mode": "included",
  "customer": {"document_type": "6", "number": "20123456789", "name": "ACME SAC"},
  "items": [
    {"description": "Servicio de mantenimiento", "quantity": "3", "total_price": "100.00"}
  ]
}
```

Reglas de cliente: factura exige tipo `6` con RUC de 11 dígitos; boleta admite `0` (sin número), `1` (DNI de 8) o `6` (RUC de 11); el nombre es obligatorio. `tax_mode` en `included`/`excluded`; moneda `PEN`.

`company_id` y `environment` no se aceptan del cuerpo: la empresa sale del token (`CompanyContext`) y el entorno siempre es `company.sunat_environment`. `tax_mode` lo envía el llamador autenticado con el token de empresa, después de verificar las preferencias del tenant; este servicio no lee ni escribe configuración fiscal y **la sincronización de esa configuración y su validación persistente sigue pendiente**. Tampoco cambia preferencias desde WhatsApp.

## Cálculo

`IgvCalculator::calculateItemFromTotal()` (extensión aditiva; el legado `calculateItem()` no cambia):

- `included`: el total de línea se conserva tal como llegó; base = total / 1.18 redondeada a 2; IGV = total − base.
- `excluded`: la base es el total recibido; IGV = base × 0.18; total = base + IGV.
- Unitarios derivados a 6 decimales para la respuesta; `calculation_version = 1`.
- Los montos de línea y los totales que viajan al adaptador no pueden exceder 12 dígitos enteros (igual que la validación de entrada); si una suma se sale, la solicitud se rechaza con 422 antes de persistir.

Ejemplo: cantidad 3, total S/ 100.00 incluye IGV → base 84.75, IGV 15.25, total 100.00 (sin usar un precio unitario intermedio redondeado a 2).

## Persistencia e idempotencia

Tabla `fiscal_previews` (migración `2026_09_26_000005`): empresa, operación, revisión, clave de idempotencia, entorno, versión de cálculo, hash canónico, digest `sha256:<64 hex>`, fecha, moneda, modo, payload/líneas/totales en JSON, vencimiento (900 s), `consumed_at` y `superseded_at`. Restricciones: única por `(company_id, operation_id, draft_revision)` y única por `(company_id, idempotency_key)`.

Cada solicitud abre una transacción, bloquea la fila de la empresa `FOR UPDATE`, revalida `active` y que `sunat_environment` siga siendo el mismo con el que se calculó, y recién entonces lee/bloquea la vista previa existente. Si la empresa cambió de estado o de entorno desde el cálculo, responde conflicto y no devuelve una vista previa del entorno anterior (por ejemplo, una `beta` tras rotar a `production`).

- Misma `operation_id` + `draft_revision` + `idempotency_key` con payload normalizado igual: devuelve la misma vista previa (replay, sin recalcular ni regenerar).
- Mismo triplete con payload distinto o clave distinta: `409 idempotency_conflict`.
- Clave de idempotencia ya usada en otra operación o revisión: `409 idempotency_conflict` (la clave es única por empresa, no se reutiliza entre operaciones).
- Vista previa vencida (`valid_until <= ahora UTC`, comparación inclusiva): `409 preview_expired`; no se reutiliza en silencio ni se crea otra con la misma identidad (la persona debe generar una revisión nueva).
- Vista previa consumida: `409 preview_consumed`.
- Vista previa superseded, petición de una revisión anterior a otra existente o cambio de estado/entorno de la empresa desde el cálculo: `409 preview_digest_mismatch`.
- Nueva revisión: crea otra fila y marca `superseded_at` en las anteriores de la operación.
- `request_hash` vincula empresa, entorno, versión de cálculo y payload normalizado; el `preview_digest` añade operación, revisión, `idempotency_key`, líneas y totales. Cualquier rotación de entorno cambia la huella y fuerza conflicto en lugar de reciclar la vista previa anterior.

El digest canónico (`App\Support\CanonicalJson`) incluye empresa, entorno, versión de cálculo, operación, revisión, `idempotency_key`, tipo, fecha, moneda, modo, cliente, ítems, líneas y totales. No incluye timestamps.

## Respuesta

`201` con `{"data": {...}}`, eco de `operation_id`, `company_id`, `environment` y `draft_revision`, más `id`, `document_type`, `issue_date`, `currency`, `tax_mode`, `items` (descripción, cantidad, base, IGV, total, unitarios), `totals`, `preview_digest`, `preview_valid_until`, `previewed_at`, `calculation_version` y `warnings`. Es la forma que consume el adaptador Python de `meowlab-platform`.

## Errores

| HTTP | `code` | Caso |
| --- | --- | --- |
| 422 | `unsupported_contract_version` | `contract_version` distinto de `1` |
| 422 | `unsupported_document_type` | Nota de crédito (`07`) y cualquier tipo fuera de `01/03` |
| 422 | (estándar Laravel) | Forma, escalas, cliente, moneda o modo inválidos |
| 409 | `idempotency_conflict` | Misma identidad con payload o clave distintos; clave reutilizada en otra operación |
| 409 | `preview_expired` | Vista previa vencida (`<=` ahora UTC): no se regenera con la misma identidad |
| 409 | `preview_consumed` | La vista previa ya fue consumida |
| 409 | `preview_digest_mismatch` | Superseded, revisión vieja o cambio de estado/entorno desde el cálculo |
| 500 | `sunat_environment_invalid` | Empresa con entorno distinto de `beta`/`production` |

## Límites

- **No emite**: no usa `SunatGatewayManager`, Greenter, IA, correlativos ni tablas de emisión.
- **Paridad con la emisión real: PENDIENTE.** El controlador legado (`InvoiceController::store`) todavía no consume `fiscal_previews` ni usa `calculateItemFromTotal`; el nuevo cálculo no está integrado al flujo de emisión. Este corte solo prepara vistas previas no emisoras.
- **No aplica la regla de boleta anónima > S/ 700**: la vista previa no bloquea ni modifica la política de emisión; la brecha fiscal existente sigue registrada en SPEC-0001.
- **Nota de crédito**: no soportada en esta ruta hasta que exista contrato de proveedor para su vista previa.
- **Migración no aplicada**: el archivo existe, pero este corte no ejecuta migraciones ni pruebas.
- La empresa debe tener `sunat_environment` válido (`beta`/`production`). No existe `tax_mode` en `companies` (vive en `invoice_drafts`); el primer corte acepta el `tax_mode` del llamador autenticado y no valida contra una columna inexistente. Sincronizar la configuración fiscal del tenant y su validación persistente queda pendiente.
- El adaptador Python de MeowLab mapeará los `409` con códigos `preview_expired`, `preview_consumed` y `preview_digest_mismatch`; `idempotency_conflict` queda como conflicto genérico. Los campos de eco (`company_id`, `environment`, `draft_revision`) siguen pendientes de confirmación final antes de publicar.
