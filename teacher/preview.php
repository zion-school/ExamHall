<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 19-Sep-2026
 *
 * Preview of an exam for the teachers who can edit it: the start card, the exam player and the
 * result, as a new student gets them (lib/player.php). A draft is previewed from its saved
 * questions, a published exam from the paper students get. A preview is kept apart from the
 * students' tries (lib/attempts.php, "Teacher previews"): it never shows in results, pays no
 * rewards, gets no AI checking and never locks the questions.
 *   ?id=<exam>           start card; POST starts a new preview
 *   ?id=<exam>&play=1    the player
 *   ?id=<exam>&n=<n>     the result of a finished preview
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exam = load_exam((string)($_GET['id'] ?? ''));
if (!$exam || !exam_can_manage($user, $exam)) {
    http_response_code(404);
    exit('Exam not found');
}
$self = 'teacher/preview.php?id=' . rawurlencode($exam['id']);
$editor = 'teacher/exam_edit.php?id=' . rawurlencode($exam['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!start_preview($exam, $user)) {
        flash('err', 'There are no questions to preview yet. Add some, or check the random picks.');
        redirect($self);
    }
    redirect($self . '&play=1');
}

// ---------- Player ----------
if (!empty($_GET['play'])) {
    // Opening the player makes this page the active one; an older tab is told to stop
    $attempt = resume_preview($exam, $user['username']);
    if (!$attempt) {
        $latest = latest_preview($exam, $user['username']);
        redirect($latest ? $self . '&n=' . $latest['n'] : $self);
    }
    render_player($exam, load_preview_paper($exam['id'], $user['username']), $attempt, $user, [
        'save' => url('api/attempt_save.php') . '?preview=1',
        'report' => '',
        'check' => url('api/attempt_check.php') . '?preview=1',
        'result' => url($self . '&n=' . $attempt['n']),
        'resume' => url($self . '&play=1'),
        'home' => url($self),
    ], true);
    exit;
}

$fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 2), '0'), '.');
$startForm = fn(string $label, string $class) => '<form method="post" class="inline-form">' . csrf_field()
    . '<button class="btn ' . $class . '">' . icon('play') . h($label) . '</button></form>';

// ---------- Result ----------
if (isset($_GET['n'])) {
    $attempt = load_attempt($exam['id'], $user['username'], (int)$_GET['n'], true);
    if (!$attempt || $attempt['status'] === 'in_progress') {
        redirect($attempt ? $self . '&play=1' : $self);
    }
    $paper = load_preview_paper($exam['id'], $user['username']);
    $pending = $attempt['status'] === 'submitted';
    page_start('Preview result', $user, ['active' => 'exams', 'css' => ['assets/css/player.css', 'assets/css/teacher.css']]);
    ?>
<div class="start-wrap">
  <div class="card start-card result-card">
    <a class="back" href="<?= h(url($editor)) ?>"><?= icon('chevron-left', 14) ?><?= h($exam['title'] ?: 'Untitled exam') ?></a>
    <div class="row row-tight"><span class="badge badge-warn"><?= icon('eye', 14) ?>Preview</span><span class="small muted">Not saved with the students' tries</span></div>
    <div class="result-hero">
      <?= render_result_score($exam, $attempt, 'Written answers are marked by the teacher.') ?>
      <?php if (exam_is_reward($exam)):
          try {
              // As a first try; "class" is the exam's first class
              $amount = reward_amount($exam, ['attempt_no' => 1] + reward_variables($paper, $attempt, [], normalize_class((string)($exam['classes'][0] ?? ''))));
              $text = 'A student with this score would get ' . cfg('CURRENCY_SYMBOL', '₹') . $fmt($amount) . ($pending ? ' (on the marks so far)' : '');
          } catch (ExprError $e) {
              $text = 'The reward formula has a problem: ' . $e->getMessage();
          } ?>
        <p class="result-band with-icon"><?= icon('coin', 22) ?><?= h($text) ?></p>
        <p class="small muted">Nothing is paid for a preview. The most per day and earlier tries are not counted.</p>
      <?php endif; ?>
    </div>

    <div class="alert alert-info"><?= match ($exam['review']) {
        'score' => 'Students see only their score.',
        'immediate' => 'Students see their score and the correct answers straight away.',
        default => 'Students see their score now, and the correct answers after the exam closes.',
    } ?> You see everything here.<?= $exam['grading']['llm'] !== 'off' ? ' AI checking is not run in a preview, so short answers keep their automatic marks.' : '' ?></div>

    <h2>Questions</h2>
    <?= render_result_questions($exam, $paper, $attempt, true, true) ?>
    <div class="row mt-16">
      <?= $startForm('Preview again', 'btn-primary') ?>
      <a class="btn" href="<?= h(url($editor)) ?>"><?= icon('pencil') ?>Back to the exam</a>
    </div>
  </div>
</div>
<?php
    page_end();
    exit;
}

