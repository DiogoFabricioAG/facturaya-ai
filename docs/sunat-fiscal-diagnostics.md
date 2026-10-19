# Diagnóstico fiscal externo PHP: auditoría y checks honestos

Fecha: 2026-10-08. Sesión: `opencode-resend-mailer` (cierre SUNAT, alcance
diagnóstico PHP). Coordinación: Codex. Propiedad: `app/Services/Sunat/*`
diagnóstico, `FiscalSetupProbeController`, `app/Data/FiscalProfileDiagnostic*`,
`app/Data/FiscalExternalProbeOutcome`, `config/fiscal_diagnostics.php`, pruebas y
este documento. No se tocaron `Company`/`CompanyController`/`CompanyCertificateStore`/rutas
ni el perfil del otro agente (OpenCode SUNAT), ni emisión.

## 1. Por qué el endpoint nunca llega a `success` (auditoría)

1. **El controlador no tiene rama de éxito**: `FiscalSetupProbeController.php`
   devuelve `invalid_local` (422) o `status: pending` (200) de forma
   incondicional; el propio comentario lo declara («Nunca devuelve success»).
   La línea de retorno `pending` está en `probe()`.
2. **El reporte no emitía evidencia positiva**: `FiscalProfileDiagnosticsService`
   fijaba `testedPermissions: []`, `certificateTested: false`,
   `signatureTested: false`, `caChainVerified: false`, `revocationVerified:
   false`, `probeValidUntil: null`. Aun con una consulta real, el reporte no
   podía acreditar nada.
3. **La consulta está apagada por defecto**: `fiscal_diagnostics.consult_enabled`
   es `false`; la fábrica no construye cliente y el resultado es
   `not_attempted` → `external_probe_not_configured`.
4. **Clasificación anterior demasiado gruesa**: Greenter marca el código de
   negocio «comprobante no existe» (`1033`, rango de excepción 100–1999,
   `BaseSunat::isExceptionCode` / `ConsultCdrService::loadFromResponse`) como
   `success=false` con `Error`. El clasificador previo solo reconocía `401` o
   «unauthorized», así que una consulta autenticada real a un comprobante
   inexistente quedaba `response_unclassified` y jamás acreditaba credenciales.
5. **Checks no verificables por diseño**: cadena CA, revocación CRL/OCSP, firma
   XMLDSig aceptada por SUNAT, permisos de emisión y aceptación de
   comprobantes no se pueden probar con una consulta no emisora. Deben quedar
   `pending` con requisito explícito (sección 4), no `success`.

**Alcance de permisos (confirmado por Codex)**: los 6 permisos reales de
plataforma (`tax_digital_certificate`, `see_sol`, `see_taxpayer_submission`,
`electronic_invoice`, `contingency`, `validity_query`) coinciden exactamente con
`FiscalSetupProbeController::ALLOWED_PERMISSIONS`; el gate **no** exige
guías/anular ni mínimos legales. No se requiere cambio de producto.

## 2. Cambios implementados (evidencia honesta)

- `GreenterConsultCdrProbe` invoca `getStatusCdr` **directamente** sobre el
  cliente SOAP inyectado (misma forma de respuesta que `ConsultCdrService`),
  conservando la procedencia:
  - CDR presente, código `1033` (comprobante inexistente) o código `0` →
    `authentication_accepted` (la solicitud autenticada fue procesada).
  - Otros códigos de negocio → `response_unclassified` con el código conservado
    para validarlo contra SUNAT real.
  - `SoapFault` de autenticación/permiso (401 o patrones usuario/contraseña,
    «no autorizado», credenciales, permiso) → `authentication_rejected`;
    `HTTP`/conexión → `transport_error`; otros faults → `response_unclassified`.
  - Los faultcode se sanean al contrato `[A-Za-z0-9._-]{1,32}` (defecto real
    detectado por prueba: `soap-env:Server` rompía `FiscalExternalProbeOutcome`).
