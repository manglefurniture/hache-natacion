# Auditoría de adopción de Hache-Base — 2026-09

**Fecha de corte:** 24 de septiembre de 2026
**Hache-Base auditado (solo lectura):** `e2df6e8872964a13af582a858bc09b7b655ef013`
**Hache Natación inicial:** `536fe3789bbf6333844e4b2f781e303d2a4cd458` (`main`, `Fix P2-07: serialize recurring restore after daily backup`)
**Alcance:** contratos reutilizables, no una sincronización de repositorios. Se leyó completo `docs/HACHE-INTERNAL-IMPROVEMENTS-ROADMAP.md` antes de evaluar F6–F9; sus autoridades funcionales y sus límites continúan vigentes.

Hache-Base aporta contratos y primitives configurables. Esta auditoría conserva las implementaciones específicas de Hache Natación cuando ya demuestran la misma garantía y solo incorpora el delta transversal cuya ausencia se comprobó. No se modificaron reglas de negocio, precios, horarios, permisos, datos de producción ni infraestructura.

## Matriz de adopción

| Capacidad Hache-Base | Implementación Hache Natación | Estado | Gap | Riesgo | Decisión | Cambio realizado | Evidencia |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Contrato de read model: autoridad, fórmula, corte, cobertura y ausencia explícita (`ReadModelContract`) | F6 y F9 declaran fuentes y cobertura en `config/dashboard-*.php` y `config/resumen-diario.php`; F9 expone `null`/disponibilidad y su API/UI consumen el mismo contrato. | PROJECT-SPECIFIC EQUIVALENT | No se demostró conversión de desconocido a cero en las rutas auditadas. | Portar una clase genérica duplicaría autoridades ya protegidas. | Conservar el contrato específico. | Ninguno. | `tests/f9-resumen-diario-regression.php`, `tests/f9-resumen-diario-operational-evidence-regression.php`, `tests/f9-resumen-diario-mariadb.php`. |
| Cobertura forward-only y no-backfill | F7 conserva marcador de cobertura y F9 declara evidencia/ausencia sin reconstruir historia; el roadmap distingue integración, deploy y verificación. | PROJECT-SPECIFIC EQUIVALENT | No se observó atribución pre-cobertura ficticia. | Convertir historial previo en PASS/0 falsearía evidencia. | Preservar frontera vigente. | Ninguno. | Roadmap F7/F9 y regresiones operacionales F7/F9 en Quality. |
| Collector realmente read-only | `api/resumen-diario.php` construye el resumen sin mutación; la regresión MariaDB compara tablas antes/después. Las rutas legacy que generan sesiones por GET no se presentan como collectors ni son llamadas por F9. | PROJECT-SPECIFIC EQUIVALENT | El GET legacy tiene contrato de operación administrativo documentado, no un contrato de lectura. | Separarlo sin requisito de negocio rompería compatibilidad operativa. | Conservarlo fuera de F9 y no reclasificarlo como read-only. | Ninguno. | `tests/f9-resumen-diario-mariadb.php`; `public/resumen-diario.php` bloquea enlaces a `/api/sesiones.php`. |
| Estados temporales y zona IANA (`DateRangeState`) | `config/dashboard-tiempo.php` usa `America/Cancun`; F6/F7 aplican fronteras de fecha operativa y tienen regresiones de periodo. | PROJECT-SPECIFIC EQUIVALENT | No se demostró una inconsistencia activa de UTC/Cancún en rutas auditadas. | Migración masiva de funciones de fecha sin evidencia puede alterar ciclos y pagos. | Mantener helpers y pruebas de dominio. | Ninguno. | `tests/dashboard-fecha-operativa.php`, `tests/dashboard-period-regression.mjs`, roadmap F6/F7. |
| Auditoría de mutaciones confirmadas, sin no-op ni telemetría (`AuditLogger`) | F8 normaliza fuentes durable/técnica, mantiene actor desconocido como desconocido, no infiere correlaciones y excluye diagnóstico/RUM de auditoría administrativa. | PROJECT-SPECIFIC EQUIVALENT | No se comprobó contaminación de F8 por telemetría ni eventos HTTP técnicos convertidos en cambios confirmados. | Reemplazar F8 perdería semántica y compatibilidad de historial. | Conservar F8. | Ninguno. | `config/auditoria-unificada.php`, `tests/f8-audit-read-regression.php`, `tests/f8-phase-evidence-regression.php`, `tests/f8-diagnostic-noise-regression.php`. |
| Sanitización y minimización de snapshots de auditoría (`AuditSanitizer`) | F8 ya evita exponer hashes/IP y omite valores PII de cambios. La bitácora de eliminación persistía además el nombre del alumno, aunque `entidad_id` ya lo identifica. | EXTEND | PII innecesaria persistida en `ELIMINAR_DEFINITIVO`. | Exposición adicional al consultar/retener el evento. | Minimizar manteniendo trazabilidad por ID y conteos eliminados. | `api/alumno-gestion.php` guarda `alumno_id`, no el nombre; regresión F8 lo bloquea. | `tests/f8-audit-read-regression.php`. |
| Estados asíncronos, inbox/outbox, idempotencia, retry y fuera de orden (`AsyncOperationState` e inbox/outbox) | Sharky usa receipts durables, claims con fencing, recuperación de leases, claves de idempotencia y orden por timestamp/rank de delivery. | PROJECT-SPECIFIC EQUIVALENT | No se demostró `accepted = confirmed`, duplicado o reordenamiento sin protección. | Sustituir la persistencia Sharky por una primitive genérica pone en riesgo Core Rules y recovery. | Conservar arquitectura Sharky. | Ninguno. | `config/sharky-orchestrator-store.php`, `config/sharky-action-recovery.php`, `config/sharky-delivery-status.php`, regresiones de concurrencia/recovery/delivery en Quality. |
| Headers, sesión, roles, CSRF y protección de API | `config/auth.php`, `config/backend-bootstrap.php`, `config/database.php` y `config/rate-limit.php` aplican cookie segura/HttpOnly/SameSite, regeneración, roles por sede, CSRF y headers. | ALREADY COVERED | No se demostró desviación aplicable del baseline. | Rehacer auth transversalmente sería de alto riesgo para usuarios y permisos. | No cambiar. | Ninguno. | `config/auth.php`, `config/backend-bootstrap.php`, `config/database.php`, regresiones `portal-access`, `auth-remember` y permisos. |
| Request/correlation ID, error contract, logging JSON y redacción | Había headers/API errors y `error_log` puntual, pero no IDs de correlación ni un logger estructurado/redactado en la frontera común. | EXTEND | Errores 5xx no ofrecían identificador de correlación ni registro estructurado minimizado. | Dificulta investigación y puede favorecer que errores futuros registren contexto sensible sin contrato. | Añadir primitive local mínima, sin vendor ni cambio de respuestas exitosas. | Nuevo `config/request-context.php`; APIs y páginas backend devuelven `X-Request-Id`/`X-Correlation-Id`; el filtro 5xx registra JSON redactado y devuelve `request_id`. | `tests/request-context-regression.php`; `config/database.php`; `config/backend-bootstrap.php`. |
| Rate limiting compartido P2-05 | Limitador local con archivo y `flock`; despliegue actual es un VPS único. | PROJECT-SPECIFIC EQUIVALENT | No hay evidencia de varias instancias, procesos coordinados o edge que exijan estado compartido. | Redis/DB/edge añadirían infraestructura y modos de fallo no justificados. | Conservar limitación local. | Ninguno. | `config/rate-limit.php`; consolidación P2 de Hache-Base. |
| Health, readiness, marcador de deploy y recovery por fases | Workflow Deploy depende de Quality, recibe el SHA aprobado y el helper de producción conserva fase/marcador; health se consulta sin mutar negocio. | ALREADY COVERED | No se demostró fallo de serialización, marker, permisos o recuperación. | Sustituir workflow/helper probado puede romper la ruta de producción. | No cambiar. | Ninguno. | `.github/workflows/deploy.yml`, `ops/`, `api/health.php`, `tests/deploy-wrapper-permissions-regression.mjs`. |
| Backup/restore con historial P2-07 | Backup diario y restore `workflow_run` requieren backup programado exitoso de `main`, guard de día 1 UTC, RPO 86400, RTO 3600, cleanup, artifact minimizado y nombre único de rerun. | EVIDENCE PENDING | Aún no existe la primera ejecución recurrente real, prevista no antes del 2026-10-01. | Declarar PASS antes del run real ocultaría la única evidencia faltante. | No rediseñar ni simular. | Ninguno. | `docs/production-readiness/RESTORE-HISTORY-P2-07.md`, `production-restore-drill.yml`, `tests/production-backup-cadence-regression.mjs`. |
| P2-06: referencias inmutables de Actions e imágenes de CI | Los workflows usaban tags móviles (`@v4`, `@v2`, `mariadb:11.8`) en Quality y evidencia operacional. | ADOPT | La resolución futura de tags podía cambiar sin cambio revisado. | Supply-chain drift de CI. | Anclar a SHA/digest de releases ya usados por Hache-Base y el release verificado de setup-php. | Doce `uses:` y dos servicios MariaDB pasan a SHA/digest; regresión inventaría todos los workflows. | `.github/workflows/*.yml`, `tests/hache-base-adoption-regression.mjs`. |
| Lockfiles, SCA, licencias, SBOM y provenance P0-12/P2-06 | Existen `composer.json` y `package.json`, pero no lockfiles comprometidos; el deploy liga el SHA de Git, no un artefacto con SBOM/provenance. | EVIDENCE PENDING | No hay base reproducible para atribuir auditoría de dependencias o SBOM a un SHA. | Generar locks o afirmar attestation sin el flujo real produciría evidencia falsa. | No generar artefactos ni claims especulativos. | Ninguno. | `composer.json`, `package.json`, ausencia de `composer.lock`/lockfile Node, `security/supply-chain/POLICY.md` de Base. |
| Upload extension P2-04 | No hay `$_FILES`, `move_uploaded_file`, MIME multipart ni endpoint de uploads en el árbol auditado. | NOT APPLICABLE | No existe superficie de archivos que proteger. | Añadir storage/scanner/policy sería infraestructura muerta. | No instalar extensión. | Ninguno. | Búsqueda del árbol de código sin coincidencias de APIs de upload. |
| Observabilidad neutral P2-01, alertas P2-02 y evidencia | Hay health, RUM minimizado, F1/F5 y workflows de evidencia específicos; no hay necesidad demostrada de APM externo. | PROJECT-SPECIFIC EQUIVALENT | El proyecto no requiere un adapter genérico adicional para las señales existentes. | Duplicar signals o añadir vendor lock-in. | Conservar señales y evidencias específicas. | Ninguno. | `api/health.php`, `api/rum-*.php`, `docs/production-readiness/`, workflows de evidencia. |
| APM SaaS, canary/blue-green, WAF avanzado, multi-región y colas globales P2/P3 | No existe requisito operacional ni incidencia que los justifique. | REJECT | La capacidad no aporta un control proporcional al sistema single-VPS actual. | Coste, privilegios e infraestructura fuera de alcance. | No adoptar. | Ninguno. | Consolidación P2/P3 de Hache-Base y arquitectura actual. |
| Design System 2026 Core→Theme→Extensions | Las correcciones no tocan layout, componentes, estados visuales ni assets. | NOT APPLICABLE | No hay cambio funcional de UI que requiera adaptación visual. | Un refresh visual ampliaría alcance sin resolver un gap. | No adoptar. | Ninguno. | Alcance del diff de esta rama. |
| Wiring de regresiones y sintaxis de CI | Quality ejecuta `npm test`, syntax PHP y regresiones MariaDB; las nuevas garantías necesitaban quedar conectadas. | ADOPT | Sin wiring, el pinning y el contexto común podrían degradar sin detección. | Regresión silenciosa de seguridad/observabilidad. | Ejecutar ambos checks en la ruta normal `npm test`. | `package.json` añade las dos regresiones a `pretest`. | `package.json`, `.github/workflows/quality.yml`. |

