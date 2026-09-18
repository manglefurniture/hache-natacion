# F8 — Auditoría interna de acciones administrativas

**Fase:** F8 — Auditoría interna  
**Base del diagnóstico F8.0:** `main` `a4c107fd8edff82872dbf2fe3ede6739d02fd136`  
**Última cobertura funcional reflejada:** F8.4 completo hasta PR #349, desplegado en `26f02a9753fe7f95d0b28c6600efd21a841b930b`.  
**Fecha:** 2026-09-18  
**Estado del documento:** F8.0/F8.1 cerrados; F8.2/F8.3 desplegados; F8.4 completado; F8.5 en implementación.

## 1. Principios de P-08

P-08 queda resuelta con estas reglas:

1. F8 no crea una segunda historia ni sustituye autoridades existentes. La lectura unificada es una proyección read-only sobre evidencias durables ya existentes.
2. `auditoria_eventos`, `historial` y las tablas de dominio conservan su significado propio. No se fusionan filas por semejanza temporal ni por texto.
3. Un evento HTTP genérico describe un **resultado técnico de la solicitud**. Un HTTP 2xx no demuestra por sí solo que una mutación de dominio haya quedado confirmada.
4. Un cambio se marca como **confirmado** solo cuando la fuente durable acredita el estado o la operación: por ejemplo, historial guardado en la misma transacción, campos de actor/fecha en la entidad, una fila de vigencia/sustitución, un cierre persistido o un audit de Sharky con estado durable.
5. `before` y `after` solo se publican cuando fueron guardados de forma durable. Si no existen, el valor normalizado es `null` y su disponibilidad es `false`; nunca se reconstruyen desde el estado actual ni desde reglas de código.
6. Los textos históricos existentes se conservan como evidencia textual. F8 no convierte automáticamente una descripción libre en estructura `before/after` salvo que un adaptador tenga un contrato explícito y no ambiguo.
7. Actor desconocido no equivale a “sistema”. Solo se etiqueta actor de sistema cuando la propia fuente identifica inequívocamente ese origen.
8. No hay backfill. Cobertura forward-only permanece visible donde aplique.
9. La auditoría específica de Sharky mantiene su idempotencia y semántica. F8 puede proyectar metadatos mínimos de acciones administrativas, pero no copia conversaciones, hashes de contacto, payloads, credenciales ni resultados cifrados.
10. La lectura F8 no amplía roles ni permisos. La superficie unificada es ADMIN.

## 2. Matriz real de cobertura F8.0

