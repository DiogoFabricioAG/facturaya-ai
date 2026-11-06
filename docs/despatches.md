# Guía de remisión remitente: venta y transporte privado

Backend publicado el 06/11/2026 UTC en `https://facturas.meowlab.tech`, con migración aditiva aplicada. No se probaron emisiones reales ni se habilitaron credenciales GRE de empresas. Evidencia operativa y rollback: [despliegue crédito/GRE](deployment-2026-11-06-credit-gre.md).

Greenter incluye `Greenter\Api`, que usa la API REST GRE de SUNAT, distinta del SOAP de facturas. Fuentes: https://greenter.dev/production/ y https://cpe.sunat.gob.pe/sites/default/files/inline-files/Manual_Servicios_GRE.pdf.

## Crear y revisar

`POST /api/despatches`, token de empresa habitual:

```json
{
  "idempotency_key": "identificador-unico-de-esta-revision",
  "invoice_id": "ULID-de-factura-aceptada",
  "issue_date": "2026-11-05",
  "transfer_date": "2026-11-06",
  "origin": {"ubigeo": "150101", "address": "Dirección completa de partida"},
  "destination": {"ubigeo": "150132", "address": "Dirección completa de llegada"},
  "weight_kg": "25.500",
  "vehicle_category": "other",
  "vehicle": {"plate": "ABC123"},
  "driver": {"dni": "12345678", "first_name": "Nombre", "last_name": "Apellido", "license": "Q12345678"},
  "items": [{"description": "Bien transportado", "quantity": "4", "unit": "NIU"}]
}
```

Primer alcance explícito: GRE remitente `09`, venta `01`, transporte privado `02`, serie `T001`. Solo factura `01` aceptada, de la misma empresa/entorno, cliente con RUC. No incluye GRE transportista, transporte público, eventos, transbordos, remolques ni regímenes especiales. Las cantidades/unidades y el peso los confirma el usuario: no se inventan a partir de importes o del texto de la factura. No controla inventario ni cantidades acumuladas de otras guías.

Manolo pregunta primero la categoría según tarjeta: `vehicle_category` = `M1`, `L` u `other`. No la deduce de palabras como "auto" o "camioneta". Si el usuario no sabe, mantiene pendiente la clasificación y no ofrece emitir.

- `M1` o `L`: omitir `vehicle` y `driver` (también se admite null/vacío, normalizado a omisión). No se aceptan datos de placa/conductor no vacíos en esta representación simplificada: evita arrastrar datos de una selección anterior. El XML declara explícitamente `SUNAT_Envio_IndicadorTrasladoVehiculoM1L` y omite vehículo/conductor.
- `other`: un vehículo principal con placa y un chofer con DNI, nombres, apellidos y licencia, como en el ejemplo.
- Por compatibilidad, API sin `vehicle_category` conserva las exigencias ordinarias de vehículo/chofer; nunca infiere M1/L por datos ausentes. Los nuevos flujos conversacionales exigen categoría explícita.
- La categoría forma parte del payload y de su digest. Cambiarla crea una revisión con nueva clave y exige otro botón de confirmación. No cambia guías anteriores ni requiere migración adicional.

