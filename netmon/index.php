<?php
require __DIR__ . '/db.php';
$me      = require_login_page();
$s       = settings();
$speedOn = (bool) $s['speedtest_enabled'];
$admin   = $me['role'] === 'admin';
$e       = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$nm      = [
    'role' => $me['role'], 'user' => $me['username'], 'csrf' => csrf_token(),
    'speedOn' => $speedOn, 'animations' => (bool) $s['animations'],
];

// Symbole (Linienzeichnung im 24er-Raster)
$ico = [
    'net'    => '<circle cx="5" cy="12" r="2.2"/><circle cx="19" cy="5" r="2.2"/><circle cx="19" cy="19" r="2.2"/><path d="M7 11l10-5M7 13l10 5"/>',
    'speed'  => '<path d="M4 18a8 8 0 1 1 16 0M12 18l4-6"/>',
    'events' => '<path d="M12 7v5l3 2"/><circle cx="12" cy="12" r="8.5"/>',
    'dev'    => '<rect x="4" y="4" width="16" height="11" rx="1.5"/><path d="M9 20h6M12 15v5"/>',
    'set'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'sun'    => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'moon'   => '<path d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9z"/>',
    'reload' => '<path d="M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6"/>',
    'out'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'list'   => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'room'   => '<path d="M3 21h18"/><path d="M6 21V4h12v17"/><path d="M14.5 12.5h.01"/>',
    'tree'   => '<circle cx="5" cy="12" r="2"/><circle cx="19" cy="5" r="2"/><circle cx="19" cy="12" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 12h10M7 11l10-5M7 13l10 5"/>',
    'online' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    'off'    => '<path d="M6 6l12 12M18 6 6 18"/>',
    'new'    => '<path d="M12 5v14M5 12h14"/>',
    'clock'  => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3 2"/>',
    'down'   => '<path d="M12 4v14M6 12l6 6 6-6"/>',
    'up'     => '<path d="M12 20V6M6 12l6-6 6 6"/>',
    'ping'   => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
    'devs'   => '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8"/>',
];
$svg = fn($k) => '<svg viewBox="0 0 24 24" aria-hidden="true">' . $ico[$k] . '</svg>';

$views = [['#tabTree', 'Netzwerk', 'net']];
if ($speedOn) { $views[] = ['#tabSpeed', 'Speedtest', 'speed']; }
$views[] = ['#tabEvents', 'Ereignisse', 'events'];
$views[] = ['#tabDevices', 'Geräte', 'dev'];
$views[] = ['#tabSettings', 'Einstellungen', 'set'];
?>
<!doctype html>
<html lang="de" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">  <!-- netmon hat eigenes Dunkel/Hell: Browser sollen nicht selbst umfärben (Samsung Internet) -->
  <meta name="theme-color" content="#14151b">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="netmon">
  <link rel="manifest" href="manifest.json">
  <link rel="icon" href="assets/icon-192.png">
  <link rel="apple-touch-icon" href="assets/icon-192.png">
  <title>netmon</title>
  <script>
    // Theme vor dem Rendern setzen, damit nichts aufblitzt (Standard: dunkel wie die Projekt-Timeline)
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
</head>
<body class="<?= $s['animations'] ? '' : 'no-anim' ?>">

<!-- Seitenleiste (PC) -->
<aside class="sidebar d-none d-lg-flex">
  <div class="brand-block">
    <div class="brand"><span class="brand-dot"></span>netmon</div>
    <div class="brand-sub">Netzwerk · Leitung · Geräte</div>
  </div>
  <nav class="side-nav" id="sideNav">
    <span class="eyebrow">Navigation</span>
    <?php foreach ($views as $i => [$t, $label, $k]): ?>
      <button type="button" data-tab="<?= $t ?>" class="<?= $i === 0 ? 'active' : '' ?>"><?= $svg($k) ?><?= $label ?><?php if ($t === '#tabDevices'): ?><span class="count" id="navDevCount"></span><?php endif; ?></button>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <div class="user-box">
      <span class="avatar"><?= $e(mb_substr($me['username'], 0, 1)) ?></span>
      <div class="min-w-0">
        <div class="name text-truncate"><?= $e($me['username']) ?></div>
        <div class="role"><?= $admin ? 'Administrator' : 'Betrachter' ?></div>
      </div>
    </div>
    <div class="side-icons">
      <button class="btn-icon theme-btn" type="button" aria-label="Hell/Dunkel umschalten" title="Hell/Dunkel"><span class="i-moon"><?= $svg('moon') ?></span><span class="i-sun"><?= $svg('sun') ?></span></button>
      <button class="btn-icon refresh-btn" type="button" aria-label="Aktualisieren" title="Aktualisieren"><?= $svg('reload') ?></button>
      <a class="btn-icon danger" href="logout.php" aria-label="Abmelden" title="Abmelden"><?= $svg('out') ?></a>
    </div>
  </div>
