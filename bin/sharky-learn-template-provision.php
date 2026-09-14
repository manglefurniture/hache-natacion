<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator-store.php';
require_once __DIR__.'/../config/sharky-followup.php';

const HACHE_SHARKY_LEARN_TEMPLATE_ENSURE_FLAG='--ensure-approved-20260914';
const HACHE_SHARKY_LEARN_TEMPLATE_STATUS_FLAG='--status';

/** @return array{status:int,json:?array,error:string} */
function hache_sharky_learn_template_graph(string $method,string $path,string $token,string $version,array $query=[],?array $body=null): array
{
    $path='/'.ltrim($path,'/');
    $url='https://graph.facebook.com/'.rawurlencode($version).$path;
    if($query!==[])$url.='?'.http_build_query($query);
    $ch=curl_init($url);
    if($ch===false)return ['status'=>0,'json'=>null,'error'=>'curl_init_failed'];
    $headers=['Authorization: Bearer '.$token,'Accept: application/json'];
    $opts=[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>25,
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$headers,
    ];
    if($body!==null){
        $json=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($json===false){curl_close($ch);return ['status'=>0,'json'=>null,'error'=>'json_encode_failed'];}
        $headers[]='Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER]=$headers;
        $opts[CURLOPT_POSTFIELDS]=$json;
    }
    curl_setopt_array($ch,$opts);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $curlError=curl_error($ch);
    curl_close($ch);
    $decoded=is_string($raw)?json_decode($raw,true):null;
    $message='';
    if(is_array($decoded)&&is_array($decoded['error']??null)){
        $code=(string)($decoded['error']['code']??'');
        $type=(string)($decoded['error']['type']??'');
        $message=trim(($type!==''?$type.' ':'').($code!==''?'#'.$code.' ':'').(string)($decoded['error']['message']??''));
    }elseif($curlError!=='')$message=$curlError;
    return ['status'=>$status,'json'=>is_array($decoded)?$decoded:null,'error'=>mb_substr($message,0,300)];
}

function hache_sharky_learn_template_version(): string
{
    $version=trim(hache_sharky_orchestrator_secret('WHATSAPP_GRAPH_VERSION'));
    return preg_match('/^v\d+\.\d+$/',$version)===1?$version:'v26.0';
}

/** @return list<array<string,mixed>> */
function hache_sharky_learn_template_data(array $response): array
{
    $data=$response['json']['data']??null;
    return is_array($data)?array_values(array_filter($data,'is_array')):[];
}

function hache_sharky_learn_template_discover_waba(string $token,string $phoneId,string $version): string
{
    foreach(['WHATSAPP_BUSINESS_ACCOUNT_ID','WHATSAPP_WABA_ID','WABA_ID'] as $key){
        $candidate=preg_replace('/\D+/','',hache_sharky_orchestrator_secret($key))?:'';
        if($candidate!=='')return $candidate;
    }

    // Some Graph versions expose the parent account from the phone-number node.
    $phone=hache_sharky_learn_template_graph('GET','/'.$phoneId,$token,$version,['fields'=>'id,whatsapp_business_account']);
    if($phone['status']>=200&&$phone['status']<300){
        $candidate=preg_replace('/\D+/','',(string)($phone['json']['whatsapp_business_account']['id']??$phone['json']['whatsapp_business_account']??''))?:'';
        if($candidate!=='')return $candidate;
    }

    // Portable fallback for system-user tokens: enumerate accessible businesses,
    // then match the configured phone number against each owned WABA.
    $businesses=hache_sharky_learn_template_graph('GET','/me/businesses',$token,$version,['fields'=>'id','limit'=>100]);
    if($businesses['status']<200||$businesses['status']>=300)return '';
    foreach(hache_sharky_learn_template_data($businesses) as $business){
        $businessId=preg_replace('/\D+/','',(string)($business['id']??''))?:'';if($businessId==='')continue;
        $accounts=hache_sharky_learn_template_graph('GET','/'.$businessId.'/owned_whatsapp_business_accounts',$token,$version,['fields'=>'id','limit'=>100]);
        if($accounts['status']<200||$accounts['status']>=300)continue;
        foreach(hache_sharky_learn_template_data($accounts) as $account){
            $waba=preg_replace('/\D+/','',(string)($account['id']??''))?:'';if($waba==='')continue;
            $phones=hache_sharky_learn_template_graph('GET','/'.$waba.'/phone_numbers',$token,$version,['fields'=>'id','limit'=>100]);
            if($phones['status']<200||$phones['status']>=300)continue;
            foreach(hache_sharky_learn_template_data($phones) as $phoneRow){
                if(hash_equals($phoneId,preg_replace('/\D+/','',(string)($phoneRow['id']??''))?:''))return $waba;
            }
        }
    }
    return '';
}

