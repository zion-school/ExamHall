<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * A student's result for one try (?n=), as on the web's student/result.php:
 * the score (render_result_score()), coins earned, and each question's
 * marks with an icon (right, some, wrong, being marked); when the exam
 * lets students see answers, a question (?s=) shows the answer given and
 * the right one.
 */
require_once __DIR__ . '/lib/wap.php';

$user = wap_student();
[$exam, $paper] = wap_exam($user);
$id = $exam['id'];
$n = (int)($_GET['n'] ?? 0);
$attempt = load_attempt($id, $user['username'], $n);
if (!$attempt) {
    wap_redirect(wap_url('exam.php', ['id' => $id]));
}
if ($attempt['status'] === 'in_progress') {
    wap_redirect(wap_url('q.php', ['id' => $id]));
}
$show_answers = exam_review_allowed($exam, 'answers', $user['username']);
$questions = array_filter(try_questions($paper, $attempt), fn($x) => $x[1]['type'] !== 'description');
$numbers = question_numbers($questions);
$self = wap_url('result.php', ['id' => $id, 'n' => $n]);
$s = (int)($_GET['s'] ?? 0);

/** "1/2 marks", "Being marked", "Extra, not counted" */
function mark_text(array $attempt, array $slot): string
{
    $g = $attempt['grades'][$slot['slot']] ?? ['state' => 'unanswered', 'mark' => 0];
    if (($g['counted'] ?? true) === false) {
        return 'extra, not counted';
    }
    return $g['state'] === 'needs_review' ? 'being marked' : fmt_num($g['mark']) . '/' . fmt_num($slot['mark']);
}

/** The marks as an icon: right, some marks, wrong, or being marked */
function mark_icon(array $attempt, array $slot): string
{
    $g = $attempt['grades'][$slot['slot']] ?? ['state' => 'unanswered', 'mark' => 0];
    if (($g['counted'] ?? true) === false || (float)$slot['mark'] <= 0) {
        return '';
    }
    if ($g['state'] === 'needs_review') {
        return wap_icon('wait');
    }
    $mark = (float)$g['mark'];
    return wap_icon($mark >= (float)$slot['mark'] ? 'tick' : ($mark > 0 ? 'half' : 'cross'));
}

// One question: the answer given and the right one
if ($show_answers && isset($questions[$s]) && !attempt_answers_lost($attempt)) {
    [$slot, $q] = $questions[$s];
    wml_start($numbers[$s] . ' ' . mark_text($attempt, $slot), '', soft_keys([['Result', go_to($self)], ['Home', go_to('index.php')]]));
    echo wap_rich($q['text'], $q['format'] ?? 'moodle', $id) . "<br/>\n";
    echo '<b>Your answer:</b> ' . e(describe_response($q, $attempt['answers'][$s] ?? null)) . "<br/>\n";
    if (($right = render_answers_text($q)) !== '') {
        echo '<b>Right answer:</b> ' . e($right) . "<br/>\n";
    }
    wml_end();
}

wml_start('Result', '', soft_keys([['Home', go_to('index.php')], ['My results', go_to('results.php')]]));
echo '<b>' . e($exam['title']) . "</b><br/>\n";
$lines = html_lines(render_result_score($exam, $attempt));
if (exam_is_reward($exam)) {
    $r = student_reward_summary($id, $user['username']);
    $lines[] = $r['paid'] > 0 ? icon_marker('coins') . 'You earned ' . wap_money($r['paid']) . ' in Zion Bank!' : '';
    $lines[] = $r['waiting'] > 0 ? wap_money($r['waiting']) . ' more on its way' : '';
}
foreach (array_filter($lines, 'strlen') as $line) {
    echo wml_line($line) . "<br/>\n";
}

if (attempt_answers_lost($attempt)) {
    echo "The answers of this try were not kept in Moodle; only the marks came across.<br/>\n";
} elseif ($exam['review'] !== 'score') {
    if (!$show_answers) {
        echo "Correct answers will be shown after the exam closes.<br/>\n";
    }
    [$page, $pages] = page_number(count($questions));
    foreach (page_slice($questions, $page, true) as $slot_no => [$slot, $q]) {
        $row = $numbers[$slot_no] . ' ' . mark_text($attempt, $slot);
        $icon = mark_icon($attempt, $slot);
        echo ($show_answers ? link_to($row, wap_url('result.php', ['id' => $id, 'n' => $n, 's' => $slot_no]), null, $icon) : $icon . e($row) . '<br/>') . "\n";
    }
    echo pager('result.php', ['id' => $id, 'n' => $n], $page, $pages);
}
wml_end();
