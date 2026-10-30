/* AcidTalk 2.0 — SPA client, parte 1: utility, API, stato, router, realtime, rendering chat.
   Script classico (nessuna build step): le dichiarazioni top-level sono condivise con app-pages.js. */
'use strict';

const ic = (n, cls = '') => `<svg class="i ${cls}" viewBox="0 0 24 24" aria-hidden="true">${window.AT_ICONS[n] || ''}</svg>`;

/* ============================================================ Utility */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmtTime = d => new Date(d).toLocaleTimeString('it-IT', {hour: '2-digit', minute: '2-digit'});
const fmtDay = d => {
  const x = new Date(d), t = new Date(); const y = new Date(); y.setDate(t.getDate() - 1);
  if (x.toDateString() === t.toDateString()) return 'Oggi';
  if (x.toDateString() === y.toDateString()) return 'Ieri';
  return x.toLocaleDateString('it-IT', {weekday: 'long', day: 'numeric', month: 'long', ...(x.getFullYear() !== t.getFullYear() ? {year: 'numeric'} : {})});
};
const fmtRel = d => {
  const s = (Date.now() - new Date(d)) / 1000;
  if (s < 60) return 'adesso'; if (s < 3600) return `${Math.floor(s / 60)} min fa`; if (s < 86400) return `${Math.floor(s / 3600)} h fa`;
  return new Date(d).toLocaleDateString('it-IT', {day: 'numeric', month: 'short'});
};
const fmtSize = b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : b > 1024 ? Math.round(b / 1024) + ' KB' : b + ' B';
const fmtDur = s => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const uid = () => Math.random().toString(36).slice(2, 9);
const cookie = n => decodeURIComponent((document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)') || [])[2] || '');

/* ============================================================ API */
const B = window.ACIDTALK || {};
class ApiError extends Error { constructor(status, data) { super(data?.message || `Errore ${status}`); this.status = status; this.data = data || {}; this.errors = data?.errors || {}; } }
async function api(method, path, body, opts = {}) {
  const headers = {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-AcidTalk-Tasks': 'client'};
  const xsrf = cookie('XSRF-TOKEN'); if (xsrf) headers['X-XSRF-TOKEN'] = xsrf; else if (B.csrf) headers['X-CSRF-TOKEN'] = B.csrf;
  let payload;
  if (body instanceof FormData) payload = body; else if (body !== undefined) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
  const res = await fetch('/api' + path, {method, headers, body: payload, credentials: 'same-origin', signal: opts.signal});
  if (res.status === 401) { location.href = '/login'; throw new ApiError(401, {message: 'Sessione scaduta'}); }
  if (res.status === 419) { toast('Sessione scaduta: ricarico la pagina', 'err'); setTimeout(() => location.reload(), 900); throw new ApiError(419, {}); }
  if (res.status === 204 || res.status === 202) return null;
  let data = null; try { data = await res.json(); } catch { data = null; }
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}
const list = r => Array.isArray(r) ? r : Array.isArray(r?.data) ? r.data : [];
const one = r => (r && r.data && !Array.isArray(r.data) && typeof r.data === 'object') ? r.data : r;

/* ============================================================ Stato */
const S = {
  user: B.user || {}, org: B.org || {}, channels: [], users: [], active: null,
  msgs: {}, more: {}, loading: {}, thread: null, panel: null, notifs: [], unreadN: 0,
  route: {view: 'chat'}, collapsed: JSON.parse(localStorage.getItem('at.collapsed') || '{}'),
  archived: {}, syncAt: {}, reads: {}, echo: null, pollTimer: null, drafts: JSON.parse(localStorage.getItem('at.drafts') || '{}'),
};
/* ============================================================ Tema */
const themePref = () => S.user.notification_prefs?.theme || window.ATTheme?.pref() || 'light';
const themeNow = () => document.documentElement.getAttribute('data-theme') || 'light';
async function setTheme(pref) {
  window.ATTheme?.set(pref);
  S.user.notification_prefs = {...(S.user.notification_prefs || {}), theme: pref};
  renderSidebar();
  try { Object.assign(S.user, one(await api('PUT', '/me', {notification_prefs: {theme: pref}}))); }
  catch (e) { toast('Tema applicato solo su questo dispositivo: ' + e.message, 'err'); }
}
window.addEventListener('at:theme', () => { const b = document.querySelector('[data-action="theme"]'); if (b) b.innerHTML = ic(themeNow() === 'dark' ? 'sun' : 'moon'); });
const isAdmin = () => S.user.role === 'admin';
const isGuest = () => S.user.role === 'guest';
const chById = id => S.channels.find(c => c.id === Number(id)) || S.archived[Number(id)];
const isRO = c => !!c?.is_archived;
const chIcon = c => c.type === 'private' ? ic('lock', 's') : c.type === 'ai_dm' ? ic('sparkles', 's') : c.type === 'dm' ? '' : ic('hash', 's');
const userById = id => S.users.find(u => u.id === id);
const avatar = (u, extra = '') => u?.is_ai || (u && u.id === null)
  ? `<span class="ava ai ${extra}">${ic('sparkles')}</span>`
  : u?.avatar_url
    ? `<span class="ava img ${extra}" style="background:${esc(u.avatar_color || '#4a154b')}"><img src="${esc(u.avatar_url)}" alt="" loading="lazy" onerror="this.remove()"></span>`
    : `<span class="ava ${extra}" style="background:${esc(u?.avatar_color || '#4a154b')}">${esc(u?.initials || (u?.name || '?').slice(0, 2).toUpperCase())}</span>`;
const botPill = u => u?.is_bot ? '<span class="pill bot">BOT</span>' : '';

/* ============================================================ Toast / modali / popover */
function toast(msg, type = '', action) {
  let host = $('.toasts'); if (!host) { host = document.createElement('div'); host.className = 'toasts'; document.body.appendChild(host); }
  const el = document.createElement('div'); el.className = `toast ${type}`; el.setAttribute('role', 'status');
  el.innerHTML = `${type === 'err' ? ic('warn') : type === 'ok' ? ic('check') : ic('info')}<span>${esc(msg)}</span>${action ? `<button>${esc(action.label)}</button>` : ''}`;
  if (action) $('button', el).onclick = () => { action.fn(); el.remove(); };
  host.appendChild(el); setTimeout(() => el.remove(), action ? 7000 : 3500);
}
let modalStack = [];
function modal({title, body, foot, cls = '', onOpen, onClose}) {
  const ov = document.createElement('div'); ov.className = 'overlay';
  ov.innerHTML = `<div class="modal ${cls}" role="dialog" aria-modal="true" ${title ? `aria-label="${esc(title)}"` : ''}>
    ${title ? `<div class="m-head"><h3>${esc(title)}</h3><button class="ibtn" data-close aria-label="Chiudi">${ic('x')}</button></div>` : ''}
    <div class="m-body">${body}</div>${foot ? `<div class="m-foot">${foot}</div>` : ''}</div>`;
  const close = () => { ov.remove(); modalStack = modalStack.filter(m => m !== close); onClose?.(); };
  ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
  $$('[data-close]', ov).forEach(b => b.onclick = close);
  document.body.appendChild(ov); modalStack.push(close);
  setTimeout(() => ($('[autofocus]', ov) || $('input,textarea,button:not([data-close])', ov))?.focus(), 30);
  onOpen?.(ov, close);
  return {el: ov, close};
}
function confirmModal({title, text, ok = 'Conferma', danger = false}) {
  return new Promise(res => {
    const m = modal({title, body: `<p style="margin:0 0 6px;color:var(--text-2)">${text}</p>`,
      foot: `<button class="btn ghost" data-close>Annulla</button><button class="btn ${danger ? 'danger' : 'primary'}" data-ok autofocus>${esc(ok)}</button>`,
      onClose: () => res(false)});
    $('[data-ok]', m.el).onclick = () => { res(true); m.close(); };
  });
}
let popEl = null;
function popover(anchor, html, {align = 'left', cls = '', onOpen} = {}) {
  closePop();
  const p = document.createElement('div'); p.className = `pop ${cls}`; p.innerHTML = html; document.body.appendChild(p);
  const r = anchor.getBoundingClientRect(); const pw = p.offsetWidth, ph = p.offsetHeight;
  let top = r.bottom + 6, left = align === 'right' ? r.right - pw : r.left;
  if (top + ph > innerHeight - 8) top = Math.max(8, r.top - ph - 6);
  if (left + pw > innerWidth - 8) left = innerWidth - pw - 8; if (left < 8) left = 8;
  p.style.top = top + 'px'; p.style.left = left + 'px';
  popEl = p; onOpen?.(p);
  setTimeout(() => document.addEventListener('mousedown', outsidePop), 0);
  return p;
}
function outsidePop(e) { if (popEl && !popEl.contains(e.target)) closePop(); }
function closePop() { if (popEl) { popEl.remove(); popEl = null; document.removeEventListener('mousedown', outsidePop); } }
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { if (popEl) return closePop(); if (modalStack.length) return modalStack.at(-1)(); if (document.body.classList.contains('sb-open')) return document.body.classList.remove('sb-open'); }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openSearch(); }
});

/* ============================================================ Router */
function parseRoute() {
  const p = location.pathname.replace(/\/+$/, '');
  let m;
  if ((m = p.match(/^\/app\/c\/(\d+)(?:\/t\/(\d+))?/))) return {view: 'chat', channelId: +m[1], threadId: m[2] ? +m[2] : null};
  if ((m = p.match(/^\/app\/admin(?:\/(\w+))?/))) return {view: 'admin', tab: m[1] || 'org'};
  if (p.match(/^\/app\/profile/)) return {view: 'profile'};
  return {view: 'chat', channelId: null};
}
function go(path, replace = false) { history[replace ? 'replaceState' : 'pushState']({}, '', path); onRoute(); }
window.addEventListener('popstate', onRoute);
async function onRoute() {
  closePop(); document.body.classList.remove('sb-open');
  S.route = parseRoute();
  if (S.route.view === 'chat') {
    let id = S.route.channelId || +localStorage.getItem('at.last') || null;
    if (id && !chById(id)) await fetchArchived(id);
    if (!chById(id)) id = (S.channels.find(c => c.slug === 'generale') || S.channels[0])?.id || null;
    if (id && id !== S.route.channelId) return go(`/app/c/${id}`, true);
    if (S.panel === 'thread' && !S.route.threadId) { S.panel = null; S.thread = null; }
    await openChannel(id);
    render();
    if (S.route.threadId) openThread(S.route.threadId);
    return;
  }
  render();
}

