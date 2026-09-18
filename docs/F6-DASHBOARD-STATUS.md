# F6 — Estado del Dashboard operativo

Fecha de actualización: 2026-09-17.

## Estado

F6 — Dashboard operativo permanece **En implementación**.

La base actualmente integrada y comprobada en producción es `dce535040557d636b01be629f434af1151542b75` (PR #309). Esa versión ya contiene las definiciones aprobadas de nuevos alumnos, bajas y asistencia.

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

## Micro-paso actual: autoridad de oportunidad, todavía inerte

Este incremento añade únicamente el esquema durable `sharky_prospect_opportunities` y su verificación de migración.

El esquema:

- no guarda nombre, teléfono ni contenido de mensajes;
- permite varias oportunidades para un mismo `contact_hash`;
- usa `origin_message_hash` como frontera idempotente de origen para impedir duplicados por reintento;
- conserva sede opcional y fuente opcional;
- reserva estados `OPEN`, `CONVERTED` y `EXCLUDED`.

Este micro-paso **no**:

- crea oportunidades desde Sharky;
- decide todavía cuándo una conversación abre una oportunidad nueva o continúa una existente;
- vincula oportunidades con alumnos o inscripciones;
- calcula conversiones;
- publica nuevas métricas en el dashboard;
- crea `dashboard_prospectos_cobertura_desde`.

Por tanto, desplegar este esquema no inicia por sí solo la cobertura de prospectos y no cambia ninguna cifra operativa.

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
| P-06 oportunidad/prospecto | `sharky_prospect_opportunities` existe como esquema, todavía sin productor |
| Fecha/hora | `config/dashboard-tiempo.php`, `America/Cancun` |

## Evidencia acumulada

| PR | Incremento | Resultado |
| --- | --- | --- |
| #300–#305 | Fuentes F1/F2/F5, operación, alumnos, intensivos y tiempo | Integrados y desplegados |
| #307 | Cierre de hallazgos técnicos de F6 | Integrado y desplegado como `6d521421...` |
| #309 | P-06: nuevos alumnos, bajas y asistencia | Integrado y producción comprobada en `dce535040557d636b01be629f434af1151542b75` |
| #310 | P-06 mezclado: prospectos/conversión/Sharky/dashboard | Abierto; no debe mergearse como unidad |

## Criterio para continuar el cierre

El micro-paso de esquema debe pasar Quality y revisión automática, integrarse a `main`, desplegarse mediante auto-deploy y verificarse. Solo después se define e implementa, en otro PR, el productor mínimo de oportunidades y su lifecycle; conversión y publicación en dashboard permanecen fuera de este incremento.
