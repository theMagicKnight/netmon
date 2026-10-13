<?php
// netmon – gemeinsame Konfiguration für Skripte und Weboberfläche
// Kopieren als config.php in denselben Ordner (neben index.php) und Zugangsdaten eintragen.
// Wird diese Datei im Browser aufgerufen, zeigt sie nichts an.

date_default_timezone_set('Europe/Berlin');

return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=netmon;charset=utf8mb4',
        'user' => 'netmon',
        'pass' => 'PASSWORT',
    ],

    // Netz, das gescannt wird
    'subnet' => '192.168.5.0/24',

    // Domain-Endung, die von Hostnamen abgeschnitten wird
    'strip_domain' => '.fritz.box',

    // Nach so vielen verpassten Scans gilt ein Gerät als offline
    'offline_after' => 3,

    'nmap'      => '/usr/bin/nmap',

    // Python mit dem Paket tplinkrouterc6u – nur für den Treiber „TP-Link Archer / Deco“ (siehe LIESMICH)
    'python'    => '/opt/netmon-python/bin/python',

    // Speedtest ein/aus (aus: kein Reiter, keine Messungen)
    'speedtest_enabled' => false,
    'speedtest' => '/usr/local/bin/speedtest',

    // Aufbewahrung in Tagen
    'keep_speedtest_days' => 30,    // danach nur noch Tageswerte
    'keep_event_days'     => 365,
    'keep_scan_days'      => 30,
];