/* ============================================================ Caricamento dati */
async function loadChannels() {
  try { S.channels = list(await api('GET', '/channels')); S.channels.forEach(c => delete S.archived[c.id]); }
  catch (e) { toast('Impossibile caricare i canali: ' + e.message, 'err'); S.channels = S.channels || []; }
  sortChannels();
}
/* Canali archiviati: non compaiono in barra laterale ma si possono aprire in sola lettura */
async function fetchArchived(id) {
  try { const c = one(await api('GET', `/channels/${id}`)); if (c?.is_archived) S.archived[c.id] = c; return c; } catch { return null; }
}
async function setArchived(c, archived) {
  try {
    Object.assign(c, one(await api('POST', `/channels/${c.id}/${archived ? 'archive' : 'unarchive'}`)));
    if (archived) { S.channels = S.channels.filter(x => x.id !== c.id); S.archived[c.id] = c; }
    else { delete S.archived[c.id]; if (!S.channels.some(x => x.id === c.id)) S.channels.push(c); sortChannels(); }
    if (S.active?.id === c.id) S.active = c;
    if (S.thread) { S.panel = S.panel === 'thread' ? null : S.panel; S.thread = null; }
    delete S.msgs[c.id]; await loadMessages(c.id);   // mostra subito il messaggio di sistema
    render();
    toast(archived ? `#${c.name} archiviato: ora è in sola lettura` : `#${c.name} ripristinato`, 'ok');
    return true;
  } catch (e) { toast(e.message, 'err'); return false; }
}
function sortChannels() { S.channels.sort((a, b) => (a.type === 'dm') - (b.type === 'dm') || a.name.localeCompare(b.name)); }
async function loadUsers() { try { S.users = list(await api('GET', '/users')); } catch { S.users = []; } }
const seenNotifs = new Set(); let notifsPrimed = false;
async function loadNotifs() {
  try {
    const r = await api('GET', '/notifications'); S.notifs = list(r); S.unreadN = r?.unread ?? S.notifs.filter(n => !n.read_at).length; renderBadges();
    // anche senza WebSocket: notifica desktop per le notifiche arrivate dall'ultimo controllo
    S.notifs.forEach(n => { if (notifsPrimed && !seenNotifs.has(n.id) && !n.read_at && Date.now() - Date.parse(n.created_at) < 5 * 60000) notifyDesktop(n); seenNotifs.add(n.id); });
    notifsPrimed = true;
  } catch {}
}
function notifBannerHtml() {
  if (!('Notification' in window) || Notification.permission !== 'default' || S.user.notification_prefs?.push === false || localStorage.getItem('at.nbanner') === 'off') return '';
  return `<div class="nbanner" id="nbanner">${ic('bell', 's')}<span>AcidTalk ha bisogno del tuo permesso per mostrare le <b>notifiche desktop</b> di menzioni e messaggi diretti.</span>
    <button class="btn primary sm" data-nb="on">Attiva notifiche</button><button class="ibtn" data-nb="off" title="Non ora" aria-label="Chiudi">${ic('x', 's')}</button></div>`;
}
document.addEventListener('click', async e => {
  const b = e.target.closest('[data-nb]'); if (!b) return;
  if (b.dataset.nb === 'on') {
    const p = await Notification.requestPermission().catch(() => 'denied');
    toast(p === 'granted' ? 'Notifiche desktop attivate' : 'Permesso negato: puoi riattivarlo dalle impostazioni del sito nel browser', p === 'granted' ? 'ok' : 'err');
    if (p === 'granted') new Notification('AcidTalk', {body: 'Le notifiche sono attive. Ti avviseremo di menzioni e messaggi diretti.', icon: '/favicon.svg'});
  } else localStorage.setItem('at.nbanner', 'off');
  $('#nbanner')?.remove();
});
async function openChannel(id) {
  if (!id) { S.active = null; return; }
  const ch = chById(id); if (!ch) return;
  S.active = ch; localStorage.setItem('at.last', id);
  if (!S.msgs[id]) await loadMessages(id);
  if (ch.unread_count) { ch.unread_count = 0; api('POST', `/channels/${id}/read`).catch(() => {}); }
  subscribeChannel(id);
}
async function loadMessages(id, before) {
  if (S.loading[id]) return; S.loading[id] = true;
  try {
    const r = await api('GET', `/channels/${id}/messages?limit=50${before ? '&before=' + before : ''}`);
    const items = list(r); S.more[id] = !!r?.meta?.has_more;
    if (!before && r?.meta?.server_time) S.syncAt[id] = r.meta.server_time;
    if (r?.meta?.reads) S.reads[id] = r.meta.reads;
    S.msgs[id] = before ? [...items, ...(S.msgs[id] || [])] : items;
  } catch (e) { if (!before) S.msgs[id] = []; toast('Errore nel caricamento dei messaggi: ' + e.message, 'err'); }
  S.loading[id] = false;
}
function upsertMessage(m, {scroll = true} = {}) {
  if (!m || !m.channel_id) return;
  if (m.is_ai) stopTyping();
  const ch = chById(m.channel_id);
  if (m.parent_id) {
    if (S.thread && S.thread.root.id === m.parent_id) { const i = S.thread.replies.findIndex(x => x.id === m.id || (m.tmp && x.tmp === m.tmp)); i >= 0 ? S.thread.replies[i] = m : S.thread.replies.push(m); renderPanel(); }
    return;
  }
  const arr = S.msgs[m.channel_id]; if (!arr) return;
  const i = arr.findIndex(x => x.id === m.id || (m.tmp && x.tmp === m.tmp)); i >= 0 ? arr[i] = m : arr.push(m);
  if (S.thread && S.thread.root.id === m.id) { S.thread.root = m; renderPanel(); }
  if (S.active?.id === m.channel_id) { renderMessages(scroll); if (m.user?.id !== S.user.id && i < 0) markReadSoon(m.channel_id); } else if (ch && m.user?.id !== S.user.id && i < 0) { ch.unread_count = (ch.unread_count || 0) + 1; renderSidebar(); }
  if (ch) ch.last_message_at = m.created_at;
}
/* Conferme di lettura: il canale aperto e visibile viene segnato come letto quando arrivano messaggi */
const markReadSoon = debounce(id => { if (document.hidden || !document.hasFocus() || S.active?.id !== id) return; api('POST', `/channels/${id}/read`).catch(() => {}); }, 800);
window.addEventListener('focus', () => { const c = S.active; if (c && S.msgs[c.id]?.length && S.route.view === 'chat') markReadSoon(c.id); });
function applyRead(e) {
  const arr = S.reads[e.channel_id] || (S.reads[e.channel_id] = []);
  const r = arr.find(x => x.user_id === e.user_id);
  if (r) r.last_read_at = e.last_read_at; else arr.push({user_id: e.user_id, name: userById(e.user_id)?.name || '', last_read_at: e.last_read_at});
  if (S.active?.id === e.channel_id) renderMessages(false);
  if (S.thread && S.thread.root.channel_id === e.channel_id) renderPanel();
}
function readersOf(m) {
  const t = new Date(m.created_at).getTime();
  return (S.reads[m.channel_id] || []).filter(r => r.user_id !== m.user?.id && r.last_read_at && new Date(r.last_read_at).getTime() >= t - 1000);
}
function receiptHtml(m) {
  if (S.org.read_receipts === false || !m.id || m.user?.id !== S.user.id || m.is_ai || m.kind === 'system') return '';
  const all = (S.reads[m.channel_id] || []).filter(r => r.user_id !== S.user.id); if (!all.length) return '';
  const who = readersOf(m), full = who.length >= all.length;
  const ch = chById(m.channel_id); const dm = ch?.type === 'dm';
  const tip = !who.length ? 'Inviato' : dm ? `Letto${who[0].last_read_at ? ' · ' + new Date(who[0].last_read_at).toLocaleString('it-IT', {day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'}) : ''}` : `Letto da ${who.slice(0, 12).map(r => r.name || userById(r.user_id)?.name || '…').join(', ')}${who.length > 12 ? ` e altri ${who.length - 12}` : ''} (${who.length} di ${all.length})`;
  return `<span class="rcpt ${who.length ? (full ? 'all' : 'some') : ''}" title="${esc(tip)}" aria-label="${esc(tip)}">${ic(who.length ? 'checks' : 'check', 's')}</span>`;
}
function seenByHtml(msgs) {
  if (S.org.read_receipts === false) return '';
  const last = [...msgs].reverse().find(m => m.id && m.kind !== 'system'); if (!last) return '';
  const who = readersOf(last).filter(r => r.user_id !== S.user.id); if (!who.length) return '';
  const ch = chById(last.channel_id);
  if (ch?.type === 'dm') return last.user?.id === S.user.id ? `<div class="seen-by">${ic('checks', 's')} Visualizzato</div>` : '';
  const us = who.slice(0, 6).map(r => userById(r.user_id) || {id: r.user_id, name: r.name});
  return `<div class="seen-by" title="${esc('Visto da ' + who.map(r => r.name || userById(r.user_id)?.name).join(', '))}"><span class="stack">${us.map(u => avatar(u, 'xs')).join('')}</span>${who.length > 6 ? `+${who.length - 6} ` : ''}Visto da ${who.length === 1 ? esc(us[0].name) : who.length + ' persone'}</div>`;
}
function removeMessage(id, channelId, parentId) {
  if (parentId && S.thread?.root.id === parentId) { S.thread.replies = S.thread.replies.filter(x => x.id !== id); renderPanel(); }
  if (S.msgs[channelId]) { S.msgs[channelId] = S.msgs[channelId].filter(x => x.id !== id); if (S.active?.id === channelId) renderMessages(false); }
  if (S.thread?.root.id === id) closePanel();
}

/* ============================================================ Realtime (Echo/Reverb) + polling */
const subscribed = new Set();
function initRealtime() {
  if ((B.reverb || B.pusher) && window.Echo && window.Pusher) {
    try {
      const auth = {authEndpoint: '/broadcasting/auth', auth: {headers: {'X-CSRF-TOKEN': B.csrf, 'X-Requested-With': 'XMLHttpRequest'}}};
      S.echo = B.pusher
        ? new window.Echo({broadcaster: 'pusher', key: B.pusher.key, cluster: B.pusher.cluster, forceTLS: true, ...auth})
        : new window.Echo({broadcaster: 'reverb', key: B.reverb.key, wsHost: B.reverb.host, wsPort: B.reverb.port, wssPort: B.reverb.port, forceTLS: B.reverb.scheme === 'https', enabledTransports: ['ws', 'wss'], ...auth});
      S.echo.connector?.pusher?.connection?.bind('state_change', st => { S.rtState = st.current; });
      S.echo.private(`user.${S.user.id}`).listen('.user.touched', async () => { await loadChannels(); await syncActive(); renderSidebar(); });
      S.echo.private(`App.Models.User.${S.user.id}`).notification(n => { S.notifs.unshift({...n, read_at: null, created_at: new Date().toISOString()}); S.unreadN++; renderBadges(); notifyDesktop(n); });
    } catch (e) { console.warn('Echo non disponibile, uso il polling', e); S.echo = null; }
  }
  startPolling();
}
function subscribeChannel(id) {
  if (!S.echo || subscribed.has(id)) return; subscribed.add(id);
  S.echo.private(`channel.${id}`)
    .listen('.message.sent', e => upsertMessage(e.message))
    .listen('.message.updated', e => upsertMessage(e.message, {scroll: false}))
    .listen('.message.deleted', e => removeMessage(e.id, e.channel_id, e.parent_id))
    .listen('.channel.read', e => applyRead(e));
}
/* Dopo il ricaricamento dei canali: gestisce il canale aperto che è stato archiviato o ripristinato */
async function syncActive() {
  if (!S.active) return;
  const id = S.active.id, wasRO = isRO(S.active);
  let c = S.channels.find(x => x.id === id);
  if (!c && !S.archived[id]) c = await fetchArchived(id);
  c = c || chById(id);
  if (!c) return;
  if (!c.is_archived) c.unread_count = 0;
  S.active = c;
  if (wasRO !== isRO(c)) { renderHeader(); renderComposer(); renderMessages(false); if (S.panel) renderPanel(); }
}
/* WebSocket attivo e connesso? Se Reverb/Pusher cadono si torna automaticamente al polling completo */
const rtOk = () => !!S.echo && (!S.echo.connector?.pusher || S.rtState === 'connected');
function startPolling() {
  clearInterval(S.pollTimer);
  let tick = 0;
  S.pollTimer = setInterval(async () => {
    if (document.hidden) return;
    if (rtOk() && tick++ % 4) return; // con il realtime basta un controllo ogni ~25 s
    const id = S.active?.id;
    if (id && S.msgs[id] && S.route.view === 'chat') {
      const last = S.msgs[id].filter(m => m.id).at(-1)?.id || 0;
      try {
        const r = await api('GET', `/channels/${id}/messages?after=${last}&limit=50`);
        list(r).forEach(m => upsertMessage(m));
        if (r?.meta?.reads && JSON.stringify(r.meta.reads) !== JSON.stringify(S.reads[id])) { S.reads[id] = r.meta.reads; renderMessages(false); }
        if (!rtOk() && S.syncAt[id]) await syncChanges(id);
        if (S.thread && !S.thread.loading) { const t = await api('GET', `/messages/${S.thread.root.id}/thread`); if (t && S.thread) { S.thread.root = t.root; S.thread.replies = list(t); renderPanel(); } }
      } catch {}
    }
    if (!rtOk()) { await loadChannels(); await syncActive(); renderSidebar(); }
    loadNotifs();
  }, 6000);
}
/* Senza WebSocket: recupera modifiche, eliminazioni, reazioni, risposte e anteprime dei link dei messaggi già caricati */
async function syncChanges(id) {
  const r = await api('GET', `/channels/${id}/messages?changed_since=${encodeURIComponent(S.syncAt[id])}`);
  if (r?.meta?.server_time) S.syncAt[id] = r.meta.server_time;
  const arr = S.msgs[id] || [];
  (r?.data || []).forEach(m => {
    const old = arr.find(x => x.id === m.id);
    if (old && JSON.stringify(old) !== JSON.stringify(m)) upsertMessage(m, {scroll: false});
  });
  (r?.deleted || []).forEach(d => arr.some(x => x.id === d) && removeMessage(d, id, null));
}
function notifyDesktop(n) {
  if (!('Notification' in window) || Notification.permission !== 'granted' || document.hasFocus() || S.user.notification_prefs?.push === false) return;
  const t = new Notification(`${n.from} in #${n.channel_name}`, {body: n.excerpt, icon: '/favicon.svg', tag: 'at-' + n.message_id});
  t.onclick = () => { window.focus(); go(`/app/c/${n.channel_id}${n.parent_id ? '/t/' + n.parent_id : ''}`); };
}

