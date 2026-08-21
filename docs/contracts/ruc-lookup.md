# Contrato de consulta de RUC para el Worker de WhatsApp

## Endpoint

`GET /api/customers/lookup/{ruc}`

La ruta vive dentro de `company.auth`, por lo que el Worker debe enviar el token privado de la empresa:

```http
Authorization: Bearer FACTURAYA_API_TOKEN
Accept: application/json
```

El Worker no envía ni recibe credenciales SOL. FacturaYa conserva esas credenciales únicamente para Greenter.

## Respuesta exitosa — `200 OK`

```json
{
  "data": {
    "id": "01j...",
    "ruc": "20557288016",
    "name": "AGU BELLO E.I.R.L.",
    "created_at": "2026-08-21T12:00:00+00:00"
  },
  "meta": {
    "source": "api_peru",
    "provider": "api_peru",
    "status": "ACTIVO",
    "condition": "HABIDO",
    "address": "LIMA - LIMA - SAN JUAN DE LURIGANCHO",
    "ubigeo": "150132"
  }
}
```

Valores permitidos para `meta.source` y `meta.provider`:

- `saved`: el cliente ya existía en la empresa.
- `cache`: el RUC ya estaba almacenado globalmente en FacturaYa.
- `api_peru`: resultado de ApiPeruDev.
- `openruc`: resultado de OpenRUC.

El Worker solo necesita `data.ruc` y `data.name`. Los demás campos son informativos y extensibles.

## Errores

### `401 Unauthorized`

El token de la empresa falta o no es válido.

```json
{
  "message": "Falta el token de la empresa."
}
```

### `422 Unprocessable Content`

El RUC no tiene exactamente 11 dígitos.

```json
{
  "message": "El RUC debe tener exactamente 11 dígitos."
}
```

### `404 Not Found`

Los dos proveedores consultados no encontraron el RUC.

```json
{
  "message": "No se encontró el RUC."
}
```

### `503 Service Unavailable`

ApiPeruDev y OpenRUC no estuvieron disponibles. El Worker puede informar al usuario que intente nuevamente.

```json
{
  "message": "El servicio de consulta RUC no está disponible temporalmente."
}
```

## Proveedores internos

FacturaYa consulta en este orden:

1. Cliente guardado dentro de la empresa.
2. Caché global de RUC.
3. ApiPeruDev, si `RUC_LOOKUP_API_PERU_TOKEN` está configurado.
4. OpenRUC como respaldo sin token.

Cada respuesta externa se guarda en `sunat_taxpayers` y se crea el cliente dentro de la empresa que hizo la consulta.

Variables del VPS:

```dotenv
RUC_LOOKUP_API_PERU_URL=https://api.apiperu.dev/ruc
RUC_LOOKUP_API_PERU_TOKEN=valor-secreto-en-el-VPS
RUC_LOOKUP_OPENRUC_URL=https://openruc.com/api/ruc
RUC_LOOKUP_CONNECT_TIMEOUT=3
RUC_LOOKUP_TIMEOUT=6
RUC_LOOKUP_CACHE_TTL=86400
```

El token de ApiPeruDev es único para la instalación o cuenta contratada; no se configura por usuario de WhatsApp ni se expone al navegador.
