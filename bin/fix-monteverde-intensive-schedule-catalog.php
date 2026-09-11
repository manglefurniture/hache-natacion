<?php

declare(strict_types=1);

function hache_fix_monteverde_intensive_schedule_catalog(PDO $pdo): string
{
    $markerKey='sharky_monteverde_intensive_catalog_fixed_20260910';
    $markerDescription='One-shot data correction: Monteverde intensive schedules are 06:00–07:00, 07:00–08:00 and 08:00–09:00; evening rows remain regular-only.';
    $morning=[
        ['06:00:00','07:00:00'],
        ['07:00:00','08:00:00'],
        ['08:00:00','09:00:00'],
    ];
    $evening=[
        ['18:00:00','19:00:00'],
        ['19:00:00','20:00:00'],
        ['20:00:00','21:00:00'],
    ];

    $pdo->beginTransaction();
    try{
        $marker=$pdo->prepare('SELECT valor FROM configuracion WHERE clave=:clave LIMIT 1 FOR UPDATE');
        $marker->execute([':clave'=>$markerKey]);
        if(trim((string)($marker->fetchColumn()?:''))==='1'){
            $pdo->commit();
            return 'ALREADY_APPLIED';
        }

        $site=$pdo->query("SELECT id FROM sedes WHERE clave='MONTEVERDE' AND activo=1 LIMIT 1 FOR UPDATE")->fetchColumn();
        if(!$site)throw new RuntimeException('MONTEVERDE active site not found');
        $site=(string)$site;

        $find=$pdo->prepare('SELECT id,activo,intensivo FROM horarios WHERE sede_id=:s AND hora_inicio=:i AND hora_fin=:f LIMIT 1 FOR UPDATE');
        $morningIds=[];$eveningIds=[];
        foreach($morning as [$start,$end]){
            $find->execute([':s'=>$site,':i'=>$start,':f'=>$end]);
            $row=$find->fetch();
            if(!$row||(int)$row['activo']!==1){
                throw new RuntimeException('Required Monteverde intensive schedule missing or inactive: '.$start.'-'.$end);
            }
            $morningIds[]=(string)$row['id'];
        }
        foreach($evening as [$start,$end]){
            $find->execute([':s'=>$site,':i'=>$start,':f'=>$end]);
            $row=$find->fetch();
            if($row)$eveningIds[]=(string)$row['id'];
        }

        if($eveningIds!==[]){
            $placeholders=implode(',',array_fill(0,count($eveningIds),'?'));
            $sql="SELECT COUNT(*) FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE ci.sede_id=? AND ci.estado IN ('PROGRAMADO','EN_CURSO') AND cia.horario_id IN ($placeholders)";
            $check=$pdo->prepare($sql);
            $check->execute(array_merge([$site],$eveningIds));
            if((int)$check->fetchColumn()>0){
                throw new RuntimeException('Active Monteverde intensive enrollment still uses an evening schedule; review it before changing the catalog');
            }
        }

        $enable=$pdo->prepare('UPDATE horarios SET intensivo=1 WHERE id=:id');
        foreach($morningIds as $id){$enable->execute([':id'=>$id]);}
        if($eveningIds!==[]){
            $disable=$pdo->prepare('UPDATE horarios SET intensivo=0 WHERE id=:id');
            foreach($eveningIds as $id){$disable->execute([':id'=>$id]);}
        }

        $verify=$pdo->prepare("SELECT TIME_FORMAT(hora_inicio,'%H:%i') inicio,TIME_FORMAT(hora_fin,'%H:%i') fin FROM horarios WHERE sede_id=:s AND activo=1 AND intensivo=1 ORDER BY hora_inicio");
        $verify->execute([':s'=>$site]);
        $actual=array_map(static fn(array $r):string=>$r['inicio'].'–'.$r['fin'],$verify->fetchAll());
        $expected=['06:00–07:00','07:00–08:00','08:00–09:00'];
        if($actual!==$expected){
            throw new RuntimeException('Unexpected Monteverde intensive catalog after correction: '.implode(',',$actual));
        }

        $upsert=$pdo->prepare(
            'INSERT INTO configuracion(clave,valor,descripcion,updated_by,updated_at) '
            .'VALUES(:clave,:valor,:descripcion,NULL,NOW()) '
            .'ON DUPLICATE KEY UPDATE valor=VALUES(valor),descripcion=VALUES(descripcion),updated_by=NULL,updated_at=NOW()'
        );
        $upsert->execute([':clave'=>$markerKey,':valor'=>'1',':descripcion'=>$markerDescription]);

        $pdo->commit();
        return 'APPLIED';
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    try{
        $pdo=require __DIR__.'/../config/pdo.php';
        $result=hache_fix_monteverde_intensive_schedule_catalog($pdo);
        fwrite(STDOUT,"MONTEVERDE_INTENSIVE_SCHEDULE_CATALOG_{$result}\n");
    }catch(Throwable $e){
        fwrite(STDERR,'MONTEVERDE_INTENSIVE_SCHEDULE_CATALOG_FAIL: '.$e->getMessage().PHP_EOL);
        exit(1);
    }
}
