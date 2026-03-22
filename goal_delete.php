<?php
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

verifyCsrf();

$userId = userId();
$goalId = (int)($_POST['goal_id'] ?? 0);
$goal = getGoalById($userId, $goalId);

if (!$goal) {
    setFlash('error', 'Sparziel nicht gefunden.');
    header('Location: dashboard.php');
    exit;
}

db()->beginTransaction();

try {
    if (hasGoalAllocations($goalId)) {
        $stmt = db()->prepare("DELETE FROM monthly_allocations WHERE goal_id = ?");
        $stmt->execute([$goalId]);
    }

    $stmt = db()->prepare("DELETE FROM goals WHERE id = ? AND user_id = ?");
    $stmt->execute([$goalId, $userId]);

    db()->commit();
    setFlash('success', 'Sparziel wurde gelöscht.');
} catch (Throwable $e) {
    db()->rollBack();
    setFlash('error', 'Löschen fehlgeschlagen.');
}

header('Location: dashboard.php');
exit;
