<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-whatsapp-adapter.php';

/**
 * Claims the original Meta message before waiting. Only the worker that reaches
 * the flush boundary processes the aggregated text. Interactive replies bypass
 * batching so button/list selections remain deterministic.
 */
function hache_sharky_whatsapp_enqueue(PDO $pdo,array $event,callable $conversationAnswer,array $extraContext=[]): array
{
    $contact=(string)($event['from']??'');$id=(string)($event['id']??'');
    if($contact===''||$id==='')return ['skip'=>true,'code'=>'INVALID_EVENT'];
    if((string)($event['type']??'')==='interactive'||trim((string)($event['interactive_id']??''))!=='')return hache_sharky_whatsapp_process($pdo,$event,$conversationAnswer,$extraContext);

    $hash=hache_sharky_orchestrator_contact_hash($contact);
    if(!hache_sharky_orchestrator_claim_message($pdo,$id,$hash,(string)($event['type']??'text')))return ['skip'=>true,'code'=>'DUPLICATE'];
    $ref=hache_sharky_orchestrator_referral($event,(int)($extraContext['now']??time()));
    if($ref){$identity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);hache_sharky_orchestrator_store_referral($pdo,$id,$hash,$ref,($identity['found']??false)?(string)$identity['student_id']:null);}

    $batch=hache_sharky_orchestrator_batch_enqueue_and_wait($contact,$event,(int)($extraContext['batch_window_ms']??HACHE_SHARKY_BATCH_WINDOW_MS));
    if($batch===null)return ['skip'=>true,'code'=>'BATCH_DEFERRED'];
    $ids=is_array($batch['ids']??null)?$batch['ids']:[$id];$latestReferral=is_array($batch['referral']??null)?$batch['referral']:null;
    if($latestReferral===null&&is_array($event['referral']??null))$latestReferral=$event['referral'];
    $synthetic=['id'=>'batch:'.hash('sha256',implode('|',$ids)),'from'=>$contact,'type'=>'text','text'=>(string)($batch['text']??''),'interactive_id'=>'','timestamp_ms'=>(int)($event['timestamp_ms']??floor(microtime(true)*1000))];
    if($latestReferral!==null)$synthetic['referral']=$latestReferral;
    $result=hache_sharky_whatsapp_process($pdo,$synthetic,$conversationAnswer,$extraContext);
    $deliveryPending=function_exists('hache_sharky_action_delivery_pending_for_message')&&hache_sharky_action_delivery_pending_for_message($pdo,$synthetic['id']);
    $deferCompletion=($extraContext['defer_receipt_completion']??false)===true||$deliveryPending;
    $result['batched_ids']=$ids;$result['synthetic_id']=$synthetic['id'];$result['defer_processed']=$deferCompletion;
    if(!$deferCompletion)foreach($ids as $messageId)hache_sharky_orchestrator_mark_processed($pdo,(string)$messageId);
    return $result;
}
