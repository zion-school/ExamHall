<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * View one attempt and mark it: essays need a mark; any automatic mark can be changed.
 * A try not submitted yet can be viewed with its marks so far (read-only).
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exam = load_exam((string)($_GET['id'] ?? ''));
$username = (string)($_GET['user'] ?? '');
$n = (int)($_GET['n'] ?? 0);
if (!$exam || !exam_can_manage($user, $exam) || !($attempt = load_attempt($exam['id'], $username, $n))) {
    http_response_code(404);
    exit('Attempt not found');
}
$self = 'teacher/mark.php?id=' . rawurlencode($exam['id']) . '&user=' . rawurlencode($username) . '&n=' . $n;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($attempt['status'] === 'in_progress') {
        flash('err', 'This try is not submitted yet, so it cannot be marked.');
        redirect($self);
    }
    $marks = array_filter((array)($_POST['mark'] ?? []), fn($m) => $m !== '');
    $changed = [];
    foreach ($marks as $slot => $m) {
        $current = $attempt['grades'][$slot]['mark'] ?? null;
        $state = $attempt['grades'][$slot]['state'] ?? '';
        // The field shows the mark rounded to 2 places (5/7 of a pair shows as 0.71): unchanged means unchanged
        if ($state === 'needs_review' || $current === null || abs(round((float)$current, 2) - (float)$m) > 0.0001 || trim($_POST['comment'][$slot] ?? '') !== ($attempt['grades'][$slot]['comment'] ?? '')) {
            $changed[$slot] = $m;
        }
    }
    set_manual_marks($exam, $username, $n, $changed, $user['username'], (array)($_POST['comment'] ?? []));
    fire_hook('attempt.graded', $exam['id'], $username);
    flash('ok', 'Marks saved.');
    // Go to the next try that needs marking
    foreach (all_attempts($exam['id']) as $u => $list) {
        foreach ($list as $a) {
            if ($a['status'] === 'submitted' && !($u === $username && $a['n'] === $n)) {
                redirect('teacher/mark.php?id=' . rawurlencode($exam['id']) . '&user=' . rawurlencode($u) . '&n=' . $a['n']);
            }
        }
    }
    redirect('teacher/results.php?id=' . rawurlencode($exam['id']));
}

$paper = load_paper($exam['id']);
$fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 2), '0'), '.');
$soFar = $attempt['status'] === 'in_progress';
if ($soFar) {
    $attempt = attempt_marks_so_far($exam, $paper, $attempt);
    [$answered, $questionCount] = attempt_progress($paper, $attempt);
}
$mediaUrl = exam_media_url($exam['id']);

page_start('Marking', $user, ['active' => 'exams', 'css' => ['assets/css/teacher.css']]);
?>
<?php
$t = attempt_times($attempt);
$badges = '';
$meta = ['Started ' . ($t['started'] ? h(date('d M Y, g:i A', $t['started'])) : '—')];
if ($soFar) {
    $last = attempt_last_activity($attempt);
    $badges .= attempt_is_paused($attempt) ? '<span class="badge badge-warn" title="The connection was lost; the time left stands still until it is back">Paused</span>'
        : (attempt_is_active($attempt) ? '<span class="badge badge-info">Writing now</span>' : '<span class="badge badge-warn">Not submitted</span>');
    $meta[] = $last ? 'Last saved ' . h(date('d M Y, g:i A', $last)) : '';
    $meta[] = (int)$answered . ' of ' . (int)$questionCount . ' answered';
    $meta[] = 'Marks so far <strong>' . $fmt($attempt['score']) . ' / ' . $fmt($attempt['max']) . '</strong>';
} else {
    $meta[] = 'Finished ' . h(date('d M, g:i A', (int)$t['finished'])) . ($attempt['auto_submitted'] ? ' (submitted automatically at time-up)' : '');
    $meta[] = $t['seconds'] !== null ? 'Took ' . h(duration_text($t['seconds'])) : '';
    $meta[] = 'Score <strong>' . $fmt($attempt['score']) . ' / ' . $fmt($attempt['max']) . '</strong>';
}
if ($attempt['late']) {
    $badges .= '<span class="badge badge-warn">Late save</span>';
}
$badges .= former_badge($username);
if (($attempt['events']['blur'] ?? 0) > 0) {
    $meta[] = 'Left the page ' . (int)$attempt['events']['blur'] . '×';
}
page_head(display_name($username, $attempt) . ' · try ' . $n, [
    'back' => ['Results of ' . $exam['title'], 'teacher/results.php?id=' . rawurlencode($exam['id'])],
    'badges' => $badges,
    'meta' => $meta,
    'actions' => '<a class="btn" target="_blank" href="' . h(url('teacher/result_sheets.php')) . '?id=' . h(rawurlencode($exam['id'])) . '&amp;users=' . h(rawurlencode($username)) . '&amp;try=' . $n . '">' . icon('printer') . 'Result sheet</a>',
]);
?>

