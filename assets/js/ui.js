/* ==========================================================================
   Marking — shared interface helpers
   --------------------------------------------------------------------------
   Loaded on every page after i18n.js and api.js. Provides DOM shorthands,
   escaping, localised time formatting, toasts, the connection banner and
   the page-level access guard.
   ========================================================================== */

/* Opened by double-click (file://)? PHP will not run and the browser blocks
   fetch, so the page explains the cause rather than surfacing a CORS error.
   The guard itself is installed at the foot of this file, once the helpers
   it depends on have been declared. */
const FILE_MODE = location.protocol === 'file:';

function showFileWarning() {
  if (document.getElementById('fileWarn')) return;
  document.body.innerHTML = `
    <div id="fileWarn" class="shell stack" style="max-width:600px;padding-block:44px">
      <div class="card card--lift stack">
        <h1>${esc(t('file.title'))}</h1>
        <p class="small muted">${esc(t('file.body'))}</p>

        <div class="banner banner--info" style="display:block">
          <strong>${esc(t('file.fix'))}</strong> ${esc(t('file.fixBody'))}
          <div class="ltr" style="font-family:var(--mono);background:var(--surface-2);
                      border-radius:8px;padding:11px 13px;margin-top:9px;font-size:.82rem">
            php -S localhost:8080 -t .
          </div>
          <div style="margin-top:8px">
            ${esc(t('file.then'))}
            <a class="ltr" style="font-family:var(--mono)" href="http://localhost:8080">http://localhost:8080</a>
          </div>
        </div>

        <p class="tiny faint">
          ${esc(t('file.xampp'))}
          <span class="num ltr">http://localhost/Marking</span>
        </p>
      </div>
    </div>`;
}

/* ---------------------------------------------------------------- DOM ---- */

const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

const esc = (s) => String(s ?? '').replace(/[&<>"']/g,
  c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));

/* Highlights the matched span of a search result. */
function hl(text, at, len) {
  const s = String(text ?? '');
  if (at == null || at < 0) return esc(s);
  return esc(s.slice(0, at)) + '<mark>' + esc(s.slice(at, at + len)) + '</mark>' + esc(s.slice(at + len));
}

/* --------------------------------------------------------------- time ---- */