Referencia del indicador: Anexo V y estructura UBL de la [RS 123-2022/SUNAT](https://www.sunat.gob.pe/legislacion/superin/2022/anexo-123-2022.pdf). La aplicación registra la categoría declarada por el usuario; no verifica automáticamente la tarjeta del vehículo.

Fechas ISO completas; traslado no anterior a emisión; emisión no anterior a factura ni futura. Peso y cantidades son cadenas positivas con hasta 3 decimales y 9 dígitos enteros. Máximo 100 bienes; unidades del catálogo 03 de SUNAT definidas en `Despatch::UNITS` (`NIU`, `KGM`, `MTR`, `LTR`, `PR`, `BG`, `BX`, `PK`, `SET`, `DZN`, `CEN`, `C62`, `GLL`, `MTK`, `MTQ`, `TU`, `BJ`, `TNE`); placa 6 caracteres alfanuméricos mayúsculos sin guion. Ubigeo: 6 dígitos, sin consulta automática a INEI. No se deducen año, direcciones ni licencia.

Respuesta `{"data": {...}}`: `id`, `confirmation_digest`, `payload`, `status: draft`, `series`, `is_demo`, `document_type`, `reason_code`, `transport_mode`. El destinatario es el cliente de la factura. La misma clave y datos devuelve el mismo borrador; cambiar datos con la misma clave da 409. Las revisiones se crean con nueva clave; los borradores son inmutables.

## Confirmar y consultar

- Mostrar todos los datos, destinatario y ambiente, y pedir confirmación explícita mediante botón.
- `POST /api/despatches/{id}/issue` con `{"confirmed": true, "confirmation_digest": "hash-devuelto"}`.
- Respuesta normal inicial: `pending` con ticket; **no equivale a aceptación**. Nunca reenviar automáticamente por timeout ni crear otra guía para el mismo traslado como reintento.
- `POST /api/despatches/{id}/status` consulta el ticket; puede seguir `pending` o terminar en `accepted`/`rejected` con CDR almacenado. Un fallo de consulta preserva el ticket y permite consultar de nuevo, sin reenviar.
- `send_failed`: SUNAT rechazó la solicitud de envío (HTTP 4xx o fallo de autorización) y no la registró; `response_code` y `message` guardan su código y mensaje, sin valores parecidos a credenciales. Se corrige el dato y se prepara una guía nueva; el correlativo usado no se reutiliza.
- `unknown`: resultado de envío incierto (5xx, sin respuesta, sin ticket o "ya registrado"); también guarda el código y mensaje de SUNAT cuando los hay. Requiere revisión manual, sin reenvío. `preparation_failed`: fallo anterior al envío; revisión manual. `processing` tras interrupción tampoco autoriza reenviar.
- `GET /api/despatches/{id}`, `GET /api/despatches` paginado (20), `GET /api/despatches/{id}/files/xml|cdr`. Cada guía incluye `invoice_number` y `customer` (RUC y nombre del destinatario).
- `GET /api/despatch-invoices`: hasta 100 facturas que una guía puede referenciar (aceptadas, `01`, cliente RUC, entorno actual), con sus bienes (descripción y cantidad) para precargar y las guías que ya tienen.
- `is_demo: true` significa simulación local; nunca anunciarlo como aceptación SUNAT real.

## Pantalla web

`/guias` (enlazada desde la navegación) usa la misma clave de empresa que la emisión de comprobantes y no usa IA. Flujo: elegir la factura (precarga destinatario y bienes con unidad `NIU`, editables), fechas, categoría del vehículo (M1/L sin placa ni conductor; otra con placa y conductor), partida y llegada con ubigeo, peso y bienes con unidad del catálogo. "Revisar guía" crea el borrador con una clave de idempotencia nueva y muestra todo lo que se enviará; "Emitir guía" exige marcar la confirmación y envía el `confirmation_digest`. Corregir algo es volver a revisar: crea otro borrador y el anterior queda sin emitir. El historial muestra estado, mensaje de SUNAT, consulta de ticket y descarga de XML/CDR. La partida se precarga con la de la guía más reciente.

## Configuración

Solo administrador: `PUT /api/admin/companies/{company}/gre-credentials` con `gre_enabled` boolean y, al configurar, `gre_client_id`, `gre_client_secret`. En la primera habilitación se exige `next_number`: próximo correlativo de T001 en producción, confirmado por el administrador tras revisar las guías existentes; nunca se presume que sea 1. No puede retroceder frente al contador local. Se cifran las credenciales con el cast encrypted del proyecto y se ocultan de serialización; la respuesta solo indica booleanos. No transmitir esos secretos a WhatsApp, navegador de usuario o logs. Omitidos preservan; para borrar enviar null y deshabilitar. Cualquier actualización invalida borradores GRE de la configuración anterior.

Para envío real: empresa `greenter`, entorno `production`, habilitación GRE explícita, ambas credenciales API y certificado/SOL existentes. No se redirige beta a producción. La ruta no configura permisos SUNAT ni obtiene credenciales. Endpoints oficiales fijos, timeout y sin redirecciones.

## Límites pendientes de integración

No hay PDF/QR de traslado en este corte. XML/CDR descargables no sustituyen la representación/QR necesario para operar el traslado. Manolo debe exponer revisión y confirmación con estas limitaciones, sin afirmar disponibilidad de producción. Se requiere validar catálogo/ubigeos y casos especiales del vehículo antes de habilitar un emisor real. Cambiar credenciales durante una consulta pendiente puede requerir recuperación asistida. La migración es aditiva; no altera comprobantes existentes.
