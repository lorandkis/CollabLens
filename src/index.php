<?php
// Handle form submission and resume upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prepare GET parameters for dashboard
    $params = [];
    $params['assignment_id'] = $_POST['assignment_id'] ?? '';

    header('Location: report.php?' . http_build_query($params));
    exit;
}


// Database Creds:
$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$user = 'appuser';
$pass = 'apppass';

// Some Dashboard Data:
$professorName = "";
$totalAssignments = "";
$totalGroups = "";
try {
    // Create PDO connection:
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // GT PROFESSOR NAME
    $stmt = $pdo->prepare("SELECT * FROM professors");
    $stmt->execute(); // <-- Missing in your original code
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $professorName = $row['first_name'] . " " . $row['last_name'];
    }

    // GET NUMBER OF GROUPS
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_groups FROM `groups`");
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $totalGroups = (string)$row['total_groups'];
    }

    // GET NUMBER OF ASSIGNMENTS
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_assignments FROM assignments");
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $totalAssignments = (string)$row['total_assignments'];
    }
    
    // GET ASSIGNMENT TABLE INFO
    $sql = "
        SELECT 
            a.id AS 'ID',
            a.title AS `Title`,
            a.description AS `Description`,
            COUNT(gm.student_id) AS `# Of Students`,
            COUNT(DISTINCT g.id) AS `# Of Groups`,
            a.created_at AS `Created On`,
            a.status AS `Current Status`
        FROM assignments a
        LEFT JOIN `groups` g ON g.assignment_id = a.id
        LEFT JOIN group_members gm ON gm.group_id = g.id
        GROUP BY a.id
    ";
    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll();

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
}

?>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CollabLens Demo Dashboard</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/css/uikit.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit-icons.min.js"></script>
  <style>
    .section-content {
        display: none;
    }
    .section-content.active {
        display: block;
    }
  </style>
