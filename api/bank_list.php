<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Questions of a bank folder for the exam editor's picker.
 * POST JSON {category, sub: bool, q: "search"} -> {ok, questions: [{id, type, name, text, tags, category, label}], pool}
 * (in file order; id is the hidden key, label the section and number shown instead)
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
csrf_check();
$req = json_request();
$category = normalize_category((string)($req['category'] ?? ''));
$sub = !empty($req['sub']);
$needle = mb_strtolower(trim((string)($req['q'] ?? '')));
$tree = category_tree($user);

$out = [];
foreach (bank_index() as $id => $row) {
    if (!isset($tree[$row['category']]) || $row['hidden'] === '1') {
        continue;
    }
    if ($category !== '' && $row['category'] !== $category && !($sub && str_starts_with($row['category'], $category . '/'))) {
        continue;
    }
    if ($needle !== '' && !str_contains(mb_strtolower($row['name'] . ' ' . $row['text'] . ' ' . $row['tags'] . ' ' . $row['section']), $needle)) {
        continue;
    }
    $out[] = ['id' => $id, 'type' => $row['type'], 'name' => $row['name'], 'text' => $row['text'], 'tags' => $row['tags'], 'category' => $row['category'], 'label' => question_label($row)];
    if (count($out) >= 500) {
        break;
    }
}
json_response(['ok' => true, 'questions' => $out, 'truncated' => count($out) >= 500]);
