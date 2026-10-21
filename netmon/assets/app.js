// netmon – Weboberfläche
'use strict';

const COLORS = {
  online: '#1D9E75', offline: '#E24B4A', infra: '#7F77DD', zone: '#888780',
  down: '#1D9E75', up: '#7F77DD', ping: '#BA7517',
};
const MOBILE = () => window.matchMedia('(max-width: 767.98px)').matches;
const CHEVRON = '<span class="chev"><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></span>';

// Symbole pro Gerätetyp (24er-Raster, Linienzeichnung)
const ICONS = {
  router: '<path d="M3 13h18v6H3z"/><path d="M7 13 5 6M17 13l2-7"/><path d="M7 16h.01M11 16h.01"/>',
  switch: '<path d="M2 8h20v8H2z"/><path d="M6 12h.01M9.3 12h.01M12.6 12h.01M15.9 12h.01M19 12h.01"/>',
  ap: '<path d="M12 19h.01"/><path class="arc a1" d="M8.6 15.6a4.8 4.8 0 0 1 6.8 0"/>'
    + '<path class="arc a2" d="M5.6 12.6a9 9 0 0 1 12.8 0"/><path class="arc a3" d="M2.6 9.6a13.3 13.3 0 0 1 18.8 0"/>',
  server: '<path d="M4 3h16v7H4zM4 14h16v7H4z"/><path d="M8 6.5h.01M8 17.5h.01"/>',
  pc: '<path d="M3 4h18v12H3z"/><path d="M8 21h8M12 16v5"/>',
  pi: '<path d="M7 7h10v10H7z"/><path d="M10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4"/>',
  iot: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
  kamera: '<path d="M3 7h12v10H3z"/><path d="m15 10.5 6-3.5v10l-6-3.5"/>',
  tv: '<path d="M3 5h18v12H3z"/><path d="M8 21h8"/>',
  handy: '<path d="M7 2h10v20H7z"/><path d="M11 18h2"/>',
  nas: '<path d="M5 3h14v18H5z"/><path d="M8 7h8M8 11h8M8 15h8"/><path d="M15.5 18.5h.01"/>',
  laptop: '<path d="M5 5h14v10H5z"/><path d="M2 19h20"/>',
  tablet: '<path d="M5 3h14v18H5z"/><path d="M11 18h2"/>',
  drucker: '<path d="M7 9V3h10v6"/><path d="M5 9h14v8H5z"/><path d="M7 14h10v7H7z"/>',
  konsole: '<path d="M7 7h10a5 5 0 0 1 0 10c-1.6 0-2.3-.8-3-2h-4c-.7 1.2-1.4 2-3 2A5 5 0 0 1 7 7z"/><path d="M7.5 10v4M5.5 12h4M16 11h.01M18 13h.01"/>',
  lautsprecher: '<path d="M6 2h12v20H6z"/><circle cx="12" cy="14" r="3.5"/><path d="M12 6h.01"/>',
  funk: '<path d="M12 11v10M8 21h8"/><circle cx="12" cy="9" r="1.6"/>'
    + '<path class="arc a1" d="M8.6 5.6a4.8 4.8 0 0 0 0 6.8M15.4 5.6a4.8 4.8 0 0 1 0 6.8"/>'
    + '<path class="arc a2" d="M5.8 2.8a8.8 8.8 0 0 0 0 12.4M18.2 2.8a8.8 8.8 0 0 1 0 12.4"/>',
};
const TYPE_LABELS = {
  router: 'Router', switch: 'Switch', ap: 'Access Point', server: 'Server', nas: 'NAS',
  pc: 'PC', laptop: 'Laptop', pi: 'Raspberry Pi', iot: 'IoT / Smart Home', kamera: 'Kamera',
  tv: 'TV', handy: 'Handy', tablet: 'Tablet', drucker: 'Drucker', konsole: 'Spielkonsole',
  lautsprecher: 'Lautsprecher', funk: 'Funk / Hotspot', sonstiges: 'Sonstiges',
};
const typeLabel = (t) => TYPE_LABELS[t] || t;
const iconFor = (d) => (d.kind === 'zone' ? (d.zoneType === 'wlan' ? ICONS.ap : null) : ICONS[d.type]) || null;

// Switch-Ports (Netgear Plus): Farbe nach Link und Geschwindigkeit
// Port-Tempo in Mbit/s ('100M', '1000M', '2.5G', '10G' …) und Farbklasse: 10 rot, 100 gelb, 1000 grün, mehr blau
const speedMbps = (s) => {
  const m = String(s || '').match(/(\d+(?:[.,]\d+)?)\s*([GM])?/i);
  return m ? parseFloat(m[1].replace(',', '.')) * (/g/i.test(m[2] || '') ? 1000 : 1) : null;
};
const speedClass = (s) => {
  const v = speedMbps(s);
  return v == null ? '' : v > 1000 ? 'sp-x' : v >= 1000 ? 'sp-1000' : v >= 100 ? 'sp-100' : 'sp-10';
};
const portClass = (p) => (p.off ? 'off locked' : !p.link ? 'off' : (parseInt(p.speed, 10) >= 1000 ? 'on ' : 'slow ') + speedClass(p.speed));
const portTitle = (p) => `Port ${p.p}: ${p.off ? 'gesperrt' : p.link ? 'Link ' + (p.speed || '') : 'kein Link'}`;
const portStrip = (ports, uplink) => (ports ? `<span class="port-strip">${ports.map((p) =>
  `<i class="${portClass(p)}${p.p === uplink ? ' up' : ''}" title="${esc(portTitle(p) + (p.p === uplink ? ' · Uplink' : ''))}"></i>`).join('')}</span>` : '');
// WLAN: Signalstärke in Worten und als Balken, Raten in kbit/s
const sigLevel = (s) => (s >= -55 ? 4 : s >= -67 ? 3 : s >= -75 ? 2 : 1);
const sigText = (s) => ['', 'schwach', 'mäßig', 'gut', 'sehr gut'][sigLevel(s)];
const sigBars = (s) => (s === null || s === undefined ? '' : `<span class="sig sig-${sigLevel(s)}" title="${s} dBm · ${sigText(s)}"><i></i><i></i><i></i><i></i></span>`);
const fmtKbps = (k) => (k ? (k >= 1000 ? Math.round(k / 1000) + ' Mbit/s' : k + ' kbit/s') : '–');
const fmtDur = (s) => (!s ? '' : s < 3600 ? Math.round(s / 60) + ' min' : s < 172800 ? Math.round(s / 3600) + ' h' : Math.round(s / 86400) + ' Tage');
// Router-Modus (z. B. Archer C80) liefert kein Signal – dann nur „WLAN“ und das Band
const dbm = (v) => (v == null ? 'WLAN' : `${v} dBm`);
const wifiShort = (w) => (w ? `${w.band ? w.band + ' GHz · ' : ''}${dbm(w.signal)}` : '');

function fmtBps(b) {
  if (b === null || b === undefined) return '–';
  if (b >= 1e6) return (b / 1e6).toLocaleString('de-DE', { maximumFractionDigits: b >= 1e8 ? 0 : 1 }) + ' Mbit/s';
  if (b >= 1e3) return Math.round(b / 1e3) + ' kbit/s';
  return b > 0 ? b + ' bit/s' : '0';
}

// ---------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------
const $ = (s) => document.querySelector(s);
const store = {
  get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
};

async function api(action, params = {}) {
  const r = await fetch('api.php?' + new URLSearchParams({ action, ...params }));
  if (r.status === 401) { location.href = 'login.php'; throw new Error('Bitte anmelden'); }
  const j = await r.json();
  if (!r.ok) throw new Error(j.error || r.statusText);
  return j;
}

async function post(action, data) {
  const r = await fetch('api.php?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.NM.csrf },
    body: JSON.stringify(data),
  });
  if (r.status === 401) { location.href = 'login.php'; throw new Error('Bitte anmelden'); }
  const j = await r.json();
  if (!r.ok) throw new Error(j.error || r.statusText);
  return j;
}
const IS_ADMIN = window.NM?.role === 'admin';

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// "2026-10-09 14:17:12" -> "09.10. 14:17"
function fmtTime(s, withYear = false) {
  if (!s) return '–';
  const [d, t = ''] = String(s).split(' ');
  const [y, m, day] = d.split('-');
  return `${day}.${m}.${withYear ? y : ''}${t ? ' ' + t.slice(0, 5) : ''}`;
}
const hhmm = (s) => (s ? String(s).slice(11, 16) : '');

// "Heute", "Gestern" oder "Mo, 06.10."
function dayLabel(s) {
  const d = new Date(String(s).slice(0, 10) + 'T00:00:00');
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const diff = Math.round((today - d) / 86400000);
  if (diff === 0) return 'Heute';
  if (diff === 1) return 'Gestern';
  return d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' });
}

// Kurzform für „seit wann“
function since(s) {
  if (!s) return '';
  const mins = Math.round((Date.now() - new Date(String(s).replace(' ', 'T'))) / 60000);
  if (mins < 60) return `seit ${Math.max(mins, 1)} min`;
  if (mins < 1440) return `seit ${Math.round(mins / 60)} h`;
  return `seit ${fmtTime(s)}`;
}

const fmtNum = (v, digits = 1) => (v === null || v === undefined ? '–'
  : Number(v).toLocaleString('de-DE', { maximumFractionDigits: digits, minimumFractionDigits: digits }));
const lastOctet = (ip) => (ip ? '.' + ip.split('.')[3] : '');
const ipNum = (ip) => (ip ? ip.split('.').reduce((a, b) => a * 256 + Number(b), 0) : 0);
const cssVar = (name) => getComputedStyle(document.body).getPropertyValue(name).trim();

let toast;
function notify(text, ok = true) {
  const el = $('#toast');
  el.classList.toggle('text-bg-success', ok);
  el.classList.toggle('text-bg-danger', !ok);
  $('#toastText').textContent = text;
  toast ??= new bootstrap.Toast(el, { delay: 2500 });
  toast.show();
}

// ---------------------------------------------------------------
// Navigation: Seitenleiste (PC) und Leiste unten (Handy/Tablet)
// ---------------------------------------------------------------
const TAB_TITLES = { '#tabTree': 'Netzwerk', '#tabSpeed': 'Speedtest', '#tabEvents': 'Ereignisse', '#tabDevices': 'Geräte', '#tabSettings': 'Einstellungen' };

function showTab(target) {
  if (!document.querySelector(target)) return;
  document.querySelectorAll('.tab-content > .tab-pane').forEach((p) => {
    const on = '#' + p.id === target;
    p.classList.toggle('active', on);
    p.classList.toggle('show', on);
  });
  document.querySelectorAll('#sideNav [data-tab], #bottomNav [data-tab]').forEach((x) => x.classList.toggle('active', x.dataset.tab === target));
  $('#pageTitle').textContent = TAB_TITLES[target] || 'netmon';
  if (target === '#tabSpeed' && speedData) drawSpeed(speedData);
  if (target === '#tabTree') drawNetwork();
  if (target === '#tabSettings') loadSettings().catch((err) => notify(err.message, false));
  window.scrollTo({ top: 0 });
}

document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-tab]');
  if (b) showTab(b.dataset.tab);
  const g = e.target.closest('[data-goto]');
  if (g) showTab(g.dataset.goto);
});

// Höhe der Kopfzeile für klebende Filterleisten
function syncHeadHeight() {
  document.documentElement.style.setProperty('--head-h', ($('#pageHead')?.offsetHeight || 64) + 'px');
}
window.addEventListener('resize', syncHeadHeight);
syncHeadHeight();

// ---------------------------------------------------------------
// Theme
// ---------------------------------------------------------------
function applyTheme() {
  const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  document.querySelector('meta[name="theme-color"]').setAttribute('content', dark ? '#14151b' : '#f5f6fa');
}
document.querySelectorAll('.theme-btn').forEach((btn) => btn.addEventListener('click', () => {
  const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-bs-theme', next);
  store.set('netmon-theme', next);
  applyTheme();
  if (speedData) drawSpeed(speedData);
  if (treeData) drawNetwork();
}));
applyTheme();

// ---------------------------------------------------------------
// Status und Kennzahlen
// ---------------------------------------------------------------
async function loadStatus() {
  const s = await api('status');
  $('#sOnline').textContent = `${s.online} / ${s.total}`;
  if ($('#navDevCount')) $('#navDevCount').textContent = `${s.online}/${s.total}`;
  $('#sOnlineSub').textContent = s.new24h ? `${s.new24h} neu in 24 h` : 'nichts Neues in 24 h';
  $('#lastScan').textContent = s.scan
    ? `Scan ${fmtTime(s.scan.started_at)} · ${s.scan.hosts_up} Geräte · ${fmtNum(s.scan.duration_ms / 1000)} s`
    : 'Noch kein Scan';

  // Ohne Speedtest: Kacheln für Offline, Neu und letzten Scan
  if ($('#sOffline')) {
    const off = s.total - s.online;
    $('#sOffline').textContent = off;
    $('#sOfflineSub').textContent = off ? 'Geräte nicht erreichbar' : 'alles erreichbar';
    $('#sNew').textContent = s.new24h;
    $('#sLast').textContent = s.scan ? hhmm(s.scan.started_at) : '–';
    $('#sLastSub').textContent = s.scan ? `${s.scan.hosts_up} Geräte · ${fmtNum(s.scan.duration_ms / 1000)} s` : 'noch kein Scan';
  }
  if (!$('#sDown')) return;

  const sp = s.speed;
  if (sp && !sp.error) {
    $('#sDown').textContent = fmtNum(sp.download_mbps) + ' Mbit/s';
    $('#sUp').textContent = fmtNum(sp.upload_mbps) + ' Mbit/s';
    $('#sPing').textContent = fmtNum(sp.ping_ms) + ' ms';
    $('#sDownSub').textContent = $('#sUpSub').textContent = $('#sPingSub').textContent = fmtTime(sp.created_at);
  } else if (sp) {
    $('#sDown').textContent = $('#sUp').textContent = $('#sPing').textContent = 'Fehler';
    $('#sDownSub').textContent = $('#sUpSub').textContent = $('#sPingSub').textContent = 'Test ' + fmtTime(sp.created_at);
  }
}

// ---------------------------------------------------------------
// Netzwerk: gemeinsame Hierarchie für Liste und Baum
// ---------------------------------------------------------------
let treeData = null;
let collapsed = null;   // zugeklappte Knoten, bleibt beim Aktualisieren erhalten
let netView = store.get('netmon-view') || (MOBILE() ? 'list' : 'tree');

const MAX_OPEN = 2;      // Handy/Tablet: höchstens so viele Zweige gleichzeitig offen
const COMPACT = () => window.matchMedia('(max-width: 1023.98px)').matches;   // Handy und Tablet
let compactMode = null;  // gemerkter Modus, um einen Wechsel (Fenster drehen/vergrößern) zu erkennen
let openOrder = [];      // Reihenfolge, in der Zweige geöffnet wurden
let knownBranches = new Set();

async function loadTree() {
  treeData = await api('tree');
  markVirtual(treeData.nodes);
  applyCompactMode();
  drawNetwork();
}

// Geräte ohne IP (Pseudo-Switch, unmanaged, geplant) haben keinen eigenen Status:
// sie gelten als „durchlässig“ – aktiv, sobald etwas dahinter online ist.
function markVirtual(nodes) {
  const kids = new Map();
  for (const n of nodes) {
    if (!kids.has(n.parent)) kids.set(n.parent, []);
    kids.get(n.parent).push(n);
  }
  const anyOn = (id, seen = new Set()) => {
    if (seen.has(id)) return false;
    seen.add(id);
    return (kids.get(id) || []).some((c) => (c.virtual ? anyOn(c.id, seen) : c.kind !== 'zone' ? c.online : anyOn(c.id, seen)));
  };
  for (const n of nodes) if (n.virtual) n.online = anyOn(n.id);
}

// Überall starten die Zweige zugeklappt. Handy/Tablet: Akkordeon mit MAX_OPEN. PC: beliebig viele offen.
function applyCompactMode() {
  const compact = COMPACT();
  if (compact !== compactMode) {
    compactMode = compact;
    collapsed = new Set();
    openOrder = [];
    knownBranches = new Set();
  }
  for (const n of treeData.nodes) {
    if (n.parent && !knownBranches.has(n.parent)) {
      knownBranches.add(n.parent);
      if (n.parent !== treeData.root) collapsed.add(n.parent);   // alle Zweige starten zugeklappt
    }
  }
}

const parentOf = (id) => treeData.nodes.find((n) => n.id === id)?.parent ?? null;
function ancestors(id) {
  const out = new Set();
  for (let p = parentOf(id); p; p = parentOf(p)) out.add(p);
  return out;
}
function closeBranch(id) {
  collapsed.add(id);
  // offene Unterzweige mit schließen
  for (const o of [...openOrder]) if (o === id || ancestors(o).has(id)) {
    collapsed.add(o);
    openOrder = openOrder.filter((x) => x !== o);
  }
}

// Auf- und Zuklappen wie ein Akkordeon: Beim Öffnen eines dritten Zweigs schließt der älteste.
// Übergeordnete Zweige des geöffneten Knotens zählen nicht mit.
function toggleBranch(id) {
  if (!collapsed.has(id)) { closeBranch(id); drawNetwork(); return; }
  collapsed.delete(id);
  if (!compactMode) { drawNetwork(); return; }   // am PC ohne Begrenzung
  openOrder = openOrder.filter((x) => x !== id).concat(id);
  const keep = ancestors(id);
  for (;;) {
    const counted = openOrder.filter((o) => !keep.has(o));
    if (counted.length <= MAX_OPEN) break;
    // ältesten Zweig ohne offene Unterzweige schließen
    const victim = counted.find((o) => o !== id && !openOrder.some((x) => x !== o && ancestors(x).has(o)));
    if (!victim) break;
    closeBranch(victim);
  }
  drawNetwork();
}