function fmtClock(ts) {
  const d = new Date(ts);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

function fmtWhen(ts) {
  if (!ts) return '—';
  const mins = Math.round((Date.now() - ts) / 60000);
  if (mins < 1)  return t('time.now');
  if (mins < 60) return t('time.minsAgo', { n: mins });

  const d = new Date(ts), now = new Date();
  if (d.toDateString() === now.toDateString()) {
    return t('time.today', { t: fmtClock(ts) });
  }
  const yesterday = new Date(now.getTime() - 864e5);
  if (d.toDateString() === yesterday.toDateString()) {
    return t('time.yesterday', { t: fmtClock(ts) });
  }
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')} ${fmtClock(ts)}`;
}

/* Short UTC day/month, used for the lab schedule windows. */
const fmtDate = ts => {
  const d = new Date(ts);
  return `${String(d.getUTCDate()).padStart(2, '0')}/${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
};

/* ------------------------------------------------------------- labels ---- */

const labName   = id => t('lab.n', { n: id });
const statusText = s => t('status.' + s);
const STATUS_PILL = { present: 'pill--ok', late: 'pill--warn', absent: 'pill--bad' };

/* -------------------------------------------------------------- toast ---- */
/* action = { label, run } — renders a button beside the message. */

let _toastEl, _toastTimer;

function toast(msg, kind = '', action = null) {
  if (!_toastEl) {
    _toastEl = document.createElement('div');
    _toastEl.setAttribute('role', 'status');
    document.body.appendChild(_toastEl);
  }
  _toastEl.className = 'toast' + (kind === 'bad' ? ' toast--bad' : '');
  _toastEl.style.pointerEvents = action ? 'auto' : 'none';
  _toastEl.textContent = '';

  const text = document.createElement('span');
  text.className = 'row__main';
  text.textContent = msg;
  _toastEl.appendChild(text);

  if (action) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'toast__act';
    b.textContent = action.label;
    b.addEventListener('click', () => { hideToast(); action.run(); });
    _toastEl.appendChild(b);
  }

  requestAnimationFrame(() => _toastEl.dataset.show = 'true');
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(hideToast, action ? 6000 : 2400);
}

function hideToast() { if (_toastEl) _toastEl.dataset.show = 'false'; }

/* ------------------------------------------- connection + local queue ---- */

(function netWatch() {
  if (!document.body || typeof Api === 'undefined' || !Api.outbox) return;

  const bar = document.createElement('div');
  bar.className = 'netbar';
  bar.hidden = true;
  document.body.prepend(bar);

  let wasOffline = null;

  Api.outbox.on((count, offline) => {
    const dead = Api.outbox.failed ? Api.outbox.failed().length : 0;

    if (dead) {
      bar.className = 'netbar';
      bar.textContent = t('net.rejected', { n: dead });
      bar.hidden = false;
      wasOffline = offline;
      return;
    }

    if (offline) {
      bar.className = 'netbar';
      bar.textContent = count ? t('net.offlineQueued', { n: count }) : t('net.offline');
      bar.hidden = false;
      if (wasOffline === false) toast(t('net.wentOffline'));
    } else if (count) {
      bar.className = 'netbar netbar--sync';
      bar.textContent = t('net.syncing', { n: count });
      bar.hidden = false;
    } else {
      if (wasOffline === true) toast(t('net.restored'));
      bar.hidden = true;
    }
    wasOffline = offline;
  });
})();

/* ---------------------------------------------------------- page guard --- */

function requireUser(role) {
  if (FILE_MODE) return null;          // the warning is showing — do not loop on redirects
  const me = Api.me();
  if (!me) { location.href = 'index.html'; return null; }

  const roles = Array.isArray(role) ? role : (role ? [role] : null);
  if (roles && !roles.includes(me.role)) {
    location.href = me.role === 'main' ? 'admin.html' : 'sections.html';
    return null;
  }
  return me;
}

function logout() { Api.logout(); location.href = 'index.html'; }

/* ------------------------------------------- choices kept between pages --- */

const Prefs = {
  key: 'marking.prefs.v1',
  get() { try { return JSON.parse(localStorage.getItem(this.key) || '{}'); } catch { return {}; } },
  set(patch) { try { localStorage.setItem(this.key, JSON.stringify({ ...this.get(), ...patch })); } catch {} }
};

/* ------------------------------------ completion colour for matrix cells -- */

function pctTone(pct) {
  if (pct >= 100) return { bg: 'var(--ok-soft)',   fg: 'var(--ok)' };
  if (pct >= 50)  return { bg: 'var(--warn-soft)', fg: 'var(--warn)' };
  if (pct > 0)    return { bg: 'var(--bad-soft)',  fg: 'var(--bad)' };
  return            { bg: 'transparent',           fg: 'var(--ink-3)' };
}

/* --------------------------------------------------- loading placeholder -- */

function skeleton(n = 3) {
  return Array.from({ length: n }, () => '<div class="skeleton"></div>').join('');
}

/* ------------------------------------------------------ clipboard helper -- */

async function copyText(value) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value);
    } else {
      const ta = document.createElement('textarea');
      ta.value = value;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
    toast(t('act.copied'));
    return true;
  } catch {
    toast(t('act.copyFail'), 'bad');
    return false;
  }
}

/* ------------------------------------------------------ file:// guard ---- */
/* Installed last: showFileWarning() uses esc() and t(), which are const
   bindings and therefore not hoisted. */
if (FILE_MODE) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', showFileWarning);
  } else {
    showFileWarning();
  }
}
