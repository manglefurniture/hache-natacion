# Hache Natación — piloto Production Readiness Nivel C

Este documento adopta de forma controlada el baseline de **Hache Base Production Readiness** como piloto real de proyecto. No copia ni modifica la norma: la referencia de implementación usada para esta adopción es Hache Base `af09a753098234525f10ff10403c0d1bdb4afbe1`.

## Clasificación

**Nivel: C — Crítico.**

La elevación a C no depende de Sharky. El backend conserva y modifica estado financiero y operativo cuyo duplicado, pérdida o corrupción puede causar daño material:

- `database/schema_hache_monteverde_v1.sql` define `pagos` y relaciones con alumnos, mensualidades, inscripciones e intensivos;
- `api/pagos-smart.php` registra pagos y bloquea filas durante decisiones financieras;
- `api/cierres-mensuales.php` materializa cierres de periodo;
- `api/editar-pago.php` modifica efectos financieros ya registrados;
- `config/intensivo-transferencias.php` bloquea transferencias cuando existe un pago válido;
- Sharky añade otra frontera externa durable mediante inbox/outbox y envío por WhatsApp, pero el proyecto seguiría siendo C aunque Sharky estuviera apagado.

Por esa razón una clasificación B sería insuficiente para el proyecto completo.

## CUF-C-01 — Registrar un pago sin duplicar el efecto

- **Dueño técnico:** Hache Interactive.
- **Dueño de negocio:** Hache Natación.
- **Objetivo:** registrar un pago válido para el concepto correcto sin crear dos efectos financieros para la misma obligación.
- **Precondiciones:** sesión ADMIN válida; alumno/sede/concepto existentes; periodo abierto cuando corresponda.
- **Pasos:** validar request → bloquear/leer estado financiero relevante → crear/actualizar obligación → insertar pago → recalcular estado → commit.
- **Resultado observable:** el pago aparece una vez y el estado financiero asociado refleja el importe válido.
- **Resultado autoritativo:** DB (`pagos` + obligación relacionada + cierre de periodo cuando aplique).
- **Mutaciones:** pagos, mensualidades/inscripciones/intensivos y reglas derivadas.
- **Integraciones:** ninguna obligatoria para persistir el pago.
- **Fallos críticos:** doble submit, periodo cerrado, carrera concurrente, rollback parcial, edición de pago ya cerrado.
- **Idempotencia/concurrencia:** constraints/transacción/`FOR UPDATE` y reglas que impiden más de un pago válido donde el concepto lo exige.
- **Pruebas existentes:** regresiones financieras, intensivos, periodos y runtime smoke de la suite del proyecto.
- **Smoke de producción:** lectura autenticada del estado resultante y conciliación sin crear un segundo pago.
- **Recovery:** no repetir una mutación ambigua; reconciliar primero contra DB y auditoría.

## CUF-C-02 — Inscripción / cupo de curso intensivo

- **Dueño técnico:** Hache Interactive.
- **Dueño de negocio:** Hache Natación.
- **Objetivo:** incorporar al alumno al curso/horario correcto sin perder ni duplicar la inscripción ni desalinearla de pagos/reglas.
- **Precondiciones:** curso activo, sede/horario válidos y alumno identificable.
- **Resultado autoritativo:** `curso_intensivo_alumnos` y las entidades financieras relacionadas.
- **Fallos críticos:** inscripción duplicada, traslado con pago incompatible, carrera sobre cupo/curso, mail posterior fallido.
- **Recovery:** la DB manda; notificaciones son efectos secundarios y nunca deben revertir una inscripción ya confirmada.

## CUF-C-03 — Comunicación transaccional Sharky / WhatsApp

- **Dueño técnico:** Hache Interactive.
- **Dueño de negocio:** Hache Natación.
- **Objetivo:** emitir una respuesta automática como máximo una vez por intención durable y conservar un estado observable del envío.
- **Precondiciones:** Sharky habilitado, migración completa, secretos estables y contacto no tomado por humano.
- **Resultado autoritativo local:** `sharky_outbox` (`PENDING`, `SENT`, `DEAD`, `CANCELLED`) y sus attempts/leases.
- **Límite actual:** `SENT` demuestra aceptación de la llamada HTTP a Meta, **no entrega al dispositivo**. Delivery/read de Meta permanece `NOT EVALUATED` hasta persistir los status webhooks correspondientes.
- **Recovery:** reintento fenced del mismo outbox; nunca generar una segunda intención para resolver un resultado ambiguo.

Este CUF hace que el proyecto sea apto para el criterio de piloto que exige una comunicación aplicable, pero el gate no se cierra hasta tener evidencia real del estado requerido.

## Evidencia automatizada del piloto

`bin/production-readiness-evidence.php` es un collector **read-only** pensado para ejecutarse en el host desplegado. Produce únicamente metadatos técnicos/aggregados:

- SHA desplegado cuando puede resolverse de forma local;
- versión PHP;
- presencia de tablas críticas (sin filas ni valores personales);
- presencia de tablas/constraints financieros relevantes;
- agregados de estados de `sharky_outbox` y fecha UTC por día del último `SENT`;
- estado explícito de los gates `field`, `restore` y `communication_delivery`.

No emite nombres, teléfonos, correos, payloads, hashes de contacto, credenciales ni contenido de conversaciones.

`.github/workflows/production-readiness-evidence.yml` permite obtener dos artefactos manuales y auditables:

1. **production snapshot**: ejecuta el collector por el mismo canal SSH del deploy y mide una solicitud HTTPS pública a `https://hnatacion.com/`;
2. **restore lab**: crea dos DB aisladas en MariaDB de CI, importa el schema real del proyecto, inserta un marker sintético, hace dump y restore, y verifica integridad básica. Es evidencia de restaurabilidad del proyecto, **no** sustituye un restore de un backup real de producción.

## Estado de los tres gates del criterio de salida P1

| Gate | Estado al introducir este piloto | Evidencia necesaria para cerrarlo |
| --- | --- | --- |
| Campo | `NOT EVALUATED` | ventana representativa de RUM/Web Vitals o evidencia de campo equivalente aprobada; una medición HTTP aislada no cuenta como p75 de campo |
| Restore | `PARTIAL` | restore lab del schema + posteriormente restore aislado de un backup real con RPO/RTO medidos |
| Communication status | `PARTIAL` | outbox real aporta `PENDING/SENT/DEAD/CANCELLED`; falta delivery status autoritativo del proveedor |

No se convierte ninguno de esos estados en PASS por el mero hecho de mergear este paquete.

## Política de cambios del piloto

- no tocar Standard/Checklist de Hache Base desde este repo;
- no exponer secretos o PII en artefactos de Actions;
- no ejecutar restore sobre la DB de producción;
- no añadir una mutación de negocio para “probar” el sistema;
- cada paso que cruce producción debe ser read-only o usar un target aislado explícito;
- no mezclar esta adopción con cambios funcionales de Sharky/Historias que estén en PRs separados.
