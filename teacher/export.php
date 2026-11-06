<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Download a bank folder (with sub-folders) as one GIFT file, or as a ZIP with
 * one GIFT file per chapter and the pictures, laid out as in the bank. Hidden questions are left out.
 *   teacher/export.php?c=V/Term-2/Maths&format=gift|zip
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$category = normalize_category((string)($_GET['c'] ?? ''));
$tree = category_tree($user);
if ($category === '' || !isset($tree[$category])) {
    http_response_code(404);
    exit('Folder not found');
}
$categories = array_values(array_filter(array_keys($tree), fn($p) => $tree[$p]['count'] > 0 && ($p === $category || str_starts_with($p, $category . '/'))));
$name = slugify(str_replace('/', ' ', $category), 60) ?: 'questions';

if (($_GET['format'] ?? 'gift') === 'zip') {
    if (!class_exists('ZipArchive')) {
        exit('This server cannot create ZIP files (PHP zip extension missing).');
    }
    $tmp = data_path('tmp', $name . '-' . getmypid() . '.zip');
    ensure_dir(dirname($tmp));
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (export_categories_gift($categories) as $file => $gift) {
        $zip->addFromString($file, $gift);
    }
    // The pictures the exported questions use, at the same path next to their .gift file
    $added = [];
    foreach ($categories as $c) {
        $folder = dirname($c) === '.' ? '' : dirname($c) . '/';
        foreach (category_questions($c, false) as $q) {
            foreach (question_media_refs($q) as $ref) {
                if (bank_picture_path($ref) !== null && !isset($added[$folder . $ref]) && is_file(bank_file_dir($c) . "/$ref")) {
                    $zip->addFile(bank_file_dir($c) . "/$ref", $folder . $ref);
                    $added[$folder . $ref] = true;
                }
            }
        }
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '.gift"');
echo '// Exported from ' . cfg('APP_NAME') . ' on ' . date('d M Y, h:i A') . "\n";
echo "// Pictures are not included in a single GIFT file; export as ZIP to keep them.\n\n";
echo implode("\n", export_categories_gift($categories));