</head>
<body>
  <div class="uk-container uk-container-expand">
    <!-- Header -->
    <nav class="uk-navbar-container uk-navbar-transparent" uk-navbar>
      <div class="uk-navbar-left">
        <a class="uk-navbar-item uk-logo" href="#">
          <span class="uk-margin-small-left">CollabLens Demo</span>
        </a>
      </div>
      <div class="uk-navbar-right">
        <ul class="uk-navbar-nav">
          <li><a href="#" onclick="showSection('dashboard')" class="nav-link active">Dashboard</a></li>
          <li><a href="#" onclick="showSection('create')" class="nav-link">Create Class</a></li>
          <li><a href="#" onclick="showSection('upload')" class="nav-link">Upload Groups</a></li>
          <li><a href="#" onclick="showSection('reports')" class="nav-link">Reports</a></li>
        </ul>
      </div>
    </nav>

    <!-- Main Content -->
    <div class="uk-section uk-section-default">
      <div class="uk-container">
        <!-- Dashboard Section -->
        <div id="dashboard" class="section-content active">
          <div class="uk-grid uk-child-width-1-1 uk-margin-medium-bottom">
            <div>
              <h1 class="uk-heading-medium uk-text-primary">Welcome, Professor <?php echo htmlspecialchars($professorName); ?>!</h1>
              <p class="uk-text-lead">Monitor your students' collaboration and generate insights.</p>
            </div>
          </div>
          
          <div class="uk-grid uk-child-width-1-3@m uk-margin-medium-top" uk-grid>
            <div>
              <div class="uk-card uk-card-default uk-card-hover uk-card-body">
                <h3 class="uk-card-title">
                  <span uk-icon="icon: users; ratio: 1.2"></span>
                  Active Assignments
                </h3>
                <div id="assignments-count" class="uk-text-large uk-text-primary"><?php echo htmlspecialchars($totalAssignments); ?></div>
                <p class="uk-text-meta">Total assignments created</p>
              </div>
            </div>
            <div>
              <div class="uk-card uk-card-default uk-card-hover uk-card-body">
                <h3 class="uk-card-title">
                  <span uk-icon="icon: git-branch; ratio: 1.2"></span>
                  Student Groups
                </h3>
                <div id="groups-count" class="uk-text-large uk-text-primary"><?php echo htmlspecialchars($totalGroups);?></div>
                <p class="uk-text-meta">Active collaboration groups</p>
              </div>
            </div>
            <div>
              <div class="uk-card uk-card-default uk-card-hover uk-card-body">
                <h3 class="uk-card-title">
                  <span uk-icon="icon: comments; ratio: 1.2"></span>
                  Recent Activity
                </h3>
                <div id="activity-count" class="uk-text-large uk-text-primary">0</div>
                <p class="uk-text-meta">Messages & file updates</p>
              </div>
            </div>
          </div>

          <div class="uk-margin-large-top">
            <h2 class="uk-heading-small">Current Assignments</h2>
            <!-- TABLES! -->
            <table class="uk-table uk-table-hover uk-table-striped">
                <thead>
                    <tr>
                        <th style="color:black;">Title</th>
                        <th style="color:black;">Description</th>
                        <th style="color:black;"># Of Students</th>
                        <th style="color:black;"># Of Groups</th>
                        <th style="color:black;">Created On</th>
                        <th style="color:black;">Current Status</th>
                    </tr>
                </thead>
                <!-- The important fix is to ensure the modals are output AFTER the table -->
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
                <tr uk-toggle="target: #preview-modal-<?php echo $assignment['ID']; ?>" style="cursor: pointer;">
                    <td><?php echo htmlspecialchars($assignment['Title']); ?></td>
                    <td><?php echo htmlspecialchars(substr($assignment['Description'],0, 55) . "..."); ?></td>
                    <td><?php echo htmlspecialchars($assignment['# Of Students']); ?></td>
                    <td><?php echo htmlspecialchars($assignment['# Of Groups']); ?></td>
                    <td><?php echo htmlspecialchars($assignment['Created On']); ?></td>
                    <td><span class="uk-label <?php echo $labelClass ?>"><?php echo htmlspecialchars($assignment['Current Status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                </table>

                <?php foreach ($results as $assignment): ?>
                <div class="uk-flex-top uk-modal-container" id="preview-modal-<?php echo $assignment['ID']; ?>" uk-modal>
                    <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical">
                        <button class="uk-modal-close-default" type="button" uk-close></button>
                        <h2 class="uk-modal-title"><?php echo htmlspecialchars($assignment['Title']); ?></h2>
                        <p><?php echo htmlspecialchars($assignment['Description']); ?></p><br>
                        <div >
                            <div>
                                <p class="uk-text-lead">All Groups:</p>
                                <table class="uk-table uk-table-hover uk-table-small uk-table-striped">
                                    <thead>
                                        <tr>
                                            <th>Group ID</th>
                                            <th>Group Members</th>
                                            <th>Discord Channel ID</th>
                                            <th>Sharepoint Folder ID</th>
                                        </tr>
                                    </thead>
                                    <tbody id="groups-table-<?php echo $assignment['ID']; ?>"></tbody>
                                </table>
                            </div>
                            <div>
                                <p class="uk-text-lead">All Students:</p>
                                <table class="uk-table uk-table-hover uk-table-small uk-table-striped">
                                    <thead>
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Discord User</th>
                                        </tr>
                                    </thead>
                                    <tbody id="students-table-<?php echo $assignment['ID']; ?>"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </tbody>
            </table>
          </div>
        </div>

        <!-- Create Class Section -->
        <div id="create" class="section-content">
          <div class="uk-grid uk-child-width-1-1 uk-margin-medium-bottom">
            <div>
              <h1 class="uk-heading-medium uk-text-primary">Create New Assignment</h1>
              <p class="uk-text-lead">Set up a new assignment with Discord and SharePoint integration.</p>
            </div>
          </div>

          <div class="uk-width-1-2@m">
            <form class="uk-form-stacked" onsubmit="event.preventDefault(); UIkit.notification({message: 'This is a demo. No class created.', status: 'primary'});">
              <div class="uk-margin">
                <label class="uk-form-label" for="class-title">Assignment Title</label>
                <div class="uk-form-controls">
                  <input class="uk-input" id="class-title" type="text" placeholder="Enter class title" required>
                </div>
              </div>

              <div class="uk-margin">
                <label class="uk-form-label" for="class-description">Description</label>
                <div class="uk-form-controls">
                  <textarea class="uk-textarea" id="class-description" rows="4" placeholder="Enter class description" required></textarea>
                </div>
              </div>

              <div class="uk-margin">
                <label class="uk-form-label" for="due-date">Due Date</label>
                <div class="uk-form-controls">
                  <input class="uk-input" id="due-date" type="date" required>
                </div>
              </div>

              <div class="uk-margin">
                <button class="uk-button uk-button-primary uk-button-large" type="submit">
                  <span uk-icon="plus"></span>
                  Create Class
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Upload Groups Section -->
        <div id="upload" class="section-content">
          <div class="uk-grid uk-child-width-1-1 uk-margin-medium-bottom">
            <div>
              <h1 class="uk-heading-medium uk-text-primary">Upload Student Groups</h1>
              <p class="uk-text-lead">Upload CSV files or manually create student groups.</p>
            </div>
          </div>

          <div class="uk-margin uk-form-stacked">
            <label class="uk-text" style="color:black;">For Assignment:</label></br>
            <select class="uk-select uk-form-width-medium" aria-label="Select">
              <?php foreach ($results as $assignment): ?>
                <option><?php echo htmlspecialchars($assignment['Title']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="uk-grid uk-child-width-1-2@m" uk-grid>
            <div>
              <div class="uk-card uk-card-default uk-card-body">
                <h3 class="uk-card-title">
                  <span uk-icon="upload"></span>
                  Upload CSV File
                </h3>
                <div class="uk-margin">
                  <div uk-form-custom="target: true">
                    <input type="file" disabled>
                    <input class="uk-input" type="text" placeholder="Select CSV file" disabled>
                  </div>
                </div>
                <p class="uk-text-meta">(Demo only. Upload disabled.)</p>
              </div>
            </div>

            <div>
              <div class="uk-card uk-card-default uk-card-body">
                <h3 class="uk-card-title">
                  <span uk-icon="plus"></span>
                  Manual Entry
                </h3>
                <p class="uk-text-meta">Add students and groups manually through the interface.</p>
                <button class="uk-button uk-button-secondary" disabled>
                  <span uk-icon="plus"></span>
                  Add Group
                </button>
              </div>
            </div>
          </div>

          <!-- <div class="uk-margin-large-top">
            <h2 class="uk-heading-small">Current Groups</h2>
            <div id="groups" class="uk-grid uk-child-width-1-2@m" uk-grid></div>
          </div> -->
        </div>

        <!-- Reports Section -->
        <div id="reports" class="section-content">
          <div class="uk-grid uk-child-width-1-1 uk-margin-medium-bottom">
            <div>
              <h1 class="uk-heading-medium uk-text-primary">Reports & Insights</h1>
              <p class="uk-text-lead">Generate AI-powered collaboration insights and view activity reports.</p>
            </div>
          </div>


          <div class="uk-grid uk-child-width-1-1@m" uk-grid>
            <div>
              <div class="uk-card uk-card-default uk-card-body">
                <form method="POST" enctype="multipart/form-data" class="uk-form-stacked" id="searchForm">
                  <!-- Assignment Dropdown -->
                  <div class="uk-margin">
                    <label class="uk-form-label">For Assignment:</label>
                    <select class="uk-select uk-form-width-medium" aria-label="Select" name="assignment_id" required>
                      <?php foreach ($results as $assignment): ?>
                        <option value="<?php echo htmlspecialchars($assignment['ID']); ?>"><?php echo htmlspecialchars($assignment['Title']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="uk-margin">
                    <label class="uk-form-label" for="class-description">Report AI Prompt</label>
                    <div class="uk-form-controls">
                      <textarea class="uk-textarea" id="class-description" rows="4" placeholder="Enter what you would like your report to have." required></textarea>
                    </div>
                  </div>

                  <!-- Submit -->
                  <div class="uk-text-left uk-margin-top">
                    <button type="submit" class="uk-button uk-button-primary uk-button-medium"><span class="uk-margin-small-right uk-icon" uk-icon="file-text"></span>Create AI Report</button>
                  </div>
                </form>
              </div>
            </div>
          

          <!-- <div id="ai-summary" class="uk-margin-medium-bottom"></div>

          <div class="uk-grid uk-child-width-1-2@m" uk-grid>
            <div>
              <h3 class="uk-heading-small">
                <span uk-icon="comments"></span>
                Discord Activity
              </h3>
              <div id="discord-activity"></div>
            </div>
            <div>
              <h3 class="uk-heading-small">
                <span uk-icon="folder"></span>
                SharePoint Activity
              </h3>
              <div id="sharepoint-activity"></div>
            </div>
          </div>

          <div class="uk-margin-large-top">
            <h2 class="uk-heading-small">Collaboration Reports</h2>
            <div id="reports-list" class="uk-grid uk-child-width-1-2@m" uk-grid></div>
          </div> -->
        </div>
      </div>
    </div>
  </div>

  <script src="assets/js/app.js"></script>
</body>
<script>
  function showSection(sectionId) {
      const sections = document.querySelectorAll('.section-content');
      const navLinks = document.querySelectorAll('.nav-link');

      sections.forEach(section => {
          section.style.display = 'none';
      });

      document.getElementById(sectionId).style.display = 'block';

      navLinks.forEach(link => {
          link.classList.remove('active');
      });

      const activeLink = Array.from(navLinks).find(link => link.getAttribute('onclick')?.includes(sectionId));
      if (activeLink) activeLink.classList.add('active');
  }

  // Initialize on page load
  document.addEventListener("DOMContentLoaded", function() {
      showSection('dashboard'); // Show the dashboard by default
  });
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("tr[uk-toggle]").forEach(row => {
        row.addEventListener("click", function () {
            const modalId = row.getAttribute("uk-toggle").match(/#preview-modal-(\d+)/)[1];
            const assignmentId = modalId;

            fetch(`getAssignmentDetails.php?assignment_id=${assignmentId}`)
                .then(response => response.json())
                .then(data => {
                    const groupsTable = document.getElementById(`groups-table-${assignmentId}`);
                    const studentsTable = document.getElementById(`students-table-${assignmentId}`);

                    groupsTable.innerHTML = '';
                    studentsTable.innerHTML = '';

                    data.groups.forEach(group => {
                        groupsTable.innerHTML += `
                            <tr>
                                <td>${group.id}</td>
                                <td>${group.group_members || '-'}</td>
                                <td>${group.discord_channel_id || '-'}</td>
                                <td>${group.sharepoint_folder_id || '-'}</td>
                            </tr>`;
                    });

                    data.students.forEach(student => {
                        studentsTable.innerHTML += `
                            <tr>
                                <td>${student.student_id}</td>
                                <td>${student.name || '-'}</td>
                                <td>${student.school_email || '-'}</td>
                                <td>${student.discord_user || '-'}</td>
                            </tr>`;
                    });
                })
                .catch(error => {
                    console.error('Error loading modal data:', error);
                });
        });
    });
});
</script>

</html> 