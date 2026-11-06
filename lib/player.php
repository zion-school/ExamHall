<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 19-Sep-2026
 *
 * The exam as a student sees it: the facts on the start card, the exam player page and the
 * result card. Used by the student pages and by a teacher's preview (teacher/preview.php).
 */

defined('EXAMS_APP') or exit;

/**
 * The facts on an exam's start card: questions and marks, time limit, schedule, rewards, tries.
 * $status: the student's (exam_student_status), or for a preview a new student's.
 * $sections (the paper's, or the exam's before it has one) add the "you can choose" line.
 */
function exam_facts_html(array $exam, int $questions, float $marks, array $status, array $sections = []): string
{
    $fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 2), '0'), '.');
    $fact = fn(string $icon, string $html) => '<li><span>' . icon($icon) . '</span>' . $html . '</li>';
    $open = $status['open'] ?? null;
    $close = $status['close'] ?? null;
    $required = $sections ? paper_required_count($sections) : $questions;
    $out = $fact('list', $questions . ' question' . ($questions === 1 ? '' : 's')
        . ($required < $questions ? ' &middot; you answer ' . $required : '') . ', ' . $fmt($marks) . ' marks');
    $choices = [];
    foreach ($sections as $section) {
        $choose = section_choose($section);
        $offered = count(section_question_marks($section));
        if ($choose > 0 && $choose < $offered) {
            $title = trim((string)($section['title'] ?? ''));
            $choices[] = 'any <strong>' . $choose . ' of ' . $offered . '</strong>' . ($title !== '' ? ' in &ldquo;' . h($title) . '&rdquo;' : '');
        }
    }
    if ($choices) {
        $out .= $fact('star', '<div><strong>You can choose!</strong> Answer ' . implode('; ', $choices)
            . '. Your best answers count, so an extra answer never costs you marks.</div>');
    }
    $out .= $fact('clock', (int)$exam['time_limit_min'] > 0 ? (int)$exam['time_limit_min'] . ' minutes once you start' : 'No time limit');
    if ($open || $close) {
        $countdown = $close && in_array($status['state'], ['open', 'in_progress', 'upcoming', 'locked'], true)
            ? ' <small class="muted" data-countdown="' . h(date('c', $close)) . '" data-server-time="' . h(date('c')) . '" data-prefix="· closes in "></small>' : '';
        $out .= $fact('calendar', '<div>' . h(schedule_text($open, $close)) . $countdown . '</div>');
    }
    if (exam_is_reward($exam)) {
        $out .= $fact('coin', 'You can earn coins in Zion Bank for a good score');
    } elseif ((int)$exam['attempts'] > 0) {
        $finished = count(array_filter($status['attempts'], fn($a) => $a['status'] !== 'in_progress'));
        $out .= $fact('repeat', $finished . ' of ' . (int)$exam['attempts'] . ' tries used');
    } elseif ($status['state'] !== 'hidden') {
        $out .= $fact('repeat', 'You can try again as many times as you like');
    }
    return '<ul class="facts">' . $out . '</ul>';
}

/**
 * The exam player page for a try in progress; assets/js/player.js does the rest.
 * $urls: save, check, report, result, resume, home. $preview: a teacher's preview
 * (a badge and an exit link, no problem reports, its own copy in the browser).
 */
function render_player(array $exam, array $paper, array $attempt, array $user, array $urls, bool $preview = false): void
{
    $boot = [
        'exam' => ['id' => $exam['id'], 'title' => $exam['title'], 'subject' => $exam['subject'], 'per_page' => (int)$exam['per_page'], 'practice' => exam_is_practice($exam)],
        'sections' => array_map(fn($s) => ['title' => (string)$s['title'], 'choose' => section_choose($s)], $paper['sections']),
        'attempt' => [
            'user' => $user['username'], 'n' => $attempt['n'], 'token' => $attempt['token'], 'epoch' => $attempt['epoch'],
            'seq' => $attempt['seq'], 'deadline' => $attempt['deadline'], 'server_time' => date('c'),
            'answers' => (object)($attempt['answers'] ?: []), 'flags' => $attempt['flags'],
        ],
        'questions' => attempt_client_questions($exam, $paper, $attempt),
        'urls' => $urls,
        'student' => $user['name'],
        'essay_limit' => ATTEMPT_ESSAY_LIMIT,
        'preview' => $preview,
    ];
    page_start($exam['title'] . ($preview ? ' (preview)' : ''), $user, ['bare' => true, 'css' => ['assets/css/player.css'], 'body_class' => 'player-body']);
    ?>
<div id="player" class="player" data-per-page="<?= (int)$exam['per_page'] ?>">
  <header class="p-top">
    <div class="p-title"><span class="p-subject"><?= h($exam['subject']) ?></span><strong><?= h($exam['title']) ?></strong></div>
    <?php if ($preview): ?><span class="badge badge-warn p-preview" title="Your answers are not saved with the students' tries"><?= icon('eye', 14) ?>Preview</span><?php endif; ?>
    <div class="p-section" id="p-section"></div>
    <div class="p-timer" id="p-timer" hidden><?= icon('clock') ?> <span>--:--</span></div>
    <div class="p-sync" id="p-sync" data-state="saved"><span class="dot"></span><span class="label">Saved</span></div>
    <?php if ($preview): ?><a class="btn btn-ghost p-exit" href="<?= h($urls['home']) ?>" title="Leave the preview (you can continue it later)"><?= icon('x') ?><span class="btn-label">Exit preview</span></a><?php endif; ?>
    <button class="btn btn-accent" id="p-finish" type="button">Finish</button>
  </header>
  <main class="p-main" id="p-main" tabindex="-1"></main>
  <footer class="p-bottom" id="p-bottom">
    <button class="btn btn-lg" id="p-prev" type="button"><?= icon('arrow-left', 20) ?>Back</button>
    <nav class="p-dots" id="p-dots" aria-label="Questions"></nav>
    <button class="btn btn-primary btn-lg" id="p-next" type="button">Next<?= icon('arrow-right', 20) ?></button>
  </footer>
  <div class="p-overlay" id="p-overlay" hidden><div class="p-overlay-card" id="p-overlay-card"></div></div>
</div>
<script type="application/json" id="p-boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
    page_end(['js' => ['assets/js/player.js']]);
}

