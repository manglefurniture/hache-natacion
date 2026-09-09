from pathlib import Path
import re

p=Path("tests/sharky-commercial-next-action-regression.php")
s=p.read_text()
old="array_column($menu['ui']['buttons']??[],'id')===['action:commercial_schedules','action:commercial_price','action:register_intensive'],\n    'Intensive menu must expose Horarios, Precio and Inscribirme in that order.'"
new="array_column($menu['ui']['buttons']??[],'id')===['action:register_intensive','flow:pause'],\n    'Intensive information block must expose only Inscribirme and No por el momento.'"
if s.count(old)!=1: raise SystemExit("commercial buttons mismatch")
s=s.replace(old,new,1)
old="commercial_next_ok(count($payload['interactive']['action']['buttons']??[])===3,'Intensive menu must render exactly three buttons.');"
new="commercial_next_ok(count($payload['interactive']['action']['buttons']??[])===2,'Intensive information block must render exactly two buttons.');\ncommercial_next_ok(str_contains((string)($menu['message']??''),'precio total')&&str_contains((string)($menu['message']??''),'un solo pago'),'Intensive venue completion must provide price automatically.');"
if s.count(old)!=1: raise SystemExit("commercial count mismatch")
s=s.replace(old,new,1)
needle="commercial_next_ok(hache_sharky_orchestrator_intent('Inscribirme','action:register_intensive')==='register_intensive','Registration action must keep the existing controlled registration intent.');"
if s.count(needle)!=1: raise SystemExit("form insertion mismatch")
extra=needle+r'''

$formContext=['intensive_options'=>[
    ['id'=>'c1','sede_clave'=>'PALAPAS','fecha_inicio'=>'2026-09-14','precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']]],
    ['id'=>'c2','sede_clave'=>'PALAPAS','fecha_inicio'=>'2026-09-21','precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']]],
]];
[$formState,$formDecision]=hache_sharky_whatsapp_registration_form_from_context($state,$formContext,1788460005);
commercial_next_ok(($formState['flow']['name']??null)==='register_intensive'&&($formState['flow']['step']??null)==='course','Inscribirme must enter the existing course step directly, without another consent question.');
commercial_next_ok(($formDecision['ui']['type']??null)==='list','Direct registration must emit the existing date list for WhatsApp Flow upgrade.');'''
s=s.replace(needle,extra,1)
p.write_text(s)

p=Path("tests/sharky-followup-regression.php")
s=p.read_text()
old="followup_ok(array_column(array_map(static fn(array $b):array=>$b['reply'],$p1['interactive']['action']['buttons']??[]),'id')===['action:register_intensive','action:commercial_schedules','action:commercial_price'],'Intensive first follow-up must offer registration, schedules and price.');"
new="followup_ok(array_column(array_map(static fn(array $b):array=>$b['reply'],$p1['interactive']['action']['buttons']??[]),'id')===['action:register_intensive','flow:pause'],'Intensive first follow-up must offer registration or durable pause.');"
if s.count(old)!=1: raise SystemExit("followup p1 mismatch")
s=s.replace(old,new,1)
old="followup_ok(count($p2['interactive']['action']['buttons']??[])===2,'Second follow-up must be softer and avoid repeating the registration push.');"
new="followup_ok(array_column(array_map(static fn(array $b):array=>$b['reply'],$p2['interactive']['action']['buttons']??[]),'id')===['action:register_intensive','flow:pause'],'Second intensive follow-up must keep registration/pause choice.');"
if s.count(old)!=1: raise SystemExit("followup p2 mismatch")
s=s.replace(old,new,1)
p.write_text(s)

p=Path("tests/sharky-batch-context-regression.php")
s=p.read_text()
old="batch_context_ok(str_contains((string)($venueChangedDecision['message']??''),'Palapas Protudec')&&str_contains((string)($venueChangedDecision['message']??''),'¿Qué quieres ver ahora?'),'Venue correction must acknowledge the new venue and ask what the customer wants next.');"
if s.count(old)==1:
    s=s.replace(old,"batch_context_ok(str_contains((string)($venueChangedDecision['message']??''),'Palapas Protudec')&&str_contains((string)($venueChangedDecision['message']??''),'precio total'),'Venue correction must acknowledge the new venue and show the intensive information block.');",1)
pattern=r"batch_context_ok\(in_array\('action:commercial_schedules',\$venueButtons,true\)&&in_array\('action:commercial_price',\$venueButtons,true\)&&in_array\('action:register_intensive',\$venueButtons,true\),'Intensive venue correction must offer schedules, price and registration\.'\);"
s,n=re.subn(pattern,"batch_context_ok($venueButtons===['action:register_intensive','flow:pause'],'Intensive venue correction must offer only registration or deferral after the information block.');",s,count=1)
if n!=1: raise SystemExit(f"batch venue buttons mismatch: {n}")
p.write_text(s)
