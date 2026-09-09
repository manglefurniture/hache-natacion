-- Professors can share the same class. A cancellation row now means that
-- one professor cannot attend a session; the session is cancelled only when
-- no active assigned professor remains available.
-- Keep a non-unique session index first because the session foreign key needs it.

CREATE INDEX IF NOT EXISTS idx_profesor_cancelaciones_sesion
  ON profesor_cancelaciones (sesion_id);
DROP INDEX IF EXISTS uq_profesor_cancelacion_sesion ON profesor_cancelaciones;
CREATE UNIQUE INDEX IF NOT EXISTS uq_profesor_cancelacion_profesor_sesion
  ON profesor_cancelaciones (profesor_id, sesion_id);