function hache_sharky_learn_template_body(array $template): string
{
    foreach(($template['components']??[]) as $component){
        if(!is_array($component)||strtoupper((string)($component['type']??''))!=='BODY')continue;
        return trim((string)($component['text']??''));
    }
    return '';
}

/** @return array<string,mixed>|null */
function hache_sharky_learn_template_find(string $waba,string $token,string $version): ?array
{
    $response=hache_sharky_learn_template_graph('GET','/'.$waba.'/message_templates',$token,$version,[
        'name'=>HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE,
        'fields'=>'id,name,status,category,language,components',
        'limit'=>100,
    ]);
    if($response['status']<200||$response['status']>=300)throw new RuntimeException('No se pudo consultar plantillas Meta: '.($response['error']!==''?$response['error']:'HTTP '.$response['status']));
    foreach(hache_sharky_learn_template_data($response) as $template){
        if((string)($template['name']??'')===HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE&&(string)($template['language']??'')==='es_MX')return $template;
    }
    return null;
}

/** @return array<string,mixed> */
function hache_sharky_learn_template_run(bool $ensure): array
{
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL no está disponible');
    $token=trim(hache_sharky_orchestrator_secret('WHATSAPP_ACCESS_TOKEN'));
    $phoneId=preg_replace('/\D+/','',hache_sharky_orchestrator_secret('WHATSAPP_PHONE_NUMBER_ID'))?:'';
    if($token===''||$phoneId==='')throw new RuntimeException('Credenciales de WhatsApp no disponibles');
    $version=hache_sharky_learn_template_version();
    $waba=hache_sharky_learn_template_discover_waba($token,$phoneId,$version);
    if($waba==='')throw new RuntimeException('No se pudo resolver el WhatsApp Business Account autorizado');

    $existing=hache_sharky_learn_template_find($waba,$token,$version);
    if(is_array($existing)){
        $exact=hash_equals(HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE_BODY,hache_sharky_learn_template_body($existing));
        if(!$exact)throw new RuntimeException('Ya existe la plantilla con el mismo nombre pero con un BODY distinto; no se modifica automáticamente');
        return [
            'ok'=>true,'action'=>'existing','name'=>HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE,
            'language'=>'es_MX','category'=>(string)($existing['category']??''),'status'=>(string)($existing['status']??'UNKNOWN'),
            'body_exact'=>true,
        ];
    }
    if(!$ensure)return [
        'ok'=>true,'action'=>'absent','name'=>HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE,'language'=>'es_MX','status'=>'ABSENT','body_exact'=>false,
    ];

    $create=hache_sharky_learn_template_graph('POST','/'.$waba.'/message_templates',$token,$version,[],[
        'name'=>HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE,
        'language'=>'es_MX',
        'category'=>'MARKETING',
        'components'=>[['type'=>'BODY','text'=>HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE_BODY]],
    ]);
    if($create['status']<200||$create['status']>=300){
        throw new RuntimeException('Meta rechazó la creación de la plantilla: '.($create['error']!==''?$create['error']:'HTTP '.$create['status']));
    }
    $created=hache_sharky_learn_template_find($waba,$token,$version);
    return [
        'ok'=>true,'action'=>'created','name'=>HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE,'language'=>'es_MX',
        'category'=>(string)($created['category']??'MARKETING'),'status'=>(string)($created['status']??$create['json']['status']??'PENDING'),
        'body_exact'=>is_array($created)&&hash_equals(HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE_BODY,hache_sharky_learn_template_body($created)),
    ];
}

if(PHP_SAPI==='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $ensure=in_array(HACHE_SHARKY_LEARN_TEMPLATE_ENSURE_FLAG,$argv,true);
    $status=in_array(HACHE_SHARKY_LEARN_TEMPLATE_STATUS_FLAG,$argv,true);
    if(!$ensure&&!$status){
        fwrite(STDERR,"Use ".HACHE_SHARKY_LEARN_TEMPLATE_ENSURE_FLAG." o ".HACHE_SHARKY_LEARN_TEMPLATE_STATUS_FLAG."\n");
        exit(2);
    }
    try{
        $result=hache_sharky_learn_template_run($ensure);
        fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
        exit(0);
    }catch(Throwable $e){
        fwrite(STDERR,'Sharky Meta template: '.$e->getMessage().PHP_EOL);
        exit(1);
    }
}
