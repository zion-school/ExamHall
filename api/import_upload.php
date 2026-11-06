<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Import uploads, called by assets/js/import.js.
 *   action=start  base, mode, strip           -> {ok, id}
 *   action=files  id, paths[], files[]        -> {ok, received, errors[]}
 *   action=chunk  id, path, offset, last, chunk -> {ok, errors[]}   (large files in pieces)
 * Files arrive in small batches so PHP's max_file_uploads / post_max_size are never exceeded.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
csrf_check();
set_time_limit(300);

$action = $_POST['action'] ?? '';
if ($action === 'start') {
    $base = (string)($_POST['base'] ?? '');
    // The base may be empty or not name a subject (the uploaded folders can); the analysis checks each question
    $baseSubject = subject_from_path(normalize_category($base));
    if ($baseSubject !== null && !can_manage_subject($user, $baseSubject)) {
        json_response(['ok' => false, 'error' => "You cannot import into $baseSubject."], 403);
    }
    $id = import_start($user, $base, (string)($_POST['mode'] ?? 'folders'), !empty($_POST['strip']));
    json_response(['ok' => true, 'id' => $id]);
}

if ($action === 'files') {
    $id = (string)($_POST['id'] ?? '');
    $meta = import_meta($id);
    if (!import_can_access($user, $meta)) {
        json_response(['ok' => false, 'error' => 'Import not found.'], 404);
    }
    $paths = $_POST['paths'] ?? [];
    $files = $_FILES['files'] ?? null;
    $received = 0;
    $errors = [];
    if ($files && is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            $relPath = (string)($paths[$i] ?? $name);
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "\"$relPath\" did not upload (error {$files['error'][$i]}; the server may limit file size).";
                continue;
            }
            $errs = import_add_file($id, $relPath, $files['tmp_name'][$i]);
            $errors = array_merge($errors, $errs);
            if (!$errs || import_extension($relPath) === 'zip') {
                $received++;
            }
        }
    }
    json_response(['ok' => true, 'received' => $received, 'errors' => $errors]);
}

if ($action === 'chunk') {
    $id = (string)($_POST['id'] ?? '');
    $meta = import_meta($id);
    if (!import_can_access($user, $meta)) {
        json_response(['ok' => false, 'error' => 'Import not found.'], 404);
    }
    $chunk = $_FILES['chunk'] ?? null;
    if (!$chunk || $chunk['error'] !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'A piece of the file did not arrive.'], 400);
    }
    $errors = import_add_chunk($id, (string)($_POST['path'] ?? ''), (int)($_POST['offset'] ?? 0), $chunk['tmp_name'], !empty($_POST['last']));
    json_response(['ok' => true, 'errors' => $errors]);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
