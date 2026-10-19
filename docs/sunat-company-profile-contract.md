# Contrato de perfil empresarial y sincronización versionada (SUNAT)

- Fecha: 2026-10-08. Responsable: OpenCode SUNAT (cierre operativo).
- Propiedad: este documento, los DTO/servicio puro previos
  (`app/Data/CompanyProfile{Snapshot,Binding,SyncDecision,SyncReceipt}.php`,
  `app/Data/CompanyFiscalConfigResult.php`,
  `app/Services/CompanyProfileVersionService.php`), la implementación durable
  (`database/migrations/2026_10_08_000001_add_company_profile_binding.php`,
  `app/Models/CompanyProfile{Binding,Version,SyncReceipt}.php`,
  `app/Services/CompanyProfileSyncService.php`,
  `app/Http/Controllers/CompanyProfileController.php`,
  `app/Http/Middleware/RequireCompanyProfileBridge.php`,
  `config/company_profile.php`), ajustes en `Company`,
  `CompanyController`, `CompanyCertificateStore` y `routes/api.php`, y las
  pruebas `tests/Unit/CompanyProfileVersionServiceTest.php`,
  `tests/Feature/CompanyProfileEndpointTest.php`,
  `tests/Support/CompanyCertificateFixture.php`.
- Estado: **implementado y validado funcionalmente con pruebas en Docker PHP
  8.4** (detalle en §7). La migración está **preparada y no aplicada**; no se
  tocó el endpoint diagnóstico ni su middleware (`Sunat/*`,
  `FiscalSetupProbeController`, `RequireFiscalDiagnosticsBridge`), ni Sunat
  real, ni bases existentes, ni commit/push/deploy.
- El POST `/sync` es un gancho interno para persistir el binding y **queda
  pendiente de confirmación de rutas** con coordinación; el GET sigue
  exactamente el contrato acordado (`X-MeowLab-Tenant-ID` + bearer dedicado).

## 1. Problema

1. La plataforma identifica al tenant por UUID y a la empresa del proveedor por
   `company_ref` (`facturaya_preview_adapter.py:131-186`), pero no existe un
   vínculo durable ni una versión de perfil: `TenantFiscalConfig` no guarda
   `company_ref` y `companies` no expone versión.
2. Cualquier rotación en `/platform` (`sol_user`, `sol_password`, certificado,
   RUC o ambiente) puede invalidar la evidencia previa sin que la plataforma lo
   detecte (`CompanyController::update`, `app/Http/Controllers/CompanyController.php:62-86`).
3. El endpoint diagnóstico actual es stateless y devuelve un eco del request
   (`docs/sunat-diagnostic-contract-review.md` §2 H6): no sirve como evidencia
   independiente de que el perfil validado sea el que emitirá.

## 2. Evidencia inspeccionada (solo lectura)

| Fuente | Hallazgo relevante |
| --- | --- |
| `app/Models/Company.php:10-29,61-67` | ULID como PK (`HasUlids`), `sol_user`/`sol_password` con cast `encrypted`, `certificate_path`; `hasSunatCredentials()` solo comprueba presencia. |
| `database/migrations/2026_08_15_000002_add_multi_company_support.php:13-36` | `companies.ruc` único, SSOL en texto cifrado, `company_api_tokens.token_hash`; sin columnas de versión ni referencia externa. |
| `app/Http/Controllers/CompanyController.php:62-86` | La actualización admin guarda credenciales/certificado sin bump de versión, sin auditoría de perfil ni notificación. |
| `app/Http/Requests/UpdateCompanyRequest.php` | Campos fiscales mutables: `ruc`, `sunat_environment`, `sol_user`, `sol_password`, `certificate`, `certificate_password`. |
| `app/Http/Controllers/FiscalSetupProbeController.php` | Respuesta `pending` con binding eco del request; intacta en esta ronda. |
| `docs/sunat-diagnostic-contract-review.md` (plataforma) | §2 H6: el binding eco no es atestación; §9 seguimiento H1/H4. |
| `src/meowlab_platform/facturaya_preview_adapter.py:131-186` | `ProviderCredentials(base_url, company_ref, api_token, environment)` y `CredentialProvider.credentials_for(tenant_id)`; sin versión de perfil. |
| `src/meowlab_platform/facturaya_artifact_storage.py:447-456,550-551` | Hook opcional `company_validator(tenant, company_ref)` para atar artefactos a la empresa. |