</aside>

<div class="app-main">
  <header class="page-head" id="pageHead">
    <div class="d-flex align-items-start gap-2">
      <div class="me-auto min-w-0">
        <div class="d-flex align-items-center flex-wrap gap-2">
          <h1 class="page-title" id="pageTitle">Netzwerk</h1>
          <span class="pill pill-blue"><?= $e($s['subnet']) ?></span>
          <?php if ($admin): ?><span class="pill pill-accent">Admin</span><?php endif; ?>
        </div>
        <div id="lastScan" class="small text-body-secondary text-truncate mt-1"></div>
      </div>
      <div class="d-flex gap-2 d-lg-none">
        <button class="btn-icon refresh-btn" type="button" aria-label="Aktualisieren"><?= $svg('reload') ?></button>
        <button class="btn-icon theme-btn" type="button" aria-label="Hell/Dunkel umschalten"><span class="i-moon"><?= $svg('moon') ?></span><span class="i-sun"><?= $svg('sun') ?></span></button>
      </div>
    </div>
  </header>

  <div class="content">
    <div class="row g-2 g-md-3 mb-4" id="stats">
      <div class="col-6 col-xl-3"><div class="stat-card" data-goto="#tabDevices"><div class="label"><?= $svg('devs') ?>Geräte online</div><div class="value" id="sOnline">–</div><div class="sub" id="sOnlineSub"></div></div></div>
<?php if ($speedOn): ?>
      <div class="col-6 col-xl-3"><div class="stat-card" data-goto="#tabSpeed"><div class="label"><?= $svg('down') ?>Download</div><div class="value" id="sDown">–</div><div class="sub" id="sDownSub"></div></div></div>
      <div class="col-6 col-xl-3"><div class="stat-card" data-goto="#tabSpeed"><div class="label"><?= $svg('up') ?>Upload</div><div class="value" id="sUp">–</div><div class="sub" id="sUpSub"></div></div></div>
      <div class="col-6 col-xl-3"><div class="stat-card" data-goto="#tabSpeed"><div class="label"><?= $svg('ping') ?>Ping</div><div class="value" id="sPing">–</div><div class="sub" id="sPingSub"></div></div></div>
<?php else: ?>
      <div class="col-6 col-xl-3"><div class="stat-card" data-goto="#tabEvents"><div class="label"><?= $svg('off') ?>Offline</div><div class="value" id="sOffline">–</div><div class="sub" id="sOfflineSub"></div></div></div>
      <div class="col-6 col-xl-3"><div class="stat-card" data-goto="#tabEvents"><div class="label"><?= $svg('new') ?>Neu in 24 h</div><div class="value" id="sNew">–</div><div class="sub">erstmals gesehen</div></div></div>
      <div class="col-6 col-xl-3"><div class="stat-card" data-goto="#tabTree"><div class="label"><?= $svg('clock') ?>Letzter Scan</div><div class="value" id="sLast">–</div><div class="sub" id="sLastSub"></div></div></div>