## Production Readiness frente a Hache-Base consolidado

| Control/capacidad | Aplica | Estado | Evidencia | Gap |
| --- | --- | --- | --- | --- |
| P0-03/P0-04 — error contract, request ID y logging mínimo | Sí | EXTEND | Frontera API existente; `request-context.php` y regresión añadidos. | Cobertura estructurada se limita por ahora a la frontera común de error 5xx; no se afirma instrumentación de cada log legacy. |
| P0-05/P0-06 — async/idempotencia | Sí, Sharky/WhatsApp | PROJECT-SPECIFIC EQUIVALENT | Receipts, claim/recovery, delivery status y suite de concurrencia. | Ningún gap demostrado. |
| P0-07/P0-08 — headers y sesión | Sí | ALREADY COVERED | Auth, cookies, CSRF, headers y pruebas de acceso. | Ningún gap demostrado. |
| P0-09/P0-10 — health, deploy y recovery | Sí | ALREADY COVERED | Quality→Deploy, SHA explícito, helper, health y regresión de permisos. | Ningún gap demostrado. |
| P0-11/P2-07 — backup y restore recurrente | Sí | EVIDENCE PENDING | Workflow serializado y regresión de cadence. | Falta el primer run recurrente real del 2026-10-01 o posterior. |
| P2-06 — referencias inmutables de CI | Sí | ADOPT | SHA/digests y regresión de inventario añadidos. | Ninguno demostrado en referencias de los workflows auditados. |
| P0-12/P2-06 — lockfiles, SCA/licencias, SBOM y provenance | Sí | EVIDENCE PENDING | Manifests presentes; no hay lockfiles ni artefacto versionado con evidencia atribuible. | Faltan lockfiles, SCA/licencias atribuibles, SBOM y provenance reales; no se reclama firma/SLSA. |
| P2-04 — uploads | No | NOT APPLICABLE | No hay superficie de upload. | Ninguno. |
| P2-05 — rate limit distribuido | No en arquitectura actual | PROJECT-SPECIFIC EQUIVALENT | Limitador local y VPS único. | No hay evidencia que justifique shared store. |
| P2-01/P2-02 — observabilidad/alertas | Sí, con alcance actual | PROJECT-SPECIFIC EQUIVALENT | Health, RUM minimizado, F1/F5 y evidencia operacional. | No se introduce APM ni vendor. |

