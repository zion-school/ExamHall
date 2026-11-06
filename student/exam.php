<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * One exam for a student: the start page, and the exam player once started
 * (?play=1). The player itself is assets/js/player.js.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_login();
$exam = load_exam((string)($_GET['id'] ?? ''));
$paper = $exam ? load_paper($exam['id']) : null;
$status = $exam && $paper ? exam_student_status($exam, $user) : ['state' => 'hidden', 'attempts' => []];
// An exam the student has taken but can no longer see (e.g. from a class they left) still opens, to reach the results;
// one the teacher hid does not
$tookIt = (bool)array_filter($status['attempts'], fn($a) => $a['status'] !== 'in_progress');
if ($status['state'] === 'hidden' && (!$tookIt || !empty($exam['hidden']) || !empty($_GET['play']) || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    http_response_code(404);
    page_start('Exam not found', $user);
    echo '<div class="card empty"><span class="empty-icon">' . icon('search', 40) . '</span>This exam is not available. <a href="' . h(url('student/index.php')) . '">Back to my exams</a></div>';
    page_end();
    exit;
}
$self = 'student/exam.php?id=' . rawurlencode($exam['id']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $started = student_start_exam($exam, $paper, $status, $user, (string)($_POST['code'] ?? ''));
    if (is_string($started)) {
        $error = $started;
    } else {
        redirect($self . '&play=1');
    }
}

// ---------- Player ----------
if (!empty($_GET['play']) && $status['state'] === 'in_progress') {
    // Opening the player makes this page the active one; an older tab is told to stop
    $attempt = start_or_resume_attempt($exam, $paper, $user, null);
    render_player($exam, $paper, $attempt, $user, [
        'save' => url('api/attempt_save.php'),
        'report' => url('api/report_question.php'),
        'check' => url('api/attempt_check.php'),
        'result' => url('student/result.php') . '?id=' . rawurlencode($exam['id']) . '&n=' . $attempt['n'],
        'resume' => url($self . '&play=1'),
        'home' => url('student/index.php'),
    ]);
    exit;
}

// ---------- Start page ----------
$finished = array_values(array_filter($status['attempts'], fn($a) => $a['status'] !== 'in_progress'));

page_start($exam['title'], $user, ['active' => 'home', 'css' => ['assets/css/player.css', 'assets/css/teacher.css']]);
?>
<div class="start-wrap">
  <div class="card start-card">
    <a class="back" href="<?= h(url('student/index.php')) ?>"><?= icon('chevron-left', 14) ?>My exams</a>
    <h1><?= h($exam['title']) ?></h1>
    <p class="muted"><?= h($exam['subject']) ?></p>

    <?= exam_facts_html($exam, paper_size($paper['sections'])[0], (float)$paper['max_marks'], $status, $paper['sections']) ?>

    <?php if (trim($exam['instructions']) !== ''): ?>
      <div class="instructions"><?= nl2br(h($exam['instructions'])) ?></div>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert alert-err"><?= h($error) ?></div><?php endif; ?>

    <?php if ($status['state'] === 'in_progress'): ?>
      <form method="post"><?= csrf_field() ?><button class="btn btn-primary btn-lg btn-block">Continue where you left off<?= icon('arrow-right', 20) ?></button></form>
    <?php elseif ($status['can_start']): ?>
      <form method="post">
        <?= csrf_field() ?>
        <?php if ($exam['access_code'] !== ''): ?>
          <label class="field"><span>Exam code (from your teacher)</span>
            <input type="text" name="code" class="code-input" autocomplete="off" autocapitalize="characters" required></label>
        <?php endif; ?>
        <button class="btn btn-primary btn-lg btn-block"><?= $finished ? 'Try again' : 'Start' ?><?= icon('arrow-right', 20) ?></button>
      </form>
    <?php else: ?>
      <div class="alert alert-info"><?= h($status['reason']) ?></div>
    <?php endif; ?>

    <?php if ($finished): ?>
      <h2 class="mt-16">Your tries</h2>
      <ul class="qlist">
        <?php foreach ($finished as $a): ?>
          <li class="qrow"><div class="qrow-head">
            <?php $t = attempt_times($a); ?>
            <span class="qrow-name">Try <?= (int)$a['n'] ?> · <?= h(date('d M, g:i A', $t['started'] ?? $t['finished'])) ?><?= $t['seconds'] !== null ? ' <span class="small muted">(' . h(duration_text($t['seconds'])) . ')</span>' : '' ?></span>
            <?php if ($a['status'] === 'submitted'): ?><span class="badge badge-warn">Being marked</span>
            <?php else: ?><span class="badge badge-ok"><?= $a['max'] > 0 ? round(100 * $a['score'] / $a['max']) : 0 ?>%</span><?php endif; ?>
            <a class="btn btn-sm" href="<?= h(url('student/result.php')) ?>?id=<?= h(rawurlencode($exam['id'])) ?>&n=<?= (int)$a['n'] ?>">See result</a>
          </div></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php page_end(['js' => ['assets/js/countdown.js']]);