/* ============================================================ Rendering: shell */
function render() {
  const root = $('#app');
  root.innerHTML = shellHtml();
  renderSidebar();
  if (S.route.view !== 'chat') { renderPage(); return; }
  renderHeader(); renderMessages(true); renderComposer(); renderPanel(); renderBadges();
}
function shellHtml() {
  const page = S.route.view !== 'chat';
  return `<div class="shell">
    <aside class="sidebar" id="sidebar"></aside>
    <div class="scrim" id="scrim"></div>
    <div class="main-wrap ${S.panel && !page ? 'with-panel' : ''}" id="mainwrap">
      ${page ? `<section class="main page" id="page"></section>` : `<section class="main">${notifBannerHtml()}<header class="ch-head" id="chhead"></header><div class="msgs" id="msgs"></div><div class="composer" id="composer"></div></section>
      ${S.panel ? `<aside class="panel" id="panel"></aside>` : ''}`}
    </div></div>`;
}
/* Barra laterale: DM attivi negli ultimi 60 giorni, canali con attività negli ultimi 90 (configurabili dall'organizzazione) */
const SB = {
  sort: localStorage.getItem('at.sbsort') === 'alpha' ? 'alpha' : 'recent',
  showAll: JSON.parse(sessionStorage.getItem('at.sbshowall') || '{}'),
  scroll: +sessionStorage.getItem('at.sbscroll') || 0,
};
const chActivity = c => Date.parse(c.last_message_at || '') || Date.parse(c.created_at || '') || 0;
function sbRecent(c, days) {
  if (c.unread_count || (S.route.view === 'chat' && S.active?.id === c.id) || c.starred) return true;
  return chActivity(c) >= Date.now() - days * 86400000;
}
function sbSorted(items, mode) {
  const coll = new Intl.Collator('it', {sensitivity: 'base', numeric: true});
  return [...items].sort(mode === 'alpha' ? (x, y) => coll.compare(x.name, y.name) : (x, y) => chActivity(y) - chActivity(x) || coll.compare(x.name, y.name));
}
function renderSidebar() {
  const sb = $('#sidebar'); if (!sb) return;
  const oldScroll = $('.sb-scroll', sb);
  if (oldScroll) SB.scroll = oldScroll.scrollTop;
  $('#scrim') && ($('#scrim').onclick = () => document.body.classList.remove('sb-open'));
  const dmDays = +S.org.sidebar_dm_days || 60, chDays = +S.org.sidebar_channel_days || 90;
  const allPub = S.channels.filter(c => ['public', 'private'].includes(c.type));
  const allDms = S.channels.filter(c => c.type === 'dm');
  const pubVis = SB.showAll.ch ? allPub : allPub.filter(c => sbRecent(c, chDays));
  const dmVis = SB.showAll.dm ? allDms : allDms.filter(c => sbRecent(c, dmDays));
  const pub = sbSorted(pubVis, SB.sort), dms = sbSorted(dmVis, 'recent');
  const ai = S.channels.filter(c => c.type === 'ai_dm');
  const item = c => {
    const active = S.route.view === 'chat' && S.active?.id === c.id;
    const other = c.other_user;
    return `<button class="sb-item ${active ? 'active' : ''} ${c.unread_count ? 'unread' : ''}" data-ch="${c.id}" title="${esc(c.topic || c.name)}">
      ${c.type === 'dm' ? `<span class="dot ${other?.online ? '' : 'off'}"></span>` : chIcon(c)}
      <span class="lbl">${esc(c.name)}</span>
      ${c.notify === 'none' ? ic('bellOff', 's') : ''}${c.unread_count ? `<span class="badge">${c.unread_count}</span>` : ''}</button>`;
  };
  const more = (key, hidden, days, what) => hidden > 0 || SB.showAll[key]
    ? `<button class="sb-more" data-showall="${key}" title="${SB.showAll[key] ? `Nascondi ${what} senza attività negli ultimi ${days} giorni` : `${hidden} ${what} senza attività negli ultimi ${days} giorni`}">${SB.showAll[key] ? `${ic('chevU', 's')} Solo attivi da ${days} giorni` : `${ic('chevD', 's')} Altri ${hidden} meno recenti`}</button>` : '';
  const sec = (key, title, items, addTitle, addAction, extra = '', foot = '') => `<div class="sb-sec ${S.collapsed[key] ? 'collapsed' : ''}">
      <button class="tg" data-toggle="${key}" aria-expanded="${!S.collapsed[key]}">${ic('chevD', 's')} ${title}</button>
      <span class="sb-sec-act">${extra}${addAction ? `<button data-action="${addAction}" title="${addTitle}" aria-label="${addTitle}">${ic('plus', 's')}</button>` : ''}</span></div>
    <div class="${S.collapsed[key] ? 'hide' : ''}">${items.map(item).join('') || `<div class="sb-empty">Nessuna conversazione recente</div>`}${foot}</div>`;
  const sortBtn = `<button data-action="sbsort" title="Ordina canali: ${SB.sort === 'alpha' ? 'alfabetico' : 'per attività recente'}" aria-label="Ordinamento canali">${ic(SB.sort === 'alpha' ? 'sortAz' : 'clock', 's')}</button>`;
  const html = `
    <div class="sb-head"><button class="sb-org" data-action="orgmenu" aria-haspopup="menu"><span>${esc(S.org.name || 'AcidTalk')}</span>${ic('chevD', 's chev')}</button>
      <div class="sb-actions"><button class="sb-btn" data-action="newmsg" title="Nuovo messaggio" aria-label="Nuovo messaggio">${ic('edit')}</button></div></div>
    <button class="sb-search" data-action="search">${ic('search', 's')} Cerca in ${esc(S.org.name || 'AcidTalk')} <kbd>Ctrl K</kbd></button>
    <div class="sb-scroll">
      <nav class="sb-nav">
        <button class="sb-item" data-action="notifs">${ic('bell', 's')}<span class="lbl">Attività</span>${S.unreadN ? `<span class="badge" id="sb-nbadge">${S.unreadN}</span>` : ''}</button>
        <button class="sb-item" data-action="browse">${ic('hash', 's')}<span class="lbl">Sfoglia canali</span></button>
        ${isAdmin() ? `<button class="sb-item ${S.route.view === 'admin' ? 'active' : ''}" data-action="admin">${ic('shield', 's')}<span class="lbl">Amministrazione</span></button>` : ''}
      </nav>
      ${sec('dm', 'Messaggi diretti', dms, 'Nuovo messaggio diretto', 'newdm', '', more('dm', allDms.length - dmVis.length, dmDays, 'messaggi diretti'))}
      ${sec('ch', 'Canali', pub, 'Crea canale', isGuest() ? null : 'newchannel', sortBtn, more('ch', allPub.length - pubVis.length, chDays, 'canali'))}
      ${sec('ai', 'Assistente AI', ai, null, null)}
    </div>
    <div class="sb-foot"><button class="ava ${S.user.avatar_url ? 'img' : ''}" data-action="me" style="background:${esc(S.user.avatar_color)}" title="Profilo">${S.user.avatar_url ? `<img src="${esc(S.user.avatar_url)}" alt="">` : esc(S.user.initials)}<span class="st"></span></button>
      <div class="me"><b>${esc(S.user.name)}</b><small>${esc(S.user.status_text || (S.user.role === 'admin' ? 'Amministratore' : S.user.role === 'guest' ? 'Ospite' : S.user.title || 'Online'))}</small></div>
      <button class="sb-btn" data-action="theme" title="${themeNow() === 'dark' ? 'Passa al tema chiaro' : 'Passa al tema scuro'}" aria-label="Cambia tema">${ic(themeNow() === 'dark' ? 'sun' : 'moon')}</button>
      <button class="sb-btn" data-action="me" title="Preferenze" aria-label="Preferenze">${ic('settings')}</button></div>`;
  if (sb._html === html && oldScroll) return; // niente da aggiornare: evita sfarfallii e salti di scorrimento
  sb._html = html; sb.innerHTML = html;
  const sc = $('.sb-scroll', sb);
  sc.scrollTop = SB.scroll;
  let t; sc.addEventListener('scroll', () => { SB.scroll = sc.scrollTop; clearTimeout(t); t = setTimeout(() => sessionStorage.setItem('at.sbscroll', SB.scroll), 150); }, {passive: true});
  $$('[data-ch]', sb).forEach(b => b.onclick = () => go(`/app/c/${b.dataset.ch}`));
  $$('[data-toggle]', sb).forEach(b => b.onclick = () => { S.collapsed[b.dataset.toggle] = !S.collapsed[b.dataset.toggle]; localStorage.setItem('at.collapsed', JSON.stringify(S.collapsed)); renderSidebar(); });
  $$('[data-showall]', sb).forEach(b => b.onclick = () => { const k = b.dataset.showall; SB.showAll[k] = !SB.showAll[k]; sessionStorage.setItem('at.sbshowall', JSON.stringify(SB.showAll)); renderSidebar(); });
  $$('[data-action]', sb).forEach(b => b.onclick = e => actions[b.dataset.action]?.(e, b));
}
function renderBadges() {
  const n = S.unreadN;
  const b = $('#sb-nbadge'); const navBtn = $('[data-action="notifs"]');
  if (navBtn) { if (n) { if (b) b.textContent = n; else navBtn.insertAdjacentHTML('beforeend', `<span class="badge" id="sb-nbadge">${n}</span>`); } else b?.remove(); }
  const pip = $('#bellpip'); if (pip) { pip.textContent = n; pip.classList.toggle('hide', !n); }
  document.title = (n ? `(${n}) ` : '') + (S.active ? `#${S.active.name} · ` : '') + (S.org.name || 'AcidTalk');
}
function renderHeader() {
  const h = $('#chhead'); if (!h) return; const c = S.active;
  if (!c) { h.innerHTML = `<button class="ibtn menu-btn" data-action="menu" aria-label="Menu">${ic('menu')}</button><b>AcidTalk</b>`; bindActions(h); return; }
  const members = c.member_count ?? c.members?.length ?? 0;
  h.innerHTML = `<button class="ibtn menu-btn" data-action="menu" aria-label="Menu">${ic('menu')}</button>
    <button class="title" data-action="details">${c.type === 'dm' ? avatar(c.other_user, 'sm') : chIcon(c)}<span>${esc(c.name)}</span>${c.type === 'ai_dm' ? `<span class="tag ai">AI</span>` : ''}${isRO(c) ? `<span class="tag arch">${ic('archive', 's')} archiviato</span>` : ''}${ic('chevD', 's')}</button>
    <div class="topic">${esc(c.topic || (c.type === 'ai_dm' ? 'Chiedi qualsiasi cosa all\'assistente' : c.type === 'dm' ? (c.other_user?.title || c.other_user?.email || '') : ''))}</div>
    <div class="ch-actions">
      ${c.type !== 'ai_dm' ? `<button class="members-pill" data-action="details" title="Membri"><span class="stack">${(c.members || []).slice(0, 3).map(u => avatar(u)).join('')}</span><span class="n">${members}</span></button>` : ''}
      ${c.type !== 'ai_dm' && !isRO(c) ? `<button class="ibtn" data-action="call" title="Avvia videochiamata" aria-label="Avvia videochiamata">${ic('video')}</button>` : ''}
      <button class="ibtn" data-action="notifs" title="Notifiche" aria-label="Notifiche">${ic('bell')}<span class="pip ${S.unreadN ? '' : 'hide'}" id="bellpip">${S.unreadN}</span></button>
      <button class="ibtn ${S.panel === 'details' ? 'on' : ''}" data-action="details" title="Dettagli canale" aria-label="Dettagli canale">${ic('info')}</button>
    </div>`;
  bindActions(h);
}
function bindActions(root) { $$('[data-action]', root).forEach(b => b.onclick = e => actions[b.dataset.action]?.(e, b)); }

/* ============================================================ Rendering: messaggi */
function groupable(prev, m) {
  return prev && !prev.is_ai === !m.is_ai && prev.kind !== 'system' && m.kind !== 'system' && prev.user?.id === m.user?.id && (new Date(m.created_at) - new Date(prev.created_at)) < 5 * 60000 && new Date(m.created_at).toDateString() === new Date(prev.created_at).toDateString();
}
function messageHtml(m, prev, {inThread = false} = {}) {
  if (m.kind === 'system') return `<div class="msg system" data-id="${m.id}"><div class="body">${m.html || esc(m.body)}</div></div>`;
  const compact = !inThread && groupable(prev, m);
  const ro = isRO(S.active);
  const u = m.user || {}; const role = u.is_ai ? '' : u.role === 'guest' ? '<span class="pill guest">ospite</span>' : u.role === 'admin' ? '<span class="pill admin">admin</span>' : '';
  const tools = `<div class="tools" role="toolbar" aria-label="Azioni messaggio">
      ${['👍', '✅', '👀', '🎉'].map(e => `<button data-react="${e}" title="Reagisci ${e}">${e}</button>`).join('')}
      <button data-act="emoji" title="Aggiungi reazione">${ic('smile', 's')}</button><span class="sep"></span>
      ${!inThread ? `<button data-act="thread" title="Rispondi nel thread">${ic('thread', 's')}</button>` : ''}
      ${m.can_edit ? `<button data-act="edit" title="Modifica">${ic('edit', 's')}</button>` : ''}
      ${m.can_delete ? `<button data-act="delete" class="danger" title="Elimina">${ic('trash', 's')}</button>` : ''}
      <button data-act="more" title="Altro">${ic('more', 's')}</button></div>`;
  const reacts = (m.reactions || []).length ? `<div class="reacts">${m.reactions.map(r => `<button class="react ${r.me ? 'me' : ''}" data-react="${esc(r.emoji)}" title="${(r.user_ids || []).map(id => userById(id)?.name || '…').join(', ')}">${r.emoji} ${r.count}</button>`).join('')}${ro ? '' : `<button class="react add" data-act="emoji" title="Aggiungi reazione">${ic('smile', 's')}</button>`}</div>` : '';
  const thread = !inThread && m.reply_count ? `<button class="thread-link" data-act="thread"><span class="stack">${avatar(m.user, 'sm')}</span>${m.reply_count} ${m.reply_count === 1 ? 'risposta' : 'risposte'} <small>· ultima ${fmtRel(m.last_reply_at || m.created_at)}</small></button>` : '';
  const edited = m.edited_at ? `<span class="edited" title="Modificato ${fmtRel(m.edited_at)}">(modificato)</span>` : '';
  const body = m.editing ? editBoxHtml(m) : `<div class="body">${m.kind === 'voice' && !(m.body || '').trim() ? '' : (m.html || esc(m.body))}${edited}</div>${attachmentsHtml(m)}${previewsHtml(m)}`;
  const rc = receiptHtml(m);
  return `<div class="msg ${compact ? 'compact' : ''} ${rc ? 'has-rcpt' : ''} ${m.is_ai ? 'ai' : ''} ${m.pending ? 'pending' : ''} ${m.failed ? 'failed' : ''}" data-id="${m.id || ''}" data-tmp="${m.tmp || ''}" id="m-${m.id}">
    ${compact ? `<span class="time-side">${fmtTime(m.created_at)}</span>` : ''}${avatar(u)}
    <div style="min-width:0">${compact ? '' : `<div class="head"><b>${esc(u.name || 'Utente')}</b>${u.is_ai ? '<span class="pill">AI</span>' : botPill(u) || role}<time datetime="${m.created_at}" title="${new Date(m.created_at).toLocaleString('it-IT')}">${fmtTime(m.created_at)}</time></div>`}
      ${body}${m.failed ? `<button class="retry" data-act="retry">Invio non riuscito · Riprova</button>` : ''}${reacts}${thread}</div>${rc}${m.pending || m.editing || ro ? '' : tools}</div>`;
}
/* ============================================================ Player audio (vocali e allegati audio)
   Un solo elemento <audio> condiviso: la riproduzione continua anche quando la lista messaggi viene ridisegnata. */
