<?php
require_once 'functions.php';
requireLogin();

$userId = userId();
$goalId = (int)($_GET['id'] ?? $_POST['goal_id'] ?? 0);
$goal = getGoalById($userId, $goalId);

if (!$goal) {
    http_response_code(404);
    exit('Sparziel nicht gefunden.');
}

$initialAmount = getGoalInitialAmount($goalId);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name = trim($_POST['goal_name'] ?? '');
    $target = (float)str_replace(',', '.', $_POST['goal_target'] ?? '0');
    $newInitialAmount = (float)str_replace(',', '.', $_POST['goal_initial_amount'] ?? '0');
    $startMonth = normalizeMonthInputToDate($_POST['goal_start_month'] ?? '');

    if ($name === '' || $target <= 0 || $newInitialAmount < 0 || $newInitialAmount > $target || $startMonth === null) {
        setFlash('error', 'Bitte gültige Werte eingeben. Der Startwert darf nicht größer als die Zielsumme sein.');
    } else {
        db()->beginTransaction();

        try {
            $stmt = db()->prepare("
                UPDATE goals
                SET name = ?, target_amount = ?, start_month = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$name, round($target, 2), $startMonth, $goalId, $userId]);

            $stmt = db()->prepare("
                SELECT id FROM monthly_allocations
                WHERE goal_id = ? AND source = 'initial'
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([$goalId]);
            $initialRow = $stmt->fetch();

            if ($initialRow) {
                if ($newInitialAmount > 0) {
                    $stmt = db()->prepare("
                        UPDATE monthly_allocations
                        SET amount = ?, booking_month = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([round($newInitialAmount, 2), firstDayOfCurrentMonth(), $initialRow['id']]);
                } else {
                    $stmt = db()->prepare("DELETE FROM monthly_allocations WHERE id = ?");
                    $stmt->execute([$initialRow['id']]);
                }
            } else {
                if ($newInitialAmount > 0) {
                    $stmt = db()->prepare("
                        INSERT INTO monthly_allocations (user_id, goal_id, booking_month, source, amount)
                        VALUES (?, ?, ?, 'initial', ?)
                    ");
                    $stmt->execute([
                        $userId,
                        $goalId,
                        firstDayOfCurrentMonth(),
                        round($newInitialAmount, 2)
                    ]);
                }
            }

            db()->commit();
            setFlash('success', 'Sparziel wurde aktualisiert.');
            header('Location: dashboard.php');
            exit;
        } catch (Throwable $e) {
            db()->rollBack();
            setFlash('error', 'Sparziel konnte nicht aktualisiert werden.');
        }
    }

    $goal = getGoalById($userId, $goalId);
    $initialAmount = getGoalInitialAmount($goalId);
}

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Sparziel bearbeiten</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="style.css" rel="stylesheet">
</head>
<body>
<div class="simple-page">
    <div class="simple-card">
        <h1>Sparziel bearbeiten</h1>
        <p class="muted">Wenn für das Ziel noch keine Monatsbuchungen existieren, wird der Start wieder auf den nächsten Monat gesetzt.</p>

        <form method="post" class="form-stack">
            <?= csrfField() ?>
            <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">

            <label>Name</label>
            <input type="text" name="goal_name" value="<?= h($goal['name']) ?>" required>

            <label>Zielsumme in Euro</label>
            <input type="number" step="0.01" min="0.01" name="goal_target" value="<?= h((string)$goal['target_amount']) ?>" required>

            <label>Startwert in Euro</label>
            <input
                type="number"
                step="0.01"
                min="0"
                name="goal_initial_amount"
                value="<?= h(number_format($initialAmount, 2, '.', '')) ?>"
            >
            <p class="muted">Bereits vorhandener Sparstand zu Beginn.</p>

            <label>Startmonat</label>
            <input
                type="month"
                name="goal_start_month"
                value="<?= h(monthInputValueFromDate($goal['start_month'])) ?>"
                required
            >
            <p class="muted">Du kannst einen Monat in der Vergangenheit oder Zukunft wählen.</p>


            <div class="action-row">
                <button type="submit">Speichern</button>
                <a class="ghost-btn" href="dashboard.php">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
