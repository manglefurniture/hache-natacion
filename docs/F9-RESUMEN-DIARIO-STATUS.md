# F9 — Resumen operativo diario

**Fase:** F9 — Resumen operativo diario  
**Base del diagnóstico F9.0:** `main` `a7aa88e09daad1e0b6db71393b94ec92d9d08353`  
**Fecha:** 2026-09-19  
**Estado:** F9 **Verificado**. F9.0/P-09 cerrados; F9.1 y F9.2 desplegados y verificados técnicamente; F9.3 desplegado y verificado operativamente con evidencia real read-only de producción.

## 1. Resolución de P-09

P-09 queda resuelta con un **corte de consulta reproducible, no una instantánea persistida**:

1. La fecha operativa usa siempre `America/Cancun`. El endpoint futuro acepta una fecha explícita `YYYY-MM-DD`; si se omite, usa la fecha operativa actual mediante `config/dashboard-tiempo.php`.
2. Cada lectura declara `actualizado_en`: ese instante es el corte de la consulta. “Apertura” y “cierre” son dos proyecciones de la misma fecha, no estados guardados ni acciones administrativas.
3. F9.1 no crea tabla ni snapshot. Una lectura histórica es **viva/reconciliable**: refleja las autoridades actuales sobre hechos fechados del día seleccionado. No se presentará como fotografía inmutable de lo que se veía a una hora pasada.
4. Correcciones posteriores pueden cambiar una lectura futura del mismo día. El contrato debe declararlo y exponer fuentes/cobertura. Pagos invalidados y correcciones con evidencia durable deben seguir visibles cuando la fuente permita relacionarlos con el día consultado; nunca se reconstruye un “antes” no guardado.
5. “Cierre operativo” no equivale a cierre financiero. Consultar F9 no cierra sesiones, periodos, pagos, pendientes ni clases y no programa mensajes o automatizaciones.
6. No se requiere almacenamiento adicional mientras el objetivo sea consulta operativa. Si en el futuro se necesita una fotografía legal/contable o comparar exactamente “lo que se veía a las 22:00”, eso será una decisión nueva y no se inferirá desde F9.1.

## 2. Matriz de fuentes reutilizables

| Bloque F9 | Autoridad reutilizada | Regla |
| --- | --- | --- |
| Fecha y hora de actualización | `config/dashboard-tiempo.php` | Zona `America/Cancun`; no usar zona implícita del servidor. |
| Alumnos activos de apertura | `dashboard_alumnos_activos()` de F6 | Misma definición F6; no equivale a estado administrativo ni derecho de acceso. |
| Clases previstas | horarios + alumnos/intensivos, replicando como lectura pura la elegibilidad usada para generar sesiones | Debe contar horarios que tendrían sesión operativa sin insertar `sesiones`. Fin de semana: cero clases previstas. |
| Sesiones registradas y marcas | `dashboard_operacion_fecha()` | Solo lectura; nunca llamar al GET de `api/sesiones.php`, porque ADMIN puede generar sesiones. |
| Asistencia de cierre con denominador fiable | `dashboard_asistencia_periodo()` de F6 para fecha→fecha | Solo sesiones `REALIZADA` no canceladas con cobertura completa persistida. |
| Pagos pendientes / reposiciones / prospectos | F1/F5 mediante `centro_pendientes_compuesto_*` | Estado actual al corte. Prospectos globales solo para ADMIN. |
| Cobros del día | `pagos` + sede del alumno | Se agrupan por fecha efectiva de cobro del día y solo `VALIDO` suma dinero; inválidos permanecen visibles como revisión, no reducen/añaden ingreso válido. |
| Altas del día | `dashboard_nuevos_alumnos()` | Reutiliza `fecha_inicio`; reactivación no es alta nueva. |
| Incidencias | sesiones canceladas + fuentes F7 de profesor/sustitución cuando existan | Exponer cobertura; una incidencia individual no equivale necesariamente a clase cancelada. |
| Correcciones posteriores | F8 + estado durable actual | No fabricar snapshot. Las revisiones demostrables se señalan; lo desconocido permanece desconocido. |

## 3. Huecos y límites comprobados

