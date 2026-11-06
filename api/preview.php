<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Question previews for staff pages (HTML fragments).
 *   POST JSON {question: "<key>"}                   a bank question
 *   POST JSON {question: "<key>", exam: "<id>"}     the exam's own copy (or its paper's), else the bank question
 *            + pairs: [{keep: 2} | {question, answer}]   a matching question with the pairs chosen in the editor, not
 *                                                   saved yet (edit_match_pairs)
 *   POST JSON {import: "import-...", key: 12}       a question in an import preview
 *   POST JSON {gift: "...", category: "V/Maths"}    live preview while editing GIFT
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
csrf_check();
$req = json_request();

if (isset($req['question'], $req['exam'])) {
    $exam = load_exam((string)$req['exam']);
    if ($exam && exam_can_manage($user, $exam)) {
        $key = (string)$req['question'];
        $copy = load_paper($exam['id'])['questions'][$key] ?? null;
        foreach ($exam['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if (($item['q'] ?? null) === $key && isset($item['copy'])) {
                    $copy = edit_match_pairs($item['copy'], $req['pairs'] ?? null);
                }
            }
        }
        if ($copy) {
            json_response(['ok' => true, 'html' => render_question_preview($copy, exam_media_url($exam['id']))]);
        }
    }
}

if (isset($req['question'])) {
    $q = load_question((string)$req['question']);
    if (!$q || !can_manage_subject($user, subject_from_path($q['category']))) {
        json_response(['ok' => false, 'error' => 'Question not found.'], 404);
    }
    json_response(['ok' => true, 'html' => render_question_preview(edit_match_pairs($q, $req['pairs'] ?? null), bank_media_url($q['category']))]);
}

if (isset($req['import'])) {
    $meta = import_meta((string)$req['import']);
    if (!import_can_access($user, $meta)) {
        json_response(['ok' => false, 'error' => 'Import not found.'], 404);
    }
    $report = read_json(import_dir($meta['id']) . '/report.json');
    $entry = $report['questions'][(int)($req['key'] ?? -1)] ?? null;
    if (!$entry) {
        json_response(['ok' => false, 'error' => 'Question not found.'], 404);
    }
    $mediaUrl = function (string $src) use ($entry, $meta) {
        $found = $entry['pictures'][$src] ?? null;
        return $found === null ? null : url('media.php') . '?import=' . rawurlencode($meta['id']) . '&f=' . rawurlencode($found);
    };
    json_response(['ok' => true, 'html' => render_question_preview($entry['question'], $mediaUrl)]);
}

if (isset($req['gift'])) {
    $category = normalize_category((string)($req['category'] ?? ''));
    $result = (new GiftParser())->parse((string)$req['gift'], 'editor');
    $messages = array_merge($result['messages'], GiftLint::check($result['questions'], 'editor'));
    $html = '';
    foreach (array_slice($result['questions'], 0, 5) as $q) {
        $html .= render_question_preview($q, $category !== '' ? bank_media_url($category) : null);
    }
    json_response([
        'ok' => true,
        'count' => count($result['questions']),
        'type' => $result['questions'][0]['type'] ?? null,
        'html' => $html,
        'messages' => array_map(fn($m) => ['level' => $m['level'], 'line' => $m['line'], 'message' => $m['message']], $messages),
    ]);
}

json_response(['ok' => false, 'error' => 'Nothing to preview.'], 400);
