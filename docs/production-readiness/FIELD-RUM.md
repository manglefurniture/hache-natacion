# Pilot C — Field Web Vitals / RUM

## Objetivo

Abrir el gate **Field** del piloto C con evidencia real de usuarios sin convertir una medición aislada ni un deploy en `PASS`. La implementación adopta el patrón P1-04 de Hache Base: primera parte, same-origin, payload minimizado, p75 reproducible y decisión humana.

El gate continúa **`NOT EVALUATED`** hasta reunir una ventana representativa y revisar cobertura, tamaños de muestra y resultados por factor de forma.

## Primera activación

La primera ruta activada es `home`, la página pública principal de Hache Natación. El build de evidencia es `pilot-c-field-v1` y durante esta ventana inicial el muestreo es 100% de las cargas elegibles para reunir señal con rapidez en un sitio de tráfico moderado.

Las etiquetas `registration` y `admin_payments` están reservadas en el contrato, pero **no cuentan como cubiertas** hasta que su instrumentación sea activada y observada realmente.

## Payload permitido

El navegador puede enviar únicamente:

```json
{
  "schema_version": 1,
  "metric": "LCP",
  "value": 1834.12345678,
  "route_group": "home",
  "build_id": "pilot-c-field-v1",
  "form_factor": "mobile"
}
```

No se envían ni almacenan URL, pathname, query, referrer, IP, User-Agent, cookie, session id, usuario, alumno, cuenta, nombre, email, teléfono, identificador de contacto, fingerprint ni contenido de página/formulario.

El collector rechaza campos desconocidos, cuerpos mayores de 1 KiB, métricas/rutas/builds fuera de allowlist y valores no finitos o fuera de límites defensivos. Los errores no registran el body.

## Transporte

`public/assets/field-rum.js` usa `fetch` hacia `/api/rum-web-vitals.php` con:

- `mode: same-origin`;
- `credentials: omit`;
- `referrerPolicy: no-referrer`;
- `cache: no-store`;
- `redirect: error`;
- `keepalive: true`.

Un fallo de telemetría nunca bloquea ni altera el CUF de la página.

## Métricas

- **LCP:** último candidato buffered, congelado en la primera interacción o al ocultar/salir de la página.
- **CLS:** session windows de hasta 5 s con gaps menores de 1 s, excluyendo shifts con `hadRecentInput`.
- **INP:** solo se emite cuando el navegador soporta Event Timing con `interactionId`; conserva las 10 interacciones más lentas y usa el candidato p98 por `floor(interactionCount / 50)`. En navegadores sin soporte no se inventa un valor INP.

Esta implementación es first-party y no afirma ser el paquete `web-vitals`. Si cambia el algoritmo normativo o se adopta dicho paquete, el build/ventana debe revalidarse.

## Persistencia y retención

La migración `database/migrations/20260905_production_rum.sql` crea `production_rum_samples` con solo métrica, valor, route group, build id, factor de forma y timestamp UTC. `value` conserva ocho decimales para evitar que el almacenamiento cambie artificialmente la evaluación de CLS.

Retención del piloto: máximo **35 días**. Cada ingest intenta purgar un lote de filas más antiguas. Existe además un techo global de 600 escrituras por minuto sin identificar clientes; al alcanzarlo la telemetría se descarta sin introducir IP/fingerprint para rate limiting.

## Ventana y agregación

El collector del piloto agrega una ventana móvil de **14 días** con percentil p75 `nearest-rank`, separada por métrica, route group, build id y `mobile`/`desktop`.

Targets de evidencia:

- LCP ≤ 2500 ms;
- INP ≤ 200 ms;
- CLS ≤ 0.1.

Para este piloto se usa un piso operativo de **20 muestras por grupo**. Es una decisión del proyecto para revisar suficiencia, no una regla universal de Hache Base. Aunque un grupo cumpla target y piso, el collector devuelve `NOT EVALUATED` + `HUMAN_REVIEW_REQUIRED`.

## Criterio para revisión humana del gate Field

Antes de considerar `PASS` deben existir, como mínimo:

1. una ventana que represente uso real y no una ráfaga de pruebas internas;
2. muestras suficientes en los factores de forma realmente relevantes;
3. LCP/CLS y, donde el navegador lo soporte, INP con resultados p75 revisables;
4. explicación explícita de rutas/CUF cubiertos y no cubiertos;
5. ausencia de una regresión conocida escondida por mezclar builds;
6. revisión humana versionada, igual que se hizo con Communication status.

La primera activación en `home` **inicia recolección; no cierra el gate**.