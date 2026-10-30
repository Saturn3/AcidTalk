/* AcidTalk — gestione tema (chiaro / scuro / automatico). Caricato nel <head> per evitare il "flash" di colori. */
(function () {
  var KEY = 'at.theme', root = document.documentElement, mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function valid(p) { return p === 'light' || p === 'dark' || p === 'system' ? p : null; }
  function pref() {
    var server = valid(root.getAttribute('data-theme-pref'));
    var local = null; try { local = valid(localStorage.getItem(KEY)); if (server && server !== local) localStorage.setItem(KEY, server); } catch (e) {}
    return server || local || 'light';
  }
  function resolve(p) { return p === 'system' ? (mq && mq.matches ? 'dark' : 'light') : p; }
  function apply(p) {
    p = valid(p) || pref();
    var t = resolve(p);
    root.setAttribute('data-theme', t);
    var m = document.querySelector('meta[name="theme-color"]');
    if (m) m.setAttribute('content', t === 'dark' ? '#19171d' : '#3f0e40');
    return t;
  }
  function set(p) {
    p = valid(p) || 'light';
    root.setAttribute('data-theme-pref', p);
    try { localStorage.setItem(KEY, p); } catch (e) {}
    var t = apply(p);
    try { window.dispatchEvent(new CustomEvent('at:theme', {detail: {pref: p, theme: t}})); } catch (e) {}
    return t;
  }
  if (mq) { var on = function () { if (pref() === 'system') apply('system'); }; mq.addEventListener ? mq.addEventListener('change', on) : mq.addListener(on); }
  apply();
  window.ATTheme = {pref: pref, set: set, apply: apply, current: function () { return root.getAttribute('data-theme'); }};
})();
