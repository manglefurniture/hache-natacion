# Codex P2 — emergency Brain regression wiring

Codex automático detectó en PR #192 que `tests/sharky-emergency-brain-disable-regression.php` existía pero no se ejecutaba como regresión funcional en Quality; solo quedaba cubierto por `php -l`.

Acción: ejecutar explícitamente el test en `.github/workflows/quality.yml` para que cualquier ruptura futura del kill switch haga fallar CI.