// Lange offline: im Baum, in der Liste und in den Räumen ausblenden (Einstellung „Offline-Geräte ausblenden“).
// Geräte, an denen etwas hängt, bleiben stehen. „N ausgeblendet“ blendet sie bis zum Neuladen wieder ein.
let showStale = false;
function staleIds() {
  const parents = new Set(treeData.nodes.map((n) => n.parent));
  return new Set(treeData.nodes.filter((n) => n.stale && !parents.has(n.id)).map((n) => n.id));
}
function updateStaleBtn(cnt) {
  const b = $('#staleBtn');
  if (!b) return;
  b.classList.toggle('d-none', cnt === 0);
  b.textContent = showStale ? `${cnt} alte ausblenden` : `${cnt} ausgeblendet`;
  b.title = showStale ? '' : `Länger als ${treeData.hideDays} Tag${treeData.hideDays === 1 ? '' : 'e'} offline – klicken zum Anzeigen`;
}
function hiddenFilter() {
  const onlyOnline = $('#onlyOnline').checked;
  const stale = staleIds();
  updateStaleBtn(stale.size);
  return (n) => !(onlyOnline && n.kind === 'device' && !n.online && !n.virtual) && (showStale || !stale.has(n.id));
}

function buildHierarchy() {
  const nodes = treeData.nodes.filter(hiddenFilter());
  // Leere Bereiche ausblenden (alle Geräte fest an Switches gehängt); sie erscheinen wieder, sobald etwas hineinfällt
  const used = new Set(nodes.map((n) => n.parent));
  const shown = nodes.filter((n) => n.kind !== 'zone' || used.has(n.id));
  const root = d3.stratify().id((d) => d.id).parentId((d) => d.parent)(shown);

  root.eachAfter((n) => {
    const isDev = n.data.kind !== 'zone' && n.depth > 0 && !n.data.virtual;
    n.cnt = (n.children || []).reduce((a, c) => a + c.cnt, 0) + (isDev ? 1 : 0);
    n.on = (n.children || []).reduce((a, c) => a + c.on, 0) + (isDev && n.data.online ? 1 : 0);
  });
  const rank = { infra: 0, zone: 1, device: 2 };
  root.sort((a, b) => rank[a.data.kind] - rank[b.data.kind] || ipNum(a.data.ip) - ipNum(b.data.ip));
  return root;
}

function drawNetwork() {
  if (!treeData) return;
  document.querySelectorAll('#viewBtns button').forEach((b) => b.classList.toggle('active', b.dataset.view === netView));
  let root;
  try {
    root = buildHierarchy();
  } catch (e) {
    $('#netList').innerHTML = `<div class="alert alert-warning">Netz konnte nicht aufgebaut werden: ${esc(e.message)}</div>`;
    $('#tree').replaceChildren();
    return;
  }
  $('#legendZone').classList.toggle('d-none', netView === 'rooms');
  if (netView === 'rooms') {
    $('#tree').replaceChildren();
    $('#netList').replaceChildren();
    drawRooms();
  } else if (netView === 'list') {
    $('#tree').replaceChildren();
    $('#rooms').replaceChildren();
    drawList(root);
  } else {
    $('#netList').replaceChildren();
    $('#rooms').replaceChildren();
    drawTree(root);
  }
}

// ---------------------------------------------------------------
// Räume: eine Karte pro Raum/Ort, Linien = Kabel zwischen den Räumen
// ---------------------------------------------------------------
let roomsNoneOpen = store.get('netmon-rooms-none') === '1';

function upstreamDevice(n, byId) {
  let p = byId.get(n.parent);
  while (p && p.kind === 'zone') p = byId.get(p.parent);   // über Bereiche hinweg zum Switch/Router
  return p && p.dbId ? p : null;
}

function drawRooms() {
  if (roomDragging) return;   // nicht mitten im Ziehen neu aufbauen
  const byId = new Map(treeData.nodes.map((n) => [n.id, n]));
  const show = hiddenFilter();
  const all = treeData.nodes.filter((n) => n.kind !== 'zone' && n.dbId);
  const devs = all.filter(show);

  // nach Raum gruppieren
  const rooms = new Map();
  for (const d of devs) {
    const k = (d.location || '').trim();
    if (!rooms.has(k)) rooms.set(k, []);
    rooms.get(k).push(d);
  }
  const byName = (a, b) => a.localeCompare(b, 'de', { numeric: true });
  const names = [...rooms.keys()].filter(Boolean).sort(byName);

  // Kabel zwischen Räumen: Gerät in Raum B hängt an Switch/Router in Raum A  ->  A speist B
  const links = new Map();
  for (const d of all) {
    const up = upstreamDevice(d, byId);
    const a = (up?.location || '').trim(), b = (d.location || '').trim();
    if (!up || !a || !b || a === b || !rooms.has(a) || !rooms.has(b)) continue;
    // Kabel: Gerät hängt an einem Switch (auch ohne IP) oder ist selbst Switch/AP.
    // Sonst (Handy, Steckdose … direkt an Router/AP) ist es WLAN – keine echte Leitung zwischen den Räumen.
    const cable = up.type === 'switch' || up.virtual || d.kind === 'infra';
    const key = a + '\u0000' + b;
    const l = links.get(key) || { from: a, to: b, n: 0, live: false, cable: false, items: [] };
    l.n++;
    // Weiß der Switch, dass der Port keinen Link hat, ist dieses Kabel tot – auch wenn das Gerät per WLAN noch erreichbar ist
    const port = up.ports && /^\d+$/.test(String(d.port || '')) ? up.ports.find((x) => x.p === Number(d.port)) : null;
    l.live = l.live || (d.online && up.online && (!port || port.link));
    l.cable = l.cable || cable;
    l.items.push({ up, d, port });
    links.set(key, l);
  }

  // Räume als Kette ab dem Raum des Routers anordnen (Spalten = Kabel-Ebenen)
  const out = new Map(names.map((n) => [n, []]));
  const hasIn = new Set();
  for (const l of links.values()) { out.get(l.from).push(l.to); hasIn.add(l.to); }
  const router = all.find((d) => d.type === 'router' && (d.location || '').trim());
  let starts = router && rooms.has(router.location.trim()) ? [router.location.trim()] : [];
  starts = starts.concat(names.filter((n) => !hasIn.has(n) && out.get(n).length && !starts.includes(n)));
  const depth = new Map(), parentRoom = new Map();
  const queue = [...starts];
  starts.forEach((n) => depth.set(n, 0));
  while (queue.length) {
    const r = queue.shift();
    for (const c of out.get(r).sort(byName)) {
      if (depth.has(c)) continue;
      depth.set(c, depth.get(r) + 1);
      parentRoom.set(c, r);
      queue.push(c);
    }
  }
  // Spalten bilden, Kinder in der Reihenfolge ihrer Eltern (weniger Kreuzungen)
  const cols = [];
  for (const [n, dpt] of depth) (cols[dpt] ||= []).push(n);
  for (let i = 1; i < cols.length; i++) {
    const order = new Map(cols[i - 1].map((n, k) => [n, k]));
    cols[i].sort((a, b) => order.get(parentRoom.get(a)) - order.get(parentRoom.get(b)) || byName(a, b));
  }
  const chained = cols.length > 1 ? new Set(depth.keys()) : new Set();
  const loose = names.filter((n) => !chained.has(n));

  const rank = (d) => (d.type === 'router' ? 0 : d.kind === 'infra' ? 1 : 2);
  const sortDevs = (list) => list.sort((a, b) => rank(a) - rank(b) || ipNum(a.ip) - ipNum(b.ip));
  const devRow = (d) => {
    const color = d.virtual ? COLORS.zone : !d.online ? COLORS.offline : d.kind === 'infra' ? COLORS.infra : COLORS.online;
    const icon = ICONS[d.type];
    const live = icon && d.online && (d.type === 'ap' || d.type === 'funk') ? ' live' : '';
    const meta = d.virtual || d.online
      ? [d.ip || 'ohne IP', d.port && 'Port ' + d.port, d.wifi && wifiShort(d.wifi), d.kind === 'infra' && d.type && typeLabel(d.type)].filter(Boolean).join(' · ')
      : `${d.ip} · offline ${since(d.lastSeen)}`;
    return `<button type="button" class="room-dev${d.kind === 'infra' ? ' infra' : ''}${d.online || d.virtual ? '' : ' off'}${d.virtual ? ' virtual' : ''}" data-dev="${d.dbId}" title="${esc(d.label)}">
      <span class="room-ico${live}" style="background:${color}">${icon ? `<svg viewBox="0 0 24 24">${icon}</svg>` : ''}</span>
      <span class="main"><span class="t">${esc(d.label)}</span><span class="m">${esc(meta)}</span></span>
      ${portStrip(d.ports, d.uplink)}
    </button>`;
  };
  const card = (name, list, none = false) => {
    const real = list.filter((d) => !d.virtual);
    const on = real.filter((d) => d.online).length;
    const off = real.length - on;
    const open = !none || roomsNoneOpen;
    const via = parentRoom.get(name);
    return `<section class="room-card${off && !none ? ' has-off' : ''}${none ? ' room-none' : ''}${none && open ? ' open' : ''}" data-room="${esc(name)}">
      <header class="room-head"${none ? ' data-toggle-none' : ''}>
        <span class="room-title"><span class="room-name">${none ? 'Ohne Ort' : esc(name)}</span>${via ? `<span class="room-via">über ${esc(via)}</span>` : ''}</span>
        <span class="room-count${off && !none ? ' bad' : ''}">${none ? list.length + ' Geräte' : `${on}/${real.length} online`}</span>
        ${none ? CHEVRON : ''}
      </header>
      ${open ? `<div class="room-body">${sortDevs(list).map(devRow).join('')}</div>` : ''}
    </section>`;
  };

  // Grundriss: frei angeordnete Karten (gespeichert) oder Bearbeiten; auf dem Handy immer untereinander
  const layout = roomEdit ? roomDraft : (treeData.roomLayout || {});
  const plan = !MOBILE() && (roomEdit || Object.keys(layout).length > 0);

  let html = '';
  const hasWlan = [...links.values()].some((l) => !l.cable);
  const wlanToggle = hasWlan
    ? `<label class="form-check form-switch only-online mb-0 small"><input class="form-check-input" type="checkbox" id="roomsWlan"${roomsWlan ? ' checked' : ''}>
         <span class="form-check-label">WLAN-Verbindungen</span></label>` : '';
  if (!MOBILE() && names.length) {
    html += roomEdit
      ? `<div class="rooms-toolbar editing"><span class="me-auto small">Karten am Kopf ziehen und ablegen.</span>
           <button type="button" class="btn btn-ghost btn-sm" data-room-act="auto">Automatisch anordnen</button>
           <button type="button" class="btn btn-ghost btn-sm" data-room-act="cancel">Abbrechen</button>
           <button type="button" class="btn btn-accent btn-sm" data-room-act="save">Fertig</button></div>`
      : `<div class="rooms-toolbar">${wlanToggle}<span class="me-auto"></span>
           ${IS_ADMIN ? `<button type="button" class="btn btn-ghost btn-sm" data-room-act="edit">${ICON_MOVE} Anordnen</button>` : ''}</div>`;
  }
  if (!names.length) {
    html += `<div class="room-hint">Noch keinem Gerät ist ein Raum zugeordnet. Öffne ein Gerät und trage unter
      <b>Raum / Ort</b> z.&nbsp;B. „Wohnzimmer“ ein – dann erscheint hier eine Karte pro Raum.</div>`;
  }
  const none = rooms.has('') ? card('', rooms.get(''), true) : '';
  if (plan) {
    html += `<div class="rooms-flow-scroll"><div class="rooms-plan${roomEdit ? ' editing' : ''}"><svg class="room-links" aria-hidden="true"></svg>`
      + names.map((n) => card(n, rooms.get(n))).join('') + '</div></div>';
    if (none) html += `<div class="rooms-grid">${none}</div>`;
  } else {
    if (chained.size) {
      html += `<div class="rooms-flow-scroll"><div class="rooms-flow"><svg class="room-links" aria-hidden="true"></svg>`
        + cols.map((c) => `<div class="rooms-col">${c.map((n) => card(n, rooms.get(n))).join('')}</div>`).join('')
        + '</div></div>';
    }
    const rest = loose.map((n) => card(n, rooms.get(n))).join('') + none;
    if (rest) {
      if (chained.size) html += `<div class="rooms-sub">${loose.length ? 'Weitere Räume' : 'Noch ohne Raum'}</div>`;
      html += `<div class="rooms-grid">${rest}</div>`;
    }
  }
  $('#rooms').innerHTML = html;

  roomLinks = [...links.values()].filter((l) => (l.cable || roomsWlan || roomEdit)
    && (plan || (chained.has(l.from) && chained.has(l.to))));
  if (plan) placeRoomCards(layout, names);
  requestAnimationFrame(drawRoomLinks);
}

const ICON_MOVE = '<svg class="ico-inline" viewBox="0 0 24 24"><path d="M12 3v18M3 12h18M12 3l-3 3M12 3l3 3M12 21l-3-3M12 21l3-3M3 12l3-3M3 12l3 3M21 12l-3-3M21 12l-3 3"/></svg>';
const CARD_W = 290;
let roomEdit = false;       // Bearbeiten-Modus (nur Admin, nicht auf dem Handy)
let roomsWlan = store.get('netmon-rooms-wlan') === '1';   // WLAN-Verbindungen als feine Linien zeigen
let roomDraft = {};         // Positionen während des Bearbeitens
let roomDragging = false;

// Karten an ihre Plätze setzen; Räume ohne gespeicherte Position kommen unten in eine Reihe
function placeRoomCards(layout, names) {
  const planEl = $('#rooms .rooms-plan');
  let maxBottom = 0;
  const missing = [];
  for (const n of names) {
    const el = [...planEl.querySelectorAll('.room-card')].find((c) => c.dataset.room === n);
    const p = layout[n];
    if (p) {
      el.style.left = p[0] + 'px';
      el.style.top = p[1] + 'px';
      maxBottom = Math.max(maxBottom, p[1] + el.offsetHeight);
    } else {
      missing.push(el);
    }
  }
  const perRow = Math.max(1, Math.floor((planEl.clientWidth + 40) / (CARD_W + 40)));
  let rowTop = maxBottom ? maxBottom + 40 : 0, rowH = 0;
  missing.forEach((el, i) => {
    if (i && i % perRow === 0) { rowTop += rowH + 40; rowH = 0; }
    const x = (i % perRow) * (CARD_W + 40);
    el.style.left = x + 'px';
    el.style.top = rowTop + 'px';
    rowH = Math.max(rowH, el.offsetHeight);
    if (roomEdit) roomDraft[el.dataset.room] = [x, rowTop];
  });
  fitPlan();
}

// Fläche so groß wie die Karten (plus Rand), damit nichts abgeschnitten wird
function fitPlan() {
  const planEl = $('#rooms .rooms-plan');
  if (!planEl) return;
  let w = 0, h = 0;
  for (const c of planEl.querySelectorAll('.room-card')) {
    w = Math.max(w, c.offsetLeft + c.offsetWidth);
    h = Math.max(h, c.offsetTop + c.offsetHeight);
  }
  planEl.style.width = (w + (roomEdit ? 320 : 4)) + 'px';
  planEl.style.height = (h + (roomEdit ? 240 : 8)) + 'px';
}

// Positionen aus der automatischen Kette übernehmen (Startpunkt fürs Anordnen)
function captureRoomPositions() {
  const host = $('#rooms .rooms-flow') || $('#rooms .rooms-plan') || $('#rooms .rooms-grid');
  const out = {};
  if (!host) return out;
  const base = host.getBoundingClientRect();
  for (const c of $('#rooms').querySelectorAll('.room-card:not(.room-none)')) {
    const r = c.getBoundingClientRect();
    out[c.dataset.room] = [Math.max(0, Math.round(r.left - base.left)), Math.max(0, Math.round(r.top - base.top))];
  }
  return out;
}

let roomLinks = [];
function drawRoomLinks() {
  const host = $('#rooms .rooms-plan') || $('#rooms .rooms-flow');
  const svg = $('#rooms .room-links');
  if (!host || !svg) return;
  const box = host.getBoundingClientRect();
  svg.setAttribute('viewBox', `0 0 ${box.width} ${box.height}`);
  svg.style.width = box.width + 'px';
  svg.style.height = box.height + 'px';
  if (MOBILE()) { svg.innerHTML = ''; return; }   // Handy: Karten untereinander, „über …“ im Kopf reicht
  const cards = new Map([...host.querySelectorAll('.room-card')].map((c) => [c.dataset.room, c]));
  // 1. Seiten bestimmen, 2. Kabel derselben Kartenseite auffächern, 3. zeichnen
  const geo = [];
  for (const l of roomLinks) {
    const ca = cards.get(l.from), cb = cards.get(l.to);
    if (ca && cb) geo.push({ l, ca, cb, ...roomCableSides(ca, cb) });
  }
  const groups = new Map();
  const slot = (card, side, g, end) => {
    const k = card.dataset.room + '|' + side;
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push([g, end]);
  };
  for (const g of geo) { slot(g.ca, g.sa, g, 'a'); slot(g.cb, g.sb, g, 'b'); }
  for (const list of groups.values()) {
    // nach Lage der Gegenstelle sortieren, damit sich die Kabel nicht kreuzen
    const other = ([g, end]) => (end === 'a' ? g.cb : g.ca).getBoundingClientRect();
    const [g0, e0] = list[0];
    const horiz = /[lr]/.test(e0 === 'a' ? g0.sa : g0.sb);
    list.sort((x, y) => (horiz ? other(x).top - other(y).top : other(x).left - other(y).left));
    const step = horiz ? 9 : 22;
    list.forEach(([g, end], i) => { g['o' + end] = (i - (list.length - 1) / 2) * step; });
  }
  let html = '';
  for (const g of geo) {
    const d = roomCablePath(g, box);
    const l = g.l;
    const hit = `<path class="rl-hit" data-link="${roomLinks.indexOf(l)}" d="${d}"/>`;
    const tip = `${l.from} → ${l.to}: ${l.n} Gerät${l.n > 1 ? 'e' : ''}`;
    if (!l.cable) {   // WLAN: fein gepunktet, ohne Datenfluss
      html += `<g><path class="rl-wlan" d="${d}"/>${hit}</g>`;
      continue;
    }
    const sps = l.items.map((x) => (x.port?.link ? speedMbps(x.port.speed) : null)).filter((v) => v != null);
    const sc = sps.length ? speedClass(Math.min(...sps) + 'M') : '';
    html += `<g><path class="rl-base${l.live ? '' : ' rl-dead'}${l.live && sc ? ' ' + sc : ''}" d="${d}"/>`
      + (l.live ? `<path class="flow ${sc}" d="${d}"/>` : '') + hit + '</g>';
  }
  svg.innerHTML = html;
}

