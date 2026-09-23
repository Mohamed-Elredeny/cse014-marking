/* ==========================================================================
   Marking — server communication layer
   --------------------------------------------------------------------------
   - Every read is cached locally, so pages keep opening when the network
     drops.
   - Every write made while offline is queued and sent automatically once
     the connection returns. The marks endpoint upserts on
     (student_id, lab_id), so replaying the queue is safe.
   ========================================================================== */

const Api = (() => {

  const BASE      = 'api/';
  const USER_KEY  = 'marking.user';
  const OUT_KEY   = 'marking.outbox.v2';
  const DEAD_KEY  = 'marking.outbox.failed.v1';
  const CACHE_KEY = 'marking.cache.v1';
  const CACHE_MAX = 60;

  /* ---------------- safe local storage ---------------- */
  const read  = (k, d) => { try { const v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch { return d; } };
  const write = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch {} };
  const drop  = (k)    => { try { localStorage.removeItem(k); } catch {} };

  /* ---------------- current user ---------------- */
  let USER = read(USER_KEY, null);
  const setUser = u => { u ? write(USER_KEY, u) : drop(USER_KEY); USER = u; };

  /* ---------------- network state ---------------- */
  let forceOffline = false;
  const isOffline = () => forceOffline || navigator.onLine === false;

  const listeners = [];
  const outbox = () => read(OUT_KEY, []);
  const notify = () => listeners.forEach(f => { try { f(outbox().length, isOffline()); } catch {} });

  /* The server localises its error messages to match the interface. */
  const langHeader = () => (typeof I18N !== 'undefined' ? I18N.lang : 'ar');

  /* ---------------- read cache ---------------- */
  function cacheGet(url) {
    const c = read(CACHE_KEY, {});
    return c[url] ? c[url].data : null;
  }

  function cacheSet(url, data) {
    const c = read(CACHE_KEY, {});
    c[url] = { at: Date.now(), data };
    const keys = Object.keys(c);
    if (keys.length > CACHE_MAX) {
      keys.sort((a, b) => c[a].at - c[b].at)
          .slice(0, keys.length - CACHE_MAX)
          .forEach(k => delete c[k]);
    }
    write(CACHE_KEY, c);
  }

  /* ---------------- requests ---------------- */
  function qs(params = {}) {
    const u = new URLSearchParams();
    Object.keys(params).forEach(k => {
      const v = params[k];
      if (v !== null && v !== undefined && v !== '') u.set(k, v);
    });
    const s = u.toString();
    return s ? '&' + s : '';
  }

  async function GET(file, doAction, params = {}) {
    const url = `${BASE}${file}?do=${doAction}${qs(params)}`;

    if (isOffline()) {
      const c = cacheGet(url);
      if (c) return { ...c, fromCache: true };
    }

    try {
      const r = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Lang': langHeader() }
      });
      const j = await r.json();
      if (r.status === 401) { setUser(null); location.href = 'index.html'; }
      if (j && j.ok) cacheSet(url, j);
      return j;
    } catch {
      notify();
      const c = cacheGet(url);
      if (c) return { ...c, fromCache: true };
      throw new Error(t('net.noServer'));
    }
  }

  async function POST(file, doAction, payload = {}) {
    const url = `${BASE}${file}?do=${doAction}`;
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Lang': langHeader(),
        'X-CSRF': (USER && USER.csrf) || ''
      },
      body: JSON.stringify(payload)
    });
    const j = await r.json();
    if (r.status === 401) { setUser(null); location.href = 'index.html'; }
    return j;
  }

  /* ---------------- local queue ---------------- */
  function queue(file, doAction, payload) {
    const q = outbox();
    q.push({
      id: 'o' + Date.now() + Math.random().toString(36).slice(2, 6),
      file, do: doAction, payload, at: Date.now()
    });
    write(OUT_KEY, q);
    notify();
  }

  /**
   * Drains the queue.
   * No operation is ever dropped silently: it is either accepted, still
   * waiting for the network, or rejected by the server and moved to the
   * "needs review" list the TA sees on the sections page.
   */
  async function flush() {
    if (isOffline()) return { sent: 0, failed: 0 };
    const q = outbox();
    if (!q.length) return { sent: 0, failed: 0 };

    const left = [];
    const dead = read(DEAD_KEY, []);
    let sent = 0, failed = 0;

    for (const op of q) {
      try {
        const res = await POST(op.file, op.do, op.payload);
        if (res && res.ok) { sent++; continue; }

        // The server answered and refused — retrying will not help.
        dead.push({ ...op, error: (res && res.error) || t('net.serverRefused'), failedAt: Date.now() });
        failed++;
      } catch {
        left.push(op);          // network problem — try again later
      }
    }

    write(OUT_KEY, left);
    write(DEAD_KEY, dead.slice(-50));
    notify();
    return { sent, failed };
  }

  addEventListener('online',  () => flush());
  addEventListener('offline', () => notify());

  /* Anything left over from an earlier session goes out immediately. */
  if (!isOffline() && outbox().length) setTimeout(flush, 800);

  /* Confirm the session is still valid, without blocking the page. */
  if (USER) {
    GET('auth.php', 'me').then(r => {
      if (!r) return;
      if (r.ok && !r.user) { setUser(null); location.href = 'index.html'; }
      else if (r.ok && r.user && !r.fromCache) setUser(r.user);
    }).catch(() => {});
  }

  /* ======================================================================
     Public interface
     ====================================================================== */
  return {

    /* ---------------- authentication ---------------- */
    async login(username, password) {
      const r = await POST('auth.php', 'login', { username, password });
      if (r.ok) setUser(r.user);
      return r;
    },

    me() { return USER; },

    logout() {
      POST('auth.php', 'logout').catch(() => {});
      setUser(null);
      drop(CACHE_KEY);
    },

    /* ---------------- labs ---------------- */
    async labs()     { return (await GET('labs.php', 'list')).labs || []; },
    async schedule() { return await GET('labs.php', 'schedule'); },

    async setLabState(labId, state) { return await POST('labs.php', 'setState', { labId, state }); },
    async setRubric(labId, items, recompute = true) {
      return await POST('labs.php', 'setRubric', { labId, items, recompute });
    },
    async setSchedule(s) { return await POST('labs.php', 'setSchedule', s); },

    /* ---------------- sections and students ---------------- */
    async sections() { return (await GET('roster.php', 'sections')).sections || []; },

    async roster({ sectionId, labId }) {
      return (await GET('roster.php', 'roster', { sectionId, labId })).roster || [];
    },

    async myWork(taId, labId) {
      return (await GET('roster.php', 'myWork', { labId })).work || [];
    },

    async studentState({ studentId, labId }) {
      return (await GET('roster.php', 'student', { studentId, labId })).student || null;
    },

    async searchStudents(q, { sectionId, labId, limit = 8 } = {}) {
      const s = String(q || '').trim();
      if (s.length < 2) return { query: s, items: [], more: 0 };
      try {
        return await GET('roster.php', 'search', { q: s, sectionId, labId, limit });
      } catch {
        return { query: s, items: [], more: 0 };
      }
    },

    /* ---------------- grades ---------------- */
    async saveMark(payload) {
      // The moment of marking — the server accepts the entry if the lab was
      // open at that time, even when it has since closed.
      const p = { at: Date.now(), ...payload };
      if (isOffline()) {
        queue('marks.php', 'save', p);
        return { ok: true, queued: true, replaced: false, entry: optimistic(p) };
      }
      try {
        return await POST('marks.php', 'save', p);
      } catch {
        queue('marks.php', 'save', p);
        notify();
        return { ok: true, queued: true, replaced: false, entry: optimistic(p) };
      }
    },

    /* ---------------- change requests ---------------- */
    async requestChange(payload) {
      if (isOffline()) {
        queue('requests.php', 'create', payload);
        return { ok: true, queued: true, request: { ...payload, state: 'pending', at: Date.now() } };
      }
      try {
        return await POST('requests.php', 'create', payload);
      } catch {
        queue('requests.php', 'create', payload);
        return { ok: true, queued: true, request: { ...payload, state: 'pending', at: Date.now() } };
      }
    },

    async myRequests(taId, limit = 20) {
      return (await GET('requests.php', 'mine', { limit })).requests || [];
    },

    async pendingRequests() {
      return (await GET('requests.php', 'pending')).requests || [];
    },

    async decideRequest(id, decision, note) {
      return await POST('requests.php', 'decide', { id, decision, note });
    },

    /* ---------------- Main TA dashboard ---------------- */
    async taOverview(labId) { return (await GET('admin.php', 'taOverview', { labId })).rows || []; },
    async labStats(labId)   { return await GET('admin.php', 'labStats', { labId }); },
    async matrix()          { return (await GET('admin.php', 'matrix')).matrix || []; },
    async overrides(limit = 20) { return (await GET('admin.php', 'overrides', { limit })).items || []; },
    async activity(limit = 12)  { return (await GET('admin.php', 'activity', { limit })).items || []; },
    async auditLog(limit = 30)  { return (await GET('admin.php', 'audit', { limit })).items || []; },
    async distribution(labId)   { return await GET('admin.php', 'distribution', { labId }); },

    async reassign(p)          { return await POST('admin.php', 'reassign', p); },
    async clearReassign(labId) { return await POST('admin.php', 'clearReassign', { labId }); },

    /* ---------------- users ---------------- */
    async users()                       { return await GET('users.php', 'list'); },
    async saveUser(u)                   { return await POST('users.php', 'save', u); },
    async resetPassword(id, pw)         { return await POST('users.php', 'password', { id, password: pw || '' }); },
    async setUserActive(id, active)     { return await POST('users.php', 'active', { id, active }); },
    async setUserSections(id, sections) { return await POST('users.php', 'sections', { id, sections }); },
    async loginLog(limit = 40, only = '') {
      return (await GET('users.php', 'loginLog', { limit, only })).items || [];
    },

    /* ---------------- export ---------------- */
    async exportLab(labId) { return await GET('export.php', 'lab', { labId }); },
    async exportAll()      { return await GET('export.php', 'all'); },

    /* ---------------- queue ---------------- */
    outbox: {
      count:   () => outbox().length,
      offline: () => isOffline(),
      flush,
      on(fn) { listeners.push(fn); fn(outbox().length, isOffline()); },
      simulate(v) { forceOffline = !!v; if (!v) flush(); notify(); },

      /* Operations the server refused — the TA must see these. */
      failed:      () => read(DEAD_KEY, []),
      clearFailed: () => { drop(DEAD_KEY); notify(); },
      async retryFailed() {
        const dead = read(DEAD_KEY, []);
        if (!dead.length) return { sent: 0, failed: 0 };
        drop(DEAD_KEY);
        write(OUT_KEY, outbox().concat(dead.map(({ error, failedAt, ...op }) => op)));
        return await flush();
      }
    }
  };

  /* A provisional entry shown to the TA until the server confirms it. */
  function optimistic(p) {
    return {
      studentId: p.studentId,
      labId: +p.labId,
      sectionId: p.sectionId,
      status: p.status,
      grade: p.status === 'absent' ? 0 : (p.grade || 0),
      items: p.items || [],
      reason: p.reason || '',
      taId: USER ? USER.id : null,
      taName: USER ? USER.name : '—',
      byMe: true,
      at: Date.now(),
      pendingSync: true
    };
  }
})();
