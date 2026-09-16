# Hache Natación — Roadmap de mejoras internas

**Documento maestro:** `docs/HACHE-INTERNAL-IMPROVEMENTS-ROADMAP.md`  
**Repositorio y fuente de verdad:** `manglefurniture/hache-natacion`  
**Fecha de elaboración:** 2026-09-15  
**Última actualización:** 2026-09-15  
**Base de esta actualización:** `main` en `d096e8457f8da8c00eadf3ed5ee61a8f3cff6ed0`  
**Estado vigente:** F1 **Implementado** · F2 **En análisis** · F3–F9 **Pendientes**

## 1. Propósito

Conservar el contexto de la siguiente etapa de mejoras internas de Hache Natación: qué construir, en qué orden, qué reutilizar, qué decisiones respetar y qué evidencia permite avanzar cada fase sin duplicar módulos ni contradecir reglas vigentes.

Este documento es obligatorio antes de modificar, por motivos incluidos en este roadmap, alumnos, pagos, mensualidades, inscripciones, asistencia, ausencias, reposiciones, profesores, usuarios o Sharky.

## 2. Fases y orden

El orden general se mantiene:

1. Centro de pendientes.
2. Finanzas internas.
3. Expediente 360° del alumno.
4. CRM interno de prospectos / Sharky.
5. Alertas internas.
6. Dashboard operativo.
7. Gestión interna de profesores.
8. Auditoría interna de acciones administrativas.
9. Resumen operativo diario.

No se inicia una fase posterior por completar un incremento anterior. Una dependencia posterior puede enriquecer una fase ya integrada sin obligar a rehacerla.

## 3. Principios obligatorios

1. **GitHub es la fuente de verdad.** Revisar `main` antes de diseñar o implementar.
2. **Reutilizar antes de crear.** Extender módulos, reglas y vistas actuales cuando sea suficiente.
3. **Una autoridad por dato.** Pendientes, expediente, alertas, dashboard y resumen consumen datos; no mantienen una segunda contabilidad, asistencia o estado del alumno.
4. **Historia y actualidad son distintas.** No recalcular el pasado con tarifas, sedes, planes, porcentajes o profesores actuales.
5. **Dato ausente no es cero.** Falta de evidencia no significa pagado, resuelto, inscrito o sin deuda.
6. **Cambios pequeños y reversibles.** Cada incremento debe delimitar archivos, contratos, pruebas y forma de retirar el cambio sin perder historia previa.
7. **Preservar permisos y sede.** Una vista agregada no amplía permisos ni mezcla información entre sedes.
8. **Lecturas nuevas sin efectos colaterales.** No reutilizar una API GET como fuente sin comprobar si escribe o promueve estado.
9. **No automatizar sin regla expresa.** Un pendiente o alerta no paga, inscribe, bloquea, sanciona ni envía mensajes por sí mismo.
10. **Documentar al cambiar.** Registrar aquí decisión, alcance, PR, validaciones, estado y siguiente paso.

## 4. Qué queda fuera

- Auditoría general de seguridad, SEO o frontend público.
- Rediseño global, migración tecnológica o refactorización general.
- Nómina, contabilidad fiscal o facturación tributaria.
- Cambios de tarifas, porcentajes, convenios, mínimos, ciclos, elegibilidad o políticas salvo autorización específica.
- Nuevas campañas o canales de comunicación.
- Aprendizaje autónomo de Sharky que modifique producción.
- Reconstruir historia retroactiva sin evidencia.

## 5. Autoridades existentes que se preservan

