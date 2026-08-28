(() => {
  const root = document.querySelector('[data-story-community]');
  if (!root) return;

  const story = root.dataset.story || '';
  const endpoint = '/historias/interacciones.php';
  const status = root.querySelector('[data-community-status]');
  const list = root.querySelector('[data-comments-list]');
  const form = root.querySelector('[data-comment-form]');
  const reactionButtons = [...root.querySelectorAll('[data-reaction]')];

  function visitorId() {
    const key = 'hache_story_visitor_v1';
    try {
      let value = localStorage.getItem(key);
      if (!value) {
        value = globalThis.crypto?.randomUUID?.() || `v-${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
        localStorage.setItem(key, value);
      }
      return value;
    } catch (_) {
      return `session-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }
  }

  const visitor = visitorId();

  function message(text, type = '') {
    if (!status) return;
    status.textContent = text;
    status.dataset.type = type;
    status.hidden = !text;
  }

  function formatDate(raw) {
    if (!raw) return '';
    const date = new Date(String(raw).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }).format(date);
  }

  function renderComments(comments) {
    if (!list) return;
    list.replaceChildren();
    if (!Array.isArray(comments) || comments.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'comments-empty';
      empty.textContent = 'Todavía no hay comentarios publicados. Puedes ser el primero.';
      list.appendChild(empty);
      return;
    }
    for (const item of comments) {
      const card = document.createElement('article');
      card.className = 'comment-card';
      const head = document.createElement('div');
      head.className = 'comment-head';
      const author = document.createElement('strong');
      author.textContent = item.autor || 'Visitante';
      const date = document.createElement('time');
      date.textContent = formatDate(item.fecha);
      head.append(author, date);
      const body = document.createElement('p');
      body.textContent = item.comentario || '';
      card.append(head, body);
      list.appendChild(card);
    }
  }

  function applyReactions(counts, mine) {
    for (const button of reactionButtons) {
      const type = button.dataset.reaction || '';
      const count = button.querySelector('[data-count]');
      if (count) count.textContent = String(Number(counts?.[type] || 0));
      const selected = mine === type;
      button.classList.toggle('is-selected', selected);
      button.setAttribute('aria-pressed', String(selected));
    }
  }

  async function load() {
    try {
      const params = new URLSearchParams({ historia: story, visitante: visitor });
      const response = await fetch(`${endpoint}?${params}`, { cache: 'no-store', credentials: 'same-origin' });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo cargar la conversación');
      applyReactions(data.reacciones, data.mi_reaccion);
      renderComments(data.comentarios);
    } catch (error) {
      message(error.message || 'No se pudieron cargar las interacciones.', 'error');
    }
  }

  for (const button of reactionButtons) {
    button.addEventListener('click', async () => {
      if (button.disabled) return;
      button.disabled = true;
      message('');
      try {
        const response = await fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ accion: 'REACCION', historia: story, visitante: visitor, tipo: button.dataset.reaction })
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo guardar la reacción');
        await load();
      } catch (error) {
        message(error.message || 'No se pudo guardar la reacción.', 'error');
      } finally {
        button.disabled = false;
      }
    });
  }

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);
    if (submit) submit.disabled = true;
    message('Enviando…');
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          accion: 'COMENTARIO',
          historia: story,
          visitante: visitor,
          nombre: formData.get('nombre'),
          comentario: formData.get('comentario'),
          website: formData.get('website')
        })
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo enviar el comentario');
      form.reset();
      message(data.mensaje || 'Gracias. Tu comentario quedó pendiente de moderación.', 'success');
    } catch (error) {
      message(error.message || 'No se pudo enviar el comentario.', 'error');
    } finally {
      if (submit) submit.disabled = false;
    }
  });

  load();
})();