| Acción relevante | Módulo | Fuente durable actual | Actor | Fecha/hora | Entidad/ref | Before | After | Resultado | Cobertura | Riesgo de reconstrucción ficticia |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Mutación API genérica | transversal | `auditoria_eventos` desde `config/database.php` | disponible si la sesión llega a `auth_user()`; puede faltar | `created_at` | endpoint; normalmente sin ID de dominio | no | no | HTTP/duración, técnico | parcial | **alto** si se interpreta 2xx como cambio confirmado |
| Configuración de alertas F5 | configuración | evento explícito `CONFIG_ALERTA_ACTUALIZADA` | sí | `created_at` | clave de configuración | sí, `anterior` | sí, `nuevo` | cambio confirmado en la transacción | completa | bajo |
| Atender/resolver pendiente | F1/F5 | `pendientes_gestion` + eventos `PENDIENTE_ATENDIDO`/`PENDIENTE_RESUELTO` | sí, ID y nombre durable | `atendido_at`/`resuelto_at` y audit | pendiente/origen | no estructurado globalmente | sí, estado/nota durable | cambio confirmado | completa para gestión | bajo |
| Baja/reactivación de alumno | alumnos | `historial` + `auditoria_eventos` específico | sí | `fecha_hora`/`created_at` | alumno | sí, `estado_anterior` | sí, `estado_nuevo` | cambio confirmado | completa | bajo |
| Editar fecha de inicio de alumno | alumnos | `historial` vía `hache_admin_history` | sí | `fecha_hora` | alumno o curso intensivo | durable en descripción | durable en descripción | cambio confirmado | completa como evidencia textual | medio si se intenta parsear retrospectivamente |
| Editar nombre/contacto/horario/plan desde ficha | alumnos | evento específico `ALUMNO_DATOS_ACTUALIZADOS` desde PR #343 | sí, forward-only | `created_at` del evento | alumno + sede cuando está demostrada | campos operativos estructurados; PII no duplicada | campos operativos estructurados; PII solo indica modificación | cambio confirmado en la transacción | completa desde cobertura F8.4 | bajo si no se reconstruye historia previa |
| Cambio rápido de horario | alumnos | `ALUMNO_DATOS_ACTUALIZADOS` desde PR #344 | sí, forward-only | `created_at` del evento | alumno + sede | horario anterior real | horario nuevo real | cambio confirmado en la transacción | completa desde cobertura F8.4 | bajo si no se reconstruye historia previa |
| Editar pago | finanzas | `historial` tipo `PAGO` en la misma transacción | sí | `fecha_hora` | pago | sí, durable en descripción | sí, durable en descripción | cambio confirmado | completa como evidencia textual | medio si se intenta parsear texto a estructura |
| Invalidar pago | finanzas | fila de pago + evento `PAGO_INVALIDADO` desde PR #347 | sí | `invalidated_at` y `created_at` del evento | pago + sede | estado `VALIDO` de la fila bloqueada | estado `INVALIDADO` | cambio confirmado en la misma transacción | completa desde cobertura F8.4 | bajo si no se reconstruye historia previa |
| Cerrar mes financiero | finanzas | `cierres_mensuales` | `cerrado_por` | `cerrado_at` | sede + periodo | no aplica como edición | snapshot del cierre | cambio confirmado | completa para creación del cierre | bajo |
| Ajustar rango de periodo financiero | finanzas | filas bloqueadas de `periodos_financieros` + `PERIODO_FINANCIERO_RANGO_ACTUALIZADO` en PR #349 | sí, forward-only | `created_at` del evento + timestamps de fila | periodo principal + periodo siguiente + sede | snapshot exacto de ambas filas, incluida ausencia real | filas persistidas después del upsert | cambio confirmado en la misma transacción | completa desde cobertura F8.4 | bajo si no se reconstruye historia previa |
| Cerrar sesión/clase | operación | `sesiones.cerrada_por`, `fecha_cierre`, estado + cobertura | sí | sí | sesión | no snapshot | REALIZADA/cerrada durable | cambio confirmado | completa para cierre | bajo |
| Marcar/corregir asistencia | operación | alta en `asistencias`; correcciones con `ASISTENCIA_CORREGIDA` desde PR #348 | alta: `created_by`; corrección: actor del evento | timestamps de fila + `created_at` del evento | asistencia + sesión; alumno/sede conservados en evidencia | estado anterior real en correcciones; texto libre no duplicado | estado nuevo real; observación solo indica modificación | cambio confirmado en la misma transacción | completa para correcciones desde cobertura F8.4; historia previa permanece sin backfill | bajo si se respeta cobertura forward-only |
| Alta de relación en intensivo | intensivos | `curso_intensivo_alumnos.created_by` y timestamps disponibles; corrección histórica además usa `historial` | sí para alta | sí | relación/alumno/curso | no aplica | relación durable | confirmado | completa para alta; mejor evidencia en corrección histórica | bajo |
| Retiro de alumno de intensivo | intensivos | evento `INTENSIVO_ALUMNO_RETIRADO` desde PR #346 | sí, forward-only | `created_at` del evento | relación eliminada + curso + alumno + sede | relación presente | relación ausente | cambio confirmado en la misma transacción | completa desde cobertura F8.4 | bajo si no se reconstruye historia previa |
| Alta/edición/inactivación de profesor | profesores | `profesores.created_by` para alta; `PROFESOR_DATOS_ACTUALIZADOS` desde PR #345; vigencias F7 al inactivar | sí donde la fuente lo acredita | timestamps de fuentes + `created_at` del evento | profesor | `activo` estructurado; PII no duplicada | `activo` estructurado; PII solo indica modificación | cambio confirmado | completa para edición de perfil desde cobertura F8.4 | bajo si no se reconstruye historia previa |
| Abrir/cerrar asignación de profesor | F7 | `profesor_horario_vigencias` | `created_by`/`closed_by`; baseline puede ser null deliberadamente | `vigente_desde`/`vigente_hasta` | asignación | no se inventa previo | vigencia durable | confirmado, forward-only | completa desde cobertura F7 | bajo si se respeta baseline |
| Registrar/anular sustitución | F7 | `profesor_sustituciones` | `created_by`/`anulada_by` | `created_at`/`anulada_at` | sustitución + sesión + profesores | no estructurado como snapshot | estado/motivo durable | confirmado | completa desde cobertura F7 | bajo |
| Cancelación/incidencia de profesor | F7/Sharky | `profesor_cancelaciones` con fuente y fecha | actor humano puede no existir; fuente sí | `created_at` | profesor + sesión | no | cancelación durable | confirmado como incidencia | parcial respecto a actor | medio |
| Acción administrativa ejecutada por Sharky | Sharky | `sharky_action_audit` | actor de sistema; no usuario humano | `created_at`/`completed_at` | acción; alumno solo cuando la fuente lo guarda | no general | resultado durable por status/code | PENDING/COMPLETED/FAILED/CANCELLED | completa para lifecycle técnico/operativo de la acción | bajo si no se infiere before/after |