## 3. Contrato propuesto

### 3.1 Identidad

- `tenant_id`: UUID canónico (plataforma).
- `company_ref`: ULID de `companies.id` (Crockford base32, 26 caracteres).
- `environment`: `beta` | `production`, debe coincidir con `sunat_environment`.
- Un tenant solo puede estar vinculado a un `company_ref` y ambiente a la vez.

### 3.2 Fotografía de perfil (`CompanyProfileSnapshot`)

| Campo | Regla |
| --- | --- |
| `company_ref` | ULID canónico. |
| `environment` | `beta`/`production`. |
| `profile_version` | Entero 1..1 000 000, monotónico por empresa. |
| `fiscal_digest` | SHA-256 hex de la carga canónica (abajo). |
| `certificate_sha256` | SHA-256 hex del **DER del certificado público** (parte X.509); nunca del contenedor PKCS#12 ni de la contraseña, para no exponer un valor dependiente de un secreto de baja entropía. |
| `ruc` | 11 dígitos. |
| `sol_username_masked` | Usuario SOL enmascarado (`MO***S`); nunca el valor completo. |
| `captured_at` | ISO-8601 UTC. |

Carga canónica del digest (claves fijas, sin contraseñas):
`certificate_sha256`, `company_ref`, `environment`, `fiscal_revision`, `ruc`,
`sol_username`.

`fiscal_revision` es un contador server-side que sube con **cualquier** escritura
fiscal, incluida la recarga de un certificado con nueva
`certificate_password`. Como el DER público no cambia al rotar la frase de paso,
esa rotación se detecta por la revisión: el digest cambia y la evidencia previa
queda inválida sin exponer ningún hash de secreto.

`toArray()` no incluye `sol_username` completo, contraseñas, frases de paso ni
bytes del certificado.

### 3.3 Binding independiente (`CompanyProfileBinding`)

`tenant_id`, `company_ref`, `environment`, `bound_profile_version`,
`bound_digest`, `bound_at`. `matches(snapshot)` exige coincidencia exacta de
empresa, ambiente, versión y digest. La fotografía se calcula desde la fila
`companies` almacenada; el binding se persiste en la plataforma con la evidencia
validada y **nunca** se completa con el eco de una request.

### 3.4 Versión, CAS e idempotencia

- `profile_version` avanza solo cuando el `fiscal_digest` cambia.
- **Techo `MAX_PROFILE_VERSION = 1_000_000`**: acota la columna entera sin signo
  y los payloads. Las versiones solo suben con cambios fiscales reales (poco
  frecuentes), así que un millón excede cualquier vida operativa razonable; al
  alcanzarlo el servicio lanza `company_profile_version_overflow` y exige
  intervención en vez de desbordar.
- Sincronización con CAS: el llamador envía `expected_profile_version`
  (0 para creación). Si el digest difiere y la versión esperada no coincide con
  la vigente, se responde `version_conflict` sin mutar nada.
- **`unchanged` con expected obsoleto, explícito**: si el digest ya coincide no
  hay mutación posible; devolver conflicto forzaría resincronizaciones inútiles.
  Se responde `unchanged` con `reason = no_change_ignores_expected_version`
  cuando `expected_profile_version` no coincide, y con `reason = null` cuando sí
  coincide. El cliente puede refrescar su versión desde la respuesta.
- Idempotencia: `idempotency_key` (1..128, `[A-Za-z0-9._-]`) + huella de la
  carga. Repetir la misma clave con la misma huella devuelve `replayed` con el
  resultado original; con huella distinta devuelve `idempotency_conflict`.
