<?php
// assignment.php — full-page view for one assignment

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_auth();

// --- Input guard ---
$assignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
if ($assignmentId <= 0) {
    http_response_code(400);
    echo "Missing or invalid assignment_id.";
    exit;
}

// --- DB connection ---
$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$user = 'appuser';
$pass = 'apppass';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$flash = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]);

  // Assignment header (join class to retrieve class-level discord_server_id)
  $stmt = $pdo->prepare("
    SELECT 
      a.id,
      a.title,
      a.description,
      a.status,
      a.created_at,
      a.due_date,
       c.discord_server_id,
       c.id AS class_id,
       c.title AS class_title,
      a.sharepoint_site_id,
      a.sharepoint_folder_id
    FROM assignments a
    LEFT JOIN classes c ON c.id = a.class_id
    WHERE a.id = ?
    LIMIT 1
  ");
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        http_response_code(404);
        echo "Assignment not found.";
        exit;
    }

    // Authorization: ensure this assignment belongs to current professor
    $currentUserId = $_SESSION['user']['id'] ?? null;
    if ($currentUserId) {
      $chk = $pdo->prepare("SELECT COUNT(*) FROM classes c JOIN assignments a2 ON a2.class_id = c.id WHERE a2.id = ? AND c.professor_id = ?");
      $chk->execute([$assignmentId, (int)$currentUserId]);
      if (!$chk->fetchColumn()) {
        http_response_code(403);
        echo "Forbidden: you don't have access to this assignment.";
        exit;
      }
    }

    // Handle edit POST (update assignment)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_assignment') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $due_date = trim($_POST['due_date'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
        $sp_site = trim($_POST['sharepoint_site_id'] ?? '') ?: null;
        $sp_folder = trim($_POST['sharepoint_folder_id'] ?? '') ?: null;

    // Normalize datetime-local input (HTML5) to MySQL DATETIME ('Y-m-d H:i:s')
    if ($due_date !== '') {
      // HTML5 datetime-local format is 'YYYY-MM-DDTHH:MM' optionally with seconds
      $due_date = str_replace('T', ' ', $due_date);
      // If seconds are missing, add :00
      if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $due_date)) {
        $due_date .= ':00';
      }
    }

    $up = $pdo->prepare('UPDATE assignments SET title = ?, description = ?, due_date = ?, status = ?, sharepoint_site_id = ?, sharepoint_folder_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $up->execute([$title, $description ?: null, $due_date ?: null, $status, $sp_site, $sp_folder, $assignmentId]);

        // reload assignment header
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        $flash = 'Assignment updated';
    }

    // Groups
  $stmt = $pdo->prepare("
    SELECT
      g.id,
      g.name,
      g.discord_channel_id,
      g.sharepoint_folder_id,
      COUNT(gm.id) AS member_count,
      GROUP_CONCAT(
        DISTINCT CONCAT(s.first_name, ' ', s.last_name, ' (', s.student_id, ')')
        ORDER BY s.last_name, s.first_name SEPARATOR ', '
      ) AS members
    FROM assignment_groups g
    LEFT JOIN group_members gm ON gm.group_id = g.id
    LEFT JOIN students s ON s.id = gm.student_id
    WHERE g.assignment_id = ?
    GROUP BY g.id, g.name, g.discord_channel_id, g.sharepoint_folder_id
    ORDER BY g.id ASC
  ");
    $stmt->execute([$assignmentId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Students
    $stmt = $pdo->prepare("
        SELECT 
            s.id AS sid,
            s.student_id,
            s.first_name,
            s.last_name,
            s.email,
            MAX(gm.discord_username) AS discord_username,
            MAX(gm.discord_user_id)  AS discord_user_id
    FROM students s
    INNER JOIN group_members gm ON gm.student_id = s.id
    INNER JOIN assignment_groups g ON g.id = gm.group_id
    WHERE g.assignment_id = ?
        GROUP BY s.id, s.student_id, s.first_name, s.last_name, s.email
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$assignmentId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    http_response_code(500);
    echo "Database error: " . h($e->getMessage());
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo h($assignment['title']); ?> — Assignment Details</title>
  <link rel="icon" type="image/x-icon" href="/reasources/baj_logo.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/css/uikit.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit-icons.min.js"></script>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.uikit.min.css" />
  <link rel="stylesheet" href="/reasources/css/custom.css" />

  <style>
    .clickable-row { cursor: pointer; }
    .clickable-row:hover td { color: #1e87f0 !important; }
    .meta span + span { margin-left: 12px; }
    .divider { border: none; border-top: 1px solid #ddd; margin: 0; }
    .logout-link {
      color: white !important;
      padding: 8px 14px;
      border-radius: 4px;
      transition: background 0.25s ease;
    }
    .logout-link:hover {
      background: rgba(0,0,0,0.2); /* subtle dark overlay */
      text-decoration: none;
    }
  </style>
</head>
<body>

  <!-- Nav Bar -->
  <nav class="uk-navbar-container" style="background: #1e87f0;">
      <div class="uk-container">
          <div uk-navbar>
              <div class="uk-navbar-left">
                  <ul class="uk-navbar-nav">
                      <li class="uk-active"><a href="/userDashboard.php"><img src="/reasources/baj_logo.svg" alt="BAJ Logo" style="height: 85px;"> <h2 style="color: white; display: inline; margin: 0;">CollabLens</h2></a></li>
                  </ul>
              </div>
              <div class="uk-navbar-right">
                <ul class="uk-navbar-nav">
                  <li><a href="/logout.php" rel="nofollow"><h4 class="logout-link" style="margin: 0;">Logout</h4></a></li>
                </ul>
              </div>
          </div>
      </div>
  </nav>

  <br/>

  <div class="uk-container uk-container-expand">

    <div class="uk-margin">
      <div class="uk-grid uk-grid-medium" uk-grid>
        <div class="uk-width-expand">
          <!-- Top buttons -->
          <button class="uk-button uk-button-small uk-button-secondary uk-border-rounded" uk-toggle="target: #modal-edit-assignment"><span class="uk-margin-small-right" uk-icon="pencil"></span>Edit Assignment</button>
          <button class="uk-button uk-button-small uk-button-primary uk-border-rounded" uk-toggle="target: #modal-discord-setup-assignment" style="margin-left:8px;">
            <span class="uk-margin-small-right" uk-icon="discord"></span> Setup </button>
          <!-- Title -->
          <h1 class="uk-heading-medium uk-text-primary" style="margin-top: 10px;"><?php echo h($assignment['title']); ?></h1>
          <!-- Breadcrumb -->
            <div class="uk-text-small uk-text-primary" style="margin-top:6px;">
              <a  href="/userDashboard.php">Dashboard</a>
              <?php if (!empty($assignment['class_id'])): ?>
                / <a href="classes.php?class_id=<?= (int)$assignment['class_id'] ?>"><?= h($assignment['class_title'] ?? 'Class') ?></a>
              <?php endif; ?>
              / <strong><?= h($assignment['title']) ?></strong>
            </div>
        </div>

      </div>
      <p class="uk-text-lead"><?php echo nl2br(h($assignment['description'] ?? '')); ?></p>

      <div class="uk-text-meta meta">
        <span>Created: <?php echo h($assignment['created_at']); ?></span>
        <span>Due: <?php echo h($assignment['due_date']); ?></span>
        <span>
          Status:
          <span class="uk-label <?php
              $cls = '';
              if ($assignment['status'] === 'active')    $cls = 'uk-label-success';
              elseif ($assignment['status'] === 'completed') $cls = 'uk-label';
              elseif ($assignment['status'] === 'archived')  $cls = 'uk-label-warning';
              echo h($cls);
          ?>">
            <?php echo h($assignment['status']); ?>
          </span>
        </span>
        <?php if (!empty($assignment['discord_server_id'])): ?>
          <span>Discord Server: <?php echo h($assignment['discord_server_id']); ?></span>
        <?php endif; ?>
        <?php if (!empty($assignment['sharepoint_site_id'])): ?>
          <span>SharePoint Site: <?php echo h($assignment['sharepoint_site_id']); ?></span>
        <?php endif; ?>
        <?php if (!empty($assignment['sharepoint_folder_id'])): ?>
          <span>SharePoint Folder: <?php echo h($assignment['sharepoint_folder_id']); ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($flash)): ?>
      <div class="uk-alert-success" uk-alert><a class="uk-alert-close" uk-close></a><p><?php echo h($flash); ?></p></div>
    <?php endif; ?>

    <!-- Equal height grid -->
    <div class="uk-grid-large uk-grid-match" uk-grid uk-height-match="target: > div > .uk-card">
      <div class="uk-width-1-1 uk-width-1-2@m">
        <div class="uk-card uk-card-default uk-card-body uk-height-1-1 uk-flex uk-flex-column">
          <h3 class="uk-card-title">All Groups</h3>
          <div class="uk-flex-1 uk-overflow-auto">
            <table id="groupsTable" class="uk-table uk-table-hover uk-table-striped uk-table-small">
              <thead>
                <tr>
                  <th>Group ID</th>
                  <th>Name</th>
                  <th>Members</th>
                  <th>Discord Channel ID</th>
                  <th>SharePoint Folder ID</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($groups as $g): ?>
                  <tr class="clickable-row" onclick="window.location.href='groupView.php?group_id=<?php echo (int)$g['id']; ?>'">
                    <td><?php echo h($g['id']); ?></td>
                    <td><?php echo h($g['name']); ?></td>
                    <td><?php echo h($g['members'] ?: '—'); ?></td>
                    <td><?php echo h($g['discord_channel_id'] ?: '—'); ?></td>
                    <td><?php echo h($g['sharepoint_folder_id'] ?: '—'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="uk-width-1-1 uk-width-1-2@m">
        <div class="uk-card uk-card-default uk-card-body uk-height-1-1 uk-flex uk-flex-column">
          <h3 class="uk-card-title">All Students</h3>
          <div class="uk-flex-1 uk-overflow-auto">
            <table id="studentsTable" class="uk-table uk-table-striped uk-table-small">
              <thead>
                <tr>
                  <th>Student ID</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Discord</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($students as $s): 
                  $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                  $discord = $s['discord_username'] ?: $s['discord_user_id'];
                ?>
                  <tr>
                    <td><?php echo h($s['student_id']); ?></td>
                    <td><?php echo h($name !== '' ? $name : '—'); ?></td>
                    <td><?php echo h($s['email'] ?: '—'); ?></td>
                    <td><?php echo h($discord ?: '—'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>

  <script>
    $(function () {
      $('#groupsTable').DataTable({ pageLength: 10 });
      $('#studentsTable').DataTable({ pageLength: 10 });
    });
    document.addEventListener('DOMContentLoaded', function(){ document.querySelectorAll('.uk-container').forEach(el => el.classList.add('ready')); });
  </script>
  
  <!-- Edit Assignment Modal -->
  <div id="modal-edit-assignment" uk-modal>
    <div class="uk-modal-dialog uk-modal-body">
      <h2 class="uk-modal-title">Edit Assignment</h2>
      <form method="post">
        <input type="hidden" name="action" value="edit_assignment" />
        <div class="uk-margin">
          <label class="uk-form-label">Title</label>
          <div class="uk-form-controls"><input class="uk-input" name="title" value="<?php echo h($assignment['title']); ?>" required /></div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">Due Date</label>
          <div class="uk-form-controls">
            <?php
              $dueVal = '';
              if (!empty($assignment['due_date'])) {
                // Convert 'Y-m-d H:i:s' to 'Y-m-d\TH:i' for datetime-local
                $dt = new DateTime($assignment['due_date']);
                $dueVal = $dt->format('Y-m-d\TH:i');
              }
            ?>
            <input class="uk-input" type="datetime-local" name="due_date" value="<?= h($dueVal) ?>" />
          </div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">Status</label>
          <div class="uk-form-controls">
            <select name="status" class="uk-select">
              <option value="active" <?php echo ($assignment['status'] === 'active') ? 'selected' : ''; ?>>active</option>
              <option value="completed" <?php echo ($assignment['status'] === 'completed') ? 'selected' : ''; ?>>completed</option>
              <option value="archived" <?php echo ($assignment['status'] === 'archived') ? 'selected' : ''; ?>>archived</option>
            </select>
          </div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">SharePoint Site ID</label>
          <div class="uk-form-controls"><input class="uk-input" name="sharepoint_site_id" value="<?php echo h($assignment['sharepoint_site_id'] ?? ''); ?>" /></div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">SharePoint Folder ID</label>
          <div class="uk-form-controls"><input class="uk-input" name="sharepoint_folder_id" value="<?php echo h($assignment['sharepoint_folder_id'] ?? ''); ?>" /></div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">Description</label>
          <div class="uk-form-controls"><textarea class="uk-textarea" name="description"><?php echo h($assignment['description'] ?? ''); ?></textarea></div>
        </div>
        <div class="uk-text-right">
          <button class="uk-button uk-button-default uk-modal-close" type="button">Cancel</button>
          <button class="uk-button uk-button-primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>
  
  <!-- Discord Setup Modal for Assignment -->
  <div id="modal-discord-setup-assignment" uk-modal>
    <div class="uk-modal-dialog uk-modal-body">
      <button class="uk-modal-close-default" type="button" uk-close></button>
      <h2 class="uk-modal-title">Discord Setup</h2>
      <ol>
        <li>Go into your class server</li>
        <li>Go to the '🤖Commands' channel</li>
        <li>Run the command <code>/createGroups <?= (int)$assignmentId ?></code></li>
      </ol>
      <div class="uk-text-right uk-margin-top">
        <button class="uk-button uk-button-default uk-modal-close" type="button">Close</button>
      </div>
    </div>
  </div>
</body>
</html>