const AP = {el: new Audio(), id: null, dur: 0, peaks: new Map(), rates: [1, 1.5, 2]};
AP.el.preload = 'metadata';
const AP_BARS = 40;
function apSeedBars(id) { let x = (+id || 7) * 9301 + 49297; return Array.from({length: AP_BARS}, (_, i) => { x = (x * 9301 + 49297) % 233280; const env = Math.sin(Math.PI * (i + .5) / AP_BARS) * .45 + .55; return Math.max(.18, (x / 233280) * env); }); }
function audioPlayerHtml(a, {voice = false, who = ''} = {}) {
  const bars = AP.peaks.get(a.id) || apSeedBars(a.id);
  const dur = +a.meta?.duration || 0, on = AP.id === a.id;
  const p = on && AP.dur ? AP.el.currentTime / AP.dur : 0;
  const rate = on ? AP.el.playbackRate : 1;
  return `<div class="ap ${voice ? 'voice' : 'file'} ${on && !AP.el.paused ? 'playing' : ''}" data-ap="${a.id}" data-src="${esc(a.url)}" data-dur="${dur}" data-title="${esc(voice ? 'Messaggio vocale' + (who ? ' di ' + who : '') : a.name)}">
    <button type="button" class="ap-pl" data-apact="play" aria-label="${on && !AP.el.paused ? 'Pausa' : 'Riproduci'}">${ic(on && !AP.el.paused ? 'pause' : 'play', 's')}</button>
    <div class="ap-main">${voice ? '' : `<div class="ap-name">${ic('audio', 's')}<span>${esc(a.name)}</span><small>${fmtSize(a.size)}</small></div>`}
      <div class="ap-wave" data-apact="seek" role="slider" tabindex="0" aria-label="Posizione di riproduzione" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(p * 100)}">${bars.map((h, i) => `<i class="${i / AP_BARS < p ? 'on' : ''}" style="height:${Math.round(h * 100)}%"></i>`).join('')}</div></div>
    <span class="ap-t">${on && AP.el.currentTime ? fmtDur(AP.el.currentTime) + ' / ' : ''}${dur || (on && AP.dur) ? fmtDur(dur || AP.dur) : '—:—'}</span>
    <button type="button" class="ap-sp" data-apact="speed" title="Velocità di riproduzione" aria-label="Velocità ${rate}x">${rate}×</button>
    <a class="ap-dl" href="${esc(a.url)}" download="${esc(a.name)}" title="Scarica" aria-label="Scarica">${ic('download', 's')}</a>
  </div>`;
}
function apWidgets(id = AP.id) { return id == null ? [] : $$(`.ap[data-ap="${id}"]`); }
function apSync() {
  const el = AP.el, playing = !el.paused && !el.ended;
  if (isFinite(el.duration) && el.duration > 0) AP.dur = el.duration;
  const p = AP.dur ? Math.min(1, el.currentTime / AP.dur) : 0;
  apWidgets().forEach(w => {
    w.classList.toggle('playing', playing);
    const b = $('.ap-pl', w); const want = playing ? 'pause' : 'play';
    if (b.dataset.st !== want) { b.dataset.st = want; b.innerHTML = ic(want, 's'); b.setAttribute('aria-label', playing ? 'Pausa' : 'Riproduci'); }
    const n = Math.floor(p * AP_BARS + .0001);
    $$('.ap-wave i', w).forEach((bar, i) => bar.classList.toggle('on', i < n));
    $('.ap-wave', w).setAttribute('aria-valuenow', Math.round(p * 100));
    $('.ap-t', w).textContent = (el.currentTime || playing ? fmtDur(el.currentTime) + ' / ' : '') + (AP.dur ? fmtDur(AP.dur) : '—:—');
    $('.ap-sp', w).textContent = el.playbackRate + '×';
  });
}
['play', 'pause', 'timeupdate', 'durationchange', 'loadedmetadata', 'ratechange', 'seeked'].forEach(ev => AP.el.addEventListener(ev, apSync));
AP.el.addEventListener('ended', () => { AP.el.currentTime = 0; apSync(); });
AP.el.addEventListener('error', () => { if (AP.id != null) { toast('Impossibile riprodurre questo audio: formato non supportato dal browser. Puoi scaricarlo.', 'err'); apWidgets().forEach(w => w.classList.remove('playing')); } });
async function apPeaks(id, src) {
  if (AP.peaks.has(id) || !window.AudioContext && !window.webkitAudioContext) return;
  try { if (new URL(src, location.href).origin !== location.origin) return; } catch { return; }
  try {
    const buf = await (await fetch(src, {credentials: 'same-origin'})).arrayBuffer();
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const data = (await ctx.decodeAudioData(buf)).getChannelData(0); ctx.close?.();
    const step = Math.floor(data.length / AP_BARS) || 1; const peaks = [];
    for (let i = 0; i < AP_BARS; i++) { let mx = 0; for (let j = i * step; j < (i + 1) * step && j < data.length; j += 16) mx = Math.max(mx, Math.abs(data[j])); peaks.push(mx); }
    const top = Math.max(...peaks) || 1; const norm = peaks.map(v => Math.max(.12, v / top));
    AP.peaks.set(id, norm);
    apWidgets(id).forEach(w => $$('.ap-wave i', w).forEach((bar, i) => bar.style.height = Math.round(norm[i] * 100) + '%'));
  } catch { /* forma d'onda decorativa: in caso di errore restano le barre generate */ }
}
function apLoad(w) {
  const id = +w.dataset.ap;
  if (AP.id === id) return;
  const prev = AP.id;
  AP.el.pause(); AP.id = id; AP.dur = +w.dataset.dur || 0; const rate = AP.el.playbackRate;
  apWidgets(prev).forEach(x => { x.classList.remove('playing'); const b = $('.ap-pl', x); b.dataset.st = 'play'; b.innerHTML = ic('play', 's'); b.setAttribute('aria-label', 'Riproduci');
    $$('.ap-wave i', x).forEach(i => i.classList.remove('on')); $('.ap-wave', x).setAttribute('aria-valuenow', 0); const d = +x.dataset.dur; $('.ap-t', x).textContent = d ? fmtDur(d) : '—:—'; $('.ap-sp', x).textContent = '1×'; });
  AP.el.src = w.dataset.src; AP.el.playbackRate = rate; AP.el.defaultPlaybackRate = rate;
  $$('.ap.playing').forEach(x => x.classList.remove('playing'));
  if ('mediaSession' in navigator) try { navigator.mediaSession.metadata = new MediaMetadata({title: w.dataset.title, artist: S.org?.name || 'AcidTalk'}); } catch {}
  apPeaks(id, w.dataset.src);
}
function apToggle(w) {
  apLoad(w);
  $$('audio,video').forEach(x => x.pause());
  if (AP.el.paused) AP.el.play().catch(err => { if (err?.name !== 'AbortError') toast('Riproduzione non riuscita: ' + (err?.message || 'errore'), 'err'); });
  else AP.el.pause();
}
function apSeekTo(w, frac) {
  apLoad(w); const d = AP.dur || +w.dataset.dur || 0; if (!d) return apToggle(w);
  const go = () => { AP.el.currentTime = Math.max(0, Math.min(d - .05, frac * d)); apSync(); };
  AP.el.readyState >= 1 ? go() : AP.el.addEventListener('loadedmetadata', go, {once: true});
  if (AP.el.paused) AP.el.play().catch(() => {});
}
document.addEventListener('click', e => {
  const b = e.target.closest('[data-apact]'); if (!b) return;
  const w = b.closest('.ap'); e.preventDefault(); e.stopPropagation();
  if (b.dataset.apact === 'play') return apToggle(w);
  if (b.dataset.apact === 'seek') { const r = b.getBoundingClientRect(); return apSeekTo(w, (e.clientX - r.left) / r.width); }
  if (b.dataset.apact === 'speed') { const i = AP.rates.indexOf(AP.el.playbackRate); AP.el.playbackRate = AP.rates[(i + 1) % AP.rates.length]; apLoad(w); apSync(); b.textContent = AP.el.playbackRate + '×'; }
}, true);
document.addEventListener('keydown', e => {
  const wv = e.target.closest?.('.ap-wave'); if (!wv) return;
  const w = wv.closest('.ap');
  if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); return apToggle(w); }
  if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); apLoad(w); const d = AP.dur || +w.dataset.dur || 0; if (d) { AP.el.currentTime = Math.max(0, Math.min(d, AP.el.currentTime + (e.key === 'ArrowRight' ? 5 : -5))); apSync(); } }
});
if ('mediaSession' in navigator) try { navigator.mediaSession.setActionHandler('play', () => AP.el.play()); navigator.mediaSession.setActionHandler('pause', () => AP.el.pause()); } catch {}

