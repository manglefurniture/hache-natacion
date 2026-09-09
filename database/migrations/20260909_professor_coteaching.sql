-- Professors can share the same class. A cancellation row now means that
-- one professor cannot attend a session; the session is cancelled only when
-- no active assigned professor remains available.

DROP INDEX IF EXISTS uq_profesor_cancelacion_sesion ON profesor_cancelaciones;
CREATE UNIQUE INDEX IF NOT EXISTS uq_profesor_cancelacion_profesor_sesion
  ON profesor_cancelaciones (profesor_id, sesion_id);
