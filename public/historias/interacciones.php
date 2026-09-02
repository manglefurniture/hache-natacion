<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$config=require __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../config/historias-notificaciones.php';
require_once __DIR__.'/../../config/rate-limit.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],$config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

const HISTORIAS_PUBLICAS=['maria-del-carmen'];
const REACCIONES_PUBLICAS=['CORAZON','APLAUSOS','INSPIRA','FUERZA','SONRISA'];

function salida(array $data,int $status=200): never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function salida_con_tarea(array $data,int $status,callable $task): never
{
    http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(function_exists('fastcgi_finish_request'))@fastcgi_finish_request();
    try{$task();}catch(Throwable $e){error_log('[historias/interacciones] Falló tarea posterior a respuesta: '.$e->getMessage());}
    exit;
}
function texto_limpio(mixed $value,int $max): string
{
    $value=strip_tags((string)$value);
    $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value)??'';
    $value=preg_replace('/[ \t]+/u',' ',$value)??'';
    $value=preg_replace('/\R{3,}/u',"\n\n",$value)??'';
    return mb_substr(trim($value),0,$max);
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
    if($id===''||strlen($id)<16||strlen($id)>128||!preg_match('/^[a-zA-Z0-9._:-]+$/',$id))return null;
    return $id;
}
function comentario_id_valido(mixed $value): ?string
{
    $id=trim((string)$value);return $id!==''&&preg_match('/^[a-f0-9-]{36}$/i',$id)?$id:null;
}
function uuid_v4(): string
{
    $data=random_bytes(16);$data[6]=chr((ord($data[6])&0x0f)|0x40);$data[8]=chr((ord($data[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
}
function secreto_interacciones(array $config): string
{
    $env=trim((string)(getenv('HACHE_PUBLIC_INTERACTION_SALT')?:''));if($env!=='')return $env;
    $dbPass=(string)($config['password']??'');return $dbPass!==''?$dbPass:'hache-public-interactions-v1';
}
function hash_privado(string $value,string $secret): string{return hash_hmac('sha256',$value,$secret);}
function origen_ip(): string{return trim((string)($_SERVER['REMOTE_ADDR']??'unknown')) ?: 'unknown';}
function mismo_origen(): bool
{
    $host=strtolower(explode(':',trim((string)($_SERVER['HTTP_HOST']??'')),2)[0]);if($host==='')return false;
    foreach(['HTTP_ORIGIN','HTTP_REFERER'] as $key){
        $raw=trim((string)($_SERVER[$key]??''));if($raw==='')continue;
        $source=parse_url($raw,PHP_URL_HOST);if(!is_string($source)||$source===''||strtolower($source)!==$host)return false;
    }
    return true;
}
function origen_bloqueado(PDO $pdo,string $hash): bool
{
    $st=$pdo->prepare('SELECT 1 FROM historia_bloqueos WHERE origen_hash=:hash AND activo=1 LIMIT 1');$st->execute([':hash'=>$hash]);return (bool)$st->fetchColumn();
}
function nombre_publico(string $nombre): string
{
    $partes=preg_split('/\s+/u',trim($nombre))?:[];return mb_substr((string)($partes[0]??'Visitante'),0,30);
}
function flags_comentario(string $comentario): array
{
    $flags=[];preg_match_all('/(?:https?:\/\/|www\.|\b[a-z0-9-]+\.(?:com|net|org|mx|io)\b)/iu',$comentario,$links);
    if(count($links[0]??[])>0)$flags[]='contiene_enlace';if(preg_match('/(.)\1{9,}/u',$comentario))$flags[]='repeticion';
    $letters=preg_replace('/[^\p{L}]/u','',$comentario)??'';
    if(mb_strlen($letters)>=20){$upper=preg_replace('/[^\p{Lu}]/u','',$letters)??'';if(mb_strlen($upper)/max(1,mb_strlen($letters))>.7)$flags[]='mayusculas';}
    if(preg_match('/\b(?:casino|viagra|cripto|crypto|apuesta|apuestas|spam)\b/iu',$comentario))$flags[]='spam_probable';
    if(preg_match('/\b(?:pendej[oa]s?|idiotas?|mierda|put[oa]s?)\b/iu',$comentario))$flags[]='lenguaje_revisar';return $flags;
}
function limitar(string $scope,string $subject,int $limit,int $window,string $message): void
{
    $rate=security_rate_limit_record($scope,$subject,$limit,$window);
    if(!$rate['allowed']){header('Retry-After: '.max(1,(int)$rate['retry_after']));salida(['ok'=>false,'error'=>$message],429);}
}
function historias_extension_disponible(PDO $pdo): bool
{
    static $available=null;if($available!==null)return $available;
    $st=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('historia_respuestas','historia_comentario_suscripciones')");
    $available=((int)$st->fetchColumn()===2);return $available;
}

try{
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));$secret=secreto_interacciones($config);$originHash=hash_privado(origen_ip(),$secret);$extension=historias_extension_disponible($pdo);
    if($method==='GET'){
        $historia=historia_valida($_GET['historia']??'');$visitante=visitante_valido($_GET['visitante']??null);$counts=array_fill_keys(REACCIONES_PUBLICAS,0);
        $st=$pdo->prepare('SELECT tipo,COUNT(*) total FROM historia_reacciones WHERE historia_slug=:historia GROUP BY tipo');$st->execute([':historia'=>$historia]);
        foreach($st->fetchAll() as $row){if(isset($counts[$row['tipo']]))$counts[$row['tipo']]=(int)$row['total'];}
        $mine=null;
        if($visitante!==null){$visitorHash=hash_privado($visitante,$secret);$st=$pdo->prepare('SELECT tipo FROM historia_reacciones WHERE historia_slug=:historia AND visitante_hash=:visitante LIMIT 1');$st->execute([':historia'=>$historia,':visitante'=>$visitorHash]);$mine=$st->fetchColumn()?:null;}

        if($extension)$st=$pdo->prepare("SELECT c.id,c.autor_nombre,c.comentario,c.created_at FROM historia_comentarios c LEFT JOIN historia_respuestas r ON r.comentario_id=c.id WHERE c.historia_slug=:historia AND c.estado='APROBADO' AND r.comentario_id IS NULL ORDER BY c.created_at DESC LIMIT 50");
        else $st=$pdo->prepare("SELECT c.id,c.autor_nombre,c.comentario,c.created_at FROM historia_comentarios c WHERE c.historia_slug=:historia AND c.estado='APROBADO' ORDER BY c.created_at DESC LIMIT 50");
        $st->execute([':historia'=>$historia]);$comments=[];$positions=[];
        foreach($st->fetchAll() as $row){$positions[(string)$row['id']]=count($comments);$comments[]=['id'=>$row['id'],'autor'=>nombre_publico((string)$row['autor_nombre']),'comentario'=>$row['comentario'],'fecha'=>$row['created_at'],'respuestas'=>[]];}

        if($extension&&$positions){
            $params=[':historia'=>$historia];$holders=[];$i=0;
            foreach(array_keys($positions) as $rootId){$key=':root'.$i++;$holders[]=$key;$params[$key]=$rootId;}
            $sql="SELECT c.id,c.autor_nombre,c.comentario,c.created_at,r.parent_id,r.reply_to_id,target.autor_nombre target_autor FROM historia_respuestas r JOIN historia_comentarios c ON c.id=r.comentario_id JOIN historia_comentarios root ON root.id=r.parent_id AND root.estado='APROBADO' JOIN historia_comentarios target ON target.id=r.reply_to_id AND target.estado='APROBADO' WHERE c.historia_slug=:historia AND c.estado='APROBADO' AND r.parent_id IN (".implode(',',$holders).") ORDER BY c.created_at DESC LIMIT 250";
            $st=$pdo->prepare($sql);$st->execute($params);$replyRows=array_reverse($st->fetchAll());
            foreach($replyRows as $row){$parent=(string)$row['parent_id'];if(!isset($positions[$parent]))continue;$comments[$positions[$parent]]['respuestas'][]=['id'=>$row['id'],'autor'=>nombre_publico((string)$row['autor_nombre']),'comentario'=>$row['comentario'],'fecha'=>$row['created_at'],'respondio_a'=>nombre_publico((string)$row['target_autor'])];}
        }
        salida(['ok'=>true,'reacciones'=>$counts,'mi_reaccion'=>$mine,'comentarios'=>$comments,'respuestas_habilitadas'=>$extension]);
    }

    if($method!=='POST')salida(['ok'=>false,'error'=>'Método no permitido'],405);if(!mismo_origen())salida(['ok'=>false,'error'=>'Origen no permitido'],403);
    $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]));if($contentType!=='application/json')salida(['ok'=>false,'error'=>'Tipo de contenido no permitido'],415);
    $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))salida(['ok'=>false,'error'=>'Solicitud inválida'],400);
    $historia=historia_valida($input['historia']??'');$accion=strtoupper(trim((string)($input['accion']??'')));$visitante=visitante_valido($input['visitante']??null);
    if(origen_bloqueado($pdo,$originHash))salida(['ok'=>false,'error'=>'No se puede publicar desde este origen'],403);

    if($accion==='REACCION'){
        if($visitante===null)salida(['ok'=>false,'error'=>'Visitante inválido'],422);$tipo=strtoupper(trim((string)($input['tipo']??'')));
        if(!in_array($tipo,REACCIONES_PUBLICAS,true))salida(['ok'=>false,'error'=>'Reacción inválida'],422);
        limitar('historias-reacciones',$originHash,25,600,'Demasiadas reacciones. Intenta más tarde.');
        $visitorHash=hash_privado($visitante,$secret);$st=$pdo->prepare('SELECT id,tipo FROM historia_reacciones WHERE historia_slug=:historia AND visitante_hash=:visitante LIMIT 1');$st->execute([':historia'=>$historia,':visitante'=>$visitorHash]);$existing=$st->fetch();
        if($existing&&$existing['tipo']===$tipo){$pdo->prepare('DELETE FROM historia_reacciones WHERE id=:id')->execute([':id'=>$existing['id']]);salida(['ok'=>true,'mi_reaccion'=>null]);}
        if($existing)$pdo->prepare('UPDATE historia_reacciones SET tipo=:tipo,origen_hash=:origen,updated_at=NOW() WHERE id=:id')->execute([':tipo'=>$tipo,':origen'=>$originHash,':id'=>$existing['id']]);
        else $pdo->prepare('INSERT INTO historia_reacciones(historia_slug,tipo,visitante_hash,origen_hash) VALUES(:historia,:tipo,:visitante,:origen)')->execute([':historia'=>$historia,':tipo'=>$tipo,':visitante'=>$visitorHash,':origen'=>$originHash]);
        salida(['ok'=>true,'mi_reaccion'=>$tipo]);
    }

    if($accion==='COMENTARIO'){
        if(texto_limpio($input['website']??'',200)!=='')salida(['ok'=>true,'pendiente'=>true]);
        $nombre=texto_limpio($input['nombre']??'',80);$comentario=texto_limpio($input['comentario']??'',700);$correo=trim((string)($input['correo']??''));
        $notificar=($input['notificar_respuestas']??false)===true||($input['notificar_respuestas']??null)===1||($input['notificar_respuestas']??null)==='1';$targetId=comentario_id_valido($input['responder_a']??null);
        if(isset($input['responder_a'])&&trim((string)$input['responder_a'])!==''&&$targetId===null)salida(['ok'=>false,'error'=>'El comentario al que respondes ya no está disponible'],422);
        if(($targetId!==null||$notificar)&&!$extension)salida(['ok'=>false,'error'=>'Las respuestas y avisos estarán disponibles al terminar la actualización del sistema.'],503);
        if(mb_strlen($nombre)<2||mb_strlen($nombre)>80||preg_match('/https?:\/\/|www\./iu',$nombre))salida(['ok'=>false,'error'=>'Escribe un nombre válido'],422);
        if(mb_strlen($comentario)<3||mb_strlen($comentario)>700)salida(['ok'=>false,'error'=>'El comentario debe tener entre 3 y 700 caracteres'],422);
        if($notificar&&(!filter_var($correo,FILTER_VALIDATE_EMAIL)||strlen($correo)>254))salida(['ok'=>false,'error'=>'Escribe un correo válido para activar los avisos'],422);
        preg_match_all('/(?:https?:\/\/|www\.|\b[a-z0-9-]+\.(?:com|net|org|mx|io)\b)/iu',$comentario,$links);if(count($links[0]??[])>1)salida(['ok'=>false,'error'=>'El comentario contiene demasiados enlaces'],422);
        $target=null;
        if($targetId!==null){
            $st=$pdo->prepare("SELECT c.id,c.autor_nombre,r.parent_id FROM historia_comentarios c LEFT JOIN historia_respuestas r ON r.comentario_id=c.id LEFT JOIN historia_comentarios root ON root.id=r.parent_id WHERE c.id=:id AND c.historia_slug=:historia AND c.estado='APROBADO' AND (r.parent_id IS NULL OR root.estado='APROBADO') LIMIT 1");$st->execute([':id'=>$targetId,':historia'=>$historia]);$target=$st->fetch();if(!$target)salida(['ok'=>false,'error'=>'El comentario al que respondes ya no está disponible'],409);
        }
        limitar('historias-comentarios-cooldown',$originHash,1,45,'Espera un momento antes de enviar otro comentario.');
        limitar('historias-comentarios-ventana',$originHash,3,1800,'Has enviado varios comentarios. Intenta más tarde.');
        $st=$pdo->prepare("SELECT 1 FROM historia_comentarios WHERE historia_slug=:historia AND origen_hash=:origen AND comentario=:comentario AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) LIMIT 1");$st->execute([':historia'=>$historia,':origen'=>$originHash,':comentario'=>$comentario]);if($st->fetchColumn())salida(['ok'=>false,'error'=>'Ese comentario ya fue enviado'],409);
        $notificationSecret=null;$confirmToken=null;
        if($notificar){$notificationSecret=historias_notificacion_secreto($config);$confirmToken=historias_confirm_token('pending',$correo,$notificationSecret);}
        $id=uuid_v4();if($notificar)$confirmToken=historias_confirm_token($id,$correo,(string)$notificationSecret);
        $visitorHash=$visitante!==null?hash_privado($visitante,$secret):null;$flags=flags_comentario($comentario);$pdo->beginTransaction();
        $pdo->prepare("INSERT INTO historia_comentarios(id,historia_slug,autor_nombre,comentario,estado,origen_hash,visitante_hash,flags) VALUES(:id,:historia,:autor,:comentario,'PENDIENTE',:origen,:visitante,:flags)")->execute([':id'=>$id,':historia'=>$historia,':autor'=>$nombre,':comentario'=>$comentario,':origen'=>$originHash,':visitante'=>$visitorHash,':flags'=>$flags?implode(',',$flags):null]);
        if($target){$rootId=trim((string)($target['parent_id']??''))!==''?(string)$target['parent_id']:(string)$target['id'];$pdo->prepare('INSERT INTO historia_respuestas(comentario_id,parent_id,reply_to_id) VALUES(:comentario,:parent,:target)')->execute([':comentario'=>$id,':parent'=>$rootId,':target'=>$target['id']]);}
        if($notificar)$pdo->prepare("INSERT INTO historia_comentario_suscripciones(comentario_id,email,estado,confirm_token_hash,confirm_expires_at) VALUES(:comentario,:email,'PENDIENTE',:token,DATE_ADD(NOW(),INTERVAL 7 DAY))")->execute([':comentario'=>$id,':email'=>$correo,':token'=>hash('sha256',(string)$confirmToken)]);
        $pdo->commit();$baseMessage=$target?'Gracias. Tu respuesta quedó pendiente de moderación.':'Gracias. Tu comentario quedó pendiente de moderación.';
        if($notificar)salida_con_tarea(['ok'=>true,'pendiente'=>true,'aviso_pendiente'=>true,'mensaje'=>$baseMessage.' Te enviamos un correo para confirmar los avisos de respuestas.'],201,static function() use($pdo,$id,$config): void {historias_enviar_confirmacion_comentario($pdo,$id,$config);});
        salida(['ok'=>true,'pendiente'=>true,'mensaje'=>$baseMessage],201);
    }
    salida(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('[historias/interacciones] '.$e->getMessage());salida(['ok'=>false,'error'=>'No se pudo procesar la interacción'],500);}
