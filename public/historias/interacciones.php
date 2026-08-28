<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');

$config=require __DIR__.'/../../config/database.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

const HISTORIAS_PUBLICAS=['maria-del-carmen'];
const REACCIONES_PUBLICAS=['CORAZON','APLAUSOS','INSPIRA','FUERZA','SONRISA'];

function salida(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function texto_limpio(mixed $value,int $max): string
{
    $value=strip_tags((string)$value);
    $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value)??'';
    $value=preg_replace('/[ \t]+/u',' ',$value)??'';
    $value=preg_replace('/\R{3,}/u',"\n\n",$value)??'';
    $value=trim($value);
    return mb_substr($value,0,$max);
}

function historia_valida(mixed $value): string
{
    $slug=strtolower(trim((string)$value));
    if(!in_array($slug,HISTORIAS_PUBLICAS,true))salida(['ok'=>false,'error'=>'Historia no disponible'],404);
    return $slug;
}

function visitante_valido(mixed $value): ?string
{
    $id=trim((string)$value);
    if($id==='')return null;
    if(strlen($id)<16||strlen($id)>128||!preg_match('/^[a-zA-Z0-9._:-]+$/',$id))return null;
    return $id;
}

function secreto_interacciones(array $config): string
{
    $env=trim((string)(getenv('HACHE_PUBLIC_INTERACTION_SALT')?:''));
    if($env!=='')return $env;
    $dbPass=(string)($config['password']??'');
    return $dbPass!==''?$dbPass:'hache-public-interactions-v1';
}

function hash_privado(string $value,string $secret): string
{
    return hash_hmac('sha256',$value,$secret);
}

function origen_ip(): string
{
    return trim((string)($_SERVER['REMOTE_ADDR']??'unknown')) ?: 'unknown';
}

function mismo_origen(): bool
{
    $host=strtolower(explode(':',trim((string)($_SERVER['HTTP_HOST']??'')),2)[0]);
    if($host==='')return false;
    foreach(['HTTP_ORIGIN','HTTP_REFERER'] as $key){
        $raw=trim((string)($_SERVER[$key]??''));
        if($raw==='')continue;
        $source=strtolower((string)(parse_url($raw,PHP_URL_HOST)??''));
        if($source!==''&&$source!==$host)return false;
    }
    return true;
}

function origen_bloqueado(PDO $pdo,string $hash): bool
{
    $st=$pdo->prepare('SELECT 1 FROM historia_bloqueos WHERE origen_hash=:hash AND activo=1 LIMIT 1');
    $st->execute([':hash'=>$hash]);
    return (bool)$st->fetchColumn();
}

function nombre_publico(string $nombre): string
{
    $partes=preg_split('/\s+/u',trim($nombre))?:[];
    return mb_substr((string)($partes[0]??'Visitante'),0,30);
}

function flags_comentario(string $comentario): array
{
    $flags=[];
    preg_match_all('/(?:https?:\/\/|www\.|\b[a-z0-9-]+\.(?:com|net|org|mx|io)\b)/iu',$comentario,$links);
    $linkCount=count($links[0]??[]);
    if($linkCount>0)$flags[]='contiene_enlace';
    if(preg_match('/(.)\1{9,}/u',$comentario))$flags[]='repeticion';
    $letters=preg_replace('/[^\p{L}]/u','',$comentario)??'';
    if(mb_strlen($letters)>=20){
        $upper=preg_replace('/[^\p{Lu}]/u','',$letters)??'';
        if(mb_strlen($upper)/max(1,mb_strlen($letters))>.7)$flags[]='mayusculas';
    }
    if(preg_match('/\b(?:casino|viagra|cripto|crypto|apuesta|apuestas|spam)\b/iu',$comentario))$flags[]='spam_probable';
    if(preg_match('/\b(?:pendej[oa]s?|idiotas?|mierda|put[oa]s?)\b/iu',$comentario))$flags[]='lenguaje_revisar';
    return $flags;
}