- `FiscalProfileDiagnosticsService` construye `checks` con
  `{check,status,source,reason}`: locales (`local_openssl`) y credenciales
  (`sunat_billconsult_getstatuscdr`), más los pendientes explícitos. Los
  `pending_reasons` se derivan de los checks `pending`/`unsupported`.
- `FiscalProfileDiagnosticReport` expone `checks` (aditivo; conserva los campos
  `external` que consume el adaptador Python).
- `FiscalDiagnosticsRuntimeFactory` pasa tipo de documento, serie y correlativo
  desde configuración (validados) a la consulta; nada de red al construir.
- `config/fiscal_diagnostics.php` añade
  `consult_document_type`/`consult_series`/`consult_correlative` (referencia
  sintáctica inexistente; sin comprobantes reales).
- `FiscalSetupProbeController` documenta y expone `checks`; sigue devolviendo
  `pending` mientras existan checks no verificables.

## 3. Estado por comprobación

| Check | Fuente | Estado actual | Requisito para verificarse |
|---|---|---|---|
| `certificate_readable` | `local_openssl` | verificado/fallido local | — |
| `private_key_readable` | `local_openssl` | verificado/fallido local | — |
| `key_pair_matches` | `local_openssl` | verificado/fallido local | — |
| `certificate_currently_valid` | `local_openssl` | verificado/fallido local | — |
| `local_signature_sha1` | `local_openssl` | verificado/fallido local | — |
| `ruc_sol_credentials` | `sunat_billconsult_getstatuscdr` | pendiente | `consult_enabled` + TLS verificado + usuario secundario y RUC reales del dashboard |
| `certificate_sunat_acceptance` | pendiente | pendiente | envío firmado autorizado o servicio validado |
| `signature_xmlsig_sunat` | pendiente | pendiente | validación de XMLDSig con servicio autorizado |
| `ca_chain_trust` | pendiente | pendiente | ancla de confianza validada (bundle CA + verificación SUNAT) |
| `revocation_status` | pendiente | pendiente | CRL/OCSP operativo |
| `emission_permissions` | pendiente | pendiente | comprobación autorizada; la consulta no emisora no prueba permisos |
| `sunat_acceptance` | pendiente | pendiente | envío real autorizado (fuera de alcance sin credenciales y evidencia) |

## 4. Comandos y resultados reales (Docker aislado)

Imagen `facturaya-ai-test:latest` (PHP 8.4.24), repo montado **read-only** en
`/var/www/html`, sin `.env` de producción, sin contenedores previos:

```
docker run --rm --entrypoint php -v "D:\Proyectos2026\facturaya-ai:/var/www/html:ro" \
  -w /var/www/html facturaya-ai-test:latest \
  vendor/bin/phpunit --do-not-cache-result tests/Unit/GreenterConsultCdrProbeTest.php
# -> OK (12 tests, 21 assertions)

docker run --rm --entrypoint php -v "D:\Proyectos2026\facturaya-ai:/var/www/html:ro" \
  -w /var/www/html facturaya-ai-test:latest \
  vendor/bin/phpunit --do-not-cache-result tests/Unit/FiscalProfileDiagnosticsServiceTest.php
# -> OK (5 tests, 59 assertions)

docker run --rm --entrypoint php -v "D:\Proyectos2026\facturaya-ai:/var/www/html:ro" \
  -w /var/www/html facturaya-ai-test:latest \
  vendor/bin/phpunit --do-not-cache-result tests/Unit
# -> OK (44 tests, 2452 assertions)  [incluye suites de otros agentes]

docker run --rm --entrypoint php -v "D:\Proyectos2026\facturaya-ai:/var/www/html:ro" \
  -w /var/www/html facturaya-ai-test:latest \
  vendor/bin/pint --test app/Services/Sunat app/Data/FiscalProfileDiagnosticReport.php \
  app/Http/Controllers/FiscalSetupProbeController.php config/fiscal_diagnostics.php \
  tests/Unit/GreenterConsultCdrProbeTest.php tests/Unit/FiscalProfileDiagnosticsServiceTest.php
# -> PASS (15 files)
```

