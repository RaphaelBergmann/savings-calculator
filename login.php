<?php
require_once 'config.php';
require_once 'auth.php';

if (userId()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare("SELECT id, password_hash FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        loginUser((int)$user['id']);
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Login fehlgeschlagen.';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Login - Spar-Rechner</title>
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
        <h2>Login</h2>
        <?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrfField() ?>
            <label>E-Mail</label>
            <input type="email" name="email" required>
            <label>Passwort</label>
            <input type="password" name="password" required>
            <button type="submit">Einloggen</button>
        </form>
        <br>
        <p class="muted">Noch kein Konto? <a href="register.php">Jetzt registrieren</a></p>
    </div>
</div>
</body>
</html>
