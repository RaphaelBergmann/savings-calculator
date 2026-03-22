<?php
require_once 'functions.php';

$stmt = db()->query("SELECT id FROM users");
$users = $stmt->fetchAll();

foreach ($users as $user) {
    processAllMonthsUntilCurrent((int)$user['id']);
}

echo "Monatsverarbeitung abgeschlossen.\n";