| Área | Autoridad actual | Regla para estas fases |
| --- | --- | --- |
| Alumno, sede, plan, horario y estado administrativo | módulos de alumnos + reglas de acceso | No duplicar ni reinterpretar estados. |
| Pagos | `pagos` y APIs de pago | `VALIDO`/`INVALIDADO` y referencias originales son autoridad. |
| Mensualidades | `mensualidades` | `importe_a_cobrar` es la obligación registrada; no sustituirlo por el precio actual del plan. |
| Intensivos | curso + relación alumno/curso + pagos válidos | Saldo = precio del curso para ese alumno menos suma de abonos válidos. |
| Inscripciones | `inscripciones` + pagos relacionados | Usar importe registrado; no inventar una inscripción histórica desde la tarifa vigente. |
| Periodos financieros | `config/periodos-financieros.php` | Mantener rango personalizado por sede y periodo. |
| Cierres | `cierres_mensuales` | Un cierre guardado es una instantánea histórica; no se sobrescribe por ajustes posteriores. |
| Reparto | configuración de sede | No fijar porcentajes nuevos en vistas o reportes. |
| Asistencia / ausencias / reposiciones | módulos operativos vigentes | Mantener producto, sesión, elegibilidad y estados actuales. |
| Atención de asuntos | F1 / `pendientes_gestion` | Estado de gestión no cambia la causa financiera u operativa. |
| Sharky | `SHARKY-CORE-RULES.md` y documentos exigidos por `AGENTS.md` | CRM y módulos internos consumen información; no controlan el funnel. |
| Auditoría | `auditoria_eventos`, `historial` y auditorías específicas | Completar cobertura sin crear otra fuente de dominio. |

## 6. Riesgos transversales ya comprobados

- Un abono de intensivo no equivale a liquidación ni derecho a clase.
- Mensualidad vigente y mensualidad vencida son señales distintas; Palapas conserva ciclos P1/P15.
- Periodo financiero, periodo de obligación y fecha de cobro son conceptos distintos.
- “Activo” en dashboard no equivale necesariamente a autorización de acceso.
- Algunas consultas existentes pueden tener efectos secundarios; las vistas nuevas deben evitarlo.
- El timeline puede contener horas de presentación; no convertirlas en marcas de auditoría reales.
- La baja lógica debe preservar historia; no reconstruir pasado desde asignaciones actuales.
- Docencia compartida debe distinguir ausencia de un profesor de cancelación de la sesión.

## 7. FASE 1 — Centro de pendientes

### Objetivo

Concentrar asuntos administrativos con causa comprobable, estado de atención, responsable y acceso a su fuente sin modificar el registro causante.

### Alcance implementado

- Mensualidad regular sin cobertura del periodo vigente.
- Inscripción regular sin cobertura.
- Reposición regular `DISPONIBLE`.
- Estados de gestión `PENDIENTE`, `ATENDIDO` y `RESUELTO`.
- Revalidación de la causa antes de resolver.
- Gestión persistente separada de pagos, mensualidades, inscripciones y reposiciones.
- Aislamiento por sede.
- ADMIN gestiona; ADMIN/VERIFICADOR consultan según permisos vigentes.

### Diferidos deliberadamente

- Saldos de intensivo → F2.
- Prospectos sin seguimiento → F4.
- Varias ausencias, continuidad de intensivo y reglas nuevas → F5.

### Estado

**Implementado.** El PR #254 fue integrado a `main` el 2026-09-15. Quality pasó sobre el incremento. Posteriormente, el PR #257 corrigió el render de `public/pendientes.php` para que “Marcar atendido” aparezca solo cuando el estado efectivo es `PENDIENTE` y la causa continúa activa; `ATENDIDO` conserva responsable, fecha y nota, y `RESUELTO` no ofrece la acción.

No se marca **Verificado** únicamente por la existencia del código: la convención exige evidencia funcional de producción para ese estado.

## 8. FASE 2 — Finanzas internas

### Problema

Existen pagos, abonos, reportes, periodos, reparto y cierres, pero falta una lectura única y explicable de obligación, pagado, saldo, periodo y relación con el cierre histórico.

### Objetivo

Completar la visión financiera sin crear contabilidad paralela y dejando una definición reutilizable por F1, F3, F5, F6 y F9.

### Decisión P-02 — resuelta para el primer incremento