## Adoptado

- Inmutabilidad de supply chain en los workflows: Actions por SHA y servicios MariaDB por digest, protegidos por `tests/hache-base-adoption-regression.mjs`.
- Wiring de las regresiones de adopción en `npm test`/Quality.

## Extendido

- Contexto de request/correlation ID, encabezados de respuesta y log JSON redactado en la frontera común de API/backend.
- Bitácora de eliminación de alumnos minimizada: conserva el ID durable y los conteos, no el nombre duplicado.

## Ya cubierto

- Auth, roles, sesión, CSRF y headers aplicables.
- Health, deploy ligado a Quality, SHA/marcador, permisos y recovery.

## Equivalentes específicos

- Contratos F6/F7/F8/F9 de read models, cobertura forward-only, desconocido explícito y lectura sin mutación.
- Tiempo operativo `America/Cancun` y fronteras de dominio.
- Sharky/WhatsApp: idempotencia, receipts, recovery, concurrencia y delivery fuera de orden.
- Rate limit local single-VPS y señales/alertas operacionales existentes.

## No aplicable

- Upload extension P2-04: no existe endpoint o API de uploads.
- Design System: ningún cambio de esta misión modifica UI o accesibilidad visual.

## Evidencia pendiente

- P2-07: primera ejecución recurrente real del restore drill, no antes del 2026-10-01.
- P0-12/P2-06: lockfiles comprometidos, SCA/licencias atribuibles al SHA, SBOM y provenance de un artefacto real. No se fabricaron.

## Rechazado

- APM SaaS, Redis/DB/edge rate limit compartido, queues externas, canary/blue-green, WAF avanzado y multi-región: no hay requisito ni evidencia de necesidad y ampliarían infraestructura.

## Deuda real restante

1. Revisar el artefacto minimizado de la primera restauración recurrente real cuando el workflow elegible termine; hasta entonces P2-07 no puede declararse comprobado por recurrencia.
2. Para elevar la evidencia de dependencias, el proyecto necesitaría adoptar de forma explícita un flujo reproducible con lockfiles y luego generar SCA/licencias/SBOM/provenance desde ese flujo. La ausencia actual se conserva como evidencia pendiente, no como aprobación.
3. Los logs heredados fuera de la frontera común aún no se reclasifican retrospectivamente como logging estructurado; el nuevo contrato previene el gap en los errores 5xx centralizados sin introducir una migración transversal no justificada.
