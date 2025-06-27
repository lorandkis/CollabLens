<?php
$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$user = 'appuser';
$pass = 'apppass';

header('Content-Type: application/json');

$assignmentId = $_GET['assignment_id'] ?? null;
if (!$assignmentId) {
    echo json_encode(['error' => 'Missing assignment_id']);
    exit;
}

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get groups
    $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE assignment_id = ?");
    $stmt->execute([$assignmentId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groups as &$group) {
        $stmtMembers = $pdo->prepare("
            SELECT CONCAT(s.first_name, ' ', s.last_name) AS name
            FROM group_members gm
            JOIN students s ON s.id = gm.student_id
            WHERE gm.group_id = ?
        ");
        $stmtMembers->execute([$group['id']]);
        $members = $stmtMembers->fetchAll(PDO::FETCH_COLUMN); // only names
        $group['group_members'] = implode(', ', $members);
    }

    // Get students
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.student_id AS student_id,
            CONCAT(s.first_name, ' ', s.last_name) AS name,
            s.email AS school_email,
            s.discord_username AS discord_user
        FROM students s
        JOIN group_members gm ON s.id = gm.student_id
        JOIN `groups` g ON g.id = gm.group_id
        WHERE g.assignment_id = ?
        ORDER BY student_id;
    ");
    $stmt->execute([$assignmentId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);


    echo json_encode([
        'groups' => $groups,
        'students' => $students
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
