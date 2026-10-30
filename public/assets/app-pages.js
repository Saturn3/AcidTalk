/* AcidTalk 2.0 — SPA client, parte 2: azioni globali, modali, pagine Profilo e Amministrazione, boot. */
'use strict';

/* ============================================================ Azioni / modali principali */
const actions = {
  sbsort: (e, b) => popover(b, `<div class="hd">Ordina i canali</div>${[['recent', 'clock', 'Attività recente'], ['alpha', 'sortAz', 'Ordine alfabetico']].map(([k, i, l]) => `<button class="mi" data-k="${k}">${ic(i, 's')} ${l}${SB.sort === k ? `<small>${ic('check', 's')}</small>` : ''}</button>`).join('')}`, {onOpen: p => $$('[data-k]', p).forEach(x => x.onclick = () => { SB.sort = x.dataset.k; localStorage.setItem('at.sbsort', SB.sort); closePop(); renderSidebar(); })}),
  theme: () => setTheme(themeNow() === 'dark' ? 'light' : 'dark'),
  menu: () => document.body.classList.toggle('sb-open'),
  search: () => openSearch(),
  notifs: (e, b) => notifPanel(b),
  details: () => { if (!S.active) return; if (S.panel === 'details') return closePanel(); S.thread = null; refreshChannel(S.active.id).then(() => openPanel('details')); },
  admin: () => go('/app/admin'),
  me: () => go('/app/profile'),
  browse: () => browseChannels(),
  newchannel: () => newChannelModal(),
  newdm: () => pickUsers({title: 'Nuovo messaggio diretto', exclude: [S.user.id]}).then(ids => ids?.length && openDm(ids[0])),
  newmsg: () => pickUsers({title: 'Nuovo messaggio diretto', exclude: [S.user.id]}).then(ids => ids?.length && openDm(ids[0])),
  orgmenu: (e, b) => popover(b, `<div class="hd">${esc(S.org.name)}</div><button class="mi" data-k="profile">${ic('user', 's')} Il mio profilo</button>${!isGuest() ? `<button class="mi" data-k="invite">${ic('mail', 's')} Invita persone</button>` : ''}${isAdmin() ? `<button class="mi" data-k="admin">${ic('shield', 's')} Amministrazione</button>` : ''}<button class="mi" data-k="api">${ic('server', 's')} API & MCP</button><div class="sep"></div><button class="mi" data-k="logout">${ic('logout', 's')} Esci</button>`, {onOpen: p => p.onclick = ev => { const k = ev.target.closest('[data-k]')?.dataset.k; if (!k) return; closePop(); if (k === 'profile') go('/app/profile'); if (k === 'invite') inviteModal(); if (k === 'admin') go('/app/admin'); if (k === 'api') { go('/app/profile'); setTimeout(() => $('#api')?.scrollIntoView({behavior: 'smooth'}), 80); } if (k === 'logout') logout(); }}),
  call: async () => {
    const c = S.active; if (!c) return;
    const ok = await confirmModal({title: 'Avviare una videochiamata?', text: `Verrà creato un link ${S.org.video_provider === 'meet' ? 'Google Meet' : 'Jitsi Meet'} e pubblicato in #${esc(c.name)} così che tutti possano partecipare.`, ok: 'Avvia chiamata'});
    if (!ok) return;
    try { const r = await api('POST', `/channels/${c.id}/calls`); upsertMessage(r.message); window.open(r.call.url, '_blank', 'noopener'); } catch (e) { toast(e.message, 'err'); }
  },
};
async function refreshChannel(id) { try { const r = one(await api('GET', `/channels/${id}`)); const c = chById(id); if (c) Object.assign(c, r); } catch {} }
async function openDm(userId) {
  try { const r = one(await api('POST', '/channels/direct', {user_id: userId})); if (!chById(r.id)) { S.channels.push(r); sortChannels(); } go(`/app/c/${r.id}`); } catch (e) { toast(e.message, 'err'); }
}
function logout() {
  const f = document.createElement('form'); f.method = 'POST'; f.action = '/logout'; f.innerHTML = `<input type="hidden" name="_token" value="${esc(B.csrf)}">`; document.body.appendChild(f); f.submit();
}
function pickUsers({title, exclude = [], multi = false, ok = 'Apri'}) {
  return new Promise(res => {
    const sel = new Set(); let q = '';
    const rows = () => S.users.filter(u => !exclude.includes(u.id) && (u.name.toLowerCase().includes(q) || (u.email || '').toLowerCase().includes(q))).map(u => `<button type="button" class="u ${sel.has(u.id) ? 'on' : ''}" data-u="${u.id}">${avatar(u)}<div><b>${esc(u.name)}</b><small>${esc(u.title || u.email)}${u.role === 'guest' ? ' · ospite' : ''}</small></div>${sel.has(u.id) ? `<span class="chk">${ic('check')}</span>` : ''}</button>`).join('') || `<div style="padding:16px;color:var(--text-3);text-align:center">Nessun utente trovato</div>`;
    const m = modal({title, body: `<div class="field"><input type="text" class="inp" placeholder="Cerca per nome o email…" autofocus></div><div class="userpick">${rows()}</div>`,
      foot: `<button class="btn ghost" data-close>Annulla</button>${multi ? `<button class="btn primary" data-ok disabled>${esc(ok)}</button>` : ''}`, onClose: () => res(null)});
    const refresh = () => { $('.userpick', m.el).innerHTML = rows(); const okb = $('[data-ok]', m.el); if (okb) okb.disabled = !sel.size; };
    $('input', m.el).oninput = e => { q = e.target.value.toLowerCase(); refresh(); };
    $('.userpick', m.el).onclick = e => { const b = e.target.closest('[data-u]'); if (!b) return; const id = +b.dataset.u; if (multi) { sel.has(id) ? sel.delete(id) : sel.add(id); refresh(); } else { res([id]); m.close(); } };
    const okb = $('[data-ok]', m.el); if (okb) okb.onclick = () => { res([...sel]); m.close(); };
  });
}
function newChannelModal() {
  const m = modal({title: 'Crea un canale', body: `
    <div class="field"><label for="nc-name">Nome</label><div style="position:relative"><span style="position:absolute;left:12px;top:9px;color:var(--text-3)">#</span><input id="nc-name" type="text" maxlength="60" placeholder="es. marketing" style="padding-left:28px" autofocus></div><div class="help">Lettere, numeri, spazi e trattini. Verrà salvato in minuscolo.</div><div class="err hide"></div></div>
    <div class="field"><label for="nc-topic">Argomento (opzionale)</label><input id="nc-topic" type="text" maxlength="200" placeholder="Di cosa si parla qui?"></div>
    <div class="field"><label>Visibilità</label><div class="radio-cards">
      <label class="check on"><input type="radio" name="nc-type" value="public" checked><div><b>${ic('hash', 's')} Pubblico</b><small>Tutti i membri possono trovarlo e unirsi</small></div></label>
      <label class="check"><input type="radio" name="nc-type" value="private"><div><b>${ic('lock', 's')} Privato</b><small>Solo su invito; non compare nell'elenco</small></div></label></div></div>
    <label class="check"><input type="checkbox" id="nc-ai" checked><div><b>Assistente AI attivo</b><small>Il bot risponde quando viene menzionato con @ai</small></div></label>`,
    foot: `<button class="btn ghost" data-close>Annulla</button><button class="btn primary" data-ok>Crea canale</button>`});
  $$('input[name="nc-type"]', m.el).forEach(r => r.onchange = () => $$('.radio-cards .check', m.el).forEach(l => l.classList.toggle('on', $('input', l).checked)));
  const submit = async () => {
    const name = $('#nc-name', m.el).value.trim(); const err = $('.err', m.el);
    if (!name) { err.textContent = 'Inserisci un nome'; err.classList.remove('hide'); return; }
    try { const r = one(await api('POST', '/channels', {name, topic: $('#nc-topic', m.el).value, type: $('input[name="nc-type"]:checked', m.el).value, ai_enabled: $('#nc-ai', m.el).checked})); S.channels.push(r); sortChannels(); m.close(); go(`/app/c/${r.id}`); toast(`Canale #${r.name} creato`, 'ok'); }
    catch (e) { err.textContent = e.errors?.name?.[0] || e.message; err.classList.remove('hide'); }
  };
  $('[data-ok]', m.el).onclick = submit; $('#nc-name', m.el).onkeydown = e => e.key === 'Enter' && submit();
}
async function browseChannels() {
  const m = modal({title: 'Sfoglia canali', body: `<div class="seg bc-tabs" role="tablist"><button data-t="active" class="on" role="tab">Attivi</button><button data-t="archived" role="tab">${ic('archive', 's')} Archiviati</button></div><div class="field"><input type="text" class="inp" placeholder="Filtra canali…" autofocus></div><div class="userpick" id="bc-list"><div style="padding:16px;color:var(--text-3);text-align:center">Caricamento…</div></div>`, foot: `${isGuest() ? '' : `<button class="btn ghost left" data-new>${ic('plus', 's')} Nuovo canale</button>`}<button class="btn primary" data-close>Chiudi</button>`});
  const nb = $('[data-new]', m.el); if (nb) nb.onclick = () => { m.close(); newChannelModal(); };
  let tab = 'active', archived = null;
  await loadChannels();
  const empty = t => `<div style="padding:16px;color:var(--text-3);text-align:center">${t}</div>`;
  const rows = (q = '') => {
    if (tab === 'archived' && !archived) return empty('Caricamento…');
    const all = tab === 'archived' ? archived : S.channels.filter(c => ['public', 'private'].includes(c.type));
    return all.filter(c => c.name.includes(q) || (c.topic || '').toLowerCase().includes(q)).map(c => `<button type="button" class="u" data-c="${c.id}"><span class="ava" style="background:var(--bg-3);color:var(--text)">${tab === 'archived' ? ic('archive', 's') : chIcon(c)}</span><div><b>${esc(c.name)}</b><small>${tab === 'archived' ? `archiviato il ${new Date(c.archived_at).toLocaleDateString('it-IT')} · ` : ''}${c.member_count || 0} membri${c.topic ? ' · ' + esc(c.topic) : ''}</small></div>${tab === 'archived' ? `<span class="tag arch" style="margin-left:auto">sola lettura</span>` : c.is_member ? `<span class="tag ok" style="margin-left:auto">membro</span>` : `<span class="tag" style="margin-left:auto">unisciti</span>`}</button>`).join('') || empty(tab === 'archived' ? 'Nessun canale archiviato' : 'Nessun canale');
  };
  if (!document.body.contains(m.el)) return;
  const inp = $('input', m.el), draw = () => $('#bc-list', m.el).innerHTML = rows(inp.value.toLowerCase());
  draw();
  inp.oninput = draw;
  $$('.bc-tabs button', m.el).forEach(b => b.onclick = async () => {
    tab = b.dataset.t; $$('.bc-tabs button', m.el).forEach(x => x.classList.toggle('on', x === b)); draw();
    if (tab === 'archived' && !archived) { try { archived = list(await api('GET', '/channels?archived=1')); archived.forEach(c => S.archived[c.id] = c); } catch (e) { archived = []; toast(e.message, 'err'); } draw(); }
  });
  $('#bc-list', m.el).onclick = e => { const b = e.target.closest('[data-c]'); if (b) { m.close(); go(`/app/c/${b.dataset.c}`); } };
}
function inviteModal(channel) {
  const chs = S.channels.filter(c => ['public', 'private'].includes(c.type));
  const m = modal({title: 'Invita una persona', body: `
    <p style="margin:0 0 14px;color:var(--text-2);font-size:14px">Gli indirizzi <b>@${esc(S.org.domain)}</b> possono iscriversi da soli; gli esterni ricevono un link di invito come <b>ospiti</b> con accesso ai soli canali selezionati.</p>
    <div class="field"><label for="inv-email">Email</label><input id="inv-email" type="email" placeholder="nome@esempio.com" autofocus><div class="err hide"></div></div>
    ${isAdmin() ? `<div class="field"><label for="inv-role">Ruolo</label><select id="inv-role"><option value="guest">Ospite (solo canali selezionati)</option><option value="member">Membro (accesso completo)</option></select></div>` : ''}
    <div class="field"><label>Canali</label><div class="userpick" style="max-height:180px">${chs.map(c => `<label class="u"><input type="checkbox" value="${c.id}" ${channel?.id === c.id ? 'checked' : ''}> ${chIcon(c)} <b style="font-weight:600">${esc(c.name)}</b></label>`).join('')}</div></div>
    <div class="field"><label for="inv-msg">Messaggio (opzionale)</label><textarea id="inv-msg" placeholder="Ciao! Ti invito nel nostro spazio di lavoro…"></textarea></div>
    <div id="inv-result" class="hide"></div>`,
    foot: `<button class="btn ghost" data-close>Chiudi</button><button class="btn primary" data-ok>${ic('mail', 's')} Invia invito</button>`});
  $('[data-ok]', m.el).onclick = async () => {
    const err = $('.err', m.el); err.classList.add('hide');
    try {
      const r = await api('POST', '/invites', {email: $('#inv-email', m.el).value.trim(), role: $('#inv-role', m.el)?.value, channel_ids: $$('input[type=checkbox]:checked', m.el).map(i => +i.value), message: $('#inv-msg', m.el).value});
      const box = $('#inv-result', m.el); box.classList.remove('hide');
      box.innerHTML = `<div class="callout ok"><b>Invito creato per ${esc(r.email)}</b> (${r.role === 'guest' ? 'ospite' : 'membro'}). Se la mail non arriva, condividi il link:</div><div class="copybox"><code>${esc(r.url)}</code><button type="button" class="btn ghost sm" data-copy>${ic('copy', 's')} Copia</button></div>`;
      $('[data-copy]', box).onclick = () => navigator.clipboard?.writeText(r.url).then(() => toast('Link copiato', 'ok'));
      $('[data-ok]', m.el).classList.add('hide');
    } catch (e) { err.textContent = e.errors?.email?.[0] || e.message; err.classList.remove('hide'); }
  };
}
function openSearch() {
  let timer;
  const m = modal({cls: 'search-modal', body: `<div class="sinp">${ic('search')}<input type="text" placeholder="Cerca messaggi, file, persone…" aria-label="Cerca" autofocus><kbd style="font:600 11px var(--mono);padding:2px 6px;border:1px solid var(--line);border-radius:4px;color:var(--text-3)">Esc</kbd></div><div class="res"><div style="padding:24px;text-align:center;color:var(--text-3);font-size:14px">Digita almeno 2 caratteri. Suggerimento: <b>@nome</b> per le persone, <b>#canale</b> per aprire un canale.</div></div>`});
  const res = $('.res', m.el), inp = $('input', m.el);
  const empty = () => `<div style="padding:24px;text-align:center;color:var(--text-3)">Nessun risultato</div>`;
  inp.oninput = () => { clearTimeout(timer); const q = inp.value.trim(); if (q.length < 2) return; timer = setTimeout(() => run(q), 220); };
  async function run(q) {
    if (q.startsWith('#')) { const cs = S.channels.filter(c => c.name.includes(q.slice(1).toLowerCase())); res.innerHTML = cs.map(c => `<button type="button" class="sres" data-c="${c.id}"><div class="m">${chIcon(c)} <b>${esc(c.name)}</b></div><div class="b">${esc(c.topic || '')}</div></button>`).join('') || empty(); return; }
    if (q.startsWith('@')) { const us = S.users.filter(u => u.name.toLowerCase().includes(q.slice(1).toLowerCase())); res.innerHTML = us.map(u => `<button type="button" class="sres" data-u="${u.id}"><div class="m">${avatar(u, 'sm')} <b>${esc(u.name)}</b> ${esc(u.email)}</div></button>`).join('') || empty(); return; }
    res.innerHTML = `<div style="padding:20px;text-align:center;color:var(--text-3)">Ricerca…</div>`;
    try {
      const r = await api('GET', `/search?q=${encodeURIComponent(q)}`); const items = list(r); const names = r?.channels || {};
      const rx = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
      res.innerHTML = items.map(x => `<button type="button" class="sres" data-c="${x.channel_id}" data-m="${x.id}" data-p="${x.parent_id || ''}"><div class="m"><b>${esc(x.user?.name)}</b> in #${esc(names[x.channel_id] || x.channel_id)} · ${fmtRel(x.created_at)}</div><div class="b">${esc((x.body || '[allegato]').replace(/[*_`>#]+/g, '')).replace(rx, '<mark>$1</mark>')}</div></button>`).join('') || empty();
    } catch (e) { res.innerHTML = `<div style="padding:20px;color:var(--danger)">${esc(e.message)}</div>`; }
  }
  res.onclick = async e => { const b = e.target.closest('.sres'); if (!b) return; m.close();
    if (b.dataset.u) return openDm(+b.dataset.u);
    const cid = +b.dataset.c; if (!chById(cid)) await loadChannels();
    go(`/app/c/${cid}${b.dataset.p ? '/t/' + b.dataset.p : ''}`);
    if (b.dataset.m) setTimeout(() => { const el = $(`#m-${b.dataset.m}`); if (el) { el.scrollIntoView({block: 'center'}); el.classList.add('highlight'); } }, 400);
  };
}
function notifPanel(anchor) {
  const row = n => { const icon = n.reason === 'mention' ? 'at' : n.reason === 'dm' ? 'mail' : n.reason === 'thread' ? 'thread' : 'hash';
    return `<button type="button" class="notif ${n.read_at ? '' : 'unread'}" data-id="${n.id}" data-c="${n.channel_id}" data-p="${n.parent_id || ''}" data-m="${n.message_id}"><span class="ic ${n.reason}">${ic(icon, 's')}</span><div class="tx"><b>${esc(n.from)} ${n.reason === 'mention' ? 'ti ha menzionato in #' + esc(n.channel_name) : n.reason === 'dm' ? 'ti ha scritto in privato' : n.reason === 'thread' ? 'ha risposto nel thread in #' + esc(n.channel_name) : 'ha scritto in #' + esc(n.channel_name)}</b><p>${esc(n.excerpt)}</p><small>${fmtRel(n.created_at)}</small></div></button>`; };
  popover(anchor, `<div class="hd">Attività <span class="hd-acts"><button type="button" data-all>Segna tutte come lette</button>${S.notifs.length ? '<button type="button" data-clear>Cancella tutte</button>' : ''}</span></div><div class="list">${S.notifs.length ? S.notifs.map(row).join('') : `<div class="empty" style="min-height:140px"><span class="ico">${ic('bell')}</span><p>Nessuna notifica: quando qualcuno ti menziona o ti scrive lo vedrai qui.</p></div>`}</div>
    <div class="sep"></div><button type="button" class="mi" data-prefs>${ic('settings', 's')} Preferenze di notifica</button>`, {align: 'right', cls: 'notif-pop', onOpen: p => {
    $('[data-all]', p).onclick = async () => { await api('POST', '/notifications/read-all').catch(() => {}); S.notifs.forEach(n => n.read_at = n.read_at || new Date().toISOString()); S.unreadN = 0; renderBadges(); closePop(); };
    const clr = $('[data-clear]', p);
    if (clr) clr.onclick = async () => { if (!confirm('Cancellare tutte le notifiche?')) return; await api('DELETE', '/notifications').catch(() => {}); S.notifs = []; S.unreadN = 0; renderBadges(); closePop(); };
    $('[data-prefs]', p).onclick = () => { closePop(); go('/app/profile'); setTimeout(() => $('#notifiche')?.scrollIntoView({behavior: 'smooth'}), 80); };
    $('.list', p).onclick = async e => { const b = e.target.closest('.notif'); if (!b) return; closePop();
      const n = S.notifs.find(x => x.id === b.dataset.id); if (n && !n.read_at) { n.read_at = new Date().toISOString(); S.unreadN = Math.max(0, S.unreadN - 1); renderBadges(); api('POST', `/notifications/${n.id}/read`).catch(() => {}); }
      if (!chById(+b.dataset.c)) await loadChannels();
      go(`/app/c/${b.dataset.c}${b.dataset.p ? '/t/' + b.dataset.p : ''}`);
      setTimeout(() => { const el = $(`#m-${b.dataset.m}`); if (el) { el.scrollIntoView({block: 'center'}); el.classList.add('highlight'); } }, 400); };
  }});
  if (window.Notification && Notification.permission === 'default' && S.user.notification_prefs?.push !== false) Notification.requestPermission().catch(() => {});
}

/* ============================================================ Pagine: profilo / admin */
function renderPage() {
  const page = $('#page'); if (!page) return;
  if (S.route.view === 'profile') return renderProfile(page);
  if (S.route.view === 'admin') { if (!isAdmin()) { go('/app', true); return; } return renderAdmin(page); }
}
/** Selettore immagine avatar: carica, ritaglia lato server (256×256) e restituisce l'utente aggiornato. */
function pickAvatar(path) {
  return new Promise(res => {
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/png,image/jpeg,image/webp,image/gif';
    inp.onchange = async () => { const f = inp.files[0]; if (!f) return res(null); if (f.size > 8 * 1024 * 1024) { toast('Immagine troppo grande (max 8 MB)', 'err'); return res(null); }
      const fd = new FormData(); fd.append('avatar', f, f.name);
      try { res(one(await api('POST', path, fd))); } catch (e) { toast(e.errors ? Object.values(e.errors).flat()[0] : e.message, 'err'); res(null); } };
    inp.click();
  });
}
function syncUser(u) { const i = S.users.findIndex(x => x.id === u.id); if (i >= 0) S.users[i] = Object.assign(S.users[i], u); if (u.id === S.user.id) Object.assign(S.user, u); }
function pageHead(title, sub) { return `<div class="page-head"><button class="back" data-back aria-label="Torna alla chat">${ic('chevL')}</button><div><h1>${title}</h1><p>${sub}</p></div></div>`; }
function renderProfile(page) {
  const u = S.user, p = u.notification_prefs || {};
  page.innerHTML = `<div class="page-in">${pageHead('Il tuo profilo', 'Dati personali, stato, notifiche e token per le integrazioni')}
    <div class="card"><div class="avatar-edit">${avatar(u, 'lg')}<div><h3 style="margin:0">${esc(u.name)}</h3><div style="color:var(--text-2);margin-bottom:8px">${esc(u.email)} · ${u.role === 'admin' ? 'Amministratore' : u.role === 'guest' ? 'Ospite' : 'Membro'}</div><div class="acts"><button type="button" class="btn ghost sm" id="pf-ava">${ic('image', 's')} ${u.avatar_url ? 'Cambia immagine' : 'Carica immagine'}</button>${u.avatar_url ? `<button type="button" class="btn ghost sm" id="pf-ava-rm">${ic('trash', 's')} Rimuovi</button>` : ''}</div></div></div>
      <div class="grid2"><div class="field"><label for="pf-name">Nome</label><input id="pf-name" type="text" value="${esc(u.name)}"></div><div class="field"><label for="pf-title">Ruolo / titolo</label><input id="pf-title" type="text" value="${esc(u.title || '')}" placeholder="es. Product Designer"></div>
      <div class="field"><label for="pf-status">Stato</label><input id="pf-status" type="text" value="${esc(u.status_text || '')}" placeholder="es. In riunione fino alle 15"></div>
      <div class="field"><label>Colore avatar</label><div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:4px">${['#4a154b', '#1264a3', '#2bac76', '#e8912d', '#e01e5a', '#5f3dc4', '#0b7285', '#862e9c'].map(c => `<button type="button" class="ava" data-color="${c}" style="background:${c};width:32px;height:32px;${u.avatar_color === c ? 'box-shadow:0 0 0 2px var(--surface),0 0 0 4px ' + c : ''}" aria-label="Colore ${c}"></button>`).join('')}</div></div></div>
      <div style="display:flex;justify-content:flex-end"><button class="btn primary" id="pf-save">Salva profilo</button></div></div>
    <div class="card" id="notifiche"><h3>Notifiche</h3><p class="sub">Scegli quando essere avvisato. Le impostazioni per singolo canale si trovano nei dettagli del canale.</p>
      ${[['mentions', 'Menzioni', 'Quando qualcuno scrive @' + u.name.split(' ')[0]], ['dms', 'Messaggi diretti', 'Nuovi messaggi privati'], ['push', 'Notifiche desktop e push', 'Avvisi del browser e notifiche push sul telefono (app Android/iOS)'], ['sound', 'Suoni', 'Riproduci un suono per i nuovi messaggi'], ['email', 'Email', 'Ricevi una mail per menzioni e DM quando sei offline']].map(([k, t, d]) => `<label class="check"><span class="switch ${(k === 'email' ? !!p[k] : p[k] !== false) ? 'on' : ''}" data-pref="${k}" role="switch"></span><div><b>${t}</b><small>${d}</small></div></label>`).join('')}
      <div class="field" style="margin-top:10px"><label>Canali</label><div class="seg" id="pf-ch">${[['all', 'Tutti i messaggi'], ['mentions', 'Solo menzioni'], ['none', 'Nessuno']].map(([k, l]) => `<button type="button" data-v="${k}" class="${(p.channels || 'all') === k ? 'on' : ''}">${l}</button>`).join('')}</div></div></div>
    <div class="card" id="aspetto"><h3>Aspetto</h3><p class="sub">Scegli il tema dell'interfaccia. "Automatico" segue le impostazioni del sistema operativo.</p>
      <div class="theme-opts">${[['light', 'Chiaro', 'sun', 'Sfondo bianco, barra laterale melanzana'], ['dark', 'Scuro', 'moon', 'Riposa la vista, ideale di sera'], ['system', 'Automatico', 'monitor', 'Come il sistema operativo']].map(([k, l, i, d]) => `<button type="button" class="theme-opt ${themePref() === k ? 'on' : ''}" data-theme-opt="${k}" aria-pressed="${themePref() === k}"><span class="pv ${({light: 'l', dark: 'd', system: 'a'})[k]}"><i></i><i></i></span><b>${ic(i, 's')} ${l}</b><small>${d}</small></button>`).join('')}</div></div>
    <div class="card"><h3>Password</h3><p class="sub">Scegli una password di almeno 8 caratteri.</p><div class="grid2"><div class="field"><label for="pw-cur">Password attuale</label><input id="pw-cur" type="password" autocomplete="current-password"></div><div></div><div class="field"><label for="pw-new">Nuova password</label><input id="pw-new" type="password" autocomplete="new-password"></div><div class="field"><label for="pw-conf">Conferma</label><input id="pw-conf" type="password" autocomplete="new-password"></div></div><div style="display:flex;justify-content:flex-end"><button class="btn ghost" id="pw-save">Aggiorna password</button></div></div>
    <div class="card" id="api"><h3>API & MCP</h3><p class="sub">Crea un token personale per usare AcidTalk dall'app mobile, dalle API REST o come server MCP in Claude.ai / Claude Desktop / Perplexity.</p>
      <div class="callout info">Endpoint MCP: <code>${location.origin}/api/mcp</code> · Header: <code>Authorization: Bearer &lt;token&gt;</code> · API REST: <code>${location.origin}/api/…</code></div>
      <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap"><input class="inp" id="tk-name" placeholder="Nome token (es. Claude Desktop)" style="max-width:320px"><button class="btn aub" id="tk-new">${ic('key', 's')} Genera token</button></div>
      <div id="tk-out"></div><div id="tk-list" style="margin-top:8px;color:var(--text-2);font-size:13px">Caricamento token…</div></div></div>`;
  $('[data-back]', page).onclick = () => go('/app');
  $('#pf-ava', page).onclick = async () => { const r = await pickAvatar('/me/avatar'); if (r) { syncUser(r); renderProfile(page); renderSidebar(); toast('Immagine del profilo aggiornata', 'ok'); } };
  const rm = $('#pf-ava-rm', page); if (rm) rm.onclick = async () => { try { syncUser(one(await api('DELETE', '/me/avatar'))); renderProfile(page); renderSidebar(); } catch (e) { toast(e.message, 'err'); } };
  $$('[data-color]', page).forEach(b => b.onclick = async () => { try { Object.assign(S.user, one(await api('PUT', '/me', {avatar_color: b.dataset.color}))); renderProfile(page); renderSidebar(); } catch (e) { toast(e.message, 'err'); } });
  $('#pf-save', page).onclick = async () => { try { Object.assign(S.user, one(await api('PUT', '/me', {name: $('#pf-name').value, title: $('#pf-title').value, status_text: $('#pf-status').value}))); renderSidebar(); toast('Profilo aggiornato', 'ok'); } catch (e) { toast(e.errors ? Object.values(e.errors).flat()[0] : e.message, 'err'); } };
  $$('[data-pref]', page).forEach(s => s.onclick = async () => { const k = s.dataset.pref; const v = !s.classList.contains('on'); s.classList.toggle('on', v); try { Object.assign(S.user, one(await api('PUT', '/me', {notification_prefs: {[k]: v}}))); if (k === 'push' && v && 'Notification' in window) Notification.requestPermission(); } catch (e) { toast(e.message, 'err'); } });
  $$('[data-theme-opt]', page).forEach(b => b.onclick = async () => { await setTheme(b.dataset.themeOpt); $$('[data-theme-opt]', page).forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b); }); });
  $$('#pf-ch button', page).forEach(b => b.onclick = async () => { $$('#pf-ch button', page).forEach(x => x.classList.toggle('on', x === b)); try { Object.assign(S.user, one(await api('PUT', '/me', {notification_prefs: {channels: b.dataset.v}}))); } catch (e) { toast(e.message, 'err'); } });
  $('#pw-save', page).onclick = async () => { try { await api('PUT', '/me', {current_password: $('#pw-cur').value, password: $('#pw-new').value, password_confirmation: $('#pw-conf').value}); toast('Password aggiornata', 'ok'); ['#pw-cur', '#pw-new', '#pw-conf'].forEach(s => $(s).value = ''); } catch (e) { toast(e.errors ? Object.values(e.errors).flat()[0] : e.message, 'err'); } };
  const loadTokens = async () => { try { const t = list(await api('GET', '/me/tokens')); const host = $('#tk-list'); if (!host) return; host.innerHTML = t.length ? `<table class="table"><thead><tr><th>Nome</th><th>Creato</th><th>Ultimo uso</th><th></th></tr></thead><tbody>${t.map(x => `<tr><td><b>${esc(x.name)}</b></td><td>${new Date(x.created_at).toLocaleDateString('it-IT')}</td><td>${x.last_used_at ? fmtRel(x.last_used_at) : 'mai'}</td><td><div class="acts"><button type="button" class="ibtn" data-rev="${x.id}" title="Revoca" aria-label="Revoca">${ic('trash', 's')}</button></div></td></tr>`).join('')}</tbody></table>` : 'Nessun token attivo.'; $$('[data-rev]', host).forEach(b => b.onclick = async () => { if (await confirmModal({title: 'Revocare il token?', text: 'Le integrazioni che lo usano smetteranno di funzionare.', ok: 'Revoca', danger: true})) { await api('DELETE', `/me/tokens/${b.dataset.rev}`); loadTokens(); } }); } catch { const host = $('#tk-list'); if (host) host.textContent = ''; } };
  loadTokens();
  $('#tk-new', page).onclick = async () => { try { const r = await api('POST', '/me/tokens', {name: $('#tk-name').value.trim() || 'token'}); $('#tk-out').innerHTML = `<div class="callout ok">Copia ora il token: non sarà più visibile.</div><div class="copybox"><code>${esc(r.token)}</code><button type="button" class="btn ghost sm" data-copy>${ic('copy', 's')} Copia</button></div>`; $('[data-copy]', $('#tk-out')).onclick = () => navigator.clipboard?.writeText(r.token).then(() => toast('Token copiato', 'ok')); loadTokens(); } catch (e) { toast(e.message, 'err'); } };
}

