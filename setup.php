<?php
declare(strict_types=1);

/**
 * First-run setup.
 *
 * Creates the core tables and the first administrator. It locks itself the
 * moment an active admin exists, so it cannot be used to mint a second one —
 * but delete the file after you have run it anyway.
 *
 * This exists because cPanel accounts do not reliably have shell access. If
 * yours does, prefer:  php app/scripts/migrate.php && php app/scripts/create_admin.php
 */

$appRoot = __DIR__ . '/app';

require_once $appRoot . '/config.php';
require_once $appRoot . '/db.php';
require_once $appRoot . '/migrations.php';

const MIN_SETUP_PASSWORD = 12;

$errors  = [];
$log     = [];
$done    = false;
$locked  = false;
$dbError = null;

try {
    db();

    if (db_table_exists('app_users')) {
        $admins = (int) db_value(
            'SELECT COUNT(*) FROM app_users WHERE role = "admin" AND is_active = 1',
            [],
            0
        );
        $locked = $admins > 0;
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

if (!$locked && $dbError === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $name     = trim((string) ($_POST['full_name'] ?? ''));
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if ($name === '') {
        $errors[] = 'Enter your name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < MIN_SETUP_PASSWORD) {
        $errors[] = 'The password must be at least ' . MIN_SETUP_PASSWORD . ' characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if ($errors === []) {
        try {
            $log = run_migrations();

            db_exec(
                'INSERT INTO app_users (email, full_name, password_hash, role, is_active)
                 VALUES (?, ?, ?, "admin", 1)',
                [$email, $name, password_hash($password, PASSWORD_DEFAULT)]
            );

            $log[] = '+ created administrator ' . $email;
            $done  = true;
        } catch (Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#F1561D">
<link rel="icon" href="movenetics.ico?v=20260823" type="image/x-icon">
<title>Setup · Movenetics Lead Search</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="styles.css?v=20260902-6">
</head>
<body class="centered">
<div class="authwrap">
<main class="authcard">
  <button class="theme-toggle auth-theme" type="button">Theme</button>

  <div class="authhead">
    <h1>Set up Lead Search</h1>
    <p>Creates the database tables and your administrator account.</p>
  </div>

<?php if ($dbError !== null): ?>
  <div class="err">
    <h3>Cannot reach the database</h3>
    <p>Check <code>.env</code> — host, database name, user and password.</p>
    <p class="mono" style="margin-top:8px"><?= h($dbError) ?></p>
  </div>

<?php elseif ($locked): ?>
  <div class="err">
    <h3>Setup is already complete</h3>
    <p>An administrator account exists, so this page is locked. Delete
       <code>setup.php</code> from the server, then <a href="login.html">sign in</a>.</p>
  </div>

<?php elseif ($done): ?>
  <div class="sechead"><h2>Done</h2></div>
  <pre class="sqlbox open mono"><?= h(implode("\n", $log)) ?></pre>
  <div class="err" style="margin-top:16px">
    <h3>One thing left</h3>
    <p>Delete <code>setup.php</code> from the server now — it is the only way in
       that does not require a password.</p>
  </div>
  <p style="margin-top:18px"><a class="go" href="login.html" style="display:inline-block;text-decoration:none">Go to sign in</a></p>

<?php else: ?>

  <?php if ($errors !== []): ?>
    <div class="err">
      <h3>Could not continue</h3>
      <p><?= h(implode(' ', $errors)) ?></p>
    </div>
  <?php endif; ?>

  <form method="post" class="authform">
    <label class="field">
      <span>Your name</span>
      <input type="text" name="full_name" required autocomplete="name"
             value="<?= h((string) ($_POST['full_name'] ?? '')) ?>">
    </label>
    <label class="field">
      <span>Email</span>
      <input type="email" name="email" required autocomplete="username"
             value="<?= h((string) ($_POST['email'] ?? '')) ?>">
    </label>
    <label class="field">
      <span>Password — at least <?= MIN_SETUP_PASSWORD ?> characters</span>
      <div class="pwwrap">
        <input type="password" name="password" required autocomplete="new-password">
        <button type="button" class="pwtoggle" aria-label="Show password" aria-pressed="false">
          <svg class="eye-on" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M12 5c-5.05 0-9.27 3.11-11 7.5 1.73 4.39 5.95 7.5 11 7.5s9.27-3.11 11-7.5C21.27 8.11 17.05 5 12 5Zm0 12.5a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>
          </svg>
          <svg class="eye-off" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" hidden>
            <path fill="currentColor" d="M2.81 2.81 1.39 4.22l3.28 3.28C3 8.94 1.5 10.85.99 12.5c1.73 4.39 5.95 7.5 11 7.5 1.83 0 3.55-.41 5.07-1.14l3.32 3.32 1.41-1.41ZM12 17.5a5 5 0 0 1-4.78-6.5l1.6 1.6a3 3 0 0 0 3.68 3.68l1.6 1.6a4.96 4.96 0 0 1-2.1.62Zm9.01-5C19.27 8.11 15.05 5 10 5c-.86 0-1.7.09-2.5.26l1.68 1.68a5 5 0 0 1 5.88 5.88l2.83 2.83c1.13-1.02 2.05-2.29 2.63-3.65Z"/>
          </svg>
        </button>
      </div>
    </label>
    <label class="field">
      <span>Confirm password</span>
      <div class="pwwrap">
        <input type="password" name="confirm" required autocomplete="new-password">
        <button type="button" class="pwtoggle" aria-label="Show password" aria-pressed="false">
          <svg class="eye-on" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M12 5c-5.05 0-9.27 3.11-11 7.5 1.73 4.39 5.95 7.5 11 7.5s9.27-3.11 11-7.5C21.27 8.11 17.05 5 12 5Zm0 12.5a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>
          </svg>
          <svg class="eye-off" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" hidden>
            <path fill="currentColor" d="M2.81 2.81 1.39 4.22l3.28 3.28C3 8.94 1.5 10.85.99 12.5c1.73 4.39 5.95 7.5 11 7.5 1.83 0 3.55-.41 5.07-1.14l3.32 3.32 1.41-1.41ZM12 17.5a5 5 0 0 1-4.78-6.5l1.6 1.6a3 3 0 0 0 3.68 3.68l1.6 1.6a4.96 4.96 0 0 1-2.1.62Zm9.01-5C19.27 8.11 15.05 5 10 5c-.86 0-1.7.09-2.5.26l1.68 1.68a5 5 0 0 1 5.88 5.88l2.83 2.83c1.13-1.02 2.05-2.29 2.63-3.65Z"/>
          </svg>
        </button>
      </div>
    </label>
    <button class="go" type="submit">Create tables and admin</button>
  </form>

  <p class="note">
    This page stops working as soon as an admin account exists. Every other
    account is created from the Users page by an administrator.
  </p>

<?php endif; ?>

</main>

<p class="authfoot">© Movenetics Digital · Lead Search</p>
</div>

<script src="app.js?v=20260902-3"></script>
</body>
</html>