// Kabel-Tooltip: welcher Port an welchem Port, Geschwindigkeit, Datenrate, was dahinter hängt
const cableTip = document.createElement('div');
cableTip.className = 'cable-tip d-none';
document.body.appendChild(cableTip);
function cableTipHtml(l) {
  const items = l.items.slice().sort((x, y) => (y.d.kind === 'infra') - (x.d.kind === 'infra'));
  const row = ({ up, d, port }) => {
    const sp = port ? (port.link ? `${port.speed || 'Link'} · ↓ ${fmtBps(port.rx)} ↑ ${fmtBps(port.tx)}` : 'kein Link am Port') : '';
    return `<div class="it"><b>${esc(up.label)}</b>${d.port ? ' Port ' + esc(d.port) : ''} → <b>${esc(d.label)}</b>${d.uplink ? ' Port ' + d.uplink : ''}
      ${sp ? `<div class="sub">${esc(sp)}</div>` : ''}</div>`;
  };
  return `<div class="hd">${esc(l.from)} → ${esc(l.to)}${l.cable ? '' : ' <span class="sub">(WLAN)</span>'}</div>`
    + items.slice(0, 6).map(row).join('') + (items.length > 6 ? `<div class="sub">+ ${items.length - 6} weitere</div>` : '');
}
function showCableTip(html, e) {
  cableTip.innerHTML = html;
  cableTip.classList.remove('d-none');
  moveCableTip(e);
}
function moveCableTip(e) {
  if (cableTip.classList.contains('d-none')) return;
  const w = cableTip.offsetWidth, hgt = cableTip.offsetHeight;
  cableTip.style.left = Math.min(e.clientX + 14, innerWidth - w - 8) + 'px';
  cableTip.style.top = Math.min(e.clientY + 14, innerHeight - hgt - 8) + 'px';
}
$('#rooms').addEventListener('mouseover', (e) => {
  const h = e.target.closest('.rl-hit');
  if (!h) return;
  const l = roomLinks[Number(h.dataset.link)];
  if (l) showCableTip(cableTipHtml(l), e);
});
$('#rooms').addEventListener('mousemove', moveCableTip);
$('#rooms').addEventListener('mouseout', (e) => {
  if (e.target.closest('.rl-hit')) cableTip.classList.add('d-none');
});

// Tooltip für eine Linie im Baum: Kabel (Port ↔ Port, Tempo, Datenrate, Kabeltest), WLAN (AP, Band, Signal) oder Zuordnung
function treeTipHtml(src, dst) {
  const s = src.data, d = dst.data;
  const sub = (t) => `<div class="sub">${t}</div>`;
  const it = (t) => `<div class="it">${t}</div>`;
  const name = (x) => `<b>${esc(x.kind === 'zone' ? x.label : (lastOctet(x.ip) ? lastOctet(x.ip) + ' ' : '') + x.label)}</b>`;
  const rooms = s.location && d.location && s.location !== d.location ? sub(`${esc(s.location)} → ${esc(d.location)}`) : '';
  const head = (kind) => `<div class="hd">${name(s)} → ${name(d)}</div><div class="sub">${kind}</div>`;

  const wlanNode = (x) => x.kind === 'zone' && x.zoneType === 'wlan';
  if (wlanNode(d)) return head('WLAN des Routers') + it(`${dst.on ?? 0} von ${dst.cnt ?? 0} Geräten online`);
  // Gerät im WLAN des Routers: wie beim AP Signal und Band zeigen
  const w0 = d.wifi;
  if (wlanNode(s) && w0) {
    return head('WLAN' + (w0.guest ? ' · Gastnetz' : ''))
      + it(`${sigBars(w0.signal)} <b>${w0.signal ?? '–'} dBm</b> (${sigText(w0.signal) || 'unbekannt'})${w0.band ? ' · ' + w0.band + ' GHz' : ''}`
        + sub(`Rate ↓ ${fmtKbps(w0.rx)} ↑ ${fmtKbps(w0.tx)}`)) + rooms;
  }
  if (s.kind === 'zone') {
    return head('Zuordnung über IP-Bereich ' + esc(s.range || ''))
      + it('Anschluss unbekannt – im Gerät unter Einstellungen den Switch/Port eintragen');
  }
  if (d.kind === 'zone') {
    return head('IP-Bereich') + it(`${esc(d.range || '')}<br>${dst.on ?? 0} von ${dst.cnt ?? 0} Geräten online`);
  }

  // WLAN: Gerät ist an genau diesem AP angemeldet
  if (d.wifi && d.wifi.apId === s.dbId) {
    const w = d.wifi;
    return head('WLAN' + (w.guest ? ' · Gastnetz' : '') + (d.wifiAuto ? ' · automatisch zugeordnet' : ''))
      + it(`${sigBars(w.signal)} <b>${w.signal ?? '–'} dBm</b> (${sigText(w.signal) || 'unbekannt'})${w.band ? ' · ' + w.band + ' GHz' : ''}`
        + sub(`Rate ↓ ${fmtKbps(w.rx)} ↑ ${fmtKbps(w.tx)}${w.online ? ' · verbunden seit ' + fmtDur(w.online) : ''}`))
      + rooms;
  }

  // Kabel
  const pNo = /^\d+$/.test(String(d.port || '')) ? Number(d.port) : null;
  const port = pNo && s.ports ? s.ports.find((p) => p.p === pNo) : null;
  const upl = d.uplink && d.ports ? d.ports.find((p) => p.p === d.uplink) : null;
  let html = head(s.virtual ? 'Kabel · Pseudo-Switch (keine Messwerte)' : 'Kabel');
  html += it(`${name(s)}${d.port ? ' Port ' + esc(d.port) : ''} → ${name(d)}${d.uplink ? ' Port ' + d.uplink : ''}`
    + (port?.desc ? sub(`Beschreibung: „${esc(port.desc)}“`) : ''));
  if (port) {
    html += it(port.link
      ? `<b>${esc(port.speed || 'Link')}</b>${parseInt(port.speed, 10) < 1000 ? ' <span class="text-warning">(kein Gigabit – Kabel/Stecker prüfen?)</span>' : ''}`
        + (port.rx != null || port.crc ? sub(`${port.rx != null ? `↓ ${fmtBps(port.rx)} ↑ ${fmtBps(port.tx)}` : ''}${port.crc ? ` · <span class="text-warning">${port.crc} CRC-Fehler</span>` : ''}`) : '')
      : '<span class="text-danger">kein Link am Port</span>');
    if (port.cable) {
      html += it(`Kabeltest: <b>${esc(port.cable.result)}</b>${port.cable.dist ? ' · ' + esc(port.cable.dist) : ''}`
        + sub(esc(fmtTime(port.cable.at, true))));
    }
  } else if (upl) {
    html += it(upl.link ? `<b>${esc(upl.speed || 'Link')}</b>` + sub(`am Uplink-Port ${upl.p}: ↓ ${fmtBps(upl.rx)} ↑ ${fmtBps(upl.tx)}`)
      : '<span class="text-danger">Uplink-Port ohne Link</span>');
  } else if (!d.port && !s.virtual) {
    html += it(sub(d.kind === 'device' && (s.type === 'router' || s.type === 'ap')
      ? 'Kabel oder WLAN unbekannt – Port im Gerät eintragen, falls per Kabel'
      : 'Port unbekannt – im Gerät unter Einstellungen eintragen'));
  }
  if (d.wifi) html += it(sub(`außerdem per WLAN angemeldet (${esc(wifiShort(d.wifi))})`));
  if (!d.online && !d.virtual) html += it(`<span class="text-danger">${esc(d.label)} offline</span> ${sub('zuletzt ' + esc(since(d.lastSeen)))}`);
  return html + rooms;
}

$('#rooms').addEventListener('change', (e) => {
  if (e.target.id !== 'roomsWlan') return;
  roomsWlan = e.target.checked;
  store.set('netmon-rooms-wlan', roomsWlan ? '1' : '0');
  drawRooms();
});

// Welche Seiten verbindet das Kabel? r/l = seitlich (stehen nebeneinander), b/t = unten/oben
function roomCableSides(ca, cb) {
  const a = ca.getBoundingClientRect(), b = cb.getBoundingClientRect();
  if (b.left >= a.right - 8) return { sa: 'r', sb: 'l' };
  if (a.left >= b.right - 8) return { sa: 'l', sb: 'r' };
  return b.top >= a.top ? { sa: 'b', sb: 't' } : { sa: 't', sb: 'b' };
}

function roomCablePath(g, box) {
  const pt = (card, side, off) => {
    const r = card.getBoundingClientRect(), h = card.querySelector('.room-head').getBoundingClientRect();
    const y = h.top + h.height / 2 + off, x = r.left + r.width / 2 + off;
    const p = side === 'r' ? [r.right, y] : side === 'l' ? [r.left, y] : side === 'b' ? [x, r.bottom] : [x, r.top];
    return [p[0] - box.left, p[1] - box.top];
  };
  const [x1, y1] = pt(g.ca, g.sa, g.oa || 0), [x2, y2] = pt(g.cb, g.sb, g.ob || 0);
  const dir = { r: [1, 0], l: [-1, 0], b: [0, 1], t: [0, -1] };
  const horiz = g.sa === 'r' || g.sa === 'l';
  const k = Math.max(30, (horiz ? Math.abs(x2 - x1) : Math.abs(y2 - y1)) / 2);
  const c1 = [x1 + dir[g.sa][0] * k, y1 + dir[g.sa][1] * k];
  const c2 = [x2 + dir[g.sb][0] * k, y2 + dir[g.sb][1] * k];
  return `M${x1},${y1} C${c1[0]},${c1[1]} ${c2[0]},${c2[1]} ${x2},${y2}`;
}

$('#rooms').addEventListener('click', async (e) => {
  const act = e.target.closest('[data-room-act]')?.dataset.roomAct;
  if (act === 'edit') {
    roomDraft = { ...(treeData.roomLayout || {}) };
    if (!Object.keys(roomDraft).length) roomDraft = captureRoomPositions();
    roomEdit = true;
    drawRooms();
    return;
  }
  if (act === 'cancel') { roomEdit = false; drawRooms(); return; }
  if (act === 'auto' || act === 'save') {
    const layout = act === 'auto' ? {} : roomDraft;
    if (act === 'auto' && !confirm('Eigene Anordnung verwerfen und Räume wieder automatisch anordnen?')) return;
    try {
      await post('save_room_layout', { layout });
      treeData.roomLayout = layout;
      roomEdit = false;
      drawRooms();
      notify(act === 'auto' ? 'Automatische Anordnung' : 'Anordnung gespeichert');
    } catch (err) {
      notify(err.message, false);
    }
    return;
  }
  if (roomEdit && e.target.closest('.room-head')) return;   // beim Anordnen kein Klick auf den Kopf
  const dev = e.target.closest('[data-dev]');
  if (dev) { openDevice(Number(dev.dataset.dev)); return; }
  if (e.target.closest('[data-toggle-none]')) {
    roomsNoneOpen = !roomsNoneOpen;
    store.set('netmon-rooms-none', roomsNoneOpen ? '1' : '0');
    drawRooms();
  }
});

// Ziehen: Karte am Kopf greifen, auf 10 px einrasten, Linien folgen
$('#rooms').addEventListener('pointerdown', (e) => {
  if (!roomEdit) return;
  const head = e.target.closest('.rooms-plan .room-head');
  if (!head || e.button > 0) return;
  const cardEl = head.closest('.room-card');
  const planEl = cardEl.parentElement;
  const start = { x: e.clientX, y: e.clientY, l: cardEl.offsetLeft, t: cardEl.offsetTop };
  let raf = 0;
  roomDragging = true;
  cardEl.classList.add('dragging');
  head.setPointerCapture(e.pointerId);
  const move = (ev) => {
    const l = Math.max(0, Math.round((start.l + ev.clientX - start.x) / 10) * 10);
    const t = Math.max(0, Math.round((start.t + ev.clientY - start.y) / 10) * 10);
    cardEl.style.left = l + 'px';
    cardEl.style.top = t + 'px';
    if (!raf) raf = requestAnimationFrame(() => { raf = 0; fitPlan(); drawRoomLinks(); });
  };
  const up = () => {
    head.removeEventListener('pointermove', move);
    head.removeEventListener('pointerup', up);
    head.removeEventListener('pointercancel', up);
    cardEl.classList.remove('dragging');
    roomDragging = false;
    roomDraft[cardEl.dataset.room] = [cardEl.offsetLeft, cardEl.offsetTop];
    fitPlan();
    drawRoomLinks();
  };
  head.addEventListener('pointermove', move);
  head.addEventListener('pointerup', up);
  head.addEventListener('pointercancel', up);
  e.preventDefault();
});

function nodeMeta(n) {
  const d = n.data;
  if (d.kind === 'zone') return { text: d.range.replace(/\d+\.\d+\.\d+\./g, '.'), bad: false };
  if (d.virtual) return { text: ['ohne IP', d.location, d.port && 'Port ' + d.port].filter(Boolean).join(' · '), bad: false };
  if (d.kind === 'device' && !d.online) return { text: [d.ip, d.location, 'offline ' + since(d.lastSeen)].filter(Boolean).join(' · '), bad: true };
  const extra = d.port ? 'Port ' + d.port : d.wifi ? wifiShort(d.wifi) : d.private ? 'private MAC' : (d.vendor || '');
  return { text: [d.ip, d.location, extra].filter(Boolean).join(' · '), bad: false };
}

function nodeColor(d) {
  if (d.kind === 'zone' || d.virtual) return COLORS.zone;
  if (d.kind === 'infra') return d.online === false ? COLORS.offline : COLORS.infra;
  return d.online ? COLORS.online : COLORS.offline;
}

// Liste (Standard auf dem Smartphone)
function drawList(root) {
  const row = (n) => {
    const d = n.data;
    const hasKids = !!n.children;
    const open = hasKids && !collapsed.has(d.id);
    const meta = nodeMeta(n);
    const col = nodeColor(d);
    const hollow = d.kind === 'zone' && !open;
    const title = d.kind === 'zone' ? d.label : `${d.label}`;
    const count = hasKids ? `<span class="count">${n.on}/${n.cnt}</span>` : '';
    const clickable = hasKids || d.dbId;
    const icon = iconFor(d);
    const live = d.kind === 'zone' ? n.on > 0 : d.online !== false;
    const ico = icon
      ? `<span class="ico has-icon${live ? ' live' : ''}" style="background:${col};border-color:${col}"><svg viewBox="0 0 24 24">${icon}</svg></span>`
      : `<span class="ico" style="border-color:${col};background:${hollow ? 'transparent' : col}"></span>`;
    let html = `<li><div class="net-row kind-${d.kind}${open ? ' open' : ''}${clickable ? ' clickable' : ''}" data-id="${esc(d.id)}">
      ${ico}
      <div class="main"><div class="title">${esc(title)}</div><div class="meta${meta.bad ? ' bad' : ''}">${esc(meta.text)}</div></div>
      ${count}${hasKids ? CHEVRON : ''}</div>`;
    if (open) html += `<ul${live ? ' class="flowing"' : ''}>${n.children.map(row).join('')}</ul>`;
    return html + '</li>';
  };
  $('#netList').innerHTML = `<ul class="net-list">${row(root)}</ul>`;
}

$('#netList').addEventListener('click', (e) => {
  const r = e.target.closest('.net-row');
  if (!r) return;
  const node = treeData.nodes.find((n) => n.id === r.dataset.id);
  const hasKids = !!r.querySelector('.chev');
  // Bereiche: überall auf- und zuklappen. Router/Switch: Pfeil/Zähler klappt, Name öffnet das Gerät.
  const toggle = hasKids && (node?.kind === 'zone' || !node?.dbId || !e.target.closest('.main'));
  if (toggle) {
    toggleBranch(r.dataset.id);
  } else if (node?.dbId) {
    openDevice(node.dbId);
  }
});

// Baum (Standard auf großen Bildschirmen)
// Textbreite messen, damit die Spalten so gesetzt werden, dass keine Beschriftung rechts abgeschnitten wird
const measureCtx = document.createElement('canvas').getContext('2d');
function textWidth(t, size, weight) {
  if (!t) return 0;
  measureCtx.font = `${weight} ${size}px "DM Sans", system-ui, sans-serif`;
  return measureCtx.measureText(t).width;
}