1. **Tres tiempos separados.** La vista distingue `periodo de obligación`, `periodo financiero` y `fecha de cobro`; no intenta forzar que coincidan.
2. **Periodo financiero como autoridad de reportes/cierres.** Para totales de reparto y comparación con cierres se reutiliza `financiero_totales()` y `financiero_rango()`.
3. **Cierre inmutable.** Un cierre guardado no se recalcula ni se sobrescribe. La vista puede comparar `actual` contra `cierre guardado` y mostrar una diferencia causada por correcciones posteriores.
4. **Saldo solo con obligación registrada.** Se cuantifica saldo cuando existe una fuente explícita de importe: `mensualidades.importe_a_cobrar`, `inscripciones.importe` o precio de intensivo para el alumno/curso. Una señal sin importe verificable se muestra como “sin importe cuantificable”, no como deuda calculada desde la tarifa actual.
5. **Pagado desde movimientos válidos.** El monto pagado se obtiene de pagos `VALIDO`; pagos invalidados permanecen visibles como historia, pero no reducen saldo.
6. **Intensivos con varios abonos.** Se suman pagos válidos por `alumno_id + intensivo_id`; el precio del curso se cuenta una sola vez por obligación del alumno.
7. **Mensualidad e inscripción no se convierten en multiabono.** Se conserva el contrato vigente de un pago válido por obligación.
8. **Reparto sin parámetros copiados.** Los porcentajes y mínimos provienen de la sede y no se persisten en una segunda configuración.

### Primer incremento autorizado

- Nueva lectura interna, solo lectura, sobre obligaciones y saldos verificables.
- Resumen del periodo financiero seleccionado.
- Comparación `actual vs cierre guardado` sin modificar el cierre.
- Listado de saldos cuantificables de mensualidades e intensivos; inscripción se incorpora solo donde el importe/relación permita una lectura inequívoca.
- Enlace al detalle/movimiento existente en lugar de duplicar acciones de pago.
- Sin migración si las fuentes actuales son suficientes.

### Criterio del incremento

- Cero pagos, un abono, varios abonos y liquidación de intensivo producen saldo correcto.
- Una mensualidad usa su `importe_a_cobrar`, no el precio actual del plan.
- Un pago invalidado deja de reducir saldo.
- El periodo financiero presenta su rango real por sede.
- Un cierre histórico permanece intacto y una diferencia posterior se explica como comparación, no como modificación.
- La consulta no escribe en alumnos, pagos, mensualidades, intensivos, cierres ni periodos.

### Estado

**En análisis.** Rama de trabajo: `feature/finanzas-internas-fase-2`. P-02 queda resuelta con las reglas anteriores antes de modificar código funcional.

## 9. FASE 3 — Expediente 360° del alumno

**Objetivo:** ampliar la ficha/timeline existentes para componer datos principales, estado, sede, horarios, planes, pagos, asistencia, ausencias, reposiciones, historial y notas internas desde sus fuentes.

**Pendiente P-03:** fuente de nivel académico, notas con autoría, cobertura histórica de cambios de horario/sede y agrupación de eventos relacionados.

**No hacer:** crear una segunda ficha maestra, inferir nivel, reconstruir pasado con el estado actual o duplicar pagos/eventos.

**Estado:** Pendiente.

## 10. FASE 4 — CRM interno / Sharky

**Objetivo:** proyectar información comercial trazable de Sharky para seguimiento interno sin convertir el CRM en controlador del funnel.

**Base:** memoria comercial, atribución, contactos y relaciones verificadas con alumnos.

**Pendiente P-04:** mapeo de etapas, identidad contacto/destinatario/oportunidad, último contacto, conversión y retención mínima.

**Contratos:** `entry_source` real se conserva; campaña no equivale a elección; contacto, alumno, responsable y profesor no se mezclan; una inscripción exige alta real.

**No hacer:** modificar prompts, routing, prioridades, sede/producto, pagos o Flows desde una etiqueta CRM.

**Estado:** Pendiente.

## 11. FASE 5 — Alertas internas

**Objetivo:** ampliar reglas determinísticas sobre hechos verificables y compartirlas con F1 sin crear otra cola.

