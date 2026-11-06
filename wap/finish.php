<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * The overview of a try, which is also where it is finished: how many are
 * answered (attempt_progress()), a table of the questions (a filled box
 * answered, an empty box not, a flag flagged: "1*", "2-", "3?" without
 * images), each a link to it, and Submit, which asks first
 * (exams.wmls). Submitting
 * marks the try and runs the hooks (Zion Bank rewards) as the web does.
 * The card's timer submits at time-up.
 */
require_once __DIR__ . '/lib/wap.php';

$user = wap_student();
[$exam, $paper, $status] = wap_exam($user, false);
$attempt = wap_playing($exam, $status);
$id = $exam['id'];

if (wap_posted()) {
    wap_csrf_check();
    $time_up = !empty($_POST['t']);
    $res = wap_save($attempt, (array)$attempt['answers'], $attempt['flags'], true, $time_up);
    if (empty($res['ok'])) {
        wap_refused($res, $exam, $attempt);
    }
    if (!empty($res['early'])) {
        wap_redirect(wap_url('finish.php', ['id' => $id]));   // the phone's timer was early
    }
    wap_redirect_then_hooks(wap_url('result.php', ['id' => $id, 'n' => $attempt['n']]), $attempt);
}

$questions = try_questions($paper, $attempt);
$numbers = array_filter(question_numbers($questions));
[$answered, $count] = attempt_progress($paper, $attempt);
$left = attempt_seconds_left($attempt);
$self = wap_url('finish.php', ['id' => $id]);
$ask = "Submit your answers? You can't change them after."
    . ($answered < $count ? ' ' . ($count - $answered) . ' not answered.' : '');
// 4 a row, 4 rows a page: an exam's long id in each link and an icon in
// each cell, so a deck stays under ~3.5 KB (and 32 focusable items with the pager)
$per_page = 16;
$pages = max(1, (int)ceil(count($numbers) / $per_page));
$page = max(1, min($pages, (int)($_GET['pg'] ?? 1)));

wml_start('Overview' . ($left !== null ? ' ' . left_text($left) : ''), '',
    soft_keys([['Submit', script_go("ask('Submit','p')", ['m' => $ask])]]), [], $left !== null ? [$left + 1, '#t'] : null);
echo e("Answered {$answered} Not " . ($count - $answered) . ' Flag ' . count($attempt['flags'])) . "<br/>\n";
echo '<table columns="4">';
foreach (array_chunk(array_slice($numbers, ($page - 1) * $per_page, $per_page, true), 4, true) as $row) {
    echo '<tr>';
    foreach ($row as $s => $number) {
        $mark = in_array($s, $attempt['flags'], true) ? wap_icon('flag', '?')
            : (grade_is_blank($attempt['answers'][$s] ?? null) ? wap_icon('box0', '-') : wap_icon('box1', '*'));
        echo '<td><a href="' . e(wap_url('q.php', ['id' => $id, 's' => $s])) . '">' . substr($number, 1) . $mark . '</a></td>';
    }
    echo str_repeat('<td/>', 4 - count($row)) . "</tr>\n";
}
echo "</table>\n" . wap_icon('box1', '*') . 'done ' . wap_icon('box0', '-') . 'not ' . wap_icon('flag', '?') . "flagged<br/>\n";
echo pager('finish.php', ['id' => $id], $page, $pages);
wml_end(post_card('p', $self, []) . ($left !== null ? post_card('t', $self, ['t' => 1]) : ''));
