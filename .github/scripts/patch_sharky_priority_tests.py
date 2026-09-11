from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected one exact match, found {count}")
    p.write_text(text.replace(old, new, 1))


path = "tests/sharky-guided-onboarding-regression.php"
old = """// If the prospect already chose a program before declaring they are new, qualification
// may validate experience but must not silently overwrite that explicit choice.
$prospect=hache_sharky_orchestrator_state(null,1788383000);
$prospect['identity']=array_replace($prospect['identity'],[
    'kind'=>'prospect','verified'=>true,'source'=>'self_declared',
]);
$prospect['commercial_context']['program']='intensive';
[$preferredState,$preferredDecision]=hache_sharky_whatsapp_qualification_start($prospect,1788383000);
guided_ok(
    ($preferredState['flow']['data']['preferred_program']??null)==='intensive',
    'Qualification must carry a previously confirmed intensive choice in flow data.'
);
"""
new = """// A program value captured before level qualification is context, not product authority.
// Level and training history must determine the canonical product before venue selection.
$prospect=hache_sharky_orchestrator_state(null,1788383000);
$prospect['identity']=array_replace($prospect['identity'],[
    'kind'=>'prospect','verified'=>true,'source'=>'self_declared',
]);
$prospect['commercial_context']['program']='intensive';
[$preferredState,$preferredDecision]=hache_sharky_whatsapp_qualification_start($prospect,1788383000);
guided_ok(
    !isset($preferredState['flow']['data']['preferred_program']),
    'Qualification must not carry a pre-qualification program as authority before level is known.'
);
"""
replace_once(path, old, new)

replace_once(
    path,
    """// Formal swimmers with no prior program get a real client-goal choice instead of an
// automatic regular assignment. The interactive state machine must recognize it.
""",
    """// Legacy program cursors remain syntactically recognized so stale buttons fail safely;
// canonical product mapping is enforced when level and training history are known.
""",
)
