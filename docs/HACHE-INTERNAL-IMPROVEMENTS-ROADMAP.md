# Hache Natación — Roadmap de mejoras internas

**Documento maestro:** `docs/HACHE-INTERNAL-IMPROVEMENTS-ROADMAP.md`  
**Repositorio y fuente de verdad:** [manglefurniture/hache-natacion](https://github.com/manglefurniture/hache-natacion)  
**Fecha de elaboración:** 2026-09-15.  
**Base comprobada en GitHub:** `main`, commit [`b304ff10b303d8738c3790354d4f3a3378099b65`](https://github.com/manglefurniture/hache-natacion/commit/b304ff10b303d8738c3790354d4f3a3378099b65).  
**Estado del roadmap:** Fase 1 en implementación; las fases 2–9 permanecen **Pendientes**.
**Autorización de esta tarea:** crear este MD. Ninguna implementación funcional, migración ni operación de producción está autorizada por este documento.

## 1. Propósito

Conservar el contexto de la siguiente etapa de mejoras del sistema administrativo de Hache Natación: qué construir, en qué orden, qué reutilizar, qué decisiones respetar y qué evidencia permitirá dar cada fase por terminada. Cualquier persona o conversación futura debe poder continuar desde aquí sin duplicar módulos ni introducir cambios que contradigan otra fase.

Este roadmap describe trabajo futuro incremental sobre el sistema existente. La elaboración del documento termina al completar y revisar este archivo; no inicia automáticamente la fase 1.

## 2. Alcance

El alcance queda limitado a estas nueve fases, en este orden:

1. Centro de pendientes.
2. Finanzas internas.
3. Expediente 360° del alumno.
4. CRM interno de prospectos / Sharky.
5. Alertas internas.
6. Dashboard operativo.
7. Gestión interna de profesores.
8. Auditoría interna de acciones administrativas.
9. Resumen operativo diario.

Se reutilizarán alumnos, pagos, mensualidades, inscripciones, asistencia, ausencias, reposiciones, profesores, usuarios, sedes y los datos comerciales disponibles de Sharky. Que un dato aparezca en el alcance no significa que ya exista como campo, tabla o comportamiento.

## 3. Qué NO forma parte de este roadmap

- Implementar mejoras durante la creación de este documento o cambiar comportamientos actuales.
- Auditar todo el proyecto, revisar su seguridad de forma general, buscar deuda técnica ajena a estas fases o revisar SEO y frontend público.
- Rediseñar Hache Natación, reemplazar la arquitectura, migrar de tecnología o refactorizar globalmente.
- Modificar infraestructura, VPS, configuración operativa, servicios, workflows o despliegues.
- Añadir nómina, contabilidad fiscal, facturación tributaria, nuevas campañas, nuevos canales de comunicación o funciones comerciales fuera de las nueve fases.
- Cambiar tarifas, porcentajes, convenios, mínimos, elegibilidad, políticas de pago, acceso a clase o reposiciones.
- Sustituir los flujos de Sharky, reactivar Brain en captación, cambiar prioridades comerciales o permitir aprendizaje autónomo que modifique producción.
- Borrar historia para simplificar las nuevas vistas o generar datos retroactivos sin evidencia.

La fase 8 es un historial operativo de acciones; no es una auditoría general de seguridad. Si aparece algo ajeno al alcance, se ignora salvo dependencia directa o riesgo evidente para una fase, que se documentará sin corregirlo dentro de esta tarea.

## 4. Principios de implementación futura

1. **Consultar este documento antes de modificar cualquiera de las áreas de la sección 2.** Leer también el registro de decisiones y progreso, identificar la fase y sus dependencias y verificar qué cambió desde el commit de referencia.
2. **Reutilizar la implementación actual.** Extender consultas, reglas y pantallas relacionadas cuando sea suficiente. Este documento no prescribe tablas nuevas, endpoints nuevos ni otra arquitectura.
3. **Una autoridad por dato o regla.** Los módulos operativos mantienen sus registros; pendientes, alertas, expediente, dashboard y resumen consumen esa información sin mantener saldos, estados de alumno o asistencias paralelos.
4. **Cambios pequeños y reversibles.** Cada incremento futuro delimitará archivos afectados, comportamiento anterior y nuevo, validaciones y forma de retirar el incremento sin perder datos previos.
5. **Preservar contratos.** Mantener referencias, identificadores, roles, alcance por sede, validaciones de negocio, trazabilidad y respuestas utilizadas por consumidores actuales. No ampliar permisos por incorporar una vista agregada.
6. **Datos disponibles y datos desconocidos son distintos.** Un campo ausente no equivale a cero, una fecha desconocida no es la de hoy y falta de evidencia no significa resuelto, pagado o inscrito.
7. **Historia y actualidad son distintas.** No recalcular silenciosamente el pasado con el precio, sede, plan, profesor o porcentaje vigente hoy. Distinguir un resumen actual de un cierre ya guardado.
8. **Reglas claras antes de automatizar.** Umbrales nuevos se deciden expresamente; se conservan los que ya estén definidos. Ningún pendiente o alerta ejecuta por sí mismo una operación administrativa o un mensaje de Sharky.
9. **Preparación para producción sin adelantar despliegues.** En una implementación futura, validar casos de negocio y regresiones afectadas, obtener revisión y registrar evidencia. Integración, despliegue y verificación son hitos distintos. Esta tarea documental no llega a ellos.
10. **Documentar al cambiar.** Actualizar aquí decisiones, fuentes, dependencia afectada, estado y evidencia. Un cambio de alcance requiere decisión explícita, no una interpretación tácita del implementador.

## 5. Estado actual relevante y evidencia

### 5.1 Cómo leer esta comprobación

Se revisaron en GitHub la estructura del repositorio, `AGENTS.md`, documentación normativa de Sharky y archivos concretos relacionados con las nueve fases. No se ejecutó la aplicación, no se consultaron datos reales ni se comprobó el estado de migraciones o servicios en producción.

- **Existente:** identificado en código o documentación versionada; no certifica por sí solo que esté desplegado.
- **Parcial:** existe una base útil, pero la revisión dirigida no acredita todo el alcance futuro.
- **Futuro:** alcance por desarrollar; no implica que se haya demostrado la ausencia absoluta de cualquier pieza similar.

Los enlaces siguientes son rutas relativas del repositorio; la referencia reproducible de esta revisión es el commit indicado en la cabecera. El esquema inicial es una referencia histórica: debe leerse junto con las migraciones y el código vigente, no como fotografía única del modelo actual.

### 5.2 Mapa de fuentes y límites

| Área | Estado comprobado | Evidencia dirigida | Consecuencia para el roadmap |
| --- | --- | --- | --- |
| Base administrativa | Existente: aplicación PHP, APIs administrativas, esquema y migraciones SQL; roles `ADMIN`, `VERIFICADOR` y `ALUMNO`, con contexto de sede. | [auth](../config/auth.php), [esquema de referencia](../database/schema_hache_monteverde_v1.sql), [modelo por sede](../database/migrations/20260816_multi_sede_model.sql). | Continuar sobre estas piezas; no crear roles ni otra arquitectura como prerrequisito. |
| Alumnos | Existente: datos principales, sede, horario preferido, plan actual/programado, observaciones y estados `PENDIENTE`, `ACTIVO`, `BAJA`. | [alumnos](../api/alumnos.php), [gestión de alumno](../api/alumno-gestion.php), [reglas de acceso](../config/reglas-acceso.php). | Separar estado administrativo, obligaciones y derecho a clase. No interpretar `PENDIENTE` como inscripción incompleta en todos los casos. |
| Pagos y abonos | Existente: pagos por inscripción, mensualidad e intensivo; estados `VALIDO`/`INVALIDADO`; contexto con total pagado y saldo del intensivo. | [pagos-smart](../api/pagos-smart.php), [pago-contexto](../api/pago-contexto.php), [migración de abonos](../database/migrations/20260909_intensive_partial_payments.sql). | Los abonos múltiples comprobados son por alumno + curso intensivo. La migración conserva unicidad del pago válido por inscripción y mensualidad. |
| Ajustes e historial financiero | Existente: edición de importe, método y fecha con motivo; la edición registra antes/después en `historial`. La invalidación conserva el pago y recalcula obligaciones relacionadas. | [editar-pago](../api/editar-pago.php), [invalidar-pago](../api/invalidar-pago.php). | No imponer que toda corrección deba borrar o reemplazar el pago: hay operaciones vigentes diferentes. |
| Periodos, reparto y cierres | Existente: periodos financieros por sede, totales por concepto, reparto desde configuración de sede y cierres guardados. | [periodos-financieros](../config/periodos-financieros.php), [cierres-mensuales](../api/cierres-mensuales.php), [resumen-financiero](../api/resumen-financiero.php), [estándar de reportes](REPORTES.md). | La fase 2 completa y concilia la visión; no inventa otro motor contable ni porcentajes. |
| Asistencia, ausencias y reposiciones | Existente: sesiones, marcas de asistencia, avisos de ausencia, reposiciones regulares y tratamiento de ausencias/reposiciones de intensivos. | [sesiones](../api/sesiones.php), [asistencia](../api/asistencia.php), [ausencias programadas](../api/ausencias-programadas.php), [modelo de asistencia](../database/migrations/20260816_attendance_model.sql). | Preservar estados, cierre de sesiones, elegibilidad y límites vigentes. Las reposiciones de ambos productos no tienen una representación idéntica. |
| Pendientes y alertas | Parcial: alertas derivadas por sede sobre mensualidad, altas pendientes, ausencia, reposición y fin de intensivo; obligaciones regulares por alumno. No se acredita en estas APIs un seguimiento persistente general pendiente/atendido/resuelto. | [alertas](../api/alertas.php), [obligaciones-alumnos](../api/obligaciones-alumnos.php). | La fase 1 organiza atención sobre estas señales; la fase 5 amplía reglas y presentación de alertas. |
| Expediente | Parcial: ficha con datos, observaciones y acciones; API de timeline que combina pagos, intensivos, asistencia, avisos e historial. | [ficha-alumno](../public/ficha-alumno.php), [timeline-alumno](../api/timeline-alumno.php). | Ampliar la ficha y la línea de tiempo existentes. No se verificó un campo de nivel académico unificado en la API de alumnos ni un módulo separado de notas con versiones. |
| Sharky / base para CRM | Existente: memoria comercial estructurada, atribución, estado conversacional, contactos con roles y referencias de alumno/profesor. Parcial respecto de un CRM interno. | [memoria comercial](../config/sharky-commercial-memory.php), [modelo de orquestador](../database/migrations/20260902_sharky_orchestrator.sql), [contactos](../database/migrations/20260908_sharky_contact_book.sql), [panel Sharky](../api/sharky-admin.php). | Contacto, identidad, estado conversacional y etapa comercial no son la misma cosa. No se acredita un pipeline CRM completo con los seis estados propuestos. |
| Dashboard | Existente: indicadores por sede, fecha operativa, facturación por periodo, alumnos activos, pendientes, mensualidades, intensivos, avisos y reposiciones. | [dashboard](../api/dashboard.php), [tiempo operativo](../config/dashboard-tiempo.php). | La fase 6 mejora el dashboard vigente y define fuentes verificables para cada indicador nuevo. |
| Profesores | Parcial: alta/edición, activo/inactivo, asignaciones de horario y cancelaciones por profesor/sesión; soporte versionado de docencia compartida. | [profesores](../api/profesores.php), [modelo de profesores](../database/migrations/20260907_sharky_member_ops.sql), [docencia compartida](../database/migrations/20260909_professor_coteaching.sql). | No presentar activo/inactivo como inexistente. Queda por definir la vista integrada de clases, sustituciones, incidencias, carga e historia. |
| Auditoría | Parcial: `auditoria_eventos`, consulta administrativa e `historial` del alumno; Sharky mantiene además su auditoría de acciones. | [modelo operativo](../database/migrations/20260816_v1_operations_layer.sql), [auditoria](../api/auditoria.php), [edición de pago](../api/editar-pago.php), [auditoría Sharky](../database/migrations/20260902_sharky_orchestrator.sql). | Reutilizar y completar cobertura; no asumir que todas las acciones ya guardan antes/después ni confundir resultado técnico con cambio confirmado. |
| Resumen diario | Futuro como vista unificada de apertura/cierre del día; existen insumos en dashboard, sesiones, pagos y alertas. | Fuentes anteriores. | Componer información existente; no duplicar cálculo ni crear un cierre contable adicional. |

### 5.3 Diferencias y riesgos concretos que deben preservarse o aclararse

1. **Abono no significa liquidación ni derecho a clase.** `pago-contexto.php` distingue `PENDIENTE`, `ANTICIPO` y `PAGADO` para el intensivo. `regla_intensivo_pagado()` compara la suma de pagos válidos del alumno/curso con el precio. No restablecer la restricción antigua de un solo pago por intensivo ni sumar los abonos de otros alumnos del mismo curso.
2. **Mensualidad vigente y mensualidad vencida son señales diferentes.** Existen ciclos `P1`/`P15` para Palapas, rangos de vigencia y obligaciones cubiertas históricamente o por continuidad cuando corresponde. No crear deuda por diferencia con la tarifa actual ni por ausencia de un recibo cuando una excepción vigente cubre la obligación.
3. **Periodo financiero y fecha de cobro no son equivalentes.** `financiero_totales()` atribuye mensualidades por mes/año de la obligación y usa fecha de inscripción o inicio de curso para los otros conceptos dentro del rango financiero. El resumen financiero consultado usa rangos de mes calendario; cierres y dashboard reutilizan periodos financieros. Una futura vista debe explicar su base temporal y conciliar diferencias, sin cambiar reglas silenciosamente.
4. **“Activo” tiene significados actuales distintos.** El dashboard cuenta regulares con mensualidad pagada vigente e intensivos vigentes con algún pago válido, excluyendo bajas. El derecho a clase exige las reglas de `reglas-acceso.php`, incluida liquidación del intensivo. El nuevo dashboard no puede presentar su conteo como autorización de acceso ni modificar esa autorización para que coincidan.
5. **Consultar una API no siempre es una lectura sin efectos.** En el código revisado, `pago-contexto.php` puede promover planes programados para ADMIN y el GET de `sesiones.php` puede generar sesiones para ADMIN. Las nuevas vistas de consulta deben reutilizar reglas sin disparar escrituras incidentales. Esta observación no autoriza cambiar esos endpoints ahora.
6. **La línea de tiempo ya compone fuentes y algunas horas son de presentación.** Por ejemplo, el timeline asigna una hora fija a asistencias y a eventos con fecha sin hora. No convertir esa hora en una marca de auditoría real ni inventar una secuencia exacta de acciones del mismo día.
7. **El historial no está garantizado de forma global.** La gestión actual de alumnos contiene una operación de eliminación definitiva con sus controles; no se modifica ni se incorpora automáticamente al expediente nuevo. La nueva gestión de profesores debe usar inactivación: el modelo tiene relaciones que podrían eliminarse en cascada ante un borrado físico.
8. **Docencia compartida.** La migración de profesores documenta que una cancelación individual no equivale a cancelar la clase si queda otro profesor activo asignado disponible. Sustituciones, carga y resumen diario deben preservar esta distinción.

Estas observaciones son dependencias directas del roadmap, no una lista de correcciones autorizadas ni una auditoría del proyecto.

## 6. Dependencias entre módulos

### 6.1 Autoridad y consumo

| Información o responsabilidad | Autoridad que se debe conservar | Consumidores futuros |
| --- | --- | --- |
| Alumno, sede, horario, plan y estado administrativo | Módulos actuales de alumnos y reglas vigentes | F1, F3, F5, F6, F9 |
| Obligaciones, pagos, saldo y reparto | Registros financieros y reglas actuales; visión completada en F2 | F1, F3, F5, F6, F9 |
| Sesiones, asistencia, ausencia y reposición | Módulos operativos actuales | F1, F3, F5, F6, F7, F9 |
| Atención de asuntos | F1: estado de gestión y responsable, sin alterar el registro causante | F3, F5, F6, F9 |
| Identidad y conversación de Sharky | Core Rules, flujos y autoridades vigentes | F4 consume; F1, F5, F6 y F9 consumen la proyección comercial de F4 |
| Seguimiento comercial interno | F4: datos trazables y mapeo de estados aprobado | F1, F5, F6, F9 |
| Detección y explicación de alertas | F5 sobre reglas existentes/expresamente definidas | F1 y F9; F6 consume agregados definidos |
| Profesores y asignaciones | Módulo vigente, ampliado en F7 | F3 cuando haya evento del alumno; F6 y F9 |
| Acciones administrativas | Historial y auditoría existentes, completados en F8 | F1–F7 según necesidad y F9 |
| Indicadores agregados | Definiciones compartidas documentadas en F6 | Dashboard y F9 |

### 6.2 Dependencias sin ciclos ni fases nuevas

- **F1 no espera a F2, F4 o F5 completas.** Empieza con las señales verificadas. Usa los cálculos actuales; incorpora saldos más completos de F2, prospectos de F4 y reglas de F5 cuando estén disponibles. Los tipos aplazados permanecen explícitos en el progreso.
- **F2 no depende de que F1 resuelva un pendiente.** Un pago válido o una corrección financiera determina la obligación; la etiqueta de atención nunca la determina.
- **F3 consume F1/F2 y la historia ya existente.** No necesita esperar a F8 para mostrar eventos comprobados ni a F7 para mostrar relaciones de profesores ya disponibles.
- **F4 alimenta seguimiento interno.** El CRM no controla el funnel ni exige modificarlo para empezar. La sede comercial puede estar todavía sin confirmar y debe mantenerse así.
- **F5 amplía la detección compartida con F1.** No crea otra cola con estados de atención incompatibles ni vuelve a calcular deuda con una regla distinta.
- **F6 integra lo disponible de F1–F5.** Los indicadores que requieran F7/F8 quedan pendientes o identificados con su cobertura real; no se adelantan estas fases.
- **F7 reutiliza clases, usuarios y auditoría existentes.** Una sustitución concreta puede necesitar trazabilidad mínima en ese mismo incremento, sin adelantar toda F8.
- **F8 completa la cobertura transversal.** No justifica que las fases anteriores introduzcan acciones sensibles sin el registro mínimo que ya puedan soportar los mecanismos actuales.
- **F9 consume los resultados consolidados de F1–F8.** Si un dato no está disponible, lo declara; no calcula una aproximación silenciosa.

## 7. Las nueve fases

### FASE 1 — Centro de pendientes

**Problema que resuelve.** La información que necesita atención está repartida entre pagos, obligaciones, alertas, asistencia, reposiciones y altas. Ver una señal no permite saber de forma general si alguien ya la atendió.

**Objetivo.** Concentrar asuntos administrativos con causa comprobable, estado de atención y acceso al registro donde se resuelven.

**Alcance.** Saldos confirmados, mensualidades vencidas, reposiciones pendientes, inscripciones realmente incompletas, alumnos con varias ausencias, prospectos sin seguimiento cuando F4 aporte evidencia y otras incidencias derivadas de datos existentes, siempre con regla documentada. El primer incremento utiliza las fuentes actuales y registra qué tipos requieren fases posteriores.

**Dependencias.** Alertas y obligaciones actuales; módulos de alumnos, pagos y sesiones; permisos/sede. F2 enriquecerá deuda, F4 seguimiento y F5 reglas. Reutilizar auditoría existente para las acciones de atención.

**Comportamiento esperado.** Cada asunto identifica tipo, registro causante, alumno/prospecto si existe, sede, fecha de referencia y explicación. Debe distinguir:

- **Pendiente:** requiere atención.
- **Atendido:** hubo gestión; conserva quién y cuándo cuando corresponda. No significa que se haya pagado, completado o resuelto la causa.
- **Resuelto:** existe evidencia de que la causa dejó de aplicar o una resolución administrativa documentada dentro de las reglas vigentes. No cancela una obligación financiera.

Un mismo hecho no debe producir duplicados al recargar. Una nueva mensualidad o una recurrencia debe distinguirse del asunto anterior sin borrar su historia. Antes de cerrar un caso se revalida el registro de origen. Resolver o atender desde este centro nunca debe marcar pagos, asistencia ni inscripciones como completados por simple cambio de etiqueta.

**Información necesaria.** Referencia estable a entidad/registro, tipo de señal, periodo o sesión/curso, sede, evidencia, estado de atención, fecha de detección y responsable/fecha de gestión cuando aplique. **Pendiente de decidir:** granularidad por tipo, persistencia mínima de la gestión, permisos de atención y criterio de recurrencia; no se prescribe una tabla.

**Riesgos de compatibilidad.** Duplicar alertas agregadas como si fueran casos individuales; confundir falta de mensualidad vigente con deuda cuantificada; generar pendientes por inscripciones históricamente cubiertas; perder restricciones por sede al agrupar.

**Qué NO debe hacerse.** Crear deudas, alumnos o reposiciones automáticamente; cambiar elegibilidad; exigir un CRM nuevo para inaugurar el centro; enviar recordatorios comerciales; considerar una etiqueta manual como prueba de pago.

**Criterio de terminado.** Los tipos habilitados tienen fuente y regla verificables, enlace al detalle y estados persistentes de gestión; recargas no duplican casos; atención identifica al responsable cuando corresponde; resolución conserva evidencia e historia; se prueban un pago posterior, una invalidación, una nueva obligación y el aislamiento por sede. Tipos diferidos quedan registrados y la fase completa no se declara verificada mientras falte alcance comprometido sin decisión documentada.

**Estado:** En implementación. Se preparó el incremento inicial con mensualidad regular sin cobertura, inscripción regular sin cobertura y reposición regular disponible. Se difieren saldos de intensivo a F2; prospectos sin seguimiento a F4; y varias ausencias, continuidad de intensivos y nuevas reglas de alerta a F5. La gestión se conserva en una tabla específica, sin modificar las fuentes de dominio. Falta ejecutar la regresión PHP nueva y las pruebas de integración con runtime disponible antes de pasar a revisión.

### FASE 2 — Finanzas internas

**Problema que resuelve.** Hay pagos, abonos, reportes y cierres, pero hace falta una lectura consistente de obligaciones, cobros, saldos y reparto sin confundir sus periodos.

**Objetivo.** Completar la visión financiera utilizando los registros y reglas existentes.

**Alcance.** Monto total de la obligación, monto pagado, saldo pendiente, fecha y periodo, concepto, método cuando exista, abonos permitidos, invalidaciones, ajustes, historial, cierres/resúmenes, sede y distribución Hache–socio cuando aplique.

**Dependencias.** Pagos, inscripción, mensualidad, curso/alumno, planes, reglas de acceso, periodos financieros, sedes y cierres. Integración con F1 sin que sus estados determinen saldos. Historial existente para las operaciones sensibles.

**Comportamiento esperado.** Mostrar obligación y movimientos relacionados con referencia al origen. Para intensivos, respetar la suma de pagos `VALIDO` por alumno + curso y el saldo calculado actualmente, incluidos segundo abono y liquidación. Mantener un único pago válido por inscripción/mensualidad; extender abonos a otros conceptos requeriría una decisión distinta, que este roadmap no toma.

La deuda debe provenir de obligaciones verificadas y ajustes autorizados. El precio actual del plan no recrea una deuda histórica. Invalidar un pago lo mantiene en historial y actualiza la lectura del saldo. Mostrar por separado fecha de cobro, periodo de la obligación y periodo financiero. Comparar cálculos actuales con cierres guardados sin sobrescribirlos. El reparto toma porcentajes, socio y mínimos de las autoridades de sede vigentes; no fija cifras nuevas. Preservar conciliaciones y comisiones existentes si una consulta las consume.

Si se extienden reportes administrativos/financieros, respetar [REPORTES.md](REPORTES.md): exportación PDF con identidad Hache, resolución por sede/periodo y CSV cuando corresponda. El detalle interno puede mostrar método de pago; el PDF de liquidación conserva su contenido y exclusiones vigentes.

**Información necesaria.** Identificadores de pago y obligación, alumno, sede atribuida a la operación, importe total/cobrado, validez, fechas, concepto, método si está registrado, motivo/autor del ajuste, reglas del periodo y del convenio. **Pendiente de decidir:** presentación de diferencias entre mes calendario y periodo personalizado, tratamiento explícito de ajustes posteriores a un cierre y cobertura de saldos sin obligación registrada. Conservar las reglas actuales hasta resolver esas decisiones.

**Riesgos de compatibilidad.** Sumar el total del curso una vez por cada abono; duplicar mensualidad e importe del pago; trasladar ingresos históricos a la sede actual del alumno; alterar reparto por recalcular con parámetros actuales; presentar un anticipo como curso liquidado; tratar diferencias de calendario como errores de datos.

**Qué NO debe hacerse.** Inventar porcentajes, mínimos o descuentos; cambiar ciclos; generalizar abonos a mensualidades/inscripciones; invalidar o ajustar registros como parte de una consulta; reabrir o rehacer cierres automáticamente; crear contabilidad paralela.

**Criterio de terminado.** Total, pagado y saldo se explican por registros concretos y coinciden con sus operaciones de origen; se verifican cero pagos, uno y varios abonos, liquidación, rechazo de sobrepago, edición e invalidación. Se comprueban periodos P1/P15 cuando corresponda, sedes, excepción de inscripción cubierta y comparación cierre/actual. Las vistas F1/F3/F6/F9 consumen la misma definición financiera aplicable y no suman dos veces el mismo dinero.

**Estado:** Pendiente. Pagos, abonos de intensivo, reparto y cierres ya tienen implementación versionada.

### FASE 3 — Expediente 360° del alumno

**Problema que resuelve.** La ficha y timeline existentes no acreditan todavía toda la visión unificada solicitada.

**Objetivo.** Facilitar la consulta completa de un alumno desde su expediente actual, con datos enlazados a las fuentes originales.

**Alcance.** Datos principales, estado administrativo, sede, horario regular y de intensivo según corresponda, nivel si hay fuente confirmada, plan actual/programado, pagos, mensualidades, asistencia, ausencias, reposiciones, historial, notas internas y eventos relevantes.

**Dependencias.** F1 para asuntos de atención, F2 para visión financiera, ficha/timeline actuales y módulos académicos. La información comercial de F4 se enlaza solo cuando la identidad esté verificada. F8 completará trazabilidad posteriormente.

**Comportamiento esperado.** Una vista por alumno con secciones y línea de tiempo compuesta desde los registros existentes. Los movimientos financieros conservan su identidad y estado; las reposiciones distinguen producto y origen. Las notas reutilizan observaciones disponibles cuando sea suficiente; una capacidad de notas con autor/fecha se define como extensión futura, sin atribuir esos datos al texto histórico que no los tiene.

Cada evento muestra fecha disponible, tipo, origen y acceso al detalle. Un pago y su registro de auditoría pueden relacionarse sin duplicarlo como dos cobros. La fecha del evento y la de registro deben distinguirse cuando existan. El nivel comercial declarado a Sharky no se convierte automáticamente en una evaluación académica del alumno.

**Información necesaria.** ID del alumno y relaciones verificadas, fuentes descritas en la sección 5, fecha del hecho/registro si existen, observaciones y referencias de historial. **Pendiente de decidir:** fuente de nivel, notas con autoría, cobertura de cambios históricos de horario/sede y regla de agrupación de eventos relacionados. Datos no verificados se presentan como no disponibles.

**Riesgos de compatibilidad.** Confundir contacto/responsable con alumno; mezclar historia entre sedes; sobrescribir planes programados; perder eventos al filtrar solo entidades activas; presentar horas sintéticas del timeline como marcas exactas de auditoría.

**Qué NO debe hacerse.** Crear una segunda ficha maestra o copiar tablas enteras al expediente; inferir nivel; recrear pasado desde el estado actual; añadir acciones de borrado para limpiar la historia; hacer públicas las notas internas.

**Criterio de terminado.** Un alumno regular, uno de intensivo y uno con continuidad permiten recorrer datos, pagos, asistencia y eventos hasta su fuente; no se duplican cobros ni reposiciones; pagos invalidados siguen identificables; notas y datos ausentes se distinguen; estados e historia sobreviven a una baja lógica y los permisos por sede se conservan.

**Estado:** Pendiente. Existe ficha y línea de tiempo parcial que deben ampliarse.

### FASE 4 — CRM interno de prospectos / Sharky

**Problema que resuelve.** Sharky conserva información comercial útil, pero esa base no equivale a un CRM interno completo y trazable.

**Objetivo.** Permitir que la información comercial ya generada por Sharky alimente posteriormente una vista de seguimiento interno, preservando íntegramente sus reglas y flujos.

**Alcance.** Prospecto/contacto y destinatario de las clases cuando sean distintos; origen, campaña/referral si existe, producto de interés confirmado o atribuido con su distinción, nivel declarado, sede confirmada, estado comercial, último contacto verificable e inscripción resultante.

La secuencia **Nuevo → Calificado → Información enviada → Interesado → Inscripción iniciada → Inscrito** es una propuesta conceptual para lectura comercial. No es un enum aprobado, no exige cambiar el estado conversacional y no sustituye estructuras existentes. Se acordará un mapeo desde hechos confirmados, conservando “sin información” cuando falte evidencia.

**Dependencias.** [Core Rules](../SHARKY-CORE-RULES.md), [patrones positivos](SHARKY-POSITIVE-PATTERNS.md), [guía lingüística](SHARKY-LANGUAGE-GUIDE.md), [funnel vigente](SHARKY-3-META-FLOW.md), memoria comercial, contactos/atribución y registros administrativos. F1 recibe casos sin seguimiento; F3 recibe la relación con el alumno; F5/F6/F9 consumen datos comerciales definidos aquí.

**Comportamiento esperado.** Proyectar información existente con fuente y fecha; gestionar seguimiento interno sin escribir en el cursor, producto o sede del funnel. Distinguir nombre sugerido por perfil, contacto, alumno, responsable y profesor. Mantener `entry_source` real (`meta_ad`, `web`, `direct`, `referral`) y no convertir interés de campaña en elección del usuario.

Una inscripción resultante exige referencia a un alta real y relación de identidad comprobada; abrir el Flow o enviar información no demuestra inscripción ni pago. Último mensaje recibido, último envío, actualización técnica de estado y último contacto comercial deben etiquetarse según la evidencia disponible. La expiración del estado conversacional impide prometer historia completa sin una fuente persistente válida.

**Información necesaria.** Referencias existentes como `contact_hash`, relación `alumno_id` cuando esté confirmada, atribución en `sharky_referrals`, contexto comercial y fechas fiables. `sharky_contacts.role` clasifica identidad, no etapa comercial. Respetar el almacenamiento protegido vigente; el CRM no justifica copiar conversaciones completas o datos sensibles a documentación. **Pendiente de decidir:** mapeo de etapas, identidad de oportunidad cuando las clases sean para otra persona, último contacto, asociación de inscripción y retención mínima compatible con las reglas actuales.

**Riesgos de compatibilidad.** Degradar alumno a prospecto; confundir campaña con preferencia; mezclar contactos; mostrar un campo no confirmado como definitivo; reabrir seguimientos cerrados por deliberación o takeover; convertir el CRM en un segundo controlador de Sharky.

**Qué NO debe hacerse.** Modificar flujos, prompts, routing, prioridades, horarios, precios, pagos o decisiones de Sharky; imponer el pipeline conceptual; reactivar Brain en el funnel; enviar mensajes o crear altas desde una etiqueta comercial; tratar una inferencia de IA como autoridad administrativa.

**Criterio de terminado.** Un dato comercial puede rastrearse a su fuente; el mapeo aprobado distingue atribución, elección e identidad; una conversión enlaza al alta real sin duplicarla; se conserva el contexto de alumno conocido, entradas Meta/web/direct/referral y takeover. Incorporar CRM no cambia decisiones, mensajes ni operaciones de los recorridos actuales; las regresiones de Sharky aplicables conservan sus resultados.

**Estado:** Pendiente. Hay memoria, contactos y atribución; el CRM descrito no se implementa ahora.

### FASE 5 — Alertas internas

**Problema que resuelve.** Las alertas actuales cubren algunos casos; falta una cobertura común y explicable para las señales previstas en el roadmap.

**Objetivo.** Priorizar atención mediante reglas deterministas sobre datos comprobados, compartidas con el centro de pendientes.

**Alcance.** Varias ausencias consecutivas, saldo pendiente, mensualidad vencida, reposición pendiente, prospecto sin seguimiento, intensivo terminado sin continuidad e inconsistencias administrativas directamente relevantes. Reutilizar las alertas existentes y diferenciar fin próximo de intensivo de finalización sin continuidad.

**Dependencias.** F1 para gestionar asuntos; F2 para deuda; F3 como acceso a detalle; F4 para seguimiento; sesiones, avisos, reposiciones y continuidad existentes.

**Comportamiento esperado.** Cada regla explica qué hecho la activa, a quién/sede/periodo afecta y cómo se verifica su resolución. Definir antes de habilitar una regla: fuente, criterio, umbral si hace falta, exclusiones, fecha de evaluación, deduplicación y vínculo al pendiente. Preservar parámetros existentes, como el aviso de fin de intensivo, sin reemplazarlos por otros inventados.

Una ausencia consecutiva debe basarse en clases aplicables y marcas válidas, distinguiendo ausencia justificada, injustificada, clase cancelada y sesión sin asistencia registrada. Los casos sin datos suficientes se señalan para revisión; no se convierten automáticamente en ausencias. Una alerta puede desaparecer al resolverse su causa y el historial de atención permanece en F1.

**Información necesaria.** Fuente de cada regla, estados de dominio, periodos, fecha operativa, evidencia y responsable cuando corresponda. **Pendiente de decidir:** cantidad de ausencias, plazo de seguimiento, momento de evaluar falta de continuidad y nivel de prioridad de reglas nuevas; ninguno queda fijado por ejemplos de este documento.

**Riesgos de compatibilidad.** Doble alerta sobre un mismo saldo; contar días naturales en vez de clases aplicables; confundir aviso con ausencia; alertar a prospectos que ya están inscritos o cuyo seguimiento se pausó; computar “sin datos” como incumplimiento.

**Qué NO debe hacerse.** Introducir IA compleja, puntuaciones opacas o aprendizaje automático; abrir otra cola de gestión; mandar comunicaciones externas; aplicar bloqueos o sanciones; generar crédito de reposición desde una alerta.

**Criterio de terminado.** Cada regla habilitada tiene ejemplos de activación y no activación, umbral aprobado cuando aplique, fuente y resolución comprobables; se prueban corrección de asistencia, pago invalidado, clase cancelada, repetición de consulta y prospecto ya convertido; la alerta y su pendiente representan el mismo hecho sin duplicidad.

**Estado:** Pendiente. Se ampliará la base de alertas existente.

### FASE 6 — Dashboard operativo

**Problema que resuelve.** Los indicadores actuales son útiles, pero faltan definiciones comunes y cobertura de los nuevos módulos.

**Objetivo.** Extender el dashboard interno para que cada cifra responda una pregunta operativa y permita comprobar los registros que la componen.

**Alcance.** Alumnos activos, nuevos alumnos, bajas, ingresos, saldos, asistencia, prospectos, conversiones e información por sede. Priorizar cifras, comparaciones necesarias y acceso al detalle; evitar gráficas decorativas.

**Dependencias.** Dashboard y tiempo operativo actuales; F1–F5; datos actuales de profesores, ampliables tras F7. F8 aportará cobertura histórica adicional donde sea necesaria.

**Comportamiento esperado.** Cada indicador muestra periodo/fecha, sede, definición, fuente y momento de actualización. El detalle debe reproducir el total bajo el mismo filtro. No cambiar la definición de un indicador existente bajo su mismo nombre sin documentar compatibilidad y decisión. Diferenciar alumnos únicos, operaciones de pago y participaciones en cursos.

**Información necesaria y contrato de indicadores.** Cada definición futura debe completar fuente, filtros, unidad, fecha usada, tratamiento de anulados/duplicados/datos ausentes y regla de agregación por sede:

| Indicador | Punto de partida verificable | Precisión que debe conservarse o decidirse |
| --- | --- | --- |
| Alumnos activos | `api/dashboard.php`: mensualidad pagada vigente o intensivo vigente con pago válido, excluyendo BAJA. | Conservar el cálculo actual hasta decisión documentada; no confundir con `estado_administrativo` ni derecho a clase. |
| Nuevos alumnos | `alumnos.created_at`, `fecha_inicio` e historial cuando exista. | Decidir si mide alta en sistema o inicio de clases; no mezclar ambos ni contar reactivaciones como altas nuevas. |
| Bajas | Estado BAJA e historia de cambio cuando esté disponible. | Estado actual no permite fechar todas las bajas pasadas; no inferirlas de `updated_at`. |
| Ingresos por periodo | `financiero_totales()` y F2. | Explicitar periodo financiero y atribución por concepto; no llamarlo cobro diario. |
| Saldos pendientes | Obligaciones y pagos válidos conciliados en F2. | No multiplicar deuda por pagos, alertas o eventos de historial; no inventar deuda sin fuente. |
| Asistencia | `asistencias` relacionadas con `sesiones`. | Definir denominador y cobertura; separar justificadas, injustificadas, canceladas y sin registro. |
| Prospectos | Proyección validada de F4. | Separar contacto único, destinatario de clases y oportunidad según el mapeo aprobado. |
| Conversiones | Relación comprobada prospecto–inscripción de F4. | Definir cohorte/periodo y denominador; un Flow abierto, mensaje enviado o pago no son conversiones intercambiables. |
| Información por sede | Autoridad de sede de cada registro y permisos actuales. | No imputar historia a la sede actual sin evidencia; mostrar sin sede confirmada cuando corresponda a prospectos. |

**Riesgos de compatibilidad.** Cambiar semántica de activos, duplicar alumno por abonos o cursos, atribuir ingresos por fecha equivocada, comparar cifras con periodos distintos, mostrar ceros por ausencia de datos.

**Qué NO debe hacerse.** Crear otro dashboard con cálculos paralelos; inventar metas, métricas o conversiones; mostrar gráficas sin acción operativa; modificar cobros o asistencia para ajustar una cifra.

**Criterio de terminado.** Cada indicador publicado tiene contrato documentado y un detalle reconciliable; se comprueban sedes, límites del periodo, alumnos con varios pagos y datos incompletos. Las cifras existentes preservan su significado o cuentan con una decisión explícita de cambio y comparación antes/después. Indicadores sin fuente suficiente permanecen pendientes, sin valores ficticios.

**Estado:** Pendiente. El dashboard administrativo existe; esta fase lo completa.

### FASE 7 — Gestión interna de profesores

**Problema que resuelve.** El módulo actual cubre estado y horarios, pero falta una lectura integrada de actividad y antecedentes del profesor.

**Objetivo.** Mejorar la gestión operativa y la trazabilidad de profesores preservando las referencias históricas.

**Alcance.** Activo/inactivo, horarios asignados, clases, sustituciones, incidencias, carga e historial. Reutilizar `profesores`, `profesor_horarios` y `profesor_cancelaciones`; no asumir que ya existe un registro completo de sustituciones o asignaciones históricas por clase.

**Dependencias.** Profesores y horarios actuales, sesiones/asistencia y permisos; reglas de docencia compartida; historial/auditoría existentes. Si una integración toca operaciones de profesores a través de Sharky, aplicar la sección 10 sin modificar los recorridos actuales.

**Comportamiento esperado.** Inactivar a un profesor preserva su ID, clases e incidencias anteriores. La inactivación afecta disponibilidad futura según el comportamiento que se acuerde, sin reescribir quién estuvo asociado a una clase pasada. Una sustitución relaciona clase, profesor original y sustituto cuando haya evidencia, fecha y motivo, y se distingue de una asignación regular de horario.

Mostrar por separado carga prevista y realizada según las fuentes disponibles; que un profesor figure hoy en un horario no demuestra que impartió todas sus clases históricas. En docencia compartida, una indisponibilidad individual no debe cancelar la sesión si permanece otro docente disponible conforme a las reglas actuales.

**Información necesaria.** Profesor, estado, horario/sede, sesión/fecha, asignaciones disponibles, cancelación o sustitución, motivo y autor cuando existan. **Pendiente de decidir:** unidad de carga (clases/horas), vigencia temporal de asignaciones, registro mínimo de sustitución y tratamiento de futuras asignaciones al inactivar.

**Riesgos de compatibilidad.** Borrado en cascada de asignaciones; modificar el pasado al editar un horario; contar doble una clase compartida; confundir registro de profesor con usuario de acceso; cancelar globalmente por ausencia de un docente.

**Qué NO debe hacerse.** Eliminar físicamente profesores como baja; borrar asignaciones históricas; inventar clases impartidas; crear un rol nuevo sin necesidad demostrada; añadir nómina, honorarios o evaluaciones no solicitadas.

**Criterio de terminado.** Un profesor inactivo conserva ficha, referencias e historial; se distinguen asignación, sustitución e incidencia; carga tiene fuente y periodo; se verifican una clase con un docente, una compartida, una sustitución y una inactivación. Los casos sin historia suficiente se declaran sin reconstrucción ficticia.

**Estado:** Pendiente. Activo/inactivo, asignaciones y cancelaciones ya tienen base versionada.

### FASE 8 — Auditoría interna

**Problema que resuelve.** Ya existe auditoría e historial, pero no se ha acreditado una cobertura uniforme de todas las acciones administrativas relevantes.

**Objetivo.** Completar una historia consultable de quién hizo qué, cuándo y sobre qué registro, incluyendo valor anterior/nuevo cuando tenga sentido.

**Alcance.** Modificaciones de pagos, cambios de horario/sede, anulaciones, cambios importantes del alumno, acciones sensibles de las fases anteriores y gestión de profesores/pendientes cuando corresponda.

**Dependencias.** `auditoria_eventos`, `historial`, usuarios, referencias de dominio y operaciones de F1–F7. La auditoría de acciones de Sharky conserva su función e idempotencia; no se reemplaza por el historial administrativo.

**Comportamiento esperado.** Cada acción cubierta identifica actor humano o sistema, operación, fecha real, entidad y referencia; motivo y antes/después cuando sean pertinentes y estén disponibles. Distinguir intento, fallo y operación confirmada si se registran: una solicitud HTTP no demuestra que cambió un pago. Relacionar eventos de un mismo hecho sin duplicar operaciones financieras.

La información histórica existente permanece legible. Cuando falte un valor anterior, se indica que no fue registrado; no se infiere desde el presente. Los eventos futuros deben ser consistentes con el resultado de la operación. El alcance de sede y la visibilidad de detalles respetan los permisos existentes; no se exponen secretos ni conversaciones completas.

**Información necesaria.** Usuario/actor identificado, acción, entidad/ID, marca temporal, resultado, motivo, cambios relevantes y relación con el evento de origen. **Pendiente de decidir:** cobertura exacta por acción, representación compatible de antes/después y correlación entre historial administrativo y acciones Sharky cuando sea necesaria.

**Riesgos de compatibilidad.** Registrar éxito antes de confirmar el cambio; perder autor por baja de usuario; copiar datos sensibles; duplicar auditoría e historial con significados distintos; prometer trazabilidad retroactiva que nunca se guardó.

**Qué NO debe hacerse.** Reescribir o eliminar historia para normalizarla; crear una auditoría general del proyecto; registrar claves, tokens o datos personales innecesarios; usar auditoría para ejecutar acciones o reintentar pagos.

**Criterio de terminado.** Existe una matriz de acciones cubiertas con fuentes y campos disponibles; cada acción incluida permite responder quién/qué/cuándo/registro y antes/después cuando corresponda. Se comprueban éxito, fallo, repetición y conservación de historial; la vista distingue evidencias existentes de campos no registrados. No se alteran movimientos financieros ni se atribuyen cambios ficticios.

**Estado:** Pendiente. Se amplía la cobertura actual, no se crea el concepto desde cero.

### FASE 9 — Resumen operativo diario

**Problema que resuelve.** La operación requiere consultar varios módulos para preparar el día y revisar lo ocurrido al terminarlo.

**Objetivo.** Crear una vista de apertura y cierre operativo del día alimentada por los otros módulos.

**Alcance.** Inicio: alumnos activos, clases previstas, pagos pendientes, reposiciones, prospectos e incidencias. Cierre: asistencias, ausencias, cobros, altas, pendientes nuevos e incidencias.

**Dependencias.** F1–F8 según la información presentada; fecha operativa de `config/dashboard-tiempo.php`, reglas de clase, fuentes financieras y contratos de indicadores de F6.

**Comportamiento esperado.** Mostrar día, sede, hora de actualización y cobertura de cada bloque. La apertura distingue clases previstas de sesiones ya registradas; consultar el resumen no genera clases. El cierre muestra hechos del día y pendientes al momento de consulta, sin cerrar automáticamente sesiones ni periodos financieros.

Cobros del día usan fecha de cobro y validez conforme a la definición aprobada en F2; no se toma directamente el total del periodo financiero. Altas del día reutilizan la definición de F6. Pendientes nuevos se distinguen de pendientes acumulados; si falta fecha de detección fiable, no se presenta como nuevo. Revisiones posteriores de un pago o asistencia deben quedar identificadas mediante sus fuentes, sin alterar silenciosamente un supuesto corte histórico.

**Información necesaria.** Fecha `America/Cancun`, sede, hechos diarios, estado actual de pendientes, fuentes y última actualización. **Pendiente de decidir:** momento de corte de la vista, si se requiere conservar una instantánea y tratamiento de correcciones posteriores. La primera vista puede ser una consulta reproducible; no se obliga a crear almacenamiento adicional.

**Riesgos de compatibilidad.** Duplicar cálculos del dashboard, confundir mes financiero con día, crear sesiones al consultar, contar pendientes viejos como nuevos, mostrar como completas sesiones sin marcas o canceladas.

**Qué NO debe hacerse.** Crear otro motor financiero, programar mensajes externos, desplegar una automatización, cerrar pagos/sesiones/meses automáticamente o inventar actividad no registrada.

**Criterio de terminado.** Cada bloque enlaza a los registros que explican su cifra bajo la misma sede y fecha; apertura/cierre funcionan con un día normal, un día sin actividad y datos incompletos. Cobros concilian por fecha, asistencia respeta cancelaciones y marcas, y consultar no produce mutaciones. El resumen reutiliza definiciones de módulos anteriores y declara diferencias de cobertura.

**Estado:** Pendiente. No se implementa ni se programa envío alguno en esta tarea.

## 8. Orden recomendado de implementación

El orden general se mantiene: **F1 → F2 → F3 → F4 → F5 → F6 → F7 → F8 → F9**.

Dentro de cada fase, trabajar en incrementos pequeños: confirmar la fuente y decisiones pendientes, delimitar el comportamiento, implementar solo lo autorizado en una tarea futura y revisar su compatibilidad con consumidores. Estos son pasos internos, no fases adicionales.

La disponibilidad de alertas, finanzas, profesores y auditoría actuales permite avanzar sin invertir el orden. Al completar una fuente posterior, volver solo a los puntos de integración ya registrados de fases anteriores. Por ejemplo, F4 habilita los pendientes de prospectos aplazados en F1; no obliga a rehacer el centro. Cualquier necesidad directa de alterar el orden se registra con motivo e impacto y requiere una decisión explícita; este documento no introduce esa alteración.

## 9. Reglas para evitar que una fase pise a otra

1. **F1 es responsable de la gestión; F5 de detectar y explicar señales.** Comparten referencias y estado de atención; no mantienen colas incompatibles.
2. **F2 conserva la autoridad financiera.** F1, F3, F5, F6 y F9 consumen sus definiciones. Cambiar una etiqueta nunca registra, liquida ni invalida un pago.
3. **F3 compone el expediente.** No se convierte en otro origen de pagos, asistencia, identidad o estado comercial.
4. **F4 representa datos comerciales sin controlar Sharky.** Una etapa del CRM no mueve un Flow, confirma sede, reactiva seguimiento ni ejecuta inscripción.
5. **F6 y F9 comparten definiciones.** Si sus periodos difieren, lo indican; no fuerzan igualdad entre totales que miden cosas distintas.
6. **F7 conserva historia y docencia compartida.** Las vistas existentes no deben perder referencias por inactivación ni reinterpretar asignaciones actuales como historia.
7. **F8 amplía trazabilidad sin sustituir registros de dominio.** Correlacionar un hecho no implica registrar dos cobros o dos inscripciones.
8. **No mezclar estados.** Estado de fase, estado de atención, estado del alumno, pago, reposición y conversación son dominios distintos. No reemplazar enums actuales por la convención documental.
9. **Preservar identidad y sede.** Usar referencias existentes y comprobar la sede relevante al hecho. No reunir registros por coincidencia de nombre o por una inferencia de IA.
10. **Declarar cambios de contratos antes de aplicarlos.** Registrar consumidor afectado, caso antes/después, compatibilidad, validación y reversión. Si hay contradicción con una decisión vigente, aclararla antes de implementar el punto dependiente.
11. **Mantener lectura sin efectos en las vistas nuevas.** Revisar el comportamiento real de una función/API antes de reutilizarla como fuente; el método GET por sí solo no demuestra que sea una lectura pura.
12. **Validar escenarios cruzados.** Como mínimo, según lo tocado: nuevo abono e invalidación; ciclo por sede; corrección de ausencia y reposición; baja lógica; cambio de plan programado; contacto convertido a alumno; clase compartida; diferencia entre resumen actual y cierre guardado.

## 10. Relación con «Charquí MD» / Sharky

**El solicitante confirmó que «Charquí MD» se refiere a [`SHARKY-CORE-RULES.md`](../SHARKY-CORE-RULES.md).** No se crea un alias de archivo ni un documento normativo competidor. Se consultaron Core Rules, `AGENTS.md`, patrones positivos, guía lingüística y el documento del funnel vigente.

Core Rules conserva la autoridad sobre identidad, fuente, producto, sede, memoria, seguimiento y acciones reales. Este roadmap gobierna la coordinación de las mejoras internas y no cambia esas reglas. Si una integración futura no puede demostrar compatibilidad, se detiene únicamente el cambio dependiente hasta aclararlo, sin reinterpretar las reglas vigentes.

Contratos que deben preservarse en F4 y en cualquier conexión de F1/F3/F5/F6/F7/F8/F9 con Sharky:

- Sharky sigue siendo un asistente con IA, identificado como tal.
- Prospectos nuevos `meta_ad`, `web` y `direct` usan el funnel cerrado vigente, preservando la fuente real; referrals no publicitarios mantienen el fallback documentado en GP-001. GP-002 es el patrón activo de captación común.
- Alumno conocido no se degrada a prospecto. Fuente de entrada e identidad son conceptos separados.
- Controles y estado estructurado gobiernan producto/sede/inscripción; texto, campañas, prefills y audio no sustituyen selecciones. Brain no gana autoridad en el funnel por incorporar CRM.
- Horarios, planes, precios, edad y elegibilidad derivan de autoridades actuales. El roadmap no cambia el recorrido ni las prioridades comerciales.
- Regulares conserva el Flow protegido y el takeover humano para coordinar pago; no se añade cobro automático. Intensivo reutiliza registro/pago y validaciones vigentes.
- Takeover y reactivación conservan contexto válido; no se reinicia conversación desde un pendiente o etiqueta CRM.
- Las reglas vigentes de seguimiento, incluida deliberación del prospecto, se respetan. “Sin seguimiento” en una vista interna no autoriza un nuevo mensaje.
- Las acciones reales mantienen intención, confirmación cuando corresponde, revalidación e idempotencia. Si falta autoridad, no se ejecutan.
- Se conserva la guía de lenguaje y la política de tono/emojis sin convertir este roadmap en un nuevo prompt.

No se reabren decisiones de aprendizaje, captación o Brain. Si una futura tarea surgiera de conversaciones o de la bandeja de aprendizaje, deberá consultar además los documentos que `AGENTS.md` exige para ese caso; eso no amplía la misión actual.

## 11. Registro de decisiones

### 11.1 Decisiones tomadas

| ID | Fecha | Decisión y fundamento | Efecto |
| --- | --- | --- | --- |
| D-01 | 2026-09-15 | Crear exclusivamente este MD; instrucción del solicitante. | No implementación, migraciones, configuración ni producción. |
| D-02 | 2026-09-15 | Mantener nueve fases en el orden solicitado. | No añadir fases ni rediseñar la arquitectura. |
| D-03 | 2026-09-15 | Reutilizar módulos comprobados en GitHub; distinguir existente/parcial/futuro. | Alertas, dashboard, ficha/timeline, profesores y auditoría no se presentan como inexistentes. |
| D-04 | 2026-09-15 | Preservar las reglas financieras actuales y sus fuentes. | Abonos múltiples de intensivo por alumno/curso; unicidad vigente de inscripción/mensualidad; sin porcentajes nuevos. |
| D-05 | 2026-09-15 | Confirmación del solicitante: «Charquí MD» es `SHARKY-CORE-RULES.md`. | Core Rules es la autoridad normativa para Sharky; CRM solo consume/proyecta información. |
| D-06 | 2026-09-15 | Separar gestión de pendientes, detección de alertas y estados de dominio. | Un atendido/resuelto no cambia deuda ni estado de alumno. |
| D-07 | 2026-09-15 | Preservar historia; baja futura de profesor mediante inactivación. | No borrado físico para dar de baja ni reconstrucción ficticia del pasado. |
| D-08 | 2026-09-15 | Indicadores con fuente, unidad, sede y periodo explícitos. | Resolver por decisión las diferencias de activos y periodos; no corregirlas de forma tácita. |
| D-09 | 2026-09-15 | Todas las fases comienzan Pendientes. | Elaborar el documento no equivale a analizar, implementar o desplegar una fase. |
| D-10 | 2026-09-15 | Entrega documental mediante rama/PR sin merge: Core Rules §23 describe auto-deploy al integrar a `main` y el solicitante prohíbe desplegar. | Esta tarea termina en el MD revisable; no fusionar ni activar despliegues para completarla. |
| D-11 | 2026-09-15 | Para F1 se habilitan únicamente tres causas ya verificables: mensualidad regular sin cobertura del período vigente, inscripción regular sin cobertura y reposición regular `DISPONIBLE`. `pendientes_gestion` es necesaria porque `historial` y `auditoria_eventos` no pueden conservar por sí solos estado, origen estable y atención de un asunto transversal. | La tabla guarda solo identidad/origen, estado y trazabilidad; ADMIN gestiona y ADMIN/VERIFICADOR consultan dentro de su sede. La causa se revalida antes de una resolución manual; si un pago o uso de reposición la deja de aplicar, la vista la presenta como resuelta por fuente sin escribir en ella. |

### 11.2 Decisiones pendientes antes del incremento afectado

No es necesario resolverlas todas para iniciar una fase; sí resolver cada una antes de implementar el comportamiento que dependa de ella. Documentarlas no cambia las reglas existentes.

| ID | Fase | Decisión pendiente | Quién debe validarla / condición |
| --- | --- | --- | --- |
| P-01 | F1 | Granularidad, persistencia mínima, responsables y recurrencia de asuntos. | Responsable de operación con quien implemente; compatibilidad con estados de dominio. |
| P-02 | F2 | Presentación/conciliación de periodos y ajustes posteriores a cierres; saldos sin obligación explícita. | Responsable administrativo/financiero; preservar convenios y cierres actuales. |
| P-03 | F3 | Fuente del nivel, notas con autoría y cobertura temporal de cambios del alumno. | Operación; datos verificables, sin completar historia por inferencia. |
| P-04 | F4 | Mapeo de etapas, contacto/participante/oportunidad, último contacto, conversión y retención mínima. | Responsable comercial; cumplimiento de Core Rules y fuentes existentes. |
| P-05 | F5 | Umbrales nuevos de ausencias/seguimiento/continuidad y prioridad. | Operación; no sustituir parámetros ya definidos ni imponer sanciones. |
| P-06 | F6 | Definición de activos, altas/bajas, asistencia y cohortes de conversión. | Operación con contraste de fuentes; distinguir métricas de reglas de acceso. |
| P-07 | F7 | Vigencia de asignaciones, unidad de carga y registro de sustituciones. | Responsable de profesores; conservar historia y clases compartidas. |
| P-08 | F8 | Matriz de cobertura y representación de antes/después con registros existentes. | Administración; consistencia entre resultado y evento, sin inventar datos pasados. |
| P-09 | F9 | Corte diario, necesidad de instantánea y correcciones posteriores. | Operación; no confundir cierre operativo con cierre financiero. |

Toda decisión nueva añadirá fecha, responsable real, motivo, fuentes, fases afectadas y la decisión anterior que sustituye, si existe. No borrar decisiones anteriores.

## 12. Registro de progreso

### 12.1 Entrega documental inicial

| Fecha | Trabajo realizado | Evidencia / límite | Resultado |
| --- | --- | --- | --- |
| 2026-09-15 | Revisión dirigida de fuentes de GitHub y aclaración de «Charquí MD». | Commit de referencia y rutas de la sección 5; Core Rules confirmado por el solicitante. Sin acceso a producción. | Base existente y limitaciones documentadas. |
| 2026-09-15 | Definición de las nueve fases, dependencias, criterios y registros. | Este archivo. | Roadmap documental completo; ninguna fase implementada. |
| 2026-09-15 | Implementación de F1 en la rama `feature/centro-pendientes-fase-1`. | `api/pendientes.php`, `config/centro-pendientes.php`, migración aditiva y vista administrativa. Sin acceso a producción. | Regresiones estáticas y JavaScript aprobadas; queda pendiente ejecutar PHP e integración. PR en borrador pendiente de crear. |

### 12.2 Estado de las fases

| Fase | Estado | Base que se reutiliza | Próximo paso cuando se autorice trabajo funcional |
| --- | --- | --- | --- |
| F1 Centro de pendientes | En implementación | Alertas, obligaciones, reglas de acceso y reposiciones regulares | Ejecutar pruebas PHP e integración del incremento inicial y abrir PR en borrador; mantener visibles los tipos diferidos hasta sus fases dependientes. |
| F2 Finanzas | Pendiente | Pagos, abonos, reglas, reportes y cierres | Resolver P-02 para la primera vista financiera. |
| F3 Expediente 360° | Pendiente | Ficha y timeline | Resolver P-03 y definir secciones a completar. |
| F4 CRM / Sharky | Pendiente | Memoria, atribución y contactos | Resolver P-04 sin cambiar el funnel. |
| F5 Alertas | Pendiente | Alertas existentes | Resolver P-05 y catálogo acotado de reglas. |
| F6 Dashboard | Pendiente | Dashboard y fecha operativa | Resolver P-06 y completar contrato por indicador. |
| F7 Profesores | Pendiente | Profesores, horarios y cancelaciones | Resolver P-07 y preservar historial. |
| F8 Auditoría | Pendiente | Auditoría e historial existentes | Resolver P-08 mediante matriz de acciones relevantes. |
| F9 Resumen diario | Pendiente | Módulos y definiciones previas | Resolver P-09 y componer apertura/cierre. |

En futuras actualizaciones registrar: fecha, fase/incremento, responsable real, estado anterior/nuevo, cambio concreto, PR/commit, validaciones y resultado, dependencias pendientes y siguiente paso. Registrar por separado los subalcances diferidos: completar un incremento no completa automáticamente la fase.

## 13. Convención de estados

Esta convención se aplica al avance del roadmap, no a alumnos, pagos, pendientes operativos ni conversaciones.

| Estado | Significado | Evidencia mínima para avanzar |
| --- | --- | --- |
| Pendiente | No ha comenzado el trabajo funcional de la fase. | Alcance y siguiente decisión identificados. |
| En análisis | Se verifica el incremento autorizado y se resuelven sus dependencias directas. | Fuentes, decisiones, criterios y límites del incremento. |
| En implementación | Se desarrollan cambios autorizados. | Rama/PR o commits y registro de alcance real. |
| En revisión | El incremento está listo para evaluar funcionalidad y compatibilidad. | Cambios revisables y validaciones pertinentes. |
| Implementado | El alcance está terminado en código, integrado y revisado. | Commit integrado, revisión y pruebas aplicables; no implica despliegue. |
| Desplegado | La versión correspondiente llegó al entorno de producción mediante el proceso autorizado. | Referencia de versión/despliegue; no implica verificación operativa. |
| Verificado | El comportamiento esperado y su compatibilidad se comprobaron en producción. | Evidencia fechada de criterios cumplidos, responsable y pendientes residuales. |

Una fase se considera terminada cuando todo su alcance comprometido cumple sus criterios y alcanza **Verificado**. Si una parte se aplaza por decisión explícita, se mantiene visible con su dependencia; no se oculta bajo un estado de fase completa. Un impedimento se anota junto al estado y el siguiente paso, sin inventar otro estado de la convención. No afirmar despliegue o verificación usando solo la existencia de código o migraciones.

## 14. Regla de continuidad y control de cierre

**Antes de modificar alumnos, pagos, mensualidades, asistencia, ausencias, reposiciones, profesores, usuarios o Sharky por motivos incluidos en este roadmap, es obligatorio consultar este documento y actualizarlo con las decisiones y el progreso del cambio.** También se consulta `AGENTS.md` y, para Sharky, Core Rules y los documentos aplicables. Esta regla documental no implementa un bloqueo técnico ni modifica otros archivos.

Para retomar el trabajo desde otra conversación:

1. Leer propósito, exclusiones, estado real y registros de decisiones/progreso.
2. Comparar las fuentes relevantes de GitHub con el commit de referencia, únicamente dentro de la fase autorizada.
3. Identificar el incremento, sus datos y consumidores, resolver sus decisiones pendientes y registrar el criterio de terminado.
4. Mantener el orden general y las reglas para evitar interferencias. No usar un dato supuesto como dependencia satisfecha.
5. Al terminar el incremento futuro, registrar evidencia y siguiente paso sin iniciar otra fase automáticamente.

### Cierre de la misión documental

- Archivo autorizado: **`docs/HACHE-INTERNAL-IMPROVEMENTS-ROADMAP.md`**.
- No es indispensable modificar un índice documental: no se propone otro archivo.
- Las nueve fases están definidas con problema, objetivo, alcance, dependencias, comportamiento, información, riesgos, exclusiones, criterio de terminado y estado.
- Las fuentes de GitHub, los límites de la comprobación y la relación con Core Rules están documentados.
- No se implementa funcionalidad, no se ejecutan migraciones, no se cambia configuración, no se accede al VPS y no se despliega.
- No se invocan manualmente Codex, Inge ni agentes adicionales.
- La misión termina con este MD completo y revisable en GitHub. Cualquier implementación requiere una tarea posterior explícita.