Prueba de arnés previa (existente): `tests/Unit/DiagnosticSoapClientTest.php` →
OK (1 test, 7 assertions). No hubo llamadas SUNAT, red externa ni escritura en
el repo (montaje `:ro`).

## 5. Configuración pendiente (nombres, nunca valores)

`FISCAL_DIAGNOSTICS_ENABLED`, `FISCAL_DIAGNOSTICS_BRIDGE_TOKEN`,
`FISCAL_DIAGNOSTICS_CONSULT_ENABLED`, `FISCAL_DIAGNOSTICS_CONSULT_WSDL`,
`FISCAL_DIAGNOSTICS_CONSULT_TIMEOUT`, `FISCAL_DIAGNOSTICS_CONSULT_READ_TIMEOUT`,
`FISCAL_DIAGNOSTICS_CONSULT_DOCUMENT_TYPE`, `FISCAL_DIAGNOSTICS_CONSULT_SERIES`,
`FISCAL_DIAGNOSTICS_CONSULT_CORRELATIVE`, `FISCAL_DIAGNOSTICS_SERVER_CA_BUNDLE`.
La ejecución real de la consulta requiere RUC, usuario secundario y certificado
cargados por dashboard (bóveda por tenant); nunca se piden valores en chat.

## 6. Límites

- Sin SUNAT real, sin `.env` de producción, sin migraciones ni despliegue; la
  consulta real no se ejecutó (configuración deshabilitada por defecto).
- La clasificación de códigos sigue siendo provisional hasta una consulta real
  autorizada; los códigos no catalogados quedan `response_unclassified`.
- El endpoint sigue devolviendo `pending` mientras CA, revocación, firma
  XMLDSig, permisos y aceptación sigan sin evidencia: es la posición honesta.
- No se tocaron rutas, perfil, Company ni el binding Python (Codex lo asume).

## 7. Evidencia técnica aditiva `technical_evidence` — ronda 2026-10-09

Base normativa: SPEC-0001 decisión 32 y
`plans/sunat-evidencia-guiada-2026-10-09.md`; reparto en
`plans/sunat-ronda-reanudada-2026-10-09.md` (rol OpenCode sunat-evidence).
Sesión `--auto`, sin HERDR, sin red, sin SUNAT y sin commit/push.

### 7.1 Contrato aditivo (schema_version 1)

El envelope legacy del endpoint no cambia: mismas claves de nivel superior
(`status`, `tenant_id`, `config_version`, `environment`, `sol_user_ref`,
`certificate_ref`, `ruc`, `diagnostics`) y el mismo `diagnostics`
(`contract_version: 1`, `binding`/`local`/`external`/`pending_reasons`/`checks`).
Se añade, al nivel superior y también en la respuesta `invalid_local` 422, el
objeto:

```json
"technical_evidence": {
  "schema_version": 1,
  "source": "facturaya_non_issuing_diagnostics",
  "credentials": {"status": "...", "source": "...", "environment": "...", "reason": null},
  "certificate": {"status": "...", "source": "...", "reason": null},
  "signature":   {"status": "...", "source": "...", "reason": null},
  "trust":       {"status": "...", "source": "...", "reason": null}
}
```

`status` por bloque: `verified`/`pending`/`rejected`; `reason` es un código
opaco o `null`. No hay agregado de readiness: el objeto describe qué se
comprobó y con qué fuente, nunca autoriza emisión.

### 7.2 Reglas de honestidad

