<?php
// netmon – Anmeldung und Ersteinrichtung des Admin-Kontos

declare(strict_types=1);

require __DIR__ . '/db.php';

session_begin();
$error = '';
$user  = trim((string) ($_POST['username'] ?? ''));

// Erst prüfen, ob Konfiguration und Datenbank erreichbar sind – sonst klare Meldung statt Fehler 500
$dbError = '';
try {
    if (!is_readable(NETMON_DIR . '/config.php')) {
        throw new RuntimeException(NETMON_DIR . '/config.php fehlt. Bitte config.example.php kopieren, in config.php umbenennen und Datenbank-Zugang eintragen.');
    }
    db()->query('SELECT 1 FROM users LIMIT 1');
} catch (PDOException $ex) {
    $dbError = str_contains($ex->getMessage(), '1045')
        ? 'Datenbank-Anmeldung abgelehnt (Benutzer „' . (cfg()['db']['user'] ?? '?') . '“). Benutzer/Passwort in ' . NETMON_DIR . '/config.php prüfen.'
        : (str_contains($ex->getMessage(), '42S02')
            ? 'Tabellen fehlen. Bitte sql/schema.sql in die Datenbank importieren.'
            : 'Datenbankfehler: ' . $ex->getMessage());
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

$setup = $dbError === '' && !users_exist();   // noch kein Konto: Admin anlegen

if ($dbError === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Sitzung abgelaufen. Bitte noch einmal versuchen.';
    } elseif ($setup) {
        $pass  = (string) ($_POST['password'] ?? '');
        $pass2 = (string) ($_POST['password2'] ?? '');
        if (!preg_match('/^[\w.\-@]{3,64}$/u', $user)) {
            $error = 'Benutzername: 3 bis 64 Zeichen, Buchstaben, Ziffern, Punkt, Bindestrich.';
        } elseif (mb_strlen($pass) < 8) {
            $error = 'Das Passwort braucht mindestens 8 Zeichen.';
        } elseif ($pass !== $pass2) {
            $error = 'Die beiden Passwörter stimmen nicht überein.';
        } elseif (users_exist()) {
            $error = 'Es wurde bereits ein Konto angelegt. Bitte anmelden.';
            $setup = false;
        } else {
            db()->prepare("INSERT INTO users (username, pass_hash, role, created_at) VALUES (?, ?, 'admin', NOW())")
                ->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
            login($user, $pass);
            header('Location: ./');
            exit;
        }
    } else {
        if (login($user, (string) ($_POST['password'] ?? ''))) {
            header('Location: ./');
            exit;
        }
        $error = ($m = login_blocked()) > 0
            ? "Zu viele Fehlversuche – bitte in $m Minuten noch einmal versuchen."
            : 'Benutzername oder Passwort stimmt nicht.';
    }
}

if ($dbError === '' && !$setup && current_user()) {
    header('Location: ./');
    exit;
}
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<!doctype html>
<html lang="de" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">  <!-- netmon hat eigenes Dunkel/Hell: Browser sollen nicht selbst umfärben (Samsung Internet) -->
  <meta name="theme-color" content="#14151b">
  <title>netmon · Anmelden</title>
  <link rel="icon" href="assets/icon-192.png">
  <script>
    (function () {
      let t = null;
      try { t = localStorage.getItem('netmon-theme'); } catch (e) {}
      if (!t) t = matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', t);
    })();
  </script>
  <link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
  <main class="login-card">
    <div class="brand-block mb-4">
      <div class="brand"><span class="brand-dot"></span>netmon</div>
      <div class="brand-sub">Netzwerk · Leitung · Geräte</div>
    </div>

    <?php if ($dbError): ?>
      <h1 class="h4 mb-3">Einrichtung unvollständig</h1>
      <div class="alert alert-danger small"><?= $e($dbError) ?></div>
      <p class="text-body-secondary small mb-0">Nach der Korrektur diese Seite neu laden.</p>
  </main>
</body>
</html>
<?php exit; endif; ?>

    <?php if ($setup): ?>
      <h1 class="h4 mb-1">Willkommen</h1>
      <p class="text-body-secondary small mb-4">Lege das Admin-Konto an. Weitere Benutzer fügst du später in den Einstellungen hinzu.</p>
    <?php else: ?>
      <h1 class="h4 mb-4">Anmelden</h1>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2 small"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf" value="<?= $e(csrf_token()) ?>">
      <div class="mb-3">
        <label class="form-label small-label" for="u">Benutzername</label>
        <input class="form-control" id="u" name="username" value="<?= $e($user) ?>" autocomplete="username" autocapitalize="none" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label small-label" for="p">Passwort</label>
        <input class="form-control" id="p" name="password" type="password" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" required>
      </div>
      <?php if ($setup): ?>
        <div class="mb-3">
          <label class="form-label small-label" for="p2">Passwort wiederholen</label>
          <input class="form-control" id="p2" name="password2" type="password" autocomplete="new-password" required>
        </div>
      <?php endif; ?>
      <button class="btn btn-accent w-100 mt-2" type="submit"><?= $setup ? 'Admin-Konto anlegen' : 'Anmelden' ?></button>
    </form>
  </main>
</body>
</html>
