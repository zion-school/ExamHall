<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * A student's result for one attempt. How much is shown follows the exam's review setting.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_login();
$exam = load_exam((string)($_GET['id'] ?? ''));
$n = (int)($_GET['n'] ?? 0);
$attempt = $exam ? load_attempt($exam['id'], $user['username'], $n) : null;
if (!$attempt) {
    http_response_code(404);
    exit('Result not found');
}
if ($attempt['status'] === 'in_progress') {
    redirect('student/exam.php?id=' . rawurlencode($exam['id']) . '&play=1');
}
$paper = load_paper($exam['id']);
$showAnswers = exam_review_allowed($exam, 'answers', $user['username']);

page_start('Result', $user, ['active' => 'home', 'css' => ['assets/css/player.css', 'assets/css/teacher.css']]);
?>
<div class="start-wrap">
  <div class="card start-card result-card">
    <?php if (!empty($exam['hidden'])): /* a hidden exam's own page is closed to students; its results stay in My results */ ?>
    <a class="back" href="<?= h(url('student/results.php')) ?>"><?= icon('chevron-left', 14) ?>My results</a>
    <?php else: ?>
    <a class="back" href="<?= h(url('student/exam.php')) ?>?id=<?= h(rawurlencode($exam['id'])) ?>"><?= icon('chevron-left', 14) ?><?= h($exam['title']) ?></a>
    <?php endif; ?>
    <div class="result-hero">
      <?= render_result_score($exam, $attempt) ?>
      <?php if (exam_is_reward($exam)):
          $r = student_reward_summary($exam['id'], $user['username']);
          $cur = cfg('CURRENCY_SYMBOL', '₹');
          if ($r['paid'] > 0 || $r['waiting'] > 0): ?>
        <p class="result-band with-icon"><?= icon('coin', 22) ?><?= $r['paid'] > 0 ? 'You earned ' . h($cur . rtrim(rtrim(number_format($r['paid'], 2), '0'), '.')) . ' in Zion Bank!' : '' ?>
          <?= $r['waiting'] > 0 ? '<span class="small muted">' . h($cur . rtrim(rtrim(number_format($r['waiting'], 2), '0'), '.')) . ' more on its way</span>' : '' ?></p>
      <?php endif; endif; ?>
    </div>

    <?php if (attempt_answers_lost($attempt)): ?>
      <div class="alert alert-info">The answers of this try were not kept in Moodle; only the marks came across.</div>
    <?php elseif ($exam['review'] !== 'score'): ?>
      <h2>Questions</h2>
      <?php if (!$showAnswers): ?>
        <div class="alert alert-info">Correct answers will be shown after the exam closes.</div>
      <?php endif; ?>
      <?= render_result_questions($exam, $paper, $attempt, $showAnswers) ?>
    <?php endif; ?>
    <div class="row mt-16"><a class="btn btn-primary" href="<?= h(url('student/index.php')) ?>"><?= icon('arrow-left') ?>Back to my exams</a></div>
  </div>
</div>
<?php page_end();