function attachmentsHtml(m) {
  const atts = m.attachments || []; if (!atts.length) return '';
  const isAudio = a => a.kind === 'audio' || /^audio\//.test(a.mime || '') || /\.(mp3|m4a|aac|ogg|oga|opus|wav|flac|weba|mka)$/i.test(a.name || '') || (m.kind === 'voice' && /webm|mp4|ogg/.test(a.mime || ''));
  if (m.kind === 'voice' && atts[0] && isAudio(atts[0])) return audioPlayerHtml(atts[0], {voice: true, who: m.user?.name});
  return `<div class="atts">${atts.map(a => {
    if (isAudio(a)) return audioPlayerHtml(a, {who: m.user?.name});
    if (a.kind === 'video') return `<div class="att video"><video controls preload="metadata" playsinline src="${esc(a.url)}"></video><div class="vf"><span class="fn">${esc(a.name)}</span><a href="${esc(a.url)}" download="${esc(a.name)}" title="Scarica" aria-label="Scarica">${ic('download', 's')}</a></div></div>`;
    if (a.kind === 'image') return `<a class="att image" href="${esc(a.url)}" data-act="lightbox" title="${esc(a.name)}"><img src="${esc(a.url)}" alt="${esc(a.name)}" loading="lazy"></a>`;
    const pdf = a.kind === 'pdf';
    return `<div class="att file ${pdf ? 'pdf' : ''}" data-att="${a.id}"><span class="fic ${pdf ? 'pdf' : ''}">${ic(pdf ? 'filePdf' : 'file')}</span><div style="min-width:0"><div class="fn">${esc(a.name)}</div><div class="fm">${pdf ? 'PDF' : (a.mime || 'file').split('/').pop().toUpperCase()} · ${fmtSize(a.size)}</div></div>
      <div class="fa">${pdf ? `<button data-act="pdf" title="Anteprima PDF" aria-label="Anteprima PDF">${ic('eye', 's')}</button>` : ''}<a href="${esc(a.url)}" download="${esc(a.name)}" title="Scarica" aria-label="Scarica">${ic('download', 's')}</a></div></div>`;
  }).join('')}</div>`;
}
const ytId = u => (u.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/) || [])[1];
const vimeoId = u => (u.match(/vimeo\.com\/(?:video\/)?(\d{6,})/) || [])[1];
function previewsHtml(m) {
  const lps = m.link_previews || []; if (!lps.length) return '';
  const canRm = m.can_edit || isAdmin();
  const rm = p => canRm ? `<button type="button" class="lp-x" data-lpact="remove" data-mid="${m.id}" data-pid="${p.id}" title="Rimuovi anteprima" aria-label="Rimuovi anteprima">${ic('x', 's')}</button>` : '';
  const host = u => { try { return new URL(u).hostname; } catch { return ''; } };
  const fav = p => { const h = host(p.url); return h ? `<img class="fav" src="https://${esc(h)}/favicon.ico" alt="" loading="lazy" onerror="this.remove()">` : ''; };
  return `<div class="lps">${lps.map(p => {
    if (p.kind === 'video_call') return `<div class="lp call"><span class="ic">${ic('video')}</span><div style="min-width:0"><span class="site">${esc(p.site_name || '')}</span><span class="t" style="color:var(--text)">${esc(p.title || 'Videochiamata')}</span><div class="d">${esc(p.description || '')}</div></div><a class="join" href="${esc(p.url)}" target="_blank" rel="noopener">Partecipa</a></div>`;
    if (p.kind === 'image') return `<div class="lp-wrap img">${rm(p)}<a class="att image" href="${esc(p.url)}" data-act="lightbox"><img src="${esc(p.image)}" alt="" loading="lazy" style="max-height:260px"></a></div>`;
    if (p.kind === 'video') return `<div class="lp video">${rm(p)}<span class="site">${p.provider === 'youtube' ? ic('play', 's') : ic('video', 's')} ${esc(p.site_name || '')}</span>
      <a class="t" href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.title || p.url)}</a>${p.description ? `<div class="d">${esc(p.description)}</div>` : ''}
      ${p.image ? `<button type="button" class="lp-player" data-lpact="play" data-provider="${esc(p.provider)}" data-url="${esc(p.url)}" aria-label="Riproduci il video"><img src="${esc(p.image)}" alt="" loading="lazy"><span class="pbtn">${ic('play')}</span></button>` : ''}</div>`;
    const large = p.provider === 'large' && p.image;
    return `<div class="lp card ${large ? 'large' : ''}">${rm(p)}${large ? `<a class="lp-big" href="${esc(p.url)}" target="_blank" rel="noopener" tabindex="-1"><img src="${esc(p.image)}" alt="" loading="lazy" onerror="this.parentNode.remove()"></a>` : ''}<div class="lp-body"><span class="site">${fav(p)}${esc(p.site_name || host(p.url))}</span>
      <a class="t" href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.title || p.url)}</a>${p.description ? `<div class="d">${esc(p.description)}</div>` : ''}
      <span class="lp-host">${ic('link', 's')} ${esc(host(p.url))}</span></div>
      ${!large && p.image ? `<a class="lp-thumb" href="${esc(p.url)}" target="_blank" rel="noopener" tabindex="-1"><img src="${esc(p.image)}" alt="" loading="lazy" onerror="this.parentNode.remove()"></a>` : ''}</div>`;
  }).join('')}</div>`;
}
document.addEventListener('click', async e => {
  const b = e.target.closest('[data-lpact]'); if (!b) return;
  e.preventDefault(); e.stopPropagation();
  if (b.dataset.lpact === 'play') {
    const u = b.dataset.url, id = b.dataset.provider === 'youtube' ? ytId(u) : vimeoId(u);
    if (!id) return window.open(u, '_blank', 'noopener');
    const src = b.dataset.provider === 'youtube' ? `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0` : `https://player.vimeo.com/video/${id}?autoplay=1`;
    AP.el.pause();
    b.outerHTML = `<div class="lp-frame"><iframe src="${src}" title="Video" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>`;
  }
  if (b.dataset.lpact === 'remove') {
    try { upsertMessage(one(await api('DELETE', `/messages/${b.dataset.mid}/previews/${b.dataset.pid}`)), {scroll: false}); } catch (err) { toast(err.message, 'err'); }
  }
}, true);
function editBoxHtml(m) {
  return `<div class="edit-box" data-edit="${m.id}"><textarea aria-label="Modifica messaggio">${esc(m.body)}</textarea><div class="acts"><span class="hint">Invio per salvare · Esc per annullare</span><button class="btn ghost sm" data-act="edit-cancel">Annulla</button><button class="btn ok sm" data-act="edit-save">Salva</button></div></div>`;
}
function renderMessages(stick) {
  const box = $('#msgs'); if (!box) return; const c = S.active;
  if (!c) { box.innerHTML = `<div class="empty"><span class="ico">${ic('hash', 'l')}</span><h3>Benvenuto in ${esc(S.org.name || 'AcidTalk')}</h3><p>Seleziona un canale dalla barra laterale oppure creane uno nuovo.</p></div>`; return; }
  const wasBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
  const msgs = S.msgs[c.id];
  let html = '';
  if (S.more[c.id]) html += `<div style="text-align:center;padding:6px"><button class="btn subtle sm" data-act="older">${ic('refresh', 's')} Carica messaggi precedenti</button></div>`;
  else if (msgs) html += introHtml(c);
  if (!msgs) html += `<div class="empty"><p>Caricamento…</p></div>`;
  else if (!msgs.length) html += `<div class="empty"><span class="ico">${ic('zap', 'l')}</span><h3>Ancora nessun messaggio</h3><p>${c.type === 'ai_dm' ? 'Scrivi una domanda: l\'assistente risponde qui in privato.' : 'Scrivi il primo messaggio per iniziare la conversazione.'}</p></div>`;
  else {
    let prevDay = null, prev = null;
    for (const m of msgs) {
      const day = new Date(m.created_at).toDateString();
      if (day !== prevDay) { html += `<div class="day"><span>${fmtDay(m.created_at)}</span></div>`; prevDay = day; prev = null; }
      html += messageHtml(m, prev); prev = m;
    }
    html += seenByHtml(msgs);
    if (S_TYPING['c' + c.id]) html += typingHtml();
  }
  box.innerHTML = html;
  bindMessageEvents(box);
  if (stick || wasBottom) box.scrollTop = box.scrollHeight;
  box._stick = !!(stick || wasBottom);
  // immagini e anteprime caricate dopo il rendering: se eravamo in fondo, restiamo in fondo
  $$('img', box).forEach(img => { if (!img.complete) img.addEventListener('load', () => { if (box._stick) box.scrollTop = box.scrollHeight; }, {once: true}); });
  if (!box._stickBound) { box._stickBound = true; box.addEventListener('scroll', () => { box._stick = box.scrollHeight - box.scrollTop - box.clientHeight < 80; }, {passive: true}); }
  box.onscroll = debounce(async () => { if (box.scrollTop < 60 && S.more[c.id] && !S.loading[c.id]) { const h = box.scrollHeight; await loadMessages(c.id, S.msgs[c.id][0]?.id); renderMessages(false); box.scrollTop = box.scrollHeight - h; } }, 120);
}
function introHtml(c) {
  if (c.type === 'ai_dm') return `<div class="intro"><span class="ava ai lg">${ic('sparkles', 'l')}</span><h2>${esc(c.name)} <span class="tag ai">assistente</span></h2><p>Questo è il tuo spazio privato con l'assistente AI. Puoi chiedere riassunti, bozze, codice o spiegazioni. Nei canali puoi coinvolgerlo scrivendo <b>@ai</b>.</p></div>`;
  if (c.type === 'dm') return `<div class="intro">${avatar(c.other_user, 'lg')}<h2>${esc(c.name)}</h2><p>${esc(c.other_user?.title || c.other_user?.email || '')}<br>Questa conversazione è privata tra te e ${esc(c.name)}.</p></div>`;
  return `<div class="intro"><h2>${chIcon(c)} ${esc(c.name)}</h2><p>${c.type === 'private' ? 'Canale privato: solo i membri invitati possono leggerlo.' : 'Questo è l\'inizio del canale pubblico.'} ${c.topic ? `— <i>${esc(c.topic)}</i>` : ''}${c.description ? `<br>${esc(c.description)}` : ''}</p></div>`;
}
function msgFromEl(el) {
  const wrap = el.closest('.msg'); if (!wrap) return null; const id = +wrap.dataset.id;
  const inThread = !!el.closest('#panel');
  if (inThread && S.thread) return S.thread.root.id === id ? S.thread.root : S.thread.replies.find(m => m.id === id || (wrap.dataset.tmp && m.tmp === wrap.dataset.tmp));
  return (S.msgs[S.active?.id] || []).find(m => m.id === id || (wrap.dataset.tmp && m.tmp === wrap.dataset.tmp));
}
function bindMessageEvents(root) {
  root.onclick = async e => {
    const btn = e.target.closest('[data-act],[data-react]'); if (!btn || !root.contains(btn)) return;
    const m = msgFromEl(btn);
    if (btn.dataset.react) { e.preventDefault(); return react(m, btn.dataset.react); }
    const act = btn.dataset.act;
    if (act === 'older') { const h = root.scrollHeight; await loadMessages(S.active.id, S.msgs[S.active.id][0]?.id); renderMessages(false); root.scrollTop = root.scrollHeight - h; return; }
    if (act === 'lightbox') { e.preventDefault(); const a = btn.closest('a'); modal({cls: 'lightbox', body: `<img src="${esc(a.href)}" alt="">`}); return; }
    if (act === 'pdf') { const card = btn.closest('.att'); const a = m.attachments.find(x => x.id === +card.dataset.att); const f = $('.pdf-frame', card); if (f) { f.remove(); card.classList.remove('pdf-open'); } else { card.classList.add('pdf-open'); card.insertAdjacentHTML('beforeend', `<iframe class="pdf-frame" src="${esc(a.url)}#toolbar=1&view=FitH" title="${esc(a.name)}"></iframe>`); } return; }
    if (!m) return;
    if (act === 'thread') return openThread(m.id);
    if (act === 'emoji') return emojiPicker(btn, em => react(m, em));
    if (act === 'edit') return startEdit(m);
    if (act === 'delete') return deleteMessage(m);
    if (act === 'edit-cancel') { m.editing = false; return rerenderOne(m); }
    if (act === 'edit-save') return saveEdit(m, $('textarea', btn.closest('.edit-box')).value);
    if (act === 'retry') return retrySend(m);
    if (act === 'more') return messageMenu(btn, m);
  };
  root.onkeydown = e => {
    const ta = e.target.closest('.edit-box textarea'); if (!ta) return; const m = msgFromEl(ta);
    if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); if (e.shiftKey || e.altKey) listNewline(ta); else saveEdit(m, ta.value); }
    if (e.key === 'Escape') { e.stopPropagation(); m.editing = false; rerenderOne(m); }
  };
}
function rerenderOne(m) {
  if (m.parent_id || (S.thread && S.thread.root.id === m.id)) renderPanel();
  if (!m.parent_id) renderMessages(false);
}
function startEdit(m) {
  if (!m.can_edit) return; m.editing = true; rerenderOne(m);
  const ta = $(`.edit-box[data-edit="${m.id}"] textarea`); if (ta) { ta.focus(); ta.selectionStart = ta.value.length; autosize(ta); }
}
async function saveEdit(m, body) {
  body = body.trim(); if (!body) return toast('Il messaggio non può essere vuoto', 'err');
  m.editing = false;
  if (body === m.body) return rerenderOne(m);
  try { const r = one(await api('PUT', `/messages/${m.id}`, {body})); upsertMessage(r, {scroll: false}); toast('Messaggio modificato', 'ok'); }
  catch (e) { toast(e.message, 'err'); rerenderOne(m); }
}
async function deleteMessage(m) {
  const ok = await confirmModal({title: 'Eliminare il messaggio?', text: 'L\'operazione non può essere annullata. Il messaggio verrà rimosso per tutti i partecipanti.', ok: 'Elimina', danger: true});
  if (!ok) return;
  try { await api('DELETE', `/messages/${m.id}`); removeMessage(m.id, m.channel_id, m.parent_id); if (m.parent_id) { const root = (S.msgs[m.channel_id] || []).find(x => x.id === m.parent_id); if (root && root.reply_count > 0) { root.reply_count--; renderMessages(false); } } toast('Messaggio eliminato', 'ok'); }
  catch (e) { toast(e.message, 'err'); }
}
async function react(m, emoji) {
  if (!m?.id) return;
  const ex = (m.reactions || []).find(r => r.emoji === emoji);
  if (ex) { if (ex.me) { ex.count--; ex.me = false; ex.user_ids = (ex.user_ids || []).filter(i => i !== S.user.id); } else { ex.count++; ex.me = true; (ex.user_ids ||= []).push(S.user.id); } m.reactions = m.reactions.filter(r => r.count > 0); }
  else (m.reactions ||= []).push({emoji, count: 1, me: true, user_ids: [S.user.id]});
  rerenderOne(m);
  try { upsertMessage(one(await api('POST', `/messages/${m.id}/react`, {emoji})), {scroll: false}); } catch (e) { toast(e.message, 'err'); }
}
function messageMenu(btn, m) {
  const wrap = btn.closest('.msg'); wrap.classList.add('menu-open');
  popover(btn, `
    <button class="mi" data-k="copy">${ic('copy', 's')} Copia testo</button>
    <button class="mi" data-k="link">${ic('link', 's')} Copia link al messaggio</button>
    ${!m.parent_id ? `<button class="mi" data-k="thread">${ic('thread', 's')} Rispondi nel thread</button>` : ''}
    ${m.can_edit ? `<div class="sep"></div><button class="mi" data-k="edit">${ic('edit', 's')} Modifica messaggio <small>↑</small></button>` : ''}
    ${m.can_delete ? `<button class="mi danger" data-k="delete">${ic('trash', 's')} Elimina messaggio</button>` : ''}`, {align: 'right', onOpen: p => {
    p.onclick = e => { const k = e.target.closest('[data-k]')?.dataset.k; if (!k) return; closePop();
      if (k === 'copy') navigator.clipboard?.writeText(m.body || '').then(() => toast('Testo copiato', 'ok'));
      if (k === 'link') navigator.clipboard?.writeText(`${location.origin}/app/c/${m.channel_id}${m.parent_id ? '/t/' + m.parent_id : ''}#m-${m.id}`).then(() => toast('Link copiato', 'ok'));
      if (k === 'thread') openThread(m.id); if (k === 'edit') startEdit(m); if (k === 'delete') deleteMessage(m); };
  }});
  const obs = new MutationObserver(() => { if (!document.body.contains(popEl)) { wrap.classList.remove('menu-open'); obs.disconnect(); } });
  obs.observe(document.body, {childList: true});
}

