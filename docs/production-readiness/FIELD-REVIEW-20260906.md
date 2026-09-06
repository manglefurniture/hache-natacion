# Hache Natación — revisión humana del gate Field

Fecha: 2026-09-06

## Decisión y aprobación

**Field: PASS**

El owner, mediante la instrucción de cierre P1 del 2026-09-06, declaró válida la evidencia recolectada y solicitó formalmente marcar Field como PASS. Este registro documenta esa aprobación humana; la comprobación técnica del artifact no sustituye al owner ni genera un PASS automático.

Se acepta la ventana de uso real proporcionada por el owner para el alcance del piloto: ruta `home`, desktop y mobile. No se repitieron pruebas de campo ni se generó tráfico artificial para este cierre.

## Evidencia revisada

- Build evaluado: `5cc3316b22a9c76134a34deb3fdabc2f8fe74805` (`git-5cc3316b22a9`).
- Run: [33999557068](https://github.com/manglefurniture/hache-natacion/actions/runs/33999557068).
- Artifact: `9995688004`, `production-readiness-field-snapshot-33999557068`.
- Generado: `2026-09-06T19:28:58+00:00`.
- Ventana móvil configurada: 14 días; no implica 14 días completos de actividad del build.
- Método p75: nearest-rank; piso del proyecto: 20 muestras por grupo.
- Snapshot: 634 mediciones; 616 del build evaluado y 18 de builds anteriores. Son mediciones, no usuarios únicos.

| Factor | Métrica | Muestras del build | p75 | Umbral | Resultado |
| --- | --- | ---: | ---: | ---: | --- |
| desktop | CLS | 27 | 0.00255711 | ≤ 0.1 | PASS |
| desktop | INP | 21 | 48 ms | ≤ 200 ms | PASS |
| desktop | LCP | 25 | 740 ms | ≤ 2500 ms | PASS |
| mobile | CLS | 244 | 0 | ≤ 0.1 | PASS |
| mobile | INP | 78 | 80 ms | ≤ 200 ms | PASS |
| mobile | LCP | 221 | 1156 ms | ≤ 2500 ms | PASS |

Los seis grupos cumplen piso y target. No se mezclaron builds: `git-1ec19d5b9d97` aporta 2 mediciones desktop y `git-c2bf0205150d` aporta 16 mobile. La LCP antigua de 23564 ms (una muestra) está fuera de target y queda explícitamente excluida de la decisión sobre el build actual, no oculta mediante agregación. La evidencia actual separada no muestra incumplimiento de los targets.

## Cobertura y límites aceptados

La cobertura de rendimiento de campo es exclusivamente `home` en desktop/mobile. `registration` y `admin_payments` no cuentan como cubiertas; este PASS no afirma medir en campo los CUF de pagos o inscripción ni todos los navegadores. INP corresponde a navegadores con soporte de Event Timing. El tamaño desktop es cercano al piso operativo y no constituye una garantía universal de rendimiento.

La aceptación humana se basa en el uso real y la validez declarados por el owner junto con los agregados revisados. El artifact agregado no identifica usuarios ni permite reconstruir sesiones o certificar por sí solo la representatividad. El collector conserva `NOT EVALUATED` / `HUMAN_REVIEW_REQUIRED`.

## Resultado del piloto

Communication status PASS y Restore PASS conservan sus revisiones del 2026-09-05. Con Field PASS, el piloto real Nivel C completa el criterio de salida P1 dentro del alcance documentado. No se abre trabajo P2.
