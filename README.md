# FacturaYa AI multiempresa

MVP de facturación electrónica peruana construido con Laravel 13, Greenter 5 y una capa opcional de IA para leer imágenes o PDF.

Una sola instalación puede administrar múltiples empresas emisoras. Cada empresa tiene aisladamente:

- RUC, razón social y domicilio fiscal;
- certificado digital y credenciales SOL cifradas;
- modo `fake` o transmisión mediante Greenter;
- entorno SUNAT beta o producción;
- serie y correlativos independientes;
- tokens de API, borradores, XML y CDR propios.

El RUC del cliente, fecha y productos se reciben en cada factura; no son variables de entorno.

## Flujo

1. El administrador registra una empresa y recibe un token una sola vez.
2. La empresa usa ese token como `Authorization: Bearer ...`.
3. Elige factura o boleta, escribe los productos en lenguaje natural o sube una cotización, lista o documento en PDF/imagen.
4. La capa de extracción produce el mismo JSON estructurado para cualquiera de las dos entradas.
5. Laravel recalcula importes e IGV sin delegar aritmética a la IA.
6. El usuario revisa y corrige cada línea.
7. El driver configurado para esa empresa genera una respuesta local o transmite a SUNAT con Greenter.
8. Desde una factura aceptada puede emitir notas de crédito totales o parciales, siempre vinculadas al comprobante original.

## Inicio rápido en Windows

Requisitos: PHP 8.3+, Composer 2 y Node.js 22+.

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

El seeder solo funciona fuera de producción y crea:

```text
Empresa: FACTURAYA DEMO S.A.C.
Token:   fya_demo_local_token
```

Abre `http://127.0.0.1:8000`, introduce ese token en “Espacio de empresa” y prueba el flujo. El modo inicial es `demo + fake`: no consume OpenAI ni contacta SUNAT.

## Interfaz visual

- `/`: acceso por clave de empresa, creación de facturas o boletas, carga del documento, selección de IGV, revisión editable, emisión y actividad reciente.
- `/platform`: administración visual de emisores. Usa `PLATFORM_ADMIN_TOKEN` para listar empresas y registrar una nueva en modo simulación o Greenter.

El panel de plataforma muestra el token inicial una sola vez después de registrar la empresa. Las claves de empresa y administración se conservan en `sessionStorage`, por lo que desaparecen al cerrar la sesión del navegador y no se insertan en el HTML generado por Laravel.

## Variables globales y secretos

Los datos de empresas y las claves SOL no están en `.env`. En desarrollo local Laravel puede leer estas variables desde `.env`; en el despliegue Docker los valores sensibles se montan como archivos de solo lectura en `/run/secrets`.

```dotenv
APP_KEY=base64:...
PLATFORM_ADMIN_TOKEN=un-secreto-largo-y-aleatorio

AI_DOCUMENT_DRIVER=demo
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.4
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_TIMEOUT=120
OPENAI_CA_BUNDLE=

SUNAT_DEFAULT_DRIVER=fake
SUNAT_DEFAULT_ENVIRONMENT=beta
```

- `APP_KEY` cifra las credenciales SOL y certificados almacenados. No la cambies sin realizar una migración de secretos.
- `PLATFORM_ADMIN_TOKEN` protege la creación y administración de empresas. Usa al menos 32 bytes aleatorios.
- `SUNAT_DEFAULT_*` solo determina valores por defecto al crear una empresa; cada empresa guarda su configuración real.
- `OPENAI_API_KEY` es global porque la plataforma paga y controla la extracción. Se puede separar por empresa en una etapa posterior si fuera necesario.