<?php endif; ?>
    </div>

    <div class="tab-content">
      <!-- Netzwerk -->
      <section class="tab-pane fade show active" id="tabTree">
        <div class="nm-tabs" id="viewBtns">
          <button type="button" data-view="tree"><?= $svg('tree') ?>Baum</button>
          <button type="button" data-view="list"><?= $svg('list') ?>Liste</button>
          <button type="button" data-view="rooms"><?= $svg('room') ?>Räume</button>
          <div class="form-check form-switch ms-auto mb-0 align-self-center only-online">
            <input class="form-check-input" type="checkbox" id="onlyOnline">
            <label class="form-check-label small text-nowrap" for="onlyOnline"><span class="d-none d-sm-inline">Nur </span>online</label>
          </div>
        </div>
        <div class="legend small text-body-secondary mb-2">
          <span><span class="dot" style="background:var(--nm-infra)"></span> Router/Switch</span>
          <span id="legendZone"><span class="dot" style="background:var(--nm-zone)"></span> Bereich</span>
          <span><span class="dot on"></span> online</span>
          <span><span class="dot off"></span> offline</span>
          <span class="ms-sm-2" title="Tempo der Leitung (Port am Switch)"><span class="sp" style="background:var(--sp-10)"></span>10M</span>
          <span><span class="sp" style="background:var(--sp-100)"></span>100M</span>
          <span><span class="sp" style="background:var(--sp-1000)"></span>1G</span>
          <span><span class="sp" style="background:var(--sp-x)"></span>2,5G+</span>
        </div>
        <div id="netList"></div>
        <div id="tree"></div>
        <div id="rooms"></div>
      </section>

<?php if ($speedOn): ?>
      <!-- Speedtest -->
      <section class="tab-pane fade" id="tabSpeed">
        <div class="btn-group btn-group-sm w-100 mb-3 range-group" role="group" id="rangeBtns">
          <button class="btn btn-ghost" data-range="24h">24 h</button>
          <button class="btn btn-ghost active" data-range="7d">7 Tage</button>
          <button class="btn btn-ghost" data-range="30d">30 Tage</button>
          <button class="btn btn-ghost" data-range="365d">1 Jahr</button>
        </div>
        <div class="card-box mb-3">
          <div class="box-title">Download und Upload · Mbit/s</div>
          <div class="chart-box"><canvas id="speedChart"></canvas></div>
        </div>
        <div class="card-box mb-3">
          <div class="box-title">Ping · ms</div>
          <div class="chart-box chart-sm"><canvas id="pingChart"></canvas></div>
        </div>
        <div class="row g-2 mb-2" id="speedStats"></div>
        <p class="small text-body-secondary" id="speedInfo"></p>
      </section>
<?php endif; ?>

      <!-- Ereignisse -->
      <section class="tab-pane fade" id="tabEvents">
        <div class="sticky-filter mb-2">
          <div class="d-flex gap-2 mb-2">
            <input class="form-control" type="search" id="evtFilter" placeholder="Gerät, Ort, IP oder Hersteller suchen">
            <select class="form-select w-auto" id="evtSort" aria-label="Sortierung">
              <option value="new">Neueste zuerst</option>
              <option value="old">Älteste zuerst</option>
              <option value="name">Nach Name</option>
              <option value="ip">Nach IP</option>
              <option value="custom" hidden>Nach Spalte</option>
            </select>
          </div>
          <div class="chips" id="evtChips">
            <button class="chip active" data-type="">Alle <span></span></button>
            <button class="chip" data-type="offline">Offline <span></span></button>
            <button class="chip" data-type="online">Wieder online <span></span></button>
            <button class="chip" data-type="neu">Neu <span></span></button>
            <button class="chip" data-type="ip_wechsel">IP-Wechsel <span></span></button>
            <button class="chip" data-type="port">Ports <span></span></button>
          </div>
          <div class="d-flex align-items-center gap-2 mt-2 small">
            <span class="text-body-secondary" id="evtCount"></span>
            <button class="btn btn-link btn-sm p-0 ms-auto d-none text-accent" id="evtReset" type="button">Filter zurücksetzen</button>
          </div>
        </div>
        <div class="list-group list-group-flush rounded-3 border d-md-none" id="eventList"></div>
        <div class="table-responsive rounded-3 border d-none d-md-block">
          <table class="table table-hover table-sm align-middle mb-0 evt-table">
            <thead>
              <tr>
                <th data-sort="t">Zeit</th>
                <th data-sort="event">Ereignis</th>
                <th data-sort="name">Gerät</th>
                <th data-sort="ip">IP</th>
                <th data-sort="vendor">Hersteller</th>
                <th data-sort="mac">MAC</th>
              </tr>
            </thead>
            <tbody id="eventTable"></tbody>
          </table>
        </div>
      </section>

      <!-- Geräte -->
      <section class="tab-pane fade" id="tabDevices">
        <div class="sticky-filter dev-filter d-flex flex-wrap gap-2 mb-2">
          <input class="form-control" type="search" id="devFilter" placeholder="Suchen: Name, Ort, IP, MAC, Hersteller, Typ">
          <select class="form-select w-auto" id="devLoc" aria-label="Raum / Ort"><option value="">Alle Orte</option></select>
          <select class="form-select w-auto" id="devState">
            <option value="">Alle</option>
            <option value="on">Online</option>
            <option value="off">Offline</option>
            <option value="noname">Ohne Namen</option>
          </select>
        </div>
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="small text-body-secondary me-auto" id="devCount"></div>
          <?php if ($admin): ?><button type="button" class="btn btn-ghost btn-sm" id="devAddVirtual">+ Gerät ohne IP</button><?php endif; ?>
        </div>
        <div class="list-group list-group-flush rounded-3 border" id="devList"></div>
      </section>

      <!-- Einstellungen -->
      <section class="tab-pane fade" id="tabSettings">
        <div class="row g-3">
