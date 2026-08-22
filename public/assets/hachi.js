(() => {
  const trigger = document.querySelector(".hachi");
  const panel = document.getElementById("hachiPanel");
  const chat = document.getElementById("hachiChat");
  const close = document.getElementById("hachiClose");
  const reset = document.getElementById("hachiReset");
  const nudge = document.getElementById("hachiNudge");
  const prompt = document.getElementById("hachiPrompt");
  if (!trigger || !panel || !chat) return;
  let busy = false;
  let history = [];
  let contextualPrompt = "¿No sabes qué curso elegir?";
  let hideTimer = null;
  let lastSection = "";
  const prompts = {
    inicio: "¿No sabes qué curso elegir?",
    programas: "¿Intensivo o regular?",
    sedes: "¿Monteverde o Palapas?",
    metodo: "¿Quieres saber cómo trabajamos?",
  };
  const trustedLinks = [
    {
      url: "https://wa.me/529902308165",
      label: "Abrir WhatsApp",
      aria: "Hablar con Hache Natación por WhatsApp",
    },
    {
      url: "https://www.instagram.com/hache.natacion/",
      label: "Abrir Instagram",
      aria: "Abrir Instagram de Hache Natación",
    },
    {
      url: "https://www.facebook.com/share/1C24ty435B/",
      label: "Abrir Facebook",
      aria: "Abrir Facebook de Hache Natación",
    },
  ];
  function renderTextWithTrustedLinks(el, text) {
    let source = String(text);
    const matches = [];
    trustedLinks.forEach((link) => {
      let idx = source.indexOf(link.url);
      while (idx !== -1) {
        matches.push({
          idx,
          url: link.url,
          label: link.label,
          aria: link.aria,
        });
        idx = source.indexOf(link.url, idx + link.url.length);
      }
    });
    matches.sort((a, b) => a.idx - b.idx);
    if (!matches.length) {
      el.textContent = source;
      return;
    }
    let cursor = 0;
    matches.forEach((match) => {
      if (match.idx < cursor) return;
      if (match.idx > cursor)
        el.appendChild(
          document.createTextNode(source.slice(cursor, match.idx)),
        );
      const a = document.createElement("a");
      a.href = match.url;
      a.target = "_blank";
      a.rel = "noopener noreferrer";
      a.className = "hachi-trusted-link";
      a.textContent = match.label;
      a.setAttribute("aria-label", match.aria);
      el.appendChild(a);
      cursor = match.idx + match.url.length;
    });
    if (cursor < source.length)
      el.appendChild(document.createTextNode(source.slice(cursor)));
  }
  function msg(text, type = "bot") {
    const el = document.createElement("div");
    el.className = "hachi-msg " + type;
    if (type.includes("bot")) renderTextWithTrustedLinks(el, text);
    else el.textContent = text;
    chat.appendChild(el);
    chat.scrollTop = chat.scrollHeight;
    return el;
  }
  function composer() {
    const form = document.createElement("form");
    form.className = "hachi-composer";
    form.innerHTML =
      '<input class="hachi-input" type="text" maxlength="700" autocomplete="off" placeholder="Pregúntale a Sharky…" aria-label="Escribe tu pregunta"><button class="hachi-send" type="submit" aria-label="Enviar">→</button>';
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (busy) return;
      const input = form.querySelector(".hachi-input");
      const text = input.value.trim();
      if (!text) return;
      input.value = "";
      await ask(text, input, form);
    });
    return form;
  }
  async function ask(text, input, form) {
    msg(text, "user");
    busy = true;
    if (input) input.disabled = true;
    if (form) form.querySelector(".hachi-send").disabled = true;
    const thinking = msg("Déjame pensar…", "bot thinking");
    try {
      const r = await fetch("/api/sharky.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message: text, history }),
      });
      const data = await r.json();
      thinking.remove();
      if (data.ok && data.answer) {
        msg(data.answer);
        history.push(
          { role: "user", content: text },
          { role: "assistant", content: data.answer },
        );
        history = history.slice(-10);
      } else {
        msg(data.error || "Ahora mismo no pude responder. Intenta otra vez.");
      }
    } catch (err) {
      thinking.remove();
      msg("Perdí la conexión por un momento. Intenta otra vez.");
    } finally {
      busy = false;
      if (input) {
        input.disabled = false;
        input.focus();
      }
      if (form) form.querySelector(".hachi-send").disabled = false;
    }
  }
  function restart(seed = "") {
    history = [];
    chat.innerHTML = "";
    msg("¡Hola! 👋 Soy Sharky, el asistente de Hache Natación.");
    const form = composer();
    chat.appendChild(form);
    chat.scrollTop = chat.scrollHeight;
    if (seed) {
      const input = form.querySelector(".hachi-input");
      setTimeout(() => ask(seed, input, form), 120);
    }
  }
  function hideNudge() {
    if (!nudge) return;
    nudge.classList.remove("show");
    nudge.classList.add("hide");
  }
  function showNudge(text) {
    if (!nudge || panel.classList.contains("open")) return;
    if (prompt) prompt.textContent = text;
    nudge.classList.remove("hide");
    void nudge.offsetWidth;
    nudge.classList.add("show");
    clearTimeout(hideTimer);
    hideTimer = setTimeout(hideNudge, 4200);
  }
  function openPanel(seed = "") {
    hideNudge();
    panel.classList.add("open");
    panel.inert = false;
    trigger.classList.add("panel-open");
    if (!chat.children.length) restart(seed);
    else if (seed) {
      const form = chat.querySelector(".hachi-composer");
      const input = form?.querySelector(".hachi-input");
      if (input && !busy) setTimeout(() => ask(seed, input, form), 120);
    }
  }
  trigger.addEventListener("click", () => {
    if (panel.classList.contains("open")) {
      panel.classList.remove("open");
      panel.inert = true;
      trigger.classList.remove("panel-open");
      setTimeout(() => showNudge(contextualPrompt), 500);
      return;
    }
    openPanel("");
  });
  if (nudge) {
    nudge.addEventListener("click", () => openPanel(contextualPrompt));
  }
  close.addEventListener("click", () => {
    panel.classList.remove("open");
    panel.inert = true;
    trigger.classList.remove("panel-open");
    setTimeout(() => showNudge(contextualPrompt), 700);
  });
  reset.addEventListener("click", () => restart());
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      panel.classList.remove("open");
      panel.inert = true;
      trigger.classList.remove("panel-open");
    }
  });
  const sections = ["inicio", "programas", "sedes", "metodo"]
    .map((id) => document.getElementById(id))
    .filter(Boolean);
  function currentSection() {
    const anchor = window.innerHeight * 0.48;
    let current = "inicio";
    let best = Infinity;
    sections.forEach((sec) => {
      const r = sec.getBoundingClientRect();
      if (r.bottom <= 0 || r.top >= window.innerHeight) return;
      const center = (r.top + r.bottom) / 2;
      const d = Math.abs(center - anchor);
      if (d < best) {
        best = d;
        current = sec.id;
      }
    });
    return current;
  }
  function updateContext() {
    if (panel.classList.contains("open")) return;
    const section = currentSection();
    const next = prompts[section] || prompts.inicio;
    contextualPrompt = next;
    if (section !== lastSection) {
      lastSection = section;
      showNudge(next);
    }
  }
  let ticking = false;
  window.addEventListener(
    "scroll",
    () => {
      if (!ticking) {
        requestAnimationFrame(() => {
          updateContext();
          ticking = false;
        });
        ticking = true;
      }
    },
    { passive: true },
  );
  window.addEventListener("resize", updateContext, { passive: true });
  setTimeout(() => {
    lastSection = currentSection();
    contextualPrompt = prompts[lastSection] || prompts.inicio;
    showNudge(contextualPrompt);
  }, 900);
})();