// ---------- Start card ----------
$latest = latest_preview($exam, $user['username']);
$running = $latest && $latest['status'] === 'in_progress';
$paper = $exam['status'] !== 'draft' ? load_paper($exam['id']) : null;
[$questions, $marks] = paper_size(($paper ?? $exam)['sections']);
if ($paper) {
    $marks = (float)$paper['max_marks'];
}
$newStudent = ['state' => 'preview', 'attempts' => [], 'open' => exam_time($exam['open_at']), 'close' => exam_time($exam['close_at'])];
$notes = [$exam['status'] === 'draft'
    ? 'This draft as it is saved. Students cannot see it until you publish it.'
    : 'The paper students get' . (!empty($exam['hidden']) ? ' (hidden from them now)' : '') . '. Changes not saved or published yet are not in it.'];
$notes[] = 'Your try is kept apart: it is not in the results, pays no rewards and does not lock the questions.';
if ((int)$exam['time_limit_min'] > 0) {
    $notes[] = 'The time limit counts down as it does for students. The closing time does not end a preview.';
}
if ($exam['access_code'] !== '') {
    $notes[] = 'Students type the exam code <strong>' . h($exam['access_code']) . '</strong> to start; a preview does not ask for it.';
}
if (exam_is_reward($exam)) {
    $notes[] = 'Students need an unlock from the Monitor to start this reward exam; a preview does not.';
}
$problems = $exam['status'] === 'draft' ? exam_publish_problems($exam, $user) : [];

page_start('Preview: ' . ($exam['title'] ?: 'Untitled exam'), $user, ['active' => 'exams', 'css' => ['assets/css/player.css', 'assets/css/teacher.css']]);
?>
<div class="start-wrap">
  <div class="card start-card">
    <a class="back" href="<?= h(url($editor)) ?>"><?= icon('chevron-left', 14) ?>Back to the exam</a>
    <div class="title-row"><h1><?= h($exam['title'] ?: 'Untitled exam') ?></h1><span class="badge badge-warn"><?= icon('eye', 14) ?>Preview</span></div>
    <p class="muted"><?= h($exam['subject']) ?></p>

    <div class="alert alert-info"><ul class="m-0"><?php foreach ($notes as $note): ?><li><?= $note ?></li><?php endforeach; ?></ul></div>
    <?php if ($problems): ?>
      <div class="alert alert-warn"><strong>Before publishing:</strong><ul class="m-0"><?php foreach ($problems as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?= exam_facts_html($exam, $questions, $marks, $newStudent, ($paper ?? $exam)['sections']) ?>

    <?php if (trim($exam['instructions']) !== ''): ?>
      <div class="instructions"><?= nl2br(h($exam['instructions'])) ?></div>
    <?php endif; ?>

    <?php if ($running): ?>
      <a class="btn btn-primary btn-lg btn-block" href="<?= h(url($self . '&play=1')) ?>">Continue the preview<?= icon('arrow-right', 20) ?></a>
      <div class="row mt-8"><?= $startForm('Start again', 'btn-ghost') ?><span class="small muted">Answers so far are thrown away.</span></div>
    <?php elseif ($questions > 0): ?>
      <form method="post"><?= csrf_field() ?><button class="btn btn-primary btn-lg btn-block">Start preview<?= icon('arrow-right', 20) ?></button></form>
      <?php if ($latest): ?><p class="small mt-8"><a href="<?= h(url($self . '&n=' . $latest['n'])) ?>">See the result of your last preview</a></p><?php endif; ?>
    <?php else: ?>
      <button class="btn btn-primary btn-lg btn-block" disabled title="Add some questions to the exam first">Start preview<?= icon('arrow-right', 20) ?></button>
    <?php endif; ?>
  </div>
</div>
<?php page_end();
