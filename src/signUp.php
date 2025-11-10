<?php
require_once __DIR__ . '/auth.php';

$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$defaultUser = 'appuser';
$defaultPass = 'apppass';

$errorMessage = '';
$flash = '';

// Load organizations for the datalist using the default credentials if possible
$orgs = [];
try {
    $pdoDefault = new PDO($dsn, $defaultUser, $defaultPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try {
        $stmtOrgs = $pdoDefault->query('SELECT id, name FROM organization ORDER BY name');
        $orgs = $stmtOrgs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_) {
        $orgs = [];
    }
} catch (Throwable $_) {
    $orgs = [];
}

// Handle POST actions: both actions require DB username + password to be provided
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $action = $_POST['action'] ?? '';

    if ($dbUser === '' || $dbPass === '') {
        $errorMessage = 'Database username and password are required to create accounts.';
    } else {
        // Try connecting with provided DB credentials
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            $errorMessage = 'Database credentials invalid or cannot connect.';
        }
    }

    if (empty($errorMessage) && $action === 'create_org') {
        $orgName = trim($_POST['org_name'] ?? '');
    $orgLocation = trim($_POST['primary_location'] ?? '');
        if ($orgName === '') {
            $errorMessage = 'Organization name is required.';
    } elseif ($orgLocation === '') {
      $errorMessage = 'Primary location is required for the organization.';
        } else {
            try {
                // check existing
                $st = $pdo->prepare('SELECT id FROM organization WHERE name = ? LIMIT 1');
                $st->execute([$orgName]);
                $exists = $st->fetchColumn();
                if ($exists) {
                    $errorMessage = 'An organization with that name already exists.';
                } else {
          $ins = $pdo->prepare('INSERT INTO organization (name, primary_location, joined_at) VALUES (?, ?, NOW())');
          $ins->execute([$orgName, $orgLocation]);
                    $_SESSION['signup_flash'] = 'Organization created successfully.';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }
            } catch (PDOException $e) {
                $errorMessage = 'Database error: ' . $e->getMessage();
            }
        }
    }

    if (empty($errorMessage) && $action === 'create_professor') {
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $orgName = trim($_POST['org_name'] ?? '');

        if ($first === '' || $last === '') $errorMessage = 'First and last name are required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errorMessage = 'Invalid email address.';
        elseif ($password === '' || $confirm === '') $errorMessage = 'Password and confirmation are required.';
        elseif ($password !== $confirm) $errorMessage = 'Passwords do not match.';

        if (empty($errorMessage)) {
            try {
        // resolve or create org
        $orgId = null;
        // Support the new select flow: if user picked '__new__', read the new name from org_name_new
        if ($orgName === '__new__') {
          $orgNameNew = trim($_POST['org_name_new'] ?? '');
          $orgLocation = trim($_POST['org_location'] ?? '');
          if ($orgNameNew === '') throw new Exception('New institution name is required.');
          if ($orgLocation === '') throw new Exception('Institution primary location is required.');
          // check if already exists
          $st = $pdo->prepare('SELECT id FROM organization WHERE name = ? LIMIT 1');
          $st->execute([$orgNameNew]);
          $orgId = $st->fetchColumn();
          if (!$orgId) {
            $ins = $pdo->prepare('INSERT INTO organization (name, primary_location, joined_at) VALUES (?, ?, NOW())');
            $ins->execute([$orgNameNew, $orgLocation]);
            $orgId = (int)$pdo->lastInsertId();
          }
        } elseif ($orgName !== '') {
          $st = $pdo->prepare('SELECT id FROM organization WHERE name = ? LIMIT 1');
          $st->execute([$orgName]);
          $orgId = $st->fetchColumn();
        }

                // duplicate email check
                $st = $pdo->prepare('SELECT id FROM professors WHERE email = ? LIMIT 1');
                $st->execute([$email]);
                if ($st->fetch()) {
                    $errorMessage = 'An account with that email already exists.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare('INSERT INTO professors (email, password_hash, first_name, last_name, org_id) VALUES (?, ?, ?, ?, ?)');
                    $ins->execute([$email, $hash, $first, $last, $orgId]);

                    // Try to auto-login using this PDO
                    $user = login_user($pdo, $email, $password, true);
                    if ($user) {
                        header('Location: userDashboard.php');
                        exit;
                    } else {
                        $_SESSION['signup_flash'] = 'Professor account created. Please log in.';
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit;
                    }
                }
            } catch (PDOException $e) {
                $errorMessage = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Pull flash set during PRG
if (!empty($_SESSION['signup_flash'])) { $flash = $_SESSION['signup_flash']; unset($_SESSION['signup_flash']); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Accounts – CollabLens</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/css/uikit.min.css" />
  <!-- <link rel="stylesheet" href="/reasources/css/custom.css" /> -->
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit-icons.min.js"></script>
</head>
<body>

  <!-- Nav Bar -->
  <nav class="uk-navbar-container" style="background: #1e87f0;">
      <div class="uk-container">
          <div uk-navbar>
              <div class="uk-navbar-left">
                  <ul class="uk-navbar-nav">
                      <li class="uk-active"><a href="/"><img src="/reasources/baj_logo.svg" alt="BAJ Logo" style="height: 85px;"> <h2 style="color: white; display: inline; margin: 0;">CollabLens</h2></a></li>
                  </ul>
              </div>
          </div>
      </div>
  </nav>




  <div class="uk-section uk-section-muted uk-flex uk-flex-center uk-flex-middle" style="min-height:100vh;">
    <div class="uk-container uk-width-3-4@l">
      <div class="uk-grid-medium" uk-grid>
        <div class="uk-width-1-2@l">
          <div class="uk-card uk-card-default uk-card-body">
            <h3 class="uk-card-title">Create Organization</h3>
            <?php if ($flash && empty($errorMessage)): ?>
              <div class="uk-alert-success" uk-alert><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
              <div class="uk-alert-danger" uk-alert><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="action" value="create_org">
              <div class="uk-margin">
                <label class="uk-form-label">Organization Name</label>
                <div class="uk-form-controls"><input class="uk-input" name="org_name" value="<?php echo htmlspecialchars($_POST['org_name'] ?? ''); ?>" required></div>
              </div>
              <div class="uk-margin">
                <label class="uk-form-label">Primary Location</label>
                <div class="uk-form-controls"><input class="uk-input" name="primary_location" value="<?php echo htmlspecialchars($_POST['primary_location'] ?? ''); ?>" placeholder="City, State or Campus" required></div>
              </div>
              <hr />
              <h4>Database Credentials (required)</h4>
              <div class="uk-margin">
                <label class="uk-form-label">DB Username</label>
                <div class="uk-form-controls"><input class="uk-input" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" required></div>
              </div>
              <div class="uk-margin">
                <label class="uk-form-label">DB Password</label>
                <div class="uk-form-controls"><input class="uk-input" type="password" name="db_pass" required></div>
              </div>
              <div class="uk-margin">
                <button class="uk-button uk-button-primary" type="submit">Create Organization</button>
              </div>
            </form>
          </div>
        </div>

        <div class="uk-width-1-2@l">
          <div class="uk-card uk-card-default uk-card-body">
            <h3 class="uk-card-title">Create Professor</h3>
            <form method="post">
              <input type="hidden" name="action" value="create_professor">
              <div class="uk-grid-small" uk-grid>
                <div class="uk-width-1-2@s"><label class="uk-form-label">First Name</label><input class="uk-input" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required></div>
                <div class="uk-width-1-2@s"><label class="uk-form-label">Last Name</label><input class="uk-input" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required></div>
              </div>
              <div class="uk-margin"><label class="uk-form-label">Email</label><input class="uk-input" type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required></div>
              <div class="uk-grid-small" uk-grid>
                <div class="uk-width-1-2@s"><label class="uk-form-label">Password</label><input class="uk-input" type="password" name="password" required></div>
                <div class="uk-width-1-2@s"><label class="uk-form-label">Confirm</label><input class="uk-input" type="password" name="confirm_password" required></div>
              </div>
              <div class="uk-margin">
                <label class="uk-form-label">Institution</label>
                <div class="uk-form-controls">
                  <select class="uk-select" name="org_name" id="org_select">
                    <option value="">Select institution...</option>
                    <?php foreach ($orgs as $o): ?>
                      <option value="<?php echo htmlspecialchars($o['name']); ?>" <?php echo (isset($_POST['org_name']) && $_POST['org_name'] === $o['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($o['name']); ?></option>
                    <?php endforeach; ?>
                    <option value="__new__" <?php echo (isset($_POST['org_name']) && $_POST['org_name'] === '__new__') ? 'selected' : ''; ?>>Create new institution...</option>
                  </select>
                </div>
              </div>

              <div id="new_org_block" style="display: <?php echo (isset($_POST['org_name']) && $_POST['org_name'] === '__new__') ? 'block' : 'none'; ?>;">
                <div class="uk-margin">
                  <label class="uk-form-label">New Institution Name</label>
                  <div class="uk-form-controls"><input class="uk-input" name="org_name_new" value="<?php echo htmlspecialchars($_POST['org_name_new'] ?? ''); ?>" placeholder="Full institution name"></div>
                </div>
                <div class="uk-margin">
                  <label class="uk-form-label">Institution Primary Location <span class="uk-text-meta">(required)</span></label>
                  <div class="uk-form-controls"><input class="uk-input" name="org_location" value="<?php echo htmlspecialchars($_POST['org_location'] ?? ''); ?>" placeholder="City, State or Campus"></div>
                </div>
              </div>

              <hr />
              <h4>Database Credentials (required)</h4>
              <div class="uk-margin">
                <label class="uk-form-label">DB Username</label>
                <div class="uk-form-controls"><input class="uk-input" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" required></div>
              </div>
              <div class="uk-margin">
                <label class="uk-form-label">DB Password</label>
                <div class="uk-form-controls"><input class="uk-input" type="password" name="db_pass" required></div>
              </div>

              <div class="uk-margin">
                <button class="uk-button uk-button-primary" type="submit">Create Professor</button>
              </div>
            </form>
            <p class="uk-text-small uk-margin-top">Already have an account? <a href="login.php">Login here</a></p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <footer class="uk-section uk-section-small uk-section-muted uk-text-center">
    <div class="uk-container"><p>© 2025 GroupLens. All rights reserved.</p></div>
  </footer>
  <script>
    (function(){
      var sel = document.getElementById('org_select');
      var block = document.getElementById('new_org_block');
      if (!sel || !block) return;
      sel.addEventListener('change', function(){
        if (sel.value === '__new__') block.style.display = 'block'; else block.style.display = 'none';
      });
    })();
  </script>
</body>
</html>