function drawTree(root) {
  cableTip.classList.add('d-none');   // alte Linie verschwindet beim Neuzeichnen
  root.each((n) => {
    n.kids = n.children;
    if (n.children && collapsed.has(n.data.id)) n.children = null;
  });

  const cw = $('#tree').clientWidth || 900;
  const small = cw < 600;
  // Am großen Bildschirm alles etwas größer zeichnen: Layout in kleinerer Breite rechnen, das SVG skaliert hoch
  const scale = cw >= 1100 ? 1.3 : cw >= 800 ? 1.15 : 1;
  const width = cw / scale;
  const leftPad = small ? 22 : 120;   // links Platz für die Router-Beschriftung
  const maxDepth = d3.max(root.descendants(), (n) => n.depth) || 1;

  // Darstellung der Knoten (wird für Layout und Zeichnen gebraucht)
  const R = (n) => (iconFor(n.data) ? 12 : n.data.kind === 'device' ? 5 : 6.5);
  const leftRoot = (n) => n.depth === 0 && !small;   // Router am PC links neben dem Symbol
  const above = (n) => !!n.children && !leftRoot(n); // offene Zweige: Text über dem Knoten
  // Sehr lange Namen (z. B. zufällige Android-Hostnamen) kürzen; der volle Name steht im Tooltip
  const clip = (t, max) => (t.length > max ? t.slice(0, max - 1) + '…' : t);
  const labelText = (n) => {
    const t = n.data.kind === 'zone' ? n.data.label : `${lastOctet(n.data.ip)}  ${n.data.label}`.trim();
    return clip(t, small && above(n) ? 13 : small ? 24 : 32);
  };
  const metaText = (n) => {
    const d = n.data;
    if (small && above(n)) return '';
    if (n.kids) return small ? `${n.on}/${n.cnt}` : `${n.on}/${n.cnt} online`;
    if (d.virtual) return small ? '' : 'ohne IP';
    if (d.wifi && d.online && !n.kids) return d.wifi.signal == null ? (d.wifi.band ? d.wifi.band + ' GHz' : 'WLAN') : small ? `${d.wifi.signal}` : `${d.wifi.signal} dBm`;
    if (d.kind === 'device' && !d.online) return small ? 'offline' : 'offline ' + since(d.lastSeen);
    if (d.port) return 'Port ' + d.port;
    return d.private && !small ? 'private MAC' : '';
  };
  // Wie weit ragt die Beschriftung rechts über den Knotenmittelpunkt hinaus?
  const reach = (n) => {
    if (leftRoot(n)) return R(n);
    const w = textWidth(labelText(n), 12.5, n.data.kind === 'device' ? 400 : 500)
      + (metaText(n) ? 6 + textWidth(metaText(n), 11, 400) : 0);
    return (above(n) ? -R(n) : R(n) + 6) + w + 6;
  };

  // Spaltenabstand: so groß wie möglich, aber jede Beschriftung muss in die Breite passen
  const avail = width - leftPad - 16;   // etwas Reserve, falls die Schrift noch nicht geladen ist
  let fit = small ? 380 : 460;
  root.each((n) => { if (n.depth > 0) fit = Math.min(fit, (avail - reach(n)) / n.depth); });
  const dy = Math.max(small ? 60 : 140, fit);
  // Beschriftung über offenen Zweigen darf nicht in die nächste Spalte ragen – sonst Zusatz („4/4 online“) weglassen
  const metaFits = (n) => !above(n) || leftRoot(n)
    || textWidth(labelText(n), 12.5, 500) + 6 + textWidth(metaText(n), 11, 400) <= dy - 10;

  const hasIcons = root.descendants().some((n) => iconFor(n.data));
  const dx = hasIcons ? (small ? 32 : 34) : 26;
  // Platzhalter: Knoten ohne sichtbare Kinder reservieren ihre Zeile bis zur letzten Ebene.
  // So schieben sich keine Geräte eines offenen Zweigs unter die Beschriftung eines zugeklappten.
  const phantomHosts = [];
  root.each((n) => {
    if (!n.children && n.depth < maxDepth) {
      let p = n;
      for (let d = n.depth + 1; d <= maxDepth; d++) {
        const ph = { data: { phantom: true }, depth: d, height: 0, parent: p };
        p.children = [ph];
        if (p === n) phantomHosts.push(n);
        p = ph;
      }
    }
  });
  const realKids = (n) => n.children && !n.children[0].data.phantom;
  // Offene Zweige tragen ihre Beschriftung oberhalb -> dort etwas mehr Abstand
  // (d3 ruft separation(unterer, oberer) auf)
  d3.tree().nodeSize([dx, dy])
    .separation((a, b) => (a.parent === b.parent ? 1 : 1.2) + (realKids(a) ? 0.75 : 0))(root);
  phantomHosts.forEach((n) => { n.children = null; });

  let x0 = Infinity, x1 = -Infinity;
  root.each((n) => { x0 = Math.min(x0, n.x); x1 = Math.max(x1, n.x); });
  const top = x0 - dx - 22;

  const svg = d3.create('svg')
    .attr('viewBox', [-leftPad, top, width, x1 - top + dx])
    .attr('role', 'img').attr('aria-label', 'Netzwerkbaum');

  const linkPath = d3.linkHorizontal().x((d) => d.y).y((d) => d.x);
  const isLive = (n) => (n.data.kind === 'zone' ? n.on > 0 : n.data.online !== false);
  // Tempo der Leitung: Port am Switch darüber, sonst Uplink-Port des Geräts (WLAN und Bereiche ohne Farbe)
  const linkSp = (l) => {
    const s = l.source.data, d = l.target.data;
    if (s.kind === 'zone' || d.kind === 'zone' || (d.wifi && d.wifi.apId === s.dbId)) return '';
    const pNo = /^\d+$/.test(String(d.port || '')) ? Number(d.port) : null;
    const port = pNo && s.ports ? s.ports.find((p) => p.p === pNo) : null;
    const upl = d.uplink && d.ports ? d.ports.find((p) => p.p === d.uplink) : null;
    const p = port || upl;
    return p && p.link ? speedClass(p.speed) : '';
  };
  svg.append('g').selectAll('path').data(root.links()).join('path')
    .attr('class', (l) => 'link' + (isLive(l.target) ? (linkSp(l) ? ' ' + linkSp(l) : '') : ' dead')).attr('d', linkPath);
  // Datenstrom: grüne Punkte wandern vom Router zum Gerät, Tempo leicht unterschiedlich
  svg.append('g').selectAll('path').data(root.links().filter((l) => isLive(l.target))).join('path')
    .attr('class', (l) => 'flow ' + linkSp(l)).attr('d', linkPath)
    .style('animation-duration', (l, i) => `${(1.4 + ((i * 7) % 10) / 10).toFixed(1)}s`)
    .style('animation-delay', (l, i) => `-${((i * 3) % 10) / 10}s`);
  // Unsichtbare breite Linien zum Überfahren mit der Maus -> Tooltip mit Kabel-/WLAN-Infos
  svg.append('g').selectAll('path').data(root.links().filter((l) => !l.target.data.phantom)).join('path')
    .attr('class', 'tl-hit').attr('d', linkPath)
    .on('mouseenter', (ev, l) => showCableTip(treeTipHtml(l.source, l.target), ev))
    .on('mousemove', moveCableTip)
    .on('mouseleave', () => cableTip.classList.add('d-none'));

  const node = svg.append('g').selectAll('g').data(root.descendants()).join('g')
    .attr('class', (n) => 'node' + (n.kids || n.data.dbId ? ' clickable' : ''))
    .attr('transform', (n) => `translate(${n.y},${n.x})`)
    .on('click', (ev, n) => {
      if (n.kids && n.data.kind !== 'device' && n.depth > 0) {
        toggleBranch(n.data.id);
      } else if (n.data.dbId) {
        openDevice(n.data.dbId);
      }
    });

  node.append('circle')
    .attr('r', R)
    .attr('stroke-width', 1.8)
    .attr('stroke', (n) => nodeColor(n.data))
    .attr('fill', (n) => (n.data.virtual || (n.data.kind === 'zone' && !n.children) ? 'var(--bs-body-bg)' : nodeColor(n.data)))
    .attr('stroke-dasharray', (n) => (n.data.virtual ? '3 2.5' : null));
  node.filter((n) => iconFor(n.data)).append('g')
    .attr('class', (n) => 'icon' + (isLive(n) ? ' live' : '') + (n.data.virtual || (n.data.kind === 'zone' && !n.children) ? ' virt' : ''))
    .attr('transform', 'translate(-7.5,-7.5) scale(0.625)')
    .html((n) => iconFor(n.data));

  const text = node.append('text')
    .attr('text-anchor', (n) => (leftRoot(n) ? 'end' : 'start'))
    .attr('x', (n) => (leftRoot(n) ? -R(n) - 10 : above(n) ? -R(n) : R(n) + 6))
    .attr('y', (n) => (above(n) ? -R(n) - 7 : 0))
    .attr('dy', (n) => (leftRoot(n) ? '-0.25em' : above(n) ? 0 : '0.32em'));
  text.append('tspan')
    .style('font-weight', (n) => (n.data.kind === 'device' ? 400 : 500))
    .text(labelText);
  text.append('tspan').attr('class', 'meta')
    .attr('x', (n) => (leftRoot(n) ? -R(n) - 10 : null))
    .attr('dy', (n) => (leftRoot(n) ? '1.3em' : null))
    .attr('dx', (n) => (leftRoot(n) ? null : 6))
    .attr('fill', (n) => (n.data.kind === 'device' && !n.data.online && !n.data.virtual ? COLORS.offline : null))
    .text((n) => (metaFits(n) ? metaText(n) : ''));

  node.append('title').text((n) => {
    const d = n.data;
    if (d.kind === 'zone') return `${d.label}\n${d.range}\n${n.on} von ${n.cnt} Geräten online`;
    return [d.label, d.location && 'Ort: ' + d.location, d.ip, d.mac, d.vendor, d.hostname && 'Host: ' + d.hostname,
      d.ports && `Ports: ${d.ports.filter((p) => p.link).length}/${d.ports.length} mit Link`,
      d.wifi && `WLAN: ${wifiShort(d.wifi)} (${sigText(d.wifi.signal)})${d.wifiAuto ? ' · automatisch dem AP zugeordnet' : ''}`,
      'Zuletzt gesehen: ' + fmtTime(d.lastSeen, true)].filter(Boolean).join('\n');
  });

  $('#tree').replaceChildren(svg.node());
}

$('#viewBtns').addEventListener('click', (e) => {
  const b = e.target.closest('button[data-view]');
  if (!b) return;
  netView = b.dataset.view;
  store.set('netmon-view', netView);
  drawNetwork();
});
$('#onlyOnline').addEventListener('change', drawNetwork);
$('#staleBtn').addEventListener('click', () => { showStale = !showStale; drawNetwork(); });
window.addEventListener('resize', () => {
  clearTimeout(window._rt);
  window._rt = setTimeout(() => { if (treeData) applyCompactMode(); drawNetwork(); }, 150);
});

// ---------------------------------------------------------------
// Speedtest
// ---------------------------------------------------------------
let speedData = null, speedRange = '7d', speedChart = null, pingChart = null;

async function loadSpeed() {
  if (!$('#tabSpeed')) return;   // Speedtest abgeschaltet
  speedData = await api('speed', { range: speedRange });
  drawSpeed(speedData);
}

function drawSpeed(d) {
  if (!$('#tabSpeed').classList.contains('active')) return;   // Canvas braucht sichtbare Größe
  const labels = d.points.map((p) => (d.daily ? fmtTime(p.t).trim() : fmtTime(p.t)));
  const grid = cssVar('--bs-border-color');
  const tick = cssVar('--bs-secondary-color');
  const font = { family: 'DM Sans, system-ui, sans-serif', size: 11 };
  const options = (legend) => ({
    responsive: true, maintainAspectRatio: false, animation: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: legend, position: 'bottom', labels: { color: tick, font, boxWidth: 10, boxHeight: 10 } },
      tooltip: { titleFont: font, bodyFont: font },
    },
    scales: {
      x: { ticks: { color: tick, font, maxTicksLimit: MOBILE() ? 4 : 8, maxRotation: 0 }, grid: { display: false } },
      y: { beginAtZero: true, ticks: { color: tick, font, maxTicksLimit: 6 }, grid: { color: grid } },
    },
    elements: { point: { radius: d.points.length > 40 ? 0 : 2, hitRadius: 8 }, line: { tension: 0.25, borderWidth: 2 } },
  });

  speedChart?.destroy();
  speedChart = new Chart($('#speedChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Download', data: d.points.map((p) => p.down), borderColor: COLORS.down, backgroundColor: COLORS.down },
        { label: 'Upload', data: d.points.map((p) => p.up), borderColor: COLORS.up, backgroundColor: COLORS.up },
      ],
    },
    options: options(true),
  });

  pingChart?.destroy();
  pingChart = new Chart($('#pingChart'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'Ping', data: d.points.map((p) => p.ping), borderColor: COLORS.ping, backgroundColor: COLORS.ping }] },
    options: options(false),
  });

  const s = d.stats;
  const tile = (label, val, unit, sub) => `<div class="col-6 col-md-3"><div class="mini-stat">${label}<b>${val} ${unit}</b>${sub}</div></div>`;
  $('#speedStats').innerHTML = d.points.length ? [
    tile('Ø Download', fmtNum(s.down.avg), 'Mbit/s', `min ${fmtNum(s.down.min)} · max ${fmtNum(s.down.max)}`),
    tile('Ø Upload', fmtNum(s.up.avg), 'Mbit/s', `min ${fmtNum(s.up.min)} · max ${fmtNum(s.up.max)}`),
    tile('Ø Ping', fmtNum(s.ping.avg), 'ms', `max ${fmtNum(s.ping.max)} ms`),
    tile(d.daily ? 'Tageswerte' : 'Messungen', d.points.length, '', d.errors ? `${d.errors} fehlgeschlagen` : 'keine Fehler'),
  ].join('') : '';
  $('#speedInfo').textContent = d.points.length ? (d.errors ? 'Fehlgeschlagene Tests erscheinen als Lücke im Verlauf.' : '')
    : 'Für diesen Zeitraum gibt es noch keine Messungen.';
}

$('#rangeBtns')?.addEventListener('click', (e) => {
  const b = e.target.closest('button[data-range]');
  if (!b) return;
  document.querySelectorAll('#rangeBtns button').forEach((x) => x.classList.toggle('active', x === b));
  speedRange = b.dataset.range;
  loadSpeed().catch((err) => notify(err.message, false));
});

// ---------------------------------------------------------------
// Ereignisse, nach Tagen gruppiert
// ---------------------------------------------------------------
const EVT = { neu: 'Neu', online: 'Wieder online', offline: 'Offline', ip_wechsel: 'IP-Wechsel', port: 'Port' };

let evtRows = [];
let evtType = '';
let evtDevice = null;   // gesetzt, wenn über „Alle Ereignisse dieses Geräts“ gefiltert wird

async function loadEvents() {
  evtRows = await api('events');
  renderEvents();
}

function evtItem(r, showDate) {
  const ip = r.event === 'port' ? r.info : r.event === 'ip_wechsel' ? `${r.oldIp} → ${r.newIp}` : (r.newIp || r.oldIp || '');
  return `<div class="list-group-item list-group-item-action" data-dev="${r.dbId}">
    <div class="item-row">
      <span class="evt evt-${esc(r.event)}">${esc(EVT[r.event] || r.event)}</span>
      <div class="main"><div class="title">${esc(r.device)}</div><div class="meta"><span class="mono">${esc(ip)}</span>${r.location ? ' · ' + esc(r.location) : ''}</div></div>
      <div class="side">${esc(showDate ? fmtTime(r.t) : hhmm(r.t))}</div>
    </div></div>`;
}

// Sortierung: Schlüssel und Richtung, gemeinsam für Liste (Handy) und Tabelle (PC)
let evtSort = { key: 't', dir: -1 };
const EVT_PRESETS = { new: ['t', -1], old: ['t', 1], name: ['name', 1], ip: ['ip', 1] };
const evtIp = (r) => r.newIp || r.oldIp || '';
const EVT_KEYS = {
  t: (r) => r.t,
  event: (r) => EVT[r.event] || r.event,
  name: (r) => r.device.toLowerCase(),
  ip: (r) => ipNum(evtIp(r)),
  vendor: (r) => (r.vendor || '~').toLowerCase(),
  mac: (r) => r.mac || '',
};

function sortEvents(list) {
  const { key, dir } = evtSort;
  const get = EVT_KEYS[key];
  return list.sort((a, b) => {
    const x = get(a), y = get(b);
    const c = typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'de');
    return c * dir || (a.t < b.t ? 1 : a.t > b.t ? -1 : 0);   // bei Gleichstand: neueste zuerst
  });
}

function syncSortControls() {
  const preset = Object.entries(EVT_PRESETS).find(([, v]) => v[0] === evtSort.key && v[1] === evtSort.dir);
  $('#evtSort').value = preset ? preset[0] : 'custom';
  document.querySelectorAll('.evt-table th[data-sort]').forEach((th) => {
    th.classList.toggle('sorted', th.dataset.sort === evtSort.key);
    th.classList.toggle('asc', th.dataset.sort === evtSort.key && evtSort.dir === 1);
  });
}

function renderEvents() {
  const q = $('#evtFilter').value.trim().toLowerCase();

  // Treffer ohne Typfilter, damit die Zähler auf den Chips zur Suche passen
  const base = evtRows.filter((r) => (!q || r.search.includes(q) || r.device.toLowerCase().includes(q))
    && (evtDevice === null || r.dbId === evtDevice));
  const counts = base.reduce((a, r) => ((a[r.event] = (a[r.event] || 0) + 1), a), {});
  document.querySelectorAll('#evtChips .chip').forEach((c) => {
    const t = c.dataset.type;
    c.classList.toggle('active', t === evtType);
    c.querySelector('span').textContent = t ? (counts[t] || 0) : base.length;
  });

  const list = sortEvents(evtType ? base.filter((r) => r.event === evtType) : base.slice());
  syncSortControls();
  const empty = evtRows.length ? 'Keine passenden Ereignisse.' : 'Noch keine Ereignisse.';

  // Smartphone: Liste, nach Tag oder Gerät gruppiert
  let html = '', group = null;
  const groupBy = evtSort.key === 't' ? (r) => dayLabel(r.t) : evtSort.key === 'name' ? (r) => r.device : null;
  for (const r of list) {
    if (groupBy) {
      const g = groupBy(r);
      if (g !== group) { html += `<div class="day-head">${esc(g)}</div>`; group = g; }
    }
    html += evtItem(r, evtSort.key !== 't');
  }
  $('#eventList').innerHTML = html || `<div class="list-group-item text-body-secondary">${empty}</div>`;

  // PC: sortierbare Tabelle
  $('#eventTable').innerHTML = list.map((r) => {
    const ip = r.event === 'port' ? r.info : r.event === 'ip_wechsel' ? `${r.oldIp} → ${r.newIp}` : evtIp(r);
    return `<tr data-dev="${r.dbId}">
      <td class="text-nowrap">${esc(fmtTime(r.t))}</td>
      <td><span class="evt evt-${esc(r.event)}">${esc(EVT[r.event] || r.event)}</span></td>
      <td>${esc(r.device)}${r.location ? ` <span class="text-body-secondary small">· ${esc(r.location)}</span>` : ''}</td>
      <td class="mono text-nowrap">${esc(ip)}</td>
      <td class="text-body-secondary">${esc(r.vendor || '')}</td>
      <td class="mono text-body-secondary text-nowrap">${esc(r.mac || '')}</td>
    </tr>`;
  }).join('') || `<tr><td colspan="6" class="text-body-secondary">${empty}</td></tr>`;

  const filtered = q || evtType || evtDevice !== null;
  $('#evtCount').textContent = filtered ? `${list.length} von ${evtRows.length} Ereignissen` : `${evtRows.length} Ereignisse`;
  $('#evtReset').classList.toggle('d-none', !filtered);
}

