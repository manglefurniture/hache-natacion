# F9 — Resumen operativo diario

**Fase:** F9 — Resumen operativo diario  
**Base del diagnóstico F9.0:** `main` `a7aa88e09daad1e0b6db71393b94ec92d9d08353`  
**Fecha:** 2026-09-19  
**Estado:** F9.0 terminado; P-09 resuelta; F9.1 **En implementación** como backend read-only. El código y las regresiones están preparados en rama y pendientes de revisión/merge/despliegue.

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

Este estado no implica todavía despliegue ni verificación operativa; esos hitos se registrarán después del merge.

### F9.2 — UI mínima

Solo después de F9.1: vista responsive con apertura/cierre, enlaces a las fuentes y avisos claros de cobertura/lectura viva.

### Cierre de F9

F9 no pasa a **Verificado** hasta comprobar en producción un día con actividad real, un caso vacío o incompleto cuando exista naturalmente, conciliación de cobros/asistencia y ausencia de mutaciones al consultar.
