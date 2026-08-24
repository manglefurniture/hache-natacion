-- Hache Natación — periodos reales de mensualidad + reglas financieras por sede
-- Monteverde / Palapas P1: mes natural.
-- Palapas P15: del día 15 del mes ancla al día 14 del mes siguiente.
-- Reparto vigente:
--   Monteverde / PROA: mensualidad 50%, intensivo 50%, inscripción 100%.
--   Palapas / PROTUDEC: mensualidad 60%, intensivo 50%, inscripción 100%.

ALTER TABLE mensualidades
  ADD COLUMN IF NOT EXISTS periodo_inicio DATE NULL AFTER anio,
  ADD COLUMN IF NOT EXISTS periodo_fin DATE NULL AFTER periodo_inicio;

-- Asegura que la configuración de sedes refleje las reglas vigentes.
UPDATE sedes
SET socio='PROA Nadadores',
    porcentaje_mensualidad_socio=50.00,
    porcentaje_intensivo_socio=50.00,
    porcentaje_inscripcion_socio=100.00,
    minimo_mensual_socio=28000.00
WHERE clave='MONTEVERDE';

UPDATE sedes
SET socio='PROTUDEC',
    porcentaje_mensualidad_socio=60.00,
    porcentaje_intensivo_socio=50.00,
    porcentaje_inscripcion_socio=100.00,
    minimo_mensual_socio=NULL
WHERE clave='PALAPAS';

-- Backfill de mensualidades existentes según sede/ciclo del alumno.
UPDATE mensualidades m
INNER JOIN alumnos a ON a.id=m.alumno_id
INNER JOIN sedes s ON s.id=m.sede_id
SET m.periodo_inicio = CASE
      WHEN s.clave='PALAPAS' AND a.ciclo_pago='P15'
        THEN STR_TO_DATE(CONCAT(m.anio,'-',LPAD(m.mes,2,'0'),'-15'),'%Y-%m-%d')
      ELSE STR_TO_DATE(CONCAT(m.anio,'-',LPAD(m.mes,2,'0'),'-01'),'%Y-%m-%d')
    END,
    m.periodo_fin = CASE
      WHEN s.clave='PALAPAS' AND a.ciclo_pago='P15'
        THEN DATE_SUB(DATE_ADD(STR_TO_DATE(CONCAT(m.anio,'-',LPAD(m.mes,2,'0'),'-15'),'%Y-%m-%d'),INTERVAL 1 MONTH),INTERVAL 1 DAY)
      ELSE LAST_DAY(STR_TO_DATE(CONCAT(m.anio,'-',LPAD(m.mes,2,'0'),'-01'),'%Y-%m-%d'))
    END
WHERE m.periodo_inicio IS NULL OR m.periodo_fin IS NULL;

CREATE INDEX IF NOT EXISTS idx_mensualidades_periodo_real
  ON mensualidades(sede_id,periodo_inicio,periodo_fin);

-- Los triggers mantienen el periodo real aunque el alta venga de un flujo antiguo.
DROP TRIGGER IF EXISTS trg_mensualidades_periodo_insert;
DROP TRIGGER IF EXISTS trg_mensualidades_periodo_update;
DELIMITER $$
CREATE TRIGGER trg_mensualidades_periodo_insert BEFORE INSERT ON mensualidades FOR EACH ROW
BEGIN
  DECLARE v_clave VARCHAR(30);
  DECLARE v_ciclo VARCHAR(3);
  IF NEW.periodo_inicio IS NULL OR NEW.periodo_fin IS NULL THEN
    SELECT s.clave,a.ciclo_pago INTO v_clave,v_ciclo
    FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id
    WHERE a.id=NEW.alumno_id LIMIT 1;

    IF v_clave='PALAPAS' AND v_ciclo='P15' THEN
      SET NEW.periodo_inicio=STR_TO_DATE(CONCAT(NEW.anio,'-',LPAD(NEW.mes,2,'0'),'-15'),'%Y-%m-%d');
      SET NEW.periodo_fin=DATE_SUB(DATE_ADD(NEW.periodo_inicio,INTERVAL 1 MONTH),INTERVAL 1 DAY);
    ELSE
      SET NEW.periodo_inicio=STR_TO_DATE(CONCAT(NEW.anio,'-',LPAD(NEW.mes,2,'0'),'-01'),'%Y-%m-%d');
      SET NEW.periodo_fin=LAST_DAY(NEW.periodo_inicio);
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_mensualidades_periodo_update BEFORE UPDATE ON mensualidades FOR EACH ROW
BEGIN
  DECLARE v_clave VARCHAR(30);
  DECLARE v_ciclo VARCHAR(3);
  IF NEW.periodo_inicio IS NULL OR NEW.periodo_fin IS NULL THEN
    SELECT s.clave,a.ciclo_pago INTO v_clave,v_ciclo
    FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id
    WHERE a.id=NEW.alumno_id LIMIT 1;

    IF v_clave='PALAPAS' AND v_ciclo='P15' THEN
      SET NEW.periodo_inicio=STR_TO_DATE(CONCAT(NEW.anio,'-',LPAD(NEW.mes,2,'0'),'-15'),'%Y-%m-%d');
      SET NEW.periodo_fin=DATE_SUB(DATE_ADD(NEW.periodo_inicio,INTERVAL 1 MONTH),INTERVAL 1 DAY);
    ELSE
      SET NEW.periodo_inicio=STR_TO_DATE(CONCAT(NEW.anio,'-',LPAD(NEW.mes,2,'0'),'-01'),'%Y-%m-%d');
      SET NEW.periodo_fin=LAST_DAY(NEW.periodo_inicio);
    END IF;
  END IF;
END$$
DELIMITER ;
