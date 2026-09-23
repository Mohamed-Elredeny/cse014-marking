/* ==========================================================================
   Marking — navigation across the three marking screens
   --------------------------------------------------------------------------
   Each step is its own page; state travels in the query string.

     sections.html?lab=4
     students.html?lab=4&section=S01
     grade.html?lab=4&section=S01&student=2400003
   ========================================================================== */

const Flow = (() => {
  const P = new URLSearchParams(location.search);

  let _lab     = +P.get('lab') || null;
  let _section = P.get('section') || null;
  const _student = P.get('student') || null;

  const PAGES = [
    { n: 1, key: 'step.section', page: 'sections.html' },
    { n: 2, key: 'step.student', page: 'students.html' },
    { n: 3, key: 'step.grade',   page: 'grade.html' }
  ];

  function url(page, extra = {}) {
    const q = new URLSearchParams();
    const all = { lab: _lab, section: _section, ...extra };
    Object.keys(all).forEach(k => {
      const v = all[k];
      if (v !== null && v !== undefined && v !== '') q.set(k, v);
    });
    const s = q.toString();
    return s ? `${page}?${s}` : page;
  }

  /* Step indicator — only completed steps are navigable. */
  function stepper(active, el) {
    el.innerHTML = PAGES.map(p => {
      const cls = p.n === active ? 'step step--on'
                : p.n <  active ? 'step step--done'
                : 'step step--off';
      const label = `<span class="step__n">${p.n}</span> ${esc(t(p.key))}`;
      return p.n < active
        ? `<a class="${cls}" href="${url(p.page)}">${label}</a>`
        : `<span class="${cls}">${label}</span>`;
    }).join('');
  }

  return {
    get labId()     { return _lab; },
    get sectionId() { return _section; },
    get studentId() { return _student; },
    setLab(id)      { _lab = id; },
    setSection(id)  { _section = id; },
    url, stepper,
    go(page, extra) { location.href = url(page, extra); }
  };
})();
