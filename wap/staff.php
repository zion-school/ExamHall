<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * Teachers and admins: the phone is for students; the rest is on the web.
 */
require_once __DIR__ . '/lib/wap.php';

$user = current_user();
if (!$user) {
    wap_redirect('login.php');
}
if (!is_staff($user)) {
    wap_redirect('index.php');
}
wml_start(cfg('APP_NAME'), '', soft_keys([['Logout', go_to('logout.php')]]));
echo 'Hi ' . e($user['name']) . "<br/>\n";
echo "The phone is for students' exams. Please use a computer for exams, marking and results.<br/>\n";
wml_end();
