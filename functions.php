<?php
require_once 'config.php';

function getCurrentWalletCredit(int $userId): float {
    $stmt = db()->prepare("SELECT COALESCE(SUM(available_amount), 0) FROM wallet_credits WHERE user_id = ?");
    $stmt->execute([$userId]);
    return (float)$stmt->fetchColumn();
}

function addWalletCredit(int $userId, float $amount): void {
    if ($amount <= 0) return;
    $stmt = db()->prepare("INSERT INTO wallet_credits (user_id, amount, available_amount) VALUES (?, ?, ?)");
    $stmt->execute([$userId, round($amount, 2), round($amount, 2)]);
}

function consumeWalletCredit(int $userId, float $amount): float {
    if ($amount <= 0) return 0.0;

    $stmt = db()->prepare("SELECT id, available_amount FROM wallet_credits WHERE user_id = ? AND available_amount > 0 ORDER BY id");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $used = 0.0;
    foreach ($rows as $row) {
        if ($amount <= 0) break;
        $available = (float)$row['available_amount'];
        $take = min($available, $amount);

        $upd = db()->prepare("UPDATE wallet_credits SET available_amount = available_amount - ? WHERE id = ?");
        $upd->execute([round($take, 2), $row['id']]);

        $used += $take;
        $amount -= $take;
    }

    return round($used, 2);
}

function getEffectiveMonthlySetting(int $userId, string $month): array {
    $stmt = db()->prepare("
        SELECT monthly_amount, absolute_percent, relative_percent
        FROM monthly_settings
        WHERE user_id = ? AND valid_from_month <= ?
        ORDER BY valid_from_month DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $month]);
    $row = $stmt->fetch();

    if (!$row) {
        return [
            'monthly_amount' => 0.0,
            'absolute_percent' => 50,
            'relative_percent' => 50,
        ];
    }

    return [
        'monthly_amount' => (float)$row['monthly_amount'],
        'absolute_percent' => (int)$row['absolute_percent'],
        'relative_percent' => (int)$row['relative_percent'],
    ];
}

function monthAlreadyCalculated(int $userId, string $month): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM monthly_runs WHERE user_id = ? AND booking_month = ?");
    $stmt->execute([$userId, $month]);
    return (int)$stmt->fetchColumn() > 0;
}