### Huecos de escritura demostrados

Estado de los huecos demostrados de F8.4:

- **cerrado forward-only — PR #343:** edición administrativa del alumno desde la ficha;
- **cerrado forward-only — PR #344:** cambio rápido de horario del alumno;
- **cerrado forward-only — PR #345:** edición de perfil de profesor;
- **cerrado forward-only — PR #346:** retiro de alumno de un intensivo;
- **cerrado forward-only — PR #347:** invalidación de pago con snapshot durable del estado anterior;
- **cerrado forward-only — PR #348:** correcciones de asistencia; conserva actor y estado before/after sin copiar observación libre;
- **cerrado forward-only — PR #349:** cambios de rango de periodo financiero; captura las dos filas afectadas bajo bloqueo, conserva before/after exactos y no genera historia falsa en guardados sin cambios.

Ninguno de estos cierres hace backfill: la historia previa a cada cobertura permanece explícitamente desconocida cuando la fuente no la guardó.

## 3. Contrato mínimo F8.1

La lectura unificada normaliza evidencias sin alterar las fuentes. Cada elemento debe usar esta forma lógica:

```text
id                 identificador estable de la evidencia proyectada
source             autoridad original (auditoria_eventos, historial, ...)
source_id          ID exacto en esa autoridad
module             módulo operativo
action             acción normalizada, sin borrar la acción original
action_raw         acción/tipo original cuando exista
actor:
  known            boolean
  type             human | system | unknown
  id               string|null
  name             string|null
occurred_at:
  known            boolean
  value             datetime|null
  semantic         request_result | domain_change | created | completed | closed | ...
entity:
  type             string|null
  id               string|null
  reference_type   string|null
  reference_id     string|null
before:
  available        boolean
  value            object|string|null
after:
  available        boolean
  value            object|string|null
result:
  level            technical | confirmed | pending | failed | cancelled | unknown
  code             string|int|null
  detail           string|null
reason             string|null
route              string|null
method             string|null
scope:
  sede_id          string|null
  sede_known       boolean
coverage:
  history          native | forward_only | unknown
  before_after     structured | textual | none
```

### Reglas del contrato

