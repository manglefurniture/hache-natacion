# F7 — Gestión interna de profesores

**Estado:** **Desplegado**  
**Fecha de corte:** 2026-09-18  
**SHA funcional verificado técnicamente en producción:** `f976b2c5c8ef828c168aa21c7fbd020e2880b1c0`

## Alcance integrado

F7 quedó implementada en incrementos pequeños:

- **F7.1:** vigencia durable forward-only de asignaciones; sin backfill.
- **F7.2:** sustituciones explícitas, auditables y no inferidas desde cancelaciones.
- **F7.3:** UI ADMIN de sustituciones consumiendo la autoridad backend.
- **F7.4:** lectura ADMIN integrada por profesor y periodo con carga prevista, carga confirmada solo con evidencia, sesiones realizadas sin atribución, incidencias, sustituciones, docencia compartida, fuentes y cobertura.
- **F7.5:** UI ADMIN de actividad/carga que consume F7.4 sin recalcular reglas de negocio.
- **F7.6:** evidencia operacional agregada y read-only para comprobar los criterios de F7 en producción sin emitir PII ni inventar datos.

No se añadió nómina, honorarios, evaluaciones, reconstrucción histórica, borrado físico ni una segunda autoridad de negocio.

## Evidencia técnica

PR #336 integró F7.6. Quality #1665 detectó un error de sintaxis causado por un escape literal entre dos `require_once`; se corrigió en el mismo PR antes de merge.

- Quality #1666 del head corregido: **success**.
- Merge funcional: `f976b2c5c8ef828c168aa21c7fbd020e2880b1c0`.
- Quality #1667 de `main`: **success**.
- Deploy #302: **success**.
- `.hache-deployed-sha`: coincidió exactamente con `f976b2c5c8ef828c168aa21c7fbd020e2880b1c0`.
- `config/profesor-actividad-evidence.php` y `bin/production-readiness-evidence.php`: sintaxis PHP correcta en producción.
- `/api/health.php`: HTTP 200 / `ok: true`.
- El endpoint interno de evidencia continuó bloqueado externamente: HTTP 404 con token inválido.
- Codex automático no tenía revisión disponible por límite de uso; no se invocó manualmente.

## Snapshot operacional real

Se ejecutó una lectura **read-only** por loopback usando el mecanismo existente de token efímero. El snapshot correspondió exactamente al SHA funcional anterior y mantuvo:

- `contains_personal_rows = false`;
- `contains_message_payloads = false`;
- `contains_contact_identifiers = false`;
- `contains_credentials = false`.

Cobertura real F7:

- asignaciones desde `2026-09-18 11:30:36 UTC`;
- sustituciones desde `2026-09-18 11:46:33 UTC`;
- periodo observado: `2026-09-18` a `2026-09-18`;
- profesores presentes: **2**;
- historia previa reconstruida: **no**.

Dentro de esa cobertura todavía había **0** sesiones de un docente, **0** sesiones compartidas, **0** sustituciones, **0** incidencias, **0** profesores inactivos con historia, **0** profesores con carga observable y **0** sesiones realizadas sin atribución. Esto no es un fallo funcional: la cobertura empezó el mismo día y aún no había actividad post-cobertura suficiente.

## Matriz del criterio de terminado

| Caso exigido | Regresión controlada | Producción real post-cobertura |
| --- | --- | --- |
| Clase con un docente | Cubierto por regresiones F7.4/F7.6 | No observado todavía |
| Docencia compartida | Cubierto por regresión MariaDB F7.4 | No observado todavía |
| Sustitución | Cubierto por regresiones F7.2/F7.4/F7.6 | No observado todavía |
| Incidencia individual | Cubierto por regresión MariaDB F7.4 | No observado todavía |
| Profesor inactivo conservando historial | Cubierto por regresión MariaDB F7.4 | No observado todavía |
| Carga con fuente y periodo | Cubierto por regresiones F7.4/F7.6 | No observado todavía |
| Evidencia insuficiente sin reconstrucción ficticia | Cubierto por regresión MariaDB F7.4; contrato `historia_previa_reconstruida=false` | La no reconstrucción está confirmada; todavía no existe una sesión real elegible para comprobar el estado sin atribución |

## Estado de cierre

F7 **no** se marca **Verificado**. La convención del roadmap exige comprobación operativa en producción y los escenarios necesarios todavía no existen dentro de la cobertura forward-only.

El siguiente paso es repetir la lectura operacional read-only cuando haya actividad real post-cobertura. No se crearán sustituciones, incidencias, inactivaciones ni sesiones ficticias para forzar el cierre.
