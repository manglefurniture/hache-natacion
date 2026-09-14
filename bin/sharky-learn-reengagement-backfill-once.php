<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-outbox.php';
require_once __DIR__.'/../config/sharky-inbox.php';
require_once __DIR__.'/../config/sharky-contact-book.php';
require_once __DIR__.'/../config/sharky-followup.php';

const HACHE_SHARKY_LEARN_BACKFILL_WINDOW_SECONDS = 86400;
const HACHE_SHARKY_LEARN_BACKFILL_FLAG = '--execute-approved-20260914-learn';

function hache_sharky_learn_reengagement_live_row_exists(PDO $pdo,string $contact): bool
{
    try{
        $st=$pdo->prepare(
            "SELECT payload_ciphertext,payload_iv,payload_tag FROM sharky_outbox "
            ."WHERE contact_hash=:c AND status='PENDING' ORDER BY created_at DESC,id DESC LIMIT 12"
        );
        $st->execute([':c'=>hache_sharky_orchestrator_contact_hash($contact)]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $payload=hache_sharky_outbox_decrypt($row);if(!is_array($payload))continue;
            $meta=$payload['_sharky_followup']??null;if(!is_array($meta)||(int)($meta['stage']??0)!==3)continue;
            if((string)($payload['template']['name']??'')===HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE)return true;
        }
        return false;
    }catch(Throwable $e){
        error_log('[sharky-learn-backfill] live row lookup failed');
        return true;
    }
}

/**
 * One-shot migration for the product decision approved on 2026-09-14:
 * - retire pending rows that still use the previous generic 48 h template;
 * - recover recent prospects whose latest explicit program button was
 *   `meta:program:learn`;
 * - persist that explicit choice in encrypted conversation state and schedule
 *   only the new learn-specific 48 h template.
 *
 * Output is aggregate-only. No phone, message body or provider id is printed.
 *
 * @return array<string,mixed>
 */
