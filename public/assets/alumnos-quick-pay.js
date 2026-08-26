(function () {
  if (location.pathname !== '/alumnos.php') return;

  const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  const now = new Date();
  const mes = now.getMonth() + 1;
  const anio = now.getFullYear();
  const label = meses[mes - 1] + ' ' + anio;

  function money(v) {
    return Number(v || 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
  }

  function ensureModal() {
    if (document.getElementById('hache-quick-pay')) return;

    document.body.insertAdjacentHTML('beforeend', `<div id="hache-quick-pay" style="display:none;position:fixed;inset:0;background:#0008;z-index:9999;align-items:center;justify-content:center;padding:16px"><div style="width:min(420px,100%);background:#fff;border-radius:16px;padding:18px;box-shadow:0 20px 50px #0003"><div style="display:flex;justify-content:space-between;gap:10px;align-items:start"><div><strong id="hqp-title" style="font-size:18px">Pago rápido</strong><div id="hqp-sub" style="font-size:12px;color:#64748b;margin-top:4px"></div></div><button id="hqp-close" type="button" style="border:0;background:#eef2f7;border-radius:8px;width:30px;height:30px;font-size:18px">×</button></div><div id="hqp-error" role="alert" aria-live="polite" style="display:none;margin:12px 0;padding:9px;border-radius:8px;background:#fee2e2;color:#991b1b;font-size:12px"></div><label style="display:block;margin-top:14px;font-size:12px;font-weight:800">Importe</label><input id="hqp-amount" type="number" step="0.01" min="0" style="width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px;margin-top:5px"><label style="display:block;margin-top:12px;font-size:12px;font-weight:800">Método de pago</label><select id="hqp-method" style="width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px;margin-top:5px"><option value="EFECTIVO">Efectivo</option><option value="TRANSFERENCIA">Transferencia</option><option value="MERCADO_PAGO">Mercado Pago</option></select><div style="display:flex;gap:8px;margin-top:16px"><button id="hqp-save" type="button" style="flex:1;border:0;border-radius:10px;padding:11px;background:#172033;color:#fff;font-weight:800">Registrar pago</button><a id="hqp-more" href="#" style="flex:1;text-align:center;text-decoration:none;border:1px solid #cbd5e1;border-radius:10px;padding:11px;color:#334155;font-weight:800">Más detalles</a></div></div></div>`);

    document.getElementById('hqp-close').onclick = close;
    document.getElementById('hache-quick-pay').onclick = (e) => {
      if (e.target.id === 'hache-quick-pay') close();
    };
    document.getElementById('hqp-save').onclick = save;
  }

  async function consultarEstadoIntensivo(alumnoId, cursoId) {
    const params = new URLSearchParams({ alumno_id: alumnoId, curso_id: cursoId });
    const response = await fetch('/api/intensivo-pago-estado.php?' + params.toString(), {
      headers: { Accept: 'application/json' }
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'No se pudo comprobar el estado del intensivo');
    }
    return data.pagado === true;
  }

  function marcarIntensivoPagado(trigger) {
    if (!trigger) return;
    const row = trigger.closest('tr');
    const cell = trigger.closest('td');
    if (row) row.dataset.intensivoPagado = '1';
    if (cell) cell.innerHTML = '<span class="estado pagado-intensivo">PAGADO</span>';
  }

  async function detenerSiIntensivoYaPagado(trigger, silencioso) {
    const type = (trigger.dataset.paymentType || '').toUpperCase();
    const courseId = trigger.dataset.courseId || '';
    if (type !== 'INTENSIVO' || !courseId) return false;

    try {
      const pagado = await consultarEstadoIntensivo(trigger.dataset.id || '', courseId);
      if (!pagado) return false;
      marcarIntensivoPagado(trigger);
      if (!silencioso) {
        alert('Este curso intensivo ya aparece pagado. No se registrará otro cobro.');
      }
      return true;
    } catch (error) {
      console.warn('No se pudo refrescar el estado del intensivo; se conserva la validación del servidor.', error);
      return false;
    }
  }

  let current = null;
  let inFlight = null;

  function open(btn) {
    ensureModal();
    current = Object.freeze({
      id: btn.dataset.id,
      name: btn.dataset.name,
      price: btn.dataset.price,
      type: (btn.dataset.paymentType || 'MENSUALIDAD').toUpperCase(),
      courseId: btn.dataset.courseId || '',
      trigger: btn
    });

    const intensive = current.type === 'INTENSIVO';
    const inscription = current.type === 'INSCRIPCION';
    document.getElementById('hqp-title').textContent = (intensive ? 'Pago de intensivo · ' : inscription ? 'Pago de inscripción · ' : 'Pago de mensualidad · ') + current.name;
    document.getElementById('hqp-sub').textContent = intensive ? 'Curso intensivo · importe sugerido según el curso' : inscription ? 'Inscripción administrativa · importe sugerido según sede' : 'Mensualidad de ' + label + ' · puedes ajustar el importe cuando corresponda';
    document.getElementById('hqp-amount').value = Number(current.price || 0);
    document.getElementById('hqp-error').style.display = 'none';
    document.getElementById('hqp-save').disabled = inFlight !== null;
    document.getElementById('hqp-more').href = '/pagos.php?alumno_id=' + encodeURIComponent(current.id) + '&tipo=' + encodeURIComponent(current.type) + (intensive && current.courseId ? '&curso_intensivo_id=' + encodeURIComponent(current.courseId) : '');
    document.getElementById('hache-quick-pay').style.display = 'flex';
  }

  function close() {
    const modal = document.getElementById('hache-quick-pay');
    if (modal) modal.style.display = 'none';
    current = null;
  }

  async function save() {
    if (!current) return;

    const target = current;
    if (inFlight) return;
    inFlight = target;
    const err = document.getElementById('hqp-error');
    const amount = document.getElementById('hqp-amount').value;
    const amountNumber = Number(amount);
    const suggestedNumber = Number(target.price || 0);
    const method = document.getElementById('hqp-method').value;
    const btn = document.getElementById('hqp-save');
    btn.disabled = true;
    err.style.display = 'none';

    try {
      if (!Number.isFinite(amountNumber) || amountNumber <= 0) {
        throw new Error('Captura un importe válido mayor a cero.');
      }

      if (target.type === 'INTENSIVO' && target.courseId) {
        try {
          const pagado = await consultarEstadoIntensivo(target.id, target.courseId);
          if (current !== target) return;
          if (pagado) {
            const trigger = target.trigger;
            marcarIntensivoPagado(trigger);
            close();
            alert('Este curso intensivo ya aparece pagado. No se registrará otro cobro.');
            return;
          }
        } catch (statusError) {
          console.warn('No se pudo refrescar el estado del intensivo antes de guardar; continúa la validación transaccional del servidor.', statusError);
        }
      }

      const local = new Date();
      const fecha = local.getFullYear() + '-' + String(local.getMonth() + 1).padStart(2, '0') + '-' + String(local.getDate()).padStart(2, '0') + ' ' + String(local.getHours()).padStart(2, '0') + ':' + String(local.getMinutes()).padStart(2, '0') + ':00';
      const amountAdjusted = target.type === 'MENSUALIDAD' && Number.isFinite(suggestedNumber) && Math.abs(amountNumber - suggestedNumber) > 0.009;
      const body = {
        alumno_id: target.id,
        tipo: target.type,
        importe: amount,
        metodo: method,
        fecha,
        observacion: amountAdjusted ? 'Importe ajustado manualmente desde Pago rápido' : ''
      };
      if (target.type === 'INTENSIVO' && target.courseId) body.curso_intensivo_id = target.courseId;

      const currentType = target.type;
      const response = await fetch('/api/pagos-smart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo registrar el pago');
      if (current !== target) return;

      close();
      const concepto = currentType === 'MENSUALIDAD' ? 'mensualidad · ' + (data.periodo_mensualidad?.etiqueta || label) : currentType === 'INSCRIPCION' ? 'inscripción' : 'curso intensivo';
      alert('Pago registrado: ' + money(amount) + ' · ' + concepto);
      location.reload();
    } catch (error) {
      if (current !== target) return;
      err.textContent = error.message;
      err.style.display = 'block';
      err.scrollIntoView({ block: 'nearest' });
    } finally {
      if (inFlight === target) {
        inFlight = null;
        if (current) btn.disabled = false;
      }
    }
  }

  document.addEventListener('click', async (e) => {
    const trigger = e.target.closest('[data-quick-pay]');
    if (!trigger) return;
    if (await detenerSiIntensivoYaPagado(trigger, false)) return;
    open(trigger);
  });

  document.querySelectorAll('[data-quick-pay][data-payment-type="INTENSIVO"]').forEach((trigger) => {
    detenerSiIntensivoYaPagado(trigger, true);
  });
})();
