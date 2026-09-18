# F6 — Estado del Dashboard operativo

Fecha de actualización: 2026-09-17.

## Estado

F6 — Dashboard operativo permanece **En implementación**.

La base actualmente integrada y comprobada en producción es `6d31f9a7bdca13f7b06d534426c9ab5c76fc3bae` (PR #316). Esa versión contiene las definiciones aprobadas de nuevos alumnos, bajas y asistencia, la autoridad durable de oportunidades, el productor mínimo del primer turno, el enriquecimiento estructurado de sede con retry durable y el vínculo verificable con una inscripción Sharky `COMPLETED`.

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

## Micro-paso cerrado anterior: enriquecimiento estructurado de sede

PR #311 dejó la autoridad durable, PR #312 activó el productor mínimo del primer turno y PR #314 integró este tercer micro-paso: enriquecer la misma oportunidad `OPEN` cuando Sharky ya tiene una sede confirmada por los controles estructurados del funnel.

Contrato de este incremento:

- el productor conserva en el estado cifrado de Sharky el UUID interno de la oportunidad creada; no agrega teléfono, nombre ni contenido del mensaje;
- `meta:venue:monteverde` y `meta:venue:palapas` pueden persistir únicamente `MONTEVERDE` o `PALAPAS`;
- `Ver otra sede` actualiza `sede_clave` sobre esa misma oportunidad, sin crear otra fila;
- repetir la misma selección es idempotente;
- `opened_at`, `entry_source` y `status` no cambian por confirmar sede;
- conversaciones abiertas antes de este incremento, sin UUID interno, solo usan compatibilidad cuando existe exactamente una oportunidad `OPEN` para el contacto; si hay más de una, se omite el enriquecimiento sin adivinar ni bloquear el funnel;
- fallos técnicos de esquema, lectura o escritura cuando existe una oportunidad durable identificable no completan silenciosamente el turno: la excepción conserva el recibo pendiente para retry;
- texto libre, prefills y dudas laterales no enriquecen sede y continúan sin tener autoridad para seleccionarla.

Al cierre de PR #314, este micro-paso **todavía no**:

- marca exclusiones posteriores;
- vincula una inscripción `COMPLETED`;
- calcula conversión;
- publica prospectos/conversión en el dashboard;
- crea `dashboard_prospectos_cobertura_desde`;
- reconstruye oportunidades anteriores a la cobertura durable.

Por tanto, la escritura continúa siendo forward-only y todavía no constituye cobertura publicable del indicador.

## Micro-paso cerrado: vínculo con inscripción Sharky `COMPLETED`

PR #316 integró el siguiente micro-paso aislado: una oportunidad F6 solo pasa a `CONVERTED` cuando existe una acción durable de Sharky `register_intensive` o `register_regular` con estado `COMPLETED`.

Contrato de este incremento:

- la oportunidad conserva únicamente `conversion_action_hash`, que referencia el hash de idempotencia de la acción; no se agrega `alumno_id`, teléfono, nombre ni contenido conversacional;
- el vínculo exige el UUID exacto `f6_opportunity_id` y que el `contact_hash` de la oportunidad coincida con el de la acción `COMPLETED`; no existe fallback por contacto para convertir;
- `uq_sharky_prospect_conversion_action` hace uno-a-uno el vínculo: una misma acción `COMPLETED` no puede convertir dos oportunidades;
- al finalizar una inscripción nueva con UUID F6, el cierre del audit y el cambio de la oportunidad a `CONVERTED` ocurren dentro de la misma transacción; si el vínculo falla, el audit no queda falsamente cerrado y el retry durable puede reconciliar;
- si la acción ya estaba `COMPLETED`, tanto intensivo como clases regulares reintentan de forma idempotente el vínculo exacto antes de cerrar la entrega;
- no se hace backfill y las conversaciones anteriores sin UUID F6 no se adivinan ni se enlazan por contacto.

Producción quedó comprobada en `6d31f9a7bdca13f7b06d534426c9ab5c76fc3bae`: el esquema contiene `conversion_action_hash`, el índice único `uq_sharky_prospect_conversion_action`, el marcador `.hache-deployed-sha` coincide con el SHA integrado y el health de `hnatacion.com` responde correctamente. `dashboard_prospectos_cobertura_desde` continúa ausente deliberadamente.

Este micro-paso **todavía no**:

- cierra como `EXCLUDED` una oportunidad provisional cuando la identidad durable demuestra que el contacto no debe contarse como prospecto;
- publica prospectos o conversión en el dashboard;
- declara cobertura histórica anterior al productor durable.

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
| P-06 oportunidad/prospecto | `sharky_prospect_opportunities` + productor del primer turno + sede estructurada + vínculo exacto a inscripción `COMPLETED`; dashboard aún sin cobertura publicable |
| Fecha/hora | `config/dashboard-tiempo.php`, `America/Cancun` |

## Evidencia acumulada

| PR | Incremento | Resultado |
| --- | --- | --- |
| #300–#305 | Fuentes F1/F2/F5, operación, alumnos, intensivos y tiempo | Integrados y desplegados |
| #307 | Cierre de hallazgos técnicos de F6 | Integrado y desplegado como `6d521421...` |
| #309 | P-06: nuevos alumnos, bajas y asistencia | Integrado y producción comprobada en `dce535040557d636b01be629f434af1151542b75` |
| #311 | P-06: autoridad durable inerte de oportunidades | Integrado, Quality y producción comprobados en `41a38479...` |
| #312 | P-06: productor mínimo del primer turno prospecto | Integrado y producción comprobada en `4bdd3acd...`; Quality #1598/#1599, Deploy #277; 2 P1 automáticos corregidos/resueltos |
| #314 | P-06: enriquecer sede estructurada en la oportunidad `OPEN` | Integrado y producción comprobada en `6060e2a...`; Quality #1602/#1603 en PR y #1604 en `main`; Deploy #279; P1 automático de retry corregido y resuelto |
| #316 | P-06: vínculo exacto oportunidad → inscripción Sharky `COMPLETED` | Integrado y producción comprobada en `6d31f9a7...`; Quality del head exitoso; P2 automático sobre doble conversión cubierto por índice único + regresión y resuelto antes del merge; esquema, marcador y health verificados |
| #310 | P-06 mezclado: prospectos/conversión/Sharky/dashboard | Abierto; no debe mergearse como unidad |

## Criterio para continuar el cierre

El vínculo verificable con una inscripción Sharky `COMPLETED` quedó integrado, desplegado y verificado técnicamente sin cambiar el funnel ni publicar métricas. El siguiente micro-paso debe cerrar el hueco mínimo de lifecycle antes de declarar cobertura: una oportunidad provisional que después queda identificada de forma durable como alumno existente no puede permanecer como prospecto `OPEN`. Ese paso debe usar identidad comprobada y el UUID exacto, sin inventar otras exclusiones ni hacer backfill. Solo después corresponde declarar cobertura forward-only y añadir la lectura de prospectos/conversión al dashboard.
