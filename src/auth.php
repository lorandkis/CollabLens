<?php
// auth.php - simple session-based auth helpers
// Place in src/ and include with require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    // Secure session cookie params (won't set 'secure' by default for local dev)
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

function require_auth(): void {
    if (!is_logged_in()) {
        // preserve requested URL for redirect after login
        $redir = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: login.php?next=' . urlencode($redir));
        exit;
    }
}

/**
 * Attempt to log the user in. Expects a PDO and a professors table with columns
 * (id, email, password_hash, first_name, last_name, org_id).
 * Returns array(user) on success or null on failure.
 */
function login_user(PDO $pdo, string $email, string $password, bool $remember = false): ?array {
    $stmt = $pdo->prepare('SELECT id, email, password_hash, first_name, last_name, org_id FROM professors WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    // password_hash should be created with password_hash(..., PASSWORD_DEFAULT)
    $hash = $row['password_hash'] ?? null;
    if (!$hash) return null;
    if (!password_verify($password, $hash)) return null;

    // Regenerate session id
    session_regenerate_id(true);

    // Optionally extend cookie lifetime for "remember me"
    if ($remember) {
        // 30 days
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), time() + 60*60*24*30, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    $user = [
        'id' => (int)$row['id'],
        'email' => $row['email'],
        'first_name' => $row['first_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'org_id' => isset($row['org_id']) ? (int)$row['org_id'] : null,
    ];

    $_SESSION['user'] = $user;

    return $user;
}

function logout_user(): void {
    // Clear session and cookie
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