/* ============================================================ Emoji */
/* Dataset completo (emoji-data.js, gemoji MIT con alias e parole chiave italiane): [emoji, alias, parole chiave, categoria] */
const EMO = window.AT_EMOJI || {cats: ['Faccine'], list: [['👍', '+1 pollice', 'ok', 0], ['❤️', 'heart cuore', 'amore', 7], ['😂', 'joy ridere', 'risata', 0]]};
const EMO_RECENT_KEY = 'at.emoji.recent';
const emoRecent = () => { try { return JSON.parse(localStorage.getItem(EMO_RECENT_KEY) || '[]'); } catch { return []; } };
function emoUse(e) { const r = [e, ...emoRecent().filter(x => x !== e)].slice(0, 24); localStorage.setItem(EMO_RECENT_KEY, JSON.stringify(r)); }
const EMO_DEFAULT = ['👍', '❤️', '😂', '🎉', '🙏', '👀', '✅', '🔥', '🚀', '💯', '👏', '🤔', '😅', '🙌', '✨', '💪'];
/** Ricerca per alias (:codice:) e parole chiave, italiano e inglese; prima gli alias che iniziano con la query. */
function emojiSearch(q, limit = 80) {
  q = q.toLowerCase().trim().replace(/^:|:$/g, ''); if (!q) return [];
  const starts = [], aliasHas = [], kw = [];
  for (const it of EMO.list) {
    const al = it[1].split(' ');
    if (al.some(a => a.startsWith(q))) starts.push(it); else if (it[1].includes(q)) aliasHas.push(it); else if (it[2].includes(q)) kw.push(it);
    if (starts.length >= limit) break;
  }
  return [...starts, ...aliasHas, ...kw].slice(0, limit);
}
const emoTitle = it => ':' + it[1].split(' ')[0] + ':';
function emojiPicker(anchor, onPick) {
  const recent = emoRecent(); const freq = (recent.length ? recent : EMO_DEFAULT);
  const cats = ['Frequenti', ...EMO.cats]; let cat = 0;
  const icons = ['🕘', '😀', '🧑', '🐶', '🍕', '⚽', '✈️', '💡', '❤️', '🏳️'];
  const btn = it => `<button data-e="${it[0]}" title="${esc(emoTitle(it))}">${it[0]}</button>`;
  const byEmoji = e => EMO.list.find(x => x[0] === e) || [e, '', '', 0];
  const grid = (q = '') => q ? (emojiSearch(q, 160).map(btn).join('') || '<p class="emo-empty">Nessuna emoji trovata</p>')
    : cat === 0 ? freq.map(e => btn(byEmoji(e))).join('') : EMO.list.filter(x => x[3] === cat - 1).map(btn).join('');
  popover(anchor, `<input class="inp" placeholder="Cerca emoji (es. cuore, festa, ok)…" aria-label="Cerca emoji"><div class="cats" role="tablist">${cats.map((c, i) => `<button data-c="${i}" class="${i === cat ? 'on' : ''}" title="${esc(c)}" aria-label="${esc(c)}">${icons[i] || '•'}</button>`).join('')}</div><div class="emo-cat">${esc(cats[cat])}</div><div class="grid">${grid()}</div><div class="emo-foot"><span class="emo-prev"></span><span class="emo-code"></span></div>`, {cls: 'emoji-pop', onOpen: p => {
    const inp = $('input', p); inp.focus();
    inp.oninput = debounce(() => { $('.grid', p).innerHTML = grid(inp.value); $('.emo-cat', p).textContent = inp.value ? 'Risultati' : cats[cat]; }, 80);
    inp.onkeydown = e => { if (e.key === 'Enter') { const f = $('.grid [data-e]', p); if (f) { e.preventDefault(); closePop(); emoUse(f.dataset.e); onPick(f.dataset.e); } } };
    p.onmouseover = e => { const b = e.target.closest('[data-e]'); if (b) { $('.emo-prev', p).textContent = b.dataset.e; $('.emo-code', p).textContent = b.title; } };
    p.onclick = e => { const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.c) { cat = +b.dataset.c; inp.value = ''; $$('.cats button', p).forEach(x => x.classList.toggle('on', x === b)); $('.emo-cat', p).textContent = cats[cat]; const g = $('.grid', p); g.innerHTML = grid(); g.scrollTop = 0; }
      if (b.dataset.e) { closePop(); emoUse(b.dataset.e); onPick(b.dataset.e); } };
  }});
}

/* --- inserimento rapido con ":" (es. :cuo → ❤️) --- */
let emoState = null;
function emojiWatch(ta) {
  const v = ta.value.slice(0, ta.selectionStart); const m = v.match(/(^|[\s(]):([a-z0-9_+\-àèéìòù]{2,30})$/i);
  if (!m) { if (emoState) { closePop(); emoState = null; } return false; }
  const cands = emojiSearch(m[2], 8);
  if (!cands.length) { if (emoState) { closePop(); emoState = null; } return false; }
  emoState = {ta, cands, idx: 0, start: ta.selectionStart - m[2].length - 1};
  const p = popEl?.classList.contains('emo-ac') ? popEl : popover(ta, '', {cls: 'mention-pop emo-ac'});
  p.innerHTML = `<div class="ac-h">Emoji corrispondenti a <b>:${esc(m[2])}</b></div>` + cands.map((it, i) => `<button type="button" class="u ${i === 0 ? 'on' : ''}" data-i="${i}"><span class="emo">${it[0]}</span><b>${esc(emoTitle(it))}</b><small>${esc(it[1].split(' ').slice(1, 3).join(' '))}</small></button>`).join('');
  const r = ta.getBoundingClientRect(); p.style.top = Math.max(8, r.top - p.offsetHeight - 6) + 'px'; p.style.left = r.left + 'px';
  p.onmousedown = e => { const b = e.target.closest('[data-i]'); if (b) { e.preventDefault(); emojiPick(+b.dataset.i); } };
  return true;
}
function emojiPick(i) {
  const st = emoState; if (!st) return; const e = st.cands[i][0], ta = st.ta;
  ta.value = ta.value.slice(0, st.start) + e + ' ' + ta.value.slice(ta.selectionStart);
  ta.selectionStart = ta.selectionEnd = st.start + e.length + 1; closePop(); emoState = null; emoUse(e); ta.dispatchEvent(new Event('input')); ta.focus();
}
function emojiKey(e, ta) {
  if (!emoState || emoState.ta !== ta || !popEl) return false;
  const n = emoState.cands.length;
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); emoState.idx = (emoState.idx + (e.key === 'ArrowDown' ? 1 : -1) + n) % n; $$('.u', popEl).forEach((b, i) => b.classList.toggle('on', i === emoState.idx)); return true; }
  if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); emojiPick(emoState.idx); return true; }
  if (e.key === 'Escape') { closePop(); emoState = null; return true; }
  return false;
}

/* ============================================================ Composer */
function renderComposer() {
  const box = $('#composer'); if (!box) return; const c = S.active;
  if (!c) { box.innerHTML = ''; return; }
  if (isRO(c)) {
    const canManage = isAdmin() || c.created_by === S.user.id;
    box.innerHTML = `<div class="joinbar archbar"><span class="arch-ic">${ic('archive')}</span><div class="arch-txt"><b>#${esc(c.name)} è archiviato</b><p>Archiviato il ${new Date(c.archived_at).toLocaleDateString('it-IT', {day: 'numeric', month: 'long', year: 'numeric'})}. Puoi leggere e cercare i messaggi, ma non scriverne di nuovi.</p></div>${canManage ? `<button class="btn primary" data-unarch>${ic('refresh', 's')} Ripristina canale</button>` : ''}</div>`;
    const b = $('[data-unarch]', box); if (b) b.onclick = async () => { b.disabled = true; if (!await setArchived(c, false)) b.disabled = false; };
    return;
  }
  if (!c.is_member && c.type === 'public') {
    box.innerHTML = `<div class="joinbar"><b>Stai visualizzando #${esc(c.name)}</b><p>${esc(c.topic || 'Unisciti al canale per partecipare alla conversazione.')}</p><button class="btn primary" data-join>${ic('plus', 's')} Unisciti al canale</button></div>`;
    $('[data-join]', box).onclick = async () => { try { const r = one(await api('POST', `/channels/${c.id}/join`)); Object.assign(c, r); renderHeader(); renderComposer(); delete S.msgs[c.id]; await loadMessages(c.id); renderMessages(true); toast(`Ti sei unito a #${c.name}`, 'ok'); } catch (e) { toast(e.message, 'err'); } };
    return;
  }
  box.innerHTML = composerHtml(c, 'main');
  bindComposer(box, c, null);
}
function composerHtml(c, scope) {
  const ph = c.type === 'ai_dm' ? `Chiedi qualcosa a ${esc(c.name)}…` : c.type === 'dm' ? `Scrivi a ${esc(c.name)}` : scope === 'thread' ? 'Rispondi nel thread…' : `Scrivi in #${esc(c.name)}`;
  const tb = [['bold', 'Grassetto (Ctrl+B)'], ['italic', 'Corsivo (Ctrl+I)'], ['strike', 'Barrato'], null, ['link', 'Link'], ['list', 'Elenco puntato'], ['quote', 'Citazione'], null, ['code', 'Codice inline'], ['codeBlock', 'Blocco di codice']];
  return `<div class="cbox" data-scope="${scope}">
    <div class="ctb" role="toolbar" aria-label="Formattazione">${tb.map(t => t ? `<button type="button" data-fmt="${t[0]}" title="${t[1]}" aria-label="${t[1]}">${ic(t[0], 's')}</button>` : '<span class="sep"></span>').join('')}</div>
    <div class="catts hide"></div>
    <textarea rows="1" placeholder="${ph}" aria-label="${ph}"></textarea>
    <div class="recbar hide"><span class="pulse"></span> Registrazione <span class="t">0:00</span><span class="spacer"></span><button type="button" class="btn ghost sm" data-rec="cancel">Annulla</button><button type="button" class="btn danger sm" data-rec="stop">${ic('stop', 's')} Termina e invia</button></div>
    <div class="cfoot">
      <button type="button" class="ibtn" data-c="attach" title="Allega file" aria-label="Allega file">${ic('clip')}</button>
      <button type="button" class="ibtn" data-c="emoji" title="Emoji" aria-label="Emoji">${ic('smile')}</button>
      <button type="button" class="ibtn" data-c="mention" title="Menziona" aria-label="Menziona">${ic('at')}</button>
      ${c.type !== 'ai_dm' && c.ai_enabled ? `<button type="button" class="ibtn" data-c="ai" title="Chiedi all'AI (@ai)" aria-label="Chiedi all'AI">${ic('sparkles')}</button>` : ''}
      <button type="button" class="ibtn" data-c="mic" title="Messaggio vocale" aria-label="Messaggio vocale">${ic('mic')}</button>
      ${c.type !== 'ai_dm' && scope === 'main' ? `<button type="button" class="ibtn" data-c="call" title="Videochiamata" aria-label="Videochiamata">${ic('video')}</button>` : ''}
      <span class="spacer"></span><span class="hint"><b>Invio</b> per inviare · <b>Alt+Invio</b> o <b>Shift+Invio</b> a capo</span>
      <button type="button" class="send" data-c="send" title="Invia" aria-label="Invia" disabled>${ic('send')}</button>
    </div>
    <input type="file" multiple class="hide" accept="image/*,.pdf,.txt,.md,.csv,.json,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,audio/*,video/*">
  </div>`;
}
function autosize(ta) { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 280) + 'px'; }
function wrapSel(ta, before, after = before, placeholder = 'testo') {
  const s = ta.selectionStart, e = ta.selectionEnd, v = ta.value, sel = v.slice(s, e) || placeholder;
  ta.value = v.slice(0, s) + before + sel + after + v.slice(e); ta.focus();
  ta.selectionStart = s + before.length; ta.selectionEnd = s + before.length + sel.length; autosize(ta); ta.dispatchEvent(new Event('input'));
}
function linePrefix(ta, prefix) {
  const s = ta.selectionStart, v = ta.value, ls = v.lastIndexOf('\n', s - 1) + 1;
  ta.value = v.slice(0, ls) + prefix + v.slice(ls); ta.focus(); ta.selectionStart = ta.selectionEnd = s + prefix.length; ta.dispatchEvent(new Event('input'));
}
/* Elenchi: a capo dentro un elenco ripete il punto o il numero successivo; su una voce vuota l'elenco termina. */
const LIST_RE = /^(\s*)(?:([-*•])|(\d{1,4})([.)]))\s(.*)$/;
function listNewline(ta) {
  const v = ta.value, s = ta.selectionStart, en = ta.selectionEnd, ls = v.lastIndexOf('\n', s - 1) + 1;
  const m = LIST_RE.exec(v.slice(ls, s));
  if (m && !m[5].trim() && s === en && (en === v.length || v[en] === '\n')) {
    ta.value = v.slice(0, ls) + v.slice(s); ta.selectionStart = ta.selectionEnd = ls;
  } else {
    const ins = '\n' + (m ? m[1] + (m[3] ? `${+m[3] + 1}${m[4]}` : m[2]) + ' ' : '');
    ta.value = v.slice(0, s) + ins + v.slice(en); ta.selectionStart = ta.selectionEnd = s + ins.length;
  }
  ta.dispatchEvent(new Event('input'));
}
function toggleBullets(ta) {
  const v = ta.value, s = ta.selectionStart, en = ta.selectionEnd, ls = v.lastIndexOf('\n', s - 1) + 1;
  let le = v.indexOf('\n', en); if (le < 0) le = v.length;
  const lines = v.slice(ls, le).split('\n'), re = /^(\s*)[-*•] /;
  const filled = lines.filter(l => l.trim()), remove = filled.length > 0 && filled.every(l => re.test(l));
  const out = lines.map(l => remove ? l.replace(re, '$1') : (re.test(l) || (lines.length > 1 && !l.trim()) ? l : '- ' + l));
  const block = out.join('\n');
  ta.value = v.slice(0, ls) + block + v.slice(le); ta.focus();
  if (s === en) ta.selectionStart = ta.selectionEnd = Math.max(ls, s + out[0].length - lines[0].length);
  else { ta.selectionStart = ls; ta.selectionEnd = ls + block.length; }
  ta.dispatchEvent(new Event('input'));
}
function bindComposer(root, c, parentId) {
  const cbox = $('.cbox', root), ta = $('textarea', cbox), send = $('[data-c="send"]', cbox), file = $('input[type=file]', cbox), attsBox = $('.catts', cbox);
  const draftKey = `${c.id}:${parentId || 0}`; const local = {atts: []};
  ta.value = S.drafts[draftKey] || ''; autosize(ta);
  const update = () => { send.disabled = (!ta.value.trim() && !local.atts.some(a => a.id)) || local.atts.some(a => a.uploading); };
  update();
  ta.addEventListener('input', () => { autosize(ta); update(); S.drafts[draftKey] = ta.value; localStorage.setItem('at.drafts', JSON.stringify(S.drafts)); if (!emojiWatch(ta)) mentionWatch(ta); });
  ta.addEventListener('keydown', e => {
    if (emojiKey(e, ta) || mentionKey(e, ta)) return;
    if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); if (e.shiftKey || e.altKey) listNewline(ta); else doSend(); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') { e.preventDefault(); wrapSel(ta, '**'); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'i') { e.preventDefault(); wrapSel(ta, '_'); }
    if (e.key === 'ArrowUp' && !ta.value && !parentId) { const mine = (S.msgs[c.id] || []).filter(m => m.can_edit).at(-1); if (mine) { e.preventDefault(); startEdit(mine); } }
  });
  ta.addEventListener('paste', e => { const files = [...(e.clipboardData?.files || [])]; if (files.length) { e.preventDefault(); addFiles(files); } });
  bindDropZone(parentId ? root.closest('#panel') : root.closest('section.main'), cbox, parentId ? 'al thread' : (c.type === 'dm' || c.type === 'ai_dm' ? `alla conversazione con ${c.name}` : `a #${c.name}`));
  $$('[data-fmt]', cbox).forEach(b => b.onclick = () => {
    const k = b.dataset.fmt;
    ({bold: () => wrapSel(ta, '**'), italic: () => wrapSel(ta, '_'), strike: () => wrapSel(ta, '~~'), code: () => wrapSel(ta, '`', '`', 'codice'), codeBlock: () => wrapSel(ta, '\n```\n', '\n```\n', 'codice'),
      link: () => { const sel = ta.value.slice(ta.selectionStart, ta.selectionEnd); wrapSel(ta, '[', `](${/^https?:/.test(sel) ? sel : 'https://'})`, sel || 'testo'); }, list: () => toggleBullets(ta), quote: () => linePrefix(ta, '> ')})[k]?.();
  });
  $('[data-c="attach"]', cbox).onclick = () => file.click();
  file.onchange = () => { addFiles([...file.files]); file.value = ''; };
  $('[data-c="emoji"]', cbox).onclick = e => emojiPicker(e.currentTarget, em => { const s = ta.selectionStart; ta.value = ta.value.slice(0, s) + em + ta.value.slice(ta.selectionEnd); ta.selectionStart = ta.selectionEnd = s + em.length; ta.focus(); ta.dispatchEvent(new Event('input')); });
  $('[data-c="mention"]', cbox).onclick = () => { const s = ta.selectionStart; const pre = s > 0 && !/\s/.test(ta.value[s - 1]) ? ' @' : '@'; ta.value = ta.value.slice(0, s) + pre + ta.value.slice(s); ta.selectionStart = ta.selectionEnd = s + pre.length; ta.focus(); ta.dispatchEvent(new Event('input')); };
  const aiBtn = $('[data-c="ai"]', cbox); if (aiBtn) aiBtn.onclick = () => { if (!/(^|\s)@ai\b/i.test(ta.value)) ta.value = '@ai ' + ta.value; ta.focus(); ta.selectionStart = ta.selectionEnd = ta.value.length; ta.dispatchEvent(new Event('input')); };
  const callBtn = $('[data-c="call"]', cbox); if (callBtn) callBtn.onclick = () => actions.call();
  $('[data-c="mic"]', cbox).onclick = () => startRecording(cbox, c, parentId);
  send.onclick = () => doSend();

  function renderAtts() {
    attsBox.classList.toggle('hide', !local.atts.length);
    attsBox.innerHTML = local.atts.map(a => `<div class="catt ${a.uploading ? 'uploading' : ''}" data-k="${a.key}">${a.preview ? `<img src="${a.preview}" alt="">` : `<span class="fic">${ic(a.kind === 'pdf' ? 'filePdf' : a.kind === 'audio' ? 'audio' : 'file', 's')}</span>`}<div style="min-width:0"><div class="n">${esc(a.name)}</div><small>${a.uploading ? 'Caricamento…' : a.error ? `<span style="color:var(--danger)">${esc(a.error)}</span>` : fmtSize(a.size)}</small></div><button type="button" class="x" title="Rimuovi" aria-label="Rimuovi">${ic('x', 's')}</button></div>`).join('');
    $$('.catt .x', attsBox).forEach(b => b.onclick = () => { const k = b.closest('.catt').dataset.k; const a = local.atts.find(x => x.key === k); if (a?.id) api('DELETE', `/attachments/${a.id}`).catch(() => {}); local.atts = local.atts.filter(x => x.key !== k); renderAtts(); });
    update();
  }
  async function addFiles(files, extra = {}) {
    const max = (S.org.max_upload_mb || 25) * 1048576;
    for (const f of files) {
      if (f.size > max) { toast(`"${f.name}" supera il limite di ${S.org.max_upload_mb || 25} MB`, 'err'); continue; }
      const a = {key: uid(), name: f.name, size: f.size, kind: f.type.startsWith('image/') ? 'image' : f.type === 'application/pdf' ? 'pdf' : f.type.startsWith('audio/') ? 'audio' : 'file', uploading: true, preview: f.type.startsWith('image/') ? URL.createObjectURL(f) : null};
      local.atts.push(a); renderAtts();
      const fd = new FormData(); fd.append('file', f, f.name); Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
      try { const r = await api('POST', '/attachments', fd); a.id = r.id; a.kind = r.kind || a.kind; } catch (e) { a.error = e.errors?.file?.[0] || e.message; }
      a.uploading = false; renderAtts();
    }
    return local.atts;
  }
  async function doSend(kindOverride) {
    const body = ta.value.trim(); const ids = local.atts.filter(a => a.id).map(a => a.id);
    if ((!body && !ids.length) || local.atts.some(a => a.uploading)) return;
    const tmp = uid(); const kind = kindOverride || (ids.length && !body && local.atts.every(a => a.kind === 'audio') ? 'voice' : 'text');
    const optimistic = {tmp, id: null, channel_id: c.id, parent_id: parentId, body, html: `<p>${esc(body).replace(/\n/g, '<br>')}</p>`, kind, user: {...S.user, is_ai: false}, attachments: [], reactions: [], link_previews: [], created_at: new Date().toISOString(), pending: true, _payload: {body: body || null, parent_id: parentId, attachment_ids: ids, kind}};
    ta.value = ''; autosize(ta); local.atts = []; renderAtts(); delete S.drafts[draftKey]; localStorage.setItem('at.drafts', JSON.stringify(S.drafts)); closePop();
    upsertMessage(optimistic);
    await sendPayload(optimistic);
    $((parentId ? '#panel' : '#composer') + ' textarea')?.focus?.();
  }
  cbox._addFiles = addFiles; cbox._send = doSend;
}
async function sendPayload(m) {
  try {
    const r = one(await api('POST', `/channels/${m.channel_id}/messages`, m._payload));
    r.tmp = m.tmp; upsertMessage(r);
    if (m.parent_id) { const root = (S.msgs[m.channel_id] || []).find(x => x.id === m.parent_id); if (root) { root.reply_count = (root.reply_count || 0) + 1; root.last_reply_at = r.created_at; renderMessages(false); } }
    runTasks(r);
  } catch (e) { m.pending = false; m.failed = true; m.error = e.message; rerenderOne(m); toast('Invio non riuscito: ' + e.message, 'err'); }
}
/* Risposta AI, anteprime dei link e bot: le esegue il browser subito dopo l'invio
   (affidabile anche su hosting condiviso senza worker); il cron recupera quelle rimaste in sospeso. */