- Estados de `CompanyProfileSyncDecision`: `created`, `unchanged`, `updated`,
  `replayed`, `version_conflict`, `idempotency_conflict` (razón opaca).

### 3.5 Invalidación de evidencia

`isEvidenceCurrent(binding, snapshot)` es falso ante cualquier cambio de versión
o digest (certificado, usuario, RUC, ambiente o `fiscal_revision`). La compuerta
de despacho debe fallar cerrado a `pending` y exigir revalidación; nunca
"reutilizar" evidencia de una versión anterior.

## 4. Componentes implementados

| Archivo | Rol |
| --- | --- |
| `app/Data/CompanyProfileSnapshot.php` | DTO validado y salida pública sin secretos. |
| `app/Data/CompanyProfileBinding.php` | Vínculo tenant↔empresa/versión/digest y `matches()`. |
| `app/Data/CompanyProfileSyncDecision.php` | Decisión pura con códigos opacos (`binding_conflict`, `not_configured`, etc.). |
| `app/Data/CompanyProfileSyncReceipt.php` | Recibo de idempotencia. |
| `app/Services/CompanyProfileVersionService.php` | Digest/fingerprint canónicos, máscara SOL, binding, vigencia y CAS. Sin E/S. |
| `database/migrations/2026_10_08_000001_add_company_profile_binding.php` | Migración preparada (no aplicada): columnas de versión, auditoría, bindings y recibos. |
| `app/Models/CompanyProfile{Binding,Version,SyncReceipt}.php` | Modelos Eloquent de binding, auditoría y recibos. |
| `app/Services/CompanyProfileSyncService.php` | Persistencia atómica: `refresh()` (digest/versión/auditoría bajo lock) y `bind()` (CAS + idempotencia). |
| `app/Http/Controllers/CompanyProfileController.php` | GET interno con pertenencia validada, POST `/sync` de binding y POST `/fiscal-config` de credenciales M2M. |
| `app/Data/CompanyFiscalConfigResult.php` | Resultado del POST `fiscal-config`: snapshot+`config_version` o conflicto opaco. |
| `app/Http/Middleware/RequireCompanyProfileBridge.php` | Bearer M2M dedicado con 503/403/401 opacos (no toca el middleware de diagnóstico). |
| `config/company_profile.php` | `enabled`, `allow_local_http` y `bridge_token` con parseo estricto. |
| `Company`, `CompanyController`, `CompanyCertificateStore`, `routes/api.php` | Casts/relaciones, bump atómico de `fiscal_revision`, DER público, ruta opaca e inmutable por carga (`certificate-<ulid>.pem.enc`) y rutas internas. |
| `tests/Unit/CompanyProfileVersionServiceTest.php` | 14 pruebas sintéticas del contrato puro. |
| `tests/Feature/CompanyProfileEndpointTest.php` + `tests/Support/CompanyCertificateFixture.php` | 9 pruebas de endpoint, binding, CAS, idempotencia y rotación con certificado sintético. |

## 5. Integración

### 5.1 Migración Laravel (preparada, no aplicada)

La migración existe en
`database/migrations/2026_10_08_000001_add_company_profile_binding.php` y **no
se aplicó** en ninguna base. Motor verificado: `config/database.php:20` resuelve
`DB_CONNECTION` (local por defecto `sqlite`; `pgsql` definida para
producción/VPS) y las migraciones existentes usan el Schema builder portable
(`database/migrations/2026_08_15_000002_add_multi_company_support.php:11-36`:
`$table->ulid('id')->primary()`, `foreignUlid(...)->constrained()`,
`$table->timestamp(...)`). Contenido, sin SQL crudo de PostgreSQL:

