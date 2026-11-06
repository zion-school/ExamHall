<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Autosave / heartbeat / submit for the exam player (assets/js/player.js).
 * Authenticated by the attempt's secret token, not the PHP session, so an expired
 * login never loses answers. Also receives navigator.sendBeacon() posts.
 *
 * POST JSON {exam, user, n, token, epoch, seq, answers, flags, events, final, time_up}
 *  -> {ok, ack, server_time, deadline, status, late, early (a time-up before the deadline: not submitted)} or {ok:false, error: bad_token|other_device|finished|time_over|account_removed|not_found}
 * ?preview=1: a teacher's preview (teacher/preview.php); submitting it pays no rewards.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'post_only'], 405);
}
$raw = file_get_contents('php://input');
if (strlen($raw) > 2 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'too_large'], 413);
}
$req = json_decode($raw ?: '[]', true);
if (!is_array($req)) {
    json_response(['ok' => false, 'error' => 'bad_request'], 400);
}
$preview = !empty($_GET['preview']);
$res = save_attempt_request($req, $preview);
$status = $res['http'] ?? 200;
unset($res['http']);
if (empty($req['final']) || empty($res['ok']) || !empty($res['early']) || $preview) {
    json_response($res, $status);
}
// Submitted: send the reply now, then run post-grading hooks (rewards) without keeping the student waiting
$body = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . strlen($body));
header('Connection: close');
ignore_user_abort(true);
echo $body;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    flush();
}
fire_hook('attempt.graded', (string)$req['exam'], (string)$req['user']);
