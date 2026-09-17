# F5 — Alerta interna de saldo de intensivo pendiente

Fecha de implementación: 2026-09-17.

## Alcance

F5 incorpora una señal administrativa para **saldo de intensivo pendiente** sin crear una definición financiera nueva. La autoridad sigue siendo la misma que F2/F1: precio del curso menos pagos `VALIDO` asociados al mismo alumno y curso.

La alerta no registra pagos, no invalida movimientos, no modifica cursos ni alumnos y no altera derecho a clase.

## Fuente compartida

`config/intensive-balance-source.php` concentra la consulta y normalización de saldo. La consumen:

- el Centro de pendientes para `SALDO_INTENSIVO_PENDIENTE`;
- la revalidación de ese pendiente antes de resolverlo;
- el Centro de alertas F5 para su resumen operativo.

Esto evita mantener una segunda fórmula de deuda en F5.

## Criterio

Participa una relación alumno–curso cuando:

- el curso pertenece a la sede consultada;
- el estado del curso es `PROGRAMADO`, `EN_CURSO` o `TERMINADO`;
- la suma de pagos `VALIDO` del mismo alumno y curso es menor al precio del curso.

Pagos inválidos no reducen el saldo. Un curso `CANCELADO` no participa. Un saldo liquidado deja de ser candidato.

No se añade un umbral administrativo adicional: el hecho financiero verificable es que exista saldo positivo según la regla vigente.

## Presentación F5

El Centro de alertas presenta un resumen por sede con:

- cantidad de alumnos/relaciones con saldo pendiente;
- suma de los saldos verificables;
- enlace al Centro de pendientes.

La prioridad es `NEUTRA`, porque F5 no tiene aprobada una prioridad nueva para esta regla. El detalle aclara que solo se consideran pagos `VALIDO` del mismo alumno y curso.

## Compatibilidad

El Centro de pendientes conserva tipo, identidad, explicación, enlace y flujo de atención/resolución existentes. La extracción de la fuente financiera a un helper compartido no cambia su contrato; únicamente elimina el cálculo duplicable.

No se requiere migración ni configuración nueva.