```php
Schema::table('companies', function (Blueprint $table): void {
    $table->unsignedInteger('profile_version')->default(1);
    $table->unsignedInteger('fiscal_revision')->default(0);
    $table->timestamp('profile_updated_at')->nullable();
});

Schema::create('company_profile_versions', function (Blueprint $table): void {
    $table->id();
    $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('profile_version');
    $table->string('fiscal_digest', 64);
    $table->string('certificate_sha256', 64);
    $table->string('environment', 16);
    $table->string('ruc', 11);
    $table->timestamp('created_at')->useCurrent();

    $table->unique(['company_id', 'profile_version']);
});
```

Esquema aplicado por la migración (además de las columnas previas): auditoría
`company_profile_versions`, binding `company_profile_bindings`
(`unique(company_id)` y `unique(tenant_id, environment)`) y recibos
`company_profile_sync_receipts` (`unique(company_id, idempotency_key)`).

Bump implementado en `CompanyController::store/update` dentro de
`DB::transaction`: si cambia cualquier campo fiscal (`ruc`,
`sunat_environment`, `sol_user`, `sol_password`, `certificate_path`) o se
recarga el certificado **aunque solo cambie `certificate_password`**, se
incrementa `fiscal_revision` y `CompanyProfileSyncService::refresh()` recalcula
el snapshot, avanza `profile_version` si el digest cambió e inserta la versión
de auditoría. La recarga reutiliza el mismo `certificate_path`; el cambio de
frase de paso se captura por el evento (revisión), no por el DER público.

### 5.2 Rutas internas (implementadas)

Autenticación de ambas: `Authorization: Bearer <FISCAL_PROFILE_BRIDGE_TOKEN>`
(middleware propio `RequireCompanyProfileBridge`, deshabilitado salvo
`FISCAL_PROFILE_ENABLED=true`), HTTPS o loopback de desarrollo, errores opacos
`company_profile_disabled` (503), `https_required` (403), `unauthorized` (401),
`company_profile_bridge_not_configured` (503). No se toca el middleware de
diagnóstico ni `POST /api/fiscal-setup/probe`.

`GET /api/internal/company-profile/{company}` (ULID implícito) con header
obligatorio `X-MeowLab-Tenant-ID: <uuid>`; valida el UUID contra el binding
persistido y responde 404 `company_profile_not_bound` si no hay binding o el
tenant no coincide (mismo código, sin oráculo). `tenant_id` en la respuesta es
el **persistido**, nunca el header.

```json
{
  "data": {
    "tenant_id": "c1f2e3d4-b5a6-4789-8012-3456789abcde",
    "company_ref": "01J8ZK9M2Q7C3V6B4N5R8T1W0X",
    "environment": "production",
    "profile_version": 2,
    "fiscal_digest": "<sha256-hex>",
    "certificate_sha256": "<sha256-hex del DER público>",
    "ruc": "20111111111",
    "sol_username_masked": "MO***S",
    "captured_at": "2026-10-08T00:00:00+00:00"
  }
}
```

`POST /api/internal/company-profile/{company}/sync` (gancho interno **pendiente
de confirmar ruta**; no forma parte del contrato GET acordado) persiste/actualiza
el binding con CAS e idempotencia. Cuerpo máximo 8 KiB, claves exactas y tipos
estrictos:

```json
{
  "tenant_id": "c1f2e3d4-b5a6-4789-8012-3456789abcde",
  "expected_profile_version": 0,
  "idempotency_key": "sync-key-1",
  "environment": "production"
}
```

Respuesta 200 con `status` en `created|updated|unchanged|replayed`; 409
`version_conflict|idempotency_conflict|binding_conflict` (razón opaca, p. ej.
`company_already_bound_to_other_tenant`); 409 `not_configured` si falta
certificado/credenciales.

`POST /api/internal/company-profile/{company}/fiscal-config` (**ruta aceptada
técnicamente; contrato informado antes de conectar el cliente Python de
Codex**). Transfiere SOL y PKCS#12 servidor-a-servidor para el bootstrap del
tenant; nunca se usa desde el navegador. Límites: cuerpo ≤128 KiB, certificado
decodificado 64 B–64 KiB, `sol_password` ≤128 y passphrase ≤512 bytes. Claves
exactas:

