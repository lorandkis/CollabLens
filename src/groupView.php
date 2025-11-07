<?php
require_once __DIR__ . '/auth.php';
require_auth();
ini_set('display_errors', '1');
error_reporting(E_ALL);

/**
 * Group Dashboard (UIkit + Chart.js + chartjs-plugin-datalabels)
 * Usage: group_dashboard.php?group_id=1
 */

$DB_HOST = 'db';
$DB_PORT = '3306';
$DB_NAME = 'myapp';
$DB_USER = 'appuser';
$DB_PASS = 'apppass';

function humanize_seconds(int $secs): string {
    if ($secs < 60) return $secs . "s";
    $m = intdiv($secs, 60);
    $s = $secs % 60;
    if ($m < 60) return sprintf("%dm %02ds", $m, $s);
    $h = intdiv($m, 60);
    $m = $m % 60;
    return sprintf("%dh %02dm %02ds", $h, $m, $s);
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Resolve group_id (GET or newest)
    $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
      if ($groupId <= 0) {
        // pick the newest group that belongs to the current professor (via classes -> assignments)
        $currentUserId = $_SESSION['user']['id'] ?? null;
        if ($currentUserId) {
          $groupId = (int)$pdo->query("SELECT ag.id FROM assignment_groups ag JOIN assignments a ON a.id = ag.assignment_id JOIN classes c ON c.id = a.class_id WHERE c.professor_id = " . (int)$currentUserId . " ORDER BY ag.created_at DESC, ag.id DESC LIMIT 1")->fetchColumn();
        } else {
          $groupId = (int)$pdo->query("SELECT id FROM assignment_groups ORDER BY created_at DESC, id DESC LIMIT 1")->fetchColumn();
        }
      }
    if (!$groupId) {
        throw new RuntimeException("No groups found. Seed data first.");
    }

    // Group + meta (assignment/class)
      $currentUserId = $_SESSION['user']['id'] ?? null;
      $stmtMeta = $pdo->prepare("
   SELECT g.id AS group_id, g.name AS group_name, g.discord_channel_id, g.sharepoint_folder_id,
     a.id AS assignment_id, a.title AS assignment_title, a.due_date,
     c.id AS class_id, c.title AS class_title, c.term
      FROM assignment_groups g
      JOIN assignments a ON a.id = g.assignment_id
      JOIN classes c ON c.id = a.class_id
        WHERE g.id = ? " . ($currentUserId ? " AND c.professor_id = ?" : "") . "\n  ");
      if ($currentUserId) {
          $stmtMeta->execute([$groupId, (int)$currentUserId]);
      } else {
        $stmtMeta->execute([$groupId]);
      }
    $meta = $stmtMeta->fetch(PDO::FETCH_ASSOC);
    if (!$meta) throw new RuntimeException("Group #$groupId not found.");

    // Students in this group (fixed order for consistent charts + table)
    $stmtStudents = $pdo->prepare("
        SELECT s.id AS student_id, s.first_name, s.last_name, s.email,
               gm.discord_user_id, gm.discord_username
        FROM group_members gm
        JOIN students s ON s.id = gm.student_id
        WHERE gm.group_id = ?
        ORDER BY s.first_name, s.last_name
    ");
    $stmtStudents->execute([$groupId]);
    $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);
    if (!$students) throw new RuntimeException("No students found in group #$groupId.");

  // Discord message metrics per student (count, avg length, first/last timestamp)
    $stmtMsgMetrics = $pdo->prepare("
      SELECT s.id AS student_id,
             COUNT(dm.id) AS msg_count,
             AVG(CHAR_LENGTH(COALESCE(dm.content, ''))) AS avg_msg_len,
             MIN(dm.timestamp) AS first_msg_ts,
             MAX(dm.timestamp) AS last_msg_ts
      FROM group_members gm
      JOIN students s ON s.id = gm.student_id
      LEFT JOIN assignment_groups ag ON ag.id = gm.group_id
      LEFT JOIN discord_messages dm
           ON dm.channel_id = ag.discord_channel_id
          AND dm.author_id = gm.discord_user_id
      WHERE gm.group_id = ?
      GROUP BY s.id
    ");
    $stmtMsgMetrics->execute([$groupId]);
    $msgMetricsRaw = $stmtMsgMetrics->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $msgMetrics = [];
    foreach ($msgMetricsRaw as $m) {
      $msgMetrics[(int)$m['student_id']] = $m;
    }

    // Average gap (seconds) between consecutive messages per student
  $stmtAvgGap = $pdo->prepare("
    WITH dm_seq AS (
      SELECT
        s.id AS student_id,
        dm.timestamp AS ts,
        LAG(dm.timestamp) OVER (PARTITION BY s.id ORDER BY dm.timestamp) AS prev_ts
      FROM group_members gm
      JOIN students s ON s.id = gm.student_id
      JOIN assignment_groups ag ON ag.id = gm.group_id
      JOIN discord_messages dm
        ON dm.channel_id = ag.discord_channel_id
       AND dm.author_id = gm.discord_user_id
      WHERE gm.group_id = ?
    )
    SELECT student_id,
         AVG(TIMESTAMPDIFF(SECOND, prev_ts, ts)) AS avg_gap_seconds
    FROM dm_seq
    WHERE prev_ts IS NOT NULL
    GROUP BY student_id
  ");
  $stmtAvgGap->execute([$groupId]);
    $avgGapsRaw = $stmtAvgGap->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    // Build one ordered array for table and charts
    $rows = [];
    foreach ($students as $st) {
        $sid = (int)$st['student_id'];
        $met = $msgMetrics[$sid] ?? null;
        $msg_count = $met ? (int)$met['msg_count'] : 0;
        $avg_len = $met ? round((float)$met['avg_msg_len'], 1) : 0;
        $first_ts = $met && $met['first_msg_ts'] ? $met['first_msg_ts'] : null;
        $last_ts = $met && $met['last_msg_ts'] ? $met['last_msg_ts'] : null;

        $rows[] = [
          'student_id' => $sid,
          'name'       => $st['first_name'].' '.$st['last_name'],
          'email'      => $st['email'],
          'msg_count'  => $msg_count,
          'avg_len'    => $avg_len,
          'first_msg'  => $first_ts ? date('Y-m-d H:i:s', strtotime($first_ts)) : '-',
          'last_msg'   => $last_ts ? date('Y-m-d H:i:s', strtotime($last_ts)) : '-',
          'avg_gap'    => isset($avgGapsRaw[$sid]) ? humanize_seconds((int)round($avgGapsRaw[$sid])) : '-',
        ];
    }
  $msgData = array_map(fn($r) => $r['msg_count'], $rows);
  $totalMsgs = array_sum($msgData);
  foreach ($rows as &$rr) {
    $rr['pct'] = $totalMsgs ? round(($rr['msg_count'] / $totalMsgs) * 100, 1) : 0;
  }
  unset($rr);
  $labels = array_map(fn($r) => $r['name'], $rows);

  // Build time-series per student (daily counts). If there are no messages, fall back to a recent date range.
  // date labels (Y-m-d)
  $dateLabels = [];
  try {
    $stmtRange = $pdo->prepare("\n      SELECT MIN(DATE(dm.timestamp)) AS min_dt, MAX(DATE(dm.timestamp)) AS max_dt\n      FROM discord_messages dm\n      JOIN assignment_groups ag ON ag.id = ?\n      WHERE dm.channel_id = ag.discord_channel_id\n    ");
    $stmtRange->execute([$groupId]);
    $range = $stmtRange->fetch(PDO::FETCH_ASSOC);
    if ($range && $range['min_dt'] && $range['max_dt']) {
      $start = new DateTime($range['min_dt']);
      $end = new DateTime($range['max_dt']);
      // inclusive loop
      $period = new DatePeriod($start, new DateInterval('P1D'), (clone $end)->add(new DateInterval('P1D')));
      foreach ($period as $d) {
        $dateLabels[] = $d->format('Y-m-d');
      }
    } else {
      // no messages - show last 14 days as default
      $today = new DateTime('today');
      for ($i = 13; $i >= 0; $i--) {
        $d = (clone $today)->sub(new DateInterval("P{$i}D"));
        $dateLabels[] = $d->format('Y-m-d');
      }
    }
  } catch (Throwable $ignore) {
    // on any error fall back to last 14 days
    $dateLabels = [];
    $today = new DateTime('today');
    for ($i = 13; $i >= 0; $i--) {
      $d = (clone $today)->sub(new DateInterval("P{$i}D"));
      $dateLabels[] = $d->format('Y-m-d');
    }
  }

  // counts per student per date (will be sparse; we'll fill zeros)
  $stmtSeries = $pdo->prepare("\n    SELECT gm.student_id, DATE(dm.timestamp) AS dt, COUNT(dm.id) AS cnt\n    FROM group_members gm\n    JOIN assignment_groups ag ON ag.id = gm.group_id\n    LEFT JOIN discord_messages dm ON dm.channel_id = ag.discord_channel_id AND dm.author_id = gm.discord_user_id\n    WHERE gm.group_id = ?\n    GROUP BY gm.student_id, dt\n    ORDER BY dt\n  ");
  $stmtSeries->execute([$groupId]);
  $seriesRaw = $stmtSeries->fetchAll(PDO::FETCH_ASSOC);

  $counts = [];
  foreach ($seriesRaw as $sr) {
    $sid = (int)($sr['student_id'] ?? 0);
    $dt = $sr['dt'] ?? null;
    $cnt = (int)($sr['cnt'] ?? 0);
    if ($dt === null) continue;
    $counts[$sid][$dt] = $cnt;
  }

  $seriesData = [];
  $seriesNames = [];
  foreach ($rows as $r) {
    $sid = (int)$r['student_id'];
    $arr = [];
    foreach ($dateLabels as $dt) {
      $arr[] = isset($counts[$sid][$dt]) ? $counts[$sid][$dt] : 0;
    }
    $seriesData[] = $arr;
    $seriesNames[] = $r['name'];
  }

} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre style='padding:1rem;background:#111;color:#eee;border:1px solid #333;'>";
    echo "Dashboard error: " . htmlspecialchars($e->getMessage());
    echo "</pre>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Group Dashboard</title>
  <link rel="icon" type="image/x-icon" href="/reasources/baj_logo.svg">
  <!-- UIkit -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.19.4/dist/css/uikit.min.css" />
  <link rel="stylesheet" href="/reasources/css/custom.css" />
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.19.4/dist/js/uikit.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.19.4/dist/js/uikit-icons.min.js"></script>
  <!-- Chart.js + DataLabels -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    .divider { border: none; border-top: 1px solid #ddd; margin: 0; }
    body { background: #0f0f10; }
    .uk-card { border-radius: 16px; }
    .muted { color: #a0a0a0; }
    .mono  { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
    canvas { max-height: 460px; } /* taller for readability */
    .chip { display:inline-block; padding:4px 10px; border-radius:999px; background:#1f1f22; color:#ddd; font-size:12px; border:1px solid #2a2a2e; }
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
<body class="uk-background-secondary uk-light">

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
  </br>

  <div class="uk-container uk-container-expand">
    
    <!-- Header -->
    <div class="uk-flex uk-flex-middle uk-flex-between uk-margin">
      <div>
        <!-- Breadcrumb -->
        <div style="margin-bottom:6px;">
          <a href="/userDashboard.php">Dashboard</a>
          <?php if (!empty($meta['class_id'])): ?> / <a href="classes.php?class_id=<?= (int)$meta['class_id'] ?>"><?= htmlspecialchars($meta['class_title'] ?? 'Class') ?></a><?php endif; ?>
          <?php if (!empty($meta['assignment_id'])): ?> / <a href="assignment.php?assignment_id=<?= (int)$meta['assignment_id'] ?>"><?= htmlspecialchars($meta['assignment_title'] ?? 'Assignment') ?></a><?php endif; ?>
          / <strong><?= htmlspecialchars($meta['group_name']) ?></strong>
        </div>
        <h2 class="uk-margin-remove">Group Dashboard</h2>
        <div class="muted">
          <!-- Info Chips -->
          <span class="chip">Group: <strong><?= htmlspecialchars($meta['group_name']) ?></strong></span>
          <span class="chip">Channel: <strong><?= htmlspecialchars($meta['discord_channel_id'] ?? '—') ?></strong></span>
          <span class="chip">Assignment: <strong><?= htmlspecialchars($meta['assignment_title'] ?? '—') ?></strong></span>
          <span class="chip">Class: <strong><?= htmlspecialchars($meta['class_title'] ?? '—') ?></strong></span>
          <?php
            $termRaw = isset($meta['term']) && $meta['term'] !== '' ? $meta['term'] : '';
            $term = $termRaw !== '' ? htmlspecialchars(ucfirst(strtolower($termRaw))) : '—';
            $year = isset($meta['year']) && $meta['year'] !== '' ? (int)$meta['year'] : (int)date('Y');
          ?>
          <span class="chip">Term: <strong><?= $term . ' ' . $year ?></strong></span>
        </div>
      </div>
    </div>

    <!-- Charts -->
    <div class="uk-grid-small" uk-grid>
      <div class="uk-width-1-2@m">
        <div class="uk-card uk-card-default uk-card-body uk-background-muted">
          <h4 class="uk-card-title uk-margin-small">Discord Messages per Student</h4>
          <canvas id="messagesPie"></canvas>
          <p class="muted uk-margin-small-top">Counts messages authored by each student in this group's channel.</p>
        </div>
      </div>

      <div class="uk-width-1-2@m">
        <div class="uk-card uk-card-default uk-card-body uk-background-muted">
          <h4 class="uk-card-title uk-margin-small">Per-student Message Activity (Daily)</h4>
          <div style="height:320px;">
            <canvas id="messagesTimeseries" style="height:100%; width:100%;"></canvas>
          </div>
          <p class="muted uk-margin-small-top">Daily message counts per student. Students with no messages are shown as flat zeros.</p>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="uk-card uk-card-default uk-card-body uk-margin-large-top uk-background-muted">
      <div class="uk-flex uk-flex-middle uk-flex-between">
        <h4 class="uk-card-title uk-margin-small">Discord Message Data</h4>
        <span class="muted">Note: Date and time data are are gathered and shown from Discord in UTC.</span>
      </div>

      <div class="uk-overflow-auto uk-margin-small-top">
        <table class="uk-table uk-table-divider uk-table-justify uk-table-striped uk-table-middle">
          <thead>
          <tr>
            <th style="min-width:220px;color:black">Student</th>
            <th class="uk-text-center" style="color:black"># of Messages</th>
            <th class="uk-text-center" style="color:black">Avg Msg Len (char)</th>
            <th class="uk-text-center" style="color:black">First Msg</th>
            <th class="uk-text-center" style="color:black">Last Msg</th>
            <th class="uk-text-center" style="color:black">Avg Gap</th>
            <th class="uk-text-center" style="color:black">% Contribution</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['name']) ?></strong><br>
                <span class="muted mono"><?= htmlspecialchars($r['email']) ?></span>
              </td>
              <td class="uk-text-center"><?= (int)$r['msg_count'] ?></td>
              <td class="uk-text-center"><?= htmlspecialchars((string)$r['avg_len']) ?></td>
              <td class="uk-text-center"><?= htmlspecialchars((string)$r['first_msg']) ?></td>
              <td class="uk-text-center"><?= htmlspecialchars((string)$r['last_msg']) ?></td>
              <td class="uk-text-center"><?= htmlspecialchars($r['avg_gap']) ?></td>
              <td class="uk-text-center"><?= htmlspecialchars((string)($r['pct'] ?? 0)) ?>%</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <script>
    // Chart.js plugin
    Chart.register(ChartDataLabels);

    // Data from PHP
    const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    const msgData = <?= json_encode($msgData) ?>;

    // Helper: abbreviate "First Last" -> "First L."
    function shortName(full) {
      const parts = String(full).trim().split(/\s+/);
      if (parts.length < 2) return full;
      return `${parts[0]} ${parts[1][0]}.`;
    }
    function percent(value, total) {
      return total ? Math.round((value / total) * 100) : 0;
    }
    function genColors(n) {
      const arr = [];
      for (let i = 0; i < n; i++) {
        const h = Math.round((360 / Math.max(1,n)) * i);
        arr.push(`hsl(${h}, 70%, 50%)`);
      }
      return arr;
    }

    const totalMsgs = Array.isArray(msgData) ? msgData.reduce((a,b)=>a+b,0) : 0;

    const commonOptions = (tot) => ({
      plugins: {
        legend: { display: false },
        datalabels: {
          formatter: (v, ctx) => {
            const nm = ctx.chart.data.labels[ctx.dataIndex];
            const p = percent(v, tot);
            return p >= 5 ? `${nm}\n${p}%` : '';
          },
          color: '#fff',
          font: { weight: '600', size: 12 },
          textAlign: 'center'
        }
      },
      layout: { padding: 6 },
      animation: { animateRotate: true, animateScale: true },
      elements: { arc: { borderWidth: 1.5, hoverBorderWidth: 2.5 } }
    });

    // Messages chart: if there are no messages, show a friendly placeholder instead
    (function renderMessagesChart(){
      const canvas = document.getElementById('messagesPie');
      const container = canvas && canvas.parentElement;
      if (!canvas || !container) return;

      if (!totalMsgs) {
        // hide the canvas and display a friendly message
        canvas.style.display = 'none';
        const msg = document.createElement('div');
        msg.className = 'uk-text-center muted';
        msg.style.padding = '48px 8px';
        msg.innerHTML = `No Discord messages found for this group's channel yet.<br>Invite students to post or run the Discord sync to import messages.`;
        container.appendChild(msg);
        return;
      }

      // otherwise render the doughnut
      new Chart(canvas, {
        type: 'doughnut',
        data: { labels, datasets: [{ data: msgData, backgroundColor: genColors(labels.length) }] },
        options: commonOptions(totalMsgs)
      });
    })();

    // Timeseries data for each student
    (function renderTimeseries(){
      const tsCanvas = document.getElementById('messagesTimeseries');
      if (!tsCanvas) return;

      const dateLabels = <?= json_encode(array_values($dateLabels)) ?>;
      const seriesNames = <?= json_encode($seriesNames) ?>;
      const seriesData = <?= json_encode($seriesData) ?>;

      // if no series data at all, show placeholder
      const hasAny = seriesData.some(arr => Array.isArray(arr) && arr.some(v => v > 0));
      if (!hasAny) {
        tsCanvas.style.display = 'none';
        const parent = tsCanvas.parentElement;
        const msg = document.createElement('div');
        msg.className = 'uk-text-center muted';
        msg.style.padding = '20px 8px';
        msg.innerHTML = `No message activity yet to display on the timeseries.`;
        parent.appendChild(msg);
        return;
      }

      function pickColors(n) {
        const cols = [];
        for (let i = 0; i < n; i++) {
          const h = Math.round((360 / Math.max(1,n)) * i);
          cols.push({bg: `hsla(${h},70%,50%,0.12)`, border: `hsl(${h},70%,45%)`} );
        }
        return cols;
      }

      const cols = pickColors(seriesNames.length);
      const datasets = seriesNames.map((nm, i) => ({
        label: nm,
        data: seriesData[i],
        fill: false,
        tension: 0.2,
        borderColor: cols[i].border,
        backgroundColor: cols[i].bg,
        pointRadius: 2,
        pointHoverRadius: 4,
        borderWidth: 2,
        hidden: false
      }));

      new Chart(tsCanvas, {
        type: 'line',
        data: { labels: dateLabels, datasets },
        options: {
          maintainAspectRatio: false,
          interaction: { mode: 'nearest', intersect: false },
          plugins: {
            legend: { display: true, position: 'bottom', labels: { boxWidth: 12 } },
            tooltip: { mode: 'index', intersect: false }
          },
          scales: {
            x: { display: true, title: { display: false } },
            y: { display: true, title: { display: true, text: 'Messages / day' }, beginAtZero: true }
          }
        }
      });
    })();
  </script>
</body>
</html>