const EVT_PH = 'Gerät, Ort, IP oder Hersteller suchen';
$('#evtFilter').addEventListener('input', () => { evtDevice = null; $('#evtFilter').placeholder = EVT_PH; renderEvents(); });
$('#evtSort').addEventListener('change', () => {
  const p = EVT_PRESETS[$('#evtSort').value];
  if (p) evtSort = { key: p[0], dir: p[1] };
  renderEvents();
});
document.querySelector('.evt-table thead').addEventListener('click', (e) => {
  const th = e.target.closest('th[data-sort]');
  if (!th) return;
  const key = th.dataset.sort;
  evtSort = evtSort.key === key ? { key, dir: -evtSort.dir } : { key, dir: key === 't' ? -1 : 1 };
  renderEvents();
});
$('#eventTable').addEventListener('click', (e) => {
  const tr = e.target.closest('tr[data-dev]');
  if (tr) openDevice(Number(tr.dataset.dev));
});
$('#evtChips').addEventListener('click', (e) => {
  const c = e.target.closest('.chip');
  if (!c) return;
  evtType = c.dataset.type;
  renderEvents();
});
$('#evtReset').addEventListener('click', () => {
  evtType = ''; evtDevice = null;
  $('#evtFilter').value = '';
  evtSort = { key: 't', dir: -1 };
  renderEvents();
});
$('#eventList').addEventListener('click', (e) => {
  const it = e.target.closest('[data-dev]');
  if (it) openDevice(Number(it.dataset.dev));
});

// Aus dem Gerätedialog: nur die Ereignisse dieses Geräts zeigen
function showDeviceEvents(id, label) {
  evtDevice = id; evtType = '';
  $('#evtFilter').value = '';
  $('#evtFilter').placeholder = 'Gefiltert: ' + label;
  renderEvents();
  showTab('#tabEvents');
}
$('#evtReset').addEventListener('click', () => { $('#evtFilter').placeholder = EVT_PH; });
$('#devEvents').addEventListener('click', () => {
  if (!currentDev) return;
  modal.hide();
  showDeviceEvents(currentDev.id, devTitle(currentDev));
});

// ---------------------------------------------------------------
// Geräte
// ---------------------------------------------------------------
let devData = null;

async function loadDevices() {
  devData = await api('devices');
  // Ortsfilter und Vorschläge im Dialog aus den vergebenen Orten
  const sel = $('#devLoc'), cur = sel.value;
  sel.innerHTML = '<option value="">Alle Orte</option>'
    + devData.locations.map((l) => `<option value="${esc(l)}">${esc(l)}</option>`).join('')
    + '<option value="-">ohne Ort</option>';
  sel.value = [...sel.options].some((o) => o.value === cur) ? cur : '';
  $('#locList').innerHTML = devData.locations.map((l) => `<option value="${esc(l)}">`).join('');
  renderDevices();
}

const devTitle = (d) => d.name || d.hostname || d.vendor || d.mac || 'unbekannt';

function renderDevices() {
  if (!devData) return;
  const f = $('#devFilter').value.trim().toLowerCase();
  const state = $('#devState').value;
  const loc = $('#devLoc').value;
  const list = devData.devices.filter((d) => {
    if (loc === '-' && d.location) return false;
    if (loc && loc !== '-' && d.location !== loc) return false;
    if (state === 'on' && !d.online) return false;
    if (state === 'off' && (d.online || d.virtual)) return false;
    if (state === 'noname' && d.name) return false;
    if (!f) return true;
    return [d.ip, d.mac, d.name, d.location, d.hostname, d.vendor, d.type, d.type && typeLabel(d.type)].some((v) => v && String(v).toLowerCase().includes(f));
  });
  $('#devCount').textContent = `${list.length} von ${devData.devices.length} Geräten`;
  $('#devList').innerHTML = list.map((d) => {
    const meta = [d.ip || 'ohne IP', d.location, d.type && typeLabel(d.type), d.name ? (d.hostname || d.vendor) : d.vendor].filter(Boolean).join(' · ');
    const side = d.virtual ? '<span class="text-body-secondary">nicht gescannt</span>' : d.online ? (d.port ? 'Port ' + esc(d.port) : '') : `<span class="text-danger">offline</span><br>${esc(since(d.lastSeen))}`;
    return `<div class="list-group-item list-group-item-action" data-dev="${d.id}">
      <div class="item-row">
        <span class="dot ${d.virtual ? 'virt' : d.online ? 'on' : 'off'}"></span>
        <div class="main"><div class="title${d.name ? '' : ' text-body-secondary'}">${esc(devTitle(d))}</div><div class="meta">${esc(meta)}</div></div>
        <div class="side">${side}</div>
      </div></div>`;
  }).join('') || '<div class="list-group-item text-body-secondary">Keine passenden Geräte.</div>';
}

$('#devFilter').addEventListener('input', renderDevices);
$('#devState').addEventListener('change', renderDevices);
$('#devLoc').addEventListener('change', renderDevices);
$('#devList').addEventListener('click', (e) => {
  const it = e.target.closest('[data-dev]');
  if (it) openDevice(Number(it.dataset.dev));
});

// Bearbeiten-Dialog
const modal = new bootstrap.Modal($('#devModal'));
let currentDev = null;

async function openDevice(id) {
  if (!devData || !devData.devices.some((d) => d.id === id)) await loadDevices();
  const d = devData.devices.find((x) => x.id === id);
  if (!d) return notify('Gerät nicht gefunden', false);
  currentDev = d;
  const f = $('#devForm');
  f.id.value = d.id;
  f.name.value = d.name || '';
  f.name.placeholder = d.hostname || '';
  f.location.value = d.location || '';
  f.port.value = d.port || '';
  f.notes.value = d.notes || '';
  f.type.innerHTML = '<option value="">–</option>' + devData.types.map((t) => `<option value="${t}" ${t === d.type ? 'selected' : ''}>${esc(typeLabel(t))}</option>`).join('');
  f.parentId.innerHTML = `<option value="">${d.virtual ? 'direkt am Router' : 'nach IP-Bereich'}</option>` + devData.parents.filter((p) => p.id !== d.id)
    .map((p) => `<option value="${p.id}" ${p.id === d.parentId ? 'selected' : ''}>${esc(p.label)}</option>`).join('');

  $('#devModalTitle').textContent = devTitle(d);
  $('#devModalSub').innerHTML = d.virtual
    ? '<span class="dot virt"></span> ohne IP · wird nicht gescannt'
    : `<span class="dot ${d.online ? 'on' : 'off'}"></span> ${d.online ? 'online' : 'offline ' + esc(since(d.lastSeen))}`;
  $('#devEvents').classList.toggle('d-none', !!d.virtual);
  const fact = (k, v) => (v ? `<dt>${k}</dt><dd>${v}</dd>` : '');
  $('#devFacts').innerHTML = fact('IP', `<span class="mono">${esc(d.ip)}</span>`)
    + fact('MAC', `<span class="mono">${esc(d.mac)}</span>${d.private ? ' · private MAC' : ''}`)
    + fact('Hersteller', esc(d.vendor)) + fact('Hostname', esc(d.hostname))
    + (d.wifi ? fact('WLAN', `${d.wifi.signal == null ? 'verbunden' : `${sigBars(d.wifi.signal)} ${d.wifi.signal} dBm <span class="text-body-secondary">(${sigText(d.wifi.signal)})</span>`}
        · ${d.wifi.band ? d.wifi.band + ' GHz' : ''} · ${fmtKbps(d.wifi.tx)}<br><span class="text-body-secondary">an</span>
        ${esc(devTitle(devData.devices.find((x) => x.id === d.wifi.apId) || { name: 'AP' }))}${d.wifi.online ? ' <span class="text-body-secondary">· seit ' + fmtDur(d.wifi.online) + '</span>' : ''}`) : '')
    + fact('Erstmals', esc(fmtTime(d.firstSeen, true))) + fact('Zuletzt', esc(fmtTime(d.lastSeen, true)));
  $('#devDelete')?.classList.toggle('d-none', d.online);
  $('#devError').textContent = '';
  renderSwInfo(d);
  renderPorts(d);
  graphInit(d);
  $('#devTabs').classList.remove('d-none');
  devTab(d.ports || d.swInfo || d.wifiClients || d.wifi ? 'over' : 'set');
  if ($('#swBox')) {
    f.swPass.value = '';
    f.swPass.placeholder = d.swPass ? 'gespeichert – leer lassen' : '';
    f.swUplink.value = d.uplink ?? '';
    $('#swRemove').classList.toggle('d-none', !d.swPass);
    $('#swMsg').textContent = '';
    $('#swPages').href = 'api.php?action=switch_pages&id=' + d.id;
    syncSwBox();
  }
  modal.show();
}

// Treiber: Liste vom Server (einmal), Vorschlag nach Name/Hersteller
let swDrivers = null;
const OTHER_BRANDS = /d-?link|tp-?link|cisco|zyxel|ubiquiti|unifi|mikrotik|tenda|hpe?|aruba|edimax|level ?one|digitus/i;
async function fillDriverSelect(d) {
  if (!swDrivers) {
    try { swDrivers = await api('switch_drivers'); } catch (e) { swDrivers = []; }
  }
  const f = $('#devForm');
  // Erst der selbst vergebene Name (z. B. „D-Link DGS-1100“), dann Modell aus dem Switch, Hersteller laut MAC und Hostname
  const sources = [[f.name.value || d.name, true], [d.swInfo?.model, false], [d.vendor, false], [d.hostname, false]];
  let hit = null, brand = null, why = '';
  for (const [text, strong] of sources) {
    if (!text) continue;
    const drv = swDrivers.find((x) => new RegExp(x.hint, 'i').test(text));
    if (drv) { hit = drv; why = text.match(new RegExp(drv.hint, 'i'))[0]; break; }
    if (strong && OTHER_BRANDS.test(text)) { brand = text.match(OTHER_BRANDS)[0]; break; }
  }
  f.swDriver.innerHTML = '<option value="auto">Automatisch erkennen</option>'
    + swDrivers.map((x) => `<option value="${x.key}">${esc(x.label)}</option>`).join('');
  f.swDriver.value = d.driver || hit?.key || 'auto';
  f.swUser.value = d.swUser || '';
  syncSwUser();
  if (d === currentDev) renderSwInfo(d);   // Router-Knöpfe brauchen die Treiberliste
  $('#swHint').textContent = d.driver ? 'eingerichtet'
    : hit ? `Vorschlag anhand von „${why}“`
    : brand ? `Für ${brand} gibt es noch keinen Treiber – mit dem Analyse-Paket kann einer gebaut werden.`
    : 'netmon versucht, den Switch selbst zu erkennen.';
}

// Modell, Firmware, Name und Einstellungs-Check aus dem Switch
function renderSwInfo(d) {
  const box = $('#devSwInfo');
  const i = d && d.swInfo;
  if (!i) { box.classList.add('d-none'); box.innerHTML = ''; return; }
  const ICON = { ok: '✓', warn: '!', info: 'i' };
  const facts = [['Modell', i.model], ['Firmware', i.firmware], ['Seriennr.', i.serial]].filter(([, v]) => v)
    .map(([k, v]) => `<span><span class="text-body-secondary">${k}</span> ${esc(v)}</span>`).join('');
  const nameRow = i.name ? `<div class="sw-name">Name im Switch: <b>${esc(i.name)}</b>
      ${IS_ADMIN ? `<button type="button" class="btn btn-link btn-sm p-0 ms-2" data-sw-take="name">als Name</button>
      <button type="button" class="btn btn-link btn-sm p-0 ms-2" data-sw-take="location">als Raum</button>` : ''}</div>` : '';
  const checks = (i.checks || []).map(([lvl, t]) => `<li class="chk-${lvl}"><span>${ICON[lvl] || '·'}</span>${esc(t)}</li>`).join('');
  // Router schalten (Speedport mit Gerätepasswort): WLAN, Gäste-WLAN, neu verbinden, neu starten
  const acts = (swDrivers || []).find((x) => x.key === d.driver)?.actions || [];
  const RA = { wlan_off: 'WLAN aus', wlan_on: 'WLAN an', guest_on: 'Gäste-WLAN an', guest_off: 'Gäste-WLAN aus', reconnect: 'Neu verbinden', reboot: 'Neu starten' };
  // nur den passenden Knopf zeigen, wenn der Zustand bekannt ist (WLAN an → nur „WLAN aus“)
  const w = i.wlan || {};
  const hide = { wlan_on: w.on === true, wlan_off: w.on === false, guest_on: w.guestOn === true, guest_off: w.guestOn === false };
  const actRow = IS_ADMIN && acts.length && d.swRealPass ? `<div class="d-flex flex-wrap gap-2 mt-2">${acts.filter((k) => !hide[k]).map((k) =>
      `<button type="button" class="btn btn-ghost btn-sm${k === 'reboot' ? ' text-danger' : ''}" data-ract="${k}">${RA[k] || k}</button>`).join('')}</div>` : '';
  box.innerHTML = `<div class="small-label mb-1">${d.type === 'ap' ? 'Access Point' : d.type === 'router' ? 'Router' : 'Switch'}${i.checked ? ' · Stand ' + esc(fmtTime(i.checked)) : ''}</div>
    <div class="sw-facts">${facts}</div>${nameRow}${checks ? `<ul class="sw-checks">${checks}</ul>` : ''}${actRow}`;
  box.classList.remove('d-none');
}
$('#devSwInfo').addEventListener('click', async (e) => {
  const a = e.target.closest('[data-ract]');
  if (a && currentDev) {
    const k = a.dataset.ract;
    const ask = { wlan_off: 'WLAN des Routers ausschalten? Alle Geräte darin verlieren das Netz.', reconnect: 'Internet neu verbinden? Es ist etwa eine halbe Minute weg.',
                  reboot: 'Router neu starten? Netz und Internet sind ein paar Minuten weg.' }[k];
    if (ask && !confirm(ask)) return;
    a.disabled = true;
    try {
      const r = await post('router_action', { id: currentDev.id, act: k });
      notify(r.msg);
    } catch (err) { $('#devError').textContent = err.message; }
    a.disabled = false;
    return;
  }
  const b = e.target.closest('[data-sw-take]');
  if (!b || !currentDev?.swInfo?.name) return;
  const f = $('#devForm');
  f[b.dataset.swTake].value = currentDev.swInfo.name;
  f[b.dataset.swTake].classList.add('flash');
  setTimeout(() => f[b.dataset.swTake].classList.remove('flash'), 900);
  notify(b.dataset.swTake === 'name' ? 'Name übernommen – Speichern nicht vergessen' : 'Raum übernommen – Speichern nicht vergessen');
});

// Reiter im Gerätedialog: Übersicht / Einstellungen
function devTab(name) {
  document.querySelectorAll('#devTabs [data-dtab]').forEach((b) => b.classList.toggle('active', b.dataset.dtab === name));
  document.querySelectorAll('#devForm .dev-pane').forEach((p) => p.classList.toggle('d-none', p.dataset.pane !== name));
  if (name === 'graph') graphShow(); else graphStop();
}
$('#devTabs').addEventListener('click', (e) => {
  const b = e.target.closest('[data-dtab]');
  if (b) devTab(b.dataset.dtab);
});

