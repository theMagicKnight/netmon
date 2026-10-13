<?php
// netmon – Abmelden

declare(strict_types=1);

require __DIR__ . '/db.php';

session_begin();
$_SESSION = [];
session_destroy();
header('Location: login.php');
