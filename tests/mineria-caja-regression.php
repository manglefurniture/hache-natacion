<?php
declare(strict_types=1);
require_once __DIR__.'/../config/mineria-caja.php';
function check(bool $ok,string $msg): void {if(!$ok)throw new RuntimeException($msg);}
function item(string $type,string $amount,string $rid,string $note=''): array{
    return ['type'=>$type,'amount'=>$amount,'date'=>'2026-10-08','note'=>$note,'request_id'=>$rid];
}
$dir=sys_get_temp_dir().'/mineria-caja-test-'.bin2hex(random_bytes(6));
if(!mkdir($dir,0700))throw new RuntimeException('mkdir');
$file=$dir.'/ledger.json';putenv('MINERIA_CAJA_FILE='.$file);
try{
  check(mineria_caja_read()['entries']===[],'No inicia vacío');
  $a=mineria_caja_add(item('opening_wallet','0.00','opening-wallet-12345'));
  mineria_caja_add(item('opening_salad','10.00','opening-salad-12345'));
  mineria_caja_add(item('sale','26.00','sale-123456789012','20 PRL'));
  mineria_caja_add(item('fee','1.03','fees-123456789012'));
  mineria_caja_add(item('transfer_salad','20','transfer-12345678901'));
  mineria_caja_add(item('usage','3.12','usage-123456789012'));
  mineria_caja_add(item('withdrawal','2','withdraw-12345678901'));
  $dup=mineria_caja_add(item('opening_wallet','0.00','opening-wallet-12345'));
  check($dup['id']===$a['id'],'Reintento duplica el movimiento');
  $entries=mineria_caja_read()['entries'];check(count($entries)===7,'Cantidad incorrecta');
  $s=mineria_caja_summary($entries);
  check($s['wallet_cents']===297,'Wallet incorrecta');
  check($s['salad_cents']===2688,'Salad incorrecta');
  check($s['combined_cents']===2985,'Transferencia generó dinero o gasto');
  check($s['sale_cents']===2600,'Ingreso incorrecto');
  check($s['usage_cents']===312&&$s['fees_cents']===103,'Gastos incorrectos');
  check($s['operating_result_cents']===2185,'Resultado incluye movimiento que no es ingreso/gasto');
  check($s['has_opening_wallet']&&$s['has_opening_salad'],'Falta apertura');
  check((fileperms($file)&0777)===0600,'Permisos débiles');
  foreach([['amount'=>'1e5'],['amount'=>'-1'],['amount'=>'1.239'],['amount'=>'nan'],['amount'=>'0.00']] as $t){
    try{mineria_caja_validate(array_merge(item('sale','1','sale-invalid-123456'),$t));throw new RuntimeException('Monto inválido aceptado');}
    catch(InvalidArgumentException $e){}
  }
  try{mineria_caja_add(item('opening_wallet','1','opening-again-12345'));throw new RuntimeException('Apertura duplicada aceptada');}
  catch(InvalidArgumentException $e){}
  try{mineria_caja_add(item('sale','27','sale-123456789012'));throw new RuntimeException('Colisión de id aceptada');}
  catch(InvalidArgumentException $e){}
  try{mineria_caja_validate(item('usage','1','short'));throw new RuntimeException('Id débil aceptado');}
  catch(InvalidArgumentException $e){}
  $t=strtotime('2026-10-08T15:00:00Z');
  $valid=['observed_at'=>'2026-10-08T15:00:00Z','groups'=>[
    ['group'=>'prl-low-4070','stale'=>false,'metrics'=>['gpu'=>'RTX 4070 Ti SUPER'],
     'instances'=>[['state'=>'running','ready'=>true,'started'=>true],['state'=>'allocating','ready'=>false,'started'=>false]]]
  ]];
  $estimate=mineria_caja_operational($valid,$t);
  check($estimate['available']&&$estimate['running_4070_low']===1&&abs($estimate['daily_usd_estimate']-3.12)<0.001,'Estimación cuenta allocating');
  $old=mineria_caja_operational($valid,$t+1000);check(!$old['available'],'Estimación stale aceptada');
  $valid['groups'][0]['stale']=true;
  check(!mineria_caja_operational($valid,$t)['available'],'Grupo stale aceptado');
  echo "MINERIA_CAJA_REGRESSION_OK\n";
}finally{
  @unlink($file);@unlink($file.'.lock');@rmdir($dir);putenv('MINERIA_CAJA_FILE');
}
