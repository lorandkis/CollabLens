<?php
require_once __DIR__ . '/auth.php';
require_auth();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['group_id'])) {
    $params = [];
    $params['group_id'] = $_GET['group_id'] ?? '';
    header('Location: report.php?' . http_build_query($params));
  exit;
}

// Pull any flash set by a previous POST (Post-Redirect-Get). This ensures
// modal messages survive a redirect but won't reappear on reload that
// re-submits a POST. The session is started in auth.php via require_auth().
$flashMessage = '';
$flashAction = null;
if (!empty($_SESSION['flash_action'])) {
    $flashAction = $_SESSION['flash_action'];
    $flashMessage = $_SESSION['flash_message'] ?? '';
    unset($_SESSION['flash_action'], $_SESSION['flash_message']);
}

$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$user = 'appuser';
$pass = 'apppass';

$professorName = "";
$totalAssignments = "";
$totalGroups = "";
$classes = [];
$classesExist = false;
$enrollTableExists = false;

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Professor: prefer the currently logged-in user (from session)
    $currentUserId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    if ($currentUserId) {
      $stmt = $pdo->prepare("SELECT * FROM professors WHERE id = ? LIMIT 1");
      $stmt->execute([$currentUserId]);
    } else {
      // Fallback (legacy) — pick the first professor if session info missing
      $stmt = $pdo->prepare("SELECT * FROM professors LIMIT 1");
      $stmt->execute();
    }

    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $professorName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: ($row['email'] ?? '');
      $professorId = (int)$row['id'];
      $professorOrgId = isset($row['org_id']) ? (int)$row['org_id'] : null;
    } else {
      $professorId = null;
      $professorOrgId = null;
    }

  // Helper: minimal XLSX reader (reads sheet1) and CSV fallback
  function readXlsxFile(string $filePath): array {
    $zip = new ZipArchive;
    if ($zip->open($filePath) === true) {
      $xml = $zip->getFromName('xl/sharedStrings.xml');
      $strings = [];
      if ($xml !== false) {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        foreach ($dom->getElementsByTagName('si') as $si) {
          $strings[] = $si->textContent;
        }
      }

      $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
      $data = [];
      if ($xml !== false) {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        foreach ($dom->getElementsByTagName('row') as $row) {
          $rowData = [];
          foreach ($row->getElementsByTagName('c') as $c) {
            $v = $c->getElementsByTagName('v')->item(0);
            if ($v) {
              $val = $v->nodeValue;
              $type = $c->getAttribute('t');
              $rowData[] = ($type === 's') ? ($strings[(int)$val] ?? $val) : $val;
            } else {
              $rowData[] = null;
            }
          }
          $data[] = $rowData;
        }
      }
      $zip->close();
      return $data;
    }
    return [];
  }

  function excelSerialToDateTime($serial) {
    $baseDate = new DateTime('1899-12-30');
    $interval = new DateInterval('PT' . round($serial * 86400) . 'S');
    $baseDate->add($interval);
    return $baseDate->format('Y-m-d H:i:s');
  }

  function sanitizeRowValues($row) {
    return array_map(function ($val) {
      $val = is_string($val) ? html_entity_decode(trim($val)) : $val;
      if ($val === '') return null;
      if (is_numeric($val) && $val > 10000 && $val < 60000) {
        return excelSerialToDateTime((float)$val);
      }
      if (is_string($val) && (preg_match('/^\d{4}-\d{2}-\d{2}/', $val) || preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}/', $val))) {
        return date("Y-m-d H:i:s", strtotime($val));
      }
      return $val;
    }, $row);
  }

  // Helper to insert or fetch existing student
  function fetchOrCreateStudent(PDO $pdo, ?string $studentId, ?string $email, ?string $first, ?string $last, ?int $orgId) {
    // Prefer lookup by email (CSV provides email reliably); fall back to student_id if present
    if ($email) {
      $stmt = $pdo->prepare('SELECT id FROM students WHERE email = ? LIMIT 1');
      $stmt->execute([$email]);
      if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) return (int)$r['id'];
    }

    if ($studentId) {
      $stmt = $pdo->prepare('SELECT id FROM students WHERE student_id = ? LIMIT 1');
      $stmt->execute([$studentId]);
      if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) return (int)$r['id'];
    }

    // Insert new student record
    $stmt = $pdo->prepare('INSERT INTO students (student_id, org_id, email, password_hash, first_name, last_name, email_verified_at) VALUES (?, ?, ?, NULL, ?, ?, NULL)');
    $sid = $studentId ?: null;
    $stmt->execute([$sid, $orgId, $email, $first, $last]);
    return (int)$pdo->lastInsertId();
  }

  // Handle POST actions for Create Class / Create Assignment
  // Do not clobber $flashMessage/$flashAction read from session above
  if (!isset($flashMessage)) $flashMessage = '';
  if (!isset($flashAction)) $flashAction = null; // 'create_class' | 'create_assignment' when we want the modal to show the message
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'create_class') {
      $name = trim($_POST['name'] ?? '');
      $termRaw = trim($_POST['term'] ?? '');
      $year = (int)($_POST['year'] ?? date('Y'));
      $description = trim($_POST['description'] ?? null);

      $termMap = [
        'winter' => 'winter', 'spring' => 'spring', 'summer' => 'summer', 'fall' => 'fall', 'intersession' => 'intersession'
      ];
      $termKey = strtolower($termRaw);
      $term = $termMap[$termKey] ?? ($termKey ?: 'fall');

      // determine org_id for professor or fallback to 1
      $orgId = $professorOrgId ?: 1;

      $stmtIns = $pdo->prepare('INSERT INTO classes (org_id, professor_id, title, term, description, status) VALUES (?, ?, ?, ?, ?, ? )');
  $stmtIns->execute([$orgId, $professorId, $name, $term, $description, 'active']);
  $newClassId = (int)$pdo->lastInsertId();

  $flashAction = 'create_class';
  $flashMessage = "Class created! Please make a plain discord server titled '" . htmlspecialchars($name, ENT_QUOTES) . " - " . ucfirst($term) . " " . intval($year) . "' then go to our {discord bot link} and add it to your server";
    } elseif ($action === 'create_assignment') {
      // Create assignment and parse uploaded student_file for groups
      $title = trim($_POST['title'] ?? '');
      $classId = (int)($_POST['class_id'] ?? 0);
      $description = trim($_POST['description'] ?? null);
      // default due date 2 weeks out
      $dueDate = date('Y-m-d H:i:s', strtotime('+14 days'));
      // If the form provided a due_date (datetime-local), normalize it to MySQL DATETIME
      if (!empty($_POST['due_date'])) {
        $rawDue = trim((string)($_POST['due_date'] ?? ''));
        // HTML5 datetime-local uses 'YYYY-MM-DDTHH:MM' (no seconds). Convert 'T' to space
        $rawDue = str_replace('T', ' ', $rawDue);
        // If seconds missing, append :00
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $rawDue)) {
          $rawDue .= ':00';
        }
        $ts = strtotime($rawDue);
        if ($ts !== false) {
          $dueDate = date('Y-m-d H:i:s', $ts);
        }
      }

      try {
        $pdo->beginTransaction();

        $stmtA = $pdo->prepare('INSERT INTO assignments (class_id, title, description, due_date, status) VALUES (?, ?, ?, ?, ?)');
        $stmtA->execute([$classId, $title, $description, $dueDate, 'active']);
        $assignmentId = (int)$pdo->lastInsertId();

        // Process uploaded file
        $groupsByName = [];
        if (!empty($_FILES['student_file']['tmp_name']) && is_uploaded_file($_FILES['student_file']['tmp_name'])) {
          $tmp = $_FILES['student_file']['tmp_name'];
          $name = strtolower($_FILES['student_file']['name'] ?? '');
          $rows = [];
          if (str_ends_with($name, '.csv') || str_ends_with($name, '.txt')) {
            // parse CSV
            if (($fh = fopen($tmp, 'r')) !== false) {
              while (($data = fgetcsv($fh)) !== false) {
                $rows[] = $data;
              }
              fclose($fh);
            }
          } else {
            // try XLSX
            $rows = readXlsxFile($tmp);
          }

          if (count($rows) >= 1) {
            $headers = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0]);
            // heuristics for column indexes
            $groupIdx = null; $studentIdIdx = null; $emailIdx = null; $nameIdx = null;
            foreach ($headers as $i => $h) {
              if ($groupIdx === null && preg_match('/group/', $h)) $groupIdx = $i;
              if ($studentIdIdx === null && preg_match('/student.*id|student_id|studentid|student number|sid|s id/', $h)) $studentIdIdx = $i;
              if ($emailIdx === null && preg_match('/email/', $h)) $emailIdx = $i;
              if ($nameIdx === null && preg_match('/name/', $h)) $nameIdx = $i;
            }
            // fallback: first col as group, others for student
            for ($r = 1; $r < count($rows); $r++) {
              $row = sanitizeRowValues($rows[$r]);
              $groupName = $groupIdx !== null ? ($row[$groupIdx] ?? null) : ($row[0] ?? null);
              $groupName = $groupName ?: 'Group';

              $studentId = $studentIdIdx !== null ? ($row[$studentIdIdx] ?? null) : null;
              $email = $emailIdx !== null ? ($row[$emailIdx] ?? null) : null;
              $fullname = $nameIdx !== null ? ($row[$nameIdx] ?? null) : null;
              $first = $last = null;
              if ($fullname) {
                $parts = preg_split('/\s+/', trim($fullname));
                $first = $parts[0] ?? null;
                $last = count($parts) > 1 ? implode(' ', array_slice($parts,1)) : null;
              }

              // ensure group bucket
              $groupsByName[$groupName][] = ['student_id' => $studentId, 'email' => $email, 'first' => $first, 'last' => $last];
            }
          }
        }

        // If no groups parsed, attempt to create a single empty group
        if (empty($groupsByName)) {
          $groupsByName['Group 1'] = [];
        }

        // Insert groups and members
        $stmtInsertGroup = $pdo->prepare('INSERT INTO assignment_groups (assignment_id, name, discord_channel_id, sharepoint_folder_id) VALUES (?, ?, NULL, NULL)');
        $stmtFindGroup = $pdo->prepare('SELECT id FROM assignment_groups WHERE assignment_id = ? AND name = ? LIMIT 1');
        $stmtInsertGM = $pdo->prepare('INSERT INTO group_members (group_id, student_id, discord_user_id, discord_username, status, joined_at) VALUES (?, ?, NULL, NULL, ?, NOW())');

        foreach ($groupsByName as $gname => $students) {
          // create group (ignore if exists)
          $stmtFindGroup->execute([$assignmentId, $gname]);
          if ($r = $stmtFindGroup->fetch(PDO::FETCH_ASSOC)) {
            $groupId = (int)$r['id'];
          } else {
            $stmtInsertGroup->execute([$assignmentId, $gname]);
            $groupId = (int)$pdo->lastInsertId();
          }

          foreach ($students as $srec) {
            $sid = fetchOrCreateStudent($pdo, $srec['student_id'] ?? null, $srec['email'] ?? null, $srec['first'] ?? null, $srec['last'] ?? null, $professorOrgId ?: 1);
            // insert member (status = registered)
            try {
              // Imported members should default to 'unregistered' unless the import provides
              // a discord_user_id or explicit registration flag. Marking them 'registered'
              // by default caused all students to appear as registered.
              $stmtInsertGM->execute([$groupId, $sid, 'unregistered']);
            } catch (PDOException $e) {
              // ignore duplicate member errors
            }
          }
        }

        $pdo->commit();
        $flashAction = 'create_assignment';
        $flashMessage = "Assignment Made! Please run '/createGroups {$assignmentId}'";
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flashAction = 'create_assignment';
        $flashMessage = 'Error creating assignment: ' . $e->getMessage();
      }
    }
  }

  // If a POST set a modal-scoped flash, store it in the session and
  // redirect (Post-Redirect-Get) so that client-side reload/close doesn't
  // accidentally re-submit the POST and re-show the modal.
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($flashAction)) {
    $_SESSION['flash_action'] = $flashAction;
    $_SESSION['flash_message'] = $flashMessage;
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }

  // Totals (scope to current professor when available)
  if (!empty($professorId)) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_groups FROM assignment_groups ag JOIN assignments a ON a.id = ag.assignment_id JOIN classes c ON c.id = a.class_id WHERE c.professor_id = ?");
    $stmt->execute([$professorId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $totalGroups = (string)$row['total_groups'];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_assignments FROM assignments a JOIN classes c ON c.id = a.class_id WHERE c.professor_id = ?");
    $stmt->execute([$professorId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $totalAssignments = (string)$row['total_assignments'];
    }
  } else {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_groups FROM assignment_groups");
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $totalGroups = (string)$row['total_groups'];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_assignments FROM assignments");
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $totalAssignments = (string)$row['total_assignments'];
    }
  }

    // Detect if classes table exists
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.tables 
        WHERE table_schema = DATABASE() AND table_name = 'classes'
    ");
    $stmt->execute();
    $classesExist = $stmt->fetchColumn() > 0;

    // Detect optional enrollment table
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.tables 
        WHERE table_schema = DATABASE() AND table_name = 'class_students'
    ");
    $stmt->execute();
    $enrollTableExists = $stmt->fetchColumn() > 0;

    // Load classes if present (sorted by Year DESC, Term order: Winter, Spring, Summer, Fall)
    if ($classesExist) {
    // Build student count expression: prefer explicit enrollment table, otherwise derive from group_members
    if ($enrollTableExists) {
      $studentCountExpr = "COUNT(DISTINCT cs.student_id)";
    } else {
      // Count distinct students that appear in any group that's part of assignments for this class
      $studentCountExpr = "(
        SELECT COUNT(DISTINCT gm.student_id)
        FROM assignment_groups g2
        JOIN group_members gm ON gm.group_id = g2.id
        JOIN assignments a2 ON a2.id = g2.assignment_id
        WHERE a2.class_id = c.id
      )";
    }

        $sqlClasses = "
            SELECT 
                c.id,
                c.title,
                c.term,     -- expected: 'Winter','Spring','Summer','Fall'
                c.description,
                {$studentCountExpr} AS students_count
            FROM classes c
            " . ($enrollTableExists ? "LEFT JOIN class_students cs ON cs.class_id = c.id" : "") . "
            " . ($professorId ? "WHERE c.professor_id = :pid" : "") . "
            GROUP BY c.id
        ";
        $stmt = $pdo->prepare($sqlClasses);
        if ($professorId) {
            $stmt->bindValue(':pid', $professorId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Term sort mapping
    $termOrder = ['Winter' => 1, 'Spring' => 2, 'Summer' => 3, 'Fall' => 4];
    usort($classes, function($a, $b) use ($termOrder) {

      // Term order
      $ta = $termOrder[$a['term']] ?? 999;
      $tb = $termOrder[$b['term']] ?? 999;
      if ($ta !== $tb) return $ta <=> $tb;
      // Fallback by title
      return strcmp($a['title'] ?? '', $b['title'] ?? '');
    });
    }

    // ACTIVE assignments only
  $sql = "
        SELECT 
            a.id AS 'ID',
            a.title AS `Title`,
      c.title AS `ClassTitle`,
      c.term AS `ClassTerm`,
            a.description AS `Description`,
            COUNT(gm.student_id) AS `# Of Students`,
            COUNT(DISTINCT g.id) AS `# Of Groups`,
            a.created_at AS `Created On`,
            a.status AS `Current Status`
        FROM assignments a
  LEFT JOIN classes c ON c.id = a.class_id
  LEFT JOIN assignment_groups g ON g.assignment_id = a.id
  LEFT JOIN group_members gm ON gm.group_id = g.id
    WHERE a.status = 'active'
    " . ($professorId ? " AND a.class_id IN (SELECT id FROM classes WHERE professor_id = :pid)" : "") . "
    GROUP BY a.id, c.title, c.term
        ORDER BY a.created_at DESC
    ";
  $stmt = $pdo->prepare($sql);
  if ($professorId) $stmt->bindValue(':pid', $professorId, PDO::PARAM_INT);
  $stmt->execute();
  $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "Database error: " . htmlspecialchars($e->getMessage());
}

// Pagination for classes: 10 per page (5 per row * 2 rows)
$classesPerPage = 10;
$totalClasses = $classesExist ? count($classes) : 0;
$maxPage = $classesPerPage ? max(1, (int)ceil($totalClasses / $classesPerPage)) : 1;
$currentPage = isset($_GET['class_page']) ? max(1, (int)$_GET['class_page']) : 1;
$currentPage = min($currentPage, $maxPage);
$offset = ($currentPage - 1) * $classesPerPage;
$visibleClasses = $classesExist ? array_slice($classes, $offset, $classesPerPage) : [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard</title>
  <link rel="icon" href="/reasources/baj_logo.svg">
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
    .section-content { display: none; }
    .section-content.active { display: block; }
    .uk-modal-dialog tr:hover td { color: #1e87f0 !important; cursor: pointer; }
    .divider { border: none; border-top: 1px solid #ddd; margin: 0; }
    .card-click { cursor: pointer; }
    .uk-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.08); }
    .classes-grid { row-gap: 16px; }
    .file-drop { border: 2px dashed #e5e5e5; border-radius: 8px; padding: 18px; text-align: center; }

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


<div class="uk-container uk-container-expand">
  
  <!-- <hr class="divider"/> -->

  <!-- Main Content -->
  <div class="uk-section uk-section-default">
    <div class="uk-container">
      <!-- Dashboard Section -->

      <div class="uk-grid uk-child-width-1-1 uk-margin-medium-bottom">
        <div>
          <h1 class="uk-heading-medium uk-text-primary">Welcome, Professor <?php echo h($professorName); ?>!</h1>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="uk-margin-medium">
        <?php if (!empty($flashMessage) && empty($flashAction)): ?>
          <div class="uk-alert-primary" uk-alert>
            <a class="uk-alert-close" uk-close></a>
            <p><?php echo h($flashMessage); ?></p>
          </div>
        <?php endif; ?>
        <div class="uk-flex uk-flex-left uk-flex-middle uk-margin-small" uk-grid>
          <div>
            <button class="uk-button uk-button-primary uk-border-rounded" uk-toggle="target: #modal-create-class">
              <span uk-icon="icon: plus"></span> Create Class
            </button>
          </div>
          <div>
            <button class="uk-button uk-button-secondary uk-border-rounded" uk-toggle="target: #modal-create-assignment">
              <span uk-icon="icon: file-text"></span> Create Assignment
            </button>
          </div>
          <div>
            <button class="uk-button uk-button-default uk-border-rounded" uk-toggle="target: #modal-get-emails">
              <span uk-icon="icon: mail"></span> Get Emails
            </button>
          </div>
          <div>
            <button class="uk-button uk-button-link uk-border-rounded" uk-toggle="target: #modal-help">
              <span uk-icon="icon: question"></span> Help
            </button>
          </div>
        </div>
      </div>

      <!-- Get Emails Modal -->
      <div id="modal-get-emails" uk-modal>
        <div class="uk-modal-dialog uk-modal-body">
          <h2 class="uk-modal-title">Get Emails</h2>
          <form id="getEmailsForm" class="uk-form-stacked">
            <div class="uk-margin">
              <label class="uk-form-label">Class</label>
              <div class="uk-form-controls">
                <select id="ge_class" class="uk-select">
                  <option value="">Select class...</option>
                  <?php foreach ($classes as $cl): ?>
                    <option value="<?= (int)$cl['id'] ?>"><?= h($cl['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="uk-margin">
              <label class="uk-form-label">Assignment</label>
              <div class="uk-form-controls">
                <select id="ge_assignment" class="uk-select"><option value="">Select assignment...</option></select>
              </div>
            </div>
            <div class="uk-margin">
              <label class="uk-form-label">Scope</label>
              <div class="uk-form-controls">
                <select id="ge_scope" class="uk-select">
                  <option>All</option>
                  <option>Unregistered</option>
                  <option>Sub 7% msg</option>
                  <option>Sub 7% work</option>
                </select>
              </div>
            </div>

            <div class="uk-margin uk-text-right">
              <button class="uk-button uk-button-default uk-modal-close" type="button">Close</button>
              <button id="ge_get" class="uk-button uk-button-primary" type="button">Get Emails</button>
            </div>
          </form>
          <div id="ge_result" class="uk-margin-small-top" style="display:none;">
            <label>Emails (copied to clipboard)</label>
            <textarea id="ge_emails" class="uk-textarea" rows="4"></textarea>
          </div>
        </div>
      </div>

      <!-- Your Classes -->
      <div class="uk-margin-large-top">
        <h2 class="uk-heading-small">Your Classes</h2>

        <?php if ($classesExist && $totalClasses > 0): ?>
          <div class="uk-grid uk-child-width-1-5@m uk-child-width-1-2@s classes-grid" uk-grid>
            <?php foreach ($visibleClasses as $c): ?>
              <div>
       <div class="uk-card uk-card-default uk-card-hover uk-card-body card-click"
         onclick="window.location.href='classes.php?class_id=<?php echo (int)$c['id']; ?>'">
                  <h3 class="uk-card-title uk-margin-remove-bottom"><?php echo h($c['title']); ?></h3>
                  <?php
                    $termRaw = isset($c['term']) && $c['term'] !== '' ? $c['term'] : '';
                    $term = $termRaw !== '' ? h(ucfirst(strtolower($termRaw))) : '—';
                    $year = isset($c['year']) && $c['year'] !== '' ? (int)$c['year'] : (int)date('Y');
                  ?>
                  <p style="margin: 0; color: #e27f3cff;"><?php echo $term . ' ' . $year; ?></p>
                  <p style="margin: 0;">Students: <strong><?php echo (int)$c['students_count']; ?></strong></p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Pager (10 per page: 5 per row x 2 rows) -->
          <div class="uk-margin-small-top uk-flex uk-flex-between">
            <a class="uk-button uk-button-default <?php echo $currentPage <= 1 ? 'uk-disabled' : ''; ?>"
               href="?class_page=<?php echo max(1, $currentPage - 1); ?>">
              <span uk-icon="chevron-left"></span> Previous
            </a>
            <div class="uk-text-meta">Page <?php echo $currentPage; ?> of <?php echo $maxPage; ?></div>
            <a class="uk-button uk-button-default <?php echo $currentPage >= $maxPage ? 'uk-disabled' : ''; ?>"
               href="?class_page=<?php echo min($maxPage, $currentPage + 1); ?>">
              Next <span uk-icon="chevron-right"></span>
            </a>
          </div>
        <?php elseif ($classesExist && $totalClasses === 0): ?>
          <div class="uk-alert uk-alert-primary">No classes yet. Use <strong>Create Class</strong> to get started.</div>
        <?php else: ?>
          <div class="uk-alert uk-alert-warning">
            <strong>Heads up:</strong> No <code>classes</code> table detected. Contact CollabLens Team!
          </div>
        <?php endif; ?>
      </div>

      <!-- Active Assignments (table filtered to status = active) -->
      <div class="uk-margin-large-top">
        <h2 class="uk-heading-small">Active Assignments</h2>

        <table id="this_table" name="this_table" class="uk-table uk-table-hover uk-table-striped">
          <thead>
          <tr>
            <th style="color:black;">Title</th>
            <th style="color:black;">Class</th>
            <th style="color:black;">Description</th>
            <th style="color:black;"># Of Students</th>
            <th style="color:black;"># Of Groups</th>
            <th style="color:black;">Created On</th>
            <th style="color:black;">Current Status</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($results as $assignment):
              $status = (string)$assignment['Current Status'];
              $labelClass = 'uk-label';
              if ($status === 'active') {
                  $labelClass = 'uk-label-success';
              } elseif ($status === 'archived') {
                  $labelClass = 'uk-label-warning';
              }
              $labelClass .= " uk-box-shadow-large uk-box-shadow-hover-small";
          ?>
            <tr class="clickable-row" onclick="window.location.href='assignment.php?assignment_id=<?php echo (int)$assignment['ID']; ?>'" style="cursor: pointer;">
              <td><strong><?php echo h($assignment['Title']); ?></strong></td>
                <td><?php
                    $ctitle = $assignment['ClassTitle'] ?? '';
                    $cterm = $assignment['ClassTerm'] ?? '';
                    $year = date('Y');
                    if ($cterm !== '') $cterm = ucfirst(strtolower($cterm));
                    if ($ctitle) {
                      echo h($ctitle . ' ' . ($cterm ?: '-') . ' ' . $year);
                    } else {
                      echo '—';
                    }
                ?></td>
              <td><?php echo h(mb_strimwidth($assignment['Description'] ?? '', 0, 55, '...')); ?></td>
              <td><?php echo h($assignment['# Of Students']); ?></td>
              <td><?php echo h($assignment['# Of Groups']); ?></td>
              <td><?php echo h($assignment['Created On']); ?></td>
              <td><span class="uk-label <?php echo $labelClass; ?>"><?php echo h($assignment['Current Status']); ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>

<!-- Create Class Modal -->
<div id="modal-create-class" uk-modal>
  <div class="uk-modal-dialog uk-modal-body">
    <button class="uk-modal-close-default" type="button" uk-close></button>
    <?php if (!empty($flashMessage) && $flashAction === 'create_class'): ?>
      <div class="uk-text-center uk-margin-small-bottom">
        <span uk-icon="icon: check; ratio: 2" class="uk-text-success"></span>
      </div>
      <div class="uk-text-center">
        <p class="uk-text-lead"><?php echo h($flashMessage); ?></p>
        <div class="uk-margin">
          <button class="uk-button uk-button-primary uk-modal-close" type="button">Close</button>
        </div>
      </div>
    <?php else: ?>
      <h3 class="uk-modal-title">Create Class</h3>
      <form class="uk-form-stacked" method="POST" action="" >
        <input type="hidden" name="action" value="create_class">
        <div class="uk-margin">
          <label class="uk-form-label">Class Name</label>
          <div class="uk-form-controls">
            <input class="uk-input" type="text" name="name" required>
          </div>
        </div>
        <div class="uk-grid-small" uk-grid>
          <div class="uk-width-1-2@s">
            <label class="uk-form-label">Class Term</label>
            <div class="uk-form-controls">
              <select class="uk-select" name="term" required>
                <option value="">Select term</option>
                <option>Fall</option>
                <option>Winter</option>
                <option>Spring</option>
                <option>Summer</option>
                <option>Intersession</option>
              </select>
            </div>
          </div>
          <div class="uk-width-1-2@s">
            <label class="uk-form-label">Year</label>
            <div class="uk-form-controls">
              <input class="uk-input" type="number" name="year" min="2000" max="2100" value="<?php echo date('Y'); ?>" required>
            </div>
          </div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">Description <span class="uk-text-meta">(optional)</span></label>
          <div class="uk-form-controls">
            <textarea class="uk-textarea" name="description" rows="3" placeholder="Brief description (optional)"></textarea>
          </div>
        </div>
        <div class="uk-margin">
          <button class="uk-button uk-button-primary">Create</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Create Assignment Modal -->
<div id="modal-create-assignment" uk-modal>
  <div class="uk-modal-dialog uk-modal-body">
    <button class="uk-modal-close-default" type="button" uk-close></button>
    <?php if (!empty($flashMessage) && $flashAction === 'create_assignment'): ?>
      <div class="uk-text-center uk-margin-small-bottom">
        <span uk-icon="icon: check; ratio: 2" class="uk-text-success"></span>
      </div>
      <div class="uk-text-center">
        <p class="uk-text-lead"><?php echo h($flashMessage); ?></p>
        <div class="uk-margin">
          <button class="uk-button uk-button-primary uk-modal-close" type="button">Close</button>
        </div>
      </div>
    <?php else: ?>
      <h3 class="uk-modal-title">Create Assignment</h3>
      <form class="uk-form-stacked" method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create_assignment">
        <div class="uk-margin">
          <label class="uk-form-label">Assignment Name</label>
          <div class="uk-form-controls">
            <input class="uk-input" type="text" name="title" required>
          </div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">Class</label>
          <div class="uk-form-controls">
            <select class="uk-select" name="class_id" required <?php echo !$classesExist || $totalClasses===0 ? 'disabled' : ''; ?>>
              <?php if ($classesExist && $totalClasses>0): ?>
                <option value="">Select class</option>
                <?php foreach ($classes as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>">
                    <?php echo h($c['title'] . ' — ' . $c['term']); ?>
                  </option>
                <?php endforeach; ?>
              <?php else: ?>
                <option value="">No classes found</option>
              <?php endif; ?>
            </select>
          </div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">Student List (groups file)</label>
            <div class="uk-form-controls">
            <div class="file-drop uk-placeholder uk-text-center">
              <span uk-icon="icon: cloud-upload"></span>
              <span class="uk-text-middle">Attach file by dropping it here or</span>
              <div uk-form-custom>
                <input id="student_file_input" type="file" name="student_file" accept=",.csv,.xlsx,.xls,.tsv,.txt" required>
                <span class="uk-link">selecting one</span>
              </div>
              <div id="student-file-name" class="uk-text-small uk-margin-top" aria-live="polite"></div>
            </div>
            <p class="uk-text-meta uk-margin-small-top">Accepted: CSV/XLSX/TSV/TXT</p>
          </div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">Due Date</label>
          <div class="uk-form-controls">
            <?php
              // Prefill value for datetime-local (YYYY-MM-DDTHH:MM)
              $prefillDue = date('Y-m-d\TH:i', strtotime('+14 days'));
            ?>
            <input class="uk-input" type="datetime-local" name="due_date" value="<?php echo h($prefillDue); ?>">
          </div>
        </div>
        <div class="uk-margin">
          <label class="uk-form-label">Description</label>
          <div class="uk-form-controls">
            <textarea class="uk-textarea" name="description" rows="3" placeholder="Optional details"></textarea>
          </div>
        </div>
        <div class="uk-margin">
          <button class="uk-button uk-button-secondary">Create Assignment</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Send Notification Modal -->
<div id="modal-send-notification" uk-modal>
  <div class="uk-modal-dialog uk-modal-body">
    <button class="uk-modal-close-default" type="button" uk-close></button>
    <h3 class="uk-modal-title">Send Notification</h3>
    <form class="uk-form-stacked" method="POST" action="send_notification.php">
      <div class="uk-margin">
        <label class="uk-form-label">Class</label>
        <div class="uk-form-controls">
          <select class="uk-select" name="class_id" required <?php echo !$classesExist || $totalClasses===0 ? 'disabled' : ''; ?>>
            <?php if ($classesExist && $totalClasses>0): ?>
              <option value="">Select class</option>
              <?php foreach ($classes as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>">
                  <?php echo h($c['name'] . ' — ' . $c['term'] ); ?>
                </option>
              <?php endforeach; ?>
            <?php else: ?>
              <option value="">No classes found</option>
            <?php endif; ?>
          </select>
        </div>
      </div>

        <!-- Help Modal -->
        <div id="modal-help" class="uk-modal-container" uk-modal>
          <div class="uk-modal-dialog uk-modal-body">
            <button class="uk-modal-close-default" type="button" uk-close></button>

            <h3 class="uk-modal-title">How To Use CollabLens</h3>
            <p><strong>1.</strong> To create a class, go to the our dashboard and click on <button class="uk-button uk-button-small uk-button-primary uk-border-rounded"><span uk-icon="icon: plus" class="uk-icon"></span> Create Class</button></p>
            <p><strong>2.</strong> Fill in the class details and click "Create".</p>
            <img style="margin-left: 25%;" src="reasources/create_class_example.png" width="50%" alt="Create Class Form">
            <p><strong>3.</strong> Following the "Class Created!" message, go Into Discord and create and empty server. After this please click on the discord bot link and follow the instructions to add our bot to your server.</p>
            <img style="margin-left: 25%;" src="reasources/class_created_example.png" width="50%" alt="Class Created Message">
            <p><strong>4.</strong> Get the class discord instructions in the class page by clicking <button class="uk-button uk-button-small uk-button-primary uk-border-rounded" style="margin-left:8px;"><span class="uk-margin-small-right uk-icon" uk-icon="discord"></span> Setup </button> then run the command shown in the instuctions.</p>
            <img style="margin-left: 25%;" src="reasources/class_discord_setup_example.png" width="50%" alt="Class Discord Setup Instructions">
            <p><strong>5.</strong> To create assignments, in the dashboard click on <button class="uk-button uk-button-small uk-button-secondary uk-border-rounded"><span uk-icon="icon: file-text" class="uk-icon"></span> Create Assignment</button> from the dashboard.</p>
            <p><strong>6.</strong> Fill in the class details and click "Create".</p>
            <img style="margin-left: 25%;" src="reasources/create_assignment_example.png" width="50%" alt="Create Assignment Form">
            <p><strong>7.</strong> After creating the assignment, go to Discord and run the command shown in the "Assignment Made!" message to create groups.</p>
            <img style="margin-left: 25%;" src="reasources/assignment_created_example.png" width="50%" alt="Assignment Made Message">
            <p><strong>Note:</strong> You can also access the discord setup instructions for assignments by clicking <button class="uk-button uk-button-small uk-button-primary uk-border-rounded" style="margin-left:8px;"><span class="uk-margin-small-right uk-icon" uk-icon="discord"></span> Setup </button> in the assignment page  .</p>
            <img style="margin-left: 25%;" src="reasources/assignment_discord_setup_example.png" width="50%" alt="Assignment Discord Setup Instructions">

             <div class="uk-text-right">
              <button class="uk-button uk-button-default uk-modal-close" type="button">Close</button>
            </div>
          </div>
        </div>
      <div class="uk-margin">
        <label class="uk-form-label">Message</label>
        <div class="uk-form-controls">
          <textarea class="uk-textarea" name="message" rows="4" required placeholder="Type your message…"></textarea>
        </div>
      </div>
      <div class="uk-margin">
        <button class="uk-button uk-button-default">Send</button>
      </div>
    </form>
  </div>
</div>

<!-- Scripts -->
<script>
  (function(){
    // Helper: escape-ish
    function showSection(sectionId) {
      const sections = document.querySelectorAll('.section-content');
      const navLinks = document.querySelectorAll('.nav-link');
      sections.forEach(section => section.style.display = 'none');
      const target = document.getElementById(sectionId);
      if (target) target.style.display = 'block';
      navLinks.forEach(link => link.classList.remove('active'));
      const activeLink = Array.from(navLinks).find(link => link.getAttribute('onclick')?.includes(sectionId));
      if (activeLink) activeLink.classList.add('active');
    }

    // Central DOMContentLoaded handler
    document.addEventListener('DOMContentLoaded', function(){
      // Default section
      showSection('dashboard');

      // DataTables
      try { $('#this_table').DataTable({ pageLength: 10 }); } catch (e) {}

      // subtle container animation
      document.querySelectorAll('.uk-container').forEach(el => { el.classList.add('js-animate'); setTimeout(()=>el.classList.add('ready'),20); });

      // Auto-open modal if server gave a flashAction
      try {
        const flashAction = <?php echo json_encode($flashAction); ?>;
        if (flashAction) {
          let modalId = null;
          if (flashAction === 'create_class') modalId = 'modal-create-class';
          if (flashAction === 'create_assignment') modalId = 'modal-create-assignment';
          if (modalId) {
            const modalEl = document.getElementById(modalId);
            if (modalEl) {
              const modal = UIkit.modal(modalEl);
              modal.show();
              try { UIkit.util.on(modalEl, 'hidden', function () { window.location.reload(); }); } catch (e2) {
                modalEl.querySelectorAll('[uk-close], .uk-modal-close').forEach(btn => btn.addEventListener('click', () => window.location.reload()));
              }
            }
          }
        }
      } catch (e) { console.error('modal flash failure', e); }

      // Dynamic preview modal data loader
      document.querySelectorAll('tr[uk-toggle]').forEach(row => {
        row.addEventListener('click', function () {
          const m = row.getAttribute('uk-toggle')?.match(/#preview-modal-(\d+)/);
          if (!m) return;
          const assignmentId = m[1];
          fetch(`getAssignmentDetails.php?assignment_id=${assignmentId}`)
            .then(response => response.json())
            .then(data => {
              const groupsTable = document.getElementById(`groups-table-${assignmentId}`);
              const studentsTable = document.getElementById(`students-table-${assignmentId}`);
              if (!groupsTable || !studentsTable) return;
              groupsTable.innerHTML = '';
              studentsTable.innerHTML = '';
              (data.groups || []).forEach(group => {
                groupsTable.innerHTML += `\n                <tr onclick="window.location.href='./groupView.php?group_id=${group.id}'">\n                  <td>${group.id}</td>\n                  <td>${group.group_members || '-'}</td>\n                  <td>${group.discord_channel_id || '-'}</td>\n                  <td>${group.sharepoint_folder_id || '-'}</td>\n                </tr>`;
              });
              (data.students || []).forEach(student => {
                studentsTable.innerHTML += `\n                <tr>\n                  <td>${student.student_id}</td>\n                  <td>${student.name || '-'}</td>\n                  <td>${student.school_email || '-'}</td>\n                  <td>${student.discord_user || '-'}</td>\n                </tr>`;
              });
            })
            .catch(error => console.error('Error loading modal data:', error));
        });
      });

      // File input feedback for Create Assignment
      (function(){
        const input = document.getElementById('student_file_input');
        const nameEl = document.getElementById('student-file-name');
        const form = input ? input.closest('form') : null;
        if (!input || !nameEl) return;
        input.addEventListener('change', function(e){
          const f = input.files && input.files[0];
          if (f) { nameEl.textContent = `Selected: ${f.name}`; nameEl.style.color = '#0b6'; } else { nameEl.textContent = ''; }
        });
        if (form) {
          form.addEventListener('submit', function(e){
            const f = input.files && input.files[0];
            if (!f) { e.preventDefault(); UIkit.notification({message: 'Please attach a student list file before creating assignment.', status: 'danger'}); input.focus(); } else { nameEl.textContent = `Uploading: ${f.name}`; }
          });
        }
      })();

      // Get Emails: wire up class->assignments loader and button
      async function loadAssignmentsForClass(classId) {
        const sel = document.getElementById('ge_assignment');
        if (!sel) return;
        sel.innerHTML = '<option value="">Select assignment...</option>';
        if (!classId) return;
        try {
          const res = await fetch(`getAssignments.php?class_id=${classId}`);
          const json = await res.json();
          if (json.error) { UIkit.notification({message: json.error, status:'danger'}); return; }
          for (const a of json.assignments) { const opt = document.createElement('option'); opt.value = a.id; opt.textContent = a.title; sel.appendChild(opt); }
        } catch (e) { console.error(e); }
      }

      const geClass = document.getElementById('ge_class'); if (geClass) geClass.addEventListener('change', function(e){ loadAssignmentsForClass(e.target.value); });
      const geGet = document.getElementById('ge_get');
      if (geGet) geGet.addEventListener('click', async function(){
        const aid = document.getElementById('ge_assignment')?.value;
        const scope = document.getElementById('ge_scope')?.value || '';
        if (!aid) { UIkit.notification({message:'Please select an assignment', status:'warning'}); return; }
        try {
          const res = await fetch(`getEmails.php?assignment_id=${aid}&scope=${encodeURIComponent(scope)}`);
          const json = await res.json();
          if (json.error) { UIkit.notification({message: json.error, status:'danger'}); return; }
          const emails = (json.emails || []).filter(Boolean).join(', ');
          const ta = document.getElementById('ge_emails'); if (ta) ta.value = emails;
          const resultEl = document.getElementById('ge_result'); if (resultEl) resultEl.style.display = emails ? 'block' : 'none';
          if (emails) { try { await navigator.clipboard.writeText(emails); } catch (_) {} UIkit.notification({message: 'Emails copied to clipboard', status:'success'}); } else { UIkit.notification({message: 'No emails found for selected criteria', status:'warning'}); }
        } catch (e) { console.error(e); UIkit.notification({message: 'Error fetching emails', status:'danger'}); }
      });

      // Replace {discord bot link} with safe anchor
      (function(){
        const invite = <?php echo json_encode('https://discord.com/oauth2/authorize?client_id=1385862968696115230&permissions=8&integration_type=0&scope=bot'); ?>;
        if (!invite) return;
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
        const nodes = [];
        while(walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(function(textNode){
          if (textNode.nodeValue && textNode.nodeValue.indexOf('{discord bot link}') !== -1) {
            const parent = textNode.parentNode;
            const parts = textNode.nodeValue.split('{discord bot link}');
            for (let i = 0; i < parts.length; i++) {
              parent.insertBefore(document.createTextNode(parts[i]), textNode);
              if (i < parts.length - 1) {
                const a = document.createElement('a'); a.href = invite; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'discord bot'; parent.insertBefore(a, textNode);
              }
            }
            parent.removeChild(textNode);
          }
        });
      })();

    }); // DOMContentLoaded
  })();
</script>

</body>
</html>
