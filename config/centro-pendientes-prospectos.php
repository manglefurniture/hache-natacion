<?php
declare(strict_types=1);

require_once __DIR__.'/centro-pendientes.php';
require_once __DIR__.'/prospect-followup-alert.php';

const CENTRO_PENDIENTES_PROSPECTO_TIPO='PROSPECTO_SIN_SEGUIMIENTO';

function centro_pendientes_prospectos_sedes(PDO $pdo): array
{
    $out=[];
    try{
        $st=$pdo->query("SELECT id,clave,nombre FROM sedes WHERE activo=1 AND clave IN ('MONTEVERDE','PALAPAS')");
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as$row){
            $out[(string)$row['clave']]=['id'=>(string)$row['id'],'nombre'=>(string)$row['nombre']];
        }
    }catch(Throwable $e){
        error_log('[pendientes] No se pudieron resolver sedes de prospectos: '.$e->getMessage());
    }
    return $out;
}

function centro_pendientes_prospectos_desde_candidatos(array $candidates,array $sedes): array
{
    $pendientes=[];
    foreach($candidates as$candidate){
        $hash=(string)$candidate['contact_hash'];
        $sedeClave=(string)($candidate['sede']??'');
        $sedeNombre=$sedeClave!==''&&isset($sedes[$sedeClave])?(string)$sedes[$sedeClave]['nombre']:'Sin sede confirmada';
        $horas=(int)$candidate['horas_sin_seguimiento'];
        $umbral=(int)$candidate['umbral_horas'];
        centro_pendientes_agregar($pendientes,[
            'tipo'=>CENTRO_PENDIENTES_PROSPECTO_TIPO,
            'origen_tipo'=>'SHARKY_PROSPECT',
            'origen_id'=>$hash,
            'alumno_id'=>null,
            'alumno_nombre'=>null,
            // La gestión es global: no se asigna una sede ficticia al prospecto.
            'sede_id'=>null,
            'sede_nombre'=>$sedeNombre,
            'periodo_inicio'=>null,
            'periodo_fin'=>null,
            'fecha_referencia'=>(string)$candidate['ultimo_contacto'],
            'explicacion'=>$horas.' h sin seguimiento interno verificable; umbral configurado: '.$umbral.' h.',
            'href'=>'/prospectos.php',
            'causa_activa'=>true,
        ]);
    }
    return centro_pendientes_indizar($pendientes);
}

function centro_pendientes_prospectos_fuentes_activas(PDO $pdo): array
{
    $settings=hache_internal_alert_settings($pdo);
    return centro_pendientes_prospectos_desde_candidatos(
        hache_internal_prospect_followup_candidates($pdo,null,$settings),
        centro_pendientes_prospectos_sedes($pdo),
    );
}

function centro_pendientes_prospectos_historico(PDO $pdo): array
{
    $st=$pdo->prepare("SELECT pg.*,NULL alumno_nombre,'CRM global' sede_nombre
        FROM pendientes_gestion pg
        WHERE pg.sede_id IS NULL AND pg.tipo=:tipo
        ORDER BY pg.updated_at DESC,pg.created_at DESC");
    $st->execute([':tipo'=>CENTRO_PENDIENTES_PROSPECTO_TIPO]);
    return centro_pendientes_indizar($st->fetchAll(PDO::FETCH_ASSOC));
}

function centro_pendientes_prospectos_causa_activa(PDO $pdo,array $pendiente): bool
{
    if((string)($pendiente['tipo']??'')!==CENTRO_PENDIENTES_PROSPECTO_TIPO)return false;
    $hash=trim((string)($pendiente['origen_id']??''));
    if(!preg_match('/^[a-f0-9]{64}$/',$hash))return false;
    foreach(hache_internal_prospect_followup_candidates($pdo) as$candidate){
        if(hash_equals((string)$candidate['contact_hash'],$hash))return true;
    }
    return false;
}

function centro_pendientes_prospectos_descripcion_tipo(string $tipo): ?string
{
    return $tipo===CENTRO_PENDIENTES_PROSPECTO_TIPO?'Prospecto sin seguimiento':null;
}

function centro_pendientes_prospectos_href_historico(string $tipo): ?string
{
    return $tipo===CENTRO_PENDIENTES_PROSPECTO_TIPO?'/prospectos.php':null;
}
