<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Grading: answers the computer could not decide, grouped so one decision marks every
 * student who wrote the same thing; AI checking progress; essays waiting for marks.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exams = array_values(array_filter(all_exams(), fn($e) => exam_can_manage($user, $e) && $e['status'] !== 'draft'));
$exam = null;
if (isset($_GET['exam'])) {
    $exam = load_exam((string)$_GET['exam']);
    if (!$exam || !exam_can_manage($user, $exam)) {
        http_response_code(404);
        exit('Exam not found');
    }
}

if ($exam && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $fraction = match ($_POST['decision'] ?? '') {
        'right' => 1.0,
        'half' => 0.5,
        'wrong' => 0.0,
        default => max(0.0, min(1.0, (float)($_POST['percent'] ?? 0) / 100)),
    };
    $n = decide_review_group($exam, (string)($_POST['group'] ?? ''), $fraction, $user, !empty($_POST['add']) && $fraction > 0);
    fire_hook('grading.changed', $exam['id']);
    flash('ok', "Marked $n student(s)" . (!empty($_POST['add']) && $fraction > 0 ? ' and added the answer to the answer key.' : '.'));
    redirect('teacher/grading.php?exam=' . rawurlencode($exam['id']) . '#answers');
}

page_start('Grading', $user, ['active' => 'grading', 'css' => ['assets/css/teacher.css']]);

if (!$exam):
    $workload = grading_workload(array_column($exams, 'id'));
    ?>
<?php page_head('Grading', ['meta' => ['Answers that need a teacher, and AI checking.']]); ?>
<div class="card flush">
  <?php $any = false; ?>
  <table class="table">
    <thead><tr><th>Exam</th><th class="num">AI still checking</th><th class="num">Answers to confirm</th><th class="num">Essays to mark</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($exams as $e):
        $w = $workload[$e['id']] ?? null;
        if (!$w || !array_sum($w)) {
            continue;
        }
        $any = true; ?>
      <tr>
        <td><strong><?= h($e['title']) ?></strong><span class="sub"><?= h($e['subject']) ?></span></td>
        <td class="num"><?= $w['pending_llm'] ?: '' ?></td>
        <td class="num"><?= $w['review'] ?: '' ?></td>
        <td class="num"><?= $w['essays'] ?: '' ?></td>
        <td><div class="row-actions"><a class="btn btn-sm btn-primary" href="?exam=<?= h(rawurlencode($e['id'])) ?>">Open</a></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$any): ?><div class="empty"><span class="empty-icon ok"><?= icon('check-circle', 40) ?></span>Nothing waiting. All submitted answers are marked.</div><?php endif; ?>
</div>
<?php
    page_end();
    exit;
endif;

$workload = grading_workload([$exam['id']])[$exam['id']] ?? ['pending_llm' => 0, 'review' => 0, 'essays' => 0];
$groups = review_groups($exam['id']);
$status = llm_status();
$essays = [];
each_submitted_grade([$exam['id']], function ($ex, $paper, $a, $slot, $q, $blank, $g) use (&$essays) {
    if ($q['type'] === 'essay' && ($g['state'] ?? '') === 'needs_review') {
        $essays[] = ['user' => $a['user'], 'n' => $a['n'], 'slot' => $slot, 'q' => $q, 'g' => $g];
    }
});
?>
<?php page_head($exam['title'], [
    'back' => ['Grading', 'teacher/grading.php'],
    'meta' => ['AI mode: <strong>' . h(['off' => 'off', 'suggest' => 'suggests, you confirm', 'auto' => 'marks automatically'][$exam['grading']['llm']] ?? $exam['grading']['llm']) . '</strong>',
        'Spelling slips ' . ($exam['grading']['fuzzy'] ? 'accepted' : 'not accepted'),
        'Ignoring ' . (implode(', ', array_keys(array_filter(['capitals' => $exam['grading']['ignore_case'], 'spacing' => $exam['grading']['ignore_spaces'], 'punctuation' => $exam['grading']['ignore_punctuation']]))) ?: 'nothing')],
    'actions' => '<a class="btn" href="' . h(url('teacher/results.php')) . '?id=' . h(rawurlencode($exam['id'])) . '">' . icon('chart') . 'Results</a>',
]); ?>

<div class="card" id="ai-card" data-exam="<?= h($exam['id']) ?>">
  <div class="row row-between">
    <div>
      <h2 class="m-0">AI checking</h2>
      <p class="small with-icon mt-4 mb-0 <?= $status['ok'] ? 'muted' : '' ?>"><span class="dot <?= $status['ok'] ? 'dot-ok' : 'dot-warn' ?>"></span><?= h($status['message']) ?></p>
    </div>
    <div class="row">
      <span id="ai-waiting" class="badge <?= $workload['pending_llm'] ? 'badge-warn' : 'badge-ok' ?>"><?= (int)$workload['pending_llm'] ?> waiting</span>
      <button class="btn btn-primary" id="ai-run" type="button" <?= $workload['pending_llm'] && $status['ok'] ? '' : 'disabled' ?>>Check now</button>
    </div>
  </div>
  <ul class="msgs" id="ai-log"></ul>
  <p class="small muted mt-8 mb-0">On the mini PC each batch of answers takes up to a minute. Keep this page open, or run <code>php bin/grade_worker.php</code> on the server.</p>