function getOpenGoals(int $userId, string $month): array {
    $stmt = db()->prepare("
        SELECT g.*,
               COALESCE(SUM(ma.amount), 0) AS saved_amount
        FROM goals g
        LEFT JOIN monthly_allocations ma ON ma.goal_id = g.id
        WHERE g.user_id = ?
          AND g.start_month <= ?
        GROUP BY g.id
        HAVING saved_amount < g.target_amount
        ORDER BY g.created_at, g.id
    ");
    $stmt->execute([$userId, $month]);
    return $stmt->fetchAll();
}

function getAllGoalsWithProgress(int $userId): array {
    $stmt = db()->prepare("
        SELECT g.*,
               COALESCE(SUM(ma.amount), 0) AS saved_amount
        FROM goals g
        LEFT JOIN monthly_allocations ma ON ma.goal_id = g.id
        WHERE g.user_id = ?
        GROUP BY g.id
        ORDER BY g.created_at, g.id
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getGoalById(int $userId, int $goalId): ?array {
    $stmt = db()->prepare("
        SELECT g.*,
               COALESCE(SUM(ma.amount), 0) AS saved_amount
        FROM goals g
        LEFT JOIN monthly_allocations ma ON ma.goal_id = g.id
        WHERE g.user_id = ? AND g.id = ?
        GROUP BY g.id
        LIMIT 1
    ");
    $stmt->execute([$userId, $goalId]);
    $goal = $stmt->fetch();
    return $goal ?: null;
}

function hasGoalAllocations(int $goalId): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM monthly_allocations WHERE goal_id = ?");
    $stmt->execute([$goalId]);
    return (int)$stmt->fetchColumn() > 0;
}

function progressPercent(float $saved, float $target): float {
    if ($target <= 0) return 0.0;
    return max(0, min(100, round(($saved / $target) * 100, 1)));
}

function allocateAmountByRule(array $goals, float $amount, int $absolutePercent): array {
    $count = count($goals);
    if ($count === 0 || $amount <= 0) return [];

    $absolutePart = round($amount * ($absolutePercent / 100), 2);
    $relativePart = round($amount - $absolutePart, 2);

    $alloc = [];
    foreach ($goals as $goal) {
        $alloc[(int)$goal['id']] = 0.0;
    }

    if ($absolutePart > 0) {
        $perGoal = round($absolutePart / $count, 2);
        $running = 0.0;
        foreach ($goals as $index => $goal) {
            $isLast = $index === $count - 1;
            $value = $isLast ? round($absolutePart - $running, 2) : $perGoal;
            $alloc[(int)$goal['id']] += $value;
            $running += $value;
        }
    }

    if ($relativePart > 0) {
        $targetTotal = array_sum(array_map(fn($g) => (float)$g['target_amount'], $goals));
        $running = 0.0;
        foreach ($goals as $index => $goal) {
            $isLast = $index === $count - 1;
            $value = $targetTotal > 0
                ? round($relativePart * ((float)$goal['target_amount'] / $targetTotal), 2)
                : 0.0;

            if ($isLast) {
                $value = round($relativePart - $running, 2);
            }

            $alloc[(int)$goal['id']] += $value;
            $running += $value;
        }
    }

    return $alloc;
}

function redistributeWithOverflow(int $userId, string $month, float $amount, int $absolutePercent, string $source): void {
    $remaining = round($amount, 2);

    while ($remaining > 0.00001) {
        $goals = getOpenGoals($userId, $month);

        if (!$goals) {
            addWalletCredit($userId, $remaining);
            return;
        }

        $allocations = allocateAmountByRule($goals, $remaining, $absolutePercent);
        $overflow = 0.0;
        $allocated = 0.0;

        foreach ($goals as $goal) {
            $goalId = (int)$goal['id'];
            $saved = (float)$goal['saved_amount'];
            $target = (float)$goal['target_amount'];
            $missing = round($target - $saved, 2);
            $planned = round($allocations[$goalId] ?? 0.0, 2);

            if ($planned <= 0) continue;

            $book = min($planned, $missing);
            $overflow += round($planned - $book, 2);

            if ($book > 0) {
                $stmt = db()->prepare("
                    INSERT INTO monthly_allocations (user_id, goal_id, booking_month, source, amount)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $goalId, $month, $source, round($book, 2)]);
                $allocated += $book;
            }
        }

        $remaining = round($overflow, 2);

        if ($allocated <= 0 && $remaining > 0) {
            addWalletCredit($userId, $remaining);
            return;
        }
    }
}

function processMonthIfNeeded(int $userId, string $month): void {
    db()->beginTransaction();

    try {
        if (!automaticMonthAlreadyProcessed($userId, $month)) {
            $setting = getEffectiveMonthlySetting($userId, $month);
            $monthlyAmount = (float)$setting['monthly_amount'];
            $absolutePercent = (int)$setting['absolute_percent'];

            $credit = getCurrentWalletCredit($userId);
            $usedCredit = 0.0;

            if ($credit > 0 && count(getOpenGoals($userId, $month)) > 0) {
                $usedCredit = consumeWalletCredit($userId, $credit);
            }

            if ($monthlyAmount > 0) {
                redistributeWithOverflow($userId, $month, $monthlyAmount, $absolutePercent, 'automatic');
            }

            if ($usedCredit > 0) {
                redistributeWithOverflow($userId, $month, $usedCredit, $absolutePercent, 'credit_wallet');
            }

            markAutomaticMonthProcessed($userId, $month);
        }

        $oneTimes = getUnprocessedOneTimeContributions($userId, $month);

        foreach ($oneTimes as $oneTime) {
            $amount = (float)$oneTime['amount'];
            $targetGoalId = !empty($oneTime['target_goal_id']) ? (int)$oneTime['target_goal_id'] : null;

            if ($amount > 0) {
                if ($targetGoalId) {
                    allocateAmountToSingleGoal($userId, $targetGoalId, $month, $amount, 'one_time');
                } else {
                    $absolutePercent = (int)$oneTime['absolute_percent'];
                    redistributeWithOverflow($userId, $month, $amount, $absolutePercent, 'one_time');
                }
            }

            markOneTimeContributionProcessed((int)$oneTime['id']);
        }

        $stmt = db()->prepare("
            UPDATE goals g
            LEFT JOIN (
                SELECT goal_id, SUM(amount) AS total_saved
                FROM monthly_allocations
                GROUP BY goal_id
            ) x ON x.goal_id = g.id
            SET g.is_completed = CASE WHEN COALESCE(x.total_saved, 0) >= g.target_amount THEN 1 ELSE 0 END,
                g.completed_at = CASE
                    WHEN COALESCE(x.total_saved, 0) >= g.target_amount AND g.completed_at IS NULL THEN ?
                    ELSE g.completed_at
                END
            WHERE g.user_id = ?
        ");
        $stmt->execute([$month, $userId]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function processAllMonthsUntilCurrent(int $userId): void {
    $stmt = db()->prepare("
        SELECT MIN(d) FROM (
            SELECT MIN(start_month) AS d FROM goals WHERE user_id = ?
            UNION
            SELECT MIN(valid_from_month) AS d FROM monthly_settings WHERE user_id = ?
            UNION
            SELECT MIN(booking_month) AS d FROM one_time_contributions WHERE user_id = ?
        ) x
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $minDate = $stmt->fetchColumn();

    if (!$minDate) return;

    $cursor = date('Y-m-01', strtotime((string)$minDate));
    $current = firstDayOfCurrentMonth();

    while ($cursor <= $current) {
        processMonthIfNeeded($userId, $cursor);
        $cursor = date('Y-m-01', strtotime($cursor . ' +1 month'));
    }
}

function simulateCompletionMonth(int $userId, array $goal): ?string {
    $saved = (float)$goal['saved_amount'];
    $target = (float)$goal['target_amount'];

    if ($saved >= $target) return 'Erreicht';

    $month = firstDayOfNextMonth();
    $guard = 0;

    while ($guard < 240) {
        $openGoals = getOpenGoals($userId, $month);

        $exists = false;
        foreach ($openGoals as $openGoal) {
            if ((int)$openGoal['id'] === (int)$goal['id']) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            return monthLabel($month);
        }

        $setting = getEffectiveMonthlySetting($userId, $month);
        $monthlyAmount = (float)$setting['monthly_amount'];
        if ($monthlyAmount <= 0) return null;

        $alloc = allocateAmountByRule($openGoals, $monthlyAmount, (int)$setting['absolute_percent']);
        $add = (float)($alloc[(int)$goal['id']] ?? 0.0);

        if ($add <= 0) return null;

        $saved += $add;
        if ($saved >= $target) return monthLabel($month);

        $month = date('Y-m-01', strtotime($month . ' +1 month'));
        $guard++;
    }

    return null;
}

function dashboardStats(int $userId): array {
    $goals = getAllGoalsWithProgress($userId);

    $totalTarget = 0.0;
    $totalSaved = 0.0;
    $openCount = 0;
    foreach ($goals as $goal) {
        $totalTarget += (float)$goal['target_amount'];
        $totalSaved += (float)$goal['saved_amount'];
        if ((float)$goal['saved_amount'] < (float)$goal['target_amount']) {
            $openCount++;
        }
    }

    return [
        'goal_count' => count($goals),
        'open_count' => $openCount,
        'total_target' => $totalTarget,
        'total_saved' => $totalSaved,
    ];
}

function automaticMonthAlreadyProcessed(int $userId, string $month): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM monthly_runs WHERE user_id = ? AND booking_month = ?");
    $stmt->execute([$userId, $month]);
    return (int)$stmt->fetchColumn() > 0;
}

function markAutomaticMonthProcessed(int $userId, string $month): void {
    $stmt = db()->prepare("
        INSERT INTO monthly_runs (user_id, booking_month)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE booking_month = VALUES(booking_month)
    ");
    $stmt->execute([$userId, $month]);
}

function getUnprocessedOneTimeContributions(int $userId, string $month): array {
    $stmt = db()->prepare("
        SELECT id, amount, absolute_percent, relative_percent, target_goal_id
        FROM one_time_contributions
        WHERE user_id = ?
          AND booking_month = ?
          AND processed_at IS NULL
        ORDER BY id ASC
    ");
    $stmt->execute([$userId, $month]);
    return $stmt->fetchAll();
}

function markOneTimeContributionProcessed(int $id): void {
    $stmt = db()->prepare("
        UPDATE one_time_contributions
        SET processed_at = NOW()
        WHERE id = ? AND processed_at IS NULL
    ");
    $stmt->execute([$id]);
}

function getPaymentHistory(int $userId, int $limit = 200): array {
    $stmt = db()->prepare("
        SELECT
            ma.id,
            ma.booking_month,
            ma.source,
            ma.amount,
            ma.created_at,
            g.name AS goal_name
        FROM monthly_allocations ma
        LEFT JOIN goals g ON g.id = ma.goal_id
        WHERE ma.user_id = ?
        ORDER BY ma.booking_month DESC, ma.created_at DESC, ma.id DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function paymentSourceLabel(string $source): string {
    return match ($source) {
        'automatic' => 'Monatlich automatisch',
        'one_time' => 'Einmal-Sparsumme',
        'credit_wallet' => 'Aus Guthaben',
        'credit_reallocation' => 'Umverteiltes Guthaben',
        'initial' => 'Startwert',
        default => $source,
    };
}

function getGoalInitialAmount(int $goalId): float {
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM monthly_allocations
        WHERE goal_id = ? AND source = 'initial'
    ");
    $stmt->execute([$goalId]);
    return (float)$stmt->fetchColumn();
}

function getSelectableOpenGoals(int $userId): array {
    return getOpenGoals($userId, firstDayOfCurrentMonth());
}

function allocateAmountToSingleGoal(int $userId, int $goalId, string $month, float $amount, string $source): void {
    $amount = round($amount, 2);
    if ($amount <= 0) return;

    $goal = getGoalById($userId, $goalId);

    if (!$goal) {
        addWalletCredit($userId, $amount);
        return;
    }

    if (strtotime($goal['start_month']) > strtotime($month)) {
        addWalletCredit($userId, $amount);
        return;
    }

    $saved = (float)$goal['saved_amount'];
    $target = (float)$goal['target_amount'];
    $missing = round($target - $saved, 2);

    if ($missing <= 0) {
        addWalletCredit($userId, $amount);
        return;
    }

    $book = min($amount, $missing);
    $rest = round($amount - $book, 2);

    if ($book > 0) {
        $stmt = db()->prepare("
            INSERT INTO monthly_allocations (user_id, goal_id, booking_month, source, amount)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $goalId, $month, $source, $book]);
    }

    if ($rest > 0) {
        $openGoals = getOpenGoals($userId, $month);
        $remainingGoals = array_values(array_filter($openGoals, fn($g) => (int)$g['id'] !== $goalId));

        if ($remainingGoals) {
            $dummyAbsolute = 0;
            redistributeWithOverflow($userId, $month, $rest, $dummyAbsolute, 'credit_reallocation');
        } else {
            addWalletCredit($userId, $rest);
        }
    }
}

function getGroupedPaymentHistory(int $userId, int $limit = 500): array {
    $stmt = db()->prepare("
        SELECT
            ma.id,
            ma.booking_month,
            ma.source,
            ma.amount,
            ma.created_at,
            g.name AS goal_name
        FROM monthly_allocations ma
        LEFT JOIN goals g ON g.id = ma.goal_id
        WHERE ma.user_id = ?
        ORDER BY ma.created_at DESC, ma.id DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $grouped = [];

    foreach ($rows as $row) {
        $source = $row['source'];
        $createdAt = $row['created_at'];
        $bookingMonth = $row['booking_month'];

        if (in_array($source, ['one_time', 'initial'], true)) {
            $eventKey = $source . '|' . $createdAt;
        } else {
            $eventKey = $source . '|' . $bookingMonth;
        }

        if (!isset($grouped[$eventKey])) {
            $grouped[$eventKey] = [
                'event_key' => $eventKey,
                'booking_month' => $bookingMonth,
                'source' => $source,
                'event_date' => $createdAt,
                'total_amount' => 0.0,
                'entries' => [],
            ];
        }

        $grouped[$eventKey]['total_amount'] += (float)$row['amount'];
        $grouped[$eventKey]['entries'][] = [
            'goal_name' => $row['goal_name'] ?? '—',
            'amount' => (float)$row['amount'],
            'created_at' => $createdAt,
        ];
    }

    $grouped = array_values($grouped);

    usort($grouped, function ($a, $b) {
        return strtotime($b['event_date']) <=> strtotime($a['event_date']);
    });

    return $grouped;
}

