<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Live monitor during an exam: who is writing, online/offline, progress, time left,
 * tab switches; unlock reward exams, give extra time, submit an attempt.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exam = load_exam((string)($_GET['id'] ?? ''));
if (!$exam || !exam_can_manage($user, $exam)) {
    http_response_code(404);
    exit('Exam not found');
}
$classes = array_values(array_unique(array_map('normalize_class', $exam['classes'])));

page_start('Monitor', $user, ['active' => 'exams', 'css' => ['assets/css/teacher.css'], 'wide' => true]);
?>
<?php page_head('Monitor', [
    'back' => [$exam['title'], 'teacher/exam_edit.php?id=' . rawurlencode($exam['id'])],
    'meta' => [h($exam['subject']), h(implode(', ', array_map(fn($c) => "Class $c", $classes))),
        $exam['access_code'] !== '' ? 'Exam code <strong class="exam-code">' . h($exam['access_code']) . '</strong>' : ''],
    'actions' => '<span class="small muted" id="mon-updated"></span><a class="btn" href="' . h(url('teacher/results.php')) . '?id=' . h(rawurlencode($exam['id'])) . '">' . icon('chart') . 'Results</a>',
]); ?>

<div id="monitor" data-exam="<?= h($exam['id']) ?>" data-reward="<?= exam_is_reward($exam) ? '1' : '' ?>">
  <?php if (exam_is_reward($exam)): ?>
  <div class="card">
    <h2 class="with-icon"><?= icon('lock') ?>Unlock this reward exam</h2>
    <p class="small muted">Students can start only while an unlock is active. Each unlock allows one try per student.</p>
    <div class="row">
      <select id="unlock-class" class="w-auto">
        <?php foreach ($classes as $c): ?><option value="<?= h($c) ?>">Class <?= h($c) ?></option><?php endforeach; ?>
      </select>
      <label class="row row-tight small">for <input type="number" id="unlock-minutes" value="40" min="1" max="600" class="w-num"> minutes</label>
      <button class="btn btn-primary" type="button" id="unlock-class-btn">Unlock for the class</button>
    </div>
    <ul class="msgs mt-8" id="unlock-list"></ul>
  </div>
  <?php endif; ?>

  <div class="card summary" id="mon-stats"></div>

  <div class="card flush">
    <div class="table-wrap">
      <table class="table" id="mon-table">
        <thead><tr><th>Student</th><th>Status</th><th>Progress</th><th>Time left</th><th>Notes</th><th class="num">Score</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>
<?php page_end(['js' => ['assets/js/monitor.js']]);