<?php if ($admin): ?>
          <div class="col-12 col-xxl-6">
            <form class="panel" id="setForm" novalidate>
              <div class="panel-head"><span class="eyebrow">Funktionen</span></div>
              <div class="panel-body">
                <div class="set-row">
                  <div class="txt"><div class="t">Speedtest</div><div class="d">Misst Download, Upload und Ping. Aus: kein Reiter, keine Messungen.</div></div>
                  <div class="ctl form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="speedtest_enabled" aria-label="Speedtest"></div>
                </div>
                <div class="set-row">
                  <div class="txt"><div class="t">Speedtest-Intervall</div><div class="d">Jeder Test lastet die Leitung etwa 20 Sekunden voll aus.</div></div>
                  <div class="ctl"><select class="form-select" name="speedtest_interval">
                    <option value="10">alle 10 Minuten</option><option value="15">alle 15 Minuten</option>
                    <option value="20">alle 20 Minuten</option><option value="30">alle 30 Minuten</option><option value="60">stündlich</option>
                  </select></div>
                </div>
                <div class="set-row">
                  <div class="txt"><div class="t">Animationen</div><div class="d">Wandernder Datenstrom und sendende WLAN-Symbole.</div></div>
                  <div class="ctl form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="animations" aria-label="Animationen"></div>
                </div>
              </div>

              <div class="panel-head border-top pt-3"><span class="eyebrow">Netzwerkscan</span></div>
              <div class="panel-body">
                <div class="set-row">
                  <div class="txt"><div class="t">Subnetz</div><div class="d">Welches Netz gescannt wird, z. B. 192.168.5.0/24.</div></div>
                  <div class="ctl"><input class="form-control mono" name="subnet" autocomplete="off"></div>
                </div>
                <div class="set-row">
                  <div class="txt"><div class="t">Scan-Intervall</div><div class="d">Wie oft das Netz nach Geräten abgesucht wird.</div></div>
                  <div class="ctl"><select class="form-select" name="scan_interval">
                    <option value="1">jede Minute</option><option value="2">alle 2 Minuten</option><option value="5">alle 5 Minuten</option>
                    <option value="10">alle 10 Minuten</option><option value="15">alle 15 Minuten</option><option value="30">alle 30 Minuten</option>
                  </select></div>
                </div>
                <div class="set-row">
                  <div class="txt"><div class="t">Offline nach</div><div class="d">Verpasste Scans hintereinander, bevor ein Gerät als offline gilt.</div></div>
                  <div class="ctl"><select class="form-select" name="offline_after">
                    <option value="1">1 Scan</option><option value="2">2 Scans</option><option value="3">3 Scans</option>
                    <option value="5">5 Scans</option><option value="10">10 Scans</option>
                  </select></div>
                </div>
                <div class="set-row">
                  <div class="txt"><div class="t">Domain-Endung</div><div class="d">Wird von Hostnamen abgeschnitten, z. B. .fritz.box.</div></div>
                  <div class="ctl"><input class="form-control mono" name="strip_domain" autocomplete="off"></div>
                </div>
              </div>

              <div class="panel-head border-top pt-3"><span class="eyebrow">Switch-LEDs</span></div>
              <div class="panel-body">
                <div class="set-row">
                  <div class="txt"><div class="t">LEDs nachts aus</div><div class="d">Schaltet die Port-LEDs aller Switches, die das können, zur ersten Zeit aus und zur zweiten wieder an. Leer = nie.</div></div>
                  <div class="ctl d-flex gap-2"><input class="form-control" type="time" name="led_off" title="aus um"><input class="form-control" type="time" name="led_on" title="an um"></div>
                </div>
              </div>

              <div class="panel-head border-top pt-3"><span class="eyebrow">Aufbewahrung</span></div>
              <div class="panel-body">
                <div class="set-row">
                  <div class="txt"><div class="t">Speedtest-Rohdaten</div><div class="d">Danach bleiben nur Tageswerte (Tage).</div></div>
                  <div class="ctl"><input class="form-control" type="number" min="1" max="365" name="keep_speedtest_days"></div>
                </div>
                <div class="set-row">
                  <div class="txt"><div class="t">Ereignisse</div><div class="d">Neu, offline, wieder online, IP-Wechsel (Tage).</div></div>
                  <div class="ctl"><input class="form-control" type="number" min="7" max="3650" name="keep_event_days"></div>
                </div>
                <div class="set-row">
                  <div class="txt"><div class="t">Scan-Protokoll</div><div class="d">Dauer und Anzahl je Scan (Tage).</div></div>
                  <div class="ctl"><input class="form-control" type="number" min="1" max="365" name="keep_scan_days"></div>
                </div>
                <div class="set-row">
                  <div class="txt"><div class="t">Verlauf Switches &amp; Access Points</div><div class="d">Port-Verkehr, CPU, Speicher und Clients je Minute (Tage).</div></div>
                  <div class="ctl"><input class="form-control" type="number" min="1" max="90" name="keep_history_days"></div>
                </div>
                <div class="text-danger small mt-2" id="setError"></div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                  <button class="btn btn-accent" type="submit">Einstellungen speichern</button>
                  <button class="btn btn-ghost" type="button" id="runScan">Jetzt scannen</button>
                  <?php if ($speedOn): ?><button class="btn btn-ghost" type="button" id="runSpeed">Speedtest jetzt</button><?php endif; ?>
                </div>
              </div>
            </form>
          </div>

          <div class="col-12 col-xxl-6 d-flex flex-column gap-3">
            <div class="panel">
              <div class="panel-head"><span class="eyebrow">Bereiche</span><button class="btn btn-ghost btn-sm ms-auto" type="button" id="zoneAdd">+ Bereich</button></div>
              <div class="panel-body"><div class="row-list" id="zoneList"></div></div>
            </div>
            <div class="panel">
              <div class="panel-head"><span class="eyebrow">Benutzer</span><button class="btn btn-ghost btn-sm ms-auto" type="button" id="userAdd">+ Benutzer</button></div>
              <div class="panel-body"><div class="row-list" id="userList"></div></div>
            </div>