const PORT_OFF = ['netgear_plus2', 'netgear_plus'];   // Treiber, die Ports per Software abschalten können
const NO_CABLE = ['avm_fritzbox', 'telekom_speedport', 'tplink_archer', 'tplink_easy', 'netgear_jgs', 'netgear_gs316'];   // Treiber ohne Kabeltest
// Port-Tabelle im Gerätedialog (für alle sichtbar, sobald der Switch abgefragt wird)
function renderPorts(d) {
  const box = $('#devPorts');
  const side = $('#devPanel');
  const wide = !!(d && (d.ports || d.swInfo || d.wifiClients));
  $('#devDialog').classList.toggle('modal-xl', wide);
  $('#devDialog').classList.toggle('modal-lg', !wide);
  $('#devForm').classList.toggle('has-panel', !!(d && (d.ports || d.wifiClients)));
  if (!d || !d.ports) {
    box.classList.add('d-none'); box.innerHTML = '';
    side.innerHTML = d && d.wifiClients ? wifiClientList(d) : '';
    return;
  }
  const parent = devData.devices.find((x) => x.id === d.parentId);
  const whoText = (p) => (p.p === d.uplink ? '↑ ' + (parent ? devTitle(parent) : 'Uplink') : (d.onPort[p.p] || []).join(', '));
  const rate = (p) => (p.link ? `${p.speed || 'Link'}${p.rx != null ? ` · ↓ ${fmtBps(p.rx)} ↑ ${fmtBps(p.tx)}` : ''}` : 'kein Link');
  // 10 Mbit und alle Geräte am Port offline: Netzwerkkarte im Standby (ausgeschalteter PC hält den Link für Wake-on-LAN)
  const standby = (p) => {
    if (!p.link || parseInt(p.speed, 10) !== 10 || p.p === d.uplink) return false;
    const here = devData.devices.filter((x) => x.parentId === d.id && String(x.port ?? '') === String(p.p) && !x.virtual);
    return here.length > 0 && here.every((x) => !x.online);
  };
  const canCable = !NO_CABLE.includes(d.driver);

  // Frontansicht: Buchsen mit LED, Beschriftung darunter, alles Weitere beim Überfahren
  const jacks = d.ports.map((p) => {
    const who = whoText(p);
    const label = who || p.desc || '';
    const tip = [`Port ${p.p}${p.p === d.uplink ? ' · Uplink' : ''}`, p.desc && `„${p.desc}“`, who, rate(p),
      standby(p) && 'Gerät aus – Netzwerkkarte im Standby (10M)',
      p.cable && `Kabel: ${p.cable.result}${p.cable.dist && p.cable.dist !== '0' ? ' · ' + p.cable.dist + ' m' : ''}`].filter(Boolean).join('\n');
    return `<button type="button" class="rj ${portClass(p)}${p.p === d.uplink ? ' uplink' : ''}" data-port="${p.p}" data-tip="${esc(tip)}" aria-label="${esc(tip)}">
      <span class="rj-num">${p.p}</span><span class="rj-jack"><span class="led"></span><span class="led2"></span></span>
      <span class="rj-lbl">${esc(label)}</span></button>`;
  }).join('');
  const model = [d.swInfo?.model, d.swInfo?.name && `„${d.swInfo.name}“`].filter(Boolean).join(' ');
  const panel = `<div class="sw-panel"><div class="sw-panel-head"><span class="brand-mini">${esc(model || devTitle(d))}</span>
      <span class="sw-panel-sum">${d.ports.filter((p) => p.link).length}/${d.ports.length} Ports aktiv</span></div>
      <div class="sw-jacks">${jacks}</div></div>`;

  const rows = d.ports.map((p) => {
    const devs = d.onPort[p.p] || [];
    const who = p.p === d.uplink
      ? `<span class="text-accent">↑ Uplink</span>${parent ? ' · ' + esc(devTitle(parent)) : ''}`
      : devs.length ? devs.map(esc).join(', ')
      : p.desc ? `<span class="text-body-secondary">„${esc(p.desc)}“</span>` : '<span class="text-body-secondary">–</span>';
    const desc = p.desc && (devs.length || p.p === d.uplink) ? `<div class="port-desc">„${esc(p.desc)}“</div>` : '';
    const sb = standby(p) ? '<div class="port-desc">Gerät aus – Netzwerkkarte im Standby, daher 10M</div>' : '';
    const cab = p.cable ? `<div class="port-cable ${/ok|normal/i.test(p.cable.result) ? 'ok' : 'bad'}">Kabel: ${esc(p.cable.result)}${p.cable.dist && p.cable.dist !== '0' ? ' · ' + esc(p.cable.dist) + ' m' : ''} · ${esc(fmtTime(p.cable.at))}</div>` : '';
    const test = IS_ADMIN && d.swPass && canCable && p.p !== d.uplink && !p.off ? `<button type="button" class="port-act" data-cable="${p.p}" title="Kabeltest – Port ist dabei kurz ohne Verbindung">⌁ Kabeltest</button>` : '';
    const guard = devData.protectedPorts?.[d.id + ':' + p.p];
    const lock = !(IS_ADMIN && d.swPass && PORT_OFF.includes(d.driver) && p.p !== d.uplink) ? ''
      : guard && !p.off ? `<span class="port-act is-guard" title="Nicht sperrbar: ${esc(guard)}">🛡 geschützt</span>`
      : `<button type="button" class="port-act ${p.off ? 'is-unlock' : 'is-lock'}" data-portoff="${p.p}" data-on="${p.off ? 1 : 0}">${p.off ? '🔓 Freigeben' : '🔒 Sperren'}</button>`;
    const pinned = (devData.quickPorts || []).includes(d.id + ':' + p.p);
    const pin = IS_ADMIN && PORT_OFF.includes(d.driver) && d.swPass && p.p !== d.uplink && !(guard && !p.off)
      ? `<button type="button" class="port-act${pinned ? ' is-pinned' : ''}" data-qpin="${p.p}" data-pinned="${pinned ? 1 : 0}" title="${pinned ? 'Aus dem Schnellzugriff nehmen' : 'Auf der Seite „Schnellzugriff“ anzeigen'}">${pinned ? '★ Schnellzugriff' : '☆ Schnellzugriff'}</button>` : '';
    const offTxt = p.off ? '<div class="port-desc text-danger">gesperrt – per Software abgeschaltet</div>' : '';
    return `<tr data-row="${p.p}"><td><span class="port-sq ${portClass(p)}">${p.p}</span></td><td class="who"><div class="who-row"><div class="who-txt">${who}${desc}${offTxt}${sb}${cab}</div>${test || lock || pin ? `<div class="port-acts">${lock}${pin}${test}</div>` : ''}</div></td>
      <td class="text-nowrap">${p.off ? '<span class="text-danger">gesperrt</span>' : p.link ? esc(p.speed || 'Link') : '<span class="text-body-secondary">kein Link</span>'}</td>
      <td class="mono text-nowrap text-end">${p.link && p.rx != null ? `↓ ${fmtBps(p.rx)}<br>↑ ${fmtBps(p.tx)}` : ''}</td></tr>`;
  }).join('');
  const t = d.ports[0]?.updated;
  const all = IS_ADMIN ? portSuggestions(d) : [];
  const dis = new Set(devData.dismissed || []);
  const hidden = all.filter((s) => dis.has(s.key));
  const sug = showHiddenSug ? all : all.filter((s) => !dis.has(s.key));
  const sugHtml = sug.length || hidden.length ? `<div class="sw-sug"><div class="small-label mb-1 d-flex">${d.swInfo?.lanHosts ? "Verbindungsvorschläge" : "Vorschläge aus den Port-Beschreibungen"}
      ${hidden.length ? `<button type="button" class="btn btn-link btn-sm p-0 ms-auto sug-toggle" data-sug-toggle>${showHiddenSug ? 'ausgeblendete verbergen' : hidden.length + ' ausgeblendet – anzeigen'}</button>` : ''}</div>
      ${sug.map((s, i) => `
      <div class="sug-row${dis.has(s.key) ? ' is-hidden' : ''}"><div class="sug-txt">${s.text}</div>
        <button type="button" class="btn btn-accent btn-sm" data-sug="${i}" data-dir="a">Verbinden</button>
        ${s.alt ? `<button type="button" class="btn btn-ghost btn-sm" data-sug="${i}" data-dir="b" title="${esc(s.altText)}">andersherum</button>` : ''}
        ${dis.has(s.key) ? `<button type="button" class="btn btn-ghost btn-sm" data-sug-undo="${i}">wieder vorschlagen</button>`
          : `<button type="button" class="btn btn-ghost btn-sm" data-sug-hide="${i}" title="Nicht mehr vorschlagen, bis sich die Port-Beschreibung ändert">Ignorieren</button>`}</div>`).join('')}</div>` : '';
  side._sug = sug;
  // Router mit eigenem WLAN (Speedport): Geräte im WLAN unter den Ports
  side.innerHTML = panel + sugHtml + (d.wifiClients ? `<div class="mt-3">${wifiClientList(d)}</div>` : '');
  box.innerHTML = `<div class="small-label mb-1 mt-3">Ports${t ? ' · Stand ' + esc(hhmm(t)) : ''}</div><table class="port-table">${rows}</table>`;
  box.classList.remove('d-none');
}

// Access Point: angemeldete WLAN-Clients, schwächstes Signal zuletzt
function wifiClientList(d) {
  const list = d.wifiClients;
  const weak = list.filter((c) => c.signal < -75).length;
  const rows = list.map((c) => `<tr${c.id ? ` data-open-dev="${c.id}" class="clickable"` : ''}>
      <td>${sigBars(c.signal)}</td>
      <td class="who">${esc(c.name)}${c.location ? `<div class="port-desc">${esc(c.location)}</div>` : ''}${c.id ? '' : '<div class="port-desc">nicht im Scan</div>'}</td>
      <td class="text-nowrap">${c.band ? c.band + ' GHz' : ''}${c.guest ? ' · Gast' : ''}</td>
      <td class="text-nowrap ${c.signal < -75 ? 'text-danger' : ''}">${c.signal == null ? '–' : c.signal + ' dBm'}</td>
      <td class="mono text-nowrap text-end">${fmtKbps(c.tx)}<br><span class="text-body-secondary">${fmtDur(c.online)}</span></td></tr>`).join('');
  return `<div class="small-label mb-1">WLAN-Clients · ${list.length}${weak ? ` · <span class="text-danger">${weak} schwach</span>` : ''}</div>
    <table class="port-table wifi-table">${rows}</table>`;
}
$('#devPanel').addEventListener('click', (e) => {
  const tr = e.target.closest('[data-open-dev]');
  if (tr) { modal.hide(); setTimeout(() => openDevice(Number(tr.dataset.openDev)), 350); }
});

// ---------------------------------------------------------------
// Reiter „Verkehr“ (Switch) bzw. „Auslastung“ (Access Point): Verlauf aus der Minuten-Abfrage und Live-Ansicht
// ---------------------------------------------------------------
const GR = {
  dev: null, range: '1h', hist: null, hist1h: null, samples: [], timer: null, liveStart: 0, paused: false,
  errors: 0, msg: '', hoverT: null, req: 0,
};
const GR_LIVE_MS = { sw: 5000, ap: 10000, fb: 5000 };      // Abfrage-Abstand in der Live-Ansicht
const GR_LIVE_WIN = { sw: 300, ap: 600, fb: 300 };        // sichtbares Zeitfenster live (Sekunden)
const GR_LIVE_MAX = 10 * 60 * 1000;              // nach 10 Minuten automatisch pausieren (schont Switch und AP)
const GR_C = { rx: COLORS.down, tx: COLORS.up, cpu: COLORS.up, mem: COLORS.ping, c24: COLORS.ping, c5: COLORS.down,
  wan_rx: COLORS.down, wan_tx: COLORS.up, dsl_down: COLORS.down, dsl_up: COLORS.up, snr_down: COLORS.down, snr_up: COLORS.up, crc: COLORS.ping };
const ROUTER_DRV = ['avm_fritzbox', 'telekom_speedport'];   // Router mit DSL-Werten: Grafik „Internet & DSL“
const grKind = (d) => (ROUTER_DRV.includes(d?.driver) && d?.type !== 'ap' ? 'fb' : d?.ports ? 'sw' : 'ap');
// Router-Messwerte: Fehlerzähler in „je Minute“ umrechnen (Rückgang = neu synchronisiert -> Lücke)
function grCrc(list) {
  let prev = null;
  for (const q of list) {
    if (q.gap) { prev = null; continue; }
    q.crc = prev && q.crc_total != null && prev.crc_total != null && q.crc_total >= prev.crc_total && q.t > prev.t
      ? (q.crc_total - prev.crc_total) / ((q.t - prev.t) / 60) : null;
    prev = q;
  }
  return list;
}

function graphInit(d) {
  graphStop();
  const has = !!(d && (d.ports || d.wifiClients || d.driver === 'tplink_archer' || (ROUTER_DRV.includes(d.driver) && d.swPass) || (d.type === 'ap' && d.swPass)));
  GR.dev = has ? d : null;
  GR.hist = null; GR.hist1h = null; GR.samples = []; GR.paused = false; GR.msg = ''; GR.hoverT = null;
  GR.range = d?.swPass ? store.get('netmon-graph-range') || '1h' : '1h';
  if (GR.range === 'live' && !d?.swPass) GR.range = '1h';
  const btn = $('#devTabGraph');
  btn.classList.toggle('d-none', !has);
  btn.textContent = { sw: 'Verkehr', ap: 'Auslastung', fb: 'Internet & DSL' }[grKind(d)];
  $('#devGraph').innerHTML = '';
  // Für den Port-Tooltip in der Übersicht schon mal die letzte Stunde holen
  if (has && grKind(d) === 'sw') {
    const id = d.id;
    api('history', { id, range: '1h' }).then((h) => { if (GR.dev?.id === id) GR.hist1h = h; }).catch(() => {});
  }
}

function graphStop() {
  clearTimeout(GR.timer);
  GR.timer = null;
  GR.req++;
}

async function graphShow() {
  const d = GR.dev;
  if (!d) return;
  if (GR.range === 'live') return graphLiveStart();
  graphRender();
  const req = ++GR.req;
  try {
    const h = await api('history', { id: d.id, range: GR.range });
    if (req !== GR.req || GR.dev !== d) return;
    GR.hist = h;
    if (GR.range === '1h') GR.hist1h = h;
    GR.msg = '';
  } catch (e) {
    GR.msg = e.message;
  }
  graphRender();
}

function graphLiveStart() {
  GR.samples = []; GR.errors = 0; GR.paused = false; GR.msg = 'verbinde …';
  GR.liveStart = Date.now();
  graphRender();
  graphLiveTick(++GR.req);
}

async function graphLiveTick(req) {
  const d = GR.dev;
  if (!d || req !== GR.req || GR.range !== 'live') return;
  const visible = !$('#devModal').classList.contains('show') ? false : !document.hidden;
  if (Date.now() - GR.liveStart > GR_LIVE_MAX || !visible) {
    GR.paused = true; GR.msg = '';
    return graphRender();
  }
  const t0 = Date.now();
  try {
    const r = await api('live', { id: d.id });
    if (req !== GR.req) return;
    GR.samples.push(r);
    const keep = GR_LIVE_WIN[grKind(d)] + 30;
    while (GR.samples.length > 2 && GR.samples[0].t < r.t - keep) GR.samples.shift();
    GR.errors = 0; GR.msg = '';
  } catch (e) {
    if (req !== GR.req) return;
    GR.errors++;
    GR.msg = e.message;
    if (GR.errors >= 3) { GR.paused = true; return graphRender(); }
  }
  graphRender();
  const wait = Math.max(1000, GR_LIVE_MS[grKind(d)] * (GR.errors ? 3 : 1) - (Date.now() - t0));
  GR.timer = setTimeout(() => graphLiveTick(req), wait);
}
document.addEventListener('visibilitychange', () => {
  if (document.hidden) graphStop();
});
$('#devModal').addEventListener('hidden.bs.modal', graphStop);

// Datenreihen je Port bzw. für den AP: [{t, …}] mit Lücken (null), wo Messungen fehlen
function grSeries() {
  const d = GR.dev, kind = grKind(d);
  if (GR.range === 'live') {
    const S = GR.samples;
    if (kind === 'ap') {
      return { ap: S.map((x) => ({ t: x.t, cpu: x.stats?.cpu ?? null, mem: x.stats?.mem ?? null, c24: x.c24, c5: x.c5, clients: x.clients })) };
    }
    if (kind === 'fb') return { fb: grCrc(S.map((x) => ({ t: x.t, ...x.metrics }))) };
    const ports = {};
    for (let i = 1; i < S.length; i++) {
      const dt = S[i].t - S[i - 1].t;
      const prev = new Map(S[i - 1].ports.map((p) => [p.p, p]));
      for (const p of S[i].ports) {
        const o = prev.get(p.p);
        const r = (a, b) => (a == null || b == null || dt <= 0 || a < b ? null : Math.round(((a - b) * 8) / dt));
        (ports[p.p] ||= []).push({ t: S[i].t, rx: o && p.link ? r(p.rx, o.rx) : null, tx: o && p.link ? r(p.tx, o.tx) : null });
      }
    }
    return { ports };
  }
  const h = GR.hist;
  if (!h) return null;
  const gap = (list) => {
    const out = [];
    list.forEach((x, i) => {
      if (i && x.t - list[i - 1].t > h.step * 2.5) out.push({ t: list[i - 1].t + h.step, gap: true });
      out.push(x);
    });
    return out;
  };
  if (kind === 'ap') {
    return { ap: gap(h.ap.map(([t, cpu, mem, clients, c24, c5]) => ({ t, cpu, mem, clients, c24, c5 }))) };
  }
  if (kind === 'fb') {
    const byT = new Map();
    for (const [k, list] of Object.entries(h.metrics || {})) {
      for (const [t, v] of list) (byT.get(t) || byT.set(t, { t }).get(t))[k] = v;
    }
    return { fb: grCrc(gap([...byT.values()].sort((a, b) => a.t - b.t))) };
  }
  const ports = {};
  for (const [p, list] of Object.entries(h.ports)) ports[p] = gap(list.map(([t, rx, tx]) => ({ t, rx, tx })));
  return { ports };
}

function grDomain() {
  const kind = grKind(GR.dev);
  if (GR.range === 'live') {
    // Fenster wächst mit den Messungen bis zur vollen Breite, damit man nicht auf eine leere Fläche schaut
    const S = GR.samples;
    const last = S.length ? S[S.length - 1].t : Date.now() / 1000;
    const first = S.length ? S[kind === 'sw' && S.length > 1 ? 1 : 0].t : last;
    return [Math.min(last - 60, Math.max(last - GR_LIVE_WIN[kind], first)), last];
  }
  return GR.hist ? [GR.hist.from, GR.hist.to] : [Date.now() / 1000 - 3600, Date.now() / 1000];
}

const grClock = (t, range) => {
  const dt = new Date(t * 1000);
  const two = (n) => String(n).padStart(2, '0');
  if (range === '7d') return `${two(dt.getDate())}.${two(dt.getMonth() + 1)}. ${two(dt.getHours())}:${two(dt.getMinutes())}`;
  return `${two(dt.getHours())}:${two(dt.getMinutes())}` + (range === 'live' ? ':' + two(dt.getSeconds()) : '');
};
const grTick = (t, range) => {
  const dt = new Date(t * 1000);
  const two = (n) => String(n).padStart(2, '0');
  if (range === '7d') return `${two(dt.getDate())}.${two(dt.getMonth() + 1)}.`;
  return `${two(dt.getHours())}:${two(dt.getMinutes())}` + (range === 'live' ? ':' + two(dt.getSeconds()) : '');
};
const grPct = (v) => (v == null ? '–' : Math.round(v) + ' %');
const grNum = (v) => (v == null ? '–' : (Math.round(v * 10) / 10).toLocaleString('de-DE'));

// Ein kleines Diagramm (SVG) für eine Zeile: keys = [[schlüssel, gefüllt?]]
function grChart(list, keys, x, w, h, yMaxMin, fmt) {
  const vals = list.flatMap((p) => keys.map(([k]) => p[k])).filter((v) => v != null);
  const max = Math.max(yMaxMin, d3.max(vals) || 0);
  const y = d3.scaleLinear().domain([0, max]).nice(3).range([h - 2, 6]);
  const ok = (k) => (p) => !p.gap && p[k] != null;
  let paths = '';
  for (const [k, fill] of keys) {
    if (fill) {
      const area = d3.area().defined(ok(k)).x((p) => x(p.t)).y0(y(0)).y1((p) => y(p[k])).curve(d3.curveMonotoneX);
      paths += `<path class="gr-area" fill="${GR_C[k]}" d="${area(list) || ''}"/>`;
    }
    const line = d3.line().defined(ok(k)).x((p) => x(p.t)).y((p) => y(p[k])).curve(d3.curveMonotoneX);
    paths += `<path class="gr-line" stroke="${GR_C[k]}" d="${line(list) || ''}"/>`;
  }
  const top = y.domain()[1];
  return `<svg class="gr-svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">
    <line class="gr-grid" x1="0" x2="${w}" y1="${y(top)}" y2="${y(top)}"/><line class="gr-base" x1="0" x2="${w}" y1="${y(0)}" y2="${y(0)}"/>
    <text class="gr-max" x="4" y="${y(top) + 11}">${esc(fmt(top))}</text>
    ${paths}<line class="gr-cross d-none" y1="0" y2="${h}"/></svg>`;
}

