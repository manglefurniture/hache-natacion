<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-product-boundary-guard.php';
require_once __DIR__.'/../config/sharky-post-pr72.php';

function priority_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY QUALIFICATION PRIORITY FAIL: $message\n");exit(1);}
}

$now=1789136400;
$pdo=new PDO('sqlite::memory:');

$base=hache_sharky_orchestrator_state(null,$now);
$base['identity']=array_replace($base['identity'],['kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched']);
$base['commercial_context']['swim_level']='beginner';
$base['commercial_context']['program']='intensive';
$base['commercial_context']['recommended_program']='intensive';
$base['commercial_context']['sede_clave']='PALAPAS';

$conflict=hache_sharky_whatsapp_swim_level_conflict($base,['text'=>'Ya sé nadar','interactive_id'=>''],$now+1);
priority_ok(is_array($conflict),'A contradictory free-text swim claim must be intercepted deterministically.');
[$conflictState,$conflictDecision]=$conflict;
priority_ok(($conflictDecision['kind']??null)==='prospect_swim_conflict','Contradictory swim claims must use the explicit conflict decision.');
priority_ok(($conflictState['flow']['step']??null)==='swim_conflict','Contradiction must freeze qualification on a dedicated clarification cursor.');
priority_ok(!isset($conflictState['commercial_context']['swim_level']),'Neither contradictory swim claim may silently win before clarification.');
priority_ok(!isset($conflictState['commercial_context']['program']),'Contradiction must invalidate the active product intention.');
priority_ok(($conflictState['commercial_context']['sede_clave']??null)==='PALAPAS','A previously explicit venue preference must survive a level contradiction.');
priority_ok(array_column($conflictDecision['ui']['buttons']??[],'id')===['qualify:swims','qualify:beginner'],'Clarification must offer only the two level answers.');

[$clarified,$backgroundDecision]=hache_sharky_whatsapp_qualification_input($pdo,$conflictState,[
    'text'=>'Ya sé nadar','interactive_id'=>'qualify:swims',
],$now+2,12);
priority_ok(($clarified['commercial_context']['swim_level']??null)==='swims','Explicit clarification must establish the selected swim level.');
priority_ok(($clarified['flow']['step']??null)==='background','A swimmer still needs training-history qualification before product resolution.');
priority_ok(array_column($backgroundDecision['ui']['buttons']??[],'id')===['qualify:formal','qualify:self'],'Swimmer clarification must continue to formal-vs-self-taught history.');

[$formal,$formalDecision]=hache_sharky_whatsapp_qualification_input($pdo,$clarified,[
    'text'=>'He tomado clases','interactive_id'=>'qualify:formal',
],$now+3,12);
priority_ok(($formal['commercial_context']['background']??null)==='formal','Formal history must persist.');
priority_ok(($formal['commercial_context']['program']??null)==='regular','Swimmer with formal lessons must map only to regular classes.');
priority_ok(($formal['flow']??null)===null,'A previously confirmed Palapas venue must not be asked again after formal qualification.');
priority_ok(($formalDecision['kind']??null)==='commercial_next_action','Known venue plus canonical regular product must continue commercially.');

$fresh=$base;
$fresh['commercial_context']=[];
$fresh=hache_sharky_orchestrator_flow($fresh,'qualify_prospect','swim',[],$now+4);
[$beginner,$beginnerDecision]=hache_sharky_whatsapp_qualification_input($pdo,$fresh,[
    'text'=>'Desde cero','interactive_id'=>'qualify:beginner',
],$now+5,12);
priority_ok(($beginner['commercial_context']['program']??null)==='intensive','Beginner must map only to intensive.');
priority_ok(($beginner['flow']['step']??null)==='sede','Beginner with no venue must proceed to venue proposal.');
priority_ok(($beginner['flow']['data']['venue_proposal']??null)==='MONTEVERDE','The first venue proposal must be Monteverde.');
priority_ok(str_contains((string)($beginnerDecision['message']??''),'Te propongo primero Colegio Monteverde'),'Venue copy must explicitly propose Monteverde first.');
priority_ok(array_column($beginnerDecision['ui']['buttons']??[],'id')===['sede:monteverde','sede:palapas'],'Venue proposal must keep Palapas as the immediate alternative.');