</div>

<div class="card" id="answers">
  <div class="card-head"><h2>Answers to confirm</h2><span class="badge"><?= count($groups) ?></span></div>
  <?php if (!$groups): ?><p class="muted">Nothing to confirm.</p><?php endif; ?>
  <?php $lastKey = null;
  foreach ($groups as $g):
      $q = $g['q'];
      if ($g['key'] . '|' . $g['blank'] !== $lastKey):
          $lastKey = $g['key'] . '|' . $g['blank'];
          $accepted = array_map(fn($a) => $a['text'] . ((float)$a['fraction'] < 1 ? ' (' . round($a['fraction'] * 100) . '%)' : ''), array_filter($q['answers'] ?? [], fn($a) => (float)$a['fraction'] > 0)); ?>
      <div class="answer-group">
        <div class="qv-text m-0"><?= format_text($g['parent']['text'], $g['parent']['format'] ?? 'moodle', exam_media_url($exam['id'])) ?></div>
        <?php if ($g['blank'] !== null): ?><div class="small muted">Blank <?= $g['blank'] + 1 ?></div><?php endif; ?>
        <div class="chips small mt-4">Accepted: <?php foreach ($accepted as $t): ?><span class="chip"><?= h($t) ?></span><?php endforeach; ?></div>
      </div>
  <?php endif; ?>
      <form method="post" class="answer-row">
        <?= csrf_field() ?>
        <input type="hidden" name="group" value="<?= h($g['group']) ?>">
        <div class="row">
          <span class="answer-text">“<?= h($g['answer']) ?>”</span>
          <span class="chip"><?= $g['count'] ?> student<?= $g['count'] === 1 ? '' : 's' ?></span>
          <?php if ($g['suggestion']):
              $v = $g['suggestion']['verdict'];
              $cls = in_array($v, ['correct', 'alternative', 'spelling'], true) ? 'badge-ok' : ($v === 'wrong' ? 'badge-err' : 'badge-warn'); ?>
            <span class="badge <?= $cls ?>" title="AI confidence <?= round(($g['suggestion']['confidence'] ?? 0) * 100) ?>%">AI: <?= h($v) ?> <?= round(($g['suggestion']['confidence'] ?? 0) * 100) ?>%</span>
          <?php endif; ?>
          <?php foreach (array_keys($g['notes']) as $note): ?><span class="small muted"><?= h($note) ?></span><?php endforeach; ?>
          <span class="spacer"></span>
          <?php $addDefault = in_array($g['suggestion']['verdict'] ?? '', ['correct', 'alternative'], true); /* not for misspellings */ ?>
          <label class="check small m-0" title="Future attempts with this answer get the marks automatically"><input type="checkbox" name="add" value="1" <?= $addDefault ? 'checked' : '' ?>> add to answer key</label>
          <button class="btn btn-sm btn-right" name="decision" value="right"><?= icon('check', 16) ?>Right</button>
          <button class="btn btn-sm" name="decision" value="half" title="Half marks">½</button>
          <button class="btn btn-sm btn-danger" name="decision" value="wrong"><?= icon('x', 16) ?>Wrong</button>
        </div>
        <details class="small mt-4"><summary>Students</summary><?= h(implode(', ', array_map(fn($r) => display_name($r[0]), $g['refs']))) ?></details>
      </form>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-head"><h2>Essays to mark</h2><span class="badge"><?= count($essays) ?></span></div>
  <?php if (!$essays): ?><p class="muted">No essays waiting.</p><?php endif; ?>
  <ul class="qlist">
    <?php foreach ($essays as $e): ?>
      <li class="qrow"><div class="qrow-head">
        <strong><?= h(display_name($e['user'])) ?></strong>
        <span class="qrow-name"><?= h(mb_substr(plain_text($e['q']['text']), 0, 80)) ?></span>
        <?php if (!empty($e['g']['suggestion']['mark']) || isset($e['g']['suggestion']['mark'])): ?><span class="badge badge-info">AI suggests <?= h($e['g']['suggestion']['mark']) ?> / <?= h($e['slot']['mark']) ?></span><?php endif; ?>
        <?php if (($e['g']['llm'] ?? '') === 'pending'): ?><span class="badge badge-warn">AI still reading</span><?php endif; ?>
        <a class="btn btn-sm btn-accent" href="<?= h(url('teacher/mark.php')) ?>?id=<?= h(rawurlencode($exam['id'])) ?>&amp;user=<?= h(rawurlencode($e['user'])) ?>&amp;n=<?= (int)$e['n'] ?>"><?= icon('pencil', 16) ?>Mark</a>
      </div></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php page_end(['js' => ['assets/js/grading.js']]);
