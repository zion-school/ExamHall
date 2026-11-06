<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * Login card: the Exams login (Zion Bank's users.csv, with its throttling
 * of wrong passwords). Students go to their exams, staff to staff.php.
 */
require_once __DIR__ . '/lib/wap.php';

if (wap_posted()) {
    $username = trim($_POST['u'] ?? '');
    $error = attempt_login($username, (string)($_POST['pw'] ?? ''));
    clear_vars_next(['pw']);
    if ($error === null) {
        wap_redirect('index.php');
    }
    wap_flash($error === 'Wrong username or password.' ? 'Wrong user or password' : $error);
    $_SESSION['wap_login_user'] = $username;
    wap_redirect('login.php');
}

if (current_user()) {
    wap_redirect('index.php');
}

// newcontext: a fresh start, so no earlier input (or password) is kept
$login = go_post('login.php', ['u' => field('u'), 'pw' => field('pw')]);
// The user name of a failed attempt is filled in again
$preset = ['u' => $_SESSION['wap_login_user'] ?? ''];
unset($_SESSION['wap_login_user']);
wml_start(cfg('APP_NAME'), 'newcontext="true"', soft_keys([['Login', $login]]), $preset);
echo "User:<input name=\"u\" format=\"*m\" maxlength=\"20\" emptyok=\"false\"/><br/>\n";
echo "Password:<input name=\"pw\" type=\"password\" maxlength=\"20\"/><br/>\n";
wml_end();
