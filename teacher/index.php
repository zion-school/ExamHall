<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Staff dashboard: what needs attention (exams running, marking, rewards, question reports).
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'close_report') {
        close_question_report((string)($_POST['id'] ?? ''), $user['username']);
        flash('ok', 'Report marked as done.');
    }
    redirect('teacher/index.php');
}

$exams = array_values(array_filter(all_exams(), fn($e) => exam_can_manage($user, $e) && $e['status'] !== 'draft'));
$running = [];
$grading = ['pending_llm' => 0, 'review' => 0, 'essays' => 0];
foreach ($exams as $e) {
    $writing = 0;
    foreach (all_attempts($e['id']) as $list) {
        $writing += count(array_filter($list, 'attempt_is_active'));   // not tries left unsubmitted long ago
    }
    if ($writing) {
        $running[] = [$e, $writing];
    }
}
foreach (grading_workload(array_column($exams, 'id')) as $w) {
    foreach ($w as $k => $v) {
        $grading[$k] += $v;
    }
}
$rewardWaiting = 0;
$rewardProblems = 0;
foreach ($exams as $e) {
    if (!exam_is_reward($e)) {
        continue;
    }
    foreach (load_rewards($e['id']) as $r) {
        $rewardWaiting += $r['status'] === 'awaiting_approval' ? 1 : 0;
        $rewardProblems += in_array($r['status'], ['failed', 'unknown'], true) ? 1 : 0;
    }
}
$reports = open_question_reports($user);

page_start('Dashboard', $user, ['active' => 'dashboard', 'css' => ['assets/css/teacher.css']]);

$writing = array_sum(array_column($running, 1));
$toMark = $grading['review'] + $grading['essays'];
page_head('Welcome, ' . $user['name'], [
    'meta' => [h($user['role'] === 'admin' ? 'You are an admin and can manage every subject.'
        : ($user['subjects'] ? 'Your subjects: ' . implode(', ', $user['subjects']) : 'No subjects are assigned to you yet. Ask an admin to add them.'))],
    'actions' => '<a class="btn" href="' . h(url('teacher/import.php')) . '">' . icon('upload') . 'Import questions</a>'
        . '<a class="btn btn-primary" href="' . h(url('teacher/exams.php')) . '?new=1">' . icon('plus') . 'New exam</a>',
]);
?>

<?php if ($user['role'] === 'admin' && data_dir_exposed()): ?>
  <details class="notice notice-err">
    <summary><?= icon('stop', 16) ?><span class="notice-text"><strong>Exam data can be downloaded from the web.</strong> Answer keys in the data folder are public.</span><span class="notice-more">How to fix</span></summary>
    <div class="notice-body">This web server ignores <code>.htaccess</code>.
      Move <code>$DATA_DIR</code> outside the web root in <code>config.local.php</code>, or block the <code>data/</code> folder in the web server
      (for <code>php -S</code> start it with <code>router.php</code>).</div>
  </details>
<?php endif; ?>

<?php
/** One counter in the summary strip: [icon, number, label, link, colour when not zero]. */
$counters = [
    ['activity', $writing, 'students writing now', url('teacher/exams.php'), 'is-ok'],
    ['pencil', $toMark, 'answers to mark', url('teacher/grading.php'), 'is-warn'],
    ['clock', $grading['pending_llm'], 'waiting for AI check', url('teacher/grading.php'), ''],
    ['coin', $rewardWaiting, 'rewards to approve', url('teacher/rewards.php'), 'is-warn'],
    ['alert', $rewardProblems, 'payment problems', url('teacher/rewards.php'), 'is-err'],
    ['flag', count($reports), 'question reports', '#reports', 'is-warn'],
];
?>
<div class="card summary summary-6">
  <?php foreach ($counters as [$ic, $n, $label, $href, $tone]): ?>
    <a class="summary-cell <?= $n ? $tone : 'is-zero' ?>" href="<?= h($href) ?>"><span class="summary-icon"><?= icon($ic) ?></span><span><b><?= (int)$n ?></b><span><?= h($label) ?></span></span></a>
  <?php endforeach; ?>
</div>

<?php if (!$running && !$reports && !$toMark && !$rewardWaiting && !$rewardProblems): ?>
  <p class="muted small">Nothing needs you right now.</p>
<?php endif; ?>

<?php if ($running || $reports): ?>
<div class="grid grid-2 align-start">
  <?php if ($running): ?>
  <section class="card">
    <div class="card-head"><h2>Exams running now</h2><span class="muted small"><?= count($running) ?> exam<?= count($running) === 1 ? '' : 's' ?></span></div>
    <ul class="qlist">
      <?php foreach ($running as [$e, $n]): ?>
        <li class="qrow"><div class="qrow-head">
          <div class="grow"><strong class="ellipsis"><?= h($e['title']) ?></strong><span class="sub"><?= h($e['subject']) ?><?= $e['classes'] ? ' · ' . h(implode(', ', array_map(fn($c) => "Class $c", $e['classes']))) : '' ?></span></div>
          <span class="badge badge-ok"><?= $n ?> writing</span>
          <?php if ($e['access_code'] !== ''): ?><span class="chip">Code <?= h($e['access_code']) ?></span><?php endif; ?>
          <a class="btn btn-sm btn-primary" href="<?= h(url('teacher/monitor.php')) ?>?id=<?= h(rawurlencode($e['id'])) ?>"><?= icon('activity', 16) ?>Monitor</a>
        </div></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($reports): ?>
  <section class="card" id="reports">
    <div class="card-head"><h2>Problems reported by students</h2><span class="muted small"><?= count($reports) ?> open</span></div>
    <ul class="qlist">
      <?php foreach (array_slice($reports, 0, 20) as $r):
          // The report names the question of the exam's paper; the bank question it came from may have changed or gone
          $copy = load_paper($r['exam'])['questions'][$r['question']] ?? [];
          $bankQ = $copy ? bank_question_for($copy) : null;
          $label = $copy ? question_source_label($copy) : ''; ?>
        <li class="qrow">
          <div class="qrow-head">
            <?= preview_toggle(['exam' => $r['exam'], 'question' => $r['question']]) ?>
            <div class="grow"><span>“<?= h($r['text']) ?>”</span>
              <span class="sub"><?= h(display_name($r['username'])) ?> · <?= h(date('d M', strtotime($r['created']))) ?> · <?= h($r['exam_title']) ?><?= $label !== '' ? ' · ' . h($label) : '' ?></span></div>
            <?php if ($bankQ): ?><a class="btn btn-sm" href="<?= h(url('teacher/question.php')) ?>?id=<?= h($bankQ['id']) ?>"><?= icon('pencil', 16) ?>Open question</a><?php endif; ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="close_report"><input type="hidden" name="id" value="<?= h($r['id']) ?>">
              <button class="btn btn-sm btn-ghost"><?= icon('check', 16) ?>Done</button></form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php page_end(['js' => ['assets/js/teacher.js']]);
