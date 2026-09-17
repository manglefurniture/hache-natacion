# F6 — Estado del Dashboard operativo

Fecha de actualización: 2026-09-17.

## Estado

F6 — Dashboard operativo queda **En implementación** según la convención del roadmap.

Los incrementos técnicamente definibles sin nuevas decisiones de negocio están implementados, revisados, desplegados y comprobados en producción. La fase completa no se marca **Implementada**, **Desplegada** ni **Verificada** porque siguen abiertos indicadores cuyo significado debe decidirse antes de publicarlos. No se asignan valores provisionales ni se fabrican datos para cerrar esos huecos.

Base funcional comprobada: `6d521421a8508c6a63de01ffbb4813aecd2bf28f`.

## Cobertura desplegada

- **Contexto operativo:** sede autorizada, fecha `America/Cancun`, periodo financiero vigente y hora de actualización visible también forzada a `America/Cancun` en el navegador.
- **Alumnos activos:** conserva la definición histórica del dashboard: alumno único con mensualidad `PAGADA` vigente o intensivo vigente con algún pago `VALIDO`, excluyendo `BAJA`. Total y detalle usan la misma lectura pura. No equivale a derecho de acceso.
- **Situación financiera:** facturación del periodo usa `financiero_totales()`; obligaciones y saldo usan la autoridad compartida de F2. El detalle de Finanzas internas conserva el mismo periodo del dashboard.
- **Mensualidades pagadas:** conserva el agregado histórico —registros `PAGADA` cuya vigencia contiene la fecha operativa— y ahora cantidad, total monetario y detalle salen de la misma lectura pura.
- **Centro de pendientes y alertas:** F6 consume las causas activas de F5 y el estado de gestión de F1 sin mantener otra cola ni recalcular las reglas. La cobertura regular recibe el mismo instante operativo de Cancún que el resto del dashboard. Los prospectos globales solo se incorporan para ADMIN.
- **Operación del día:** sesiones ya registradas y marcas de asistencia se consultan con una lectura pura. El desglose visible incluye `PROGRAMADA`, `REALIZADA`, `CANCELADA` y cerrada; las marcas de sesiones `CANCELADA` se excluyen de asistencia. F6 no usa el GET de `api/sesiones.php` que puede generar sesiones para ADMIN.
- **Datos incompletos:** ausencia de sesiones registradas produce asistencia desconocida; un día con solo sesiones canceladas se distingue y no contabiliza sus marcas. No se calcula porcentaje sin denominador aprobado.
- **Intensivos activos:** conserva la definición histórica por estado almacenado `PROGRAMADO` o `EN_CURSO`; total y detalle salen de la misma consulta sin reconciliar ni escribir estados.
- **Avisos de ausencia activos:** conserva el conteo histórico de avisos `ACTIVO` cuyo rango contiene la fecha operativa; el total y el detalle salen de la misma lectura.
- **Sede:** enlaces de detalle y acciones del dashboard propagan la sede autoritativa de la sesión, respetando ADMIN/VERIFICADOR.

## Autoridades reutilizadas

| Bloque | Autoridad |
| --- | --- |
| Pendientes | F1 / `pendientes_gestion` y fuentes compuestas del Centro de pendientes |
| Finanzas | F2 / periodos financieros, `financiero_totales()` y obligaciones compartidas |
| Alertas | F5 / mismas causas activas consumidas por F1 |
| Alumnos activos | contrato histórico del dashboard extraído a `config/dashboard-alumnos.php` |
| Mensualidades pagadas y avisos | contratos históricos del dashboard extraídos a `config/dashboard-indicadores.php` |
| Sesiones y asistencia | `sesiones` + `asistencias`, mediante `config/dashboard-operacion.php` |
| Intensivos activos | `cursos_intensivos.estado`, mediante `config/dashboard-intensivos.php` |
| Fecha/hora | `config/dashboard-tiempo.php`, zona `America/Cancun` |

F6 no escribe en ninguna de estas autoridades.

## Evidencia de integración y despliegue

| PR | Incremento | Resultado integrado |
| --- | --- | --- |
| #300 | F1/F5: Centro de pendientes y alertas activas | `7cc849fa16c3b621403c632f93073b6dda9687f7` |
| #301 | F2: obligaciones y saldos compartidos | `ea281c5f442d7d7b5855d5dc8bdd348919fe2776` |
| #302 | sesiones/asistencia en lectura pura | `0b567f0282f6f8cd2a6ef29b312081cf1aae52dd` |
| #303 | alumnos activos reconciliables y sede | `fe3470861723cacefb13c1b03612efbb23b71e49` |
| #304 | intensivos activos reconciliables | `b5fbc876268243c4e27b236d70197acded0f2841` |
| #305 | sede, fecha y hora de actualización visibles | `f87f14d20d6d196a55716deb289859acf915cf53` |
| #307 | cierre de hallazgos técnicos: fecha operativa F1/F5, cancelaciones/programadas, zona horaria y detalle de mensualidades/avisos | `6d521421a8508c6a63de01ffbb4813aecd2bf28f` |

Quality del PR #307 falló inicialmente por una expectativa de fixture detectada también por la revisión automática; el fixture se corrigió sin cambiar el contrato funcional. Quality #1557 pasó en el PR y Quality #1558 pasó después del merge sobre `main`. Deploy automático #273 terminó correctamente. La comprobación de producción confirmó el marcador exacto `6d521421a8508c6a63de01ffbb4813aecd2bf28f`, sintaxis PHP correcta de `api/dashboard.php`, `config/dashboard-indicadores.php`, `config/dashboard-operacion.php` y `public/dashboard.php`, y `https://hnatacion.com/api/health.php` respondió `ok: true`.

Los hilos P2 pendientes de los PR #300, #302, #305 y #306 quedaron resueltos después de integrar #307. El hilo P1 de #307 correspondía al fixture ya corregido y también quedó resuelto.

## Huecos que requieren decisión antes de continuar

1. **Nuevos alumnos:** decidir si el indicador mide alta en sistema (`created_at`), inicio de clases (`fecha_inicio`) u otra definición. Debe definirse también el tratamiento de reactivaciones.
2. **Bajas:** no existe evidencia uniforme para fechar todas las bajas históricas. No se usará `updated_at` como fecha de baja por inferencia.
3. **Asistencia porcentual/cobertura:** falta aprobar el denominador y el tratamiento de sesiones sin marcas. Mientras tanto se muestran hechos registrados y estados separados.
4. **Prospectos:** F4 ya aporta hechos verificables, pero F6 necesita definir la unidad del indicador —contacto único, destinatario de clases u oportunidad— y el tratamiento de prospectos sin sede confirmada.
5. **Conversiones:** falta definir cohorte/periodo y denominador. Una inscripción iniciada, un alta `COMPLETED` y un pago no se tratarán como métricas equivalentes.

Estos puntos corresponden a P-06. Son decisiones de producto/operación, no correcciones técnicas evidentes, por lo que F6 se detiene en este límite sin avanzar a F7, F8 ni F9.