<?php else: ?>
          <div class="col-12 col-xxl-6 d-flex flex-column gap-3">
<?php endif; ?>
            <form class="panel" id="pwForm" novalidate>
              <div class="panel-head"><span class="eyebrow">Mein Konto · <?= $e($me['username']) ?></span></div>
              <div class="panel-body">
                <div class="row g-2">
                  <div class="col-sm-6"><label class="form-label small-label" for="pwOld">Bisheriges Passwort</label>
                    <input class="form-control" type="password" id="pwOld" name="old" autocomplete="current-password"></div>
                  <div class="col-sm-6"><label class="form-label small-label" for="pwNew">Neues Passwort</label>
                    <input class="form-control" type="password" id="pwNew" name="new" autocomplete="new-password" minlength="8"></div>
                </div>
                <div class="text-danger small mt-2" id="pwError"></div>
                <div class="d-flex gap-2 mt-3">
                  <button class="btn btn-ghost" type="submit">Passwort ändern</button>
                  <a class="btn btn-ghost ms-auto" href="logout.php">Abmelden</a>
                </div>
              </div>
            </form>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>

<!-- Navigation unten (Handy/Tablet) -->
<nav class="bottom-nav d-lg-none" id="bottomNav">
  <?php foreach ($views as $i => [$t, $label, $k]): ?>
    <button type="button" data-tab="<?= $t ?>" class="<?= $i === 0 ? 'active' : '' ?>"><?= $svg($k) ?><?= $t === '#tabSettings' ? 'Mehr' : $label ?></button>
  <?php endforeach; ?>
