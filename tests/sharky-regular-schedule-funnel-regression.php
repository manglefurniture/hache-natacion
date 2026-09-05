<?php

declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../config/sharky-deterministic-replies.php')?:'';

function regular_funnel_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"REGULAR FUNNEL FAIL: $message\n");exit(1);}
}

regular_funnel_ok(str_contains($source,"if(\$commercial['program']==='regular')"),'Regular schedule selection must have an explicit forward-only branch.');
regular_funnel_ok(str_contains($source,'Ya tengo: clases regulares en'),'Selected regular venue/schedule must be reaffirmed instead of reopening discovery.');
regular_funnel_ok(str_contains($source,'dime si prefieres 3 o 5 clases por semana'),'After a valid regular schedule, Sharky must advance to the weekly plan decision.');
regular_funnel_ok(!str_contains($source,"para '.$programLabel.' en '.$label.'. Si quieres, te digo el precio o continuamos con el siguiente paso."),'Generic backward/vague schedule-selection copy must not remain shared by regular classes.');

fwrite(STDOUT,"SHARKY_REGULAR_SCHEDULE_FUNNEL_OK\n");
