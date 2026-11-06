<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Exams list for staff (schedule, hide/show for students, preview), and creating a new exam.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$subjects = $user['role'] === 'admin' ? subject_names() : $user['subjects'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['hide', 'show'], true)) {
    csrf_check();
    $exam = load_exam((string)($_POST['id'] ?? ''));
    if ($exam && exam_can_manage($user, $exam)) {
        $exam['hidden'] = $_POST['action'] === 'hide';
        save_exam($exam, $user['username']);
        flash('ok', '"' . $exam['title'] . '" ' . ($exam['hidden'] ? 'is hidden from students. Their results stay in My results.' : 'is visible to students again.'));
    }
    redirect('teacher/exams.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim((string)($_POST['title'] ?? ''));
    $subject = (string)($_POST['subject'] ?? '');
    $classes = array_values(array_intersect($_POST['classes'] ?? [], all_classes()));
    if ($title === '' || !in_array($subject, $subjects, true)) {
        flash('err', 'Give the exam a title and choose one of your subjects.');
        redirect('teacher/exams.php');
    }
    $exam = create_exam($user, $title, $subject, $classes);
    redirect('teacher/exam_edit.php?id=' . rawurlencode($exam['id']));
}

$exams = array_values(array_filter(all_exams(), fn($e) => exam_can_manage($user, $e)));
$classes = all_classes();

page_start('Exams', $user, ['active' => 'exams', 'css' => ['assets/css/teacher.css']]);
$showForm = !$exams || !empty($_GET['new']);
page_head('Exams', [
    'meta' => [count($exams) . ' exam(s)' . ($user['role'] === 'teacher' ? ' in your subjects' : '')],
    'actions' => '<button class="btn btn-primary" type="button" data-toggle="new-exam" aria-expanded="' . ($showForm ? 'true' : 'false') . '">' . icon('plus') . 'New exam</button>',
]);
?>

<div class="card" id="new-exam" <?= $showForm ? '' : 'hidden' ?>>
  <h2>New exam</h2>
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid grid-2">
      <label class="field"><span>Title</span><input type="text" name="title" required placeholder="e.g. Maths Unit Test 2"></label>
      <label class="field"><span>Subject</span>
        <select name="subject" required>
          <?php foreach ($subjects as $s): ?><option><?= h($s) ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="field-label">For classes</div>
    <div class="checks">
      <?php foreach ($classes as $c): ?><label class="check"><input type="checkbox" name="classes[]" value="<?= h($c) ?>"> Class <?= h($c) ?></label><?php endforeach; ?>
      <?php if (!$classes): ?><span class="muted small">No classes found in users.csv.</span><?php endif; ?>
    </div>
    <button class="btn btn-primary"><?= icon('plus') ?>Create and add questions</button>
  </form>
</div>

<?php if (!$exams): ?>
  <div class="card"><p class="muted m-0">No exams yet.</p></div>
<?php else: ?>
<div class="card flush">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Exam</th><th>For</th><th>Status</th><th>When</th><th class="num">Started</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($exams as $e):
        $attempts = all_attempts($e['id']);
        $started = count($attempts);
        $running = count(array_filter($attempts, fn($list) => (bool)array_filter($list, 'attempt_is_active')));
        $unsubmitted = count(array_filter($attempts, fn($list) => (bool)array_filter($list, fn($a) => $a['status'] === 'in_progress' && !attempt_is_active($a))));
        $open = exam_time($e['open_at']);
        $close = exam_time($e['close_at']);
        $hidden = !empty($e['hidden']) && $e['status'] !== 'draft';
        $idParam = h(rawurlencode($e['id']));
        ?>
      <tr class="<?= $hidden ? 'is-hidden-exam' : '' ?>">
        <td>
          <a class="row-title" href="<?= h(url('teacher/exam_edit.php')) ?>?id=<?= $idParam ?>" title="<?= h($e['id']) ?>"><?= h($e['title']) ?></a>
          <span class="sub with-icon"><?= h($e['subject']) ?><?php if (exam_is_reward($e)): ?> · <?= icon('coin', 14) ?>Reward<?php endif; ?></span>
        </td>
        <td class="nowrap"><?= h(implode(', ', array_map(fn($c) => "Class $c", $e['classes']))) ?><?php if ($e['users']): ?><span class="sub">+ <?= count($e['users']) ?> student<?= count($e['users']) === 1 ? '' : 's' ?></span><?php endif; ?></td>
        <td>
          <div class="badges">
            <?php if ($e['status'] === 'draft'): ?><span class="badge">Draft</span>
            <?php elseif ($e['status'] === 'closed'): ?><span class="badge">Closed</span>
            <?php elseif (exam_from_past_year($e)): ?><span class="badge" title="From an earlier school year: students cannot start it">Closed</span>
            <?php else: ?><span class="badge badge-ok">Published</span><?php endif; ?>
            <?php if ($hidden): ?><span class="badge badge-warn" title="Students cannot see or start it"><?= icon('eye-off', 14) ?>Hidden</span><?php endif; ?>
            <?php if ($running): ?><span class="badge badge-warn"><?= $running ?> writing now</span><?php endif; ?>
            <?php if ($unsubmitted): ?><a class="badge badge-warn" href="<?= h(url('teacher/results.php')) ?>?id=<?= $idParam ?>" title="Marks so far are on the results page"><?= $unsubmitted ?> not submitted</a><?php endif; ?>
          </div>
        </td>
        <td class="nowrap"><?= h(schedule_text($open, $close)) ?><?php if (($year = exam_school_year($e)) !== null): ?><span class="sub"><?= h($year) ?> school year</span><?php endif; ?><?php if ((int)$e['time_limit_min'] > 0): ?><span class="sub with-icon"><?= icon('clock', 14) ?><?= (int)$e['time_limit_min'] ?> min</span><?php endif; ?></td>
        <td class="num"><?= $started ?></td>
        <td>
          <div class="row-actions">
            <a class="btn btn-sm btn-icon" href="<?= h(url('teacher/exam_edit.php')) ?>?id=<?= $idParam ?>" title="Edit"><?= icon('pencil', 16) ?></a>
            <a class="btn btn-sm btn-icon" href="<?= h(url('teacher/preview.php')) ?>?id=<?= $idParam ?>" target="_blank" title="Preview: try it as a student"><?= icon('play', 16) ?></a>
            <?php if ($e['status'] !== 'draft'): ?>
              <a class="btn btn-sm" href="<?= h(url('teacher/monitor.php')) ?>?id=<?= $idParam ?>" title="Monitor"><?= icon('activity', 16) ?><span class="btn-label">Monitor</span></a>
              <a class="btn btn-sm" href="<?= h(url('teacher/results.php')) ?>?id=<?= $idParam ?>" title="Results"><?= icon('chart', 16) ?><span class="btn-label">Results</span></a>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($e['id']) ?>">
                <?php if ($hidden): ?><button class="btn btn-sm btn-icon" name="action" value="show" title="Show to students again"><?= icon('eye', 16) ?></button>
                <?php else: ?><button class="btn btn-sm btn-icon btn-ghost" name="action" value="hide" title="Hide from students (results stay)"><?= icon('eye-off', 16) ?></button><?php endif; ?>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php page_end();
