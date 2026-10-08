# Pruebas reutilizables de GPU en Salad

## Objetivo
Cambiar únicamente la clase de GPU de UN Container Group de Hache/prl-tests con prioridad Low y una réplica. El resto de sus ajustes se conserva. La GPU real puede seguir en allocating luego de que Salad haya aplicado la configuración.

## Uso
En GitHub: Actions → Salad - Probar GPU (manual) → Run workflow (rama main).
Completar:
- group: nombre exacto, que comience con prl-low-.
- current_gpu: GPU que esperamos encontrar, con el nombre exacto del catálogo Salad (ej.: RTX 4070 Ti Super (16 GB)).
- target_gpu: GPU destino, nombre exacto (ej.: RTX 4090 (24 GB)). Las versiones Laptop y Desktop son distintas.
- operation: PREVISUALIZAR para consultar sin cambios; APLICAR solo cuando ya revisamos el costo por hora y autorizamos la posible interrupción de minería.
- confirm_group: para APLICAR escribir exactamente el nombre del grupo.

El flujo no se ejecuta por push, PR, timer ni monitor: solo por workflow_dispatch manual. En los PR únicamente corre el test.

## Seguridad
- Organización y proyecto fijados a hache/prl-tests.
- Solo grupos prl-low-*, una réplica, prioridad low, GPU única, pending_change false.
- Preflight comprueba que la GPU real coincide con current_gpu y que target_gpu identifica exactamente una clase en Salad.
- Hace un segundo GET antes del cambio para detectar modificaciones concurrentes.
- PATCH parcial solamente de container.resources.gpu_classes.
- Comprueba configuración final y pending_change false; no crea, destruye ni aumenta réplicas ni modifica billetera, pool, prioridad o imagen.
- La clave API queda exclusivamente como GitHub Actions secret; no se imprime.
- Si la API ya aceptó PATCH pero la confirmación sigue pendiente, la ejecución falla sin repetir el cambio; examinar el estado en Salad antes de reintentar.
- No incluye rollback automático: en allocation inestable, otro PATCH automático podría empeorar el problema.

## Revertir una prueba
Cuando ya no exista pending_change, repetir manualmente con el modelo nuevo como current_gpu y el anterior como target_gpu. La consola del primer run registra ambos nombres.
Comprobar después la instancia y las lecturas de SRBMiner. CONFIGURADA no significa READY ni hashrate real.

## Costos
El flujo no calcula precios en tiempo real ni compra saldo. Confirmar en Salad el costo Low antes de pulsar APLICAR. Una GPU más cara puede elevar el gasto incluso sin minar mientras se asigna.

## Referencia
El procedimiento de una sola vez usado para RTX 4090 está en PR #380; su workflow automático fue retirado para impedir ejecuciones accidentales futuras.