```json
{
  "tenant_id": "c1f2e3d4-b5a6-4789-8012-3456789abcde",
  "config_version": 7,
  "ruc": "20111111111",
  "sol_username": "NUEVOUSER",
  "sol_password": "<efímera>",
  "certificate_b64": "<PKCS#12 base64 estricto>",
  "certificate_passphrase": "<efímera>",
  "expected_profile_version": 0,
  "idempotency_key": "fiscal-key-1",
  "environment": "production"
}
```

Respuesta 200: `data` es el **mismo snapshot del GET** más `config_version`
aplicado y el estado:

```json
{
  "data": {
    "tenant_id": "c1f2e3d4-b5a6-4789-8012-3456789abcde",
    "company_ref": "01J8ZK9M2Q7C3V6B4N5R8T1W0X",
    "environment": "production",
    "profile_version": 2,
    "fiscal_digest": "<sha256-hex>",
    "certificate_sha256": "<sha256-hex del DER público>",
    "ruc": "20111111111",
    "sol_username_masked": "NU***R",
    "captured_at": "2026-10-08T00:00:00+00:00",
    "config_version": 7,
    "status": "created",
    "replayed": false
  }
}
```

Reglas: el certificado se valida/descifra **antes** de mutar; SOL se guarda con
el cast cifrado existente y el PEM en ruta nueva; en una sola transacción se
actualiza `fiscal_revision`, se recalcula la versión, se persiste el binding
(con `config_version`) y el recibo. Se exige `ruc` exacto de la empresa,
ambiente coincidente y rechazo cross-tenant; un `config_version` menor al
persistido devuelve 409 `config_version_regressed`. El payload crudo no se
guarda ni registra: el recibo solo conserva un HMAC-SHA256 con `APP_KEY`.
Conflictos: 409 `binding_conflict|version_conflict|idempotency_conflict`;
certificado inválido: 422 `certificate_rejected` sin mutación.

### 5.3 Rotación de certificado y rollback

- Toda carga (admin `CompanyCertificateStore::store` y M2M
  `storeValidatedPem`) escribe un archivo **nuevo** con ruta opaca
  `company-certificates/{company}/certificate-<ulid>.pem.enc`; nunca se
  sobrescribe ni borra el archivo activo, que puede ser la versión v1.
- La conversión PKCS#12→PEM valida antes de escribir; un certificado o secreto
  inválido no crea archivos ni muta la fila.
- Si la transacción falla **después** de escribir (refresh/auditoría/DB), se
  descarta con `discard()` únicamente el archivo recién creado; el
  `certificate_path` y los bytes de la versión activa quedan intactos porque el
  rollback de la base nunca llegó a apuntar al archivo nuevo. Si el propio
  descarte fallara, el único residuo posible es el archivo nuevo huérfano: la
  versión activa jamás se pierde.
- Los archivos antiguos no se borran automáticamente (assets existentes
  preservados); una limpieza de huérfanos sería una tarea separada con
  verificación de referencias.

### 5.4 Plataforma (propuesta aditiva)

- Guardar el binding por tenant (tabla nueva, p. ej.
  `tenant_company_profile_bindings` con `unique(tenant_id)`) al validar, junto a
  `config_version`; `api_token`/`token_ref` fuera del cuerpo y nunca en browser.
- Extender de forma compatible `ProviderCredentials` con
  `company_profile_version: int = 0` y `profile_digest: str = ""` **o** añadir un
  protocolo `CompanyProfileBindingProvider`; no se editó
  `facturaya_preview_adapter.py` en esta ronda.
- La compuerta/validador relee la fotografía y exige `matches(binding)`; al
  fallar, `pending_validation` (fail-closed).

## 6. Compatibilidad revisada

