<?php
declare(strict_types=1);

const HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS='f5_prospect_followup_hours';
const HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES='f5_consecutive_absences';
const HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_UNJUSTIFIED='f5_consecutive_unjustified_absences';
const HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS='f5_intensive_no_continuity_days';
const HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE='f5_intensive_no_continuity_scope';

function hache_internal_alert_defaults(): array
{
    return [
        HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS=>'24',
        HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES=>'3',
        HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_UNJUSTIFIED=>'2',
        HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS=>'',
        HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE=>'',
    ];
}

function hache_internal_alert_descriptions(): array
{
    return [
        HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS=>'Horas desde el último contacto verificable antes de alertar por prospecto sin seguimiento.',
        HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES=>'Cantidad de ausencias consecutivas, justificadas o no, que activa la alerta.',
        HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_UNJUSTIFIED=>'Cantidad de ausencias no justificadas consecutivas que activa la alerta temprana.',
        HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS=>'Días después de finalizar un intensivo para alertar por falta de continuidad. Vacío = regla deshabilitada.',
        HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE=>'Qué estados de continuidad generan la alerta. Vacío = regla deshabilitada.',
    ];
}

function hache_internal_alert_value_valid(string $key,string $value): bool
{
    $value=trim($value);
    return match($key){
        HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS=>ctype_digit($value)&&(int)$value>=1&&(int)$value<=168,
        HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES,
        HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_UNJUSTIFIED=>ctype_digit($value)&&(int)$value>=1&&(int)$value<=30,
        HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS=>$value===''||(ctype_digit($value)&&(int)$value>=0&&(int)$value<=60),
        HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE=>in_array($value,['','SIN_EVALUAR','SIN_EVALUAR_O_NO'],true),
        default=>false,
    };
}

function hache_internal_alert_settings(PDO $pdo): array
{
    $values=hache_internal_alert_defaults();
    try{
        $keys=array_keys($values);$marks=implode(',',array_fill(0,count($keys),'?'));
        $st=$pdo->prepare("SELECT clave,valor FROM configuracion WHERE clave IN ($marks)");$st->execute($keys);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as$row){$key=(string)$row['clave'];$value=trim((string)$row['valor']);if(hache_internal_alert_value_valid($key,$value))$values[$key]=$value;}
    }catch(Throwable $e){}
    return [
        'prospect_followup_hours'=>(int)$values[HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS],
        'consecutive_absences'=>(int)$values[HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES],
        'consecutive_unjustified'=>(int)$values[HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_UNJUSTIFIED],
        'intensive_no_continuity_days'=>$values[HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS]===''?null:(int)$values[HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS],
        'intensive_no_continuity_scope'=>$values[HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE]!==''?$values[HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE]:null,
    ];
}

function hache_internal_alert_config_rows(PDO $pdo): array
{
    $defaults=hache_internal_alert_defaults();$descriptions=hache_internal_alert_descriptions();$current=$defaults;
    try{$keys=array_keys($defaults);$marks=implode(',',array_fill(0,count($keys),'?'));$st=$pdo->prepare("SELECT clave,valor FROM configuracion WHERE clave IN ($marks)");$st->execute($keys);foreach($st->fetchAll(PDO::FETCH_ASSOC) as$row){$key=(string)$row['clave'];$value=trim((string)$row['valor']);if(hache_internal_alert_value_valid($key,$value))$current[$key]=$value;}}catch(Throwable $e){}
    return [
        ['clave'=>HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS,'valor'=>$current[HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS],'descripcion'=>$descriptions[HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS],'etiqueta'=>'Prospecto sin seguimiento','tipo'=>'number','min'=>1,'max'=>168,'unidad'=>'horas'],
        ['clave'=>HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES,'valor'=>$current[HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES],'descripcion'=>$descriptions[HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES],'etiqueta'=>'Ausencias consecutivas','tipo'=>'number','min'=>1,'max'=>30,'unidad'=>'ausencias'],
        ['clave'=>HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_UNJUSTIFIED,'valor'=>$current[HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_UNJUSTIFIED],'descripcion'=>$descriptions[HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_UNJUSTIFIED],'etiqueta'=>'Ausencias injustificadas consecutivas','tipo'=>'number','min'=>1,'max'=>30,'unidad'=>'ausencias'],
        ['clave'=>HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS,'valor'=>$current[HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS],'descripcion'=>$descriptions[HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS],'etiqueta'=>'Intensivo sin continuidad','tipo'=>'number_optional','min'=>0,'max'=>60,'unidad'=>'días después'],
        ['clave'=>HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE,'valor'=>$current[HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE],'descripcion'=>$descriptions[HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE],'etiqueta'=>'Alcance de continuidad','tipo'=>'select','opciones'=>[''=>'Regla deshabilitada','SIN_EVALUAR'=>'Solo sin evaluar','SIN_EVALUAR_O_NO'=>'Sin evaluar + marcado que no continúa']],
    ];
}