**Alcance previsto:** ausencias consecutivas, saldo, mensualidad vencida, reposición, prospecto sin seguimiento, intensivo terminado sin continuidad e inconsistencias administrativas relevantes.

**Pendiente P-05:** umbrales de ausencias, seguimiento, continuidad y prioridad.

**No hacer:** puntuaciones opacas, sanciones, comunicaciones externas o generación automática de créditos/reposiciones.

**Estado:** Pendiente.

## 12. FASE 6 — Dashboard operativo

**Objetivo:** extender el dashboard vigente con indicadores que tengan fuente, unidad, sede, periodo, filtros y detalle reconciliable.

**Indicadores previstos:** alumnos activos, altas, bajas, ingresos, saldos, asistencia, prospectos, conversiones e información por sede.

**Pendiente P-06:** definiciones de activos, altas/bajas, denominador de asistencia y cohortes de conversión.

**Regla:** F6 consume definiciones de F2/F4/F5; no recalcula dinero ni convierte ausencia de datos en cero.

**Estado:** Pendiente.

## 13. FASE 7 — Gestión interna de profesores

**Objetivo:** integrar activo/inactivo, horarios, clases, sustituciones, incidencias, carga e historia preservando referencias pasadas y docencia compartida.

**Pendiente P-07:** vigencia temporal de asignaciones, unidad de carga y registro mínimo de sustitución.

**No hacer:** borrado físico, nómina, reconstrucción ficticia de clases impartidas o creación de roles sin necesidad.

**Estado:** Pendiente.

## 14. FASE 8 — Auditoría interna

**Objetivo:** completar cobertura consultable de actor, acción, fecha, entidad, resultado, motivo y antes/después cuando exista.

**Pendiente P-08:** matriz de acciones cubiertas y representación compatible de cambios.

**Regla:** auditoría describe operaciones; no ejecuta ni duplica pagos, inscripciones o acciones de Sharky.

**Estado:** Pendiente.

## 15. FASE 9 — Resumen operativo diario

**Objetivo:** componer apertura y cierre del día desde F1–F8 sin crear otro motor financiero ni mutar sesiones al consultar.

**Inicio previsto:** alumnos activos, clases previstas, pagos pendientes, reposiciones, prospectos e incidencias.

**Cierre previsto:** asistencias, ausencias, cobros por fecha real, altas, pendientes nuevos e incidencias.

**Pendiente P-09:** momento de corte, necesidad de instantánea y tratamiento de correcciones posteriores.

**Estado:** Pendiente.

## 16. Dependencias entre fases

- F1 consume F2/F4/F5 cuando esas fuentes estén disponibles; no espera a que se completen para existir.
- F2 es autoridad de lectura financiera para F1/F3/F5/F6/F9.
- F3 compone información; no se convierte en origen de pagos, asistencia o identidad.
- F4 proyecta seguimiento; no controla Sharky.
- F5 detecta; F1 gestiona.
- F6 agrega; no crea otra definición financiera o comercial.
- F7 preserva historia de profesores y clases.
- F8 completa trazabilidad transversal sin sustituir registros de dominio.
- F9 consume definiciones consolidadas y declara datos faltantes.

## 17. Relación con Sharky

`SHARKY-CORE-RULES.md` conserva autoridad normativa. Antes de tocar cualquier archivo relacionado con Sharky se deben seguir además los documentos exigidos por `AGENTS.md`.

Este roadmap no reactiva Brain, no cambia el funnel Sharky 3.0 y no autoriza que CRM, pendientes, alertas o dashboard envíen mensajes por sí mismos.

## 18. Registro de decisiones

