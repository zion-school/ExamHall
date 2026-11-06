<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Analyse an uploaded import and return where to review it.
 * POST JSON {id}
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
csrf_check();
set_time_limit(600);

$req = json_request();
$id = (string)($req['id'] ?? '');
$meta = import_meta($id);
if (!import_can_access($user, $meta) || $meta['status'] === 'committed') {
    json_response(['ok' => false, 'error' => 'Import not found.'], 404);
}
if ($meta['files'] === 0) {
    json_response(['ok' => false, 'error' => 'No GIFT files or pictures were received.'], 400);
}
$report = import_analyze($id, $user);
json_response(['ok' => true, 'questions' => $report['totals']['questions'], 'redirect' => url('teacher/import_review.php') . '?id=' . rawurlencode($id)]);
