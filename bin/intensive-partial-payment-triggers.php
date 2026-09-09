<?php

declare(strict_types=1);

function hache_intensive_partial_payment_trigger_statements(): array
{
    $insert=<<<'SQL'
CREATE TRIGGER trg_un_pago_valido_insert
BEFORE INSERT ON pagos
FOR EACH ROW
BEGIN
    IF NEW.estado = 'VALIDO' THEN
        IF NEW.inscripcion_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.inscripcion_id = NEW.inscripcion_id AND p.estado = 'VALIDO') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta inscripción';
        END IF;
        IF NEW.mensualidad_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.mensualidad_id = NEW.mensualidad_id AND p.estado = 'VALIDO') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta mensualidad';
        END IF;
        IF NEW.intensivo_id IS NOT NULL AND (
  COALESCE((SELECT SUM(p.importe) FROM pagos p WHERE p.intensivo_id=NEW.intensivo_id AND p.alumno_id=NEW.alumno_id AND p.tipo='INTENSIVO' AND p.estado='VALIDO'),0) + NEW.importe
        ) > COALESCE((SELECT ci.precio FROM cursos_intensivos ci WHERE ci.id=NEW.intensivo_id),0) + 0.009 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El abono supera el saldo pendiente del intensivo';
        END IF;
    END IF;
END
SQL;
    $update=<<<'SQL'
CREATE TRIGGER trg_un_pago_valido_update
BEFORE UPDATE ON pagos
FOR EACH ROW
BEGIN
    IF NEW.estado = 'VALIDO' THEN
        IF NEW.inscripcion_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.inscripcion_id = NEW.inscripcion_id AND p.estado = 'VALIDO' AND p.id <> NEW.id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta inscripción';
        END IF;
        IF NEW.mensualidad_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.mensualidad_id = NEW.mensualidad_id AND p.estado = 'VALIDO' AND p.id <> NEW.id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta mensualidad';
        END IF;
        IF NEW.intensivo_id IS NOT NULL AND (
  COALESCE((SELECT SUM(p.importe) FROM pagos p WHERE p.intensivo_id=NEW.intensivo_id AND p.alumno_id=NEW.alumno_id AND p.tipo='INTENSIVO' AND p.estado='VALIDO' AND p.id <> NEW.id),0) + NEW.importe
        ) > COALESCE((SELECT ci.precio FROM cursos_intensivos ci WHERE ci.id=NEW.intensivo_id),0) + 0.009 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El abono supera el saldo pendiente del intensivo';
        END IF;
    END IF;
END
SQL;
    return ['DROP TRIGGER IF EXISTS trg_un_pago_valido_insert','DROP TRIGGER IF EXISTS trg_un_pago_valido_update',$insert,$update];
}

function hache_intensive_partial_payment_triggers_ready(PDO $pdo): bool
{
    $st=$pdo->prepare("SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('trg_un_pago_valido_insert','trg_un_pago_valido_update')");
    $st->execute();$rows=$st->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach(['trg_un_pago_valido_insert','trg_un_pago_valido_update'] as $name){$body=(string)($rows[$name]??'');if($body===''||!str_contains($body,'El abono supera el saldo pendiente del intensivo')||str_contains($body,'Este alumno ya tiene un pago válido para este intensivo'))return false;}
    return true;
}

function hache_intensive_partial_payment_apply(PDO $pdo): void
{
    if(hache_intensive_partial_payment_triggers_ready($pdo))return;
    foreach(hache_intensive_partial_payment_trigger_statements() as $sql)$pdo->exec($sql);
    if(!hache_intensive_partial_payment_triggers_ready($pdo))throw new RuntimeException('No se pudieron verificar los triggers de abonos intensivos');
}
