<?php
// netmon – einfache Ansicht / Schnellzugriff: Internet-Ampel, Anschlüsse sperren/freigeben (mit Hinweis, wer betroffen ist),
// Switch-Lichter, letzte Ereignisse in Alltagssprache. Anschlüsse anheften: Profi-Ansicht → Switch → Port-Liste → „☆ Schnellzugriff“
require __DIR__ . '/db.php';
$me = require_login_page();
if (($_GET['ansicht'] ?? '') === 'einfach') {
    set_ui_mode('einfach');
}
$nm = ['role' => $me['role'], 'user' => $me['username'], 'csrf' => csrf_token()];
?>
<!doctype html>
<html lang="de" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <meta name="theme-color" content="#14151b">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="netmon">
  <link rel="manifest" href="manifest.json">
  <link rel="icon" href="assets/icon-192.png">
  <link rel="apple-touch-icon" href="assets/icon-192.png">
  <title>netmon</title>
  <script>
    (function () {
      let t = null;
      try { t = localStorage.getItem('netmon-theme'); } catch (e) {}
      if (!t) t = matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', t);
    })();
    window.NM = <?= json_encode($nm, JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    body { padding: max(16px, env(safe-area-inset-top)) 16px calc(24px + env(safe-area-inset-bottom)); }
    .q-wrap { max-width: 560px; margin: 0 auto; }
    .q-head { display: flex; align-items: center; gap: .6rem; margin-bottom: 1rem; }
    .q-head h1 { font-family: "DM Serif Display", Georgia, serif; font-size: 1.6rem; margin: 0; }
    .q-head .hi { font-size: .85rem; color: var(--bs-secondary-color); margin-left: .2rem; align-self: flex-end; padding-bottom: .2rem; }
    .q-head .tools { margin-left: auto; display: flex; gap: .4rem; }
    .q-sec { display: flex; align-items: center; gap: .5rem; font-size: .72rem; letter-spacing: .08em; text-transform: uppercase;
             color: var(--nm-muted); margin: 1.5rem 0 .55rem; }
    .q-card { background: var(--nm-surface); border: 1px solid var(--bs-border-color); border-radius: 1rem; }
    /* Ampel */
    .q-amp { display: flex; gap: 1rem; align-items: center; padding: 1rem 1.1rem; }
    .q-amp .lamp { width: 3.2rem; height: 3.2rem; border-radius: 50%; flex: 0 0 auto; display: grid; place-items: center;
                   font-size: 1.6rem; font-weight: 700; color: #fff; background: var(--nm-muted); }
    .q-amp.ok .lamp { background: var(--nm-online); } .q-amp.warn .lamp { background: var(--nm-warn); } .q-amp.bad .lamp { background: var(--nm-offline); }
    .q-amp .big { font-size: 1.3rem; font-weight: 650; line-height: 1.2; }
    .q-amp .det { font-size: .85rem; color: var(--bs-secondary-color); margin-top: .15rem; }
    .q-where { font-size: .85rem; color: var(--bs-secondary-color); padding: .65rem 1.1rem; border-top: 1px solid var(--bs-border-color); }
    .q-where b { color: var(--bs-body-color); font-weight: 600; }
    /* Anschlüsse */
    #qPorts { display: grid; grid-template-columns: minmax(0, 1fr); gap: .6rem; }
    .q-grid > * { min-width: 0; }
    .q-port { padding: .9rem 1rem; }
    .q-port.is-off { border-color: color-mix(in srgb, var(--nm-offline) 60%, var(--bs-border-color)); }
    .q-top { display: flex; align-items: center; gap: .9rem; }
    .q-dot { width: .8rem; height: .8rem; border-radius: 50%; flex: 0 0 auto; background: var(--bs-border-color); }
    .q-dot.on { background: var(--nm-online); } .q-dot.off { background: var(--nm-offline); }
    .q-main { flex: 1; min-width: 0; }
    .q-title { font-weight: 650; font-size: 1.1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .q-state { font-size: .9rem; }
    .q-state.off { color: var(--nm-offline); font-weight: 600; }
    .q-sub { font-size: .78rem; color: var(--bs-secondary-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .q-btn { min-width: 7.2rem; min-height: 3rem; border-radius: .8rem; font-weight: 600; border: 0; color: #fff; padding: 0 1rem; }
    .q-btn.lock { background: var(--nm-offline); } .q-btn.unlock { background: var(--nm-online); }
    .q-btn.plain { background: var(--nm-btn); color: var(--bs-body-color); border: 1px solid var(--nm-btn-border); }
    .q-btn:disabled { opacity: .55; }
    .q-btn.muted { background: var(--nm-btn); color: var(--bs-secondary-color); border: 1px solid var(--nm-btn-border); }
    .q-note { font-size: .85rem; margin-top: .7rem; padding: .6rem .75rem; border-radius: .7rem; background: var(--nm-btn); color: var(--bs-secondary-color); }
    .q-ask { margin-top: .8rem; padding-top: .8rem; border-top: 1px solid var(--bs-border-color); }
    .q-ask .h { font-size: .9rem; font-weight: 600; margin-bottom: .45rem; }
    .q-chips { display: flex; flex-wrap: wrap; gap: .35rem; }
    .q-chip { font-size: .8rem; padding: .2rem .55rem; border-radius: 999px; background: var(--nm-btn); border: 1px solid var(--bs-border-color); }
    .q-chip.on { border-color: color-mix(in srgb, var(--nm-online) 55%, var(--bs-border-color)); }
    .q-chip.off { color: var(--bs-secondary-color); }
    .q-chip.me { border-color: var(--nm-offline); color: var(--nm-offline); font-weight: 600; }
    .q-warn { margin-bottom: .7rem; padding: .7rem .8rem; border-radius: .7rem; font-size: .88rem;
              background: color-mix(in srgb, var(--nm-offline) 14%, transparent); border: 1px solid color-mix(in srgb, var(--nm-offline) 50%, transparent); }
    .q-actions { display: flex; gap: .5rem; margin-top: .8rem; }
    .q-actions .q-btn { flex: 1; }
    .q-router { padding: .2rem 1rem; margin-bottom: .6rem; }
    .q-router .q-rhead { font-size: .78rem; color: var(--bs-secondary-color); padding: .7rem 0 0; }
    .q-rr { display: flex; align-items: center; gap: .9rem; padding: .75rem 0; border-top: 1px solid var(--bs-border-color); }
    .q-rr:first-child { border-top: 0; }
    .q-rr .q-btn { min-width: 8.5rem; min-height: 2.7rem; }
    .q-rr-wrap .q-ask { margin-top: 0; padding-top: 0; border-top: 0; padding-bottom: .8rem; }
    .q-row { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
    .q-row .q-btn { width: 100%; }
    .q-ev { font-size: .88rem; padding: .5rem 0; border-top: 1px solid var(--bs-border-color); display: flex; gap: .6rem; align-items: baseline; }
    .q-ev:first-child { border-top: 0; }
    .q-ev .t { color: var(--bs-secondary-color); flex: 0 0 auto; font-variant-numeric: tabular-nums; font-size: .8rem; }
    .q-ev .k { width: .55rem; height: .55rem; border-radius: 50%; flex: 0 0 auto; background: var(--nm-muted); transform: translateY(-.05rem); }
    .q-ev .k.off, .q-ev .k.bad { background: var(--nm-offline); } .q-ev .k.on { background: var(--nm-online); }
    .q-empty { border-style: dashed; padding: 1rem; font-size: .9rem; color: var(--bs-secondary-color); }
    .q-help-btn { border: 1px solid var(--bs-border-color); background: none; color: var(--bs-secondary-color); border-radius: 50%;
                  width: 1.35rem; height: 1.35rem; font-size: .75rem; line-height: 1; padding: 0; letter-spacing: 0; }
    .q-hint { font-size: .85rem; color: var(--bs-secondary-color); margin: -.2rem 0 .6rem; }
    .q-help details { border-top: 1px solid var(--bs-border-color); padding: .6rem 0; }
    .q-help details:first-child { border-top: 0; }
    .q-help summary { cursor: pointer; font-weight: 600; font-size: .92rem; }
    .q-help p { font-size: .88rem; color: var(--bs-secondary-color); margin: .45rem 0 0; }
    .q-foot { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1.6rem; }
    .q-msg { position: fixed; left: 16px; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom)); max-width: 528px; margin: 0 auto;
             background: var(--nm-surface); border: 1px solid var(--bs-border-color); border-radius: .8rem; padding: .7rem 1rem; display: none;
             box-shadow: 0 6px 24px rgba(0,0,0,.25); }
    .q-msg.show { display: block; }
    .q-side .q-sec:first-child { margin-top: 1.5rem; }
    .q-side-box { padding: .2rem 1rem .6rem; }
    /* PC: Anschlüsse als Kacheln, rechts Lichter, Ereignisse und Hilfe */
    @media (min-width: 992px) {
      body { padding: 28px 32px; }
      .q-wrap { max-width: 1240px; }
      .q-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 2rem; align-items: start; }
      #qPorts { grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); align-items: start; }
      .q-side { position: sticky; top: 28px; }
      .q-msg { left: auto; right: 32px; bottom: 28px; width: 380px; }
    }
  </style>
</head>
<body>
<div class="q-wrap">
  <div class="q-head">
    <span class="brand-dot"></span><h1>netmon</h1><span class="hi" id="qHi"></span>
    <div class="tools">
      <button class="btn btn-ghost btn-sm" type="button" id="qTheme" aria-label="Hell/Dunkel" title="Hell/Dunkel"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor"/></svg></button>
    </div>
  </div>

  <div class="q-card" id="qAmp"></div>

  <div class="q-grid">
  <div>
    <div id="qRouterBox" hidden>
      <div class="q-sec">Router &amp; WLAN <button class="q-help-btn" type="button" data-help="router" aria-label="Erklärung">?</button></div>
      <div class="q-hint" id="hint-router" hidden>Das WLAN direkt vom Router ein- und ausschalten, das Gäste-WLAN für Besuch freigeben –
        oder bei Internet-Problemen neu verbinden (das Internet ist dabei etwa eine halbe Minute weg).</div>
      <div id="qRouters"></div>
    </div>
    <div class="q-sec">Anschlüsse <button class="q-help-btn" type="button" data-help="ports" aria-label="Erklärung">?</button></div>
    <div class="q-hint" id="hint-ports" hidden>Hier schaltest du einzelne Anschlüsse am Switch aus und wieder an – zum Beispiel das
      WLAN im Kinderzimmer. Vor dem Sperren siehst du, welche Geräte dann kein Netz mehr haben.</div>
    <div id="qPorts"></div>
  </div>

  <aside class="q-side">
    <div id="qLedBox">
      <div class="q-sec">Lichter an den Switches <button class="q-help-btn" type="button" data-help="leds" aria-label="Erklärung">?</button></div>
      <div class="q-hint" id="hint-leds" hidden>Schaltet nur die kleinen Lämpchen an den Netzwerk-Verteilern aus, zum Beispiel nachts.
        Das Netz läuft ganz normal weiter.</div>
      <div class="q-row">
        <button class="q-btn plain" type="button" data-led="0">Lichter aus</button>
        <button class="q-btn plain" type="button" data-led="1">Lichter an</button>
      </div>
      <div class="q-sub mt-2" id="qLedInfo" style="white-space:normal"></div>
    </div>

    <div class="q-sec">Was zuletzt passiert ist</div>
    <div class="q-card q-side-box" id="qEvents"></div>

    <div class="q-sec">Hilfe</div>
    <div class="q-card q-side-box q-help" id="qHelp"></div>
  </aside>
  </div>

  <div class="q-foot">
    <a class="btn btn-ghost btn-sm" href="./?ansicht=profi">Profi-Ansicht</a>
    <a class="btn btn-ghost btn-sm" href="logout.php">Abmelden</a>
  </div>
</div>
<div class="q-msg" id="qMsg" role="status"></div>

<script>
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const $ = (s) => document.querySelector(s);
let data = null;
let asking = null;   // Anschluss, bei dem gerade die Sperr-Rückfrage offen ist ("id:port")
let busy = false;

async function call(action, body) {
  const r = await fetch('api.php?action=' + action, body ? {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': NM.csrf }, body: JSON.stringify(body),
  } : {});
  if (r.status === 401) { location.href = 'login.php'; throw new Error('Bitte anmelden'); }
  const j = await r.json();
  if (!r.ok) throw new Error(j.error || r.statusText);
  return j;
}
function msg(t) {
  const m = $('#qMsg'); m.textContent = t; m.classList.add('show');
  clearTimeout(msg.t); msg.t = setTimeout(() => m.classList.remove('show'), 5000);
}
const hhmm = (s) => (s ? s.slice(11, 16) : '');
const when = (s) => {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  const today = new Date().toDateString() === d.toDateString();
  return today ? hhmm(s) : `${s.slice(8, 10)}.${s.slice(5, 7)}. ${hhmm(s)}`;
};
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

function renderAmp() {
  const i = data.internet, w = data.where, st = data.status;
  const icon = { ok: '✓', warn: '!', bad: '✕' }[i.state] || '?';
  let where = '';
  if (w.device) where = `Du bist hier mit <b>${esc(w.device)}</b>${w.via ? ` – über ${esc(w.via)}` : ''}.`;
  else if (w.remote) where = 'Du bist <b>von unterwegs</b> verbunden.';
  $('#qAmp').className = 'q-card';
  $('#qAmp').innerHTML = `<div class="q-amp ${esc(i.state)}"><div class="lamp">${icon}</div>
      <div><div class="big">${esc(i.text)}</div><div class="det">${esc(i.detail)}</div></div></div>
    <div class="q-where">${plural(st.online, 'Gerät', 'Geräte')} im Netz${where ? ' · ' + where : ''}</div>`;
}

function portState(p) {
  if (p.off) return `<div class="q-state off">Gesperrt – hier gibt es kein Netz</div>`;
  const n = p.onlineCount;
  const t = n ? `An · ${plural(n, 'Gerät', 'Geräte')} online` : p.link ? 'An' : 'An · gerade nichts angeschlossen';
  return `<div class="q-state">${t}</div>`;
}

function askBox(p) {
  const aff = p.affected;
  const on = aff.filter((a) => a.online);
  const show = (on.length ? on : aff).slice(0, 10);
  const more = (on.length ? on.length : aff.length) - show.length;
  const me = aff.find((a) => a.me);
  return `<div class="q-ask">
    ${me ? `<div class="q-warn"><b>Achtung, das betrifft dich selbst:</b> ${esc(me.name)} hängt an diesem Anschluss.
      Nach dem Sperren hast du hier kein Netz mehr und kannst ihn <b>von diesem Gerät nicht wieder freigeben</b> –
      dann nur von einem anderen Gerät oder am Handy über Mobilfunk.</div>` : ''}
    <div class="h">${on.length ? 'Danach haben kein Netz mehr:' : aff.length ? 'Gerade ist dort niemand online. Betroffen wären später:' : 'Dort ist gerade kein bekanntes Gerät angeschlossen.'}</div>
    ${show.length ? `<div class="q-chips">${show.map((a) => `<span class="q-chip ${a.me ? 'me' : a.online ? 'on' : 'off'}">${esc(a.name)}${a.me ? ' (du)' : ''}</span>`).join('')}
      ${more > 0 ? `<span class="q-chip off">und ${more} weitere</span>` : ''}</div>` : ''}
    <div class="q-actions">
      <button class="q-btn lock" type="button" data-do="lock">${me ? 'Trotzdem sperren' : 'Ja, sperren'}</button>
      <button class="q-btn plain" type="button" data-do="cancel">Abbrechen</button>
    </div></div>`;
}

function renderPorts() {
  if (!data.ports.length) {
    $('#qPorts').innerHTML = `<div class="q-card q-empty">${data.admin
      ? 'Noch keine Anschlüsse hier. In der <a href="./?ansicht=profi">Profi-Ansicht</a> den Switch öffnen und in der Port-Liste auf <b>☆ Schnellzugriff</b> tippen – dann erscheint der Anschluss hier mit einem großen Knopf.'
      : 'Es sind noch keine Anschlüsse zum Sperren eingerichtet. Das macht der Admin.'}</div>`;
    return;
  }
  $('#qPorts').innerHTML = data.ports.map((p, i) => {
    const key = p.id + ':' + p.p;
    let btn = '', note = '';
    if (!data.canSwitch) {
      note = 'Nur ansehen – zum Sperren und Freigeben fragst du den Admin.';
    } else if (p.cannot) {
      btn = `<button class="q-btn muted" type="button" disabled>geht nicht</button>`;
      note = p.cannot;
    } else if (p.guard && !p.off) {
      btn = `<button class="q-btn muted" type="button" disabled>geschützt</button>`;
      note = `Kann nicht gesperrt werden: ${p.guard}. Sonst wäre zu viel auf einmal offline.`;
    } else if (p.off) {
      btn = `<button class="q-btn unlock" type="button" data-i="${i}">Freigeben</button>`;
    } else if (asking !== key) {
      btn = `<button class="q-btn lock" type="button" data-i="${i}">Sperren</button>`;
    }
    if (p.stale && !note) note = `Stand ${when(p.updated) || 'unbekannt'} – der Switch hat sich länger nicht gemeldet.`;
    return `<div class="q-card q-port${p.off ? ' is-off' : ''}" data-key="${esc(key)}" data-i="${i}">
      <div class="q-top"><span class="q-dot ${p.off ? 'off' : p.link ? 'on' : ''}"></span>
        <div class="q-main"><div class="q-title">${esc(p.label)}</div>${portState(p)}
          <div class="q-sub">${esc(p.switch)}${p.location ? ' · ' + esc(p.location) : ''} · Anschluss ${p.p}</div></div>${btn}</div>
      ${note ? `<div class="q-note">${esc(note)}</div>` : ''}
      ${asking === key && !p.off ? askBox(p) : ''}</div>`;
  }).join('');
}

// Router-Karte: WLAN, Gäste-WLAN, neu verbinden – mit Rückfrage, wer betroffen ist
function chips(list) {
  const on = list.filter((a) => a.online);
  const show = (on.length ? on : list).slice(0, 10);
  const more = (on.length ? on.length : list.length) - show.length;
  return show.length ? `<div class="q-chips">${show.map((a) => `<span class="q-chip ${a.me ? 'me' : a.online ? 'on' : 'off'}">${esc(a.name)}${a.me ? ' (du)' : ''}</span>`).join('')}
    ${more > 0 ? `<span class="q-chip off">und ${more} weitere</span>` : ''}</div>` : '';
}
function renderRouters() {
  const rs = data.routers || [];
  $('#qRouterBox').hidden = !rs.length;
  $('#qRouters').innerHTML = rs.map((r, i) => {
    const can = data.canSwitch && r.canAct;
    const row = (title, state, btn, key, ask) => `<div class="q-rr-wrap"><div class="q-rr">
        <div class="q-main"><div class="q-title" style="font-size:1rem">${title}</div><div class="q-state${state.off ? ' off' : ''}">${esc(state.text)}</div></div>
        ${asking === key ? '' : btn}</div>${asking === key ? ask : ''}</div>`;
    const yesNo = (k, label) => `<div class="q-actions"><button class="q-btn lock" type="button" data-ract="${k}" data-r="${i}">${label}</button>
        <button class="q-btn plain" type="button" data-do="cancel">Abbrechen</button></div>`;
    const btn = (k, label, cls) => can ? `<button class="q-btn ${cls}" type="button" data-rask="${k}" data-r="${i}">${label}</button>` : '';
    const wl = r.wlan === null ? { text: 'Zustand unbekannt' } : r.wlan ? { text: `An · ${plural(r.wifiAffected.filter((a) => a.online).length, 'Gerät', 'Geräte')} verbunden` } : { text: 'Aus – kein WLAN vom Router', off: true };
    const wlanAsk = `<div class="q-ask">${r.self ? `<div class="q-warn"><b>Achtung, das betrifft dich selbst:</b> du bist über dieses WLAN verbunden.
        Danach hast du hier kein Netz mehr – einschalten geht dann nur von einem anderen Gerät oder über Mobilfunk.</div>` : ''}
        <div class="h">${r.wifiAffected.some((a) => a.online) ? 'Danach haben kein Netz mehr:' : 'Gerade ist niemand in diesem WLAN.'}</div>
        ${chips(r.wifiAffected)}${yesNo('wlan_off', r.self ? 'Trotzdem ausschalten' : 'Ja, ausschalten')}</div>`;
    const recAsk = `<div class="q-ask"><div class="h">Das Internet ist etwa eine halbe Minute weg – für alle im Haus.
        ${data.where.remote ? 'Du bist von unterwegs verbunden: netmon ist in der Zeit auch für dich weg und meldet sich danach wieder.' : ''}</div>
        ${yesNo('reconnect', 'Ja, neu verbinden')}</div>`;
    const has = (k) => r.actions.includes(k);
    return `<div class="q-card q-router${r.wlan === 0 ? ' is-off' : ''}">
      ${rs.length > 1 ? `<div class="q-rhead">${esc(r.name)}${r.location ? ' · ' + esc(r.location) : ''}</div>` : ''}
      ${!has('wlan_off') ? '' : row(`WLAN${r.ssid ? ` „${esc(r.ssid)}“` : ''}`, wl, r.wlan === 0 ? btn('wlan_on', 'Einschalten', 'unlock') : btn('wlan_off', 'Ausschalten', 'lock'), r.id + ':wlan_off', wlanAsk)}
      ${!has('guest_on') ? '' : row(`Gäste-WLAN${r.guestSsid ? ` „${esc(r.guestSsid)}“` : ''}`, r.guest === null ? { text: 'Zustand unbekannt' } : { text: r.guest ? 'An – Besuch kann sich verbinden' : 'Aus' },
            r.guest ? btn('guest_off', 'Ausschalten', 'plain') : btn('guest_on', 'Einschalten', 'unlock'), '', '')}
      ${!has('reconnect') ? '' : row('Internet neu verbinden', { text: 'Hilft, wenn das Internet hängt' }, btn('reconnect', 'Neu verbinden', 'plain'), r.id + ':reconnect', recAsk)}
      ${!r.canAct && data.canSwitch ? `<div class="q-note" style="margin-bottom:.8rem">Zum Schalten braucht netmon das Gerätepasswort von ${esc(r.name)} – das trägt der Admin in der Profi-Ansicht ein.</div>` : ''}
    </div>`;
  }).join('');
}

function renderSide() {
  $('#qLedBox').hidden = !data.canSwitch || !data.leds.count;
  $('#qLedInfo').textContent = `${plural(data.leds.count, 'Switch', 'Switches')}` +
    (data.leds.off ? ` · gehen automatisch um ${data.leds.off} Uhr aus${data.leds.on ? ` und um ${data.leds.on} Uhr an` : ''}` : '');
  $('#qEvents').innerHTML = data.simpleEvents.map((e) => `<div class="q-ev"><span class="k ${esc(e.kind)}"></span>
      <span class="t">${esc(when(e.t))}</span><span>${esc(e.text)}</span></div>`).join('')
    || '<div class="q-ev"><span class="q-sub">Nichts Besonderes.</span></div>';
}

const HELP = [
  ['Was passiert beim Sperren?', 'Der Anschluss am Switch wird abgeschaltet. Alles, was daran hängt – auch ein WLAN-Router und alle Handys, Tablets und Konsolen darin – hat dann kein Netz mehr. „Freigeben“ schaltet ihn wieder an; die Geräte verbinden sich nach ein bis zwei Minuten von selbst wieder.'],
  ['Warum steht bei einem Anschluss „geschützt“?', 'Dahinter hängt ein weiterer Switch, der Router oder netmon selbst. Gesperrt wäre ein ganzes Haus offline – oder netmon könnte den Anschluss nicht mehr freigeben. Deshalb geht das hier nicht.'],
  ['Was bedeutet die Ampel?', 'Grün: Das Internet läuft, regelmäßig gemessen. Gelb: langsam, oder der letzte Test ist fehlgeschlagen – meist ist das nach ein paar Minuten vorbei. Rot: Der Router meldet keine Verbindung ins Internet.'],
  ['Ich habe mich selbst ausgesperrt', 'Nimm ein Gerät, das nicht an diesem Anschluss hängt – oder am Handy das WLAN ausschalten und netmon über Mobilfunk öffnen: ' + location.origin + location.pathname.replace(/[^/]*$/, '') + ' . Dort auf „Freigeben“ tippen.'],
  ['Was macht „Internet neu verbinden“?', 'Der Router trennt die Verbindung ins Internet und baut sie gleich wieder auf – wie Stecker ziehen, nur schneller. Das hilft oft, wenn alles hängt. Für etwa eine halbe Minute ist das Internet für alle weg.'],
  ['Wie kommt ein neuer Anschluss hierher?', 'Das macht der Admin: in der Profi-Ansicht den Switch öffnen und beim Anschluss auf „☆ Schnellzugriff“ tippen. Den Namen (z. B. „WLAN Kinderzimmer“) trägt er am besten am Switch als Port-Beschreibung ein.'],
];
$('#qHelp').innerHTML = HELP.map(([q, a]) => `<details><summary>${esc(q)}</summary><p>${esc(a)}</p></details>`).join('');

function render() {
  $('#qHi').textContent = `Hallo ${data.user}`;
  renderAmp(); renderRouters(); renderPorts(); renderSide();
}

async function load() {
  if (busy) return;
  try { data = await call('quick'); render(); } catch (e) { msg(e.message); }
}

async function switchPort(p, on, btn) {
  busy = true;
  if (btn) { btn.disabled = true; btn.textContent = on ? 'gebe frei …' : 'sperre …'; }
  try {
    await call('switch_port', { id: p.id, port: p.p, on: on ? 1 : 0 });
    msg(on ? `„${p.label}“ ist wieder frei. Die Geräte verbinden sich in ein bis zwei Minuten.` : `„${p.label}“ ist gesperrt.`);
  } catch (err) { msg(err.message); }
  asking = null; busy = false;
  load();
}

async function routerAct(r, k, btn) {
  busy = true;
  if (btn) { btn.disabled = true; btn.textContent = 'einen Moment …'; }
  try {
    const res = await call('router_action', { id: r.id, act: k });
    msg(k === 'reconnect' ? 'Verbindet neu – das Internet ist gleich wieder da.' : res.msg);
  } catch (err) { msg(err.message); }
  asking = null; busy = false;
  load();
}

document.addEventListener('click', (e) => {
  const h = e.target.closest('[data-help]');
  if (h) { const el = $('#hint-' + h.dataset.help); el.hidden = !el.hidden; return; }
  const ra = e.target.closest('[data-rask]');
  if (ra) {
    const r = data.routers[Number(ra.dataset.r)], k = ra.dataset.rask;
    if (k === 'wlan_off' || k === 'reconnect') { asking = r.id + ':' + k; renderRouters(); return; }   // erst zeigen, was passiert
    routerAct(r, k, ra);
    return;
  }
  const rx = e.target.closest('[data-ract]');
  if (rx) { routerAct(data.routers[Number(rx.dataset.r)], rx.dataset.ract, rx); return; }
  const d = e.target.closest('[data-do]');
  if (d) {
    if (d.dataset.do === 'cancel') { asking = null; renderRouters(); renderPorts(); return; }
    const card = d.closest('.q-port');
    const p = data.ports[Number(card.dataset.i)];
    switchPort(p, false, d);
    return;
  }
  const b = e.target.closest('button[data-i]');
  if (b) {
    const p = data.ports[Number(b.dataset.i)];
    if (p.off) { switchPort(p, true, b); return; }
    asking = p.id + ':' + p.p;   // erst zeigen, wer betroffen ist
    renderPorts();
    return;
  }
  const l = e.target.closest('[data-led]');
  if (l) {
    l.disabled = true;
    call('leds_all', { on: Number(l.dataset.led) })
      .then((r) => msg(r.errors.length ? r.errors.join(' · ') : `Lichter ${l.dataset.led === '1' ? 'an' : 'aus'} (${plural(r.count, 'Switch', 'Switches')})`))
      .catch((err) => msg(err.message))
      .finally(() => { l.disabled = false; });
  }
});

$('#qTheme').addEventListener('click', () => {
  const t = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-bs-theme', t);
  try { localStorage.setItem('netmon-theme', t); } catch (e) {}
});

load();
setInterval(() => { if (!document.hidden && !asking) load(); }, 30000);
document.addEventListener('visibilitychange', () => { if (!document.hidden && !asking) load(); });
</script>
</body>
</html>
