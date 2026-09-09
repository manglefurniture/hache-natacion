from pathlib import Path
import re

def replace_once(path, old, new, label):
    p=Path(path); s=p.read_text(); n=s.count(old)
    if n!=1: raise SystemExit(f"{label}: expected 1 match, got {n}")
    p.write_text(s.replace(old,new,1))

def sub_once(path, pattern, repl, label):
    p=Path(path); s=p.read_text()
    out,n=re.subn(pattern,repl,s,count=1,flags=re.S)
    if n!=1: raise SystemExit(f"{label}: expected 1 match, got {n}")
    p.write_text(out)

replace_once(
    "config/sharky-whatsapp-adapter.php",
    "require_once __DIR__.'/sharky-orchestrator-db.php';\nrequire_once __DIR__.'/sharky-commercial-memory.php';",
    "require_once __DIR__.'/sharky-orchestrator-db.php';\nrequire_once __DIR__.'/sharky-commercial-memory.php';\nrequire_once __DIR__.'/sharky-deterministic-replies.php';",
    "deterministic include"
)

new_block=r'''function hache_sharky_whatsapp_intensive_information_message(array $state,string $prefix='Perfecto.'): string
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $sede=(string)($commercial['sede_clave']??'');
    if(($commercial['program']??null)!=='intensive'||!in_array($sede,['MONTEVERDE','PALAPAS'],true)){
        return hache_sharky_whatsapp_commercial_ready_message($state,$prefix);
    }
    $sedeLabel=hache_sharky_whatsapp_venue_label($sede);
    $pdo=hache_sharky_pdo();
    $business=hache_sharky_business_values($pdo instanceof PDO?$pdo:null);
    $selected=$commercial['course_price']??($state['selected_course_price']??null);
    $price=is_numeric($selected)?(float)$selected:(float)hache_sharky_config_int($business,'sharky_precio_intensivo',1200,0,100000);
    $priceText=number_format($price,0,'.',',');
    $message=rtrim($prefix).' En '.$sedeLabel.', el curso intensivo tiene un precio total de $'.$priceText.' MXN. Es un solo pago por el curso completo.';
    $hours=[];
    if($pdo instanceof PDO){
        try{$hours=hache_sharky_deterministic_active_schedules($pdo,'intensive',$sede);}catch(Throwable $ignored){}
    }
    if($hours){
        $message.="\n\nHorarios disponibles:\n".implode("\n",array_map(static fn(string $hour):string=>'• '.$hour,$hours));
    }elseif($pdo instanceof PDO){
        $message.="\n\nAhora mismo no encuentro horarios activos para esta sede.";
    }else{
        $message.="\n\nNo pude consultar los horarios activos en este momento. Prefiero no inventarte datos.";
    }
    return $message;
}

function hache_sharky_whatsapp_commercial_next_action(array $state,string $prefix='Perfecto.'): array
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(($commercial['program']??null)==='intensive'){
        $message=hache_sharky_whatsapp_intensive_information_message($state,$prefix);
        return hache_sharky_orchestrator_decision('commercial_next_action',$message,['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('action:register_intensive','Inscribirme'),
            hache_sharky_orchestrator_button('flow:pause','No por el momento'),
        ]]);
    }
    $program='clases regulares';
    $sede=hache_sharky_whatsapp_venue_label((string)($commercial['sede_clave']??''));
    $age=is_int($commercial['age']??null)?' para una persona de '.(int)$commercial['age'].' años':'';
    $message=rtrim($prefix).' Ya tengo: '.$program.' en '.$sede.$age.'. ¿Qué quieres ver ahora?';
    return hache_sharky_orchestrator_decision('commercial_next_action',$message,['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('action:commercial_schedules','Horarios'),
        hache_sharky_orchestrator_button('action:commercial_price','Precio'),
        hache_sharky_orchestrator_button('action:human','Inscribirme'),
    ]]);
}

function hache_sharky_whatsapp_registration_form_from_context(array $state,array $context,int $now,string $prefix='Perfecto.'): array
{
    if(!hache_sharky_whatsapp_commercial_ready($state)||($state['commercial_context']['program']??null)!=='intensive'){
        return [$state,hache_sharky_orchestrator_decision('conversation',hache_sharky_whatsapp_commercial_ready_message($state,$prefix))];
    }
    $sede=(string)($state['commercial_context']['sede_clave']??'');
    foreach(['course_id','fecha_inicio','course_price','schedule_id','schedule_label'] as $key)unset($state['commercial_context'][$key]);
    [$state,$decision]=hache_sharky_orchestrator_registration_course_step($state,['sede_clave'=>$sede],$context,$now);
    if(($decision['kind']??'')==='registration_course'){
        $decision['message']=rtrim($prefix).' Completa en el formulario la fecha de inicio, el horario y tus datos para inscribirte.';
    }
    return [$state,$decision];
}

function hache_sharky_whatsapp_registration_offer_from_context'''

sub_once(
    "config/sharky-whatsapp-adapter.php",
    r"function hache_sharky_whatsapp_commercial_next_action\(array \$state,string \$prefix='Perfecto\.'\): array\n\{.*?\n\}\n\nfunction hache_sharky_whatsapp_registration_offer_from_context",
    new_block,
    "commercial next action"
)

marker="        if(trim((string)($event['interactive_id']??''))===''&&!is_array($state['flow']??null)&&hache_sharky_whatsapp_commercial_ready($state)&&($state['commercial_context']['program']??null)==='intensive'&&hache_sharky_whatsapp_offer_affirmation((string)($event['text']??''))){"
direct=r'''        if(trim((string)($event['interactive_id']??''))==='action:register_intensive'
            &&!is_array($state['flow']??null)
            &&($state['identity']['kind']??'')==='prospect'
            &&hache_sharky_whatsapp_commercial_ready($state)
            &&($state['commercial_context']['program']??null)==='intensive'){
            [$state,$decision]=hache_sharky_whatsapp_registration_form_from_context($state,$context,(int)($context['now']??time()),'Perfecto.');
            hache_sharky_db_state_save($pdo,$contact,$state);
            hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];
        }

'''
replace_once("config/sharky-whatsapp-adapter.php",marker,direct+marker,"direct form intercept")