- `docs/sunat-diagnostic-contract-review.md` (plataforma): H6 (binding eco) se
  resuelve con la lectura independiente de este contrato; H1/H4 quedan en su
  seguimiento y no se tocan aquí.
- `facturaya_preview_adapter.py:131-186`: la extensión propuesta es aditiva y
  no rompe `ProviderCredentials` ni `CredentialProvider`; `api_token` sigue
  siendo secreto solo de backend.
- Endpoint diagnóstico y middleware Bridge: intactos.

## 7. Pruebas ejecutadas (Docker PHP 8.4 aislado)

Comando real (imagen local, código montado, SQLite en memoria, sin `.env` de
producción):

```text
docker run --rm --entrypoint php -v "D:\Proyectos2026\facturaya-ai:/app" -w /app \
  -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
  -e APP_KEY=base64:<clave-dummy-de-pruebas> \
  facturaya-ai-test:latest vendor/bin/phpunit <archivo> --colors=never
```

Resultados reales:

| Suite | Resultado |
| --- | --- |
| `tests/Unit/CompanyProfileVersionServiceTest.php` | **OK (14 tests, 50 assertions)** |
| `tests/Feature/CompanyProfileEndpointTest.php` | **OK (15 tests, 83 assertions)** |
| `tests/Feature/CompanyCertificateRotationTest.php` | **OK (4 tests, 20 assertions)** |
| Suite completa (`vendor/bin/phpunit`) | **OK (113 tests, 2965 assertions)** |

Cobertura de las pruebas de perfil: snapshot determinista y máscara SOL; cambio
de DER o `fiscal_revision`; binding vigente solo con versión+digest; empresa
distinta; CAS `created`/`unchanged`/`updated`/`version_conflict`; idempotencia
replay y conflicto; GET con tenant persistido (404 en no-binding o tenant
distinto); 401/503 del middleware; `binding_conflict` al intentar un segundo
tenant; bump por actualización fiscal admin; recarga del mismo certificado
(misma huella pública, `fiscal_revision` +1 y versión nueva); y el POST
`fiscal-config`: aplicación con `config_version` persistido, replay idempotente,
cross-tenant rechazado, RUC distinto rechazado, certificado inválido sin
mutación y CAS obsoleto con actualización posterior. Las pruebas de rotación
(4) cubren: ruta nueva sin sobrescribir el archivo v1, fallo DB tras la
escritura en admin y en M2M con limpieza del archivo nuevo y v1 intacta, y
certificado inválido sin escritura. Ninguna prueba usa
certificados ni servicios reales: el fixture genera un par RSA autofirmado
sintético en memoria.

## 8. Límites y siguientes pasos

- Migración **no aplicada** en ninguna base; sin SQL ejecutado sobre bases
  existentes, sin commit/push/deploy, sin SUNAT real ni producción.
- El GET y el POST `fiscal-config` siguen el contrato acordado/informado; el
  POST `/sync` es un gancho interno adicional **pendiente de confirmación** con
  coordinación antes de conectar clientes (no altera el GET).
- El binding del lado plataforma (token opaco y consumo del cliente Python)
  queda en el trabajo de Codex; aquí se persiste la pertenencia tenant↔empresa
  y el `config_version` aplicado en FacturaYa.
- Provisionar por entorno: `FISCAL_PROFILE_ENABLED`,
  `FISCAL_PROFILE_BRIDGE_TOKEN` y, solo desarrollo, `FISCAL_PROFILE_ALLOW_LOCAL_HTTP`.
  Valores nunca por chat/logs.
- Si el descarte del archivo nuevo fallara tras un rollback, quedaría como
  huérfano únicamente ese archivo; la versión activa (`certificate_path` y
  bytes) nunca se sobrescribe ni se borra.
- Pendiente de aceptación entre sistemas: cliente Python GET (Codex) contra este
  endpoint en ensayo aislado; evidencia fiscal real (permisos, CA, revocación)
  sigue fuera de alcance y la emisión permanece cerrada.
