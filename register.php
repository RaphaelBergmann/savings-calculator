<?php
require_once 'config.php';
require_once 'auth.php';

if (userId()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Bitte eine gültige E-Mail eingeben.';
    } elseif (strlen($password) < 6) {
        $error = 'Das Passwort muss mindestens 6 Zeichen lang sein.';
    } else {
        $stmt = db()->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $error = 'Diese E-Mail ist bereits registriert.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = db()->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
            $stmt->execute([$email, $hash]);

            setFlash('success', 'Registrierung erfolgreich. Bitte einloggen.');
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Registrieren - Spar-Rechner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="style.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-wrap">
    <div class="brand-card">
        <div class="logo">€</div>
        <h1>Spar-Rechner</h1>
        <p>Plane deine Ziele, verteile monatliche Sparbeträge und behalte den Fortschritt im Blick.</p>
    </div>

    <div class="auth-card">
        <h2>Registrieren</h2>
        <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrfField() ?>
            <label>E-Mail</label>
            <input type="email" name="email" required>
            <label>Passwort</label>
            <input type="password" name="password" required>
            <button type="submit">Konto erstellen</button>
        </form>
        <br>
        <p class="muted">Schon registriert? <a href="login.php">Zum Login</a></p>
    </div>
</div>
</body>
</html>
