# Sharky — bandeja de aprendizaje

## Propósito

Cerrar el circuito de aprendizaje controlado a partir de conversaciones reales sin permitir auto-modificación de producción.

Flujo:

**conversación real → detector horario → caso de aprendizaje → revisión de ChatGPT → veredicto → regresión/guía si aplica → PR → Quality → deploy**.

## Casos

La bandeja recibe:

- `FINDING`: hallazgos `REVIEW`/`PROBLEM` detectados por las reglas horarias;
- `GOOD_SAMPLE`: una muestra limpia por ventana para identificar patrones positivos que conviene proteger.

No se persiste una segunda copia del texto de WhatsApp. El contexto se descifra únicamente al exportar un caso pendiente para revisión y se mantiene fuera de la tabla de aprendizaje.

## Veredictos

ChatGPT puede emitir únicamente:

- `CORRECTO`: Sharky actuó correctamente;
- `MEJORABLE`: no existe incumplimiento duro, pero hay una mejora conversacional clara;
- `ERROR_REAL`: incumplimiento de una regla, pérdida de contexto o comportamiento objetivamente incorrecto;
- `FALSO_POSITIVO`: la señal automática no representa un problema al leer el contexto;
- `GOOD_PATTERN`: recorrido útil que conviene proteger como patrón positivo.

Cada veredicto incluye prioridad, área de regla, explicación, comportamiento esperado, recomendación y si requiere regresión.

## Autoridad

El revisor no modifica reglas, prompts ni código desde la bandeja. Un caso marcado como aprendizaje aprobado solo puede avanzar mediante las reglas de `AGENTS.md`, `SHARKY-CORE-RULES.md`, la guía lingüística y los patrones positivos.

Cuando `regression_required=true`, el finding asociado pasa a `REGRESSION_CANDIDATE`. Esto sigue siendo una señal de trabajo pendiente, no una modificación automática.

## Operación restringida

El wrapper autorizado expone tres comandos sin conceder sudo general:

```bash
sudo /usr/local/sbin/deploy-hache-natacion sharky-learning-pending
sudo /usr/local/sbin/deploy-hache-natacion sharky-learning-summary
printf '%s' '<json-verdict>' | sudo /usr/local/sbin/deploy-hache-natacion sharky-learning-verdict
```

La UI administrativa está en `/sharky-learning.php`. Solo muestra metadatos/veredictos; no muestra ni guarda el texto de WhatsApp.

## Privacidad

- no guardar teléfonos, nombres, correos, capturas ni texto crudo en la tabla de aprendizaje;
- los casos que lleguen a documentación o fixtures deben estar anonimizados;
- el texto exportado para revisión es transitorio y no debe copiarse a PRs ni logs permanentes.
