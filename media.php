<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Serves pictures with access checks:
 *   media.php?c=<category>&f=<path>     question bank (staff of that subject); path from the chapter file's folder, such as media/icon.png
 *   media.php?exam=<id>&f=<file>        exam paper (students of the exam, staff of the subject)
 *   media.php?exam=<id>&attachment=<username>&f=<file>  attachment pages (staff of the subject)
 *   media.php?import=<id>&f=<path>      pictures inside an import upload (its owner)
 */
require_once __DIR__ . '/lib/bootstrap.php';

const MEDIA_TYPES = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'svg' => 'image/svg+xml',
];

function media_not_found(): void
{
    http_response_code(404);
    exit('Not found');
}

$user = require_login();
$file = null;

try {
    if (isset($_GET['c'])) {
        $category = normalize_category((string)$_GET['c']);
        if (!is_staff($user) || !can_manage_subject($user, subject_from_path($category))) {
            media_not_found();
        }
        // f is the picture's path from the folder of the chapter file (media/icon.png, images/ch-1/vga.jpg); a bare name is in media/
        $path = (string)($_GET['f'] ?? '');
        $path = bank_picture_path(str_contains($path, '/') ? $path : "media/$path");
        $file = $path === null ? null : bank_file_dir($category) . '/' . $path;
    } elseif (isset($_GET['exam'], $_GET['attachment'])) {
        $exam = load_exam((string)$_GET['exam']);
        if (!$exam || !is_staff($user) || !exam_can_manage($user, $exam)) {
            media_not_found();
        }
        $file = attachment_dir($exam['id'], (string)$_GET['attachment']) . '/' . basename((string)($_GET['f'] ?? ''));
    } elseif (isset($_GET['exam'])) {
        $exam = load_exam((string)$_GET['exam']);
        $allowed = $exam && (
            (is_staff($user) && exam_can_manage($user, $exam))
            || ($exam['status'] !== 'draft' && exam_in_audience($exam, $user))
        );
        if (!$allowed) {
            media_not_found();
        }
        $file = exam_dir($exam['id']) . '/media/' . basename((string)($_GET['f'] ?? ''));
    } elseif (isset($_GET['import'])) {
        $meta = import_meta((string)$_GET['import']);
        if (!is_staff($user) || !import_can_access($user, $meta)) {
            media_not_found();
        }
        $path = import_safe_path((string)($_GET['f'] ?? ''));
        $file = $path === null ? null : import_dir($meta['id']) . '/files/' . $path;
    }
} catch (Throwable $e) {
    media_not_found();
}

$ext = $file ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : '';
if (!$file || !isset(MEDIA_TYPES[$ext]) || !is_file($file)) {
    media_not_found();
}

$etag = '"' . filemtime($file) . '-' . filesize($file) . '"';
header('Content-Type: ' . MEDIA_TYPES[$ext]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('ETag: ' . $etag);
if ($ext === 'svg') {
    // An SVG opened directly must not run scripts
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
}
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($file));
readfile($file);
