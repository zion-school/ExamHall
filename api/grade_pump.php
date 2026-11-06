<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Runs one unit of AI grading while a teacher keeps the Grading page open
 * (for servers without bin/grade_worker.php). POST JSON {exam} -> {ok, done, message, workload}
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
csrf_check();
$req = json_request();
$exam = load_exam((string)($req['exam'] ?? ''));
if (!$exam || !exam_can_manage($user, $exam)) {
    json_response(['ok' => false, 'error' => 'Exam not found.'], 404);
}
session_write_close();   // do not block the teacher's other pages while the model works
set_time_limit((int)cfg('LLM_TIMEOUT_SEC', 180) + 30);
$result = trim((string)cfg('LLM_URL', '')) === ''
    ? ['done' => true, 'message' => 'AI checking is not set up (LLM_URL).']
    : grading_process_one([$exam['id']]);
fire_hook('grading.changed', $exam['id']);
json_response(['ok' => true, 'done' => $result['done'], 'error' => !empty($result['error']), 'message' => $result['message'],
    'workload' => grading_workload([$exam['id']])[$exam['id']] ?? ['pending_llm' => 0, 'review' => 0, 'essays' => 0]]);