| Bloque | `verified` exige | `rejected` cuando | `pending` cuando |
| --- | --- | --- | --- |
| `credentials` | Consulta SUNAT no emisora con TLS confiable clasificada `authentication_accepted` (única fuente posible de SUNAT en este endpoint) | `authentication_rejected` | Sin probe, transporte no confiable, ambiente sin consulta o resultado no clasificado |
| `certificate` | Material legible, vigente, par de claves compatible y **matcher de identidad inyectado por el servidor** que devuelva `match` (`source: server_identity_matcher`) | Ilegible, no vigente, par incompatible o `conflict` demostrado por el matcher confiable | Falta clave privada; sin matcher inyectado; o el matcher devuelve `unknown`/`ambiguous`. Ningún marcador de subject/CN autoriza |
| `signature` | Con motor Greenter configurado: XMLDSig real sobre el XML diagnóstico, firmado y verificado en memoria con el certificado cargado (`source: greenter_local_xml_signature`, ver §8). Sin motor: desafío RSA-SHA1 legacy (`source: local_engine_signature`). Ninguno acredita aceptación SUNAT, XMLDSig de comprobante ni CDR | Clave no RSA o firma local fallida; con motor, verificación XMLDSig fallida | Certificado o clave ilegibles; motor sin material o no disponible |
| `trust` | No disponible en este endpoint | — | Siempre: anclas/CRL las aporta infraestructura/plataforma |

Prohibiciones explícitas: no se devuelve `success`, no se acreditan los seis
permisos SOL con ninguna consulta, no se emite documento, no se marca
aceptación SUNAT por firma local y no se convierte el RUC del request en
identidad demostrada. Un marcador genérico `RUC` dentro del subject es, como
mucho, una observación de inspección: no autoriza `verified` ni produce
`rejected` por sí mismo. Los flags legacy siguen en `tested_permissions: []`,
`certificate_tested: false`, `signature_tested: false`, `ca_chain_verified:
false`, `revocation_verified: false`, `probe_valid_until: null`.

Mapeo: `credentials` deriva del mismo resultado del puerto
`SunatNonIssuingProbe` que alimenta el check `ruc_sol_credentials`; los checks
legacy no se retiran.

### 7.3 Puerto de identidad del certificado (infraestructura pendiente)

`App\Services\Sunat\CertificateIdentityMatcher` es el único camino admisible
para evaluar la identidad tributaria del certificado:

```php
interface CertificateIdentityMatcher
{
    public function match(string $certificatePem, string $expectedRuc): CertificateIdentityMatchResult;
}
```

- Recibe solo el PEM público exportado en memoria y el RUC esperado del perfil;
  nunca claves ni contraseñas.
- El resultado (`App\Data\CertificateIdentityMatchResult`) admite `match`
  (verificado), `unknown`/`ambiguous` (pending con motivo opaco) y `conflict`
  (rejected por contradicción demostrada por el matcher confiable; no es una
  afirmación tributaria de SUNAT).
- **Sin matcher inyectado no hay evaluación**: el bloque queda `pending` con
  `certificate_issuer_identity_not_demonstrated`. El servicio no incluye
  implementación por defecto ni heurísticas de subject, OID o CN, y no asume
  «oficial» por CN. Asociar el matcher a un perfil CA/OIDs documentados es
  trabajo de infraestructura aún pendiente.

### 7.4 Evidencia ejecutada (Docker local, repo montado `:ro`)

```
docker run --rm --entrypoint sh -v "D:\Proyectos2026\facturaya-ai:/var/www/html:ro" \
  -w /var/www/html facturaya-ai-test:latest -c "php -l app/Data/CertificateIdentityMatchResult.php \
  && php -l app/Services/Sunat/CertificateIdentityMatcher.php \
  && php -l app/Data/FiscalTechnicalEvidence.php \
  && php -l app/Data/FiscalProfileDiagnosticReport.php \
  && php -l app/Services/Sunat/FiscalProfileDiagnosticsService.php \
  && php -l app/Http/Controllers/FiscalSetupProbeController.php \
  && php -l tests/Unit/FiscalProfileDiagnosticsServiceTest.php \
  && php -l tests/Feature/FiscalSetupProbeEndpointTest.php"
# -> No syntax errors (8 archivos)

vendor/bin/phpunit --do-not-cache-result tests/Unit/FiscalProfileDiagnosticsServiceTest.php \
  tests/Feature/FiscalSetupProbeEndpointTest.php
# -> OK (15 tests, 195 assertions)  [12 unit + 3 feature]

vendor/bin/phpunit --do-not-cache-result tests/Unit
# -> OK (51 tests, 2532 assertions)  [incluye suites de otros agentes]

vendor/bin/pint --test app/Data/CertificateIdentityMatchResult.php \
  app/Services/Sunat/CertificateIdentityMatcher.php app/Data/FiscalTechnicalEvidence.php \
  app/Data/FiscalProfileDiagnosticReport.php app/Services/Sunat/FiscalProfileDiagnosticsService.php \
  app/Http/Controllers/FiscalSetupProbeController.php tests/Unit/FiscalProfileDiagnosticsServiceTest.php \
  tests/Feature/FiscalSetupProbeEndpointTest.php
# -> PASS (8 files)
```