</nav>

<!-- Gerät -->
<div class="modal fade" id="devModal" tabindex="-1" aria-labelledby="devModalTitle">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down modal-dialog-scrollable" id="devDialog">
    <form class="modal-content" id="devForm" novalidate>
      <div class="modal-header">
        <div class="min-w-0">
          <h2 class="modal-title fs-4 text-truncate" id="devModalTitle">Gerät</h2>
          <div class="small text-body-secondary" id="devModalSub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <div class="nm-tabs dev-tabs" id="devTabs">
          <button type="button" data-dtab="over" class="active">Übersicht</button>
          <button type="button" data-dtab="graph" class="d-none" id="devTabGraph">Verkehr</button>
          <button type="button" data-dtab="set">Einstellungen</button>
        </div>
        <div class="dev-pane" data-pane="over">
          <div class="dev-over-grid">
            <div class="dev-over-left">
              <dl class="dev-facts mb-2" id="devFacts"></dl>
              <div id="devSwInfo" class="dev-swinfo d-none"></div>
              <button type="button" class="btn btn-link btn-sm px-0 mb-2 text-accent" id="devEvents">Alle Ereignisse dieses Geräts</button>
            </div>
            <div class="dev-over-right" id="devPanel"></div>
          </div>
          <div id="devPorts" class="dev-ports d-none"></div>
        </div>
        <div class="dev-pane d-none" data-pane="graph"><div id="devGraph"></div></div>
        <div class="dev-pane d-none" data-pane="set">
        <fieldset id="devFields" <?= $admin ? '' : 'disabled' ?>>
          <div class="mb-3">
            <label class="form-label small-label" for="fName">Name</label>
            <input class="form-control" id="fName" name="name" maxlength="64" autocomplete="off">
          </div>
          <div class="mb-3">
            <label class="form-label small-label" for="fLocation">Raum / Ort</label>
            <input class="form-control" id="fLocation" name="location" maxlength="64" autocomplete="off" list="locList" placeholder="z. B. Wohnzimmer">
            <datalist id="locList"></datalist>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-7">
              <label class="form-label small-label" for="fType">Typ</label>
              <select class="form-select" id="fType" name="type"></select>
            </div>
            <div class="col-5">
              <label class="form-label small-label" for="fPort">Port</label>
              <input class="form-control" id="fPort" name="port" maxlength="16" inputmode="numeric">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small-label" for="fParent">Hängt an</label>
            <select class="form-select" id="fParent" name="parentId"></select>
            <div class="form-text">Ohne Auswahl wird das Gerät über seinen IP-Bereich zugeordnet.</div>
          </div>
          <div class="mb-1">
            <label class="form-label small-label" for="fNotes">Notizen</label>
            <textarea class="form-control" id="fNotes" name="notes" rows="3" maxlength="2000"></textarea>
          </div>
          <?php if ($admin): ?>
          <div class="sw-box mt-3 d-none" id="swBox">
            <div class="small-label mb-2">Geräte-Abfrage (Switch / Access Point)</div>
            <div class="mb-2">
              <label class="form-label small text-body-secondary mb-1" for="fSwDriver">Treiber</label>
              <select class="form-select" id="fSwDriver" name="swDriver"></select>
              <div class="form-text" id="swHint"></div>
            </div>
            <div class="mb-2 d-none" id="swUserRow">
              <label class="form-label small text-body-secondary mb-1" for="fSwUser">Benutzer</label>
              <input class="form-control" id="fSwUser" name="swUser" maxlength="32" autocomplete="off" placeholder="z. B. netmon">
              <div class="form-text">Eigener FRITZ!Box-Benutzer mit dem Recht „FRITZ!Box Einstellungen“. Repeater mit Anmeldung nur per Kennwort: leer lassen.</div>
            </div>
            <div class="row g-2">
              <div class="col-7" id="swPassCol">
                <label class="form-label small text-body-secondary mb-1" for="fSwPass">Passwort der Weboberfläche</label>
                <input class="form-control" id="fSwPass" name="swPass" type="password" autocomplete="new-password" maxlength="64">
              </div>
              <div class="col-5">
                <label class="form-label small text-body-secondary mb-1" for="fSwUplink">Uplink-Port</label>
                <input class="form-control" id="fSwUplink" name="swUplink" inputmode="numeric" maxlength="2" placeholder="z. B. 1">
              </div>
            </div>
            <div class="d-flex gap-2 mt-2 align-items-center">
              <button type="button" class="btn btn-ghost btn-sm" id="swPoll">Verbinden &amp; abfragen</button>
              <a class="btn btn-link btn-sm px-0" id="swPages" href="#" title="Alle Seiten der Switch-Weboberfläche als Textdatei – für neue Treiber">Analyse-Paket</a>
              <button type="button" class="btn btn-link btn-sm text-danger px-0 d-none" id="swRemove">Zugang entfernen</button>
              <span class="small text-body-secondary ms-auto" id="swMsg"></span>
            </div>
          </div>
          <?php endif; ?>
        </fieldset>
        </div>
        <div class="text-danger small mt-2" id="devError"></div>
      </div>
      <div class="modal-footer">
