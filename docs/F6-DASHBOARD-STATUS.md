# F6 — Estado del Dashboard operativo

Fecha de actualización: 2026-09-17.

## Estado

F6 — Dashboard operativo permanece **En implementación** mientras el último incremento de P-06 completa revisión, integración y despliegue.

La base de producción comprobada antes de este incremento es `dce535040557d636b01be629f434af1151542b75`. Esa versión ya contiene PR #309 con las definiciones aprobadas de nuevos alumnos, bajas y asistencia. El incremento actual añade prospectos y conversión sin modificar el funnel de Sharky ni reconstruir historia sin evidencia.

## Decisiones P-06 resueltas

- **Nuevos alumnos:** alumno único cuya `fecha_inicio` cae dentro del periodo financiero visible. Una reactivación no crea un alta nueva porque no modifica `fecha_inicio`.
- **Bajas:** solo eventos `ALUMNO_BAJA` registrados con cobertura fiable hacia delante. No se usa `updated_at` como fecha inferida.
- **Asistencia porcentual:** solo sesiones `REALIZADA` no canceladas con snapshot persistido completo y un registro por cada alumno esperado. Sesiones incompletas quedan fuera del denominador.
- **Prospecto:** una oportunidad/persona destinataria de las clases, no un número de WhatsApp. La implementación persiste una identidad técnica de oportunidad sin copiar nombre ni teléfono al ledger analítico. Los casos sin sede confirmada permanecen explícitos como `SIN_SEDE`.
- **Conversión:** cohorte de oportunidades creadas en el periodo que posteriormente alcanzan una inscripción completada por Sharky. Abrir un Flow, iniciar pago o enviar información no convierte. Una conversión posterior puede elevar la tasa de una cohorte histórica.

No se hace backfill de prospectos anteriores al inicio de cobertura porque el estado conversacional histórico no permite reconstruir de forma fiable la persona/oportunidad destinataria.

## Cobertura vigente

- **Contexto operativo:** sede autorizada, fecha `America/Cancun`, periodo financiero vigente y hora de actualización.
- **Alumnos activos:** definición histórica del dashboard: mensualidad `PAGADA` vigente o intensivo vigente con algún pago `VALIDO`, excluyendo `BAJA`; no equivale a derecho de acceso.
- **Situación financiera:** F2 conserva autoridad para facturación, obligaciones y saldos.
- **Mensualidades pagadas:** cantidad, total y detalle salen de la misma lectura pura.
- **Centro de pendientes y alertas:** F6 consume F1/F5 sin otra cola ni recalcular reglas.
- **Operación del día:** lectura pura de sesiones y marcas; canceladas excluidas de asistencia.
- **Intensivos y avisos:** contratos existentes, sin reconciliar ni escribir estados al consultar.
- **Nuevos alumnos, bajas y asistencia de periodo:** implementados en PR #309 con cobertura explícita.
- **Prospectos y conversión:** el incremento actual usa un ledger mínimo `sharky_prospect_opportunities` con `contact_hash`, fuente, sede, estado y vínculo al `alumno_id` al convertir. No guarda PII del prospecto. La métrica es global y visible solo para ADMIN, igual que los prospectos globales de F4/F5.

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
| P-06 prospectos/conversión | `sharky_prospect_opportunities` + inscripción Sharky `COMPLETED` |
| Fecha/hora | `config/dashboard-tiempo.php`, `America/Cancun` |

El ledger de oportunidades no sustituye el CRM ni el estado conversacional y no decide producto, sede, plan o inscripción. Solo conserva la unidad analítica aprobada y su conversión verificable.

## Evidencia acumulada

| PR | Incremento | Resultado |
| --- | --- | --- |
| #300–#305 | Fuentes F1/F2/F5, operación, alumnos, intensivos y tiempo | Integrados y desplegados |
| #307 | Cierre de hallazgos técnicos de F6 | Integrado y desplegado como `6d521421...` |
| #309 | P-06: nuevos alumnos, bajas y asistencia | Integrado; producción comprobada en `dce535040557d636b01be629f434af1151542b75` |
| #310 | P-06: oportunidad/persona y conversión por cohorte | En revisión antes de merge/deploy |

La producción fue comprobada sobre `dce535040557d636b01be629f434af1151542b75`: el marcador desplegado coincide y el health público responde `ok: true`.

## Criterio para cerrar F6

El código del último incremento debe pasar Quality y revisión automática, integrarse a `main`, desplegarse mediante auto-deploy y comprobarse en producción. Si todavía no existen oportunidades reales posteriores al inicio de cobertura, no se fabricarán datos para forzar la validación; se verificará esquema, permisos, lectura segura y comportamiento disponible, dejando la evidencia de datos reales como seguimiento operativo.
