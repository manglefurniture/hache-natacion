# Hache Natación — Roadmap de mejoras internas

**Documento maestro:** `docs/HACHE-INTERNAL-IMPROVEMENTS-ROADMAP.md`  
**Repositorio y fuente de verdad:** [manglefurniture/hache-natacion](https://github.com/manglefurniture/hache-natacion)  
**Fecha de elaboración:** 2026-09-15.  
**Base comprobada inicialmente en GitHub:** `main`, commit [`b304ff10b303d8738c3790354d4f3a3378099b65`](https://github.com/manglefurniture/hache-natacion/commit/b304ff10b303d8738c3790354d4f3a3378099b65).  
**Última base comprobada para esta actualización:** `main`, commit `6d521421a8508c6a63de01ffbb4813aecd2bf28f`.  
**Estado del roadmap en esta actualización:** Fase 6 **En implementación**; los incrementos técnicamente definidos de F6 están desplegados y los huecos restantes de P-06 requieren decisiones explícitas antes de publicar nuevas métricas.  
**Nota de continuidad:** la autorización documental inicial quedó superada por tareas funcionales posteriores expresamente autorizadas; el registro de decisiones y progreso de este archivo refleja el estado vigente.

## 1. Propósito

Conservar el contexto de la siguiente etapa de mejoras del sistema administrativo de Hache Natación: qué construir, en qué orden, qué reutilizar, qué decisiones respetar y qué evidencia permitirá dar cada fase por terminada. Cualquier persona o conversación futura debe poder continuar desde aquí sin duplicar módulos ni introducir cambios que contradigan otra fase.

Este roadmap describe trabajo incremental sobre el sistema existente. La elaboración inicial del documento no inició automáticamente ninguna fase; el trabajo funcional posterior se registra expresamente en las secciones de decisiones y progreso.

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

- Auditar todo el proyecto, revisar su seguridad de forma general, buscar deuda técnica ajena a estas fases o revisar SEO y frontend público.
- Rediseñar Hache Natación, reemplazar la arquitectura, migrar de tecnología o refactorizar globalmente.
- Modificar infraestructura, VPS, configuración operativa, servicios o workflows salvo dependencia directa y autorización separada.
- Añadir nómina, contabilidad fiscal, facturación tributaria, nuevas campañas, nuevos canales de comunicación o funciones comerciales fuera de las nueve fases.
- Cambiar tarifas, porcentajes, convenios, mínimos, elegibilidad, políticas de pago, acceso a clase o reposiciones sin una decisión explícita distinta.
- Sustituir los flujos de Sharky, reactivar Brain en captación, cambiar prioridades comerciales o permitir aprendizaje autónomo que modifique producción.
- Borrar historia para simplificar las nuevas vistas o generar datos retroactivos sin evidencia.

La fase 8 es un historial operativo de acciones; no es una auditoría general de seguridad. Si aparece algo ajeno al alcance, se ignora salvo dependencia directa o riesgo evidente para una fase, que se documentará sin corregirlo de forma implícita.

## 4. Principios de implementación futura

1. **Consultar este documento antes de modificar cualquiera de las áreas de la sección 2.** Leer también el registro de decisiones y progreso, identificar la fase y sus dependencias y verificar qué cambió desde el último commit de referencia.
2. **Reutilizar la implementación actual.** Extender consultas, reglas y pantallas relacionadas cuando sea suficiente. Este documento no prescribe tablas nuevas, endpoints nuevos ni otra arquitectura.
3. **Una autoridad por dato o regla.** Los módulos operativos mantienen sus registros; pendientes, alertas, expediente, dashboard y resumen consumen esa información sin mantener saldos, estados de alumno o asistencias paralelos.
4. **Cambios pequeños y reversibles.** Cada incremento futuro delimitará archivos afectados, comportamiento anterior y nuevo, validaciones y forma de retirar el incremento sin perder datos previos.
5. **Preservar contratos.** Mantener referencias, identificadores, roles, alcance por sede, validaciones de negocio, trazabilidad y respuestas utilizadas por consumidores actuales. No ampliar permisos por incorporar una vista agregada.
6. **Datos disponibles y datos desconocidos son distintos.** Un campo ausente no equivale a cero, una fecha desconocida no es la de hoy y falta de evidencia no significa resuelto, pagado o inscrito.
7. **Historia y actualidad son distintas.** No recalcular silenciosamente el pasado con el precio, sede, plan, profesor o porcentaje vigente hoy. Distinguir un resumen actual de un cierre ya guardado.
8. **Reglas claras antes de automatizar.** Umbrales nuevos se deciden expresamente; se conservan los que ya estén definidos. Ningún pendiente o alerta ejecuta por sí mismo una operación administrativa o un mensaje de Sharky.
9. **Preparación para producción sin confundir hitos.** Validar casos de negocio y regresiones afectadas, obtener revisión y registrar evidencia. Integración, despliegue y verificación son hitos distintos.
10. **Documentar al cambiar.** Actualizar aquí decisiones, fuentes, dependencia afectada, estado y evidencia. Un cambio de alcance requiere decisión explícita, no una interpretación tácita del implementador.

## 5. Estado actual relevante y evidencia

### 5.1 Cómo leer esta comprobación

Se revisaron en GitHub la estructura del repositorio, `AGENTS.md`, documentación normativa de Sharky y archivos concretos relacionados con las nueve fases. La revisión documental inicial no consultó datos reales de producción; las fases posteriores deben registrar por separado cuando exista verificación de producción.

- **Existente:** identificado en código o documentación versionada; no certifica por sí solo que esté desplegado.
- **Parcial:** existe una base útil, pero la revisión dirigida no acredita todo el alcance futuro.
- **Futuro:** alcance por desarrollar; no implica que se haya demostrado la ausencia absoluta de cualquier pieza similar.

Los enlaces siguientes son rutas relativas del repositorio. El esquema inicial es una referencia histórica: debe leerse junto con las migraciones y el código vigente, no como fotografía única del modelo actual.

### 5.2 Mapa de fuentes y límites

| Área | Estado comprobado | Evidencia dirigida | Consecuencia para el roadmap |
| --- | --- | --- | --- |
| Base administrativa | Existente: aplicación PHP, APIs administrativas, esquema y migraciones SQL; roles `ADMIN`, `VERIFICADOR` y `ALUMNO`, con contexto de sede. | [auth](../config/auth.php), [esquema de referencia](../database/schema_hache_monteverde_v1.sql), [modelo por sede](../database/migrations/20260816_multi_sede_model.sql). | Continuar sobre estas piezas; no crear roles ni otra arquitectura como prerrequisito. |
| Alumnos | Existente: datos principales, sede, horario preferido, plan actual/programado, observaciones y estados `PENDIENTE`, `ACTIVO`, `BAJA`. | [alumnos](../api/alumnos.php), [gestión de alumno](../api/alumno-gestion.php), [reglas de acceso](../config/reglas-acceso.php). | Separar estado administrativo, obligaciones y derecho a clase. No interpretar `PENDIENTE` como inscripción incompleta en todos los casos. |
| Pagos y abonos | Existente: pagos por inscripción, mensualidad e intensivo; estados `VALIDO`/`INVALIDADO`; contexto con total pagado y saldo del intensivo. | [pagos-smart](../api/pagos-smart.php), [pago-contexto](../api/pago-contexto.php), [migración de abonos](../database/migrations/20260909_intensive_partial_payments.sql). | Los abonos múltiples comprobados son por alumno + curso intensivo. La migración conserva unicidad del pago válido por inscripción y mensualidad. |
| Ajustes e historial financiero | Existente: edición de importe, método y fecha con motivo; la edición registra antes/después en `historial`. La invalidación conserva el pago y recalcula obligaciones relacionadas. | [editar-pago](../api/editar-pago.php), [invalidar-pago](../api/invalidar-pago.php). | No imponer que toda corrección deba borrar o reemplazar el pago: hay operaciones vigentes diferentes. |
| Periodos, reparto y cierres | Existente: periodos financieros por sede, totales por concepto, reparto desde configuración de sede y cierres guardados. | [periodos-financieros](../config/periodos-financieros.php), [cierres-mensuales](../api/cierres-mensuales.php), [resumen-financiero](../api/resumen-financiero.php), [estándar de reportes](REPORTES.md). | La fase 2 completa y concilia la visión; no inventa otro motor contable ni porcentajes. |
| Asistencia, ausencias y reposiciones | Existente: sesiones, marcas de asistencia, avisos de ausencia, reposiciones regulares y tratamiento de ausencias/reposiciones de intensivos. | [sesiones](../api/sesiones.php), [asistencia](../api/asistencia.php), [ausencias programadas](../api/ausencias-programadas.php), [modelo de asistencia](../database/migrations/20260816_attendance_model.sql). | Preservar estados, cierre de sesiones, elegibilidad y límites vigentes. Las reposiciones de ambos productos no tienen una representación idéntica. |
| Pendientes y alertas | F1 conserva la gestión persistente; F2 aporta saldo financiero y F5 ya comparte con esa cola las reglas de mensualidad, inscripción, reposición, ausencias, continuidad y prospectos sin seguimiento. Los prospectos sin sede se gestionan como casos globales ADMIN, sin sede ficticia. | [pendientes](../api/pendientes.php), [centro de pendientes](../config/centro-pendientes.php), [prospectos pendientes](../config/centro-pendientes-prospectos.php), [alertas](../api/alertas.php), [obligaciones-alumnos](../api/obligaciones-alumnos.php). | F1 gestiona atención; F2 conserva autoridad financiera; F5 detecta y explica sin duplicar reglas ni cola. |
| Expediente | Parcial: ficha con datos, observaciones y acciones; API de timeline que combina pagos, intensivos, asistencia, avisos e historial. | [ficha-alumno](../public/ficha-alumno.php), [timeline-alumno](../api/timeline-alumno.php). | Ampliar la ficha y la línea de tiempo existentes. No se verificó un campo de nivel académico unificado en la API de alumnos ni un módulo separado de notas con versiones. |
| Sharky / base para CRM | Existente: memoria comercial estructurada, atribución, estado conversacional, contactos con roles y referencias de alumno/profesor. Parcial respecto de un CRM interno. | [memoria comercial](../config/sharky-commercial-memory.php), [modelo de orquestador](../database/migrations/20260902_sharky_orchestrator.sql), [contactos](../database/migrations/20260908_sharky_contact_book.sql), [panel Sharky](../api/sharky-admin.php). | Contacto, identidad, estado conversacional y etapa comercial no son la misma cosa. No se acredita un pipeline CRM completo con los seis estados propuestos. |
| Dashboard | Existente: indicadores por sede, fecha operativa, facturación por periodo, alumnos activos, pendientes, mensualidades, intensivos, avisos y reposiciones. | [dashboard](../api/dashboard.php), [tiempo operativo](../config/dashboard-tiempo.php). | La fase 6 mejora el dashboard vigente y define fuentes verificables para cada indicador nuevo. |
| Profesores | Parcial: alta/edición, activo/inactivo, asignaciones de horario y cancelaciones por profesor/sesión; soporte versionado de docencia compartida. | [profesores](../api/profesores.php), [modelo de profesores](../database/migrations/20260907_sharky_member_ops.sql), [docencia compartida](../database/migrations/20260909_professor_coteaching.sql). | No presentar activo/inactivo como inexistente. Queda por definir la vista integrada de clases, sustituciones, incidencias, carga e historia. |
| Auditoría | Parcial: `auditoria_eventos`, consulta administrativa e `historial` del alumno; Sharky mantiene además su auditoría de acciones. | [modelo operativo](../database/migrations/20260816_v1_operations_layer.sql), [auditoria](../api/auditoria.php), [edición de pago](../api/editar-pago.php), [auditoría Sharky](../database/migrations/20260902_sharky_orchestrator.sql). | Reutilizar y completar cobertura; no asumir que todas las acciones ya guardan antes/después ni confundir resultado técnico con cambio confirmado. |
| Resumen diario | Futuro como vista unificada de apertura/cierre del día; existen insumos en dashboard, sesiones, pagos y alertas. | Fuentes anteriores. | Componer información existente; no duplicar cálculo ni crear un cierre contable adicional. |

### 5.3 Diferencias y riesgos concretos que deben preservarse o aclararse

1. **Abono no significa liquidación ni derecho a clase.** `pago-contexto.php` distingue `PENDIENTE`, `ANTICIPO` y `PAGADO` para el intensivo. `regla_intensivo_pagado()` compara la suma de pagos válidos del alumno/curso con el precio. No restablecer la restricción antigua de un solo pago por intensivo ni sumar los abonos de otros alumnos del mismo curso.
2. **Mensualidad vigente y mensualidad vencida son señales diferentes.** Existen ciclos `P1`/`P15` para Palapas, rangos de vigencia y obligaciones cubiertas históricamente o por continuidad cuando corresponde. No crear deuda por diferencia con la tarifa actual ni por ausencia de un recibo cuando una excepción vigente cubre la obligación.
3. **Periodo financiero y fecha de cobro no son equivalentes.** `financiero_totales()` atribuye mensualidades por mes/año de la obligación y usa fecha de inscripción o inicio de curso para los otros conceptos dentro del rango financiero. Una vista financiera debe explicar su base temporal y conciliar diferencias, sin cambiar reglas silenciosamente.
4. **“Activo” tiene significados actuales distintos.** El dashboard cuenta regulares con mensualidad pagada vigente e intensivos vigentes con algún pago válido, excluyendo bajas. El derecho a clase exige las reglas de `reglas-acceso.php`, incluida liquidación del intensivo. El nuevo dashboard no puede presentar su conteo como autorización de acceso ni modificar esa autorización para que coincidan.
5. **Consultar una API no siempre es una lectura sin efectos.** En el código revisado, `pago-contexto.php` puede promover planes programados para ADMIN y el GET de `sesiones.php` puede generar sesiones para ADMIN. Las nuevas vistas de consulta deben reutilizar reglas sin disparar escrituras incidentales.
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

- **F1 no espera a F2, F4 o F5 completas.** Empezó con las señales verificadas. Usa los cálculos actuales; incorpora saldos más completos de F2, prospectos de F4 y reglas de F5 cuando estén disponibles. Los tipos aplazados permanecen explícitos en el progreso.
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

**Información necesaria.** Referencia estable a entidad/registro, tipo de señal, periodo o sesión/curso, sede, evidencia, estado de atención, fecha de detección y responsable/fecha de gestión cuando aplique. La primera implementación resolvió granularidad, persistencia y permisos para sus tres causas iniciales; nuevas causas deben definir su recurrencia y origen estable antes de incorporarse.

**Riesgos de compatibilidad.** Duplicar alertas agregadas como si fueran casos individuales; confundir falta de mensualidad vigente con deuda cuantificada; generar pendientes por inscripciones históricamente cubiertas; perder restricciones por sede al agrupar.

**Qué NO debe hacerse.** Crear deudas, alumnos o reposiciones automáticamente; cambiar elegibilidad; exigir un CRM nuevo para inaugurar el centro; enviar recordatorios comerciales; considerar una etiqueta manual como prueba de pago.

**Criterio de terminado.** Los tipos habilitados tienen fuente y regla verificables, enlace al detalle y estados persistentes de gestión; recargas no duplican casos; atención identifica al responsable cuando corresponde; resolución conserva evidencia e historia; se prueban un pago posterior, una invalidación, una nueva obligación y el aislamiento por sede. Tipos diferidos quedan registrados y la fase completa no se declara verificada mientras falte alcance comprometido sin decisión documentada.

**Estado:** **Implementado.** El PR #254 fue integrado a `main` el 2026-09-15 e incorporó el primer incremento con mensualidad regular sin cobertura, inscripción regular sin cobertura y reposición regular disponible. El incremento F2 del PR #262 incorpora saldo de intensivo pendiente usando la misma gestión, sin convertir F1 en autoridad financiera. F5 incorporó posteriormente ausencias consecutivas, continuidad de intensivos y prospectos sin seguimiento en la misma cola; PR #298 resolvió el caso de prospectos sin sede mediante alcance global ADMIN con `sede_id=NULL`, sin crear una sede ficticia ni ampliar permisos de VERIFICADOR. La gestión se conserva en una tabla específica, sin modificar las fuentes de dominio. El PR #257 corrigió la interfaz para que “Marcar atendido” solo aparezca cuando el estado efectivo es `PENDIENTE` y la causa siga activa; `ATENDIDO` conserva su traza y `RESUELTO` no ofrece la acción. La fase no se marca **Verificada** solo por integración: ese estado exige comprobación funcional de producción.

### FASE 2 — Finanzas internas

**Problema que resuelve.** Hay pagos, abonos, reportes y cierres, pero hace falta una lectura consistente de obligaciones, cobros, saldos y reparto sin confundir sus periodos.

**Objetivo.** Completar la visión financiera utilizando los registros y reglas existentes.

**Alcance.** Monto total de la obligación, monto pagado, saldo pendiente, fecha y periodo, concepto, método cuando exista, abonos permitidos, invalidaciones, ajustes, historial, cierres/resúmenes, sede y distribución Hache–socio cuando aplique.

**Dependencias.** Pagos, inscripción, mensualidad, curso/alumno, planes, reglas de acceso, periodos financieros, sedes y cierres. Integración con F1 sin que sus estados determinen saldos. Historial existente para las operaciones sensibles.

**Comportamiento esperado.** Mostrar obligación y movimientos relacionados con referencia al origen. Para intensivos, respetar la suma de pagos `VALIDO` por alumno + curso y el saldo calculado actualmente, incluidos segundo abono y liquidación. Mantener un único pago válido por inscripción/mensualidad; extender abonos a otros conceptos requeriría una decisión distinta, que este roadmap no toma.

La deuda debe provenir de obligaciones verificadas y ajustes autorizados. El precio actual del plan no recrea una deuda histórica. Invalidar un pago lo mantiene en historial y actualiza la lectura del saldo. Mostrar por separado fecha de cobro, periodo de la obligación y periodo financiero. Comparar cálculos actuales con cierres guardados sin sobrescribirlos. El reparto toma porcentajes, socio y mínimos de las autoridades de sede vigentes; no fija cifras nuevas. Preservar conciliaciones y comisiones existentes si una consulta las consume.

Si se extienden reportes administrativos/financieros, respetar [REPORTES.md](REPORTES.md): exportación PDF con identidad Hache, resolución por sede/periodo y CSV cuando corresponda. El detalle interno puede mostrar método de pago; el PDF de liquidación conserva su contenido y exclusiones vigentes.

**Información necesaria.** Identificadores de pago y obligación, alumno, sede atribuida a la operación, importe total/cobrado, validez, fechas, concepto, método si está registrado, motivo/autor del ajuste, reglas del periodo y del convenio.

**Decisión P-02 resuelta para el primer incremento (2026-09-15).**

1. La vista distingue **periodo de obligación**, **periodo financiero** y **fecha de cobro**; no intenta forzar que coincidan.
2. Para totales de reparto y comparación con cierres se reutilizan `financiero_totales()` y `financiero_rango()` como autoridades vigentes.
3. Un cierre guardado es una instantánea histórica inmutable. La vista puede comparar cálculo actual contra cierre guardado, pero nunca lo sobrescribe ni lo rehace.
4. Solo se cuantifica saldo cuando existe una obligación con importe registrado: `mensualidades.importe_a_cobrar`, `inscripciones.importe` o precio del intensivo para el alumno/curso. Una señal sin importe verificable no se convierte en deuda usando la tarifa actual.
5. El monto pagado proviene de pagos `VALIDO`; un pago `INVALIDADO` permanece visible como historia, pero no reduce saldo.
6. En intensivos, los abonos se suman por `alumno_id + curso_intensivo_id`; el precio de la obligación se cuenta una sola vez por alumno/curso.
7. La fase no generaliza multiabono a mensualidad ni inscripción.
8. Porcentajes, socio y mínimos siguen viniendo de la configuración de sede; no se copian a otra autoridad.

**Primer incremento integrado.** El PR #261 incorporó una API estrictamente de lectura y una vista interna “Obligaciones y saldos”, integrada al centro financiero. Presenta resumen del periodo financiero, obligaciones cuantificables, pagos válidos, saldos, pagos invalidados como historia y comparación `actual vs cierre guardado`. No añade migración ni acciones de cobro. Quedó integrado en `main` como `c68c72270b369de5c6e4cdda829a1a6e9d1daa90`.

**Segundo incremento integrado y desplegado.** El PR #262 incorporó `SALDO_INTENSIVO_PENDIENTE` al Centro de pendientes. La obligación se identifica por alumno + curso, usa el precio registrado del curso y resta únicamente pagos `VALIDO`; conserva abonos parciales, incluye cursos programados, en curso y terminados, y deja de aplicar cuando el saldo se liquida. El deep link abre Pagos con alumno, `tipo=INTENSIVO` y curso preseleccionados. No cambia pagos, precios, acceso a clase, estados del alumno ni introduce migración. Quedó integrado y desplegado como `bed91c7a9db1dbefeeae62d7081b254651773f5e`.

**Verificación parcial de producción (2026-09-16).** Quality del PR y Quality posterior sobre `main` completaron con éxito; el deploy automático #229 publicó el SHA `bed91c7...` y el marcador de producción coincidió con ese commit. La regresión del Centro de pendientes devolvió `CENTRO_PENDIENTES_REGRESSION_OK`. Con sesión ADMIN se comprobó visualmente que “Obligaciones y saldos” muestra obligaciones de intensivo sin pagos y con anticipo parcial, que el Centro de pendientes muestra `SALDO DE INTENSIVO PENDIENTE` con saldo/pagado coherentes y que “Abrir registro original” abre Pagos con el alumno, tipo Intensivo y curso correctos ya seleccionados. Esta evidencia acredita el incremento desplegado, pero no sustituye los escenarios adicionales del criterio de terminado.

**Riesgos de compatibilidad.** Sumar el total del curso una vez por cada abono; duplicar mensualidad e importe del pago; trasladar ingresos históricos a la sede actual del alumno; alterar reparto por recalcular con parámetros actuales; presentar un anticipo como curso liquidado; tratar diferencias de calendario como errores de datos.

**Qué NO debe hacerse.** Inventar porcentajes, mínimos o descuentos; cambiar ciclos; generalizar abonos a mensualidades/inscripciones; invalidar o ajustar registros como parte de una consulta; reabrir o rehacer cierres automáticamente; crear contabilidad paralela.

**Criterio de terminado.** Total, pagado y saldo se explican por registros concretos y coinciden con sus operaciones de origen; se verifican cero pagos, uno y varios abonos, liquidación, rechazo de sobrepago, edición e invalidación. Se comprueban periodos P1/P15 cuando corresponda, sedes, excepción de inscripción cubierta y comparación cierre/actual. Las vistas F1/F3/F6/F9 consumen la misma definición financiera aplicable y no suman dos veces el mismo dinero.

**Estado:** **Desplegado.** Los incrementos de PR #261 y #262 están integrados, revisados, probados y desplegados; además existe comprobación funcional representativa en producción. Para pasar a **Verificado** deben quedar evidenciados los escenarios restantes del criterio de terminado, en particular varios abonos y liquidación, rechazo de sobrepago, edición e invalidación, periodos P1/P15 y sedes aplicables, excepción de inscripción cubierta y comparación cierre/actual. No se cambia el criterio para cerrar la fase con evidencia incompleta.

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

**Información necesaria y decisiones vigentes.** Cada regla conserva fuente, estados de dominio, periodo o fecha operativa y evidencia. El seguimiento de prospectos usa 24 horas por defecto; las rachas usan defaults configurables de 3 ausencias generales y 2 injustificadas. La regla de intensivo sin continuidad no impone default de negocio: permanece deshabilitada hasta que ADMIN configure días y alcance válidos. La prioridad alta/media/baja de las reglas nuevas sigue pendiente de decisión; hasta entonces se usa `NEUTRA`, que no significa prioridad baja.

**Riesgos de compatibilidad.** Doble alerta sobre un mismo saldo; contar días naturales en vez de clases aplicables; confundir aviso con ausencia; alertar a prospectos que ya están inscritos o cuyo seguimiento se pausó; computar “sin datos” como incumplimiento.

**Qué NO debe hacerse.** Introducir IA compleja, puntuaciones opacas o aprendizaje automático; abrir otra cola de gestión; mandar comunicaciones externas; aplicar bloqueos o sanciones; generar crédito de reposición desde una alerta.

**Criterio de terminado.** Cada regla habilitada tiene ejemplos de activación y no activación, umbral aprobado cuando aplique, fuente y resolución comprobables; se prueban corrección de asistencia, pago invalidado, clase cancelada, repetición de consulta y prospecto ya convertido; la alerta y su pendiente representan el mismo hecho sin duplicidad.

**Estado:** **Desplegado.** El alcance comprometido de F5 está implementado e integrado con F1/F2/F4: prospecto sin seguimiento, umbrales configurables, ausencias consecutivas, continuidad de intensivo, saldo pendiente de intensivo, mensualidad sin cobertura, reposición disponible e inscripción sin cobertura. Las reglas reutilizan las autoridades de dominio y la misma cola F1. PR #298 cerró el último hueco funcional al materializar `PROSPECTO_SIN_SEGUIMIENTO` como pendiente global ADMIN, incluido el caso `SIN_SEDE`, sin duplicar identidad ni asignar sede ficticia. Quality #1534 pasó en el PR; Quality #1535 pasó en `main`; Deploy #264 publicó `cf7dc078cd71fa3d9fa479cb66fe66f53e97b3b4`, confirmó `CENTRO_PENDIENTES_MIGRATION_OK` y el marcador de producción coincidió. La evidencia detallada está en [F5-CLOSURE.md](F5-CLOSURE.md). Para pasar a **Verificado** falta únicamente la comprobación operativa dirigida en producción exigida por la convención del roadmap; no se fabricarán datos para forzar escenarios inexistentes.

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

**Estado:** **En implementación.** PR #300–#307 integraron y desplegaron fuentes reconciliables de F1/F2/F5, operación diaria en lectura pura, alumnos activos, intensivos activos, mensualidades pagadas, avisos de ausencia, sede y contexto temporal, preservando los contratos previos. #307 cerró los hallazgos técnicos pendientes de revisión automática sobre fecha operativa, cancelaciones, sesiones programadas, zona horaria y detalle reconciliable. PR #309 implementó las definiciones P-06 de nuevos alumnos, bajas y asistencia. D-18 resuelve la semántica restante de prospectos y conversión; su implementación se divide desde `main` en micro-pasos independientes, sin publicar cobertura ni valores hasta disponer de evidencia durable. La evidencia y los límites actuales están en [F6-DASHBOARD-STATUS.md](F6-DASHBOARD-STATUS.md).

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

**Estado:** Pendiente. No se implementa ni se programa envío alguno en esta fase todavía.

## 8. Orden recomendado de implementación

El orden general se mantiene: **F1 → F2 → F3 → F4 → F5 → F6 → F7 → F8 → F9**.

Dentro de cada fase, trabajar en incrementos pequeños: confirmar la fuente y decisiones pendientes, delimitar el comportamiento, implementar solo lo autorizado y revisar su compatibilidad con consumidores. Estos son pasos internos, no fases adicionales.

La disponibilidad de alertas, finanzas, profesores y auditoría actuales permite avanzar sin invertir el orden. Al completar una fuente posterior, volver solo a los puntos de integración ya registrados de fases anteriores. Por ejemplo, F4 habilita los pendientes de prospectos aplazados en F1; no obliga a rehacer el centro. Cualquier necesidad directa de alterar el orden se registra con motivo e impacto y requiere una decisión explícita.

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

**El solicitante confirmó que «Charquí MD» se refiere a [`SHARKY-CORE-RULES.md`](../SHARKY-CORE-RULES.md).** No se crea un alias de archivo ni un documento normativo competidor. Core Rules conserva la autoridad sobre identidad, fuente, producto, sede, memoria, seguimiento y acciones reales. Este roadmap gobierna la coordinación de las mejoras internas y no cambia esas reglas.

Contratos que deben preservarse en F4 y en cualquier conexión de F1/F3/F5/F6/F7/F8/F9 con Sharky:

- Sharky sigue siendo un asistente con IA, identificado como tal.
- Prospectos nuevos `meta_ad`, `web` y `direct` usan el funnel cerrado vigente, preservando la fuente real; referrals no publicitarios mantienen el fallback documentado.
- Alumno conocido no se degrada a prospecto. Fuente de entrada e identidad son conceptos separados.
- Controles y estado estructurado gobiernan producto/sede/inscripción; texto, campañas, prefills y audio no sustituyen selecciones. Brain no gana autoridad en el funnel por incorporar CRM.
- Horarios, planes, precios, edad y elegibilidad derivan de autoridades actuales. El roadmap no cambia el recorrido ni las prioridades comerciales.
- Regulares conserva el Flow protegido y el takeover humano para coordinar pago; no se añade cobro automático. Intensivo reutiliza registro/pago y validaciones vigentes.
- Takeover y reactivación conservan contexto válido; no se reinicia conversación desde un pendiente o etiqueta CRM.
- Las reglas vigentes de seguimiento se respetan. “Sin seguimiento” en una vista interna no autoriza un nuevo mensaje.
- Las acciones reales mantienen intención, confirmación cuando corresponde, revalidación e idempotencia. Si falta autoridad, no se ejecutan.
- Se conserva la guía de lenguaje y la política de tono/emojis sin convertir este roadmap en un nuevo prompt.

Antes de modificar archivos relacionados con Sharky deben consultarse `AGENTS.md`, Core Rules y los documentos adicionales que `AGENTS.md` exija para el caso concreto.

## 11. Registro de decisiones

### 11.1 Decisiones tomadas

| ID | Fecha | Decisión y fundamento | Efecto |
| --- | --- | --- | --- |
| D-01 | 2026-09-15 | Crear inicialmente este MD como documento maestro. | Estableció el roadmap; no autorizó por sí solo cambios funcionales. |
| D-02 | 2026-09-15 | Mantener nueve fases en el orden solicitado. | No añadir fases ni rediseñar la arquitectura. |
| D-03 | 2026-09-15 | Reutilizar módulos comprobados en GitHub; distinguir existente/parcial/futuro. | Alertas, dashboard, ficha/timeline, profesores y auditoría no se presentan como inexistentes. |
| D-04 | 2026-09-15 | Preservar las reglas financieras actuales y sus fuentes. | Abonos múltiples de intensivo por alumno/curso; unicidad vigente de inscripción/mensualidad; sin porcentajes nuevos. |
| D-05 | 2026-09-15 | Confirmación del solicitante: «Charquí MD» es `SHARKY-CORE-RULES.md`. | Core Rules es la autoridad normativa para Sharky; CRM solo consume/proyecta información. |
| D-06 | 2026-09-15 | Separar gestión de pendientes, detección de alertas y estados de dominio. | Un atendido/resuelto no cambia deuda ni estado de alumno. |
| D-07 | 2026-09-15 | Preservar historia; baja futura de profesor mediante inactivación. | No borrado físico para dar de baja ni reconstrucción ficticia del pasado. |
| D-08 | 2026-09-15 | Indicadores con fuente, unidad, sede y periodo explícitos. | Resolver por decisión las diferencias de activos y periodos; no corregirlas de forma tácita. |
| D-09 | 2026-09-15 | Todas las fases comenzaron Pendientes. | Elaborar el documento no equivale a implementar o desplegar una fase. |
| D-10 | 2026-09-15 | La entrega documental inicial se hizo mediante rama/PR. | Decisión histórica de PR #253; no limita autorizaciones funcionales posteriores. |
| D-11 | 2026-09-15 | Para F1 se habilitan únicamente tres causas ya verificables: mensualidad regular sin cobertura del período vigente, inscripción regular sin cobertura y reposición regular `DISPONIBLE`. `pendientes_gestion` conserva estado y atención transversal. | La tabla guarda identidad/origen, estado y trazabilidad; ADMIN gestiona y ADMIN/VERIFICADOR consultan dentro de su sede. |
| D-12 | 2026-09-15 | PR #254 y corrección #257 integrados a `main`. | F1 pasa a **Implementado**; los tipos diferidos permanecen asignados a F2/F4/F5. |
| D-13 | 2026-09-15 | Resolver P-02 separando obligación, periodo financiero y cobro; cierres históricos inmutables; saldo solo con obligación registrada; pagos válidos como reducción del saldo. | Define el primer incremento de F2 y evita recrear deuda con precios actuales o una contabilidad paralela. |
| D-14 | 2026-09-15 | F2 empieza con una vista/API de solo lectura sin migración. | PR #261 agrega “Obligaciones y saldos” y comparación con cierre; no modifica pagos ni cierres. |
| D-15 | 2026-09-16 | Reutilizar la definición financiera de F2 para incorporar `SALDO_INTENSIVO_PENDIENTE` a F1, con identidad alumno + curso y sin depender del estado administrativo del alumno. Un curso terminado no liquida por sí mismo la obligación. | PR #262 expone el saldo registrado en la cola existente; pagos `VALIDO` reducen la causa y la liquidación la resuelve sin modificar el pago desde F1. |
| D-16 | 2026-09-16 | Registrar la comprobación funcional ADMIN del resumen financiero, saldo intensivo en pendientes y deep link de pago, junto con checks y deploy exitosos del SHA integrado, sin equipararla a la verificación completa de todos los criterios de F2. | F2 queda **Desplegado** con verificación parcial documentada; el cierre como **Verificado** exige completar la evidencia restante del criterio de terminado. |
| D-17 | 2026-09-17 | Cerrar el alcance funcional de F5 usando reglas deterministas y fuentes compartidas con F1/F2/F4; los prospectos sin sede se persisten como pendientes globales ADMIN con `sede_id=NULL`, sin sede ficticia. | F5 queda **Desplegado**. La prioridad de reglas nuevas continúa sin decisión y se representa como `NEUTRA`; pasar a **Verificado** requiere comprobación operativa dirigida en producción. |
| D-18 | 2026-09-17 | Resolver P-06 para el cierre de F6: nuevos alumnos se miden por `fecha_inicio` sin contar reactivaciones como altas nuevas; bajas solo desde eventos fiables hacia delante; asistencia solo con sesiones `REALIZADA` no canceladas y snapshot completo; prospecto se mide como oportunidad/persona destinataria de clases y no como número de WhatsApp; conversión es una oportunidad de la cohorte de apertura que posteriormente alcanza una inscripción Sharky `COMPLETED`. | PR #309 ya implementó nuevos alumnos, bajas y asistencia. Prospectos/conversión se implementan desde `main` en micro-pasos: primero autoridad durable sin PII, después productor/lifecycle, vínculo de conversión y finalmente lectura del dashboard. No se hace backfill sin evidencia ni se equiparan Flow, pago iniciado o mensaje con conversión. |
| D-19 | 2026-09-17 | El enriquecimiento de sede de una oportunidad F6 solo puede provenir de una selección estructurada vigente de Sharky. El UUID interno de la oportunidad se conserva dentro del estado cifrado; una conversación previa sin ese UUID solo puede usar fallback cuando exista exactamente una oportunidad `OPEN` no ambigua para el contacto. | Confirmar o cambiar sede actualiza únicamente `sede_clave` de la misma oportunidad; no crea otra fila, no cambia cohorte/estado, no infiere desde texto libre y no habilita todavía conversión ni dashboard. |

### 11.2 Decisiones pendientes antes del incremento afectado

No es necesario resolverlas todas para iniciar una fase; sí resolver cada una antes de implementar el comportamiento que dependa de ella. Documentarlas no cambia las reglas existentes.

| ID | Fase | Decisión pendiente | Quién debe validarla / condición |
| --- | --- | --- | --- |
| P-03 | F3 | Fuente del nivel, notas con autoría y cobertura temporal de cambios del alumno. | Operación; datos verificables, sin completar historia por inferencia. |
| P-04 | F4 | Mapeo de etapas, contacto/participante/oportunidad, último contacto, conversión y retención mínima. | Responsable comercial; cumplimiento de Core Rules y fuentes existentes. |
| P-05 | F5 | Prioridad alta/media/baja de las reglas nuevas. Los umbrales habilitados ya se obtienen de configuración validada y continuidad queda deliberadamente inactiva mientras falten días/alcance. | Operación; `NEUTRA` se mantiene hasta una decisión explícita y no implica prioridad baja. |
| P-07 | F7 | Vigencia de asignaciones, unidad de carga y registro de sustituciones. | Responsable de profesores; conservar historia y clases compartidas. |
| P-08 | F8 | Matriz de cobertura y representación de antes/después con registros existentes. | Administración; consistencia entre resultado y evento, sin inventar datos pasados. |
| P-09 | F9 | Corte diario, necesidad de instantánea y correcciones posteriores. | Operación; no confundir cierre operativo con cierre financiero. |

P-01 quedó resuelta para el alcance inicial al implementar F1. P-02 quedó resuelta para el primer incremento mediante D-13. P-05 conserva únicamente la decisión de prioridad: los umbrales y el comportamiento de activación ya están definidos por la configuración validada de F5. P-06 quedó resuelta por D-18; su implementación restante se divide en micro-pasos y no autoriza inferir historia previa. Si un incremento posterior requiere ampliar esas decisiones, se registra una decisión adicional; no se borra la anterior.

## 12. Registro de progreso

### 12.1 Historial

| Fecha | Trabajo realizado | Evidencia / límite | Resultado |
| --- | --- | --- | --- |
| 2026-09-15 | Revisión dirigida de fuentes de GitHub y aclaración de «Charquí MD». | Commit de referencia y rutas de la sección 5; Core Rules confirmado por el solicitante. | Base existente y limitaciones documentadas. |
| 2026-09-15 | Definición de las nueve fases, dependencias, criterios y registros. | PR #253. | Roadmap documental integrado. |
| 2026-09-15 | Implementación del primer incremento de F1. | PR #254; `api/pendientes.php`, `config/centro-pendientes.php`, migración aditiva y vista administrativa. | Integrado a `main`; Quality exitoso. |
| 2026-09-15 | Corrección de render de acción ATENDER. | PR #257; `public/pendientes.php` y regresión. | Integrado a `main`; `ATENDIDO`/`RESUELTO` ya no muestran “Marcar atendido”. |
| 2026-09-15 | Análisis F2 y resolución P-02. | Revisión de periodos, cierres, obligaciones y abonos sobre `main` `d096e845...`. | Contrato financiero del primer incremento documentado. |
| 2026-09-15 | Primer incremento F2: lectura unificada de obligaciones/saldos y comparación con cierre. | PR #261; API/vista de solo lectura; sin migración. | Integrado a `main` como `c68c722...`. |
| 2026-09-16 | Segundo incremento F2: saldo de intensivo en Centro de pendientes. | PR #262; `config/centro-pendientes.php`, vista y regresión; sin migración ni cambios a pagos. | Integrado a `main` como `bed91c7...`; Quality del PR y Quality #1409 de `main` exitosos; deploy #229 exitoso. |
| 2026-09-16 | Verificación funcional representativa de F2 en producción. | Sesión ADMIN: vista “Obligaciones y saldos”, Centro de pendientes y deep link hacia Pagos; marcador desplegado `bed91c7...`; regresión `CENTRO_PENDIENTES_REGRESSION_OK`. | Evidencia parcial correcta; F2 permanece **Desplegado** hasta completar todos sus criterios de verificación. |
| 2026-09-17 | Implementación incremental de F5 sobre fuentes compartidas. | Prospectos, ausencias, continuidad, saldo, mensualidad, reposición e inscripción; regresiones específicas y Quality por incremento. | Cobertura funcional del catálogo comprometido sin nueva cola ni reglas financieras paralelas. |
| 2026-09-17 | Integración final F1/F4/F5 de prospectos sin seguimiento. | PR #298; Quality #1534 y #1535; Deploy #264; `CENTRO_PENDIENTES_MIGRATION_OK`; producción `cf7dc078...`. | Último hueco funcional de F5 cerrado; fase pasa a **Desplegado** y queda pendiente solo verificación operativa completa para **Verificado**. |
| 2026-09-17 | F6 Dashboard: integración incremental de autoridades y contratos seguros. | PR #300–#305; Quality post-merge #1540, #1545, #1547, #1549, #1551 y #1553; Deploy #266–#271; producción `f87f14d...`; evidencia en `F6-DASHBOARD-STATUS.md`. | Base funcional de F6 integrada; revisiones automáticas posteriores identificaron huecos técnicos adicionales antes del cierre. |
| 2026-09-17 | F6 Dashboard: cierre de hallazgos técnicos publicados. | PR #307; Quality #1557 en PR y #1558 en `main`; Deploy #273; producción `6d521421...`; marcador, sintaxis PHP y health verificados. | Los indicadores ya publicados cumplen contrato/detalle reconciliable y los hilos técnicos de #300/#302/#305/#306 quedaron resueltos; F6 sigue **En implementación** únicamente por P-06. |
| 2026-09-17 | F6 P-06: autoridad durable de oportunidades. | PR #311; Quality #1590/#1591; Deploy #276; producción `41a38479...`; migración F6 y health verificados. | Esquema hash-only integrado sin productor, conversión ni métricas públicas. |
| 2026-09-17 | F6 P-06: segundo micro-incremento, productor mínimo de oportunidades. | PR #312; Quality #1598 en PR y #1599 en `main`; Deploy #277; producción `4bdd3acd...`; marcador, migración F6, helper/recovery y health verificados. Codex automático detectó dos P1 de durabilidad, ambos corregidos y resueltos antes del merge. | **Desplegado y verificado técnicamente** para este micro-alcance: webhook y recovery comparten la frontera durable, los fallos dejan el recibo pendiente para retry y el mismo evento es idempotente. Conversión, exclusión, enriquecimiento posterior y dashboard quedan fuera. |
| 2026-09-17 | F6 P-06: tercer micro-incremento, enriquecimiento estructurado de sede. | Rama `f6/p06-opportunity-venue-enrichment` desde `main` `188ef09d...`; sin reutilizar PR #310. | En implementación: enlaza la selección canónica de sede con la misma oportunidad `OPEN`, conserva cohorte/fuente/estado y falla sin adivinar ante múltiples oportunidades sin UUID interno. Conversión y dashboard quedan fuera. |

### 12.2 Estado de las fases

| Fase | Estado | Base que se reutiliza | Próximo paso |
| --- | --- | --- | --- |
| F1 Centro de pendientes | Implementado | Alertas, obligaciones, reglas de acceso, reposiciones regulares y `pendientes_gestion` | Completar su verificación global cuando se prueben los casos restantes de F1; los tipos F5 ya consumen la misma cola. |
| F2 Finanzas | Desplegado | Pagos, abonos, obligaciones registradas, reglas, periodos, reportes y cierres | Completar evidencia de varios abonos/liquidación, rechazo de sobrepago, edición e invalidación, P1/P15 y sedes aplicables, inscripción cubierta y comparación cierre/actual; después marcar F2 Verificado. |
| F3 Expediente 360° | Pendiente | Ficha y timeline | Resolver P-03 y definir secciones a completar. |
| F4 CRM / Sharky | Pendiente | Memoria, atribución y contactos | Resolver P-04 sin cambiar el funnel. |
| F5 Alertas | Desplegado | Alertas, F1, F2, proyección F4 y configuración F5 | Realizar verificación operativa dirigida en producción sobre casos reales disponibles; no fabricar datos para forzar escenarios. |
| F6 Dashboard | En implementación | Dashboard, tiempo operativo, F1/F2/F5, P-06 resuelta por D-18/D-19, lecturas puras y productor durable de oportunidades desplegado | Cerrar el enriquecimiento estructurado de sede y después implementar, en otro micro-paso, el vínculo verificable de conversión; no publicar prospectos/conversión hasta contar con cobertura suficiente. |
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

**Antes de modificar alumnos, pagos, mensualidades, asistencia, ausencias, reposiciones, profesores, usuarios o Sharky por motivos incluidos en este roadmap, es obligatorio consultar este documento y actualizarlo con las decisiones y el progreso del cambio.** También se consulta `AGENTS.md` y, para Sharky, Core Rules y los documentos aplicables.

Para retomar el trabajo desde otra conversación:

1. Leer propósito, exclusiones, estado real y registros de decisiones/progreso.
2. Comparar las fuentes relevantes de GitHub con el último commit de referencia, únicamente dentro de la fase autorizada.
3. Identificar el incremento, sus datos y consumidores, resolver sus decisiones pendientes y registrar el criterio de terminado.
4. Mantener el orden general y las reglas para evitar interferencias. No usar un dato supuesto como dependencia satisfecha.
5. Al terminar el incremento, registrar evidencia y siguiente paso sin iniciar otra fase automáticamente.

### Cierre de la misión documental inicial — histórico

- PR #253 creó este documento maestro.
- Las nueve fases quedaron definidas con problema, objetivo, alcance, dependencias, comportamiento, información, riesgos, exclusiones, criterio de terminado y estado.
- Las fuentes de GitHub, los límites de la comprobación y la relación con Core Rules quedaron documentados.
- Ese cierre describía exclusivamente la misión documental inicial y **no** representa el estado funcional actual; para el estado vigente deben leerse las secciones 11 y 12.