function graphRender() {
  const d = GR.dev;
  const box = $('#devGraph');
  if (!d || box.closest('.dev-pane').classList.contains('d-none')) return;
  const kind = grKind(d);
  const ranges = [...(d.swPass ? [['live', 'Live']] : []), ['1h', '1 Std.'], ['24h', '24 Std.'], ['7d', '7 Tage']];
  const live = GR.range === 'live';
  const status = live
    ? GR.paused ? `pausiert · <button type="button" class="btn btn-link btn-sm p-0" data-gr-resume>weiter</button>`
      : `alle ${GR_LIVE_MS[kind] / 1000} s${GR.samples.length ? ' · ' + grClock(GR.samples[GR.samples.length - 1].t, 'live') : ''}`
    : GR.hist ? `je ${GR.hist.step >= 60 ? GR.hist.step / 60 + ' Min.' : GR.hist.step + ' s'} gemittelt` : 'lädt …';
  const legend = kind === 'sw'
    ? `<span class="gr-key"><i style="background:${GR_C.rx}"></i>↓ empfangen am Port</span><span class="gr-key"><i style="background:${GR_C.tx}"></i>↑ gesendet</span>`
    : kind === 'fb' ? `<span class="gr-key"><i style="background:${GR_C.wan_rx}"></i>↓ Download</span><span class="gr-key"><i style="background:${GR_C.wan_tx}"></i>↑ Upload</span>`
    : '';
  let html = `<div class="gr-bar"><div class="btn-group btn-group-sm gr-ranges" role="group">${ranges.map(([k, l]) =>
    `<button type="button" class="btn btn-ghost${GR.range === k ? ' active' : ''}" data-gr-range="${k}">${k === 'live' ? '<span class="gr-dot"></span>' : ''}${l}</button>`).join('')}</div>
    <div class="gr-legend">${legend}</div><div class="gr-status">${status}${GR.msg ? ` · <span class="${GR.msg === 'verbinde …' ? '' : 'text-danger'}">${esc(GR.msg)}</span>` : ''}</div></div>`;
  if (live && kind === 'ap') {
    html += `<div class="gr-note">Live meldet sich alle ${GR_LIVE_MS.ap / 1000} s am Access Point an – eine offene Tether-App oder Weboberfläche wird dabei abgemeldet.</div>`;
  }

  const S = grSeries();
  const wrap = box.clientWidth || 800;
  const narrow = wrap < 640;
  const labelW = narrow ? 0 : 170, valW = narrow ? 0 : 128;
  const w = Math.max(160, wrap - labelW - valW - (narrow ? 0 : 24));
  const dom = grDomain();
  const x = d3.scaleLinear().domain(dom).range([0, w]);
  GR.x = x; GR.w = w;

  let rows = [];
  if (kind === 'sw') {
    const parent = devData.devices.find((v) => v.id === d.parentId);
    const ports = (d.ports || []).filter((p) => p.link || S?.ports?.[p.p]?.some((q) => q.rx != null || q.tx != null));
    rows = ports.map((p) => {
      const who = p.p === d.uplink ? '↑ ' + (parent ? devTitle(parent) : 'Uplink') : (d.onPort[p.p] || []).join(', ') || (p.desc ? `„${p.desc}“` : '');
      return { key: 'p' + p.p, list: S?.ports?.[p.p] || [], keys: [['rx', true], ['tx', false]], min: 1000, fmt: fmtBps,
        label: `<span class="port-sq ${portClass(p)}">${p.p}</span><span class="gr-who">${esc(who || 'Port ' + p.p)}<small>${esc(p.link ? p.speed || 'Link' : 'kein Link')}</small></span>`,
        val: (q) => `↓ ${fmtBps(q?.rx)}<br>↑ ${fmtBps(q?.tx)}` };
    });
  } else if (kind === 'fb') {
    const list = S?.fb || [];
    const mb = (v) => fmtBps(v);
    const db = (v) => (v == null ? '–' : String(Math.round(v * 10) / 10).replace('.', ',') + ' dB');
    const last = [...list].reverse().find((q) => !q.gap && q.max_down);
    rows = [
      { key: 'wan', list, keys: [['wan_rx', true], ['wan_tx', false]], min: 1e6, fmt: mb,
        label: `<span class="gr-who">Internet<small>aktuelle Rate</small></span>`, val: (q) => `↓ ${mb(q?.wan_rx)}<br>↑ ${mb(q?.wan_tx)}` },
      { key: 'sync', list, keys: [['dsl_down', false], ['dsl_up', false]], min: 1e6, fmt: mb,
        label: `<span class="gr-who">DSL-Sync<small>${last ? 'möglich ↓ ' + esc(mb(last.max_down)) : 'Leitungsrate'}</small></span>`,
        val: (q) => `↓ ${mb(q?.dsl_down)}<br>↑ ${mb(q?.dsl_up)}` },
      { key: 'snr', list, keys: [['snr_down', false], ['snr_up', false]], min: 12, fmt: db,
        label: `<span class="gr-who">Störabstand<small>unter 6 dB wird es knapp</small></span>`, val: (q) => `↓ ${db(q?.snr_down)}<br>↑ ${db(q?.snr_up)}` },
      { key: 'crc', list, keys: [['crc', false]], min: 1, fmt: (v) => grNum(v) + '/Min.',
        label: `<span class="gr-who">CRC-Fehler<small><span class="gr-key"><i style="background:${GR_C.crc}"></i>je Minute</span></small></span>`,
        val: (q) => `${grNum(q?.crc)} / Min.` },
    ].filter((r) => r.key === 'sync' || r.list.some((q) => r.keys.some(([k]) => q?.[k] != null)));   // Speedport: nur was er liefert
  } else {
    const list = S?.ap || [];
    rows = [
      { key: 'load', list, keys: [['cpu', false], ['mem', false]], min: 100, fmt: (v) => v + ' %',
        label: `<span class="gr-who">Auslastung<small><span class="gr-key"><i style="background:${GR_C.cpu}"></i>CPU</span> <span class="gr-key"><i style="background:${GR_C.mem}"></i>Speicher</span></small></span>`,
        val: (q) => `CPU ${grPct(q?.cpu)}<br>Speicher ${grPct(q?.mem)}` },
      { key: 'cl', list, keys: [['c5', false], ['c24', false]], min: 4, fmt: (v) => v + ' Clients',
        label: `<span class="gr-who">WLAN-Clients<small><span class="gr-key"><i style="background:${GR_C.c5}"></i>5 GHz</span> <span class="gr-key"><i style="background:${GR_C.c24}"></i>2,4 GHz</span></small></span>`,
        val: (q) => `5 GHz ${grNum(q?.c5)}<br>2,4 GHz ${grNum(q?.c24)}` },
    ];
  }
  GR.rows = rows;
  const H = kind === 'ap' ? 110 : kind === 'fb' ? 76 : 58;
  const empty = (r) => !r.list.some((q) => !q.gap && r.keys.some(([k]) => q[k] != null));
  if (!rows.length || rows.every(empty)) {
    const why = live ? (GR.paused ? 'Live-Ansicht pausiert.' : kind === 'sw' ? 'Erste Messwerte kommen nach zwei Abfragen …' : 'Warte auf die erste Abfrage …')
      : !GR.hist ? '' : 'Noch kein Verlauf – netmon sammelt ab jetzt jede Minute Werte.';
    html += `<div class="gr-empty">${why}</div>`;
  }
  html += `<div class="gr-rows${narrow ? ' narrow' : ''}">` + rows.map((r) => {
    const last = [...r.list].reverse().find((q) => !q.gap && r.keys.some(([k]) => q[k] != null));
    return `<div class="gr-row" data-gr-row="${r.key}"><div class="gr-label">${r.label}</div>
      <div class="gr-plot">${grChart(r.list, r.keys, x, w, H, r.min, r.fmt)}</div>
      <div class="gr-val mono" data-gr-val>${r.val(last)}</div></div>`;
  }).join('') + '</div>';
  // Zeitachse
  const ticks = x.ticks(narrow ? 3 : 6);
  html += `<div class="gr-axis${narrow ? ' narrow' : ''}"><div></div><svg width="${w}" height="18">${ticks.map((t) =>
    `<text x="${x(t)}" y="12" text-anchor="middle">${grTick(t, GR.range)}</text>`).join('')}</svg><div class="gr-hovertime mono"></div></div>`;
  box.innerHTML = html;
  if (GR.hoverT != null) grHover(GR.hoverT);
}

// Fadenkreuz über alle Zeilen: Werte zur Zeit t rechts anzeigen
function grHover(t) {
  GR.hoverT = t;
  const box = $('#devGraph');
  const bis = d3.bisector((q) => q.t).center;
  let shown = null;
  for (const r of GR.rows || []) {
    const row = box.querySelector(`[data-gr-row="${r.key}"]`);
    if (!row) continue;
    const cross = row.querySelector('.gr-cross');
    const val = row.querySelector('[data-gr-val]');
    if (t == null) {
      cross.classList.add('d-none');
      const last = [...r.list].reverse().find((q) => !q.gap && r.keys.some(([k]) => q[k] != null));
      val.innerHTML = r.val(last);
      continue;
    }
    const pts = r.list.filter((q) => !q.gap);
    const q = pts.length ? pts[bis(pts, t)] : null;
    const near = q && Math.abs(GR.x(q.t) - GR.x(t)) < 24 ? q : null;
    cross.classList.remove('d-none');
    cross.setAttribute('x1', GR.x(near ? near.t : t));
    cross.setAttribute('x2', GR.x(near ? near.t : t));
    val.innerHTML = r.val(near);
    if (near) shown = near.t;
  }
  const ht = box.querySelector('.gr-hovertime');
  if (ht) ht.textContent = t == null ? '' : grClock(shown ?? t, GR.range);
}

$('#devGraph').addEventListener('click', (e) => {
  const b = e.target.closest('[data-gr-range]');
  if (b) {
    GR.range = b.dataset.grRange;
    store.set('netmon-graph-range', GR.range);
    GR.hist = null; GR.hoverT = null;
    graphStop();
    return graphShow();
  }
  if (e.target.closest('[data-gr-resume]')) { graphStop(); graphLiveStart(); }
});
$('#devGraph').addEventListener('mousemove', (e) => {
  const svg = e.target.closest('.gr-svg');
  if (!svg || !GR.x) return;
  const rx = e.clientX - svg.getBoundingClientRect().left;
  grHover(GR.x.invert(Math.max(0, Math.min(GR.w, rx))));
});
$('#devGraph').addEventListener('mouseleave', () => grHover(null));
window.addEventListener('resize', () => {
  clearTimeout(GR._rt);
  GR._rt = setTimeout(graphRender, 200);
});

// Port in der Frontansicht überfahren: Infos + Verkehr der letzten Stunde als Mini-Diagramm
function grSpark(port) {
  const list = GR.hist1h?.ports?.[port];
  if (!list || list.length < 2) return '';
  const pts = list.map(([t, rx, tx]) => ({ t, rx, tx }));
  const w = 240, h = 46;
  const x = d3.scaleLinear().domain([GR.hist1h.from, GR.hist1h.to]).range([0, w]);
  const peakRx = d3.max(pts, (p) => p.rx) || 0, peakTx = d3.max(pts, (p) => p.tx) || 0;
  return `<div class="it"><div class="sub mb-1">letzte Stunde · Spitze ↓ ${fmtBps(peakRx)} ↑ ${fmtBps(peakTx)}</div>
    ${grChart(pts, [['rx', true], ['tx', false]], x, w, h, 1000, fmtBps)}</div>`;
}
$('#devPanel').addEventListener('mouseover', (e) => {
  const j = e.target.closest('.rj');
  if (!j || j.contains(e.relatedTarget)) return;
  const [head, ...rest] = (j.dataset.tip || '').split('\n');
  showCableTip(`<div class="hd">${esc(head)}</div>${rest.map((l) => `<div class="sub">${esc(l)}</div>`).join('')}${grSpark(Number(j.dataset.port))}`, e);
});
$('#devPanel').addEventListener('mousemove', (e) => { if (e.target.closest('.rj')) moveCableTip(e); });
$('#devPanel').addEventListener('mouseout', (e) => {
  const j = e.target.closest('.rj');
  if (j && !j.contains(e.relatedTarget)) cableTip.classList.add('d-none');
});

// Port-Beschreibungen mit Geräten, Switch-Namen und Räumen abgleichen
function portSuggestions(sw) {
  const norm = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9äöüß]/g, '');
  const all = devData.devices;
  const byId = new Map(all.map((x) => [x.id, x]));
  const names = (x) => [x.name, x.hostname, x.swInfo?.name].map(norm).filter(Boolean);
  const anc = (x) => { const s = new Set(); for (let p = x.parentId, n = 0; p && n < 50; p = byId.get(p)?.parentId, n++) s.add(p); return s; };
  const INFRA = ['switch', 'ap', 'router'];
  const swNames = [...names(sw), norm(sw.location)].filter(Boolean);
  const out = [];
  // Router (FritzBox): die Heimnetz-Liste sagt, welche Geräte an welcher LAN-Buchse aktiv sind
  const lan = sw.swInfo?.lanHosts || {};
  for (const p of sw.ports || []) {
    const macs = new Set(lan[p.p] || []);
    if (!macs.size) continue;
    const here = all.filter((x) => x.id !== sw.id && x.mac && macs.has(x.mac.toUpperCase()));
    let c = here.filter((x) => ['switch', 'ap'].includes(x.type));
    // Was hinter einem anderen Gerät dieser Liste hängt, sitzt nicht direkt an der Buchse
    c = (c.length ? c : here).filter((x) => !here.some((y) => y !== x && anc(x).has(y.id)));
    if (c.length > 1 && c.filter((x) => x.type === 'switch').length === 1) c = c.filter((x) => x.type === 'switch');
    if (c.length !== 1) continue;
    const o = c[0];
    if (o.parentId === sw.id && String(o.port ?? '') === String(p.p)) continue;
    // Hängt es schon über einen Pseudo-Switch (ohne IP, unsichtbar für die FritzBox) an diesem Router? Dann passt es.
    let v = byId.get(o.parentId), hops = 0;
    while (v && v.virtual && v.parentId !== sw.id && hops++ < 20) v = byId.get(v.parentId);
    if (v && v.virtual && v.parentId === sw.id && (!v.port || String(v.port) === String(p.p))) continue;
    out.push({ key: `${sw.id}:${p.p}:mac:${o.id}`, a: { child: o.id, parent: sw.id, port: p.p, uplink: '' },
      text: `<b>${esc(devTitle(o))}</b> hängt laut ${esc(devTitle(sw))} an LAN ${p.p}${here.length > 1 ? ` <span class="text-body-secondary">(dahinter ${here.length - 1} weitere)</span>` : ''}` });
  }
  for (const p of sw.ports || []) {
    const D = norm(p.desc);
    if (!D) continue;
    let c = all.filter((x) => x.id !== sw.id && names(x).includes(D));
    if (!c.length) {
      // Raum als Beschreibung: nur Switch/AP/Router dort – aber keins, das schon an einem anderen Port dieses Switches hängt
      c = all.filter((x) => x.id !== sw.id && norm(x.location) === D && INFRA.includes(x.type)
        && !(x.parentId === sw.id && x.port && String(x.port) !== String(p.p)));
    }
    if (c.length > 1) c = c.filter((x) => x.parentId === sw.id || x.id === sw.parentId);   // Gleichstand: was schon am Switch hängt
    if (c.length !== 1) continue;
    const o = c[0];
    const cp = (o.ports || []).find((q) => q.desc && swNames.includes(norm(q.desc)));   // Gegenstelle am anderen Switch
    let up = null;   // true = o liegt oberhalb (Richtung Router)
    if (sw.uplink === p.p) up = true;
    else if (cp && o.uplink === cp.p) up = false;
    else if (anc(sw).has(o.id)) up = true;
    else if (anc(o).has(sw.id)) up = false;
    const below = { child: o.id, parent: sw.id, port: p.p, uplink: cp?.p ?? '' };
    const above = { child: sw.id, parent: o.id, port: cp?.p ?? '', uplink: p.p };
    const done = (v) => byId.get(v.child)?.parentId === v.parent && String(byId.get(v.child)?.port ?? '') === String(v.port)
      && (v.uplink === '' || byId.get(v.child)?.uplink === v.uplink);
    if (done(up ? above : below)) continue;
    const q = `<span class="text-body-secondary">„${esc(p.desc)}“</span>`;
    const textBelow = `<b>${esc(devTitle(o))}</b> hängt an Port ${p.p} ${q}${cp ? `, dort an Port ${cp.p}` : ''}`;
    const textAbove = `<b>${esc(devTitle(sw))}</b> hängt über Port ${p.p} ${q} an <b>${esc(devTitle(o))}</b>${cp ? ` (Port ${cp.p})` : ''}`;
    // Schlüssel fürs Ignorieren: ändert sich die Beschreibung, ist es ein neuer Vorschlag
    const key = `${sw.id}:${p.p}:${D}:${o.id}`;
    out.push(up
      ? { key, text: textAbove, a: above }
      : { key, text: textBelow, a: below, alt: up === null ? above : null, altText: textAbove.replace(/<[^>]+>/g, '') });
  }
  return out;
}

