<?php
require_once __DIR__ . '/auth.php';
require_auth();

$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$user = 'appuser';
$pass = 'apppass';

header('Content-Type: application/json');

$assignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
$scope = $_GET['scope'] ?? 'All';

if ($assignmentId <= 0) {
    echo json_encode(['error' => 'Missing assignment_id']);
    exit;
}

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $currentUserId = $_SESSION['user']['id'] ?? null;
    if ($currentUserId) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM assignments a JOIN classes c ON c.id = a.class_id WHERE a.id = ? AND c.professor_id = ?');
        $chk->execute([$assignmentId, (int)$currentUserId]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
    }

    // Build base query joining students -> group_members -> assignment_groups
    if ($scope === 'All') {
        $stmt = $pdo->prepare("SELECT DISTINCT s.email FROM students s
            JOIN group_members gm ON gm.student_id = s.id
            JOIN assignment_groups g ON g.id = gm.group_id
            WHERE g.assignment_id = ? AND s.email IS NOT NULL");
        $stmt->execute([$assignmentId]);
    } elseif ($scope === 'Unregistered') {
        $stmt = $pdo->prepare("SELECT DISTINCT s.email FROM students s
            JOIN group_members gm ON gm.student_id = s.id
            JOIN assignment_groups g ON g.id = gm.group_id
            WHERE g.assignment_id = ? AND gm.status = 'unregistered' AND s.email IS NOT NULL");
        $stmt->execute([$assignmentId]);
    } elseif ($scope === 'Sub 7% msg') {
        // Students whose message contribution for their group's channel is < 7%
        // join a derived table that contains total messages per channel to avoid correlated subqueries
        $stmt = $pdo->prepare("SELECT DISTINCT s.email
            FROM students s
            JOIN group_members gm ON gm.student_id = s.id
            JOIN assignment_groups g ON g.id = gm.group_id
            LEFT JOIN discord_messages dm ON dm.channel_id = g.discord_channel_id AND dm.author_id = gm.discord_user_id
            LEFT JOIN (
               SELECT channel_id, COUNT(*) AS total_msgs
               FROM discord_messages
               GROUP BY channel_id
            ) totals ON totals.channel_id = g.discord_channel_id
            WHERE g.assignment_id = ?
            GROUP BY s.id, totals.total_msgs
            HAVING COALESCE(totals.total_msgs,0) > 0 AND (COUNT(dm.id) * 100) < 7 * totals.total_msgs");
        $stmt->execute([$assignmentId]);
    } elseif ($scope === 'Sub 7% work') {
        // Students whose SharePoint activity contribution for their group's folder is < 7%
        // join derived totals per folder
        $stmt = $pdo->prepare("SELECT DISTINCT s.email
            FROM students s
            JOIN group_members gm ON gm.student_id = s.id
            JOIN assignment_groups g ON g.id = gm.group_id
            LEFT JOIN sharepoint_activities sa ON sa.folder_id = g.sharepoint_folder_id
            LEFT JOIN (
               SELECT folder_id, COUNT(*) AS total_acts
               FROM sharepoint_activities
               GROUP BY folder_id
            ) totals ON totals.folder_id = g.sharepoint_folder_id
            WHERE g.assignment_id = ?
            GROUP BY s.id, totals.total_acts
            HAVING COALESCE(totals.total_acts,0) > 0 AND (SUM(CASE WHEN sa.user_email IS NOT NULL AND sa.user_email = s.email THEN 1 ELSE 0 END) * 100) < 7 * totals.total_acts");
        $stmt->execute([$assignmentId]);
    } else {
        echo json_encode(['error' => 'Unknown scope']);
        exit;
    }

    $emails = array_filter(array_map(fn($r) => $r[0] ?? null, $stmt->fetchAll(PDO::FETCH_NUM)));
    echo json_encode(['emails' => $emails]);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
