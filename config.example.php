<?php
declare(strict_types=1);

session_start();

define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    return $pdo;
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function userId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function requireLogin(): void {
    if (!userId()) {
        header('Location: login.php');
        exit;
    }
}

function firstDayOfCurrentMonth(): string {
    return date('Y-m-01');
}

function firstDayOfNextMonth(): string {
    return date('Y-m-01', strtotime('first day of next month'));
}

function monthLabel(string $date): string {
    return date('m/Y', strtotime($date));
}

function euro(float $value): string {
    return number_format($value, 2, ',', '.') . ' €';
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(419);
        exit('CSRF-Validierung fehlgeschlagen.');
    }
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function monthInputValueFromDate(string $date): string {
    return date('Y-m', strtotime($date));
}

function normalizeMonthInputToDate(?string $value): ?string {
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
        return null;
    }

    [$year, $month] = explode('-', $value);

    if (!checkdate((int)$month, 1, (int)$year)) {
        return null;
    }

    return sprintf('%04d-%02d-01', (int)$year, (int)$month);
}
