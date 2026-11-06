<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Live monitor data and actions for teachers.
 *   POST JSON {exam}                                        -> {ok, rows, unlocks, server_time}
 *   POST JSON {exam, action: unlock, scope, target, minutes}
 *   POST JSON {exam, action: end_unlock, no}
 *   POST JSON {exam, action: extend, user, minutes}
 *   POST JSON {exam, action: submit, user, n}
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
csrf_check();
$req = json_request();
$exam = load_exam((string)($req['exam'] ?? ''));
if (!$exam || !exam_can_manage($user, $exam)) {
    json_response(['ok' => false, 'error' => 'Exam not found.'], 404);
}

switch ($req['action'] ?? '') {
    case 'unlock':
        $minutes = max(1, min(600, (int)($req['minutes'] ?? 40)));
        $scope = ($req['scope'] ?? '') === 'class' ? 'class' : 'user';
        $target = (string)($req['target'] ?? '');
        if ($target === '' || ($scope === 'user' && !find_user($target))) {
            json_response(['ok' => false, 'error' => 'Choose a class or student.'], 400);
        }
        add_exam_unlock($exam['id'], $scope, $target, $minutes, $user['username']);
        break;
    case 'end_unlock':
        end_exam_unlock($exam['id'], (int)($req['no'] ?? 0));
        break;
    case 'extend':
        extend_attempt_time($exam, (string)($req['user'] ?? ''), max(1, min(240, (int)($req['minutes'] ?? 10))));
        break;
    case 'submit':
        force_submit_attempt($exam, (string)($req['user'] ?? ''), (int)($req['n'] ?? 0));
        fire_hook('attempt.graded', $exam['id'], (string)($req['user'] ?? ''));
        break;
}

$unlocks = array_values(array_filter(exam_unlocks($exam['id']), fn($u) => strtotime($u['until']) > time()));
foreach ($unlocks as &$u) {
    $u['minutes_left'] = (int)ceil((strtotime($u['until']) - time()) / 60);
    $u['by_name'] = display_name($u['created_by']);
}
unset($u);
json_response(['ok' => true, 'rows' => exam_monitor_rows($exam), 'unlocks' => $unlocks, 'server_time' => date('c')]);
