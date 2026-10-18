<?php
// netmon – Schnellzugriff fürs Handy: angeheftete Ports sperren/freigeben, LEDs, Status auf einen Blick
// Ports anheften: Switch öffnen → Port-Liste → „☆ Schnellzugriff“
require __DIR__ . '/db.php';
$me = require_login_page();
$nm = ['role' => $me['role'], 'csrf' => csrf_token()];
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
  <title>netmon · Schnellzugriff</title>
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
    .q-head a { margin-left: auto; }
    .q-sec { font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; color: var(--nm-muted); margin: 1.4rem 0 .5rem; }
    .q-stats { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
    .q-stat { background: var(--nm-surface); border: 1px solid var(--bs-border-color); border-radius: .8rem; padding: .7rem .85rem; }
    .q-stat .v { font-size: 1.35rem; font-weight: 600; line-height: 1.2; }
    .q-stat .l { font-size: .75rem; color: var(--bs-secondary-color); }
    .q-port { background: var(--nm-surface); border: 1px solid var(--bs-border-color); border-radius: 1rem; padding: .9rem 1rem;
              display: flex; align-items: center; gap: .9rem; margin-bottom: .6rem; }
    .q-port.is-off { border-color: color-mix(in srgb, var(--nm-offline) 60%, var(--bs-border-color)); }
    .q-dot { width: .8rem; height: .8rem; border-radius: 50%; flex: 0 0 auto; background: var(--bs-border-color); }
    .q-dot.on { background: var(--nm-online); } .q-dot.off { background: var(--nm-offline); }
    .q-main { flex: 1; min-width: 0; }
    .q-title { font-weight: 600; font-size: 1.05rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .q-sub { font-size: .8rem; color: var(--bs-secondary-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .q-btn { min-width: 7.2rem; min-height: 3rem; border-radius: .8rem; font-weight: 600; border: 0; color: #fff; }
    .q-btn.lock { background: var(--nm-offline); } .q-btn.unlock { background: var(--nm-online); }
    .q-btn:disabled { opacity: .55; }
    .q-row { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
    .q-row .q-btn { width: 100%; background: var(--nm-btn); color: var(--bs-body-color); border: 1px solid var(--nm-btn-border); }
    .q-ev { font-size: .85rem; padding: .45rem 0; border-top: 1px solid var(--bs-border-color); display: flex; gap: .6rem; }
    .q-ev:first-child { border-top: 0; }
    .q-ev .t { color: var(--bs-secondary-color); flex: 0 0 auto; font-variant-numeric: tabular-nums; }
    .q-empty { background: var(--nm-surface); border: 1px dashed var(--bs-border-color); border-radius: 1rem; padding: 1rem; font-size: .9rem; color: var(--bs-secondary-color); }
    .q-msg { position: fixed; left: 16px; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom)); max-width: 528px; margin: 0 auto;
             background: var(--nm-surface); border: 1px solid var(--bs-border-color); border-radius: .8rem; padding: .7rem 1rem; display: none; }
    .q-msg.show { display: block; }
  </style>
</head>
<body>
<div class="q-wrap">
  <div class="q-head">
    <span class="brand-dot"></span><h1>Schnellzugriff</h1>
    <a class="btn btn-ghost btn-sm" href="./">Übersicht</a>
  </div>

  <div class="q-stats" id="qStats"></div>

  <div class="q-sec">Ports</div>
  <div id="qPorts"></div>

  <div id="qLedBox">
    <div class="q-sec">Switch-LEDs</div>
    <div class="q-row">
      <button class="q-btn" type="button" data-led="0">LEDs aus</button>
      <button class="q-btn" type="button" data-led="1">LEDs an</button>
    </div>
    <div class="q-sub mt-2" id="qLedInfo"></div>
  </div>

  <div class="q-sec">Letzte Ereignisse</div>
  <div id="qEvents"></div>
</div>
<div class="q-msg" id="qMsg"></div>

<script>
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const $ = (s) => document.querySelector(s);
let data = null;

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
  clearTimeout(msg.t); msg.t = setTimeout(() => m.classList.remove('show'), 3500);
}
const EV = { neu: 'neu im Netz', online: 'wieder online', offline: 'offline', ip_wechsel: 'neue IP' };
const hhmm = (s) => (s ? s.slice(11, 16) : '');
const day = (s) => (s ? s.slice(8, 10) + '.' + s.slice(5, 7) + '.' : '');

function render() {
  const st = data.status, sp = st.speed;
  $('#qStats').innerHTML = `
    <div class="q-stat"><div class="v">${st.online} / ${st.total}</div><div class="l">Geräte online${st.new24h ? ` · ${st.new24h} neu` : ''}</div></div>
    <div class="q-stat"><div class="v">${sp && !sp.error ? `↓ ${Math.round(sp.download_mbps)}` : '–'}</div>
      <div class="l">${sp && !sp.error ? `↑ ${Math.round(sp.upload_mbps)} Mbit/s · ${Math.round(sp.ping_ms)} ms` : 'kein Speedtest'}</div></div>`;

  $('#qPorts').innerHTML = data.ports.length ? data.ports.map((p, i) => {
    const name = p.who.length ? p.who.join(', ') : p.desc || `Port ${p.p}`;
    const state = p.off ? 'gesperrt' : p.link ? `frei · ${p.speed || 'Link'}` : 'frei · kein Gerät aktiv';
    const btn = data.admin ? `<button class="q-btn ${p.off ? 'unlock' : 'lock'}" type="button" data-i="${i}">${p.off ? 'Freigeben' : 'Sperren'}</button>` : '';
    return `<div class="q-port${p.off ? ' is-off' : ''}"><span class="q-dot ${p.off ? 'off' : p.link ? 'on' : ''}"></span>
      <div class="q-main"><div class="q-title">${esc(name)}</div>
        <div class="q-sub">${esc(state)}</div><div class="q-sub">${esc(p.switch)} · Port ${p.p}</div></div>${btn}</div>`;
  }).join('') : `<div class="q-empty">Noch keine Ports angeheftet. In der Übersicht den Switch öffnen und in der Port-Liste
      auf <b>☆ Schnellzugriff</b> tippen – dann erscheint der Port hier mit einem großen Sperren-Knopf.</div>`;

  $('#qLedBox').hidden = !data.admin || !data.leds.count;
  $('#qLedInfo').textContent = `${data.leds.count} Switch${data.leds.count === 1 ? '' : 'es'}` +
    (data.leds.off ? ` · automatisch aus ${data.leds.off}, an ${data.leds.on || '–'}` : '');

  $('#qEvents').innerHTML = data.events.map((e) => `<div class="q-ev"><span class="t">${day(e.created_at)} ${hhmm(e.created_at)}</span>
      <span>${esc(e.label || '')}${e.info ? ' – ' + esc(e.info) : ' – ' + esc(EV[e.event] || e.event)}</span></div>`).join('') || '<div class="q-sub">keine</div>';
}

async function load() {
  try { data = await call('quick'); render(); } catch (e) { msg(e.message); }
}

document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-i]');
  if (b) {
    const p = data.ports[Number(b.dataset.i)];
    const name = p.who.join(', ') || p.desc || `Port ${p.p}`;
    if (!p.off && !confirm(`„${name}“ sperren?\nAlles an diesem Port ist dann vom Netz getrennt.`)) return;
    b.disabled = true; b.textContent = p.off ? 'gebe frei …' : 'sperre …';
    try {
      await call('switch_port', { id: p.id, port: p.p, on: p.off ? 1 : 0 });
      msg(`${name}: ${p.off ? 'freigegeben' : 'gesperrt'}`);
    } catch (err) { msg(err.message); }
    load();
    return;
  }
  const l = e.target.closest('[data-led]');
  if (l) {
    l.disabled = true;
    try {
      const r = await call('leds_all', { on: Number(l.dataset.led) });
      msg(r.errors.length ? r.errors.join(' · ') : `LEDs ${l.dataset.led === '1' ? 'an' : 'aus'} (${r.count})`);
    } catch (err) { msg(err.message); }
    l.disabled = false;
  }
});

load();
setInterval(() => { if (!document.hidden) load(); }, 30000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
</script>
</body>
</html>
