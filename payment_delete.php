<?php
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

verifyCsrf();

$userId = userId();
$source = $_POST['source'] ?? '';
$bookingMonth = $_POST['booking_month'] ?? '';
$eventDate = $_POST['event_date'] ?? '';

$allowedSources = ['one_time', 'initial', 'automatic', 'credit_wallet', 'credit_reallocation'];

if (!in_array($source, $allowedSources, true)) {
    setFlash('error', 'Ungültige Buchungsart.');
    header('Location: dashboard.php');
    exit;
}

$normalizedMonth = normalizeMonthInputToDate(substr($bookingMonth, 0, 7));
if (!$normalizedMonth) {
    setFlash('error', 'Ungültiger Buchungsmonat.');
    header('Location: dashboard.php');
    exit;
}

db()->beginTransaction();

try {
    if (in_array($source, ['one_time', 'initial'], true)) {
        $stmt = db()->prepare("
            SELECT ma.id
            FROM monthly_allocations ma
            WHERE ma.user_id = ?
              AND ma.source = ?
              AND ma.created_at = ?
        ");
        $stmt->execute([$userId, $source, $eventDate]);
        $allocationIds = array_column($stmt->fetchAll(), 'id');

        if ($allocationIds) {
            $placeholders = implode(',', array_fill(0, count($allocationIds), '?'));
            $stmt = db()->prepare("DELETE FROM monthly_allocations WHERE id IN ($placeholders) AND user_id = ?");
            $stmt->execute([...$allocationIds, $userId]);
        }

        if ($source === 'one_time') {
            $stmt = db()->prepare("
                DELETE FROM one_time_contributions
                WHERE user_id = ?
                  AND booking_month = ?
                  AND ABS(TIMESTAMPDIFF(SECOND, processed_at, ?)) < 5
            ");
            $stmt->execute([$userId, $normalizedMonth, $eventDate]);
        }

    } else {
        $stmt = db()->prepare("
            DELETE FROM monthly_allocations
            WHERE user_id = ?
              AND booking_month = ?
              AND source = ?
        ");
        $stmt->execute([$userId, $normalizedMonth, $source]);

        if ($source === 'automatic') {
            $stmt = db()->prepare("
                DELETE FROM monthly_runs
                WHERE user_id = ?
                  AND booking_month = ?
            ");
            $stmt->execute([$userId, $normalizedMonth]);
        }
    }

    db()->commit();
    setFlash('success', 'Buchung wurde gelöscht.');
} catch (Throwable $e) {
    db()->rollBack();
    setFlash('error', 'Buchung konnte nicht gelöscht werden.');
}

header('Location: dashboard.php');
exit;