| ID | Fecha | Decisión | Efecto |
| --- | --- | --- | --- |
| D-01 | 2026-09-15 | Mantener nueve fases en el orden definido. | No añadir fases por conveniencia de implementación. |
| D-02 | 2026-09-15 | Reutilizar módulos existentes y distinguir dato actual de historia. | Evita duplicar fuentes. |
| D-03 | 2026-09-15 | Preservar reglas financieras vigentes. | Intensivos permiten abonos múltiples por alumno/curso; mensualidad/inscripción conservan unicidad vigente. |
| D-04 | 2026-09-15 | `SHARKY-CORE-RULES.md` es la autoridad de Sharky. | CRM solo consume/proyecta. |
| D-05 | 2026-09-15 | Separar atención, detección y estado de dominio. | `ATENDIDO`/`RESUELTO` no liquidan deuda ni cambian alumno. |
| D-06 | 2026-09-15 | F1 usa `pendientes_gestion` y tres causas iniciales verificables. | Gestión persistente sin copiar el dato causante. |
| D-07 | 2026-09-15 | PR #254 integrado; PR #257 corrige la acción ATENDER. | F1 pasa a Implementado; verificación de producción sigue siendo un hito separado. |
| D-08 | 2026-09-15 | P-02 resuelta: periodo financiero, obligación y fecha de cobro se presentan por separado; cierres son inmutables; saldo solo con obligación registrada. | Define el primer incremento de F2 y evita deuda/reparto inventados. |

## 19. Decisiones pendientes

| ID | Fase | Decisión |
| --- | --- | --- |
| P-03 | F3 | Fuente de nivel, notas con autoría y cobertura temporal. |
| P-04 | F4 | Etapas, oportunidad, último contacto, conversión y retención. |
| P-05 | F5 | Umbrales y prioridad de alertas nuevas. |
| P-06 | F6 | Contratos exactos de activos, altas/bajas, asistencia y conversiones. |
| P-07 | F7 | Vigencia de asignaciones, carga y sustituciones. |
| P-08 | F8 | Matriz de auditoría y antes/después. |
| P-09 | F9 | Corte diario, instantánea y correcciones posteriores. |

P-01 quedó resuelta por el diseño e integración de F1. P-02 queda resuelta para el primer incremento de F2 mediante D-08. Si un incremento futuro requiere ampliar esas decisiones, se registra una nueva decisión sin borrar la anterior.

## 20. Registro de progreso

| Fecha | Fase | Trabajo | Evidencia / resultado |
| --- | --- | --- | --- |
| 2026-09-15 | Documento | Roadmap inicial de nueve fases. | PR #253 integrado a `main`. |
| 2026-09-15 | F1 | Centro de pendientes: mensualidad regular, inscripción regular y reposición disponible. | PR #254 integrado; Quality exitoso. |
| 2026-09-15 | F1 | Corregir acción “Marcar atendido” fuera de estado PENDIENTE. | PR #257 integrado. |
| 2026-09-15 | F2 | Revisión de `financiero_totales`, cierres, resumen financiero, mensualidades y saldo de intensivos; resolución P-02. | Rama `feature/finanzas-internas-fase-2`; sin cambio funcional todavía al registrar esta fila. |

## 21. Convención de estados

| Estado | Significado |
| --- | --- |
| Pendiente | No inició trabajo funcional. |
| En análisis | Fuentes, decisiones, contratos y límites del incremento están siendo confirmados. |
| En implementación | Se desarrolla el cambio autorizado en rama/PR. |
| En revisión | El incremento está listo para revisión y checks. |
| Implementado | Código integrado y pruebas/revisión aplicables completadas; no implica producción. |
| Desplegado | La versión correspondiente llegó a producción por el proceso autorizado. |
| Verificado | El comportamiento y compatibilidad fueron comprobados en producción con evidencia fechada. |

Una fase completa solo se considera terminada cuando todo su alcance comprometido alcanza **Verificado** o cuando un subalcance queda explícitamente diferido a otra fase mediante decisión registrada.

## 22. Regla de continuidad

Antes de cada incremento:

1. Leer este documento y `AGENTS.md`.
2. Comparar `main` con el último estado registrado.
3. Identificar la fase y resolver solo sus dependencias directas.
4. Implementar cambios pequeños y reversibles.
5. Ejecutar regresiones y checks aplicables.
6. Registrar PR/commit, estado, pendientes y siguiente paso.
7. No iniciar automáticamente la siguiente fase.

Para cambios de Sharky, consultar además Core Rules y los documentos que `AGENTS.md` indique.
