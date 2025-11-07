<?php
require_once __DIR__ . '/auth.php';
require_auth();

$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$user = 'appuser';
$pass = 'apppass';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if ($classId <= 0) {
    header('Location: userDashboard.php');
    exit;
}

$flash = '';
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Ensure class belongs to logged-in professor
    $currentUserId = $_SESSION['user']['id'] ?? null;
    $stmt = $pdo->prepare('SELECT * FROM classes WHERE id = ? LIMIT 1');
    $stmt->execute([$classId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        throw new RuntimeException('Class not found');
    }
    if ($currentUserId && (int)$class['professor_id'] !== (int)$currentUserId) {
        throw new RuntimeException('Forbidden');
    }

    // Handle edit POST
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_class') {
    $title = trim($_POST['title'] ?? '');
    $term = trim($_POST['term'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $discord = trim($_POST['discord_server_id'] ?? '') ?: null;
    $sp_site = trim($_POST['sharepoint_site_id'] ?? '') ?: null;
    $sp_folder = trim($_POST['sharepoint_folder_id'] ?? '') ?: null;

    $up = $pdo->prepare('UPDATE classes SET title = ?, term = ?, description = ?, discord_server_id = ?, sharepoint_site_id = ?, sharepoint_folder_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND professor_id = ?');
    $up->execute([$title, $term ?: null, $description ?: null, $discord, $sp_site, $sp_folder, $classId, $currentUserId]);
    $flash = 'Class updated';

    // reload class
    $stmt->execute([$classId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
  }

  // Load assignments for class with group/student counts
  $stmt = $pdo->prepare("SELECT a.id, a.title, a.description, a.status, a.created_at,
        (SELECT COUNT(*) FROM assignment_groups g WHERE g.assignment_id = a.id) AS group_count,
        (SELECT COUNT(DISTINCT gm.student_id) FROM assignment_groups g JOIN group_members gm ON gm.group_id = g.id WHERE g.assignment_id = a.id) AS student_count
        FROM assignments a
        WHERE a.class_id = ?
        ORDER BY a.created_at DESC");
    $stmt->execute([$classId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    // simple error page
    http_response_code(500);
    echo '<h1>Error</h1><pre>' . h($e->getMessage()) . '</pre>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Class: <?= h($class['title']) ?></title>
  <link rel="icon" type="image/x-icon" href="/reasources/baj_logo.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/css/uikit.min.css" />
  <link rel="stylesheet" href="/reasources/css/custom.css" />
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit-icons.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.uikit.min.css" />
  <style>
    .clickable-row { cursor: pointer; }
    .clickable-row:hover td { color: #1e87f0 !important; }
    .meta span + span { margin-left: 12px }
    .logout-link {
      color: white !important;
      padding: 8px 14px;
      border-radius: 4px;
      transition: background 0.25s ease;
    }
    .logout-link:hover {
      background: rgba(0,0,0,0.2);
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

  <div class="uk-container uk-container-expand">
    
    <?php if ($flash): ?>
      <div class="uk-alert-success" uk-alert><a class="uk-alert-close" uk-close></a><p><?= h($flash) ?></p></div>
    <?php endif; ?>

      <br/>
      <div class="uk-grid uk-grid-medium" >
        <div class="uk-width-expand">
          <!-- Top buttons -->
          <button class="uk-button uk-button-small uk-button-secondary uk-border-rounded" uk-toggle="target: #modal-edit-class"><span class="uk-margin-small-right" uk-icon="pencil"></span> Edit Class</button>
          <button class="uk-button uk-button-small uk-button-primary uk-border-rounded" uk-toggle="target: #modal-discord-setup" style="margin-left:8px;">
            <span class="uk-margin-small-right" uk-icon="discord"></span> Setup </button>
          <!-- Title -->
          <h1 class="uk-heading-medium" style="margin-top: 10px; margin-bottom: 0;"><?= h($class['title']) ?></h1>
          <!-- Breadcrumb -->
          <div class="uk-text-small uk-text-primary" style="margin-top:6px; margin-bottom: 10px;">
            <a href="/userDashboard.php">Dashboard</a> / <strong><?= h($class['title']) ?></strong>
          </div>
            <div class="uk-margin-small-top"><p class="uk-text-lead"><?= h($class['description'] ?? '') ?></p></div>
            <div class="uk-text-meta meta">
              <span>Term: <strong><?= h($class['term'] ?? '—') ?></strong></span>
              <span>Students: <strong><?php
                  // fetch student count (if enrollment table exists)
                  try {
                    $cst = $pdo->prepare('SELECT COUNT(DISTINCT cs.student_id) FROM class_students cs WHERE cs.class_id = ?');
                    $cst->execute([$classId]);
                    echo (int)$cst->fetchColumn();
                  } catch (Throwable $_) { echo '—'; }
                ?></strong></span>
              <span>Discord Server: <strong><?= h($class['discord_server_id'] ?? '—') ?></strong></span>
              <span>SharePoint Site: <strong><?= h($class['sharepoint_site_id'] ?? '—') ?></strong></span>
              <span>SharePoint Folder: <strong><?= h($class['sharepoint_folder_id'] ?? '—') ?></strong></span>
            </div>
        </div>
      </div>

      <hr class="divider" />

      
    

    <!-- Edit Modal -->
    <div id="modal-edit-class" uk-modal>
      <div class="uk-modal-dialog uk-modal-body">
        <h2 class="uk-modal-title">Edit Class</h2>
        <form method="post">
          <input type="hidden" name="action" value="edit_class" />
          <div class="uk-margin">
            <label class="uk-form-label">Title</label>
            <div class="uk-form-controls"><input class="uk-input" name="title" value="<?= h($class['title']) ?>" required /></div>
          </div>
          <div class="uk-margin">
            <label class="uk-form-label">Term</label>
            <div class="uk-form-controls">
              <select class="uk-select" name="term">
                <?php
                  $terms = ['Winter','Spring','Summer','Fall','Intersession']; // NOTE: Hardcoded academic terms!
                  $cur = isset($class['term']) ? trim((string)$class['term']) : '';
                ?>
                <option value="">Select term</option>
                <?php foreach ($terms as $t): ?>
                  <option value="<?= h($t) ?>" <?= ($cur !== '' && strcasecmp($cur, $t) === 0) ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="uk-margin">
            <label class="uk-form-label">Discord Server ID</label>
            <div class="uk-form-controls"><input class="uk-input" name="discord_server_id" value="<?= h($class['discord_server_id'] ?? '') ?>" /></div>
          </div>
          <div class="uk-margin">
            <label class="uk-form-label">SharePoint Site ID</label>
            <div class="uk-form-controls"><input class="uk-input" name="sharepoint_site_id" value="<?= h($class['sharepoint_site_id'] ?? '') ?>" /></div>
          </div>
          <div class="uk-margin">
            <label class="uk-form-label">SharePoint Folder ID</label>
            <div class="uk-form-controls"><input class="uk-input" name="sharepoint_folder_id" value="<?= h($class['sharepoint_folder_id'] ?? '') ?>" /></div>
          </div>
          <div class="uk-margin">
            <label class="uk-form-label">Description</label>
            <div class="uk-form-controls"><textarea class="uk-textarea" name="description"><?= h($class['description'] ?? '') ?></textarea></div>
          </div>
          <div class="uk-text-right">
            <button class="uk-button uk-button-default uk-modal-close" type="button">Cancel</button>
            <button class="uk-button uk-button-primary" type="submit">Save</button>
          </div>
        </form>
      </div>
    </div>

  </div>

    <!-- Discord Setup Modal -->
    <div id="modal-discord-setup" uk-modal>
      <div class="uk-modal-dialog uk-modal-body">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <h2 class="uk-modal-title">Discord Setup</h2>
        <!-- NOTE: Hardcoded Discord bot link! -->
        <p>1. Create an empty Discord server <br /> 2. Invite <a href="<?= htmlspecialchars('https://discord.com/oauth2/authorize?client_id=1385862968696115230&permissions=8&scope=bot') ?>" target="_blank" rel="noopener">our bot</a> <br />3. Run the command <code>/format <?= (int)$classId ?></code>. <br />4. Invite your students to the server.</p>
        <div class="uk-text-right uk-margin-top">
          <button class="uk-button uk-button-default uk-modal-close" type="button">Close</button>
        </div>
      </div>
    </div>

    <div class="uk-container uk-container-expand">
    <h2>Assignments</h3>
        <div class="uk-overflow-auto">
          <table id="assignmentsTable" class="uk-table uk-table-hover uk-table-striped">
            <thead>
              <tr><th>Title</th><th>Description</th><th># of Groups</th><th># of Students</th><th>Status</th><th>Created</th></tr>
            </thead>
            <tbody>
              <?php foreach ($assignments as $a):
                  $status = (string)$a['status'];
                  $labelClass = 'uk-label';
                  if ($status === 'active') {
                      $labelClass = 'uk-label-success';
                  } elseif ($status === 'archived') {
                      $labelClass = 'uk-label-warning';
                  }
                  $labelClass .= " uk-box-shadow-large uk-box-shadow-hover-small";
              ?>
                <tr class="clickable-row" onclick="window.location='assignment.php?assignment_id=<?= (int)$a['id'] ?>'">
                  <td><?= h($a['title']) ?></a></td>
                  <td><?= h(mb_strimwidth($a['description'] ?? '', 0, 55, '...')) ?></td>
                  <td class="uk-text-center"><?= (int)$a['group_count'] ?></td>
                  <td class="uk-text-center"><?= (int)$a['student_count'] ?></td>
                  <td><span class="uk-label <?php echo $labelClass; ?>"><?php echo h($status); ?></span></td>
                  <td><?= h($a['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
  </div>

  <script>
    $(function(){ $('#assignmentsTable').DataTable({ pageLength: 10 }); });
    document.addEventListener('DOMContentLoaded', function(){ document.querySelectorAll('.uk-container').forEach(el => el.classList.add('ready')); });
  </script>
</body>
</html>