Cobertura del matcher: sin matcher con marcador RUC presente (sigue pending),
`match` (verificado, recibe solo PEM público), `unknown`, `ambiguous`,
`conflict` (rejected solo desde matcher confiable) y ausencia de material
público. Certificados, credenciales y tokens de las pruebas son sintéticos; no
hubo SUNAT, red externa, `.env` de producción ni escritura en el repo.

### 7.5 Consumo pendiente

Es un contrato aditivo para revisión del coordinador: no debe consumirse como
verdad de readiness sin los bindings (tenant/versión/ambiente/perfil) y los
controles independientes que decida la plataforma, ni usarse para copiar
permisos ni afirmar aceptación SUNAT. La revisión guiada de permisos (registro
administrativo) es otra fuente separada, según decisión 32.

## 8. Firma XMLDSig real del motor Greenter — ronda 2026-10-09 (b)

Implementa el punto pendiente de `plans/sunat-ronda-reanudada-2026-10-09.md`:
verificación técnica local con el firmador XML **real** de Greenter, sin
comprobante fiscal, sin SOAP y sin red. Fuentes primarias en el propio vendor
instalado (`composer.lock` fija las versiones):

| Componente | Evidencia |
| --- | --- |
| Firmador usado por Greenter | `vendor/greenter/xmldsig/src/Sunat/SignedXml.php:15`; lo instancia `Greenter\See` (`vendor/greenter/lite/src/Greenter/See.php:69`) y lo ejecuta `FeFactory::getXmlSigned` (`vendor/greenter/lite/src/Greenter/Factory/FeFactory.php:157`) |
| Algoritmos por defecto (no se inventa ninguno) | Firma RSA-SHA1 (`SignedXml.php:39`), digest SHA1 (`:48`), canonicalización C14N (`:57`), transform enveloped (`:18`) |
| Nodo firmado | `getNodeSign` firma `ext:ExtensionContent` si existe; sin él, el elemento raíz (`SignedXml.php:262-283`) |
| Verificación | Método público `verify(DOMDocument)` (`SignedXml.php:169-216`); `verifyXml()` fue descartado porque reemplaza la clave cargada con el certificado embebido (`:80-86,113-120`) |

### 8.1 XML diagnóstico (no tributario)

`GreenterLocalXmlSignatureProbe` construye en memoria:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<DiagnosticoFiscalLocal xmlns="urn:meowlab:facturaya:fiscal-diagnostics:1" version="1">
  <Proposito>verificacion-firma-local-sin-emision</Proposito>
  <EmitidoEn>...UTC...</EmitidoEn>
  <Nonce>...</Nonce>