<?php if ($admin): ?>
        <button type="button" class="btn btn-outline-danger me-auto" id="devDelete">Löschen</button>
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Abbrechen</button>
        <button type="submit" class="btn btn-accent">Speichern</button>
<?php else: ?>
        <span class="small text-body-secondary me-auto">Nur Admins können Geräte bearbeiten.</span>
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Schließen</button>
<?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($admin): ?>
<!-- Bereich -->
<div class="modal fade" id="zoneModal" tabindex="-1" aria-labelledby="zoneModalTitle">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="zoneForm" novalidate>
      <div class="modal-header"><h2 class="modal-title fs-4" id="zoneModalTitle">Bereich</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <div class="mb-3"><label class="form-label small-label" for="zName">Name</label>
          <input class="form-control" id="zName" name="name" maxlength="64" placeholder="Kinderzimmer 1"></div>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small-label" for="zFrom">Von IP</label><input class="form-control mono" id="zFrom" name="from" placeholder="192.168.5.30"></div>
          <div class="col-6"><label class="form-label small-label" for="zTo">Bis IP</label><input class="form-control mono" id="zTo" name="to" placeholder="192.168.5.39"></div>
        </div>
        <div class="row g-2 mb-1">
          <div class="col-6"><label class="form-label small-label" for="zType">Art</label>
            <select class="form-select" id="zType" name="type">
              <option value="kabel">Kabel</option><option value="wlan">WLAN</option><option value="labor">Labor</option><option value="dhcp">DHCP</option>
            </select></div>
          <div class="col-6"><label class="form-label small-label" for="zParent">Hängt an</label>
            <select class="form-select" id="zParent" name="parentId"></select></div>
        </div>
        <div class="text-danger small mt-2" id="zoneError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="zoneDelete">Löschen</button>
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Abbrechen</button>
        <button type="submit" class="btn btn-accent">Speichern</button>
      </div>
    </form>
  </div>
</div>

<!-- Benutzer -->
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalTitle">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="userForm" novalidate>
      <div class="modal-header"><h2 class="modal-title fs-4" id="userModalTitle">Benutzer</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <div class="mb-3"><label class="form-label small-label" for="uName">Benutzername</label>
          <input class="form-control" id="uName" name="username" maxlength="64" autocomplete="off" autocapitalize="none"></div>
        <div class="mb-3"><label class="form-label small-label" for="uRole">Rolle</label>
          <select class="form-select" id="uRole" name="role">
            <option value="viewer">Betrachter – darf alles ansehen</option>
            <option value="admin">Admin – darf auch bearbeiten und einstellen</option>
          </select></div>
        <div class="mb-1"><label class="form-label small-label" for="uPass">Passwort</label>
          <input class="form-control" type="password" id="uPass" name="password" autocomplete="new-password" minlength="8">
          <div class="form-text" id="uPassHint">Mindestens 8 Zeichen.</div></div>
        <div class="text-danger small mt-2" id="userError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="userDelete">Löschen</button>
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Abbrechen</button>
        <button type="submit" class="btn btn-accent">Speichern</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="toast-container position-fixed toast-pos p-3">
  <div id="toast" class="toast align-items-center border-0" role="status">
    <div class="d-flex"><div class="toast-body" id="toastText"></div><button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Schließen"></button></div>
  </div>
</div>

<script src="assets/vendor/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/d3.min.js"></script>
<script src="assets/vendor/chart.umd.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