function hache_sharky_learn_reengagement_backfill_once(?PDO $pdo=null,?int $now=null): array
{
    $now??=time();
    $stats=[
        'mode'=>'learn-reengagement-approved-20260914',
        'window_hours'=>24,
        'old_pending_template_cancelled'=>0,
        'legacy_state_requeued'=>0,
        'receipt_rows_scanned'=>0,
        'contacts_seen'=>0,
        'learn_candidates'=>0,
        'scheduled'=>0,
        'excluded'=>[
            'decrypt_failed'=>0,
            'invalid_contact'=>0,
            'latest_program_not_learn'=>0,
            'not_current_prospect'=>0,
            'registered'=>0,
            'pending_inbound'=>0,
            'state_unavailable'=>0,
            'state_not_current_prospect'=>0,
            'state_too_old'=>0,
            'opted_out_or_deferred'=>0,
            'context_not_eligible'=>0,
            'already_scheduled'=>0,
            'queue_failed'=>0,
        ],
        'privacy'=>'aggregate_counts_only',
        'generated_at_utc'=>gmdate('c',$now),
    ];

    $pdo??=hache_sharky_pdo();
    if(!$pdo instanceof PDO)throw new RuntimeException('Database unavailable');
    if(!hache_sharky_orchestrator_store_ready($pdo))throw new RuntimeException('Sharky store unavailable');

    // Retire unsent rows for the generic template. New dispatch validation also
    // fails them closed, but cancelling them makes the cutover explicit.
    $oldRows=$pdo->query(
        "SELECT id,payload_ciphertext,payload_iv,payload_tag FROM sharky_outbox "
        ."WHERE status='PENDING' AND created_at>=DATE_SUB(NOW(),INTERVAL 5 DAY) "
        ."AND (lease_until IS NULL OR lease_until<NOW()) ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $cancel=$pdo->prepare(
        "UPDATE sharky_outbox SET status='CANCELLED',last_error='REPLACED_BY_LEARN_REENGAGEMENT_20260914',lease_until=NULL,owner_token=NULL "
        ."WHERE id=:id AND status='PENDING' AND (lease_until IS NULL OR lease_until<NOW())"
    );
    foreach($oldRows as $row){
        $payload=hache_sharky_outbox_decrypt($row);if(!is_array($payload))continue;
        $meta=$payload['_sharky_followup']??null;if(!is_array($meta)||(int)($meta['stage']??0)!==3)continue;
        if((string)($payload['template']['name']??'')!==HACHE_SHARKY_FOLLOWUP_RESUME_TEMPLATE)continue;
        $cancel->execute([':id'=>(int)$row['id']]);
        $stats['old_pending_template_cancelled']+=(int)$cancel->rowCount();
    }

    $since=$now-HACHE_SHARKY_LEARN_BACKFILL_WINDOW_SECONDS;
    $st=$pdo->prepare(
        'SELECT contact_hash,message_id,payload_ciphertext,payload_iv,payload_tag,UNIX_TIMESTAMP(received_at) received_ts '
        .'FROM sharky_message_receipts WHERE received_at>=FROM_UNIXTIME(:since) AND payload_ciphertext IS NOT NULL AND processed_at IS NOT NULL '
        .'ORDER BY received_at ASC,message_id ASC'
    );
    $st->execute([':since'=>$since]);
    $contacts=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $stats['receipt_rows_scanned']++;
        $event=hache_sharky_inbox_decrypt($row);
        if(!is_array($event)){$stats['excluded']['decrypt_failed']++;continue;}
        $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
        if($contact===''||!hash_equals((string)($row['contact_hash']??''),hache_sharky_orchestrator_contact_hash($contact))){
            $stats['excluded']['invalid_contact']++;
            continue;
        }
        $eventAt=(int)floor(((int)($event['timestamp_ms']??0))/1000);
        if($eventAt<=0)$eventAt=(int)($row['received_ts']??0);
        $key=(string)$row['contact_hash'];
        if(!isset($contacts[$key]))$contacts[$key]=[
            'contact'=>$contact,'latest_at'=>0,'latest_text'=>'','latest_program'=>null,'latest_program_at'=>0,
        ];
        if($eventAt>=(int)$contacts[$key]['latest_at']){
            $contacts[$key]['latest_at']=$eventAt;
            $contacts[$key]['latest_text']=trim((string)($event['text']??''));
        }
        $id=strtolower(trim((string)($event['interactive_id']??'')));
        if(in_array($id,['meta:program:learn','meta:program:regular'],true)&&$eventAt>=(int)$contacts[$key]['latest_program_at']){
            $contacts[$key]['latest_program']=$id==='meta:program:learn'?'learn':'regular';
            $contacts[$key]['latest_program_at']=$eventAt;
        }
    }
    $stats['contacts_seen']=count($contacts);

    foreach($contacts as $contactHash=>$candidate){
        if(($candidate['latest_program']??null)!=='learn'){
            $stats['excluded']['latest_program_not_learn']++;
            continue;
        }
        $stats['learn_candidates']++;
        $contact=(string)$candidate['contact'];
        $normalized=hache_sharky_contact_book_normalize_phone($contact);
        if($normalized===null){$stats['excluded']['invalid_contact']++;continue;}
        $identity=hache_sharky_contact_book_identity($pdo,$normalized['e164']);
        if(($identity['role']??'')!=='PROSPECT'){$stats['excluded']['not_current_prospect']++;continue;}
        $registered=hache_sharky_followup_registered_contact($pdo,$contact);
        if($registered!==false){$stats['excluded']['registered']++;continue;}
        $latestAt=(int)$candidate['latest_at'];
        if(hache_sharky_followup_newer_inbound_pending($pdo,$contact,$latestAt)){
            $stats['excluded']['pending_inbound']++;
            continue;
        }
        try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){$stats['excluded']['state_unavailable']++;continue;}
        if(($state['identity']['kind']??'')!=='prospect'){$stats['excluded']['state_not_current_prospect']++;continue;}
        $userTurnAt=(int)($state['updated_at']??0);
        if($userTurnAt<=0||$userTurnAt<$since){$stats['excluded']['state_too_old']++;continue;}
        $state['commercial_context']['program_button_choice']='learn';
        $state['commercial_context']['program_button_choice_at']=(int)$candidate['latest_program_at'];
        // The accepted learn button always resolves to intensive in Sharky 3.0.
        if(($state['commercial_context']['program']??null)===null)$state['commercial_context']['program']='intensive';
        if(hache_sharky_followup_user_opted_out((string)($state['last_user_text']??$candidate['latest_text']))){
            $stats['excluded']['opted_out_or_deferred']++;
            continue;
        }
        if(!hache_sharky_followup_learn_reengagement_eligible($state)){
            $stats['excluded']['context_not_eligible']++;
            continue;
        }
        $existing=hache_sharky_followup_state($state);
        if((int)($existing['next_stage']??0)===3&&str_starts_with((string)($existing['status']??''),'reengagement')){
            if(hache_sharky_learn_reengagement_live_row_exists($pdo,$contact)){
                $stats['excluded']['already_scheduled']++;
                continue;
            }
            // A legacy row may have been cancelled above while its durable state
            // still says stage 3 is armed. Requeue with the new template/token.
            $stats['legacy_state_requeued']++;
        }
        $meta=hache_sharky_followup_arm_meta($state,$contact,'learn-backfill-20260914',3);
        if(!is_array($meta)){$stats['excluded']['queue_failed']++;continue;}
        $token=(string)$meta['token'];

        $started=false;
        try{
            if(!$pdo->inTransaction()){$pdo->beginTransaction();$started=true;}
            if(!hache_sharky_followup_schedule_learn_reengagement($pdo,$contact,$state,$token,$userTurnAt,$now)){
                if($started&&$pdo->inTransaction())$pdo->rollBack();
                $stats['excluded']['queue_failed']++;
                continue;
            }
            if($started&&$pdo->inTransaction())$pdo->commit();
            $stats['scheduled']++;
        }catch(Throwable $e){
            if($started&&$pdo->inTransaction())$pdo->rollBack();
            $stats['excluded']['queue_failed']++;
        }
    }

    return $stats;
}

if(PHP_SAPI==='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    if(!in_array(HACHE_SHARKY_LEARN_BACKFILL_FLAG,$argv,true)){
        fwrite(STDERR,"This one-shot command requires ".HACHE_SHARKY_LEARN_BACKFILL_FLAG."\n");
        exit(2);
    }
    try{
        fwrite(STDOUT,json_encode(hache_sharky_learn_reengagement_backfill_once(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
        exit(0);
    }catch(Throwable $e){
        fwrite(STDERR,'Sharky learn re-engagement backfill: '.$e->getMessage().PHP_EOL);
        exit(1);
    }
}