</DiagnosticoFiscalLocal>
```

Sin UBL, sin serie, sin correlativo, sin RUC/razón social, sin totales, sin
reservas y sin numeración de comprobantes. La firma resultante es enveloped
sobre el documento completo.

### 8.2 Semántica de evidencia

- `signAndVerify(combinedPem)`: firma el XML con el PEM combinado del perfil y
  vuelve a verificar con el solo certificado cargado (no con el embebido). El
  mismo material → `verified`; XML alterado o certificado de otra clave →
  `failed` (probado por tests focales con el motor real).
- `technical_evidence.signature`:
  - Motor configurado y verificado → `status: verified`, `source:
    greenter_local_xml_signature`, `reason: null`.
  - Motor configurado y verificación fallida → `status: rejected`, mismo
    `source`, `reason: greenter_local_xml_signature_verification_failed` (o el
    código opaco del motor).
  - Motor configurado sin material o con error interno → `status: pending`,
    mismo `source`, motivos `greenter_local_xml_signature_material_unavailable`,
    `greenter_local_xml_signature_engine_unavailable` o
    `greenter_local_xml_signature_result_invalid`.
  - Motor no configurado → fallback intacto: challenge RSA-SHA1 con `source:
    local_engine_signature`.
- `diagnostics.checks` añade `greenter_local_xml_signature`
  (`verified`/`failed`/`pending`, `source: greenter_local_xml_signature`) solo
  cuando el motor está configurado; el check legacy `local_signature_sha1`
  (challenge, `source: local_openssl`) permanece.
- Invariantes que no cambian: `success` nunca; los seis permisos fuera;
  `certificate_tested`, `signature_tested`, `ca_chain_verified`,
  `revocation_verified`, `probe_valid_until` siguen en `false`/`null`;
  identidad, trust y credenciales conservan sus estados previos. La firma local
  no habilita readiness ni sustituye aceptación SUNAT/CDR.
- El endpoint compone el motor real en `FiscalDiagnosticsRuntimeFactory` para
  ambos modos (consulta CDR apagada o encendida); el motor no hace E/S.

### 8.3 Nota de parser Python

- `signature.source` ahora puede ser `greenter_local_xml_signature` (nuevo) o
  `local_engine_signature` (fallback). Mismo esquema de bloque
  `{status, source, reason}`; `schema_version` sigue 1.
- El nuevo check de `diagnostics.checks` es aditivo; los consumidores que
  indexan por `check` no deben asumir posiciones fijas.
- No interpretar `signature.verified` como aceptación SUNAT, firma de
  comprobante real ni permiso de emisión.

### 8.4 Evidencia ejecutada (Docker local, repo montado `:ro`)

```
vendor/bin/phpunit --do-not-cache-result \
  tests/Unit/GreenterLocalXmlSignatureProbeTest.php \
  tests/Unit/FiscalProfileDiagnosticsServiceTest.php \
  tests/Feature/FiscalSetupProbeEndpointTest.php
# -> OK (22 tests, 263 assertions)
# Cubre: firma+verificación con mismo certificado; tamper del XML firmado;
# certificado de otra clave; XML inválido; mapeo del motor a
# verified/rejected/pending en el servicio; envelope legacy intacto.

vendor/bin/phpunit --do-not-cache-result tests/Unit
# -> OK (58 tests, 2596 assertions)  [incluye suites de otros agentes]

