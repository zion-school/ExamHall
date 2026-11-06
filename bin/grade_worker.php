<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Background AI grading and reward payouts. Run on the web server (cron, termux-services or a terminal):
 *   php bin/grade_worker.php            keep running, check for work every 20 s
 *   php bin/grade_worker.php --once     do everything waiting now, then stop
 * Without a worker, the Grading page runs the same work while a teacher has it open.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';

$once = in_array('--once', $argv, true);
if (trim((string)cfg('LLM_URL', '')) === '') {
    echo "LLM_URL is not set: only rewards will be processed.\n";
}
$lock = fopen(data_path('.locks') . '/grade_worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another grade worker is already running.\n");
    exit(0);
}
echo date('H:i:s') . " grade worker started (model " . cfg('LLM_MODEL') . ")\n";
while (true) {
    $result = trim((string)cfg('LLM_URL', '')) === '' ? ['done' => true, 'message' => ''] : grading_process_one();
    foreach (all_exams() as $e) {
        if (exam_is_reward($e) && $e['status'] !== 'draft') {
            fire_hook('grading.changed', $e['id']);   // pays rewards that became due
        }
    }
    if ($result['done']) {
        if ($once) {
            break;
        }
        sleep(20);
        continue;
    }
    echo date('H:i:s') . ' ' . $result['message'] . "\n";
    if (!empty($result['error'])) {
        sleep(30);   // model down or overloaded: back off
    }
}
