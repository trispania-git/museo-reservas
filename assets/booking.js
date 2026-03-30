/* global MR, flatpickr */
(function(){
  'use strict';

  // ✅ Anti-doble carga (si el script se evalúa 2 veces)
  if (window.__MR_BOOKING_JS_LOADED__) {
    console.warn('[MR] booking.js ya estaba cargado. Evito doble init.');
    return;
  }
  window.__MR_BOOKING_JS_LOADED__ = true;

  const $ = (sel, root=document) => root.querySelector(sel);

  /* ==========================
     UI mensajes
  ========================== */
  function msg(type, text){
    const el = $('#mr_msg');
    if (!el) return;
    el.className = 'mr-msg ' + type;
    el.textContent = text;
    el.style.display = 'block';
  }
  function clearMsg(){
    const el = $('#mr_msg');
    if (!el) return;
    el.style.display = 'none';
    el.textContent = '';
    el.className = 'mr-msg';
  }

  function formatDateDDMMYYYY(iso){
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso || '';
    const [y,m,d] = iso.split('-');
    return `${d}-${m}-${y}`;
  }

  function getErrMsg(res, fallback){
    if (!res) return fallback;
    if (typeof res === 'string') return res;
    const d = res.data;
    if (typeof d === 'string' && d.trim()) return d.trim();
    if (d && typeof d === 'object' && d.message) return String(d.message);
    if (res.message) return String(res.message);
    return fallback;
  }

  /* ==========================
     ✅ Límite dinámico asistentes por sesión
  ========================== */
  const MAX_GENERAL = parseInt(window.MR?.maxAtt || '5', 10) || 5;
  let slotMax = MAX_GENERAL;

  function setAttendeesOptions(maxAllowed){
    const sel = $('#mr_attendees');
    if (!sel) return;

    const prev = parseInt(sel.value || '1', 10) || 1;
    const max = Math.max(1, parseInt(maxAllowed || '1', 10) || 1);

    sel.innerHTML = '';
    for (let i=1; i<=max; i++){
      const opt = document.createElement('option');
      opt.value = String(i);
      opt.textContent = String(i);
      sel.appendChild(opt);
    }

    const next = Math.min(prev, max);
    sel.value = String(next);
    buildCompanions(next);
  }

  function resetAttendeesToGeneral(){
    slotMax = MAX_GENERAL;
    setAttendeesOptions(MAX_GENERAL);
  }

  function applySlotLimitFromRemaining(remaining){
    const rem = parseInt(String(remaining ?? ''), 10);
    if (Number.isNaN(rem)) return;
    slotMax = Math.max(1, Math.min(MAX_GENERAL, rem));
    setAttendeesOptions(slotMax);
  }

  /* ==========================
     Estilos modal (blindados contra Divi)
  ========================== */
  function injectModalStyles(){
    if (document.getElementById('mr_modal_style')) return;
    const style = document.createElement('style');
    style.id = 'mr_modal_style';
    style.textContent = `
      .mr-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:999999;padding:16px;}
      .mr-modal{background:#fff;border-radius:14px;max-width:520px;width:100%;box-shadow:0 18px 60px rgba(0,0,0,.25);overflow:hidden;}
      .mr-modal-h{padding:16px 18px;border-bottom:1px solid rgba(0,0,0,.08);font-weight:700;}
      .mr-modal-b{padding:16px 18px;line-height:1.45;}
      .mr-modal-a{display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;border-top:1px solid rgba(0,0,0,.08);}

      #mr_confirm_backdrop .mr-btn{
        appearance:none !important;
        -webkit-appearance:none !important;
        border:1px solid rgba(0,0,0,.25) !important;
        background:#fff !important;
        color:#111 !important;
        border-radius:10px !important;
        padding:10px 14px !important;
        font-weight:700 !important;
        line-height:1 !important;
        cursor:pointer !important;
        min-width:88px !important;
      }
      #mr_confirm_backdrop .mr-btn-primary{
        background:#111 !important;
        color:#fff !important;
        border-color:#111 !important;
      }

      #mr_times{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;}
      .mr-timepill{border:1px solid #ddd;border-radius:10px;padding:10px 12px;background:#fff;cursor:pointer;display:inline-flex;gap:8px;align-items:center;}
      .mr-timepill .mr-rem{opacity:.75;font-size:.9em;}
      .mr-timepill.is-active{border-color:#111;box-shadow:0 0 0 2px rgba(0,0,0,.08);}
      .mr-timepill:disabled{opacity:.45;cursor:not-allowed;}

      .mr-empty-red{color:#b00;font-weight:700;margin-top:10px;}
      .mr-success{border:1px solid rgba(0,0,0,.12);border-radius:14px;padding:18px;background:#fff;max-width:720px;}
    `;
    document.head.appendChild(style);
  }

  /* ==========================
     Modal confirmación (antidoble-clic)
  ========================== */
  function confirmModal(text){
    injectModalStyles();

    let bd = document.getElementById('mr_confirm_backdrop');
    if (!bd){
      bd = document.createElement('div');
      bd.id = 'mr_confirm_backdrop';
      bd.className = 'mr-backdrop';
      bd.style.display = 'none';
      bd.innerHTML = `
        <div class="mr-modal" role="dialog" aria-modal="true">
          <div class="mr-modal-h">Confirmar reserva</div>
          <div class="mr-modal-b" id="mr_confirm_text"></div>
          <div class="mr-modal-a">
            <button type="button" class="mr-btn" id="mr_confirm_no">No</button>
            <button type="button" class="mr-btn mr-btn-primary" id="mr_confirm_yes">Sí</button>
          </div>
        </div>
      `;
      document.body.appendChild(bd);
    }

    const textEl = $('#mr_confirm_text', bd);
    const btnNo  = $('#mr_confirm_no', bd);
    const btnYes = $('#mr_confirm_yes', bd);

    textEl.textContent = text;

    btnYes.disabled = false;
    btnYes.textContent = 'Sí';

    bd.style.display = 'flex';

    return new Promise(resolve => {
      const cleanup = (val) => {
        bd.style.display = 'none';
        btnNo.removeEventListener('click', onNo);
        btnYes.removeEventListener('click', onYes);
        bd.removeEventListener('click', onBg);
        document.removeEventListener('keydown', onEsc);
        resolve(val);
      };

      const onNo = () => cleanup(false);
      const onYes = () => {
        btnYes.disabled = true;
        btnYes.textContent = 'Reservando...';
        cleanup(true);
      };
      const onBg = (e) => { if (e.target === bd) cleanup(false); };
      const onEsc = (e) => { if (e.key === 'Escape') cleanup(false); };

      btnNo.addEventListener('click', onNo);
      btnYes.addEventListener('click', onYes);
      bd.addEventListener('click', onBg);
      document.addEventListener('keydown', onEsc);

      btnYes.focus();
    });
  }

  /* ==========================
     AJAX helper
  ========================== */
  async function post(action, data){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', window.MR?.nonce || '');
    Object.keys(data || {}).forEach(k => fd.append(k, data[k]));

    const ajaxUrl = window.MR?.ajax || '/wp-admin/admin-ajax.php';

    const res = await fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });

    const txt = await res.text();
    try { return JSON.parse(txt); }
    catch {
      console.error('[MR] Respuesta NO JSON action=', action, txt);
      return {success:false, data:{message:'Error del servidor (respuesta no JSON). Revisa Network → Response o debug.log.'}};
    }
  }

  /* ==========================
     Acompañantes
  ========================== */
  function buildCompanions(attendees){
    const wrap = $('#mr_comp_fields');
    if (!wrap) return;
    wrap.innerHTML = '';
    const n = Math.max(0, attendees - 1);

    for (let i=1; i<=n; i++){
      const row = document.createElement('div');
      row.className = 'mr-row';
      row.innerHTML = `
        <div class="mr-field">
          <label>Nombre (acompañante ${i})</label>
          <input data-c-first required>
        </div>
        <div class="mr-field">
          <label>Apellidos (acompañante ${i})</label>
          <input data-c-last required>
        </div>
        <div class="mr-field">
          <label>DNI/NIE (acompañante ${i})</label>
          <input data-c-dni required>
        </div>
      `;
      wrap.appendChild(row);
    }
  }

  /* ==========================
     Sesiones
  ========================== */
  function clearTimesUI(){
    const box = $('#mr_times');
    if (box) box.innerHTML = '';
    const hidden = $('#mr_time');
    if (hidden) hidden.value = '';
  }

  function setTimesLoading(){
    const box = $('#mr_times');
    if (box) box.innerHTML = `<div class="mr-hint">Cargando sesiones...</div>`;
    const hidden = $('#mr_time');
    if (hidden) hidden.value = '';
  }

  function renderNoSessions(box){
    const d = document.createElement('div');
    d.className = 'mr-empty-red';
    d.textContent = 'No quedan sesiones disponibles';
    box.appendChild(d);
  }

  function renderTimes(times){
    injectModalStyles();
    const box = $('#mr_times');
    const hidden = $('#mr_time');
    if (!box || !hidden) return;

    box.innerHTML = '';
    hidden.value = '';

    resetAttendeesToGeneral();

    if (!Array.isArray(times) || times.length === 0){
      renderNoSessions(box);
      return;
    }

    let anyEnabled = false;

    times.forEach(t => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mr-timepill';
      btn.dataset.time = t.time;
      btn.dataset.remaining = String(t.remaining ?? 0);

      const rem = Number(t.remaining ?? 0);
      btn.disabled = rem <= 0;
      if (!btn.disabled) anyEnabled = true;

      btn.innerHTML = `
        <span class="mr-time">${t.time}</span>
        <span class="mr-rem">${rem} plazas</span>
      `;

      btn.addEventListener('click', () => {
        box.querySelectorAll('.mr-timepill.is-active').forEach(x => x.classList.remove('is-active'));
        btn.classList.add('is-active');
        hidden.value = t.time;

        applySlotLimitFromRemaining(btn.dataset.remaining);
        clearMsg();
      });

      box.appendChild(btn);
    });

    if (!anyEnabled){
      renderNoSessions(box);
    }
  }

  async function loadTimes(date){
    setTimesLoading();
    resetAttendeesToGeneral();

    const res = await post('mr_get_availability', { date });

    if (!res || !res.success){
      clearTimesUI();
      msg('err', getErrMsg(res, 'No se pudieron cargar las sesiones.'));
      return;
    }

    const times = res.data?.times || [];
    renderTimes(times);
  }

  /* ==========================
     Éxito
  ========================== */
  function showSuccess(container, bookingCode){
    injectModalStyles();
    const form = $('#mr_form', container) || $('#mr_form');
    const host = container || (form ? form.parentElement : null) || document.body;

    if (form) form.style.display = 'none';

    const existing = host.querySelector('#mr_success');
    if (existing) existing.remove();

    const box = document.createElement('div');
    box.id = 'mr_success';
    box.className = 'mr-success';
    box.innerHTML = `
      <h3>✅ Reserva confirmada</h3>
      <p>Tu reserva se ha realizado con éxito.</p>
      <p><strong>Código de reserva:</strong> <code>${bookingCode || '—'}</code></p>
      <p>Recibirás un correo con el justificante.</p>
    `;
    host.appendChild(box);
    box.scrollIntoView({behavior:'smooth', block:'start'});
  }

  /* ==========================
     Flatpickr ES
  ========================== */
  function isoFromDate(d){
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const day = String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${day}`;
  }

  function parseIsoSet(arr){
    const set = new Set();
    (arr || []).forEach(v => {
      if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v.trim())) set.add(v.trim());
    });
    return set;
  }

  // ✅ NUEVO: normaliza openDays para que funcione si viene 0–6 o 1–7
  function normalizeOpenDays(raw){
    const out = [];
    (raw || []).forEach(v => {
      const n = parseInt(String(v), 10);
      if (Number.isNaN(n)) return;

      // si ya es 0..6 (JS getDay): OK
      if (n >= 0 && n <= 6) { out.push(n); return; }

      // si viene 1..7 (1=lun..7=dom): lo pasamos a 0..6
      if (n >= 1 && n <= 7) { out.push(n % 7); return; } // 7%7=0 (domingo)

      // si llega cualquier cosa rara, ignoramos
    });
    return out;
  }

  function initCalendar(){
    if (typeof window.flatpickr !== 'function') return;

    const dateEl = $('#mr_date');
    if (!dateEl) return;
    if (dateEl._flatpickr || dateEl._mr_fp) return;

    if (window.flatpickr?.l10ns?.es && typeof flatpickr.localize === 'function') {
      flatpickr.localize(flatpickr.l10ns.es);
    }

    const openDaysRaw = Array.isArray(window.MR?.openDays) ? MR.openDays : [];
    const openDays = normalizeOpenDays(openDaysRaw);
    const openDaySet = new Set(openDays.map(x => String(x)));

    const closedSet = parseIsoSet(window.MR?.closedDates || []);
    const extraOpenSet = parseIsoSet(window.MR?.extraOpenDates || window.MR?.extraOpen || []);

    const disableFn = (date) => {
      const iso = isoFromDate(date);
      if (closedSet.has(iso)) return true;       // deshabilita cierres
      if (extraOpenSet.has(iso)) return false;   // habilita extras
      const dow = String(date.getDay());         // 0..6
      return !openDaySet.has(dow);               // deshabilita si no está en openDays
    };

    const fp = flatpickr(dateEl, {
      locale: window.flatpickr?.l10ns?.es || undefined,
      dateFormat: 'Y-m-d',
      altInput: true,
      altFormat: 'd-m-Y',
      disableMobile: true,
      clickOpens: true,
      allowInput: false,
      disable: [disableFn],
      onChange: function(_, iso){
        clearMsg();
        clearTimesUI();
        resetAttendeesToGeneral();
        if (iso) loadTimes(iso);
      }
    });

    dateEl._mr_fp = fp;

    const btn = $('#mr_datebtn');
    if (btn) btn.addEventListener('click', () => fp.open());
  }

  function initCalendarRobust(){
    initCalendar();
    let tries = 0;
    const timer = setInterval(() => {
      tries++;
      initCalendar();
      if (tries >= 10) clearInterval(timer);
    }, 300);
  }

  /* ==========================
     INIT
  ========================== */
  function run(){
    const form = $('#mr_form');
    if (!form) return;

    if (form.dataset.mrBound === '1') {
      console.warn('[MR] #mr_form ya tenía listener. Evito doble submit handler.');
      return;
    }
    form.dataset.mrBound = '1';

    injectModalStyles();
    initCalendarRobust();

    resetAttendeesToGeneral();

    const attEl = $('#mr_attendees');
    if (attEl) {
      buildCompanions(parseInt(attEl.value,10));
      attEl.addEventListener('change', () => buildCompanions(parseInt(attEl.value,10)));
    }

    let isSubmitting = false;
    const submitBtn = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      if (window.__MR_SUBMIT_LOCK__) return;
      if (isSubmitting) return;
      isSubmitting = true;
      window.__MR_SUBMIT_LOCK__ = true;

      clearMsg();

      if (submitBtn){
        submitBtn.disabled = true;
        submitBtn.dataset.prevText = submitBtn.textContent;
        submitBtn.textContent = 'Confirmando...';
      }

      try {
        const date = $('#mr_date')?.value || '';
        const time = $('#mr_time')?.value || '';
        const attendees = parseInt($('#mr_attendees')?.value || '0', 10);

        const privacy = $('#mr_privacy');
        if (privacy && !privacy.checked){
          msg('err', 'Debes aceptar la política de privacidad.');
          return;
        }

        if (!date){ msg('err', 'Selecciona una fecha.'); return; }
        if (!time){ msg('err', 'Selecciona una sesión.'); return; }

        const maxAllowed = slotMax || MAX_GENERAL;
        if (!attendees || attendees < 1 || attendees > maxAllowed){
          msg('err', `El número de asistentes debe estar entre 1 y ${maxAllowed} para esta sesión.`);
          setAttendeesOptions(maxAllowed);
          return;
        }

        const req_first_name = ($('#mr_req_first_name')?.value || '').trim();
        const req_last_name  = ($('#mr_req_last_name')?.value || '').trim();
        const req_dni        = ($('#mr_req_dni')?.value || '').trim();
        const req_phone      = ($('#mr_req_phone')?.value || '').trim();
        const req_email      = ($('#mr_req_email')?.value || '').trim();

        if (!req_first_name || !req_last_name || !req_dni || !req_phone || !req_email){
          msg('err', 'Faltan datos obligatorios del solicitante.');
          return;
        }

        const companions = [];
        const rows = Array.from(document.querySelectorAll('#mr_comp_fields .mr-row'));
        rows.forEach(r => {
          companions.push({
            first_name: (r.querySelector('[data-c-first]')?.value || '').trim(),
            last_name:  (r.querySelector('[data-c-last]')?.value || '').trim(),
            dni:        (r.querySelector('[data-c-dni]')?.value || '').trim()
          });
        });

        for (let i=0; i<companions.length; i++){
          const c = companions[i];
          if (!c.first_name || !c.last_name || !c.dni){
            msg('err', `Datos incompletos en acompañantes (acompañante ${i+1}).`);
            return;
          }
        }

        const ok = await confirmModal(
          `Vas a reservar ${attendees} plaza(s) para el día ${formatDateDDMMYYYY(date)} a las ${time} h. ¿Estás de acuerdo?`
        );
        if (!ok) return;

        if (submitBtn) submitBtn.textContent = 'Reservando...';

        const res = await post('mr_make_booking', {
          date,
          time,
          attendees: String(attendees),
          req_first_name,
          req_last_name,
          req_dni,
          req_phone,
          req_email,
          companions: JSON.stringify(companions),
          privacy: '1'
        });

        if (!res || !res.success){
          msg('err', getErrMsg(res, 'No se pudo completar la reserva.'));
          if (date) loadTimes(date);
          return;
        }

        showSuccess(form.parentElement, res.data?.booking_code || '');
        loadTimes(date);

      } finally {
        isSubmitting = false;
        window.__MR_SUBMIT_LOCK__ = false;
        if (submitBtn && form.style.display !== 'none'){
          submitBtn.disabled = false;
          submitBtn.textContent = submitBtn.dataset.prevText || 'Confirmar reserva';
        }
      }
    });
  }

  document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', run)
    : run();

})();
