<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * One exam: the web start card's facts (exam_facts_html()) and
 * instructions, the exam code when it has one, and the student's tries. The
 * soft key starts it (Start, Try again) or carries on with the try in
 * progress (Continue), which takes the try over from a computer or another
 * phone that had it.
 */
require_once __DIR__ . '/lib/wap.php';

$user = wap_student();
[$exam, $paper, $status] = wap_exam($user, !wap_posted());
$self = wap_url('exam.php', ['id' => $exam['id']]);

$finished = array_values(array_filter($status['attempts'], fn($a) => $a['status'] !== 'in_progress'));

if (wap_posted()) {
    wap_csrf_check();
    // "k": the tries finished when the page was made; a Start from before the last try finished
    // is the browser sending it again on going Back, not a new try
    if ($status['state'] !== 'in_progress' && (int)($_POST['k'] ?? -1) !== count($finished)) {
        wap_redirect($self);
    }
    $attempt = student_start_exam($exam, $paper, $status, $user, (string)($_POST['code'] ?? ''));
    if (is_string($attempt)) {
        wap_flash($attempt);
        wap_redirect($self);
    }
    $attempt = wap_take_over($attempt);
    // Carry on at the first question not answered yet
    $slot = $attempt['slots'][0]['slot'];
    foreach (try_questions($paper, $attempt) as $s => [, $q]) {
        if (grade_is_blank($attempt['answers'][$s] ?? null) && $q['type'] !== 'description') {
            $slot = $s;
            break;
        }
    }
    wap_redirect(wap_url('q.php', ['id' => $exam['id'], 's' => $slot]));
}

$code = $status['state'] === 'open' && $exam['access_code'] !== '';
if ($status['can_start']) {
    $label = $status['state'] === 'in_progress' ? 'Continue' : ($finished ? 'Try again' : 'Start');
    // With an exam code, exams.wmls asks for it before the card "s" posts it
    $key = [$label, $code ? script_go("code('s')") : go_post($self, ['k' => count($finished)])];
} else {
    $key = ['Home', go_to('index.php')];
}

wml_start($exam['title'], '', soft_keys([$key]), $code ? ['code' => ''] : []);
echo '<b>' . e($exam['title']) . "</b><br/>\n";
$facts = exam_facts_html($exam, paper_size($paper['sections'])[0], (float)$paper['max_marks'], $status, $paper['sections']);
$facts = icon_markers($facts, ['clock' => 'clock', 'calendar' => 'cal', 'coin' => 'coins']);
$instructions = nl2br(h(mb_strimwidth(trim($exam['instructions']), 0, 400, '...', 'UTF-8')));
foreach (array_merge([$exam['subject']], html_lines($facts), html_lines($instructions)) as $line) {
    $line = preg_replace('/^- /', '', $line);     // the facts are a list on the web
    echo $line !== '' ? wml_line($line) . "<br/>\n" : '';
}
if ($status['state'] === 'in_progress') {
    $left = attempt_seconds_left($status['in_progress']);
    echo '<b>' . e('Started' . ($left !== null ? ', ' . left_text($left) . ' left' : '')) . "</b><br/>\n";
} elseif ($code) {
    echo wap_icon('key') . "Exam code:<input name=\"code\" maxlength=\"20\"/><br/>\n";
} elseif (!$status['can_start']) {
    echo '<b>' . e($status['reason']) . "</b><br/>\n";
}
foreach ($finished as $a) {
    $score = $a['status'] === 'submitted' ? 'being marked' : ($a['max'] > 0 ? round(100 * $a['score'] / $a['max']) : 0) . '%';
    echo link_to("Try {$a['n']}: {$score}", wap_url('result.php', ['id' => $exam['id'], 'n' => $a['n']])) . "\n";
}
wml_end($code && $status['can_start'] ? post_card('s', $self, ['k' => count($finished), 'code' => field('code')]) : '');
