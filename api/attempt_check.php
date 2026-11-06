<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Practice exams only (no rewards, answers shown straight away): check one answer during
 * the attempt. Nothing is saved; the score still comes from submitting.
 * POST JSON {exam, user, n, token, slot, answer} -> {ok, fraction, message, right_answer, feedback}
 * ?preview=1: a teacher's preview (teacher/preview.php).
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$req = json_request();
$preview = !empty($_GET['preview']);
$exam = load_exam((string)($req['exam'] ?? ''));
$attempt = $exam ? load_attempt($exam['id'], (string)($req['user'] ?? ''), (int)($req['n'] ?? 0), $preview) : null;
if (!$attempt || !is_string($req['token'] ?? null) || !hash_equals($attempt['token'], $req['token'])) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}
if (!exam_is_practice($exam)) {
    json_response(['ok' => false, 'error' => 'Checking answers is only for practice exams.'], 403);
}
$paper = $preview ? load_preview_paper($exam['id'], $attempt['user']) : load_paper($exam['id']);
$slot = array_values(array_filter($attempt['slots'], fn($s) => $s['slot'] === (int)($req['slot'] ?? 0)))[0] ?? null;
if (!$slot) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}
$q = $paper['questions'][$slot['key']];
$answer = sanitize_response($q, $req['answer'] ?? null);
$grade = grade_response($q, $answer, answer_leniency($exam));
if ($q['type'] === 'shortanswer' && is_string($answer)) {
    $grade = smart_grade_short(['grading' => ['fuzzy' => $exam['grading']['fuzzy'], 'spelling_penalty' => 0, 'llm' => 'off']], $q, $answer, $grade);
}
$fraction = $grade['fraction'];
$feedback = '';
if (in_array($q['type'], ['multichoice'], true) && $answer !== null) {
    foreach ((array)$answer as $i) {
        $feedback .= (string)($q['answers'][(int)$i]['feedback'] ?? '');
    }
}
$message = match (true) {
    $q['type'] === 'essay' => 'Your teacher will read this answer.',
    $grade['state'] === 'unanswered' => 'Answer the question first.',
    $fraction >= 1 => 'Correct!' . (($grade['by'] ?? '') === 'fuzzy' ? ' (check your spelling)' : ''),
    $fraction > 0 => 'Partly right.',
    default => 'Not quite. Try again!',
};
// Matching: say which pairs are right instead of listing answers (stems may be pictures)
$pairs = null;
if ($q['type'] === 'match' && is_array($answer)) {
    $choices = match_choices($q);
    $pairs = [];
    foreach (match_stems($q) as $stem) {
        $pairs[$stem] = isset($answer[$stem]) && ($choices[$answer[$stem]] ?? null) === $q['pairs'][$stem]['answer'];
    }
}
json_response([
    'ok' => true,
    'pairs' => $pairs,
    'fraction' => $fraction,
    'message' => $message,
    'feedback' => $feedback !== '' ? format_text($feedback, $q['format'] ?? 'moodle', exam_media_url($exam['id'])) : '',
    'right_answer' => $grade['state'] !== 'unanswered' && ($fraction ?? 0) < 1 && !in_array($q['type'], ['essay', 'match'], true)
        ? render_answers_text($q) : '',
]);