vendor/bin/pint --test <13 archivos>
# -> PASS
```

Nota honesta: cargar `SignedXml` en PHP 8.4 emite una deprecación del vendor
(`SignedXml.php:113`, parámetro implícitamente nullable) que no se corrige aquí
porque `vendor/` no es fuente propia; no afecta la firma ni la verificación.

### 8.5 Límites

- Usa los algoritmos por defecto del motor (RSA-SHA1/SHA1/C14N), los mismos que
  Greenter aplica por defecto a los comprobantes; no se elige ni inventa otro
  algoritmo.
- Acredita un ciclo local firmar→verificar del motor sobre XML diagnóstico
  propio; no acredita que SUNAT acepte la firma, el certificado ni el XMLDSig
  de comprobantes.
- Sin SUNAT, sin SOAP, sin red, sin credenciales reales, sin migraciones y sin
  commit/push.



# Conexión del comprobador de identidad, corte2026-10-11

`FiscalDiagnosticsRuntimeFactory` acepta por constructor un
`CertificateIdentityMatcher` opcional administrado por el servidor. Lo propaga
al servicio en ambas ramas de composición. Sin inyección mantiene identidad
`pending`; no incorpora heurísticas de CN/RUC ni políticas reales por defecto.

Codex verificó la rama local con PHPUnit12.5.33/PHP8.4.24:3pruebas,
31aserciones. Certificados y credenciales sintéticos en memoria. Se probaron
ausencia del matcher, comprobador explícito recibiendo solo certificado público
y resultado desconocido manteniendo pending. El matcher positivo de esta prueba
es un mock, no aceptación de un emisor/perfil real; credenciales, CA y permisos
siguen sin acreditarse por ese resultado. Rama con consulta externa no ejecutada.

Los archivos revisados se copiaron únicamente a una carpeta temporal propia en
el contenedor existente y se ejecutaron con `--no-configuration`, sin iniciar
Laravel ni usar su.env. Los tres archivos y la carpeta se eliminaron después;
la aplicación `/app` y su snapshot empaquetado no se actualizaron.

PHP reportó1deprecation de Greenter SignedXml::getPublicKey por parámetro
implícitamente nullable en PHP8.4. No se parcheó vendor ni se ocultó el aviso.
La implementación configurable de perfiles de identidad se prepara por Pi;
no hay comprobador/política real instalado en el servicio vivo.

Reejecución ampliada del coordinador:4pruebas/33aserciones aprobadas, con la
misma deprecationvendor. El caso añadido construye la rama de consulta con
WSDL/XSD locales e inspecciona la inyección del matcher; nunca llama a
diagnose/probe ni envía SOAP. Ajusta timeout únicamente dentro del proceso
de pruebas y lo restaura. Por tanto hay evidencia de composición de ambas
ramas, sin aceptación de una consulta externa. Temporales propios eliminados.

## Configuración pública de identidad del primer piloto

El factory prepara una única política explícita de infraestructura mediante
`fiscal_diagnostics.identity_profile`. Variables: FISCAL_IDENTITY_ISSUER_CERT_DER_BASE64,
FISCAL_IDENTITY_ISSUER_SHA256_DER, FISCAL_IDENTITY_PROFILE_ID,
FISCAL_IDENTITY_POLICY_OID, FISCAL_IDENTITY_RUC_ATTRIBUTE_OID y
FISCAL_IDENTITY_RUC_ATTRIBUTE_PREFIX (literal opcional, vacío por defecto).
El DER codificado es certificado público del issuer aprobado, no certificado
privado del cliente ni PKCS#12. No hay issuer, OID o prefijo SUNAT elegido por
defecto. No confundir esta política con confianza CA/CRL completa.

Sin configuración el matcher permanece ausente/pending. Configuración parcial,
tipo inválido, base64 no canónico, pin incorrecto o perfil inválido fallan con
fiscal_diagnostics_identity_configuration_invalid sin detalle de valores.
Una inyección explícita del matcher por constructor prevalece sobre estos
metadatos. No se acepta configuración por un request HTTP.

Aceptación del factory ampliada:8pruebas/48aserciones aprobadas,1deprecation
Greenter. Pruebas incluyen metadata pública pinneada construyendo el matcher
real de Pi con certificado sintético; ese certificado sin policy válida sigue
unknown, nunca genera prueba de identidad positiva. Se copiaron solo módulos
seleccionados a/tmp propio; aplicación activa no se actualizó. Temporales
eliminados. Implementación completa del matcher de Pi continúa en revisión.

## Huella de hoja y reejecución completa

El diagnóstico emite technical_evidence schema2 cuando puede calcular SHA256
del DER del certificado público evaluado. El consumidor compara esa huella con
PKCS#12 y evaluación CA/CRL. Schema1 sigue compatible para diagnóstico, pero
no promueve validación compuesta sin binding. La respuesta no contiene claves
ni bytes de certificados.

Codex reejecutó matcher+servicio+factory en/tmp aislado:32pruebas,
358aserciones aprobadas,1deprecationGreenter/PHP8.4. Temporales propios
eliminados; aplicación/volúmenes/servicio vivo conservan snapshot anterior.
No se configuró emisor/política real ni se consultó SUNAT.
