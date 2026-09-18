# F6 — Estado del Dashboard operativo

Fecha de actualización: 2026-09-17.

## Estado

F6 — Dashboard operativo permanece **En implementación**.

La base actualmente integrada y comprobada en producción es `c7178291e5f30a00d58bc09d7586c8b471a8ea2f` (PR #320, sobre PR #318). Esa versión contiene las definiciones aprobadas de nuevos alumnos, bajas y asistencia, la autoridad durable de oportunidades, el productor mínimo del primer turno, el enriquecimiento estructurado de sede con retry durable, el vínculo verificable con una inscripción Sharky `COMPLETED`, la exclusión exacta de oportunidades provisionales cuando identidad durable demuestra que el contacto ya es alumno existente y el fail-closed del lookup de identidad live.

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

- publica prospectos o conversión en el dashboard;
- declara cobertura histórica anterior al productor durable.

## Micro-paso cerrado: exclusión de alumno existente

PR #318 cerró el hueco mínimo de lifecycle acordado después del vínculo de conversión: una oportunidad provisional `OPEN` deja de contar como prospecto cuando identidad durable demuestra que el contacto ya corresponde a un alumno existente.

Contrato de este incremento:

- la exclusión exige el UUID exacto `f6_opportunity_id` conservado en el estado cifrado; no existe fallback por contacto;
- el `student_id` solo sirve como evidencia de identidad y no se persiste en el ledger F6;
- la fila pasa de `OPEN` a `EXCLUDED`, conserva `conversion_action_hash=NULL` y fija `closed_at`;
- una oportunidad ya `CONVERTED` no se degrada;
- repetir la reconciliación es idempotente;
- la reconciliación corre antes de los retornos tempranos de member routing tanto en live como en recovery; la verificación durable también usa la misma exclusión exacta;
- si existe un UUID exacto pero el almacenamiento/contacto no puede reconciliarse, el recibo permanece pendiente para retry en vez de completar silenciosamente;
- conversaciones previas sin UUID F6 no se excluyen por adivinanza.

Codex automático detectó un P1 real en la primera versión del PR: member routing podía devolver antes de llegar a la exclusión. Se corrigió en PR #318 moviendo la reconciliación durable antes de member/commerce routing en live y recovery. La revisión automática posterior de esta documentación detectó un segundo P1 real: `sharky_lab_identity_before()` convertía una excepción de consulta en un falso `found=false`. PR #320 añadió un sentinel `lookup_failed` y obliga a la reconciliación pre-routing a devolver retry en ese caso, evitando que un fallo técnico se confunda con un unmatched válido.

Producción quedó comprobada en `c7178291e5f30a00d58bc09d7586c8b471a8ea2f`: marcador de deploy exacto, sintaxis PHP correcta, sentinel live y fail-closed presentes, reconciliación live activa y health de `hnatacion.com` correcto. `dashboard_prospectos_cobertura_desde` continúa ausente deliberadamente.


## Micro-paso en revisión: cobertura forward-only y lectura backend

PR #321 implementa el primer consumidor publicable del ledger de oportunidades sin añadir UI ni reconstruir historia previa.

Contrato de este incremento:

- `dashboard_prospectos_cobertura_desde` se crea con `INSERT IGNORE` al desplegar este micro-paso; por tanto, ninguna oportunidad abierta antes de ese instante entra en la métrica publicada;
- la cohorte se determina por `opened_at` dentro del periodo consultado y nunca por la fecha de conversión;
- solo `OPEN` y `CONVERTED` forman el denominador; `EXCLUDED` queda fuera;
- una cohorte puede aumentar su número de conversiones después, cuando una inscripción Sharky exacta llegue a `COMPLETED`;
- `SIN_SEDE` se conserva cuando todavía no existe sede estructurada y la fuente se mantiene separada;
- el backend devuelve total, tasa, desglose por cohorte diaria, sede y fuente, más filas reconciliables por UUID de oportunidad;
- el detalle no expone nombre, teléfono, `contact_hash` ni `conversion_action_hash`;
- la lectura es global y exclusiva de ADMIN, igual que el alcance comercial ya acordado;
- el runner F6 exige el nuevo marcador para impedir que un despliegue parcial publique la lectura sin frontera de cobertura;
- no cambia el productor, lifecycle, funnel, Brain, Flow, pagos, takeover ni mensajes de Sharky.

Al crear este registro, PR #321 permanece sujeto a Quality y revisión automática. Integración, despliegue y verificación de producción deben registrarse por separado antes de considerar cerrado el micro-paso.

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
| #318 | P-06: excluir oportunidad provisional de alumno existente | Integrado y producción comprobada en `d334ce5d...`; Quality exitoso; P1 automático por retorno temprano de member routing corregido y resuelto antes del merge; live/recovery, marcador y health verificados |
| #320 | P-06: fail-closed ante error de lookup de identidad live | Integrado y producción comprobada en `c7178291...`; Quality exitoso y revisión automática sin nuevos hallazgos; sentinel `lookup_failed`, retry y health verificados |
| #310 | P-06 mezclado: prospectos/conversión/Sharky/dashboard | Abierto; no debe mergearse como unidad |

## Criterio para continuar el cierre

El lifecycle mínimo acordado para oportunidades ya cubre creación durable, sede estructurada, conversión `COMPLETED` y exclusión exacta de alumno existente. El siguiente micro-paso puede declarar cobertura **forward-only** desde su despliegue y añadir una lectura backend reconciliable de prospectos/conversiones por cohorte, sede y fuente. No debe hacer backfill, reutilizar historia previa ni publicar todavía una UI si el contrato backend no ha pasado primero sus pruebas de detalle/denominador.