try{
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $secret=secreto_interacciones($config);
    $originHash=hash_privado(origen_ip(),$secret);

    if($method==='GET'){
        $historia=historia_valida($_GET['historia']??'');
        $visitante=visitante_valido($_GET['visitante']??null);
        $counts=array_fill_keys(REACCIONES_PUBLICAS,0);
        $st=$pdo->prepare('SELECT tipo,COUNT(*) total FROM historia_reacciones WHERE historia_slug=:historia GROUP BY tipo');
        $st->execute([':historia'=>$historia]);
        foreach($st->fetchAll() as $row){if(isset($counts[$row['tipo']]))$counts[$row['tipo']]=(int)$row['total'];}
        $mine=null;
        if($visitante!==null){
            $visitorHash=hash_privado($visitante,$secret);
            $st=$pdo->prepare('SELECT tipo FROM historia_reacciones WHERE historia_slug=:historia AND visitante_hash=:visitante LIMIT 1');
            $st->execute([':historia'=>$historia,':visitante'=>$visitorHash]);
            $mine=$st->fetchColumn()?:null;
        }
        $st=$pdo->prepare("SELECT id,autor_nombre,comentario,created_at FROM historia_comentarios WHERE historia_slug=:historia AND estado='APROBADO' ORDER BY created_at DESC LIMIT 50");
        $st->execute([':historia'=>$historia]);
        $comments=[];
        foreach($st->fetchAll() as $row){
            $comments[]=['id'=>$row['id'],'autor'=>nombre_publico((string)$row['autor_nombre']),'comentario'=>$row['comentario'],'fecha'=>$row['created_at']];
        }
        salida(['ok'=>true,'reacciones'=>$counts,'mi_reaccion'=>$mine,'comentarios'=>$comments]);
    }

    if($method!=='POST')salida(['ok'=>false,'error'=>'Método no permitido'],405);
    if(!mismo_origen())salida(['ok'=>false,'error'=>'Origen no permitido'],403);
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))salida(['ok'=>false,'error'=>'Solicitud inválida'],400);
    $historia=historia_valida($input['historia']??'');
    $accion=strtoupper(trim((string)($input['accion']??'')));
    $visitante=visitante_valido($input['visitante']??null);
    if(origen_bloqueado($pdo,$originHash))salida(['ok'=>false,'error'=>'No se puede publicar desde este origen'],403);

    if($accion==='REACCION'){
        if($visitante===null)salida(['ok'=>false,'error'=>'Visitante inválido'],422);
        $tipo=strtoupper(trim((string)($input['tipo']??'')));
        if(!in_array($tipo,REACCIONES_PUBLICAS,true))salida(['ok'=>false,'error'=>'Reacción inválida'],422);
        $st=$pdo->prepare('SELECT COUNT(*) FROM historia_reacciones WHERE origen_hash=:origen AND updated_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)');
        $st->execute([':origen'=>$originHash]);
        if((int)$st->fetchColumn()>=25)salida(['ok'=>false,'error'=>'Demasiadas reacciones. Intenta más tarde.'],429);
        $visitorHash=hash_privado($visitante,$secret);
        $st=$pdo->prepare('SELECT id,tipo FROM historia_reacciones WHERE historia_slug=:historia AND visitante_hash=:visitante LIMIT 1');
        $st->execute([':historia'=>$historia,':visitante'=>$visitorHash]);
        $existing=$st->fetch();
        if($existing&&$existing['tipo']===$tipo){
            $del=$pdo->prepare('DELETE FROM historia_reacciones WHERE id=:id');
            $del->execute([':id'=>$existing['id']]);
            salida(['ok'=>true,'mi_reaccion'=>null]);
        }
        if($existing){
            $up=$pdo->prepare('UPDATE historia_reacciones SET tipo=:tipo,origen_hash=:origen,updated_at=NOW() WHERE id=:id');
            $up->execute([':tipo'=>$tipo,':origen'=>$originHash,':id'=>$existing['id']]);
        }else{
            $ins=$pdo->prepare('INSERT INTO historia_reacciones(historia_slug,tipo,visitante_hash,origen_hash) VALUES(:historia,:tipo,:visitante,:origen)');
            $ins->execute([':historia'=>$historia,':tipo'=>$tipo,':visitante'=>$visitorHash,':origen'=>$originHash]);
        }
        salida(['ok'=>true,'mi_reaccion'=>$tipo]);
    }

    if($accion==='COMENTARIO'){
        if(texto_limpio($input['website']??'',200)!=='')salida(['ok'=>true,'pendiente'=>true]);
        $nombre=texto_limpio($input['nombre']??'',80);
        $comentario=texto_limpio($input['comentario']??'',700);
        if(mb_strlen($nombre)<2||mb_strlen($nombre)>80||preg_match('/https?:\/\/|www\./iu',$nombre))salida(['ok'=>false,'error'=>'Escribe un nombre válido'],422);
        if(mb_strlen($comentario)<3||mb_strlen($comentario)>700)salida(['ok'=>false,'error'=>'El comentario debe tener entre 3 y 700 caracteres'],422);
        preg_match_all('/(?:https?:\/\/|www\.|\b[a-z0-9-]+\.(?:com|net|org|mx|io)\b)/iu',$comentario,$links);
        if(count($links[0]??[])>1)salida(['ok'=>false,'error'=>'El comentario contiene demasiados enlaces'],422);
        $st=$pdo->prepare('SELECT COUNT(*),MAX(created_at) ultima FROM historia_comentarios WHERE origen_hash=:origen AND created_at>=DATE_SUB(NOW(),INTERVAL 30 MINUTE)');
        $st->execute([':origen'=>$originHash]);
        $rate=$st->fetch();
        if((int)($rate['COUNT(*)']??0)>=3)salida(['ok'=>false,'error'=>'Has enviado varios comentarios. Intenta más tarde.'],429);
        if(!empty($rate['ultima'])&&strtotime((string)$rate['ultima'])>time()-45)salida(['ok'=>false,'error'=>'Espera un momento antes de enviar otro comentario.'],429);
        $st=$pdo->prepare("SELECT 1 FROM historia_comentarios WHERE historia_slug=:historia AND origen_hash=:origen AND comentario=:comentario AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) LIMIT 1");
        $st->execute([':historia'=>$historia,':origen'=>$originHash,':comentario'=>$comentario]);
        if($st->fetchColumn())salida(['ok'=>false,'error'=>'Ese comentario ya fue enviado'],409);
        $visitorHash=$visitante!==null?hash_privado($visitante,$secret):null;
        $flags=flags_comentario($comentario);
        $ins=$pdo->prepare("INSERT INTO historia_comentarios(historia_slug,autor_nombre,comentario,estado,origen_hash,visitante_hash,flags) VALUES(:historia,:autor,:comentario,'PENDIENTE',:origen,:visitante,:flags)");
        $ins->execute([':historia'=>$historia,':autor'=>$nombre,':comentario'=>$comentario,':origen'=>$originHash,':visitante'=>$visitorHash,':flags'=>$flags?implode(',',$flags):null]);
        salida(['ok'=>true,'pendiente'=>true,'mensaje'=>'Gracias. Tu comentario quedó pendiente de moderación.'],201);
    }

    salida(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){
    error_log('[historias/interacciones] '.$e->getMessage());
    salida(['ok'=>false,'error'=>'No se pudo procesar la interacción'],500);
}