<?php if ($soFar): ?>
  <div class="alert alert-info">This try has not been submitted. The marks below are worked out from the answers saved so far and are not final; the student can still continue. Unanswered questions get 0.</div>
<?php endif; ?>
<?php if (attempt_answers_lost($attempt)): ?>
  <div class="alert alert-info">The answers of this try were not kept in Moodle; only the marks came across, so there is nothing to mark.</div>
<?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <?php $number = 0;
  $section = -1;
  foreach ($attempt['slots'] as $slot):
      $q = $paper['questions'][$slot['key']];
      if ($q['type'] === 'description') {
          continue;
      }
      $number++;
      $g = $attempt['grades'][$slot['slot']] ?? null;
      $needs = ($g['state'] ?? '') === 'needs_review';
      $extra = ($g['counted'] ?? true) === false;   // "answer any five": written, but not one of the five that counted
      if ((int)($slot['section'] ?? 0) !== $section):
          $section = (int)($slot['section'] ?? 0);
          $sec = $paper['sections'][$section] ?? [];
          $choose = section_choose($sec);
          $offered = count(section_question_marks($sec));
          if ($choose > 0 && $choose < $offered): ?>
    <div class="alert alert-info"><?= h(trim((string)($sec['title'] ?? '')) ?: 'This section') ?>: students answer any <?= $choose ?> of these <?= $offered ?>.
      Mark every answer — the best <?= $choose ?> count towards the score, and an extra answer is left out on its own.</div>
      <?php endif;
      endif; ?>
    <div class="card <?= $needs ? 'needs-marking' : '' ?>">
      <div class="row row-between">
        <strong class="row row-tight">Q<?= $number ?> <span class="chip type-badge t-<?= h($q['type']) ?>"><?= h(question_type_label($q['type'])) ?></span><?php if ($extra): ?><span class="badge" title="The student answered more than they had to; their best answers counted">Extra · not counted</span><?php endif; ?></strong>
        <span class="row small">
          <?php if ($g && ($g['by'] ?? '') === 'teacher'): ?><span class="badge badge-info">Marked by <?= h(display_name($g['marked_by'] ?? '')) ?></span><?php endif; ?>
          <label class="row row-tight">Mark
            <input type="number" name="mark[<?= (int)$slot['slot'] ?>]" step="any" min="0" max="<?= h($slot['mark']) ?>" class="mark-field" <?= $attempt['status'] === 'in_progress' ? 'disabled' : '' ?>
              value="<?= $needs || !$g || $g['mark'] === null ? '' : h($fmt($g['mark'])) ?>" <?= $needs ? 'required' : '' ?>>
            / <?= $fmt($slot['mark']) ?></label>
        </span>
      </div>
      <div class="grid grid-2 mt-8">
        <div>
          <div class="field-label">Student's answer</div>
          <div class="your-answer given"><?= $q['type'] === 'match' ? match_marking_html($q, $attempt['answers'][$slot['slot']] ?? null, $mediaUrl) : answer_html($q, $attempt['answers'][$slot['slot']] ?? null) ?></div>
          <?php if (!empty($g['note']) || !empty($g['suggestion'])): ?>
            <div class="small mt-8">
              <?php if (!empty($g['note'])): ?><span class="muted"><?= h($g['note']) ?></span><?php endif; ?>
              <?php if (isset($g['suggestion']['mark'])): ?>
                <span class="badge badge-info">AI suggests <?= h($fmt($g['suggestion']['mark'])) ?></span> <?= h($g['suggestion']['reason'] ?? '') ?>
                <button type="button" class="btn btn-sm" onclick="this.form.elements['mark[<?= (int)$slot['slot'] ?>]'].value='<?= h($fmt($g['suggestion']['mark'])) ?>'">Use</button>
              <?php elseif (!empty($g['suggestion']['verdict'])): ?>
                <span class="badge badge-info">AI: <?= h($g['suggestion']['verdict']) ?> <?= round(($g['suggestion']['confidence'] ?? 0) * 100) ?>%</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <input type="text" name="comment[<?= (int)$slot['slot'] ?>]" value="<?= h($g['comment'] ?? '') ?>" placeholder="Comment for the student (optional)" class="mt-8" <?= $attempt['status'] === 'in_progress' ? 'disabled' : '' ?>>
        </div>
        <div>
          <div class="field-label">Question and answer key</div>
          <?= render_question_preview($q, $mediaUrl) ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($attempt['status'] !== 'in_progress' && !attempt_answers_lost($attempt)): ?>
  <div class="card sticky-actions">
    <div class="row"><button class="btn btn-primary"><?= icon('save') ?>Save marks and go to the next</button>
      <span class="muted small">Automatic marks you don't change stay as they are.</span></div>
  </div>
  <?php endif; ?>
</form>
<?php page_end();