$staleRegular=$fresh;
$staleRegular['commercial_context']=array_replace($staleRegular['commercial_context'],[
    'program'=>'regular','recommended_program'=>'regular','background'=>'formal','plan_id'=>'r3','plan_name'=>'Regular 3','sessions_per_week'=>3,'plan_price'=>1000,
]);
$staleRegular=hache_sharky_orchestrator_flow($staleRegular,'qualify_prospect','swim',[],$now+5);
[$staleBeginner,$staleBeginnerDecision]=hache_sharky_whatsapp_qualification_input($pdo,$staleRegular,[
    'text'=>'Desde cero','interactive_id'=>'qualify:beginner',
],$now+6,12);
priority_ok(($staleBeginner['commercial_context']['swim_level']??null)==='beginner','A new beginner selection must become authoritative even over stale pre-qualification context.');
priority_ok(($staleBeginner['commercial_context']['program']??null)==='intensive','A beginner selection must overwrite a stale regular program before advancing.');
priority_ok(($staleBeginner['commercial_context']['recommended_program']??null)==='intensive','A beginner selection must overwrite a stale regular recommendation.');
priority_ok(!isset($staleBeginner['commercial_context']['background']),'A beginner selection must invalidate stale formal-history context.');
priority_ok(!isset($staleBeginner['commercial_context']['plan_id']),'A beginner selection must discard stale regular-plan state.');
priority_ok(($staleBeginner['flow']['step']??null)==='sede','Repaired beginner state must continue only to venue selection.');
priority_ok(($staleBeginner['flow']['data']['venue_proposal']??null)==='MONTEVERDE','Repaired beginner state must retain the Monteverde-first venue rule.');
priority_ok(array_column($staleBeginnerDecision['ui']['buttons']??[],'id')===['sede:monteverde','sede:palapas'],'Repaired beginner state must expose only venue controls, never regular-product continuation.');

[$palapas,$palapasDecision]=hache_sharky_whatsapp_qualification_input($pdo,$beginner,[
    'text'=>'No','interactive_id'=>'',
],$now+6,12);
priority_ok(($palapas['commercial_context']['sede_clave']??null)==='PALAPAS','Rejecting the explicit Monteverde proposal must select Palapas.');
priority_ok(($palapas['flow']??null)===null,'Palapas rejection path must continue instead of asking Monteverde again.');
priority_ok(($palapasDecision['kind']??null)==='commercial_next_action','Palapas fallback must reach the normal commercial continuation.');

$selfState=$fresh;
$selfState['commercial_context']['swim_level']='swims';
$selfState=hache_sharky_orchestrator_flow($selfState,'qualify_prospect','background',[],$now+7);
[$self,$selfDecision]=hache_sharky_whatsapp_qualification_input($pdo,$selfState,[
    'text'=>'Por mi cuenta','interactive_id'=>'qualify:self',
],$now+8,12);
priority_ok(($self['commercial_context']['background']??null)==='self_taught','Self-taught background must persist.');
priority_ok(($self['commercial_context']['program']??null)==='intensive','Self-taught swimmer must remain intensive-only.');
priority_ok(($self['flow']['data']['venue_proposal']??null)==='MONTEVERDE','Self-taught intensive path must use the same Monteverde-first venue rule.');

$legacy=$fresh;
$legacy['commercial_context']['swim_level']='swims';
$legacy['commercial_context']['background']='formal';
$legacy=hache_sharky_orchestrator_flow($legacy,'qualify_prospect','program',['background'=>'formal'],$now+9);
[$legacyAfter,$legacyDecision]=hache_sharky_whatsapp_qualification_input($pdo,$legacy,[
    'text'=>'Intensivo','interactive_id'=>'qualify:intensive',
],$now+10,12);
priority_ok(($legacyAfter['commercial_context']['program']??null)==='regular','A stale legacy product cursor must not bypass formal-swimmer regular eligibility.');
priority_ok(($legacyAfter['flow']['step']??null)==='sede','Legacy product cursor must converge on venue selection after canonicalizing regular.');
priority_ok(array_column($legacyDecision['ui']['buttons']??[],'id')===['sede:monteverde','sede:palapas'],'Legacy cursor must converge on the venue UI, not reopen product choice.');

$reverse=$base;
$reverse['commercial_context']['swim_level']='swims';
$reverse['commercial_context']['background']='formal';
$reverse['commercial_context']['program']='regular';
$reverseConflict=hache_sharky_whatsapp_swim_level_conflict($reverse,['text'=>'No sé nadar, empiezo desde cero','interactive_id'=>''],$now+11);
priority_ok(is_array($reverseConflict),'Reverse swim-level contradiction must also freeze instead of silently switching products.');

$sanitized=hache_sharky_product_boundary_sanitize_state([
    'identity'=>['kind'=>'prospect'],
    'commercial_context'=>['swim_level'=>'swims','background'=>'formal','program'=>'intensive','course_id'=>'old-course'],
]);
priority_ok(($sanitized['commercial_context']['program']??null)==='regular','Final product boundary must repair stale formal+intensive state to regular.');
priority_ok(!isset($sanitized['commercial_context']['course_id']),'Formal-to-regular repair must discard intensive-only course selection.');

$policy=hache_sharky_post72_whatsapp_style_policy();
priority_ok(str_contains($policy,'el producto automático es clases regulares'),'Brain policy must mirror formal-swimmer regular eligibility.');
priority_ok(str_contains($policy,'no lo sobrescribas silenciosamente'),'Brain policy must mirror swim-level contradiction handling.');
priority_ok(str_contains($policy,'propone primero Colegio Monteverde'),'Brain policy must mirror venue priority without inventing capacity logic.');

fwrite(STDOUT,"SHARKY_QUALIFICATION_PRIORITY_OK\n");
