# Matcher de identidad de certificado configurado por infraestructura

## Alcance y contrato

`App\Services\Sunat\ConfiguredCertificateIdentityMatcher` implementa el contrato existente `CertificateIdentityMatcher::match(string $certificatePem, string $expectedRuc): CertificateIdentityMatchResult`. Solo se puede construir con una `CertificateIdentityProfileConfiguration` explícita del servidor. No busca configuración en el entorno, no descarga certificados ni consulta navegador, red o SQL.

La configuración exige el certificado público del issuer aprobado y su pin SHA-256 del DER. El constructor vuelve a obtener el DER con OpenSSL y rechaza un pin ausente, mal formado o distinto. Los perfiles son una lista cerrada de `CertificateIdentityProfile`: identificador, OID exacto de `certificatePolicies`, OID exacto del atributo de subject que contiene el RUC y un prefijo literal opcional. El prefijo es vacío por defecto; está limitado a 32 bytes ASCII visibles. No se deducen perfiles ni prefijos del subject, CN o contenido del certificado.

Ejemplo exclusivamente estructural (los identificadores siguientes no son una política oficial):

```php
$profile = new CertificateIdentityProfile(
    profileId: 'synthetic-profile',
    policyOid: '1.3.6.1.4.1.55555.20.1',
    rucAttributeOid: '1.3.6.1.4.1.55555.20.3',
    rucAttributePrefix: 'NTRPE-', // literal y solo si el perfil revisado lo exige
);

$configuration = new CertificateIdentityProfileConfiguration(
    issuerCertificatePem: $infrastructureApprovedPublicIssuerPem,
    issuerSha256Der: $infrastructureApprovedIssuerDerSha256,
    profiles: [$profile],
);
$matcher = new ConfiguredCertificateIdentityMatcher($configuration);
```

No se fija aquí ningún OID de emisor, política o atributo como oficial. Los OIDs de pruebas son sintéticos (`1.3.6.1.4.1.55555...`). Cualquier perfil real, incluido uno que use `NTRPE-`, requiere revisión y configuración explícitas; no se instala ni se habilita en este cambio.

## Evidencia y resultados

El matcher comprueba el nombre issuer del certificado frente al subject DER del certificado público configurado, y verifica la firma del certificado presentado con la clave pública de ese issuer usando OpenSSL. Después requiere una sola política reconocida por un único perfil y un único atributo de subject bajo el OID autorizado por ese perfil. El valor del atributo debe venir en una de las formas de cadena soportadas (UTF8String, PrintableString o IA5String), contener solo ASCII visible y ajustarse al prefijo literal configurado seguido exactamente por once dígitos. Otros tags o valores mal formados quedan `unknown`. Compara ese valor con `prefix + expectedRuc`, sin normalización, búsqueda de CN ni regex para descubrir formatos.

- Coincidencia exacta: `match`.
- RUC válido bajo el formato literal del perfil pero distinto del esperado, o firma inválida demostrada por OpenSSL: `conflict`.
- Falta de evidencia, material mal formado, prefijo que no corresponde al perfil, issuer no configurado o error de parseo/verificación: `unknown`.
- Atributo RUC duplicado, varias políticas/perfiles configurados coincidentes o política repetida: `ambiguous`.

`unknown` y `ambiguous` permanecen pendientes. Los motivos de resultado son códigos opacos, no contienen RUC, subject, rutas ni representaciones del certificado. El matcher no afirma identidad SUNAT, aceptación de SUNAT, autorización, readiness ni permiso tributario.

## Límite de parseo

La API PHP/OpenSSL se usa para leer el certificado y verificar la firma; las políticas y atributos no se extraen de su volcado textual legible para humanos. El código inspecciona DER para localizar los campos X.509 necesarios: secuencia exterior/TBSCertificate, nombres issuer/subject, extensión `certificatePolicies`, OIDs y el valor del atributo elegido en las formas de cadena admitidas. Rechaza longitudes DER no canónicas, estructuras truncadas y formas/tags que no reconoce.

Esto es un lector acotado de las formas DER que necesita este matcher, **no** un validador DER/ASN.1 general ni un validador PKI completo. En particular no construye ni valida una cadena CA, vigencia, key usage/CA constraints, CRL/OCSP, políticas tributarias ni confianza del issuer fuera del pin configurado. El lector no pretende verificar todas las reglas semánticas de RFC 5280. Una política repetida se rechaza como evidencia ambigua, en línea con el requisito de unicidad de `certificatePolicies` de [RFC 5280 §4.2.1.4](https://www.rfc-editor.org/rfc/rfc5280.html#section-4.2.1.4). La configuración CA/CRL y sus revisiones permanecen separadas.

## Pruebas

`tests/Unit/ConfiguredCertificateIdentityMatcherTest.php` emite certificados y claves efímeros con OpenSSL local, OIDs privados sintéticos y una identidad ficticia. Cubre firma/issuer, pin, política ausente/no configurada/múltiple/repetida, RUC exacto, prefijo literal, RUC contrario, campo ausente y atributo duplicado. No usa certificados reales, claves persistentes, red, SQL, Laravel boot ni servicios fiscales.
