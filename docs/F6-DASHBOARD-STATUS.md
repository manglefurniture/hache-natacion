# F6 — Estado del Dashboard operativo

Fecha de actualización: 2026-09-17.

## Estado

F6 — Dashboard operativo permanece **En implementación**.

La base actualmente integrada y comprobada en producción es `4bdd3acd080b75e94ba309d5e97af1319f7203d1` (PR #312). Esa versión contiene las definiciones aprobadas de nuevos alumnos, bajas y asistencia, la autoridad durable de oportunidades y el productor mínimo del primer turno con recovery y retry.

PR #310 **no se considera integrable como unidad**: mezcló prospectos, conversión, migración, Sharky, dashboard, pruebas y documentación, y la revisión automática encontró problemas reales de identidad/lifecycle e idempotencia. El cierre restante de P-06 se divide desde `main` en micro-pasos independientes.

## Decisiones P-06 ya aplicadas

- **Nuevos alumnos:** alumno único cuya `fecha_inicio` cae dentro del periodo financiero visible. Una reactivación no crea un alta nueva porque no modifica `fecha_inicio`.
- **Bajas:** solo eventos `ALUMNO_BAJA` registrados con cobertura fiable hacia delante. No se usa `updated_at` como fecha inferida.
- **Asistencia porcentual:** solo sesiones `REALIZADA` no canceladas con snapshot persistido completo y un registro por cada alumno esperado. Sesiones incompletas quedan fuera del denominador.

## Cierre restante de P-06

Las decisiones de producto para el cierre restante siguen siendo:

- **Prospecto:** la unidad objetivo es una oportunidad/persona destinataria de las clases, no el número de WhatsApp. Los casos sin sede confirmada deben conservarse como `SIN_SEDE`.
- **Conversión:** una conversión requiere una inscripción Sharky `COMPLETED`; abrir un Flow, enviar información o iniciar pago no son conversiones. La cohorte se define por la apertura de la oportunidad y puede convertirse después.

No se reconstruirá historia previa al inicio real de cobertura.

## Micro-paso actual: enriquecimiento estructurado de sede

PR #311 dejó la autoridad durable y PR #312 activó el productor mínimo del primer turno. Este tercer micro-paso se limita a enriquecer la misma oportunidad `OPEN` cuando Sharky ya tiene una sede confirmada por los controles estructurados del funnel.

Contrato de este incremento:

- el productor conserva en el estado cifrado de Sharky el UUID interno de la oportunidad creada; no agrega teléfono, nombre ni contenido del mensaje;
- `meta:venue:monteverde` y `meta:venue:palapas` pueden persistir únicamente `MONTEVERDE` o `PALAPAS`;
- `Ver otra sede` actualiza `sede_clave` sobre esa misma oportunidad, sin crear otra fila;
- repetir la misma selección es idempotente;
- `opened_at`, `entry_source` y `status` no cambian por confirmar sede;
- conversaciones abiertas antes de este incremento, sin UUID interno, solo usan compatibilidad cuando existe exactamente una oportunidad `OPEN` para el contacto; si hay más de una, se omite el enriquecimiento sin adivinar ni bloquear el funnel;
- fallos técnicos de esquema, lectura o escritura cuando existe una oportunidad durable identificable no completan silenciosamente el turno: la excepción conserva el recibo pendiente para retry;
- texto libre, prefills y dudas laterales no enriquecen sede y continúan sin tener autoridad para seleccionarla.

Este micro-paso **todavía no**:

- marca exclusiones posteriores;
- vincula una inscripción `COMPLETED`;
- calcula conversión;
- publica prospectos/conversión en el dashboard;
- crea `dashboard_prospectos_cobertura_desde`;
- reconstruye oportunidades anteriores a la cobertura durable.

Por tanto, la escritura continúa siendo forward-only y todavía no constituye cobertura publicable del indicador.

## Cobertura vigente

- **Contexto operativo:** sede autorizada, fecha `America/Cancun`, periodo financiero vigente y hora de actualización.
- **Alumnos activos:** definición histórica del dashboard: mensualidad `PAGADA` vigente o intensivo vigente con algún pago `VALIDO`, excluyendo `BAJA`; no equivale a derecho de acceso.
- **Situación financiera:** F2 conserva autoridad para facturación, obligaciones y saldos.
- **Mensualidades pagadas:** cantidad, total y detalle salen de la misma lectura pura.
- **Centro de pendientes y alertas:** F6 consume F1/F5 sin otra cola ni recalcular reglas.
- **Operación del día:** lectura pura de sesiones y marcas; canceladas excluidas de asistencia.
- **Intensivos y avisos:** contratos existentes, sin reconciliar ni escribir estados al consultar.
- **Nuevos alumnos, bajas y asistencia de periodo:** implementados en PR #309 con cobertura explícita.

## Autoridades y límites

| Bloque | Autoridad / fuente |
| --- | --- |
| Pendientes | F1 / `pendientes_gestion` y fuentes compuestas |
| Finanzas | F2 / periodos, `financiero_totales()` y obligaciones |
| Alertas | F5 / mismas causas activas consumidas por F1 |
| Alumnos activos | `config/dashboard-alumnos.php` |
| Mensualidades y avisos | `config/dashboard-indicadores.php` |
| Operación diaria | `config/dashboard-operacion.php` |
| P-06 alumnos/bajas/asistencia | `config/dashboard-p06.php` + cobertura persistida |
| P-06 oportunidad/prospecto | `sharky_prospect_opportunities` + productor del primer turno; dashboard aún sin cobertura publicable |
| Fecha/hora | `config/dashboard-tiempo.php`, `America/Cancun` |

## Evidencia acumulada

| PR | Incremento | Resultado |
| --- | --- | --- |
| #300–#305 | Fuentes F1/F2/F5, operación, alumnos, intensivos y tiempo | Integrados y desplegados |
| #307 | Cierre de hallazgos técnicos de F6 | Integrado y desplegado como `6d521421...` |
| #309 | P-06: nuevos alumnos, bajas y asistencia | Integrado y producción comprobada en `dce535040557d636b01be629f434af1151542b75` |
| #311 | P-06: autoridad durable inerte de oportunidades | Integrado, Quality y producción comprobados en `41a38479...` |
| #312 | P-06: productor mínimo del primer turno prospecto | Integrado y producción comprobada en `4bdd3acd...`; Quality #1598/#1599, Deploy #277; 2 P1 automáticos corregidos/resueltos |
| Este micro-paso | P-06: enriquecer sede estructurada en la oportunidad `OPEN` | En implementación desde `main` `188ef09d...`; sin conversión ni publicación de dashboard |
| #310 | P-06 mezclado: prospectos/conversión/Sharky/dashboard | Abierto; no debe mergearse como unidad |

## Criterio para continuar el cierre

Este incremento debe pasar Quality y revisión automática, integrarse desde su rama aislada, desplegarse mediante auto-deploy y verificarse sin cambiar el funnel. Después se abordará por separado el vínculo verificable de una inscripción `COMPLETED`; la publicación de prospectos/conversión en dashboard permanece fuera hasta contar con cobertura suficiente.
