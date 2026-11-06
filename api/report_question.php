<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * A student reports a problem with a question during an exam (authenticated by the attempt token).
 * POST JSON {exam, user, n, token, slot, text} -> {ok, id}
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$req = json_request();
$exam = load_exam((string)($req['exam'] ?? ''));
$attempt = $exam ? load_attempt($exam['id'], (string)($req['user'] ?? ''), (int)($req['n'] ?? 0)) : null;
if (!$attempt || !is_string($req['token'] ?? null) || !hash_equals($attempt['token'], $req['token'])) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}
$slot = array_values(array_filter($attempt['slots'], fn($s) => $s['slot'] === (int)($req['slot'] ?? 0)))[0] ?? null;
$text = trim((string)($req['text'] ?? ''));
if (!$slot || $text === '') {
    json_response(['ok' => false, 'error' => 'Write what is wrong.'], 400);
}
json_response(['ok' => true, 'id' => add_question_report($exam['id'], $slot['key'], $attempt['user'], $text)]);