let showHiddenSug = false;
$('#devPanel').addEventListener('click', async (e) => {
  if (e.target.closest('[data-sug-toggle]')) { showHiddenSug = !showHiddenSug; renderPorts(currentDev); return; }
  const hide = e.target.closest('[data-sug-hide], [data-sug-undo]');
  if (hide) {
    const s = $('#devPanel')._sug[Number(hide.dataset.sugHide ?? hide.dataset.sugUndo)];
    try {
      const r = await post('dismiss_suggestion', { key: s.key, undo: hide.dataset.sugUndo !== undefined ? 1 : 0 });
      devData.dismissed = r.dismissed;
      renderPorts(currentDev);
    } catch (err) {
      $('#devError').textContent = err.message;
    }
    return;
  }
  const jack = e.target.closest('.rj');
  if (jack) {
    const row = $('#devPorts').querySelector(`tr[data-row="${jack.dataset.port}"]`);
    row?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    row?.classList.add('hl');
    setTimeout(() => row?.classList.remove('hl'), 1200);
    return;
  }
  const b = e.target.closest('[data-sug]');
  if (!b) return;
  const s = $('#devPanel')._sug[Number(b.dataset.sug)];
  const v = b.dataset.dir === 'b' ? s.alt : s.a;
  try {
    await post('connect', v);
    notify('Verbunden');
    await loadDevices();
    currentDev = devData.devices.find((x) => x.id === currentDev.id) || currentDev;
    $('#devForm').parentId.value = currentDev.parentId ?? '';
    $('#devForm').port.value = currentDev.port ?? '';
    if ($('#devForm').swUplink) $('#devForm').swUplink.value = currentDev.uplink ?? '';
    renderPorts(currentDev);
    if (treeData) loadTree();
  } catch (err) {
    $('#devError').textContent = err.message;
  }
});

// Zugangsfelder nur bei Switches mit IP
function syncSwBox() {
  const f = $('#devForm');
  const show = currentDev && !currentDev.virtual && currentDev.ip && ['switch', 'ap', 'router'].includes(f.type.value);
  $('#swBox').classList.toggle('d-none', !show);
  if (show) fillDriverSelect(currentDev);
}
$('#fType').addEventListener('change', () => { if ($('#swBox')) syncSwBox(); });
// Benutzerfeld nur für Treiber, die einen Benutzernamen brauchen (FritzBox)
function syncSwUser() {
  const f = $('#devForm');
  const drv = (swDrivers || []).find((x) => x.key === f.swDriver.value);
  const guess = f.swDriver.value === 'auto' && /fritz|avm/i.test([f.name.value, currentDev?.vendor, currentDev?.hostname].join(' '));
  $('#swUserRow').classList.toggle('d-none', !(drv?.user || guess || f.swUser.value));
  // Speedport & Co.: Daten ohne Anmeldung lesbar – Passwort freiwillig (dann kann netmon auch schalten) oder gar keins
  const noPass = drv && drv.pass === false && !drv.passOptional;
  $('#swPassCol').classList.toggle('invisible', noPass);
  if (noPass) f.swPass.value = '';
  f.swPass.placeholder = drv?.passOptional ? (currentDev?.swRealPass ? 'gespeichert – leer lassen' : 'freiwillig – nur zum Schalten') : '';
}
$('#fSwDriver')?.addEventListener('change', syncSwUser);

$('#swPoll')?.addEventListener('click', async () => {
  const f = $('#devForm');
  const btn = $('#swPoll');
  btn.disabled = true;
  $('#swMsg').textContent = 'frage ab …';
  $('#devError').textContent = '';
  try {
    const r = await post('switch_poll', { id: currentDev.id, swPass: f.swPass.value, swDriver: f.swDriver.value, swUser: f.swUser.value });
    $('#swMsg').textContent = r.ports ? `${r.up}/${r.ports} Ports mit Link` : r.clients !== null ? `${r.clients} WLAN-Clients` : 'verbunden';
    f.swPass.value = '';
    await loadDevices();
    currentDev = devData.devices.find((x) => x.id === currentDev.id) || currentDev;
    f.swPass.placeholder = currentDev.swPass ? 'gespeichert – leer lassen' : '';
    $('#swRemove').classList.toggle('d-none', !currentDev.swPass);
    fillDriverSelect(currentDev);
    renderSwInfo(currentDev);
    renderPorts(currentDev);
    graphInit(currentDev);
    if (treeData) loadTree();
  } catch (err) {
    $('#swMsg').textContent = '';
    $('#devError').textContent = err.message;
  } finally {
    btn.disabled = false;
  }
});
// Port an den Schnellzugriff heften
$('#devPorts').addEventListener('click', async (e) => {
  const b = e.target.closest('[data-qpin]');
  if (!b || !currentDev) return;
  try {
    const r = await post('quick_pin', { id: currentDev.id, port: Number(b.dataset.qpin), pin: b.dataset.pinned === '1' ? 0 : 1 });
    devData.quickPorts = r.pinned;
    renderPorts(currentDev);
  } catch (err) { $('#devError').textContent = err.message; }
});
// Port sperren / freigeben
$('#devPorts').addEventListener('click', async (e) => {
  const b = e.target.closest('[data-portoff]');
  if (!b || !currentDev) return;
  const port = Number(b.dataset.portoff);
  const on = b.dataset.on === '1';
  b.disabled = true;
  b.textContent = on ? 'gebe frei …' : 'sperre …';
  try {
    await post('switch_port', { id: currentDev.id, port, on: on ? 1 : 0 });
    notify(`Port ${port} ${on ? 'freigegeben' : 'gesperrt'}`);
    await loadDevices();
    currentDev = devData.devices.find((x) => x.id === currentDev.id) || currentDev;
    renderPorts(currentDev);
    if (treeData) loadTree();
  } catch (err) {
    $('#devError').textContent = err.message;
    b.disabled = false;
    b.textContent = on ? '🔓 Freigeben' : '🔒 Sperren';
  }
});
$('#devPorts').addEventListener('click', async (e) => {
  const b = e.target.closest('[data-cable]');
  if (!b || !currentDev) return;
  const port = Number(b.dataset.cable);
  const who = (currentDev.onPort[port] || []).join(', ');
  if (!confirm(`Kabel an Port ${port}${who ? ' (' + who + ')' : ''} testen?\n\nDer Port ist dabei ein paar Sekunden ohne Verbindung.`)) return;
  b.disabled = true;
  b.textContent = 'teste …';
  try {
    const r = await post('switch_cable', { id: currentDev.id, port });
    notify(`Port ${port}: ${r.result}${r.dist && r.dist !== '0' ? ' · ' + r.dist + ' m' : ''}`);
    await loadDevices();
    currentDev = devData.devices.find((x) => x.id === currentDev.id) || currentDev;
    renderPorts(currentDev);
  } catch (err) {
    $('#devError').textContent = err.message;
    b.disabled = false;
    b.textContent = '⌁ Kabeltest';
  }
});

$('#swRemove')?.addEventListener('click', () => {
  if (!confirm('Passwort und Port-Daten dieses Switches entfernen?')) return;
  $('#devForm').swPass.value = '-';
  $('#devForm').requestSubmit();
});

// Gerät ohne IP anlegen: unmanaged Switch, geplanter Access Point, Netzwerkdose …
async function newVirtualDevice() {
  if (!devData) await loadDevices();
  currentDev = null;
  const f = $('#devForm');
  f.reset();
  f.id.value = '';
  f.name.placeholder = 'z. B. Switch Kinderzimmer';
  f.type.innerHTML = '<option value="">–</option>' + devData.types.map((t) => `<option value="${t}" ${t === 'switch' ? 'selected' : ''}>${esc(typeLabel(t))}</option>`).join('');
  f.parentId.innerHTML = '<option value="">direkt am Router</option>'
    + devData.parents.map((p) => `<option value="${p.id}">${esc(p.label)}</option>`).join('');
  $('#devModalTitle').textContent = 'Gerät ohne IP';
  $('#devModalSub').innerHTML = '<span class="dot virt"></span> wird nicht gescannt';
  $('#devFacts').innerHTML = '<dd class="text-body-secondary mb-0">Für Geräte, die netmon nicht finden kann: unmanaged Switches, '
    + 'geplante Access Points, Netzwerkdosen. Andere Geräte können daran hängen – so planst du die Struktur.</dd>';
  $('#devEvents').classList.add('d-none');
  $('#devDelete')?.classList.add('d-none');
  $('#swBox')?.classList.add('d-none');
  renderPorts(null);
  renderSwInfo(null);
  graphInit(null);
  $('#devTabs').classList.add('d-none');
  devTab('set');
  $('#devError').textContent = '';
  modal.show();
  setTimeout(() => f.name.focus(), 300);
}
$('#devAddVirtual')?.addEventListener('click', newVirtualDevice);

$('#devForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!IS_ADMIN) return;
  const data = Object.fromEntries(new FormData(e.target).entries());
  try {
    await post(data.id ? 'save_device' : 'create_device', data);
    modal.hide();
    notify('Gespeichert');
    await refresh(true);
  } catch (err) {
    $('#devError').textContent = err.message;
  }
});

$('#devDelete')?.addEventListener('click', async () => {
  if (!currentDev || !confirm('Gerät samt seiner Ereignisse löschen? Taucht es wieder auf, wird es neu angelegt.')) return;
  try {
    await post('delete_device', { id: currentDev.id });
    modal.hide();
    notify('Gelöscht');
    await refresh(true);
  } catch (err) {
    $('#devError').textContent = err.message;
  }
});


// ---------------------------------------------------------------
// Einstellungen (Admin: alles, Betrachter: nur eigenes Passwort)
// ---------------------------------------------------------------
let setData = null;
const ZONE_TYPES = { kabel: 'Kabel', wlan: 'WLAN', labor: 'Labor', dhcp: 'DHCP' };

async function loadSettings() {
  if (!IS_ADMIN) return;
  const [sd, users] = await Promise.all([api('settings'), api('users')]);
  setData = sd;
  const f = $('#setForm');
  for (const [k, v] of Object.entries(sd.settings)) {
    const el = f.elements[k];
    if (!el) continue;
    if (el.type === 'checkbox') el.checked = !!Number(v); else el.value = v;
  }
  syncSpeedFields();
  $('#setError').textContent = '';

  $('#zoneList').innerHTML = sd.zones.map((z) => `<div class="item">
      <span class="dot" style="background:var(--nm-zone)"></span>
      <div class="main"><div class="text-truncate">${esc(z.name)}</div>
        <div class="meta mono">${esc(z.from)} – ${esc(lastOctet(z.to))} · ${esc(ZONE_TYPES[z.type] || z.type)} · ${z.devices} Geräte</div></div>
      <button class="btn btn-ghost btn-sm" type="button" data-zone="${z.id}">Bearbeiten</button></div>`).join('')
    || '<div class="text-body-secondary small">Noch keine Bereiche. Ohne Bereiche hängen alle Geräte direkt am Router.</div>';

  $('#userList').innerHTML = users.map((u) => `<div class="item">
      <span class="avatar" style="width:2rem;height:2rem;font-size:.85rem">${esc(u.username.slice(0, 1))}</span>
      <div class="main"><div class="text-truncate">${esc(u.username)}${u.self ? ' <span class="pill pill-accent ms-1">Du</span>' : ''}</div>
        <div class="meta">${{ admin: 'Admin', family: 'Familie', viewer: 'Betrachter' }[u.role] || u.role} · ${u.mode === 'einfach' ? 'einfache Ansicht' : 'Profi-Ansicht'} · zuletzt angemeldet ${u.lastLogin ? esc(fmtTime(u.lastLogin, true)) : 'noch nie'}</div></div>
      <button class="btn btn-ghost btn-sm" type="button" data-user="${u.id}">Bearbeiten</button></div>`).join('');
  setData.users = users;
}

function syncSpeedFields() {
  const on = $('#setForm').elements.speedtest_enabled.checked;
  $('#setForm').elements.speedtest_interval.disabled = !on;
}

if (IS_ADMIN) {
  $('#setForm').elements.speedtest_enabled.addEventListener('change', syncSpeedFields);

  $('#setForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const data = {};
    for (const el of f.elements) {
      if (!el.name) continue;
      data[el.name] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
    }
    const before = setData?.settings || {};
    try {
      await post('save_settings', data);
      $('#setError').textContent = '';
      // Speedtest oder Animationen verändern den Seitenaufbau -> neu laden
      if (Number(before.speedtest_enabled) !== data.speedtest_enabled || Number(before.animations) !== data.animations
          || before.subnet !== data.subnet) {
        store.set('netmon-tab', '#tabSettings');
        location.reload();
        return;
      }
      notify('Einstellungen gespeichert');
      await loadSettings();
      if (String(before.hide_offline_days) !== String(data.hide_offline_days)) loadTree();
    } catch (err) {
      $('#setError').textContent = err.message;
    }
  });

  const runNow = async (what, label) => {
    try {
      await post('run_now', { what });
      notify(`${label} ist angefordert und startet innerhalb einer Minute`);
    } catch (err) { notify(err.message, false); }
  };
  $('#runScan').addEventListener('click', () => runNow('scan', 'Scan'));
  $('#runSpeed')?.addEventListener('click', () => runNow('speedtest', 'Speedtest'));

  // Bereiche
  const zoneModal = new bootstrap.Modal($('#zoneModal'));
  const openZone = (z) => {
    const f = $('#zoneForm');
    f.id.value = z?.id || '';
    f.name.value = z?.name || '';
    f.from.value = z?.from || '';
    f.to.value = z?.to || '';
    f.type.value = z?.type || 'kabel';
    f.parentId.innerHTML = '<option value="">Router (oberste Ebene)</option>' + setData.parents
      .map((p) => `<option value="${p.id}" ${z && p.id === z.parentId ? 'selected' : ''}>${esc(p.label)}</option>`).join('');
    if (!z && setData.parents.length) f.parentId.value = setData.parents[0].id;
    $('#zoneModalTitle').textContent = z ? 'Bereich bearbeiten' : 'Neuer Bereich';
    $('#zoneDelete').classList.toggle('d-none', !z);
    $('#zoneError').textContent = '';
    zoneModal.show();
  };
  $('#zoneAdd').addEventListener('click', () => openZone(null));
  $('#zoneList').addEventListener('click', (e) => {
    const b = e.target.closest('[data-zone]');
    if (b) openZone(setData.zones.find((z) => z.id === Number(b.dataset.zone)));
  });
  $('#zoneForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await post('save_zone', Object.fromEntries(new FormData(e.target).entries()));
      zoneModal.hide();
      notify('Bereich gespeichert');
      await Promise.all([loadSettings(), loadTree()]);
    } catch (err) { $('#zoneError').textContent = err.message; }
  });
  $('#zoneDelete').addEventListener('click', async () => {
    if (!confirm('Bereich löschen? Die Geräte darin hängen danach direkt am Router.')) return;
    try {
      await post('delete_zone', { id: Number($('#zoneForm').id.value) });
      zoneModal.hide();
      notify('Bereich gelöscht');
      await Promise.all([loadSettings(), loadTree()]);
    } catch (err) { $('#zoneError').textContent = err.message; }
  });

  // Benutzer
  const userModal = new bootstrap.Modal($('#userModal'));
  const openUser = (u) => {
    const f = $('#userForm');
    f.id.value = u?.id || '';
    f.username.value = u?.username || '';
    f.username.disabled = !!u;
    f.role.value = u?.role || 'family';
    f.mode.value = u?.mode || 'einfach';
    f.password.value = '';
    $('#uPassHint').textContent = u ? 'Leer lassen, um das Passwort nicht zu ändern.' : 'Mindestens 8 Zeichen.';
    $('#userModalTitle').textContent = u ? 'Benutzer bearbeiten' : 'Neuer Benutzer';
    $('#userDelete').classList.toggle('d-none', !u || u.self);
    $('#userError').textContent = '';
    userModal.show();
  };
  $('#userAdd').addEventListener('click', () => openUser(null));
  // Neue Rolle gewählt: passende Ansicht vorschlagen (Admin → Profi, sonst Einfach)
  $('#userForm').role.addEventListener('change', (e) => { e.target.form.mode.value = e.target.value === 'admin' ? 'profi' : 'einfach'; });
  $('#userList').addEventListener('click', (e) => {
    const b = e.target.closest('[data-user]');
    if (b) openUser(setData.users.find((u) => u.id === Number(b.dataset.user)));
  });
  $('#userForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    try {
      await post('save_user', { id: f.id.value, username: f.username.value, role: f.role.value, mode: f.mode.value, password: f.password.value });
      userModal.hide();
      notify('Benutzer gespeichert');
      await loadSettings();
    } catch (err) { $('#userError').textContent = err.message; }
  });
  $('#userDelete').addEventListener('click', async () => {
    if (!confirm('Benutzer löschen?')) return;
    try {
      await post('delete_user', { id: Number($('#userForm').id.value) });
      userModal.hide();
      notify('Benutzer gelöscht');
      await loadSettings();
    } catch (err) { $('#userError').textContent = err.message; }
  });
}

// Eigenes Passwort (alle Benutzer)
$('#pwForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await post('change_password', { old: e.target.old.value, new: e.target.new.value });
    e.target.reset();
    $('#pwError').textContent = '';
    notify('Passwort geändert');
  } catch (err) { $('#pwError').textContent = err.message; }
});

// ---------------------------------------------------------------
// Start und regelmäßiges Aktualisieren
// ---------------------------------------------------------------
async function refresh(withSpeed = false) {
  try {
    await Promise.all([loadStatus(), loadTree(), loadEvents(), loadDevices(), withSpeed ? loadSpeed() : null]);
  } catch (err) {
    notify('Daten konnten nicht geladen werden: ' + err.message, false);
  }
}

document.querySelectorAll('.refresh-btn').forEach((btn) => btn.addEventListener('click', async () => {
  btn.classList.add('spin');
  await refresh(true);
  if ($('#tabSettings').classList.contains('active')) await loadSettings().catch(() => {});
  btn.classList.remove('spin');
}));

refresh(true);
// Nach einem Neuladen aus den Einstellungen dorthin zurückkehren
if (store.get('netmon-tab')) { const t = store.get('netmon-tab'); store.set('netmon-tab', ''); showTab(t); }
setInterval(() => {
  if (!document.hidden && !document.querySelector('.modal.show')) refresh(false);
}, 60_000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(false); });

// Sobald die Schriften geladen sind, Baum neu vermessen (Textbreiten ändern sich minimal)
if (document.fonts?.ready) document.fonts.ready.then(() => { if (treeData) drawNetwork(); });
