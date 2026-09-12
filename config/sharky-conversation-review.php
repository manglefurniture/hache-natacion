<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-inbox.php';
require_once __DIR__.'/sharky-outbox.php';

const HACHE_SHARKY_REVIEW_WINDOW_SECONDS=7200;
const HACHE_SHARKY_REVIEW_MAX_CONTACTS=200;
const HACHE_SHARKY_REVIEW_MAX_ROWS_PER_DIRECTION=80;

function hache_sharky_conversation_review_schema_ready(PDO $pdo): bool
{
    try{
        $required=['sharky_conversation_review_state','sharky_conversation_reviews','sharky_conversation_findings'];
        $marks=implode(',',array_fill(0,count($required),'?'));
        $st=$pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($marks)");
        $st->execute($required);
        return count($st->fetchAll(PDO::FETCH_COLUMN))===count($required);
    }catch(Throwable $e){return false;}
}

function hache_sharky_conversation_review_uuid(): string
{
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);
    $h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

function hache_sharky_conversation_review_normalize(string $text): string
{
    $text=mb_strtolower(trim($text),'UTF-8');
    $text=strtr($text,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','¿'=>'','¡'=>'']);
    $text=preg_replace('/https?:\/\/\S+/u',' url ',$text)??$text;
    $text=preg_replace('/\s+/u',' ',$text)??$text;
    return trim($text);
}

function hache_sharky_conversation_review_outbound_text(array $payload): string
{
    if(($payload['type']??'')==='text')return trim((string)($payload['text']['body']??$payload['body']??''));
    if(($payload['type']??'')==='interactive')return trim((string)($payload['interactive']['body']['text']??''));
    if(isset($payload['text'])&&is_string($payload['text']))return trim($payload['text']);
    return '';
}

function hache_sharky_conversation_review_question_family(string $text): ?string
{
    $t=hache_sharky_conversation_review_normalize($text);
    if(!str_contains($text,'?')&&!str_contains($text,'¿'))return null;
    if(preg_match('/\b(?:sabes?|se)\s+nadar\b|\bdesde\s+cero\b|\bempezando\b/u',$t))return 'swim';
    if(preg_match('/\b(?:tomado|tomaste|recibido)\s+clases\b|\bclases\s+formales\b|\bpor\s+tu\s+cuenta\b|\bprofesor\b|\bentrenador\b/u',$t))return 'background';
    if(preg_match('/\b(?:sede|monteverde|palapas|protudec)\b/u',$t))return 'venue';
    if(preg_match('/\bhorarios?\b|\bhora\b/u',$t))return 'schedule';
    if(preg_match('/\b(?:precio|cuanto\s+cuesta|costo)\b/u',$t))return 'price';
    return 'generic:'.substr(hash('sha256',preg_replace('/[^a-z0-9 ]/u','',$t)??$t),0,16);
}

function hache_sharky_conversation_review_product(string $text): ?string
{
    $t=hache_sharky_conversation_review_normalize($text);
    $intensive=preg_match('/\b(?:curso\s+)?intensiv[oa]s?\b/u',$t)===1;
    $regular=preg_match('/\bclases?\s+regulares?\b|\bmensualidad(?:es)?\b/u',$t)===1;
    if($intensive===$regular)return null;
    return $intensive?'intensive':'regular';
}

function hache_sharky_conversation_review_explicit_product_change(string $text,string $product): bool
{
    $t=hache_sharky_conversation_review_normalize($text);
    if($product==='regular')return preg_match('/\b(?:regulares?|mensualidad(?:es)?)\b/u',$t)===1;
    return preg_match('/\bintensiv[oa]s?\b/u',$t)===1;
}

function hache_sharky_conversation_review_user_correction(string $text): bool
{
    $t=hache_sharky_conversation_review_normalize($text);
    return preg_match('/\b(?:ya\s+te\s+(?:dije|dije\s+eso)|eso\s+ya\s+te\s+lo\s+dije|no\s+me\s+entendiste|no\s+entendiste|te\s+acabo\s+de\s+decir|otra\s+vez\s+lo\s+mismo|ya\s+te\s+respondi)\b/u',$t)===1;
}

function hache_sharky_conversation_review_price_question(string $text): bool
{
    $t=hache_sharky_conversation_review_normalize($text);
    return preg_match('/\b(?:precio|costo|cuanto\s+cuesta|cuanto\s+sale|que\s+cuesta)\b/u',$t)===1;
}

function hache_sharky_conversation_review_price_answer(string $text): bool
{
    $t=hache_sharky_conversation_review_normalize($text);
    return str_contains($text,'$')||preg_match('/\b(?:mxn|pesos?|precio|cuesta|costo)\b/u',$t)===1;
}


function hache_sharky_conversation_review_location_request(string $text): bool
{
    $t=hache_sharky_conversation_review_normalize($text);
    return preg_match('/\b(?:ubicacion|direccion|maps|mapa|donde\s+(?:esta|queda)|como\s+(?:llego|llegar))\b/u',$t)===1;
}

function hache_sharky_conversation_review_explicit_venue(string $text): ?string
{
    $t=hache_sharky_conversation_review_normalize($text);
    $mv=preg_match('/\bmonteverde\b/u',$t)===1;
    $pal=preg_match('/\bpalapas(?:\s+protudec)?\b/u',$t)===1;
    if($mv&&!$pal)return 'MONTEVERDE';
    if($pal&&!$mv)return 'PALAPAS';
    return null;
}

function hache_sharky_conversation_review_location_answer(string $text): bool
{
    $t=hache_sharky_conversation_review_normalize($text);
    return str_contains($t,' url ')
        || preg_match('/\b(?:ubicacion|direccion|indicaciones|maps|mapa)\b/u',$t)===1;
}

function hache_sharky_conversation_review_schedule_answer(string $text): bool
{
    $t=hache_sharky_conversation_review_normalize($text);
    return preg_match('/\bhorarios?\b/u',$t)===1
        || preg_match('/\b\d{1,2}:\d{2}\s*[–-]\s*\d{1,2}:\d{2}\b/u',$t)===1;
}

function hache_sharky_conversation_review_finding(string $type,string $severity,string $sourceId,string $relatedId='',array $evidence=[]): array
{
    return ['type'=>$type,'severity'=>$severity,'source_id'=>$sourceId,'related_id'=>$relatedId,'evidence'=>$evidence];
}

/** @param list<array{direction:string,id:string,ts:int,text:string}> $timeline */
function hache_sharky_conversation_review_analyze(array $timeline): array
{
    usort($timeline,static fn(array $a,array $b):int=>($a['ts']<=>$b['ts'])?:strcmp($a['id'],$b['id']));
    $findings=[];$lastInboundByText=[];$lastQuestion=[];$familyCount=[];$lastOutboundProduct=null;$lastOutboundProductIndex=null;

    foreach($timeline as $i=>$turn){
        $dir=$turn['direction'];$text=$turn['text'];$id=$turn['id'];$ts=(int)$turn['ts'];
        if($text==='')continue;

        if($dir==='in'){
            $n=hache_sharky_conversation_review_normalize($text);
            if(hache_sharky_conversation_review_user_correction($text)){
                $findings[]=hache_sharky_conversation_review_finding('USER_CORRECTION','HIGH',$id,'',['signal'=>'explicit_correction']);
            }
            if(mb_strlen($n)>=3&&isset($lastInboundByText[$n])){
                $prev=$lastInboundByText[$n];$hasOut=false;
                for($j=$prev['index']+1;$j<$i;$j++)if(($timeline[$j]['direction']??'')==='out'){$hasOut=true;break;}
                if($hasOut&&$ts-$prev['ts']<=900){
                    $findings[]=hache_sharky_conversation_review_finding('USER_REPEAT','WARN',$id,$prev['id'],['seconds'=>$ts-$prev['ts']]);
                }
            }
            if($n!=='')$lastInboundByText[$n]=['id'=>$id,'ts'=>$ts,'index'=>$i];

            if(hache_sharky_conversation_review_price_question($text)){
                for($j=$i+1;$j<count($timeline);$j++){
                    if(($timeline[$j]['direction']??'')!=='out')continue;
                    $reply=$timeline[$j];$family=hache_sharky_conversation_review_question_family($reply['text']);
                    if(!hache_sharky_conversation_review_price_answer($reply['text'])&&in_array($family,['swim','background','venue'],true)){
                        $findings[]=hache_sharky_conversation_review_finding('DIRECT_PRICE_QUESTION_DEFERRED','WARN',$id,$reply['id'],['next_question_family'=>$family]);
                    }
                    break;
                }
            }
            continue;
        }

        if($dir!=='out')continue;
        $family=hache_sharky_conversation_review_question_family($text);
        if(in_array($family,['swim','background','venue'],true)){
            $familyCount[$family]=($familyCount[$family]??0)+1;
            if(isset($lastQuestion[$family])){
                $prev=$lastQuestion[$family];$hasInbound=false;
                for($j=$prev['index']+1;$j<$i;$j++)if(($timeline[$j]['direction']??'')==='in'){$hasInbound=true;break;}
                if($hasInbound&&$ts-$prev['ts']<=1200){
                    $findings[]=hache_sharky_conversation_review_finding('REPEATED_QUESTION',$familyCount[$family]>=3?'HIGH':'WARN',$id,$prev['id'],['family'=>$family,'seconds'=>$ts-$prev['ts']]);
                }
            }
            $lastQuestion[$family]=['id'=>$id,'ts'=>$ts,'index'=>$i];
        }

        if($i>=3
            &&($timeline[$i-1]['direction']??'')==='in'
            &&($timeline[$i-2]['direction']??'')==='out'
            &&($timeline[$i-3]['direction']??'')==='in'
            &&hache_sharky_conversation_review_location_request((string)$timeline[$i-3]['text'])
            &&hache_sharky_conversation_review_explicit_venue((string)$timeline[$i-1]['text'])!==null
            &&hache_sharky_conversation_review_location_request((string)$timeline[$i-2]['text'])
            &&hache_sharky_conversation_review_schedule_answer($text)
            &&!hache_sharky_conversation_review_location_answer($text)){
            $findings[]=hache_sharky_conversation_review_finding(
                'CONTEXT_LOSS_LOCATION_INTENT','WARN',(string)$timeline[$i-1]['id'],$id,
                ['expected'=>'location_for_selected_venue']
            );
        }

        if($i>=2&&($timeline[$i-1]['direction']??'')==='in'&&($timeline[$i-2]['direction']??'')==='out'){
            $prevFamily=hache_sharky_conversation_review_question_family((string)$timeline[$i-2]['text']);
            $answer=hache_sharky_conversation_review_normalize((string)$timeline[$i-1]['text']);
            if($prevFamily==='background'&&preg_match('/^nunca[.! ]*$/u',$answer)===1){
                $current=hache_sharky_conversation_review_normalize($text);
                if($family==='background'||preg_match('/\b(?:te\s+refieres|quieres\s+decir|nunca\s+te\s+refieres)\b/u',$current)===1){
                    $findings[]=hache_sharky_conversation_review_finding('CONTEXT_LOSS_SHORT_ANSWER','HIGH',(string)$timeline[$i-1]['id'],$id,['expected'=>'background_no_formal']);
                }
            }
        }

        $product=hache_sharky_conversation_review_product($text);
        if($product!==null&&$lastOutboundProduct!==null&&$product!==$lastOutboundProduct&&$lastOutboundProductIndex!==null){
            $authorized=false;
            for($j=$lastOutboundProductIndex+1;$j<$i;$j++){
                if(($timeline[$j]['direction']??'')==='in'&&hache_sharky_conversation_review_explicit_product_change((string)$timeline[$j]['text'],$product)){$authorized=true;break;}
            }
            if(!$authorized){
                $findings[]=hache_sharky_conversation_review_finding('PRODUCT_DRIFT','HIGH',$id,(string)$timeline[$lastOutboundProductIndex]['id'],['from'=>$lastOutboundProduct,'to'=>$product]);
            }
        }
        if($product!==null){$lastOutboundProduct=$product;$lastOutboundProductIndex=$i;}
    }

    $unique=[];
    foreach($findings as $finding){
        $key=$finding['type'].'|'.$finding['source_id'].'|'.$finding['related_id'];
        $unique[$key]=$finding;
    }
    return array_values($unique);
}

/** @return list<array{direction:string,id:string,ts:int,text:string}> */
function hache_sharky_conversation_review_timeline(PDO $pdo,string $contactHash,int $since): array
{
    $timeline=[];$limit=HACHE_SHARKY_REVIEW_MAX_ROWS_PER_DIRECTION;
    $in=$pdo->prepare("SELECT message_id,UNIX_TIMESTAMP(received_at) ts,payload_ciphertext,payload_iv,payload_tag FROM sharky_message_receipts WHERE contact_hash=:c AND received_at>=FROM_UNIXTIME(:s) AND payload_ciphertext IS NOT NULL ORDER BY received_at DESC LIMIT $limit");
    $in->execute([':c'=>$contactHash,':s'=>$since]);
    foreach(array_reverse($in->fetchAll(PDO::FETCH_ASSOC)) as $row){
        $event=hache_sharky_inbox_decrypt($row);if(!is_array($event))continue;
        $text=trim((string)($event['text']??''));if($text==='')continue;
        $timeline[]=['direction'=>'in','id'=>(string)$row['message_id'],'ts'=>(int)$row['ts'],'text'=>$text];
    }
    $out=$pdo->prepare("SELECT id,UNIX_TIMESTAMP(COALESCE(sent_at,created_at)) ts,payload_ciphertext,payload_iv,payload_tag FROM sharky_outbox WHERE contact_hash=:c AND status='SENT' AND COALESCE(sent_at,created_at)>=FROM_UNIXTIME(:s) ORDER BY COALESCE(sent_at,created_at) DESC LIMIT $limit");
    $out->execute([':c'=>$contactHash,':s'=>$since]);
    foreach(array_reverse($out->fetchAll(PDO::FETCH_ASSOC)) as $row){
        $payload=hache_sharky_outbox_decrypt($row);if(!is_array($payload))continue;
        $text=hache_sharky_conversation_review_outbound_text($payload);if($text==='')continue;
        $timeline[]=['direction'=>'out','id'=>(string)$row['id'],'ts'=>(int)$row['ts'],'text'=>$text];
    }
    usort($timeline,static fn(array $a,array $b):int=>($a['ts']<=>$b['ts'])?:strcmp($a['id'],$b['id']));
    return $timeline;
}

function hache_sharky_conversation_review_store(PDO $pdo,string $contactHash,int $windowStart,int $windowEnd,array $findings): array
{
    $high=count(array_filter($findings,static fn(array $f):bool=>($f['severity']??'')==='HIGH'));
    $classification=$high>0?'PROBLEM':($findings!==[]?'REVIEW':'OK');
    $reviewId=hache_sharky_conversation_review_uuid();
    $reviewFingerprint=hash('sha256','review|'.$contactHash.'|'.gmdate('YmdH',$windowEnd));
    $st=$pdo->prepare("INSERT IGNORE INTO sharky_conversation_reviews(id,contact_hash,window_start,window_end,classification,finding_count,high_count,review_fingerprint) VALUES(:id,:c,FROM_UNIXTIME(:ws),FROM_UNIXTIME(:we),:cl,:fc,:hc,:fp)");
    $st->execute([':id'=>$reviewId,':c'=>$contactHash,':ws'=>$windowStart,':we'=>$windowEnd,':cl'=>$classification,':fc'=>count($findings),':hc'=>$high,':fp'=>$reviewFingerprint]);
    if($st->rowCount()!==1){
        $q=$pdo->prepare('SELECT id FROM sharky_conversation_reviews WHERE review_fingerprint=:fp LIMIT 1');$q->execute([':fp'=>$reviewFingerprint]);$reviewId=(string)($q->fetchColumn()?:'');
    }
    $new=0;
    foreach($findings as $finding){
        if($reviewId==='')break;
        $fp=hash('sha256','finding|'.$contactHash.'|'.$finding['type'].'|'.$finding['source_id'].'|'.$finding['related_id']);
        $evidence=json_encode($finding['evidence']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $q=$pdo->prepare("INSERT IGNORE INTO sharky_conversation_findings(id,review_id,contact_hash,finding_type,severity,source_message_id,related_message_id,evidence_json,finding_fingerprint) VALUES(:id,:r,:c,:t,:s,:src,:rel,:e,:fp)");
        $q->execute([':id'=>hache_sharky_conversation_review_uuid(),':r'=>$reviewId,':c'=>$contactHash,':t'=>$finding['type'],':s'=>$finding['severity'],':src'=>$finding['source_id']?:null,':rel'=>$finding['related_id']?:null,':e'=>$evidence===false?null:$evidence,':fp'=>$fp]);
        if($q->rowCount()===1){$new++;if(($finding['severity']??'')==='HIGH')error_log('[sharky-review][HIGH] type='.$finding['type'].' contact='.substr($contactHash,0,12).' source='.substr((string)$finding['source_id'],0,24));}
    }
    return ['classification'=>$classification,'findings'=>count($findings),'high'=>$high,'new'=>$new];
}

function hache_sharky_conversation_review_execute(PDO $pdo,?int $now=null): array
{
    $now??=time();$since=$now-HACHE_SHARKY_REVIEW_WINDOW_SECONDS;
    $stats=['contacts'=>0,'ok'=>0,'review'=>0,'problem'=>0,'findings'=>0,'new_findings'=>0,'high'=>0];
    $q=$pdo->prepare('SELECT DISTINCT contact_hash FROM sharky_message_receipts WHERE received_at>=FROM_UNIXTIME(:s) ORDER BY contact_hash LIMIT '.HACHE_SHARKY_REVIEW_MAX_CONTACTS);
    $q->execute([':s'=>$since]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $contactHash){
        $contactHash=(string)$contactHash;if(!preg_match('/^[a-f0-9]{64}$/',$contactHash))continue;
        $timeline=hache_sharky_conversation_review_timeline($pdo,$contactHash,$since);if($timeline===[])continue;
        $findings=hache_sharky_conversation_review_analyze($timeline);
        $stored=hache_sharky_conversation_review_store($pdo,$contactHash,$since,$now,$findings);
        $stats['contacts']++;$stats[strtolower($stored['classification'])]++;
        $stats['findings']+=$stored['findings'];$stats['new_findings']+=$stored['new'];$stats['high']+=$stored['high'];
    }
    return $stats;
}

function hache_sharky_conversation_review_maybe_run(PDO $pdo,bool $force=false): array
{
    if(!hache_sharky_conversation_review_schema_ready($pdo))return ['status'=>'schema_missing'];
    try{
        if(!$force){
            $claim=$pdo->prepare("UPDATE sharky_conversation_review_state SET next_run_at=DATE_ADD(NOW(),INTERVAL 1 HOUR),last_started_at=NOW(),last_status='RUNNING',last_error=NULL WHERE id=1 AND next_run_at<=NOW()");
            $claim->execute();if($claim->rowCount()!==1)return ['status'=>'not_due'];
        }else{
            $pdo->exec("UPDATE sharky_conversation_review_state SET last_started_at=NOW(),last_status='RUNNING',last_error=NULL WHERE id=1");
        }
        $stats=hache_sharky_conversation_review_execute($pdo);
        $pdo->exec("UPDATE sharky_conversation_review_state SET last_finished_at=NOW(),last_status='OK',last_error=NULL WHERE id=1");
        if(function_exists('hache_sharky_metric_increment')){
            if(($stats['problem']??0)>0)hache_sharky_metric_increment('conversation_review_problem');
            if(($stats['new_findings']??0)>0)hache_sharky_metric_increment('conversation_review_finding');
        }
        return ['status'=>'ok']+$stats;
    }catch(Throwable $e){
        try{$st=$pdo->prepare("UPDATE sharky_conversation_review_state SET last_finished_at=NOW(),last_status='ERROR',last_error=:e WHERE id=1");$st->execute([':e'=>mb_substr($e->getMessage(),0,255)]);}catch(Throwable $ignored){}
        error_log('[sharky-review] hourly review failed');
        return ['status'=>'error'];
    }
}