async function runTasks(r) {
  const pending = r?.meta?.pending || []; if (!pending.length) return;
  for (const task of pending) {
    if (task === 'ai') aiTyping(r.channel_id, r.parent_id);
    api('POST', `/messages/${r.id}/tasks/${task}`).then(res => {
      if (res?.message && task === 'previews') upsertMessage(res.message, {scroll: false});
      (res?.replies || []).forEach(x => { upsertMessage(x); if (x.parent_id && S.thread?.root.id === x.parent_id) renderPanel(); });
      if (task === 'ai') stopTyping();
    }).catch(e => {
      if (task === 'ai') { stopTyping(); toast('Assistente AI: ' + (e.status === 504 || e.status === 0 ? 'tempo scaduto, la risposta arriverà a breve' : e.message), 'err'); }
    });
  }
}
async function retrySend(m) { m.failed = false; m.pending = true; rerenderOne(m); await sendPayload(m); }
const S_TYPING = {};
function stopTyping() { Object.keys(S_TYPING).forEach(k => delete S_TYPING[k]); $$('.ai-typing').forEach(el => el.remove()); }
const typingHtml = () => `<div class="msg ai ai-typing"><span class="ava ai">${ic('sparkles')}</span><div><div class="head"><b>${esc(S.org.ai_bot_name || 'AI')}</b><span class="pill">AI</span></div><div class="body typing-dots" style="color:var(--text-3)">sta scrivendo<i></i><i></i><i></i></div></div></div>`;
function aiTyping(channelId, parentId) {
  S_TYPING[(parentId ? 't' + parentId : 'c' + channelId)] = Date.now();
  const host = parentId ? $('#panel .thread-replies') : $('#msgs'); if (!host || $('.ai-typing', host)) return;
  host.insertAdjacentHTML('beforeend', typingHtml());
  const scroller = parentId ? $('#panel .panel-body') : host; if (scroller) scroller.scrollTop = scroller.scrollHeight;
  setTimeout(() => { delete S_TYPING[(parentId ? 't' + parentId : 'c' + channelId)]; $('.ai-typing', host)?.remove(); }, 180000);
}

/* --- trascinamento file su tutta l'area della chat o del thread --- */
function bindDropZone(zone, cbox, label) {
  if (!zone) return;
  zone._dropCbox = cbox; zone._dropLabel = label;
  if (zone._dropBound) return;
  zone._dropBound = true; let depth = 0;
  const hasFiles = e => [...(e.dataTransfer?.types || [])].includes('Files');
  const hide = () => { depth = 0; zone.classList.remove('dropping'); $('.drop-ov', zone)?.remove(); };
  zone.addEventListener('dragenter', e => {
    if (!hasFiles(e) || !zone._dropCbox?.isConnected) return;
    e.preventDefault(); depth++;
    if (!$('.drop-ov', zone)) zone.insertAdjacentHTML('beforeend', `<div class="drop-ov"><div>${ic('upload')}<b>Rilascia i file per allegarli</b><small>${esc(zone._dropLabel || '')}</small></div></div>`);
    zone.classList.add('dropping');
  });
  zone.addEventListener('dragover', e => { if (hasFiles(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; } });
  zone.addEventListener('dragleave', e => { if (hasFiles(e) && --depth <= 0) hide(); });
  zone.addEventListener('drop', e => {
    if (!hasFiles(e)) return;
    e.preventDefault(); e.stopPropagation(); hide();
    const files = [...e.dataTransfer.files];
    if (files.length && zone._dropCbox?.isConnected) zone._dropCbox._addFiles(files);
  });
}
// evita che un file rilasciato fuori dalle aree di chat venga aperto dal browser al posto dell'app
window.addEventListener('dragover', e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); });
window.addEventListener('drop', e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); });

/* --- link #canale nei messaggi --- */
document.addEventListener('click', e => {
  const a = e.target.closest('a.chan-ref'); if (!a) return;
  e.preventDefault(); go(`/app/c/${a.dataset.channelId}`);
});

/* --- menzioni @utente e riferimenti #canale --- */
let mentionState = null;
function mentionWatch(ta) {
  const v = ta.value.slice(0, ta.selectionStart); const m = v.match(/(^|\s)([@#])([\p{L}\d._-]*)$/u);
  if (!m) { if (mentionState) { closePop(); mentionState = null; } return; }
  const kind = m[2], q = m[3].toLowerCase();
  const cands = kind === '#'
    ? S.channels.filter(c => (c.type === 'public' || c.type === 'private') && !c.is_archived && c.slug && (c.slug.includes(q) || c.name.toLowerCase().includes(q)))
        .sort((a, b) => (b.slug.startsWith(q) - a.slug.startsWith(q)) || a.slug.localeCompare(b.slug)).slice(0, 8)
    : [{id: 'ai', name: 'ai', title: 'Assistente AI', is_ai: true}, ...S.users.filter(u => u.id !== S.user.id)].filter(u => u.name.toLowerCase().includes(q) || (u.email || '').toLowerCase().includes(q)).slice(0, 8);
  if (!cands.length) { if (mentionState) { closePop(); mentionState = null; } return; }
  mentionState = {ta, kind, cands, idx: 0, start: ta.selectionStart - m[3].length - 1};
  const html = () => kind === '#'
    ? `<div class="mp-hd">Canali</div>` + cands.map((c, i) => `<button type="button" class="u ch ${i === mentionState.idx ? 'on' : ''}" data-i="${i}"><span class="ch-ic">${chIcon(c)}</span><b>${esc(c.slug)}</b><small>${esc(c.topic || (c.name !== c.slug ? c.name : ''))}</small></button>`).join('')
    : `<div class="mp-hd">Persone</div>` + cands.map((u, i) => `<button type="button" class="u ${i === mentionState.idx ? 'on' : ''}" data-i="${i}">${avatar(u, 'sm')}<b>${esc(u.name)}</b><small>${esc(u.title || u.email || '')}</small></button>`).join('');
  const p = popEl?.classList.contains('mention-pop') ? popEl : popover(ta, '', {cls: 'mention-pop'});
  p.innerHTML = html();
  const r = ta.getBoundingClientRect(); p.style.top = Math.max(8, r.top - p.offsetHeight - 6) + 'px'; p.style.left = r.left + 'px';
  p.onmousedown = e => { const b = e.target.closest('[data-i]'); if (b) { e.preventDefault(); mentionPick(+b.dataset.i); } };
}
function mentionPick(i) {
  const st = mentionState; if (!st) return; const u = st.cands[i]; const ta = st.ta;
  const name = st.kind === '#' ? u.slug : u.is_ai ? 'ai' : u.is_bot ? u.name.toLowerCase().replace(/[^\p{L}\d._-]+/gu, '') : u.name.split(' ')[0];
  ta.value = ta.value.slice(0, st.start) + st.kind + name + ' ' + ta.value.slice(ta.selectionStart);
  ta.selectionStart = ta.selectionEnd = st.start + name.length + 2; closePop(); mentionState = null; ta.dispatchEvent(new Event('input')); ta.focus();
}
function mentionKey(e, ta) {
  if (!mentionState || mentionState.ta !== ta || !popEl) return false;
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); mentionState.idx = (mentionState.idx + (e.key === 'ArrowDown' ? 1 : -1) + mentionState.cands.length) % mentionState.cands.length; $$('.u', popEl).forEach((b, i) => b.classList.toggle('on', i === mentionState.idx)); return true; }
  if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); mentionPick(mentionState.idx); return true; }
  if (e.key === 'Escape') { closePop(); mentionState = null; return true; }
  return false;
}