async function renderAdmin(page) {
  const tab = S.route.tab || 'org';
  const tabs = [['org', 'Organizzazione', 'globe'], ['ai', 'Assistente AI', 'sparkles'], ['video', 'Videochiamate', 'video'], ['users', 'Utenti', 'users'], ['bots', 'Bot', 'bot'], ['invites', 'Inviti', 'mail'], ['push', 'Push', 'bell'], ['system', 'Sistema', 'activity'], ['integrations', 'API & MCP', 'server']];
  page.innerHTML = `<div class="page-in">${pageHead('Amministrazione', `Impostazioni di ${esc(S.org.name)} — visibili solo agli amministratori`)}
    <div class="tabs">${tabs.map(([k, l, i]) => `<button type="button" data-tab="${k}" class="${tab === k ? 'on' : ''}">${ic(i, 's')} ${l}</button>`).join('')}</div><div id="adm"></div></div>`;
  $('[data-back]', page).onclick = () => go('/app');
  $$('[data-tab]', page).forEach(b => b.onclick = () => go(`/app/admin/${b.dataset.tab}`));
  const box = $('#adm'); box.innerHTML = `<div class="empty"><p>Caricamento…</p></div>`;
  let st; try { st = await api('GET', '/admin/settings'); } catch (e) { box.innerHTML = `<div class="callout">${esc(e.message)}</div>`; return; }
  if (!document.body.contains(box)) return;
  const s = st.settings || {};
  const save = async (payload, msg = 'Impostazioni salvate') => { try { st = await api('PUT', '/admin/settings', payload); Object.assign(S.org, {name: st.name, domain: st.domain, ai_bot_name: st.settings.ai_bot_name, video_provider: st.settings.video_provider, max_upload_mb: st.settings.max_upload_mb, allow_guest_invites: st.settings.allow_guest_invites, sidebar_dm_days: st.settings.sidebar_dm_days, sidebar_channel_days: st.settings.sidebar_channel_days, read_receipts: st.settings.read_receipts !== false}); renderSidebar(); toast(msg, 'ok'); return true; } catch (e) { toast(e.errors ? Object.values(e.errors).flat()[0] : e.message, 'err'); return false; } };
  const stats = `<div class="stats">${[['users', 'Utenti'], ['channels', 'Canali attivi'], ['messages', 'Messaggi'], ['invites_pending', 'Inviti in attesa']].map(([k, l]) => `<div class="stat"><b>${st.stats?.[k] ?? '–'}</b><span>${l}</span></div>`).join('')}</div>`;

  if (tab === 'org') {
    box.innerHTML = `${stats}<div class="card"><h3>Organizzazione</h3><p class="sub">Nome mostrato nell'app e dominio email abilitato all'iscrizione autonoma.</p>
      <div class="grid2"><div class="field"><label for="o-name">Nome</label><input id="o-name" type="text" value="${esc(st.name)}"></div><div class="field"><label for="o-domain">Dominio email</label><input id="o-domain" type="text" value="${esc(st.domain)}" placeholder="azienda.it"></div></div>
      <label class="check"><span class="switch ${st.allow_domain_signup ? 'on' : ''}" id="o-signup" role="switch"></span><div><b>Iscrizione libera per @${esc(st.domain)}</b><small>Chi ha un'email del dominio può registrarsi da solo dalla pagina di accesso</small></div></label>
      <label class="check"><span class="switch ${s.allow_guest_invites ? 'on' : ''}" id="o-guests" role="switch"></span><div><b>I membri possono invitare ospiti</b><small>Se disattivo, solo gli amministratori creano inviti</small></div></label>
      <label class="check"><span class="switch ${s.read_receipts !== false ? 'on' : ''}" id="o-reads" role="switch"></span><div><b>Conferme di lettura</b><small>Spunte sui messaggi inviati e "Visto da" sotto l'ultimo messaggio</small></div></label>
      <label class="check"><span class="switch ${s.emoji_autoconvert !== false ? 'on' : ''}" id="o-emoji" role="switch"></span><div><b>Conversione automatica delle emoji</b><small>:sorriso:, :+1: e le emoticon come :) o &lt;3 diventano emoji</small></div></label>
      <div class="field" style="max-width:240px"><label for="o-upload">Dimensione massima allegati (MB)</label><input id="o-upload" type="number" min="1" max="500" value="${esc(s.max_upload_mb || 25)}"></div>
      <div class="grid2"><div class="field"><label for="o-dmdays">Messaggi diretti nella barra laterale</label><input id="o-dmdays" type="number" min="1" max="3650" value="${esc(s.sidebar_dm_days || 60)}"><div class="help">Mostra le conversazioni attive negli ultimi N giorni (default 60). Le altre restano raggiungibili con "Altri messaggi diretti" o dalla ricerca.</div></div>
      <div class="field"><label for="o-chdays">Canali nella barra laterale</label><input id="o-chdays" type="number" min="1" max="3650" value="${esc(s.sidebar_channel_days || 90)}"><div class="help">Mostra i canali con attività negli ultimi N giorni (default 90). I canali con messaggi non letti sono sempre visibili.</div></div></div>
      <div style="display:flex;justify-content:flex-end"><button class="btn primary" id="o-save">Salva</button></div></div>`;
    $$('.switch', box).forEach(sw => sw.onclick = () => sw.classList.toggle('on'));
    $('#o-save').onclick = () => save({name: $('#o-name').value, domain: $('#o-domain').value, allow_domain_signup: $('#o-signup').classList.contains('on'), settings: {allow_guest_invites: $('#o-guests').classList.contains('on'), read_receipts: $('#o-reads').classList.contains('on'), emoji_autoconvert: $('#o-emoji').classList.contains('on'), max_upload_mb: +$('#o-upload').value, sidebar_dm_days: +$('#o-dmdays').value || 60, sidebar_channel_days: +$('#o-chdays').value || 90}});
  }
  if (tab === 'ai') {
    const prov = s.ai_provider || 'ollama';
    const cards = [['ollama', 'Ollama', 'Modelli open source in locale o su un server interno. Nessun dato esce dall\'azienda.'], ['openai', 'OpenAI', 'GPT-4o, GPT-4.1 e successivi via API. Richiede una chiave API.'], ['anthropic', 'Anthropic', 'Claude Sonnet / Opus via API. Richiede una chiave API.']];
    const defModel = Object.assign({ollama: 'llama3.1', openai: 'gpt-4o-mini', anthropic: 'claude-sonnet-5-5'}, s.ai_model_defaults || {});
    const fits = (p, m) => !m || (p === 'anthropic' ? /^claude/.test(m) : p === 'openai' ? /^(gpt-|chatgpt|o\d|ft:)/.test(m) : !/^(claude|gpt-)/.test(m));
    box.innerHTML = `<div class="card"><h3>Provider AI</h3><p class="sub">L'assistente risponde nei DM "AI" e quando viene menzionato con <b>@ai</b> nei canali. Il provider scelto qui sovrascrive il valore in <code>.env</code>.</p>
      <div class="provider-cards">${cards.map(([k, n, d]) => `<button type="button" class="pcard ${prov === k ? 'on' : ''}" data-p="${k}"><b>${ic(k === 'ollama' ? 'server' : k === 'openai' ? 'zap' : 'sparkles', 's')} ${n}</b><small>${d}</small></button>`).join('')}</div>
      <div class="grid2">
        <div class="field"><label for="ai-model">Modello</label><input id="ai-model" type="text" value="${esc(s.ai_model || '')}" placeholder="${defModel[prov]}"><div class="help">Lascia vuoto per usare il default del provider.</div></div>
        <div class="field"><label for="ai-name">Nome del bot</label><input id="ai-name" type="text" value="${esc(s.ai_bot_name || 'AI')}"></div>
        <div class="field ${prov === 'ollama' ? '' : 'hide'}" data-for="ollama"><label for="ai-ollama">URL Ollama</label><input id="ai-ollama" type="url" value="${esc(s.ollama_base_url || '')}" placeholder="http://127.0.0.1:11434"></div>
        <div class="field ${prov === 'openai' ? '' : 'hide'}" data-for="openai"><label for="ai-okey">Chiave API OpenAI</label><input id="ai-okey" type="password" value="${esc(s.openai_api_key || '')}" placeholder="${st.env_defaults?.has_openai_key ? 'configurata in .env' : 'sk-…'}"></div>
        <div class="field ${prov === 'anthropic' ? '' : 'hide'}" data-for="anthropic"><label for="ai-akey">Chiave API Anthropic</label><input id="ai-akey" type="password" value="${esc(s.anthropic_api_key || '')}" placeholder="${st.env_defaults?.has_anthropic_key ? 'configurata in .env' : 'sk-ant-…'}"></div>
      </div>
      <div class="field"><label for="ai-prompt">Istruzioni di sistema</label><textarea id="ai-prompt" placeholder="Sei l'assistente di ${esc(st.name)}. Rispondi in modo conciso…">${esc(s.ai_system_prompt || '')}</textarea></div>
      <div id="ai-test-out" class="ai-test hide" role="status" aria-live="polite"></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap"><button class="btn ghost" id="ai-test">${ic('zap', 's')} Prova connessione</button><button class="btn primary" id="ai-save">Salva</button></div></div>`;
    let cur = prov;
    $$('.pcard', box).forEach(b => b.onclick = () => { cur = b.dataset.p; $$('.pcard', box).forEach(x => x.classList.toggle('on', x === b)); $$('[data-for]', box).forEach(f => f.classList.toggle('hide', f.dataset.for !== cur)); $('#ai-model').placeholder = defModel[cur]; if (!fits(cur, $('#ai-model').value.trim())) $('#ai-model').value = ''; $('#ai-test-out').classList.add('hide'); });
    const aiForm = () => ({ai_provider: cur, ai_model: fits(cur, $('#ai-model').value.trim()) ? ($('#ai-model').value.trim() || null) : null, ollama_base_url: $('#ai-ollama').value || null, openai_api_key: $('#ai-okey').value || null, anthropic_api_key: $('#ai-akey').value || null});
    $('#ai-test').onclick = async () => {
      const btn = $('#ai-test'), out = $('#ai-test-out'); btn.disabled = true; out.className = 'ai-test'; out.textContent = 'Contatto il provider…';
      try {
        const r = await api('POST', '/admin/ai/test', aiForm());
        out.className = 'ai-test ' + (r.ok ? 'ok' : 'err');
        out.innerHTML = r.ok
          ? `${ic('check', 's')} <div><b>Connessione riuscita</b> · ${esc(r.provider)} / <code>${esc(r.model)}</code> · ${(r.ms / 1000).toFixed(1)} s<br><span>${esc(r.reply)}</span></div>`
          : `${ic('warn', 's')} <div><b>Connessione non riuscita</b> · ${esc(r.provider)} / <code>${esc(r.model || '—')}</code><br><span>${esc(r.error)}</span></div>`;
      } catch (e) { out.className = 'ai-test err'; out.textContent = e.message; } finally { btn.disabled = false; }
    };
    $('#ai-save').onclick = () => save({settings: {ai_provider: cur, ai_model: aiForm().ai_model, ai_bot_name: $('#ai-name').value, ollama_base_url: $('#ai-ollama').value || null, openai_api_key: $('#ai-okey').value || null, anthropic_api_key: $('#ai-akey').value || null, ai_system_prompt: $('#ai-prompt').value || null}});
  }
  if (tab === 'video') {
    box.innerHTML = `<div class="card"><h3>Videochiamate</h3><p class="sub">Il tasto "videochiamata" crea un link e lo pubblica nel canale. I link a Meet, Jitsi, Zoom e Teams incollati nei messaggi vengono riconosciuti con il tasto "Partecipa".</p>
      <div class="radio-cards" style="margin-bottom:14px"><label class="check ${s.video_provider !== 'meet' ? 'on' : ''}"><input type="radio" name="vp" value="jitsi" ${s.video_provider !== 'meet' ? 'checked' : ''}><div><b>Jitsi Meet</b><small>Stanze generate automaticamente, nessun account necessario</small></div></label><label class="check ${s.video_provider === 'meet' ? 'on' : ''}"><input type="radio" name="vp" value="meet" ${s.video_provider === 'meet' ? 'checked' : ''}><div><b>Google Meet</b><small>Usa un link Meet fisso oppure apre meet.google.com/new</small></div></label></div>
      <div class="grid2"><div class="field"><label for="v-jitsi">Dominio Jitsi</label><input id="v-jitsi" type="text" value="${esc(s.jitsi_domain || 'meet.jit.si')}"></div><div class="field"><label for="v-meet">Link Google Meet fisso (opzionale)</label><input id="v-meet" type="url" value="${esc(s.meet_link || '')}" placeholder="https://meet.google.com/abc-defg-hij"></div></div>
      <div style="display:flex;justify-content:flex-end"><button class="btn primary" id="v-save">Salva</button></div></div>`;
    $$('input[name=vp]', box).forEach(r => r.onchange = () => $$('.radio-cards .check', box).forEach(l => l.classList.toggle('on', $('input', l).checked)));
    $('#v-save').onclick = () => save({settings: {video_provider: $('input[name=vp]:checked').value, jitsi_domain: $('#v-jitsi').value, meet_link: $('#v-meet').value || null}});
  }
  if (tab === 'users') {
    let users = list(await api('GET', '/admin/users').catch(() => []));
    const roleTag = r => `<span class="tag ${r}">${r === 'admin' ? 'admin' : r === 'guest' ? 'ospite' : 'membro'}</span>`;
    const draw = () => { box.innerHTML = `<div class="card"><div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap"><div><h3>Utenti (${users.length})</h3><p class="sub" style="margin:0">Crea account, cambia ruolo o reimposta le password.</p></div><span style="flex:1"></span><button class="btn primary" id="u-new">${ic('plus', 's')} Nuovo utente</button></div>
      <table class="table"><thead><tr><th>Utente</th><th class="hide-m">Titolo</th><th>Ruolo</th><th class="hide-m">Ultimo accesso</th><th></th></tr></thead><tbody>${users.map(u => `<tr><td><div class="who">${avatar(u)}<div><b>${esc(u.name)}</b><small>${esc(u.email)}</small></div></div></td><td class="hide-m">${esc(u.title || '—')}</td><td>${roleTag(u.role)}</td><td class="hide-m">${u.last_seen_at ? fmtRel(u.last_seen_at) : 'mai'}</td><td><div class="acts"><button type="button" class="ibtn" data-ava="${u.id}" title="Immagine profilo" aria-label="Immagine profilo">${ic('image', 's')}</button><button type="button" class="ibtn" data-edit="${u.id}" title="Modifica" aria-label="Modifica">${ic('edit', 's')}</button>${u.id !== S.user.id ? `<button type="button" class="ibtn" data-del="${u.id}" title="Elimina" aria-label="Elimina">${ic('trash', 's')}</button>` : ''}</div></td></tr>`).join('')}</tbody></table></div>`;
      $('#u-new').onclick = () => userForm();
      $$('[data-edit]', box).forEach(b => b.onclick = () => userForm(users.find(u => u.id === +b.dataset.edit)));
      $$('[data-ava]', box).forEach(b => b.onclick = async () => { const r = await pickAvatar(`/admin/users/${b.dataset.ava}/avatar`); if (r) { users = users.map(x => x.id === r.id ? r : x); syncUser(r); draw(); toast('Immagine aggiornata', 'ok'); } });
      $$('[data-del]', box).forEach(b => b.onclick = async () => { const u = users.find(x => x.id === +b.dataset.del); if (!await confirmModal({title: `Eliminare ${esc(u.name)}?`, text: 'L\'utente perderà l\'accesso. I suoi messaggi resteranno visibili.', ok: 'Elimina', danger: true})) return; try { await api('DELETE', `/admin/users/${u.id}`); users = users.filter(x => x.id !== u.id); draw(); toast('Utente eliminato', 'ok'); } catch (e) { toast(e.message, 'err'); } });
    };
    const userForm = (u) => {
      const m = modal({title: u ? `Modifica ${u.name}` : 'Nuovo utente', body: `<div class="grid2"><div class="field"><label for="uf-name">Nome</label><input id="uf-name" type="text" value="${esc(u?.name || '')}" autofocus></div><div class="field"><label for="uf-email">Email</label><input id="uf-email" type="email" value="${esc(u?.email || '')}"></div><div class="field"><label for="uf-title">Titolo</label><input id="uf-title" type="text" value="${esc(u?.title || '')}"></div><div class="field"><label for="uf-role">Ruolo</label><select id="uf-role">${[['member', 'Membro'], ['admin', 'Amministratore'], ['guest', 'Ospite']].map(([k, l]) => `<option value="${k}" ${u?.role === k ? 'selected' : ''}>${l}</option>`).join('')}</select></div><div class="field"><label for="uf-pass">${u ? 'Nuova password (vuota = invariata)' : 'Password'}</label><input id="uf-pass" type="password" autocomplete="new-password"></div></div><div class="err hide" style="color:var(--danger);font-size:13px;font-weight:600"></div>`,
        foot: `<button class="btn ghost" data-close>Annulla</button><button class="btn primary" data-ok>${u ? 'Salva' : 'Crea utente'}</button>`});
      $('[data-ok]', m.el).onclick = async () => {
        const payload = {name: $('#uf-name').value, email: $('#uf-email').value, title: $('#uf-title').value, role: $('#uf-role').value, password: $('#uf-pass').value || undefined};
        try { const r = one(u ? await api('PUT', `/admin/users/${u.id}`, payload) : await api('POST', '/admin/users', payload)); if (u) users = users.map(x => x.id === r.id ? r : x); else users.push(r); m.close(); draw(); toast(u ? 'Utente aggiornato' : 'Utente creato', 'ok'); await loadUsers(); }
        catch (e) { const err = $('.err', m.el); err.textContent = e.errors ? Object.values(e.errors).flat()[0] : e.message; err.classList.remove('hide'); }
      };
    };
    draw();
  }
  if (tab === 'invites') {
    let inv = list(await api('GET', '/invites').catch(() => []));
    const draw = () => { box.innerHTML = `<div class="card"><div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap"><div><h3>Inviti</h3><p class="sub" style="margin:0">Link di invito per ospiti esterni e nuovi membri.</p></div><span style="flex:1"></span><button class="btn primary" id="i-new">${ic('mail', 's')} Nuovo invito</button></div>
      ${inv.length ? `<table class="table"><thead><tr><th>Email</th><th>Ruolo</th><th class="hide-m">Invitato da</th><th>Stato</th><th></th></tr></thead><tbody>${inv.map(i => `<tr><td><b>${esc(i.email)}</b></td><td>${i.role === 'guest' ? '<span class="tag guest">ospite</span>' : '<span class="tag">membro</span>'}</td><td class="hide-m">${esc(i.invited_by || '—')}</td><td>${i.accepted_at ? '<span class="tag ok">accettato</span>' : i.valid ? `<span class="tag">in attesa · scade ${new Date(i.expires_at).toLocaleDateString('it-IT')}</span>` : '<span class="tag danger">scaduto</span>'}</td><td><div class="acts">${!i.accepted_at ? `<button type="button" class="ibtn" data-copy="${esc(i.url)}" title="Copia link" aria-label="Copia link">${ic('copy', 's')}</button>` : ''}<button type="button" class="ibtn" data-del="${i.id}" title="Elimina" aria-label="Elimina">${ic('trash', 's')}</button></div></td></tr>`).join('')}</tbody></table>` : `<div class="empty" style="min-height:120px"><p>Nessun invito. Crea il primo per far entrare un ospite esterno.</p></div>`}</div>`;
      $('#i-new').onclick = () => inviteModal();
      $$('[data-copy]', box).forEach(b => b.onclick = () => navigator.clipboard?.writeText(b.dataset.copy).then(() => toast('Link copiato', 'ok')));
      $$('[data-del]', box).forEach(b => b.onclick = async () => { try { await api('DELETE', `/invites/${b.dataset.del}`); inv = inv.filter(x => x.id !== +b.dataset.del); draw(); } catch (e) { toast(e.message, 'err'); } });
    };
    draw();
  }
  if (tab === 'bots') await adminBots(box);
  if (tab === 'system') await adminSystem(box);
  if (tab === 'push') {
    const done = !!st.settings.fcm_project_id;
    box.innerHTML = `<div class="card"><h3>Notifiche push (Firebase)</h3><p class="sub">Le app Android e iOS ricevono le notifiche anche quando sono chiuse tramite Firebase Cloud Messaging. Il web continua a usare le notifiche del browser.</p>
      <div class="callout ${done ? 'ok' : 'info'}">${done ? `Firebase configurato · progetto <b>${esc(st.settings.fcm_project_id)}</b>${st.settings.fcm_from_env ? ' (da .env)' : ''}` : 'Non configurato: crea un progetto Firebase, genera una chiave dell\'account di servizio (JSON) e incollala qui sotto. Istruzioni complete in <code>docs/push-firebase.md</code>.'}</div>
      <div class="field"><label for="fcm-json">JSON dell'account di servizio</label><textarea id="fcm-json" rows="6" placeholder='{"type": "service_account", "project_id": "…", "private_key": "-----BEGIN PRIVATE KEY-----…", "client_email": "…"}' style="font-family:var(--mono);font-size:12px">${esc(s.fcm_service_account || '')}</textarea><div class="help">Firebase console › Impostazioni progetto › Account di servizio › Genera nuova chiave privata. La chiave resta sul server e non viene mai mostrata.</div></div>
      <div id="fcm-out" class="ai-test hide" role="status"></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap">${done ? `<button class="btn ghost" id="fcm-clear">${ic('trash', 's')} Rimuovi</button><button class="btn ghost" id="fcm-test">${ic('bell', 's')} Invia notifica di prova</button>` : ''}<button class="btn primary" id="fcm-save">Salva</button></div></div>`;
    $('#fcm-save').onclick = async () => { const v = $('#fcm-json').value.trim(); if (await save({settings: {fcm_service_account: v || null}}, 'Configurazione Firebase salvata')) renderAdmin(page); };
    const cl = $('#fcm-clear'); if (cl) cl.onclick = async () => { if (await save({settings: {}, clear_secrets: ['fcm_service_account']}, 'Configurazione Firebase rimossa')) renderAdmin(page); };
    const tb = $('#fcm-test'); if (tb) tb.onclick = async () => { const out = $('#fcm-out'); out.className = 'ai-test'; out.textContent = 'Invio in corso…';
      try { const r = await api('POST', '/admin/push/test'); out.className = 'ai-test ' + (r.ok ? 'ok' : 'err'); out.innerHTML = r.ok ? `${ic('check', 's')} <div><b>Notifica inviata</b> a ${r.sent} dispositivo/i${r.removed ? ` · ${r.removed} token scaduti rimossi` : ''}</div>` : `${ic('warn', 's')} <div><b>Invio non riuscito</b><br><span>${esc(r.error || (r.errors || []).join(' · '))}</span></div>`; }
      catch (e) { out.className = 'ai-test err'; out.textContent = e.message; } };
  }
  if (tab === 'integrations') {
    box.innerHTML = `<div class="card"><h3>API REST</h3><p class="sub">Tutte le funzioni dell'app sono esposte sotto <code>/api</code> con autenticazione Sanctum (token Bearer). Documentazione completa in <code>docs/api.md</code>.</p>
      <div class="copybox" style="margin-bottom:8px"><code>POST ${location.origin}/api/auth/login  {"email","password","device_name"}  →  {"token"}</code></div>
      <div class="copybox" style="margin-bottom:8px"><code>GET /api/channels · GET /api/channels/{id}/messages · POST /api/channels/{id}/messages · PUT/DELETE /api/messages/{id}</code></div>
      <div class="copybox"><code>GET/PUT /api/admin/settings · GET/POST/PUT/DELETE /api/admin/users</code></div></div>
      <div class="card"><h3>Server MCP</h3><p class="sub">Collega Claude.ai, Claude Desktop o Perplexity ai canali tramite Model Context Protocol (trasporto Streamable HTTP).</p>
      <div class="copybox" style="margin-bottom:8px"><code>URL: ${location.origin}/api/mcp<br>Header: Authorization: Bearer &lt;token personale&gt;</code></div>
      <p style="font-size:14px;color:var(--text-2);margin:8px 0">I <b>bot</b> (scheda Bot) usano le stesse API con il proprio token. Tool MCP disponibili: <code>list_channels</code>, <code>read_channel</code>, <code>read_thread</code>, <code>search_messages</code>, <code>post_message</code>, <code>list_users</code>. Ogni utente genera il proprio token dal profilo: il server MCP vede solo i canali a cui l'utente ha accesso.</p>
      <div class="copybox"><code>claude mcp add --transport http acidtalk ${location.origin}/api/mcp --header "Authorization: Bearer &lt;token&gt;"</code></div></div>
      <div class="card"><h3>Realtime & app mobile</h3><p class="sub">Gli eventi <code>message.sent</code>, <code>message.updated</code>, <code>message.deleted</code> viaggiano su Laravel Reverb (canali privati <code>channel.{id}</code> e <code>user.{id}</code>). L'app Flutter (Android/iOS) usa le stesse API con token Bearer: vedi <code>docs/mobile.md</code>.</p>${B.reverb ? `<div class="callout ok">Reverb configurato: ${esc(B.reverb.scheme)}://${esc(B.reverb.host)}:${B.reverb.port}</div>` : `<div class="callout">Reverb non configurato: la web app aggiorna i messaggi con il polling ogni 6 secondi. Imposta <code>BROADCAST_CONNECTION=reverb</code> e avvia <code>php artisan reverb:start</code>.</div>`}</div>`;
  }
}

