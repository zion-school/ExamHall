<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * A picture of an exam question as a 1-bit WBMP for a 128x64 screen
 * (Type 0, WAP-237 WAE section 6): ?id=<exam>&f=<file in its media/>,
 * for the students of the exam (as media.php). Fitted to 120x48, dithered
 * by GD, and cached in the data folder until the picture changes.
 */
require_once __DIR__ . '/lib/wap.php';

$user = wap_student();
$exam = load_exam((string)($_GET['id'] ?? ''));
$file = $exam ? exam_dir($exam['id']) . '/media/' . basename((string)($_GET['f'] ?? '')) : '';
if (!$exam || $exam['status'] === 'draft' || !exam_in_audience($exam, $user) || !is_file($file)) {
    http_response_code(404);
    exit();
}

$dir = data_path('cache', 'wap');
$cache = $dir . '/' . sha1($file) . '.wbmp';
if (!is_file($cache) || filemtime($cache) < filemtime($file)) {
    $src = @imagecreatefromstring(file_get_contents($file));
    if (!$src) {
        http_response_code(404);
        exit();
    }
    $k = min(1, 120 / imagesx($src), 48 / imagesy($src));
    $w = max(1, (int)round(imagesx($src) * $k));
    $h = max(1, (int)round(imagesy($src) * $k));
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));    // transparent is white
    imagecopyresampled($img, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
    imagefilter($img, IMG_FILTER_GRAYSCALE);
    imagetruecolortopalette($img, true, 2);
    // The darker of the two colours is the WBMP's black
    $dark = imagecolorsforindex($img, 0)['red'] <= imagecolorsforindex($img, imagecolorstotal($img) - 1)['red'] ? 0 : imagecolorstotal($img) - 1;
    if (!is_dir($dir)) {
        ensure_dir($dir);
        file_put_contents($dir . '/.gitignore', "*\n");
    }
    imagewbmp($img, $cache, $dark);
}
header('Content-Type: image/vnd.wap.wbmp');
header('Content-Length: ' . filesize($cache));
readfile($cache);