- `null` significa desconocido/no registrado; nunca se sustituye por `0`, `false`, fecha actual o valor vigente.
- `result.level=technical` se usa para el audit HTTP genérico, incluso con HTTP 2xx.
- `result.level=confirmed` requiere evidencia durable del hecho de dominio.
- Un adaptador puede conservar `before/after` como evidencia textual si así fue almacenada; no tiene obligación de parsearla.
- No se correlacionan dos fuentes por proximidad temporal, usuario, IP, ruta ni texto.
- Una misma operación puede tener un evento técnico y una evidencia de dominio separados. La UI deberá explicarlo en vez de deduplicarlos heurísticamente.
- IDs y referencias originales se conservan.
- No se exponen IP, hashes de contacto, payloads cifrados, tokens, credenciales ni conversaciones completas en la proyección F8.
- Filtros por periodo se aplican al timestamp real de cada fuente. Si una fuente no tiene timestamp real compatible, no se le fabrica uno.
- Filtros por sede solo se aplican cuando la sede está demostrada por la fuente o por una relación exacta y actual que no reescriba historia. Si no puede demostrarse, `sede_known=false`.
- El backend F8 será ADMIN read-only y no disparará escrituras incidentales.

## 4. Primer backend autorizado por este contrato

F8.2 puede comenzar sin una decisión adicional de negocio.

Orden recomendado de adaptadores para mantener el cambio pequeño:

1. `auditoria_eventos`: conservar eventos específicos y genéricos, clasificando estos últimos como técnicos.
2. `historial`: proyectar historia administrativa del alumno sin parsear descripciones libres.
3. En micro-incrementos posteriores, añadir fuentes de dominio con semántica propia: F7, cierres/sesiones, pagos y Sharky.

La primera API no debe modificar `api/auditoria.php` de manera incompatible. Puede extenderla de forma compatible o crear una lectura F8 separada y dejar la vista actual intacta hasta F8.3.

## 5. Criterio de regresión para F8

Antes de cerrar la fase debe existir evidencia automatizada y operativa de:

- evento con before/after realmente guardado;
- evento con before desconocido que permanece `null`;
- actor conocido y actor desconocido;
- timestamp real y su semántica;
- entidad/referencia original;
- diferencia entre resultado técnico y cambio confirmado;
- filtros ADMIN sin ampliar permisos;
- convivencia con `historial` existente;
- ausencia de deduplicación heurística;
- ausencia de reconstrucción histórica o backfill.

### Evidencia automatizada F8.5

El conjunto de regresiones F8 cubre explícitamente:
- before/after durable y before desconocido conservado como `null`;
- actor conocido y desconocido;
- timestamp y semántica de resultado;
- entidad/referencia exacta;
- separación entre resultado técnico y cambio confirmado;
- ADMIN-only en backend y UI;
- convivencia con `historial` textual sin parseo retrospectivo;
- ausencia de deduplicación/correlación heurística;
- ausencia de reconstrucción histórica, backfill y ampliación de datos sensibles.

`tests/f8-phase-evidence-regression.php` funciona como prueba agregada del contrato de cierre F8.5 y se ejecuta dentro de Quality.


## 6. Estado vigente de F8

- F8.0: **terminado**.
- P-08: **resuelta** por el contrato anterior.
- F8.1: **terminado**.
- F8.2: **desplegado** — lectura ADMIN unificada read-only.
- F8.3: **desplegado** — UI ADMIN mínima sobre el contrato F8.2.
- F8.4: **terminado y desplegado** — PR #343–#349 integrados; matriz revalidada sin otros huecos de escritura administrativos importantes demostrados dentro del alcance F8.
- F8.5: **en implementación** — regresión agregada del contrato preparada; la evidencia operativa real en producción sigue siendo obligatoria para el cierre.
- F8 global: **En implementación**. No se marca Verificado sin evidencia real en producción conforme a F8.5/F8.6.