/* --- registrazione vocale --- */
async function startRecording(cbox, c, parentId) {
  if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) return toast('Registrazione audio non supportata da questo browser', 'err');
  let stream; try { stream = await navigator.mediaDevices.getUserMedia({audio: true}); } catch { return toast('Permesso microfono negato', 'err'); }
  const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].find(t => MediaRecorder.isTypeSupported(t)) || '';
  const rec = new MediaRecorder(stream, mime ? {mimeType: mime} : undefined); const chunks = []; const t0 = Date.now();
  const bar = $('.recbar', cbox), ta = $('textarea', cbox); bar.classList.remove('hide'); ta.classList.add('hide'); $('.ctb', cbox).classList.add('hide'); cbox.classList.add('rec');
  const timer = setInterval(() => $('.t', bar).textContent = fmtDur((Date.now() - t0) / 1000), 500);
  let cancelled = false;
  const cleanup = () => { clearInterval(timer); stream.getTracks().forEach(t => t.stop()); bar.classList.add('hide'); ta.classList.remove('hide'); $('.ctb', cbox).classList.remove('hide'); cbox.classList.remove('rec'); };
  rec.ondataavailable = e => e.data.size && chunks.push(e.data);
  rec.onstop = async () => {
    cleanup(); if (cancelled) return;
    const dur = (Date.now() - t0) / 1000; if (dur < 1) return toast('Registrazione troppo breve', 'err');
    const ext = mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm';
    const file = new File(chunks, `vocale-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.${ext}`, {type: mime || 'audio/webm'});
    await cbox._addFiles([file], {duration: dur.toFixed(1), voice: 1});
    cbox._send('voice');
  };
  $('[data-rec="stop"]', bar).onclick = () => rec.stop();
  $('[data-rec="cancel"]', bar).onclick = () => { cancelled = true; rec.stop(); };
  rec.start(250);
  setTimeout(() => rec.state === 'recording' && rec.stop(), 5 * 60000);
}

/* ============================================================ Pannello destro: thread / dettagli */
function openPanel(kind) { const was = S.panel; S.panel = kind; if (!was) render(); else renderPanel(); $('#mainwrap')?.classList.add('with-panel'); renderHeader(); }
function closePanel() { const hadThread = S.panel === 'thread'; S.panel = null; S.thread = null; if (hadThread && S.active) { history.replaceState({}, '', `/app/c/${S.active.id}`); S.route.threadId = null; } render(); }
async function openThread(rootId) {
  const c = S.active; if (!c) return;
  const known = (S.msgs[c.id] || []).find(m => m.id === rootId);
  S.thread = {root: known || {id: rootId, channel_id: c.id, user: {}, body: '', created_at: new Date().toISOString()}, replies: [], loading: true};
  openPanel('thread');
  history.replaceState({}, '', `/app/c/${c.id}/t/${rootId}`); S.route.threadId = rootId;
  try { const t = await api('GET', `/messages/${rootId}/thread`); if (S.thread?.root.id === rootId) { S.thread.root = t.root; S.thread.replies = list(t); S.thread.loading = false; renderPanel(); } }
  catch (e) { toast('Thread non disponibile: ' + e.message, 'err'); closePanel(); }
}
function renderPanel() {
  const p = $('#panel'); if (!p || !S.panel) return; const c = S.active; if (!c) return;
  if (S.panel === 'thread' && S.thread) {
    const t = S.thread;
    p.innerHTML = `<div class="panel-head"><b>Thread</b><small>#${esc(c.name)}</small><button class="ibtn" data-close-panel aria-label="Chiudi">${ic('x')}</button></div>
      <div class="panel-body"><div class="thread-root">${messageHtml(t.root, null, {inThread: true})}</div>
        <div class="thread-count">${t.loading ? 'Caricamento…' : t.replies.length ? `${t.replies.length} ${t.replies.length === 1 ? 'risposta' : 'risposte'}` : 'Nessuna risposta: inizia tu'}</div>
        <div class="thread-replies">${t.replies.map((m, i) => messageHtml(m, t.replies[i - 1], {inThread: true})).join('')}</div>
        <div class="composer">${isRO(c) ? `<div class="ro-note">${ic('archive', 's')} Canale archiviato: sola lettura</div>` : composerHtml(c, 'thread')}</div></div>`;
    bindMessageEvents($('.panel-body', p)); if (!isRO(c)) bindComposer($('.composer', p), c, t.root.id);
    const body = $('.panel-body', p); body.scrollTop = body.scrollHeight;
  } else if (S.panel === 'details') {
    p.innerHTML = `<div class="panel-head"><b>${c.type === 'dm' ? 'Dettagli conversazione' : 'Dettagli canale'}</b><button class="ibtn" data-close-panel aria-label="Chiudi">${ic('x')}</button></div><div class="panel-body"><div class="details" id="details"></div></div>`;
    renderDetails(c);
  }
  $('[data-close-panel]', p).onclick = closePanel;
}
function renderDetails(c) {
  const box = $('#details'); if (!box) return;
  const ro = isRO(c);
  const canManage = (isAdmin() || c.created_by === S.user.id) && !ro;
  const canArchive = isAdmin() || c.created_by === S.user.id;
  const members = c.members || [];
  box.innerHTML = `
    <div class="sec" style="text-align:center;padding:6px 0 2px">${c.type === 'dm' ? avatar(c.other_user, 'lg') : c.type === 'ai_dm' ? `<span class="ava ai lg">${ic('sparkles', 'l')}</span>` : `<span class="ava lg" style="background:var(--bg-3);color:var(--text)">${chIcon(c)}</span>`}
      <h3 style="margin:10px 0 2px">${esc(c.name)}</h3><div style="color:var(--text-2);font-size:13px">${c.type === 'private' ? 'Canale privato' : c.type === 'public' ? 'Canale pubblico' : c.type === 'dm' ? esc(c.other_user?.email || '') : 'Assistente AI'}${c.created_at ? ` · creato il ${new Date(c.created_at).toLocaleDateString('it-IT')}` : ''}</div>${ro ? `<div style="margin-top:8px"><span class="tag arch">${ic('archive', 's')} archiviato il ${new Date(c.archived_at).toLocaleDateString('it-IT')}</span></div>` : ''}</div>
    ${c.type === 'public' || c.type === 'private' ? `<div class="sec"><h4>Argomento</h4>
      <div class="topic-edit"><textarea id="d-topic" ${canManage ? '' : 'disabled'} placeholder="Nessun argomento">${esc(c.topic || '')}</textarea>
      ${canManage ? `<div style="display:flex;gap:6px;justify-content:flex-end;margin-top:6px"><button class="btn primary sm" id="d-save-topic">Salva</button></div>` : ''}</div></div>` : ''}
    ${ro ? '' : `<div class="sec"><h4>Notifiche</h4><div class="seg" id="d-notify">${[['all', 'Tutti i messaggi'], ['mentions', 'Solo menzioni'], ['none', 'Silenziato']].map(([k, l]) => `<button data-n="${k}" class="${(c.notify || 'all') === k ? 'on' : ''}">${l}</button>`).join('')}</div></div>`}
    ${!ro && (c.type === 'public' || c.type === 'private') ? `<div class="sec"><h4>Assistente AI</h4><label class="check" style="margin:0"><span class="switch ${c.ai_enabled ? 'on' : ''}" id="d-ai" role="switch" aria-checked="${!!c.ai_enabled}"></span><div><b>Rispondi a @ai in questo canale</b><small>${canManage ? 'Quando attivo, il bot risponde alle menzioni @ai' : 'Modificabile dal creatore del canale o da un admin'}</small></div></label></div>` : ''}
    ${c.type !== 'ai_dm' ? `<div class="sec"><h4>Membri (${members.length})</h4>
      ${c.type !== 'dm' && !ro ? `<button class="row btnrow" id="d-add" style="width:100%;text-align:left"><span class="ava" style="background:var(--accent-100);color:var(--accent);width:30px;height:30px">${ic('plus', 's')}</span><div class="grow"><b>Aggiungi persone</b></div></button>` : ''}
      ${members.map(u => `<div class="row">${avatar(u)}<div class="grow"><b>${esc(u.name)}${u.id === S.user.id ? ' (tu)' : ''}</b><small>${esc(u.title || u.email || '')}${u.online ? ' · <span style="color:var(--ok)">online</span>' : ''}</small></div>${u.role === 'admin' ? '<span class="tag admin">admin</span>' : u.role === 'guest' ? '<span class="tag guest">ospite</span>' : ''}${canManage && u.id !== S.user.id && c.type !== 'dm' ? `<button class="ibtn" data-rm="${u.id}" title="Rimuovi dal canale" aria-label="Rimuovi dal canale">${ic('x', 's')}</button>` : ''}</div>`).join('')}</div>` : ''}
    ${c.type === 'public' || c.type === 'private' ? `<div class="sec"><h4>Azioni</h4>
      ${!ro && !isGuest() && S.org.allow_guest_invites !== false ? `<button class="row btnrow" id="d-invite" style="width:100%;text-align:left"><span class="ava" style="background:var(--warn-100);color:var(--warn);width:30px;height:30px">${ic('mail', 's')}</span><div class="grow"><b>Invita un ospite esterno</b><small>Accesso limitato a questo canale</small></div></button>` : ''}
      ${c.slug !== 'generale' && !ro ? `<button class="row btnrow" id="d-leave" style="width:100%;text-align:left"><span class="ava" style="background:var(--bg-3);color:var(--text-2);width:30px;height:30px">${ic('logout', 's')}</span><div class="grow"><b>Lascia il canale</b></div></button>` : ''}
      ${canArchive && c.slug !== 'generale' && !ro ? `<button class="row btnrow" id="d-archive" style="width:100%;text-align:left"><span class="ava" style="background:var(--warn-100);color:var(--warn);width:30px;height:30px">${ic('archive', 's')}</span><div class="grow"><b>Archivia il canale</b><small>Esce dalla barra laterale e diventa sola lettura; si può ripristinare</small></div></button>` : ''}
      ${canArchive && ro ? `<button class="row btnrow" id="d-unarchive" style="width:100%;text-align:left"><span class="ava" style="background:var(--ok-100);color:var(--ok);width:30px;height:30px">${ic('refresh', 's')}</span><div class="grow"><b>Ripristina il canale</b><small>Torna attivo per tutti i membri</small></div></button>` : ''}</div>` : ''}`;
  const q = s => $(s, box);
  if (q('#d-save-topic')) q('#d-save-topic').onclick = async () => { try { Object.assign(c, one(await api('PUT', `/channels/${c.id}`, {topic: q('#d-topic').value}))); renderHeader(); toast('Argomento aggiornato', 'ok'); } catch (e) { toast(e.message, 'err'); } };
  $$('#d-notify button', box).forEach(b => b.onclick = async () => { try { await api('PUT', `/channels/${c.id}/notify`, {notify: b.dataset.n}); c.notify = b.dataset.n; renderDetails(c); renderSidebar(); } catch (e) { toast(e.message, 'err'); } });
  if (q('#d-ai')) q('#d-ai').onclick = async () => { if (!canManage) return; try { Object.assign(c, one(await api('PUT', `/channels/${c.id}`, {ai_enabled: !c.ai_enabled}))); renderDetails(c); renderComposer(); } catch (e) { toast(e.message, 'err'); } };
  if (q('#d-add')) q('#d-add').onclick = () => pickUsers({title: `Aggiungi persone a #${c.name}`, exclude: members.map(u => u.id), multi: true, ok: 'Aggiungi'}).then(async ids => { if (!ids?.length) return; try { Object.assign(c, one(await api('POST', `/channels/${c.id}/members`, {user_ids: ids}))); renderDetails(c); renderHeader(); toast('Membri aggiunti', 'ok'); } catch (e) { toast(e.message, 'err'); } });
  $$('[data-rm]', box).forEach(b => b.onclick = async () => { const u = userById(+b.dataset.rm); if (!await confirmModal({title: 'Rimuovere dal canale?', text: `${esc(u?.name || 'L\'utente')} non potrà più leggere #${esc(c.name)}.`, ok: 'Rimuovi', danger: true})) return; try { await api('DELETE', `/channels/${c.id}/members/${b.dataset.rm}`); c.members = members.filter(x => x.id !== +b.dataset.rm); c.member_count = c.members.length; renderDetails(c); renderHeader(); } catch (e) { toast(e.message, 'err'); } });
  if (q('#d-invite')) q('#d-invite').onclick = () => inviteModal(c);
  if (q('#d-leave')) q('#d-leave').onclick = async () => { if (!await confirmModal({title: `Lasciare #${c.name}?`, text: 'Potrai rientrare in qualsiasi momento se il canale è pubblico.', ok: 'Lascia'})) return; try { await api('POST', `/channels/${c.id}/leave`); await loadChannels(); S.panel = null; S.thread = null; go('/app'); } catch (e) { toast(e.message, 'err'); } };
  if (q('#d-archive')) q('#d-archive').onclick = async () => { if (!await confirmModal({title: `Archiviare #${c.name}?`, text: 'Il canale uscirà dalla barra laterale di tutti i membri e diventerà di sola lettura. Messaggi e file restano consultabili e ricercabili, e potrai ripristinarlo in qualsiasi momento da Sfoglia canali › Archiviati.', ok: 'Archivia'})) return; await setArchived(c, true); };
  if (q('#d-unarchive')) q('#d-unarchive').onclick = () => setArchived(c, false);
}
