<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Login page. Sends students to their exams and staff to the dashboard,
 * and WAP phones to wap/.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (wants_wml()) {
    redirect('wap/');
}

$user = current_user();
if ($user) {
    redirect(home_path_for($user));
}

$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $error = attempt_login($username, (string)($_POST['password'] ?? ''));
    if ($error === null) {
        redirect(home_path_for(current_user()));
    }
}

page_start('Log in', null, ['bare' => true]);
?>
<div class="login-wrap">
  <form class="login-card" method="post" autocomplete="off">
    <div class="brand-mark"><?= icon('sheet', 26) ?></div>
    <h1><?= h(cfg('APP_NAME')) ?></h1>
    <p class="muted"><?= h(cfg('SCHOOL_NAME')) ?></p>
    <?php if ($error): ?><div class="alert alert-err"><?= h($error) ?></div><?php endif; ?>
    <label class="field"><span>Username</span>
      <input type="text" name="username" value="<?= h($username) ?>" autocapitalize="none" spellcheck="false" autofocus required>
    </label>
    <label class="field"><span>Password</span>
      <input type="password" name="password" required>
    </label>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Log in</button>
  </form>
</div>
<?php page_end(['js' => ['assets/js/sync-rescue.js']]);
