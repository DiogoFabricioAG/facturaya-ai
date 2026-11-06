# Despliegue crédito y GRE privada

Fecha: 06/11/2026 UTC (05/11/2026 en Lima). Servidor: alias SSH `meowlab`.

## FacturaYa

- URL: `https://facturas.meowlab.tech`.
- Imágenes activas: `facturaya-ai-app:credit-gre-20261106T042556Z` y `facturaya-ai-web:credit-gre-20261106T042556Z`.
- Se comparó el código de `/opt/facturaya-ai` con el contenedor activo antes de preparar el paquete: coincidían. Composer y Greenter mantienen las versiones desplegadas; no hubo actualización de dependencias.
- Sólo se superpusieron los archivos de crédito/GRE/categoría y assets web construidos. Base de datos, proxy, redes, secretos y otros servicios conservaron su configuración.
- Migraciones aplicadas explícitamente: `2026_11_05_000001_add_invoice_payment_terms` y `2026_11_05_000002_create_despatches`.
- Salud local/pública HTTP 200; GET `/api/despatches` sin token HTTP 401; inicio HTTP 200 con selector de condición de pago; seis rutas GRE registradas.
- Smoke de contrato en el contenedor publicado: columnas/tablas presentes, M1 sin conductor válido, otra categoría sin conductor rechazada y validación de crédito correcta. Sin escrituras de negocio ni llamadas a SUNAT/OpenAI.
- Hubo reversión automática ante una comprobación inicial no superada. La imagen nueva se comprobó aisladamente y se republicó con verificaciones HTTP explícitas; todas pasaron. No se restauró ni retrocedió la base de datos.

## Respaldo y recuperación

Directorio privado remoto: `/opt/facturaya-ai/backups/releases/credit-gre-20261106T042556Z`.

Contiene snapshot del código anterior, manifiestos, imágenes de rollback referenciadas, scripts, comprobaciones y `database-before.dump.enc`. El dump PostgreSQL está cifrado con AES-256/PBKDF2 usando la clave de aplicación ya existente como contraseña de respaldo, sin copiarla al paquete. Se verificó descifrando hacia `pg_restore --list`, sin mostrar datos. El directorio está excluido de futuros builds por la regla `backups` de Docker.

Rollback de aplicación, si es necesario y coordinando también Manolo:

```sh
bash /opt/facturaya-ai/backups/releases/credit-gre-20261106T042556Z/rollback.sh
```

El rollback restaura imágenes anteriores y cachés, conservando el esquema aditivo. **No restaura automáticamente el dump:** hacerlo podría perder operaciones posteriores al respaldo. El código fuente del host permanece con el nuevo corte; cualquier nueva compilación debe decidirse explícitamente.

## Límites

No se modificaron facturas existentes ni se emitieron documentos reales. GRE sigue requiriendo habilitación administrativa, credenciales API y confirmación del próximo correlativo T001. No hay PDF/QR de guías ni pantalla web de guías en este corte. La precarga de bienes desde factura continúa pendiente.

## Manolo publicado

El backend se verificó antes de autorizar el cambio de Manolo. El coordinador de ese repositorio confirmó:

- Worker activo al 100%: `4ceeaa93-b19e-41b5-b864-bdeb046a2f42`, sobre la versión anterior `158b52bc` conservada para rollback.
- Bridge activo: imagen de identificador corto `84ee1c65`, saludable.
- Salud pública HTTP 200; firmas HMAC, permisos y replay comprobados con cinco operaciones sintéticas rechazadas, sin proveedores fiscales ni emisiones.
- Paquete aislado: 282/282 pruebas aprobadas. Se conservaron bindings, nombres de secretos, variables, compatibilidad, cron de 15 minutos y ráfagas activas; la guardia también cubre la confirmación de guías.
- Respaldo PostgreSQL del bridge restaurado en aislamiento (37 tablas), respaldo D1 restaurado en SQLite (26 tablas, integridad correcta) e imagen previa guardada. Staging retirado.

Su runbook y evidencia se conservan en `D:/meowlab/docs`. No se configuraron credenciales GRE ni correlativos y no se emitieron documentos reales durante el despliegue.