En producción, `.env.production` solo contiene configuración no sensible. Los archivos `secrets/app_key`, `secrets/db_password`, `secrets/platform_admin_token` y `secrets/openai_api_key` están ignorados tanto por Git como por el contexto de construcción de Docker. Cada contenedor recibe únicamente los secretos que necesita. Esta disposición sigue la recomendación de [Docker Compose Secrets](https://docs.docker.com/compose/how-tos/use-secrets/) y mantiene la clave de OpenAI exclusivamente en el servidor, como indica la [documentación de autenticación de OpenAI](https://developers.openai.com/api/reference/overview#authentication).

## Registrar una empresa

Primero configura `PLATFORM_ADMIN_TOKEN`. Para una empresa en modo simulación:

```bash
curl -X POST http://localhost:8000/api/admin/companies \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TU_PLATFORM_ADMIN_TOKEN" \
  -d '{
    "ruc": "20666666666",
    "legal_name": "SERVICIOS ANDINOS S.A.C.",
    "trade_name": "Servicios Andinos",
    "ubigeo": "150101",
    "department": "LIMA",
    "province": "LIMA",
    "district": "LIMA",
    "address": "Av. Principal 123",
    "sunat_driver": "fake",
    "sunat_environment": "beta",
    "default_series": "F001",
    "default_credit_note_series": "FC01",
    "default_boleta_series": "B001",
    "default_boleta_credit_note_series": "BC01",
    "token_name": "Sistema principal"
  }'
```

La respuesta contiene `api_token`. Se muestra una sola vez y debe entregarse únicamente a esa empresa.

Para activar Greenter desde el registro:

```bash
curl -X POST http://localhost:8000/api/admin/companies \
  -H "Accept: application/json" \
  -H "Authorization: Bearer TU_PLATFORM_ADMIN_TOKEN" \
  -F "ruc=20666666666" \
  -F "legal_name=SERVICIOS ANDINOS S.A.C." \
  -F "ubigeo=150101" \
  -F "department=LIMA" \
  -F "province=LIMA" \
  -F "district=LIMA" \
  -F "address=Av. Principal 123" \
  -F "sunat_driver=greenter" \
  -F "sunat_environment=beta" \
  -F "sol_user=USUARIOSECUNDARIO" \
  -F "sol_password=CLAVE_SOL" \
  -F "certificate=@certificado.p12" \
  -F "certificate_password=CONTRASENA_DEL_CERTIFICADO" \
  -F "default_series=F001" \
  -F "default_credit_note_series=FC01" \
  -F "default_boleta_series=B001" \
  -F "default_boleta_credit_note_series=BC01"
```

Acepta el archivo original `.p12` o `.pfx` descargado de SUNAT o entregado por el proveedor del certificado. La aplicación lo abre en memoria, valida que incluya una clave privada correspondiente y que esté vigente, lo convierte internamente al PEM requerido por Greenter y cifra el resultado antes de guardarlo. `certificate_password` solo se utiliza durante esa conversión y no se almacena. Primero valida contra `beta`; cambia una empresa a `production` únicamente después de comprobar firma, credenciales, serie y respuestas CDR.

## Consumir como empresa

Todas las rutas tributarias requieren el token de esa empresa:

Con productos escritos por una persona:

```bash
curl -X POST http://localhost:8000/api/invoice-drafts/import \
  -H "Accept: application/json" \
  -H "Authorization: Bearer fya_TOKEN_DE_LA_EMPRESA" \
  -F "document_type=01" \
  -F "customer_document_type=6" \
  -F "customer_ruc=20123456789" \
  -F "customer_name=COMERCIAL ANDINA S.A.C." \
  -F "issue_date=2026-08-15" \
  -F "tax_mode=included" \
  -F "products_text=Vendí 2 laptops a S/ 2500 cada una y 3 licencias a S/ 120"
```

Con una imagen o PDF:

```bash
curl -X POST http://localhost:8000/api/invoice-drafts/import \
  -H "Accept: application/json" \
  -H "Authorization: Bearer fya_TOKEN_DE_LA_EMPRESA" \
  -F "document_type=03" \
  -F "customer_document_type=1" \
  -F "customer_ruc=12345678" \
  -F "customer_name=COMERCIAL ANDINA S.A.C." \
  -F "issue_date=2026-08-15" \
  -F "tax_mode=included" \
  -F "file=@cotizacion.pdf"
```

También se pueden enviar ambos campos en la misma solicitud. El texto complementa el archivo o corrige explícitamente sus datos, y la extracción devuelve una sola lista sin duplicar conceptos:

```bash
curl -X POST http://localhost:8000/api/invoice-drafts/import \
  -H "Accept: application/json" \
  -H "Authorization: Bearer fya_TOKEN_DE_LA_EMPRESA" \
  -F "customer_ruc=20123456789" \
  -F "customer_name=COMERCIAL ANDINA S.A.C." \
  -F "issue_date=2026-08-15" \
  -F "tax_mode=included" \
  -F "products_text=Agrega 3 meses de soporte a S/ 250 y no dupliques los productos del archivo" \
  -F "file=@cotizacion.pdf"
```

Para una boleta usa `document_type=03`. El cliente puede identificarse con `customer_document_type=1` y un DNI de 8 dígitos, o con `customer_document_type=6` y un RUC de 11 dígitos. La serie de boleta se toma de `default_boleta_series` (por defecto `B001`). Las facturas usan `01` y las series `F` configuradas.

Modalidades tributarias:

- `included`: el precio ya incluye IGV; base = total / 1.18.
- `excluded`: el precio es base; IGV = base × 0.18.

## API

### Administración de plataforma

Requieren `PLATFORM_ADMIN_TOKEN`.

| Método | Ruta | Uso |
|---|---|---|
| `GET` | `/api/admin/companies` | Lista empresas |
| `POST` | `/api/admin/companies` | Registra empresa y crea token inicial |
| `GET` | `/api/admin/companies/{id}` | Consulta configuración no sensible |
| `PUT/PATCH` | `/api/admin/companies/{id}` | Actualiza empresa o credenciales |
| `POST` | `/api/admin/companies/{id}/tokens` | Crea otro token |
| `DELETE` | `/api/admin/companies/{id}/tokens/{tokenId}` | Revoca token |

### Operación de empresa

Requieren un token `fya_...`.

| Método | Ruta | Uso |
|---|---|---|
| `GET` | `/api/company` | Verifica token y obtiene empresa activa |
| `GET` | `/api/customers/lookup/{ruc}` | Obtiene la razón social desde clientes guardados, caché o proveedores RUC |
| `POST` | `/api/invoice-drafts/import` | Sube archivo y crea borrador |
| `GET` | `/api/invoice-drafts/{id}` | Obtiene borrador y cálculo |
| `PUT` | `/api/invoice-drafts/{id}` | Corrige líneas y recalcula |
| `POST` | `/api/invoice-drafts/{id}/issue` | Emite una sola vez |
| `GET` | `/api/invoices/{id}/files/xml` | Descarga XML propio |
| `GET` | `/api/invoices/{id}/files/cdr` | Descarga CDR propio |
| `GET` | `/api/invoices/{id}/credit-notes` | Lista notas vinculadas |
| `POST` | `/api/invoices/{id}/credit-notes` | Emite una nota de crédito |
| `GET` | `/api/credit-notes/{id}/files/{xml|cdr}` | Descarga archivos de la nota |

Un token de la empresa A obtiene `404` al intentar acceder a recursos de la empresa B. Las dos pueden usar `F001-00000001` porque los correlativos y restricciones son por empresa.

La consulta automática de RUC está documentada en [`docs/contracts/ruc-lookup.md`](docs/contracts/ruc-lookup.md). FacturaYa usa el cliente guardado primero, ApiPeruDev como proveedor principal y OpenRUC como respaldo. El token de ApiPeruDev vive únicamente en el VPS.

## Extracción real con OpenAI

```dotenv
AI_DOCUMENT_DRIVER=openai
OPENAI_MODEL=gpt-5.4
```

En Docker, `OPENAI_API_KEY` se coloca mediante `deploy/bootstrap-secrets.sh`; no se escribe en `.env.production`.

La implementación usa la Responses API con texto, imagen o PDF y un esquema JSON estricto. Las respuestas se solicitan con `store: false`. Los PDF se suben temporalmente con propósito `user_data` y la aplicación intenta eliminarlos al terminar. `OPENAI_CA_BUNDLE` solo es necesario si el PHP local no encuentra el almacén de certificados raíz; en Docker se instala `ca-certificates`. Referencias oficiales: [entradas de archivos](https://developers.openai.com/api/docs/guides/file-inputs), [salidas estructuradas](https://developers.openai.com/api/docs/guides/structured-outputs) y [capacidades de GPT-5.4](https://developers.openai.com/api/docs/models/gpt-5.4).

La IA solo transcribe. La validación de cantidades, precios y cálculo del IGV ocurre posteriormente en Laravel y requiere revisión humana.

## Docker/VPS

Para un VPS propio se mantiene el código en el repositorio privado y se ejecuta con Docker Compose. El archivo `compose.vps.yaml` agrega Caddy delante de la aplicación: publica únicamente 80/443 y obtiene/renueva HTTPS automáticamente cuando el dominio ya apunta al servidor.

Requisitos del servidor: Linux, Docker Engine con el plugin Compose, Git, OpenSSL, un dominio apuntando al VPS y los puertos 80/443 disponibles para el proxy HTTPS.

Primera instalación en el servidor. El repositorio privado debe clonarse con una llave SSH o Deploy Key de solo lectura; no incluyas un token de GitHub en la URL:

```bash
git clone git@github.com:DiogoFabricioAG/facturaya-ai.git
cd facturaya-ai

# 1. Instala Docker Engine/Compose desde el repositorio APT oficial.
./deploy/vps.sh prepare

# Cierra la sesión SSH y vuelve a entrar para aplicar el grupo docker.

# 2. Solicita dominio, correo y OpenAI; las entradas sensibles se ocultan.
./deploy/vps.sh configure

# 3. Construye, migra y espera hasta verificar el HTTPS público.
./deploy/vps.sh deploy
```

`prepare` admite Ubuntu y Debian oficiales. No modifica SSH ni activa/desactiva el firewall. La instalación sigue el [repositorio APT oficial de Docker](https://docs.docker.com/engine/install/ubuntu/); no utiliza el script rápido que Docker reserva para desarrollo. Caddy se comunica con Nginx por la red privada de Compose y usa su [HTTPS automático](https://caddyserver.com/docs/automatic-https). La aplicación también permanece disponible en `127.0.0.1:8080` exclusivamente para diagnósticos locales.

No pegues en GitHub, el chat ni comandos con historial la clave OpenAI, Clave SOL, contraseña del certificado, `APP_KEY`, contraseña PostgreSQL o tokens. El `.p12/.pfx`, su contraseña y las credenciales SOL se ingresan directamente en `/platform` únicamente después de habilitar HTTPS. La contraseña del certificado se usa en memoria y no se guarda; el PEM y la Clave SOL quedan cifrados con `APP_KEY` en el almacenamiento persistente.

Operación habitual:

```bash
# Estado de contenedores, endpoint interno y endpoint HTTPS
./deploy/vps.sh status

# Logs recientes o seguimiento en vivo
./deploy/vps.sh logs all
./deploy/vps.sh logs app --follow

# Recupera el token de /platform solo dentro de la sesión SSH
./deploy/vps.sh admin-token

# Actualización segura: exige un Git limpio, hace pull --ff-only y despliega
./deploy/vps.sh update

# PostgreSQL + XML/CDR + secretos, cifrados con una contraseña independiente
./deploy/vps.sh backup
```

El respaldo se guarda como `backups/facturaya-FECHA.tar.gz.enc`, con permisos `600`, y se verifica inmediatamente. Debe copiarse fuera del VPS. No pierdas ni la contraseña del respaldo ni `APP_KEY`.

Antes de producción:

- conserva una copia offline cifrada de `secrets/app_key`; sin ella no se pueden descifrar los certificados y claves SOL;
- respalda juntos los volúmenes `postgres_data`, `app_storage` y la `APP_KEY` correspondiente;
- deja `APP_DEBUG=false`;
- rota cualquier clave que haya sido usada durante desarrollo;
- prueba primero una empresa contra SUNAT Beta;
- configura un dominio, HTTPS y un monitor sobre `/api/health`.

### Prueba SUNAT controlada

1. En `/platform`, registra el emisor con driver `greenter` y entorno `beta`.
2. Para Beta utiliza el RUC del emisor y las credenciales de prueba `MODDATOS`; el certificado no necesita estar registrado en SUNAT, pero la aplicación sí requiere un `.p12/.pfx` válido para firmar el XML.
3. Emite una factura de prueba y conserva XML, ZIP del CDR, código, descripción y observaciones. Un código CDR `0` significa aceptada.
4. Prueba también una nota de crédito sobre esa factura Beta.
5. Solo después sustituye las credenciales por el usuario SOL secundario real, confirma que el certificado esté habilitado/vigente y cambia el entorno a `production`.
6. En producción emite únicamente por una venta real: aunque sea de importe mínimo, ya es un comprobante tributario. Si los datos son incorrectos, corrige mediante la nota de crédito aplicable.

SUNAT describe Beta como un servicio exclusivo para validar estructuras de facturas y notas, sin necesidad de registrar el certificado: [pautas oficiales del servicio Beta](https://orientacion.sunat.gob.pe/12-pautas-servicio-beta). Los endpoints oficiales de Beta, producción y consulta figuran en [Servicios Web disponibles](https://cpe.sunat.gob.pe/sites/default/files/inline-files/servicios%20web%20disponibles%20%281%29.pdf).

## Seguridad pendiente antes de un SaaS público

El aislamiento por empresa y los tokens ya funcionan, pero sigue siendo un MVP. Antes de producción agrega:

- panel de usuarios con autenticación y roles;
- segundo factor para administración;
- rate limiting en administración, IA y emisión;
- rotación y expiración de tokens;
- antivirus y política de retención de archivos;
- bitácora inmutable de cambios y reintentos;
- un gestor de secretos externo para instalaciones de mayor escala;
- colas y control de reintentos ante indisponibilidad de SUNAT.

## Verificación

```bash
php artisan test
npm run build
vendor/bin/pint --test
```

La suite cubre fórmulas de IGV, autenticación, aislamiento entre empresas, correlativos independientes, cifrado de credenciales/certificado, revisión, emisión local, archivos generados e idempotencia.