/* ============================================================ Admin: bot */
async function adminBots(box) {
  let bots = list(await api('GET', '/admin/bots').catch(() => []));
  const chans = S.channels.filter(c => ['public', 'private'].includes(c.type));
  const showToken = (title, r) => {
    const m = modal({title, body: `<div class="callout ok">Copia ora il token: per sicurezza non sarà più visibile.</div><div class="field"><label>Token API (Authorization: Bearer …)</label><div class="token-box">${esc(r.token)}</div></div>${r.webhook_secret ? `<div class="field"><label>Segreto webhook (firma HMAC-SHA256 nell'header X-AcidTalk-Signature)</label><div class="token-box">${esc(r.webhook_secret)}</div></div>` : ''}
      <div class="field"><label>Prova da terminale</label><div class="token-box">curl -X POST ${esc(location.origin)}/api/channels/&lt;id&gt;/messages -H "Authorization: Bearer ${esc(r.token)}" -H "Content-Type: application/json" -d '{"body":"Ciao dal bot"}'</div></div>`,
      foot: `<button class="btn ghost" data-cp>${ic('copy', 's')} Copia token</button><button class="btn primary" data-close>Fatto</button>`});
    $('[data-cp]', m.el).onclick = () => navigator.clipboard?.writeText(r.token).then(() => toast('Token copiato', 'ok'));
  };
  const form = (b) => {
    const sel = new Set(b?.channel_ids || []);
    const m = modal({title: b ? `Modifica ${b.name}` : 'Nuovo bot', body: `<div class="grid2"><div class="field"><label for="bf-name">Nome</label><input id="bf-name" type="text" value="${esc(b?.name || '')}" placeholder="es. Meteo" autofocus><div class="help">Si menziona con @${esc(b ? b.name.toLowerCase().replace(/\s+/g, '') : 'nome')}</div></div><div class="field"><label for="bf-desc">Descrizione</label><input id="bf-desc" type="text" value="${esc(b?.bot_description || '')}" placeholder="Cosa fa il bot"></div></div>
      <div class="field"><label for="bf-hook">Webhook in uscita (opzionale)</label><input id="bf-hook" type="url" value="${esc(b?.webhook_url || '')}" placeholder="https://servizio.esempio.it/acidtalk"><div class="help">Riceve in POST JSON i messaggi in cui il bot è menzionato e i messaggi diretti. Se risponde con <code>{"text": "…"}</code> il testo viene pubblicato come risposta del bot.</div></div>
      <div class="field"><label>Canali</label><div class="userpick" style="max-height:200px">${chans.map(c => `<button type="button" class="u ${sel.has(c.id) ? 'on' : ''}" data-c="${c.id}"><span class="ava" style="background:var(--bg-3);color:var(--text)">${chIcon(c)}</span><div><b>${esc(c.name)}</b><small>${c.type === 'private' ? 'privato' : 'pubblico'}</small></div>${sel.has(c.id) ? `<span class="chk">${ic('check')}</span>` : ''}</button>`).join('') || '<p class="sub">Nessun canale</p>'}</div></div><div class="err hide" style="color:var(--danger);font-size:13px;font-weight:600"></div>`,
      foot: `<button class="btn ghost" data-close>Annulla</button><button class="btn primary" data-ok>${b ? 'Salva' : 'Crea bot'}</button>`});
    $('.userpick', m.el).onclick = e => { const x = e.target.closest('[data-c]'); if (!x) return; const id = +x.dataset.c; sel.has(id) ? sel.delete(id) : sel.add(id); x.classList.toggle('on', sel.has(id)); const ck = $('.chk', x); if (ck) ck.remove(); else x.insertAdjacentHTML('beforeend', `<span class="chk">${ic('check')}</span>`); };
    $('[data-ok]', m.el).onclick = async () => {
      const payload = {name: $('#bf-name', m.el).value.trim(), description: $('#bf-desc', m.el).value || null, webhook_url: $('#bf-hook', m.el).value.trim() || null, channel_ids: [...sel]};
      try { const r = b ? await api('PUT', `/admin/bots/${b.id}`, payload) : await api('POST', '/admin/bots', payload); m.close(); await loadUsers(); bots = list(await api('GET', '/admin/bots')); draw(); if (!b) showToken(`Bot ${r.data.name} creato`, r); else toast('Bot aggiornato', 'ok'); }
      catch (e) { const err = $('.err', m.el); err.textContent = e.errors ? Object.values(e.errors).flat()[0] : e.message; err.classList.remove('hide'); }
    };
  };
  const draw = () => {
    box.innerHTML = `<div class="card"><div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap"><div><h3>Bot (${bots.length})</h3><p class="sub" style="margin:0">Utenti automatici comandati via API: pubblicano messaggi con il proprio token e rispondono alle menzioni tramite webhook. Non possono accedere all'interfaccia.</p></div><span style="flex:1"></span><button class="btn primary" id="b-new">${ic('plus', 's')} Nuovo bot</button></div>
      ${bots.length ? bots.map(b => `<div class="bot-row">${avatar(b)}<div class="meta"><b>${esc(b.name)}</b> <span class="pill bot" style="font-size:10px;padding:1px 5px;border-radius:4px">BOT</span><small>${esc(b.bot_description || 'Nessuna descrizione')}</small><small>@${esc(b.name.toLowerCase().replace(/[^\p{L}\d._-]+/gu, ''))} · ${b.channel_ids.length} canali · token ${b.tokens ? 'attivo' : 'revocato'}${b.last_used_at ? ' · ultimo uso ' + fmtRel(b.last_used_at) : ''}</small>${b.webhook_url ? `<small>${ic('link', 's')} ${esc(b.webhook_url)}${b.last_delivery ? ` · ultima consegna ${fmtRel(b.last_delivery.at)}: ${b.last_delivery.error ? '<span style="color:var(--danger)">' + esc(b.last_delivery.error) + '</span>' : 'HTTP ' + b.last_delivery.status}` : ''}</small>` : ''}</div>
        <div class="acts"><button type="button" class="ibtn" data-bava="${b.id}" title="Immagine" aria-label="Immagine">${ic('image', 's')}</button>${b.webhook_url ? `<button type="button" class="ibtn" data-ping="${b.id}" title="Prova webhook" aria-label="Prova webhook">${ic('zap', 's')}</button>` : ''}<button type="button" class="ibtn" data-tok="${b.id}" title="Nuovo token" aria-label="Nuovo token">${ic('key', 's')}</button><button type="button" class="ibtn" data-bedit="${b.id}" title="Modifica" aria-label="Modifica">${ic('edit', 's')}</button><button type="button" class="ibtn" data-bdel="${b.id}" title="Elimina" aria-label="Elimina">${ic('trash', 's')}</button></div></div>`).join('')
        : `<div class="empty" style="min-height:140px"><span class="ico">${ic('bot', 'l')}</span><p>Nessun bot. Crea un bot per pubblicare avvisi da altri sistemi (CI, monitoraggio, gestionale) o rispondere ai comandi degli utenti.</p></div>`}</div>
      <div class="card"><h3>Come si usa</h3><p class="sub">Esempi completi in <code>docs/bots.md</code>.</p>
      <div class="copybox" style="margin-bottom:8px"><code>POST ${location.origin}/api/channels/{id}/messages · Authorization: Bearer &lt;token del bot&gt; · {"body": "Deploy completato :rocket:"}</code></div>
      <div class="copybox"><code>Webhook → POST {"event":"message","bot":{…},"channel":{…},"user":{…},"message":{"body":"@meteo domani?"}} · risposta {"text":"…"}</code></div></div>`;
    $('#b-new').onclick = () => form();
    $$('[data-bedit]', box).forEach(x => x.onclick = () => form(bots.find(b => b.id === +x.dataset.bedit)));
    $$('[data-bava]', box).forEach(x => x.onclick = async () => { const r = await pickAvatar(`/admin/users/${x.dataset.bava}/avatar`); if (r) { bots = bots.map(b => b.id === r.id ? Object.assign(b, r) : b); syncUser(r); draw(); } });
    $$('[data-tok]', box).forEach(x => x.onclick = async () => { if (!await confirmModal({title: 'Generare un nuovo token?', text: 'Il token attuale smetterà subito di funzionare.', ok: 'Genera'})) return; try { const r = await api('POST', `/admin/bots/${x.dataset.tok}/token`); showToken('Nuovo token', r); bots = list(await api('GET', '/admin/bots')); draw(); } catch (e) { toast(e.message, 'err'); } });
    $$('[data-ping]', box).forEach(x => x.onclick = async () => { try { const r = await api('POST', `/admin/bots/${x.dataset.ping}/ping`); toast(r.ok ? `Webhook raggiungibile (HTTP ${r.status})` : `Webhook non raggiungibile: ${r.error || 'HTTP ' + r.status}`, r.ok ? 'ok' : 'err'); } catch (e) { toast(e.message, 'err'); } });
    $$('[data-bdel]', box).forEach(x => x.onclick = async () => { const b = bots.find(y => y.id === +x.dataset.bdel); if (!await confirmModal({title: `Eliminare ${esc(b.name)}?`, text: 'Il token viene revocato. I messaggi già pubblicati restano.', ok: 'Elimina', danger: true})) return; try { await api('DELETE', `/admin/bots/${b.id}`); bots = bots.filter(y => y.id !== b.id); draw(); loadUsers(); } catch (e) { toast(e.message, 'err'); } });
  };
  draw();
}

/* ============================================================ Admin: stato del sistema */
async function adminSystem(box) {
  const fmt = v => v ? `${new Date(v).toLocaleString('it-IT', {day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'})} (${fmtRel(v)})` : 'mai';
  const draw = (d) => {
    const cron = d.cron.ok ? `<span class="ok">Attivo</span>` : d.cron.last_run ? `<span class="warn">Fermo</span>` : `<span class="ko">Mai eseguito</span>`;
    box.innerHTML = `<div class="card"><div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><div><h3>Stato del sistema</h3><p class="sub" style="margin:0">AcidTalk ${esc(d.version)} · PHP ${esc(d.php)}</p></div><span style="flex:1"></span><button class="btn ghost" id="sys-reload">${ic('refresh', 's')} Aggiorna</button><button class="btn primary" id="sys-run">${ic('play', 's')} Esegui ora</button></div>
      <div class="sys-grid">
        <div class="sys-card"><h4>${ic('clock', 's')} Cron (schedule:run)</h4><div class="v">${cron}<br>Ultima esecuzione: ${fmt(d.cron.last_run)}</div></div>
        <div class="sys-card"><h4>${ic('list', 's')} Coda</h4><div class="v">Connessione <b>${esc(d.queue.connection)}</b> · worker ${d.queue.worker ? 'attivo (QUEUE_WORKER=true)' : 'non usato'}<br>Job in attesa: <b>${d.queue.jobs ?? '–'}</b> · falliti: <b class="${d.queue.failed ? 'ko' : ''}">${d.queue.failed ?? '–'}</b></div></div>
        <div class="sys-card"><h4>${ic('sparkles', 's')} Assistente AI</h4><div class="v">Ultima risposta: ${fmt(d.ai.last_reply)}<br>Attività in sospeso: <b class="${d.tasks.pending ? 'warn' : ''}">${d.tasks.pending}</b></div></div>
        <div class="sys-card"><h4>${ic('bell', 's')} Notifiche push</h4><div class="v">${d.push.configured ? `<span class="ok">Firebase attivo</span> · ${esc(d.push.project_id)}` : '<span class="warn">Non configurate</span>'}<br>Dispositivi registrati: <b>${d.push.devices}</b> · ultimo invio: ${fmt(d.push.last_sent)}</div></div>
        <div class="sys-card"><h4>${ic('zap', 's')} Realtime e email</h4><div class="v">Broadcast: <b>${esc(d.broadcast)}</b>${['log', 'null'].includes(d.broadcast) ? ' (aggiornamento con polling)' : ''}<br>Email: <b>${esc(d.mail)}</b></div></div>
        <div class="sys-card"><h4>${ic('server', 's')} Estensioni PHP</h4><div class="v">${Object.entries(d.extensions).map(([k, v]) => `${k} <span class="${v ? 'ok' : 'ko'}">${v ? 'sì' : 'no'}</span>`).join(' · ')}</div></div>
      </div>
      ${!d.cron.ok ? `<div class="callout">Il cron non risulta attivo: le email e le attività rimaste in sospeso non vengono eseguite. Su SiteGround aggiungi in Site Tools › Devs › Cron Jobs, ogni minuto: <code>cd ~/www/&lt;dominio&gt;/public_html &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code></div>` : ''}
      ${d.ai.last_error ? `<h4 style="margin:14px 0 4px">Ultimo errore AI · ${fmt(d.ai.last_error.at)}</h4><div class="sys-err">${esc(d.ai.last_error.error)}</div>` : ''}
      ${d.push.last_error ? `<h4 style="margin:14px 0 4px">Ultimo errore push · ${fmt(d.push.last_error.at)}</h4><div class="sys-err">${esc(d.push.last_error.error)}</div>` : ''}
      ${d.queue.last_failed?.length ? `<h4 style="margin:14px 0 4px">Ultimi job falliti</h4>${d.queue.last_failed.map(j => `<div class="sys-err">#${j.id} · ${esc(j.failed_at)}\n${esc(j.error)}</div>`).join('')}` : ''}</div>`;
    $('#sys-reload').onclick = () => load();
    $('#sys-run').onclick = async () => { const b = $('#sys-run'); b.disabled = true; b.textContent = 'In esecuzione…'; try { const r = await api('POST', '/admin/system/run'); toast(`Eseguite ${r.tasks} attività in sospeso${r.queue_ran ? ' e la coda dei job' : ''}`, 'ok'); draw(r); } catch (e) { toast(e.message, 'err'); b.disabled = false; } };
  };
  const load = async () => { try { draw(await api('GET', '/admin/system')); } catch (e) { box.innerHTML = `<div class="callout">${esc(e.message)}</div>`; } };
  await load();
}

/* ============================================================ Boot */
async function boot() {
  const root = document.getElementById('app');
  root.innerHTML = `<div class="shell"><aside class="sidebar"></aside><section class="main" style="display:grid;place-items:center;color:var(--text-2)">Caricamento di AcidTalk…</section></div>`;
  await Promise.all([loadChannels(), loadUsers(), loadNotifs()]);
  initRealtime();
  await onRoute();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) loadNotifs(); });
}
boot().catch(e => { console.error(e); document.getElementById('app').innerHTML = `<div class="empty" style="height:100vh;background:var(--bg)"><span class="ico">${ic('warn', 'l')}</span><h3>Impossibile avviare AcidTalk</h3><p>${esc(e.message)}</p><button class="btn primary" onclick="location.reload()">Riprova</button></div>`; });
