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

  function localMessage(target, text, type = '') {
    if (!target) return;
    target.textContent = text;
    target.dataset.type = type;
    target.hidden = !text;
  }

  function formatDate(raw) {
    if (!raw) return '';
    const date = new Date(String(raw).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }).format(date);
  }

  function labelledField(labelText, control) {
    const label = document.createElement('label');
    label.append(document.createTextNode(labelText), control);
    return label;
  }

  function notificationFields(prefix) {
    const email = document.createElement('input');
    email.type = 'email';
    email.name = 'correo';
    email.maxLength = 254;
    email.autocomplete = 'email';
    email.inputMode = 'email';
    const emailLabel = labelledField('Correo electrónico (opcional)', email);
    const hint = document.createElement('span');
    hint.className = 'field-hint';
    hint.textContent = 'Solo lo usaremos si activas los avisos de respuestas.';
    emailLabel.append(hint);

    const notify = document.createElement('input');
    notify.type = 'checkbox';
    notify.name = 'notificar_respuestas';
    notify.value = '1';
    notify.id = `${prefix}-notify`;
    const toggle = document.createElement('label');
    toggle.className = 'notification-toggle';
    toggle.htmlFor = notify.id;
    const toggleText = document.createElement('span');
    toggleText.textContent = 'Avísame por correo si alguien responde a mi comentario.';
    toggle.append(notify, toggleText);

    const privacy = document.createElement('p');
    privacy.className = 'notification-help';
    privacy.textContent = 'Si activas los avisos, te enviaremos un correo de confirmación. Puedes cancelarlos desde cualquier aviso y no te suscribe a promociones.';

    const syncRequired = () => {
      email.required = notify.checked;
      email.setAttribute('aria-required', String(notify.checked));
    };
    notify.addEventListener('change', syncRequired);
    syncRequired();
    return { emailLabel, toggle, privacy, email, notify };
  }

  function wireExistingNotificationFields(targetForm) {
    const email = targetForm?.querySelector('input[name="correo"]');
    const notify = targetForm?.querySelector('input[name="notificar_respuestas"]');
    if (!email || !notify) return;
    const syncRequired = () => {
      email.required = notify.checked;
      email.setAttribute('aria-required', String(notify.checked));
    };
    notify.addEventListener('change', syncRequired);
    syncRequired();
  }

  async function submitComment(targetForm, replyTo = null, targetStatus = status) {
    const submit = targetForm.querySelector('button[type="submit"]');
    const formData = new FormData(targetForm);
    if (submit) submit.disabled = true;
    localMessage(targetStatus, 'Enviando…');
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
          correo: formData.get('correo'),
          notificar_respuestas: formData.get('notificar_respuestas') === '1',
          responder_a: replyTo,
          website: formData.get('website')
        })
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo enviar el comentario');
      targetForm.reset();
      wireExistingNotificationFields(targetForm);
      localMessage(targetStatus, data.mensaje || 'Gracias. Tu comentario quedó pendiente de moderación.', 'success');
      return true;
    } catch (error) {
      localMessage(targetStatus, error.message || 'No se pudo enviar el comentario.', 'error');
      return false;
    } finally {
      if (submit) submit.disabled = false;
    }
  }

  function replyForm(item) {
    const wrap = document.createElement('div');
    wrap.className = 'reply-form-wrap';
    wrap.id = `responder-${item.id}`;

    const heading = document.createElement('div');
    heading.className = 'reply-form-heading';
    const title = document.createElement('strong');
    title.textContent = `Responder a ${item.autor || 'este comentario'}`;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'reply-cancel';
    close.textContent = 'Cancelar';
    close.addEventListener('click', () => wrap.remove());
    heading.append(title, close);

    const reply = document.createElement('form');
    reply.className = 'comment-form reply-form';
    reply.dataset.replyForm = item.id;

    const name = document.createElement('input');
    name.type = 'text';
    name.name = 'nombre';
    name.maxLength = 80;
    name.autocomplete = 'name';
    name.required = true;
    reply.append(labelledField('Tu nombre', name));

    const notification = notificationFields(`reply-${item.id}`);
    reply.append(notification.emailLabel, notification.toggle, notification.privacy);

    const textarea = document.createElement('textarea');
    textarea.name = 'comentario';
    textarea.maxLength = 700;
    textarea.required = true;
    textarea.setAttribute('aria-label', `Respuesta para ${item.autor || 'este comentario'}`);
    reply.append(labelledField('Respuesta', textarea));

    const honey = document.createElement('label');
    honey.className = 'comment-honeypot';
    honey.setAttribute('aria-hidden', 'true');
    const honeyInput = document.createElement('input');
    honeyInput.type = 'text';
    honeyInput.name = 'website';
    honeyInput.tabIndex = -1;
    honeyInput.autocomplete = 'off';
    honey.append(document.createTextNode('Sitio web'), honeyInput);
    reply.append(honey);

    const send = document.createElement('button');
    send.type = 'submit';
    send.textContent = 'Enviar respuesta para moderación';
    reply.append(send);

    const replyStatus = document.createElement('p');
    replyStatus.className = 'community-status reply-status';
    replyStatus.setAttribute('role', 'status');
    replyStatus.setAttribute('aria-live', 'polite');
    replyStatus.hidden = true;

    reply.addEventListener('submit', async (event) => {
      event.preventDefault();
      const ok = await submitComment(reply, item.id, replyStatus);
      if (ok) textarea.focus({ preventScroll: true });
    });

    wrap.append(heading, reply, replyStatus);
    setTimeout(() => name.focus({ preventScroll: true }), 0);
    return wrap;
  }

  function addReplyButton(card, item) {
    const actions = document.createElement('div');
    actions.className = 'comment-actions';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'reply-button';
    button.textContent = 'Responder';
    button.setAttribute('aria-controls', `responder-${item.id}`);
    button.setAttribute('aria-expanded', 'false');
    button.addEventListener('click', () => {
      const existing = card.querySelector(':scope > .reply-form-wrap');
      if (existing) {
        existing.remove();
        button.setAttribute('aria-expanded', 'false');
        return;
      }
      root.querySelectorAll('.reply-form-wrap').forEach((node) => node.remove());
      root.querySelectorAll('.reply-button[aria-expanded="true"]').forEach((other) => other.setAttribute('aria-expanded', 'false'));
      card.append(replyForm(item));
      button.setAttribute('aria-expanded', 'true');
    });
    actions.append(button);
    card.append(actions);
  }

  function commentCard(item, isReply = false) {
    const card = document.createElement('article');
    card.className = isReply ? 'comment-card comment-reply' : 'comment-card';
    card.id = `comentario-${item.id}`;

    const head = document.createElement('div');
    head.className = 'comment-head';
    const author = document.createElement('strong');
    author.textContent = item.autor || 'Visitante';
    const date = document.createElement('time');
    date.textContent = formatDate(item.fecha);
    head.append(author, date);
    card.append(head);

    if (isReply && item.respondio_a) {
      const context = document.createElement('span');
      context.className = 'reply-context-label';
      context.textContent = `En respuesta a ${item.respondio_a}`;
      card.append(context);
    }

    const body = document.createElement('p');
    body.textContent = item.comentario || '';
    card.append(body);
    addReplyButton(card, item);
    return card;
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
      const thread = document.createElement('section');
      thread.className = 'comment-thread';
      thread.append(commentCard(item));
      const replies = Array.isArray(item.respuestas) ? item.respuestas : [];
      if (replies.length) {
        const replyList = document.createElement('div');
        replyList.className = 'comment-replies';
        replyList.setAttribute('aria-label', `Respuestas al comentario de ${item.autor || 'Visitante'}`);
        for (const reply of replies) replyList.append(commentCard(reply, true));
        thread.append(replyList);
      }
      list.appendChild(thread);
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

  wireExistingNotificationFields(form);
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    await submitComment(form, null, status);
  });

  load();
})();
