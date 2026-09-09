from pathlib import Path

def replace_once(path,old,new,label):
    p=Path(path); s=p.read_text(); n=s.count(old)
    if n!=1: raise SystemExit(f"{label}: expected 1 match, got {n}")
    p.write_text(s.replace(old,new,1))

replace_once(
    "config/sharky-whatsapp-batching.php",
    "$isPauseText=preg_match('/^(?:ahora\\s+no|por\\s+ahora\\s+no|no\\s+por\\s+ahora|todavia\\s+no|aun\\s+no)[.! ]*$/u',$t)===1;",
    "$isPauseText=preg_match('/^(?:ahora\\s+no|por\\s+ahora\\s+no|no\\s+por\\s+ahora|no\\s+por\\s+el\\s+momento|por\\s+el\\s+momento\\s+no|todavia\\s+no|aun\\s+no)[.! ]*$/u',$t)===1;",
    "pause wording"
)
replace_once(
    "config/sharky-followup.php",
    "$message='¿Te gustaría que te ayude a iniciar la inscripción al curso intensivo en '.$sede.'?';\n        $buttons=[hache_sharky_followup_button('action:register_intensive','Inscribirme'),hache_sharky_followup_button('action:commercial_schedules','Horarios'),hache_sharky_followup_button('action:commercial_price','Precio')];",
    "$message='¿Quieres continuar con la inscripción al curso intensivo en '.$sede.'?';\n        $buttons=[hache_sharky_followup_button('action:register_intensive','Inscribirme'),hache_sharky_followup_button('flow:pause','No por el momento')];",
    "first intensive followup"
)
replace_once(
    "config/sharky-followup.php",
    "$message='Si prefieres decidirlo con calma, también puedo mostrarte horarios y precio del curso intensivo en '.$sede.'. ¿Te los comparto?';\n        $buttons=[hache_sharky_followup_button('action:commercial_schedules','Horarios'),hache_sharky_followup_button('action:commercial_price','Precio')];",
    "$message='Si todavía te interesa el curso intensivo en '.$sede.', puedes inscribirte desde aquí. Si prefieres dejarlo para después, no hay problema.';\n        $buttons=[hache_sharky_followup_button('action:register_intensive','Inscribirme'),hache_sharky_followup_button('flow:pause','No por el momento')];",
    "second intensive followup"
)