/**
 * "Answer any five" as a line above the section's questions, for the result card ($li: a list
 * item) and the printed sheet. Empty for a section where every question counts.
 */
function choice_note_text(array $section): string
{
    $choose = section_choose($section);
    $offered = count(section_question_marks($section));
    if ($choose < 1 || $choose >= $offered) {
        return '';
    }
    return 'A choice here: any ' . $choose . ' of these ' . $offered . ' questions. The best ' . $choose . ' counted.';
}

function choice_note_html(array $section, bool $li = false): string
{
    $text = choice_note_text($section);
    if ($text === '') {
        return '';
    }
    return $li ? '<li class="choice-note">' . icon('star', 16) . h($text) . '</li>'
        : '<div class="choice-note">' . h($text) . '</div>';
}

/** The top of a result card: the score (or marks so far while some answers wait for the teacher), when it was written. */
function render_result_score(array $exam, array $attempt, string $pendingText = 'Your teacher is still marking some answers.'): string
{
    $fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 2), '0'), '.');
    $percent = $attempt['max'] > 0 ? 100 * $attempt['score'] / $attempt['max'] : 0;
    if ($attempt['status'] === 'submitted') {
        $out = '<div class="result-score">' . icon('pencil', 56) . '</div>'
            . '<p class="result-band">' . h($pendingText) . '</p>'
            . '<p class="muted">Marks so far: ' . $fmt($attempt['score']) . ' / ' . $fmt($attempt['max']) . '</p>';
    } else {
        $band = exam_band_text($exam, $percent);
        $out = '<div class="result-score">' . round($percent) . '<small>%</small></div>'
            . '<p class="muted">' . $fmt($attempt['score']) . ' out of ' . $fmt($attempt['max']) . ' marks</p>'
            . '<p class="result-band">' . h($band !== '' ? $band : ($percent >= 90 ? 'Excellent!' : ($percent >= 60 ? 'Good work!' : 'Keep practising!'))) . '</p>';
    }
    $t = attempt_times($attempt);
    $out .= '<p class="small muted">Started ' . ($t['started'] ? h(moment_text($t['started'])) : '—') . ($t['seconds'] !== null ? ' · took ' . h(duration_text($t['seconds'])) : '') . '</p>';
    if ($attempt['auto_submitted']) {
        $out .= '<p class="small muted">Submitted automatically when the time was up.</p>';
    }
    return $out;
}

/**
 * The questions of a result card with the marks of each; with $showAnswers the answer given and,
 * behind "Show", the question with its correct answer. $teacher (a preview): the correct answers
 * are shown already, and how an answer was marked (e.g. a spelling slip accepted).
 */
function render_result_questions(array $exam, array $paper, array $attempt, bool $showAnswers, bool $teacher = false): string
{
    $fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 2), '0'), '.');
    $out = '';
    $number = 0;
    $section = -1;
    foreach ($attempt['slots'] as $slot) {
        $q = $paper['questions'][$slot['key']];
        if ($q['type'] === 'description') {
            continue;
        }
        if ((int)($slot['section'] ?? 0) !== $section) {
            $section = (int)($slot['section'] ?? 0);
            $out .= choice_note_html($paper['sections'][$section] ?? [], true);
        }
        $number++;
        $g = $attempt['grades'][$slot['slot']] ?? ['fraction' => 0, 'state' => 'unanswered', 'mark' => 0];
        $extra = ($g['counted'] ?? true) === false;
        $markClass = $g['state'] === 'needs_review' ? '' : ($g['mark'] >= $slot['mark'] ? 'full' : ($g['mark'] > 0 ? 'part' : 'zero'));
        $out .= '<li class="qrow' . ($extra ? ' q-extra' : '') . '"><div class="qrow-head">'
            . '<strong>Q' . $number . '</strong>'
            . '<span class="qrow-name">' . h(mb_substr(html_to_text($q['text'], '(picture)'), 0, 90)) . '</span>'
            . ($extra ? '<span class="mark extra" title="You answered more than you had to; your best answers counted">Extra &middot; not counted</span>'
                : '<span class="mark ' . $markClass . '">' . ($g['state'] === 'needs_review' ? 'Being marked' : $fmt($g['mark']) . ' / ' . $fmt($slot['mark'])) . '</span>')
            . '</div>';
        if ($showAnswers) {
            $out .= '<div class="qrow-body">'
                . '<div class="your-answer"><span class="small muted">Your answer:</span> ' . answer_html($q, $attempt['answers'][$slot['slot']] ?? null) . '</div>'
                . ($teacher && !empty($g['note']) ? '<p class="small muted m-0">' . h($g['note']) . '</p>' : '')
                . '<details' . ($teacher ? ' open' : '') . '><summary class="small">Show the question and correct answer</summary>'
                . render_question_preview($q, exam_media_url($exam['id']))
                . '</details></div>';
        }
        $out .= '</li>';
    }
    return '<ul class="qlist result-list">' . $out . '</ul>';
}