- **Pendientes nuevos:** las fuentes activas de F1/F5 no comparten hoy una fecha durable de primera detección. `fecha_referencia` describe la causa (inicio de periodo, ausencia, creación de reposición, último contacto, etc.) y no siempre equivale a “detectado hoy”. Por eso F9.1 debe devolver “pendientes nuevos” como **no disponible** hasta tener una autoridad de detección fiable; sí puede mostrar pendientes actuales/acumulados.
- **Lecturas con efectos secundarios:** `api/sesiones.php` puede generar sesiones para ADMIN y `api/pagos.php` puede ejecutar reconciliación de sede. F9 no debe usar esos endpoints como fuentes internas.
- **Día histórico:** sin snapshot, una invalidación de pago o corrección de asistencia posterior puede cambiar la lectura actual del día. La UI/API debe etiquetarla como lectura viva, no como cierre histórico.
- **F7:** la funcionalidad de profesores está desplegada pero todavía no Verificada por falta de casos reales suficientes. Cualquier bloque de incidencias/sustituciones conserva sus marcadores de cobertura y no inventa historia previa.
- **F4:** sigue Pendiente como CRM completo. F9 solo puede consumir las proyecciones comerciales ya autorizadas y durables que usan F1/F5/F6; no crea un CRM paralelo.
- **Cero no significa desconocido:** cuando una fuente no tiene cobertura suficiente, F9 devuelve disponibilidad/cobertura explícita y no convierte la ausencia de evidencia en cero.

## 4. Contrato mínimo de F9.1

Primer incremento propuesto: `GET /api/resumen-diario.php?fecha=YYYY-MM-DD&sede=...`.

Características obligatorias:

- acceso `ADMIN` y `VERIFICADOR`, respetando sede; información comercial global solo para ADMIN;
- solo lectura: sin migración, POST, creación de sesiones, reconciliaciones incidentales, cierres ni mensajes;
- respuesta con `fecha`, `actualizado_en`, `sede`, `snapshot=false`, `tipo_lectura=VIVA_RECONCILIABLE`;
- bloque `apertura`: alumnos activos, clases previstas, pendientes financieros actuales, reposiciones, prospectos disponibles e incidencias;
- bloque `cierre`: sesiones registradas, asistencia/cobertura, cobros válidos del día, pagos invalidados del día consultado, altas, pendientes actuales e incidencias;
- cada bloque declara `fuente`, disponibilidad y límite/cobertura cuando aplique;
- “pendientes nuevos” permanece no disponible mientras no exista primera detección durable común;
- fecha futura puede mostrar apertura/clases previstas, pero el cierre debe declarar que todavía no es un hecho cerrado;
- frontend futuro consume el contrato; no recalcula reglas de negocio.

## 5. Criterios para los siguientes micro-pasos

### F9.1 — Backend read-only

Debe demostrar con regresiones que:

- consultar no crea ni modifica filas de dominio;
- una fecha normal, una fecha sin actividad y una fecha con datos incompletos responden sin inventar ceros;
- clases previstas no dependen de que ya exista una sesión;
- sesiones canceladas no entran como realizadas/asistencia válida;
- cobros usan fecha efectiva y estado `VALIDO`;
- invalidaciones no desaparecen silenciosamente;
- ADMIN y VERIFICADOR mantienen su alcance actual;
- no se reconstruyen pendientes nuevos sin fuente durable.

### Implementación preparada de F9.1

El incremento mantiene el alcance del contrato:

- `config/resumen-diario.php` compone fuentes puras para clases previstas, cobros por fecha efectiva, pendientes actuales F1/F5, incidencias F7 y correcciones durables relacionadas con pagos/asistencia;
- `api/resumen-diario.php` es GET-only para `ADMIN`/`VERIFICADOR`, devuelve `snapshot=false` y `tipo_lectura=VIVA_RECONCILIABLE`;
- una fecha pasada no reconstruye clases previstas desde asignaciones actuales: ese bloque queda no disponible sin snapshot;
- una fecha futura permite apertura/planificación actual, pero declara el cierre como no disponible;
- `pendientes_nuevos` permanece no disponible por falta de primera detección durable común;
- los cobros muestran válidos e invalidados por separado y solo `VALIDO` suma ingreso;
- F9 no llama los GET mutantes de sesiones/pagos y no contiene escrituras SQL en su helper;
- Quality incorpora una regresión de contrato y otra MariaDB que comprueba planificación sin sesiones, cobros válidos/invalidados, incidencias/sustituciones y ausencia de mutaciones en las tablas observadas.

F9.1 quedó integrado mediante PR #355. Quality #1730 del head y #1731 de `main` pasaron; el merge `dbce96b49f2700acc27d68fc5f5843fcebee1f8c` fue publicado por Deploy #322. El marcador productivo coincidió exactamente y `/api/health.php` respondió HTTP 200 / `ok:true`. Esto verifica técnicamente el backend, no cierra todavía F9.

### F9.2 — UI mínima

Solo después de F9.1: vista responsive con apertura/cierre, enlaces a las fuentes y avisos claros de cobertura/lectura viva.

### Implementación preparada de F9.2

