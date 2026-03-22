<?php
require_once 'functions.php';
requireLogin();

$userId = userId();
$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['add_goal'])) {
        $name = trim($_POST['goal_name'] ?? '');
        $target = (float)str_replace(',', '.', $_POST['goal_target'] ?? '0');
        $initialAmount = (float)str_replace(',', '.', $_POST['goal_initial_amount'] ?? '0');
        $startMonth = normalizeMonthInputToDate($_POST['goal_start_month'] ?? '');

        if ($name !== '' && $target > 0 && $initialAmount >= 0 && $initialAmount <= $target && $startMonth !== null) {
            db()->beginTransaction();

            try {
                $stmt = db()->prepare("
                    INSERT INTO goals (user_id, name, target_amount, start_month)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $name, round($target, 2), $startMonth]);
                $goalId = (int)db()->lastInsertId();

                if ($initialAmount > 0) {
                    $stmt = db()->prepare("
                        INSERT INTO monthly_allocations (user_id, goal_id, booking_month, source, amount)
                        VALUES (?, ?, ?, 'initial', ?)
                    ");
                    $stmt->execute([
                        $userId,
                        $goalId,
                        firstDayOfCurrentMonth(),
                        round($initialAmount, 2)
                    ]);
                }

                db()->commit();
                setFlash('success', 'Sparziel wurde angelegt.');
            } catch (Throwable $e) {
                db()->rollBack();
                setFlash('error', 'Sparziel konnte nicht angelegt werden.');
            }
        } else {
            setFlash('error', 'Bitte gültige Werte eingeben. Der Startwert darf nicht größer als die Zielsumme sein.');
        }

        header('Location: dashboard.php');
        exit;
    }

    if (isset($_POST['save_monthly'])) {
        $amount = (float)str_replace(',', '.', $_POST['monthly_amount'] ?? '0');
        $absolutePercent = max(0, min(100, (int)($_POST['absolute_percent'] ?? 50)));
        $relativePercent = 100 - $absolutePercent;

        $stmt = db()->prepare("
            INSERT INTO monthly_settings (user_id, valid_from_month, monthly_amount, absolute_percent, relative_percent)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                monthly_amount = VALUES(monthly_amount),
                absolute_percent = VALUES(absolute_percent),
                relative_percent = VALUES(relative_percent)
        ");
        $stmt->execute([$userId, firstDayOfNextMonth(), round($amount, 2), $absolutePercent, $relativePercent]);

        setFlash('success', 'Monatliche Sparsumme gespeichert. Gilt ab nächstem Monat.');
        header('Location: dashboard.php');
        exit;
    }

    if (isset($_POST['add_onetime'])) {
        $amount = (float)str_replace(',', '.', $_POST['onetime_amount'] ?? '0');
        $absolutePercent = max(0, min(100, (int)($_POST['onetime_absolute_percent'] ?? 50)));
        $relativePercent = 100 - $absolutePercent;
        $targetGoalId = (int)($_POST['onetime_target_goal_id'] ?? 0);
        $targetGoalId = $targetGoalId > 0 ? $targetGoalId : null;

        if ($targetGoalId !== null) {
            $stmt = db()->prepare("
                SELECT COUNT(*)
                FROM goals g
                LEFT JOIN (
                    SELECT goal_id, COALESCE(SUM(amount), 0) AS saved_amount
                    FROM monthly_allocations
                    GROUP BY goal_id
                ) x ON x.goal_id = g.id
                WHERE g.user_id = ?
                  AND g.id = ?
                  AND g.start_month <= ?
                  AND COALESCE(x.saved_amount, 0) < g.target_amount
            ");
            $stmt->execute([$userId, $targetGoalId, firstDayOfCurrentMonth()]);
            $validTarget = (int)$stmt->fetchColumn() > 0;
        } else {
            $validTarget = true;
        }

        if ($amount > 0 && $validTarget) {
            $stmt = db()->prepare("
                INSERT INTO one_time_contributions
                (user_id, booking_month, amount, absolute_percent, relative_percent, target_goal_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                firstDayOfCurrentMonth(),
                round($amount, 2),
                $absolutePercent,
                $relativePercent,
                $targetGoalId
            ]);
            setFlash('success', 'Einmal-Sparsumme wurde hinzugefügt.');
        } else {
            setFlash('error', 'Bitte einen gültigen Betrag eingeben und optional nur ein offenes Sparziel auswählen.');
        }

        header('Location: dashboard.php');
        exit;
    }

}

processAllMonthsUntilCurrent($userId);

$goals = getAllGoalsWithProgress($userId);
$wallet = getCurrentWalletCredit($userId);
$nextSetting = getEffectiveMonthlySetting($userId, firstDayOfNextMonth());
$stats = dashboardStats($userId);
$paymentHistory = getGroupedPaymentHistory($userId, 500);
$selectableOpenGoals = getSelectableOpenGoals($userId);

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Spar-Rechner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="style.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div>
            <div class="sidebar-brand">
                <div class="logo">€</div>
                <div>
                    <strong>Spar-Rechner</strong>
                    <div class="muted small-text">Dein Spar-Dashboard</div>
                </div>
            </div>

            <nav class="side-nav">
                <a href="#overview">Übersicht</a>
                <a href="#goals">Sparziele</a>
                <a href="#settings">Sparsumme</a>
                <a href="#transactions">Zahlungsverlauf</a>
            </nav>
        </div>

        <a class="ghost-btn full" href="logout.php">Logout</a>
    </aside>

    <main class="main-content">
        <header class="page-header">
            <div>
                <h1>Willkommen zurück</h1>
                <p class="muted">Verwalte deine Ziele, Sparraten und dein Guthaben zentral.</p>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <section id="overview" class="stats-grid">
            <div class="stat-card">
                <span>Gesamtziele</span>
                <strong><?= (int)$stats['goal_count'] ?></strong>
            </div>
            <div class="stat-card">
                <span>Offene Ziele</span>
                <strong><?= (int)$stats['open_count'] ?></strong>
            </div>
            <div class="stat-card">
                <span>Gespart gesamt</span>
                <strong><?= h(euro((float)$stats['total_saved'])) ?></strong>
            </div>
            <div class="stat-card">
                <span>Guthaben</span>
                <strong><?= h(euro($wallet)) ?></strong>
            </div>
        </section>

        <section id="goals" class="panel goals-panel">
            <div class="panel-head">
                <h2>Deine Sparziele</h2>
                <span class="badge"><?= (int)$stats['goal_count'] ?> Einträge</span>
            </div>

            <?php if (!$goals): ?>
                <div class="empty-state">
                    <h3>Noch keine Sparziele</h3>
                    <p>Lege dein erstes Sparziel an, um zu starten.</p>
                </div>
            <?php else: ?>
                <div class="goal-list">
                    <?php foreach ($goals as $goal): ?>
                        <?php
                        $saved = (float)$goal['saved_amount'];
                        $target = (float)$goal['target_amount'];
                        $percent = progressPercent($saved, $target);
                        $forecast = simulateCompletionMonth($userId, $goal);
                        ?>
                        <article class="goal-card">
                            <div class="goal-card-top">
                                <div>
                                    <h3><?= h($goal['name']) ?></h3>
                                    <p class="muted">
                                        Start: <?= h(date('d.m.Y', strtotime($goal['start_month']))) ?> ·
                                        Prognose: <?= h($forecast ?? 'Nicht berechenbar') ?>
                                    </p>
                                </div>
                                <div class="goal-actions">
                                    <a class="ghost-btn" href="goal_edit.php?id=<?= (int)$goal['id'] ?>">Bearbeiten</a>
                                    <form method="post" action="goal_delete.php" onsubmit="return confirm('Sparziel wirklich löschen?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">
                                        <button type="submit" class="danger-btn">Löschen</button>
                                    </form>
                                </div>
                            </div>

                            <div class="goal-values">
                                <span><?= h(euro($saved)) ?></span>
                                <span>von <?= h(euro($target)) ?></span>
                            </div>

                            <div class="progress-wrap" aria-label="Fortschritt">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= h((string)$percent) ?>%"></div>
                                </div>
                                <div class="progress-text"><?= h(number_format($percent, 1, ',', '.')) ?>%</div>
                            </div>

                            <div class="goal-meta">
                                <span class="pill <?= $saved >= $target ? 'success' : 'info' ?>">
                                    <?= $saved >= $target ? 'Erreicht' : 'Offen' ?>
                                </span>
                                <?php if (!empty($goal['completed_at'])): ?>
                                    <span class="muted">Erreicht am <?= h(date('d.m.Y', strtotime($goal['completed_at']))) ?></span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel-grid">
            <div class="panel">
                <div class="panel-head">
                    <h2>Neues Sparziel</h2>
                    <span class="badge">Start: <?= h(date('d.m.Y', strtotime(firstDayOfNextMonth()))) ?></span>
                </div>

                <form method="post" class="form-stack">
                    <?= csrfField() ?>
                    <input type="hidden" name="add_goal" value="1">
                    <label>Name</label>
                    <input type="text" name="goal_name" placeholder="z. B. Karibik-Reise" required>

                    <label>Zielsumme in Euro</label>
                    <input type="number" step="0.01" min="0.01" name="goal_target" placeholder="4000" required>

                    <label>Startwert in Euro</label>
                    <input type="number" step="0.01" min="0" name="goal_initial_amount" placeholder="1500">
                    <p class="muted">Optional: Betrag, den du für dieses Ziel bereits angespart hast.</p>

                    <label>Startmonat</label>
                    <input
                        type="month"
                        name="goal_start_month"
                        value="<?= h(date('Y-m', strtotime(firstDayOfNextMonth()))) ?>"
                        required
                    >
                    <p class="muted">Du kannst einen Monat in der Vergangenheit oder Zukunft wählen.</p>

                    <button type="submit">Sparziel anlegen</button>
                </form>
            </div>

            <div id="settings" class="panel">
                <div class="panel-head">
                    <h2>Automatische Sparsumme</h2>
                    <span class="badge">Gilt ab <?= h(date('d.m.Y', strtotime(firstDayOfNextMonth()))) ?></span>
                </div>

                <form method="post" class="form-stack">
                    <?= csrfField() ?>
                    <input type="hidden" name="save_monthly" value="1">

                    <label>Monatlicher Betrag</label>
                    <input type="number" step="0.01" min="0" name="monthly_amount" value="<?= h((string)$nextSetting['monthly_amount']) ?>" required>

                    <label>Aufteilung</label>
                    <div class="split-labels">
                        <span><strong id="relVal"><?= (int)$nextSetting['relative_percent'] ?></strong>% relativ</span>
                        <span><strong id="absVal"><?= (int)$nextSetting['absolute_percent'] ?></strong>% absolut</span>
                    </div>
                    <input type="range" min="0" max="100" name="absolute_percent" id="absolute_percent" value="<?= (int)$nextSetting['absolute_percent'] ?>">
                    <p class="muted">Relativ bedeutet, die Summe wird basierend auf Höhe des Sparziels proportional verteilt. Bei Absolut werden beispielsweise 100 Euro bei 2 Sparzielen unabhängig von der Höhe exakt 50/50 aufgeteilt.</p>
                    <button type="submit">Sparsumme speichern</button>
                </form>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h2>Einmal-Sparsumme</h2>
                    <span class="badge">Monat <?= h(monthLabel(firstDayOfCurrentMonth())) ?></span>
                </div>

                <form method="post" class="form-stack" id="oneTimeForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="add_onetime" value="1">

                    <label>Einmaliger Zusatzbetrag</label>
                    <input type="number" step="0.01" min="0.01" name="onetime_amount" placeholder="100" required>

                    <label>Optional direkt einem offenen Sparziel zuweisen</label>
                    <select name="onetime_target_goal_id" id="onetime_target_goal_id">
                        <option value="">Auf alle offenen Ziele verteilen</option>
                        <?php foreach ($selectableOpenGoals as $openGoal): ?>
                            <option value="<?= (int)$openGoal['id'] ?>">
                                <?= h($openGoal['name']) ?> (noch offen: <?= h(euro((float)$openGoal['target_amount'] - (float)$openGoal['saved_amount'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="oneTimeSplitBlock">
                        <label>Aufteilung für diese Einmal-Sparsumme</label>
                        <br>
                        <br>
                        <div class="split-labels">
                            <span><strong id="otRelVal">50</strong>% relativ</span>
                            <span><strong id="otAbsVal">50</strong>% absolut</span>
                        </div>
                        <input type="range" min="0" max="100" name="onetime_absolute_percent" id="onetime_absolute_percent" value="50">
                        <p class="muted">Diese Aufteilung gilt nur, wenn kein einzelnes Sparziel ausgewählt ist.</p>
                    </div>

                    <button type="submit">Betrag hinzufügen</button>
                </form>
            </div>

        </section>

        <section id="transactions" class="panel payment-history-panel">
            <div class="panel-head">
                <h2>Zahlungsverlauf</h2>
                <span class="badge"><?= count($paymentHistory) ?> Gruppen</span>
            </div>

            <?php if (!$paymentHistory): ?>
                <div class="empty-state">
                    <h3>Noch keine Zahlungen</h3>
                    <p>Sobald Zahlungen auf Sparziele verteilt wurden, erscheinen sie hier.</p>
                </div>
            <?php else: ?>
                <div class="history-accordion">
                    <?php foreach ($paymentHistory as $group): ?>
                        <details class="history-group">
                            <summary class="history-summary">
                                <div class="history-summary-main">
                                    <?php if (in_array($group['source'], ['one_time', 'initial'], true)): ?>
                                        <strong><?= h(date('d.m.Y H:i', strtotime($group['event_date']))) ?></strong>
                                    <?php else: ?>
                                        <strong><?= h(monthLabel($group['booking_month'])) ?></strong>
                                    <?php endif; ?>
                                    <span class="history-type"><?= h(paymentSourceLabel($group['source'])) ?></span>
                                </div>
                                <div class="history-summary-side">
                                    <span class="history-total"><?= h(euro((float)$group['total_amount'])) ?></span>
                                    <span class="history-count"><?= count($group['entries']) ?> Buchungen</span>
                                </div>
                            </summary>

                            <div class="history-details">
                                <div class="history-detail-head">
                                    <span>Sparziel</span>
                                    <span>Betrag</span>
                                    <span>Gebucht am</span>
                                </div>

                                <?php foreach ($group['entries'] as $entry): ?>
                                    <div class="history-detail-row">
                                        <span data-label="Sparziel"><?= h($entry['goal_name']) ?></span>
                                        <span data-label="Betrag"><?= h(euro((float)$entry['amount'])) ?></span>
                                        <span data-label="Gebucht am"><?= h(date('d.m.Y H:i', strtotime($entry['created_at']))) ?></span>
                                    </div>
                                <?php endforeach; ?>

                                <div class="history-delete-row">
                                    <form
                                        method="post"
                                        action="payment_delete.php"
                                        onsubmit="return confirm('Buchung wirklich löschen? Alle Aufteilungen dieser Zahlung werden entfernt.');"
                                    >
                                        <?= csrfField() ?>
                                        <input type="hidden" name="source" value="<?= h($group['source']) ?>">
                                        <input type="hidden" name="booking_month" value="<?= h($group['booking_month']) ?>">
                                        <input type="hidden" name="event_date" value="<?= h($group['event_date']) ?>">
                                        <button type="submit" class="danger-btn small-btn">Buchung löschen</button>
                                    </form>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>


    </main>
</div>

<script>
function bindSlider(sliderId, absId, relId) {
    const slider = document.getElementById(sliderId);
    const absVal = document.getElementById(absId);
    const relVal = document.getElementById(relId);

    if (!slider || !absVal || !relVal) return;

    function updateSlider() {
        const abs = parseInt(slider.value, 10);
        absVal.textContent = abs;
        relVal.textContent = 100 - abs;
    }

    slider.addEventListener('input', updateSlider);
    updateSlider();
}

function bindOneTimeTargetToggle() {
    const select = document.getElementById('onetime_target_goal_id');
    const splitBlock = document.getElementById('oneTimeSplitBlock');
    const slider = document.getElementById('onetime_absolute_percent');

    if (!select || !splitBlock || !slider) return;

    function updateVisibility() {
        const hasTarget = select.value !== '';
        splitBlock.style.opacity = hasTarget ? '0.45' : '1';
        slider.disabled = hasTarget;
    }

    select.addEventListener('change', updateVisibility);
    updateVisibility();
}

bindSlider('absolute_percent', 'absVal', 'relVal');
bindSlider('onetime_absolute_percent', 'otAbsVal', 'otRelVal');
bindOneTimeTargetToggle();
</script>


</body>
</html>
