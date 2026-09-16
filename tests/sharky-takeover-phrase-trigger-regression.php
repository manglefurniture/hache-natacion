<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-echoes.php';

function phrase_takeover_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY TAKEOVER PHRASE FAIL: $message\n");exit(1);}
}

phrase_takeover_ok(hache_sharky_whatsapp_echo_sleep_requested(['type'=>'text','text'=>'Voy a seguir yo, Sharky duerme por favor.']),'Embedded Sharky duerme must activate exclusive takeover.');
phrase_takeover_ok(hache_sharky_whatsapp_echo_resume_requested(['type'=>'text','text'=>'Listo por acá; Sharky despierta y continúa.']),'Embedded Sharky despierta must release takeover.');
phrase_takeover_ok(hache_sharky_whatsapp_echo_sleep_requested(['type'=>'text','text'=>'Hola, soy el profe Ariel.']),'Profe Ariel must activate the same exclusive takeover as Sharky duerme.');
phrase_takeover_ok(hache_sharky_whatsapp_echo_sleep_requested(['type'=>'text','text'=>'HOLA, SOY EL PROFE   ARIEL!']),'Profe Ariel trigger must be case-insensitive and tolerate repeated spaces.');
phrase_takeover_ok(!hache_sharky_whatsapp_echo_sleep_requested(['type'=>'text','text'=>'Hola, soy Ariel.']),'A normal human message must not become exclusive takeover.');
phrase_takeover_ok(!hache_sharky_whatsapp_echo_sleep_requested(['type'=>'image','text'=>'profe Ariel']),'Only text echoes may trigger phrase takeover.');
phrase_takeover_ok(hache_sharky_whatsapp_echo_operator_command(['type'=>'text','text'=>'Sharky duerme. Luego, Sharky despierta.'])==='wake','When commands conflict, the last instruction in the message must win.');
phrase_takeover_ok(hache_sharky_whatsapp_echo_operator_command(['type'=>'text','text'=>'Sharky despierta. Ahora atiende el profe Ariel.'])==='sleep','A later Profe Ariel trigger must override an earlier wake instruction.');

$payload=[
    'entry'=>[[
        'changes'=>[[
            'field'=>'smb_message_echoes',
            'value'=>[
                'metadata'=>['phone_number_id'=>'phone-id'],
                'message_echoes'=>[
                    [
                        'id'=>'wamid.human.ariel',
                        'to'=>'+52 998 000 0001',
                        'timestamp'=>'1789500000',
                        'type'=>'text',
                        'text'=>['body'=>'Hola, soy el profe Ariel. Te ayudo personalmente.'],
                    ],
                    [
                        'id'=>'wamid.human.normal',
                        'to'=>'+52 998 000 0002',
                        'timestamp'=>'1789500001',
                        'type'=>'text',
                        'text'=>['body'=>'Hola, ¿en qué te ayudo?'],
                    ],
                    [
                        'id'=>'wamid.human.pure-control',
                        'to'=>'+52 998 000 0003',
                        'timestamp'=>'1789500002',
                        'type'=>'text',
                        'text'=>['body'=>'Sharky duerme'],
                    ],
                ],
            ],
        ]],
    ]],
];

$echoes=hache_sharky_whatsapp_extract_echoes($payload);
phrase_takeover_ok(count($echoes)===3,'All outbound human echoes must still be extracted.');
phrase_takeover_ok(($echoes[0]['operator_command']??'')==='sleep','Profe Ariel outbound echo must carry sleep control metadata.');
phrase_takeover_ok(($echoes[0]['text']??'')==='Hola, soy el profe Ariel. Te ayudo personalmente.','A mixed human/control message must remain available as HUMANO_HACHE context.');
phrase_takeover_ok(($echoes[1]['operator_command']??'')===''&&($echoes[1]['text']??'')==='Hola, ¿en qué te ayudo?','Ordinary human messages must keep the existing manual_grace path.');
phrase_takeover_ok(($echoes[2]['operator_command']??'')==='sleep'&&($echoes[2]['text']??'')==='','A pure operational command must remain outside conversational text.');

$inboundLikePayload=[
    'entry'=>[[
        'changes'=>[[
            'field'=>'messages',
            'value'=>[
                'messages'=>[[
                    'id'=>'wamid.customer',
                    'from'=>'529980000004',
                    'type'=>'text',
                    'text'=>['body'=>'Quiero hablar con el profe Ariel'],
                ]],
            ],
        ]],
    ]],
];
phrase_takeover_ok(hache_sharky_whatsapp_extract_echoes($inboundLikePayload)===[],'Customer/inbound text must never be interpreted as an operator takeover trigger.');

echo "SHARKY_TAKEOVER_PHRASE_OK\n";