- `public/resumen-diario.php` consume únicamente `GET /api/resumen-diario.php`; no consulta APIs laterales ni contiene mutaciones.
- La vista conserva `ADMIN` / `VERIFICADOR`, fecha operativa de Cancún y sede resuelta por la autoridad de autenticación.
- Apertura y cierre muestran directamente las cifras, disponibilidad, cobertura y filas entregadas por F9.1; el frontend no recalcula reglas de negocio.
- `snapshot=false`, `VIVA_RECONCILIABLE`, fechas futuras, fuentes parciales y `pendientes_nuevos` no disponibles se presentan de forma explícita.
- Los enlaces de detalle aceptan únicamente rutas internas compatibles con el rol y con lectura segura: no se enlaza la vista de sesiones que puede generar filas y `VERIFICADOR` no recibe enlaces a auditoría ADMIN-only.
- Las incidencias exponen los marcadores forward-only de F7 y advierten que un cero anterior a cobertura no demuestra ausencia; cambios rápidos de fecha cancelan la solicitud anterior para evitar resultados obsoletos.
- El dashboard incorpora un acceso a “Resumen diario”; la cuadrícula queda adaptada a seis acciones en escritorio y dos columnas en móvil.
- `tests/f9-resumen-diario-ui-regression.mjs` protege roles, fuente única F9.1, lectura viva, read-only, enlaces internos, adaptación móvil y ausencia de reconstrucción desde otras APIs.

F9.2 quedó integrado mediante PR #356. Quality #1732 pasó antes de la revisión automática; Codex señaló un P1 y tres P2. Los cuatro hallazgos se corrigieron y se cerraron sus hilos; Quality #1735 del head pasó después de las correcciones. El merge `10de591cc895a1dab49dc11487ec108cf2cd6985` pasó Quality #1736 en `main` y Deploy #323. El marcador productivo coincidió exactamente, `public/resumen-diario.php` pasó `php -l`, `/api/health.php` respondió HTTP 200 / `ok:true` y la ruta protegida respondió 302 sin sesión, como corresponde.

F9.2 queda **Desplegado y verificado técnicamente**. Esto no cierra F9: falta evidencia operativa real.

### F9.3 — Evidencia operativa read-only

Siguiente micro-paso: recolectar evidencia agregada real sin fabricar escenarios ni exponer PII.

La implementación preparada:

- agrega `config/resumen-diario-evidence.php`, collector agregado por sede y sin filas personales;
- ejecuta la consulta dentro de `SET TRANSACTION READ ONLY` para que cualquier mutación accidental falle;
- busca hasta 31 días hacia atrás el último día con actividad real por sede usando las autoridades F9/F6 existentes;
- conserva conteos de sesiones, cobros válidos/invalidados, altas, incidencias, correcciones y disponibilidad de asistencia;
- reconcilia internamente los totales de cobros y las marcas de asistencia devueltas por los contratos existentes;
- registra un caso naturalmente vacío del día actual cuando exista y el caso de planificación histórica no disponible, sin convertir ausencia de evidencia en cero;
- minimiza la salida: no incluye nombres, IDs de alumnos, pagos, sesiones ni mensajes;
- se integra en `bin/production-readiness-evidence.php` bajo `operations.daily_summary_f9`;
- `tests/f9-resumen-diario-operational-evidence-regression.php` protege privacidad, reconciliación, transacción read-only y `HUMAN_REVIEW_REQUIRED`.

F9.3 quedó integrado mediante PR #357. Quality #1737 del head y #1738 de `main` pasaron; el merge `c745034fb07a21a9c683e1039c7759f71d895d9f` fue publicado por Deploy #324 y Ops Field Evidence Once #273 terminó correctamente. El marcador productivo coincidió exactamente y los dos PHP nuevos pasaron `php -l`.\n\nLa ejecución real del collector en producción confirmó `read_only_transaction_completed=true`, actividad real reciente en ambas sedes, cobros reconciliados, asistencia operativa reconciliada cuando estuvo disponible, un caso vacío natural en PALAPAS y el caso histórico incompleto sin reconstrucción ficticia. La salida mantuvo el contrato de privacidad sin filas personales, nombres ni IDs de pagos. El collector conserva `decision=HUMAN_REVIEW_REQUIRED`; esta actualización registra la revisión humana de los criterios de F9 y no convierte el collector en una aprobación automática.\n\nF9.3 queda **Desplegado y verificado operativamente**.

### Cierre de F9

Los criterios de cierre quedaron comprobados en producción: hubo actividad real, un caso vacío natural, un caso histórico incompleto tratado como desconocido, reconciliación de cobros y asistencia disponible, y la lectura completó una transacción `READ ONLY` sin mutaciones. F9 pasa a **Verificado**. Esto no crea snapshots históricos ni cambia las limitaciones documentadas de cobertura.
