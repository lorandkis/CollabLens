<?php
require_once __DIR__ . '/auth.php';
require_auth();

$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$user = 'appuser';
$pass = 'apppass';

header('Content-Type: application/json');

$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if ($classId <= 0) {
    echo json_encode(['error' => 'Missing class_id']);
    exit;
}

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Verify ownership
    $currentUserId = $_SESSION['user']['id'] ?? null;
    if ($currentUserId) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE id = ? AND professor_id = ?');
        $chk->execute([$classId, (int)$currentUserId]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
    }

    $stmt = $pdo->prepare('SELECT id, title FROM assignments WHERE class_id = ? ORDER BY created_at DESC');
    $stmt->execute([$classId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['assignments' => $rows]);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
