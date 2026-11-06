<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Attachment uploads, called by assets/js/attachments.js.
 *   JSON  {action: "match", id, names: [...]}   -> {ok, matches: {name: username|null}}
 *   form  action=put, id, user, name, upload (client token), offset, last, chunk
 *         Files arrive in pieces of 1 MB or less, so PHP's upload limits never get in the way.
 *         On the last piece the file becomes pages of the student -> {ok, added, problems, pages}
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
csrf_check();
set_time_limit(300);

$isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
$req = $isJson ? json_request() : $_POST;
$exam = load_exam((string)($req['id'] ?? ''));
if (!$exam || !exam_can_manage($user, $exam)) {
    json_response(['ok' => false, 'error' => 'Exam not found.'], 404);
}

if (($req['action'] ?? '') === 'match') {
    $names = array_values(array_filter(array_map('strval', (array)($req['names'] ?? []))));
    json_response(['ok' => true, 'matches' => attachment_match_files($names, attachment_students($exam))]);
}

if (($req['action'] ?? '') === 'put') {
    $username = (string)($req['user'] ?? '');
    $token = (string)($req['upload'] ?? '');
    $name = basename((string)($req['name'] ?? 'file'));
    if (!isset(attachment_students($exam)[$username]) || !preg_match('/^[a-z0-9]{8,40}$/', $token)) {
        json_response(['ok' => false, 'error' => 'Unknown student or upload.'], 400);
    }
    $chunk = $_FILES['chunk'] ?? null;
    if (!$chunk || $chunk['error'] !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'The upload did not arrive. Try again.'], 400);
    }
    $part = exam_dir($exam['id']) . "/attachments/.incoming/$token.part";
    ensure_dir(dirname($part));
    foreach (glob(dirname($part) . '/*.part') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);   // uploads abandoned a day ago
        }
    }
    $offset = (int)($req['offset'] ?? 0);
    clearstatcache(true, $part);
    $size = is_file($part) ? filesize($part) : 0;
    if ($offset === 0 && $size > 0) {
        unlink($part);   // a restarted upload
        $size = 0;
    }
    if ($offset !== $size) {
        json_response(['ok' => false, 'error' => 'A piece of the file went missing. Try again.'], 409);
    }
    if ($size + (int)$chunk['size'] > 60 * 1024 * 1024) {
        @unlink($part);
        json_response(['ok' => false, 'error' => "$name is larger than 60 MB."], 413);
    }
    file_put_contents($part, file_get_contents($chunk['tmp_name']), FILE_APPEND | LOCK_EX);
    if (empty($req['last'])) {
        json_response(['ok' => true, 'received' => filesize($part)]);
    }
    [$added, $problems] = attachment_add_files($exam['id'], $username, [[$name, $part]]);
    @unlink($part);
    json_response(['ok' => true, 'added' => $added, 'problems' => $problems, 'pages' => count(attachment_pages($exam['id'], $username))]);
}

json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
