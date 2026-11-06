<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Student home: exams to do now, locked ones, coming up (with a countdown) and finished.
 * A hidden exam does not show here (unless a try is still running; its results stay in My results); one from an earlier school year only shows
 * under Finished, and only when the student has taken it.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_login();
if (is_staff($user)) {
    redirect('teacher/index.php');
}

$groups = student_home_exams($user);

function exam_card(array $entry): void
{
    $exam = $entry['exam'];
    $status = $entry['status'];
    $result = $entry['result'];
    $href = url('student/exam.php') . '?id=' . rawurlencode($exam['id']);
    // One colour per subject, picked from its name, so exams of the same subject look alike
    $tint = 'tint-' . crc32(mb_strtolower($exam['subject'])) % 6;
    ?>
    <a class="exam-card state-<?= h($status['state']) ?>" href="<?= h($href) ?>">
      <span class="exam-card-icon <?= $tint ?>"><?= icon('sheet', 24) ?></span>
      <span class="exam-card-body">
        <strong><?= h($exam['title']) ?></strong>
        <span class="muted small with-icon"><?= h($exam['subject']) ?>
          <?php if ((int)$exam['time_limit_min'] > 0): ?> · <?= icon('clock', 14) ?><?= (int)$exam['time_limit_min'] ?> min<?php endif; ?>
          <?php if (exam_is_reward($exam)): ?> · <?= icon('coin', 14) ?>Reward<?php endif; ?>
        </span>
        <?php if (in_array($status['state'], ['open', 'upcoming', 'locked', 'in_progress'], true) && ($status['open'] !== null || $status['close'] !== null)): ?>
          <span class="small exam-card-when with-icon"><?= icon('calendar', 14) ?><?= h(schedule_text($status['open'], $status['close'])) ?></span>
        <?php endif; ?>
        <span class="exam-card-state">
          <?php if ($status['state'] === 'in_progress'): ?><span class="badge badge-warn">In progress — continue</span>
          <?php elseif ($status['state'] === 'open' && $status['close'] !== null && $status['close'] - time() < 6 * 3600): ?><span class="badge badge-ok" data-countdown="<?= h(date('c', $status['close'])) ?>" data-server-time="<?= h(date('c')) ?>" data-prefix="Ready · closes in ">Ready to start</span>
          <?php elseif ($status['state'] === 'open'): ?><span class="badge badge-ok">Ready to start</span>
          <?php elseif ($status['state'] === 'locked'): ?><span class="badge"><?= icon('lock', 14) ?>Ask your teacher to unlock</span>
          <?php elseif ($status['state'] === 'upcoming'): ?><span class="badge badge-info" data-countdown="<?= h(date('c', $status['open'])) ?>" data-server-time="<?= h(date('c')) ?>" data-prefix="Opens in ">Opens <?= h(moment_text($status['open'])) ?></span>
          <?php elseif ($result !== null): ?><span class="badge badge-ok"><?= $result['pending'] ? 'Marking in progress' : 'Score ' . round($result['percent']) . '%' ?></span>
          <?php else: ?><span class="badge"><?= h($status['reason'] ?: 'Closed') ?></span><?php endif; ?>
        </span>
      </span>
      <span class="exam-card-go"><?= icon('chevron-right', 22) ?></span>
    </a>
    <?php
}

page_start('My exams', $user, ['active' => 'home', 'css' => ['assets/css/player.css']]);
?>
<?php page_head('Hello, ' . $user['name'], ['meta' => [$user['class'] !== '' ? 'Class ' . h($user['class']) : '']]); ?>

<?php $sections = [
    'now' => ['Ready for you', 'Nothing to do right now.'],
    'locked' => ['Waiting for your teacher', ''],
    'upcoming' => ['Coming up', ''],
    'done' => ['Finished', ''],
];
foreach ($sections as $key => [$title, $empty]):
    if (!$groups[$key] && $empty === '') {
        continue;
    } ?>
  <h2 class="group-title"><?= h($title) ?><?php if ($groups[$key]): ?> <span class="badge"><?= count($groups[$key]) ?></span><?php endif; ?></h2>
  <?php if (!$groups[$key]): ?>
    <div class="card empty"><span class="empty-icon"><?= icon('sun', 40) ?></span><?= h($empty) ?></div>
  <?php else: ?>
    <div class="exam-grid"><?php foreach ($groups[$key] as $entry) exam_card($entry); ?></div>
  <?php endif; ?>
<?php endforeach; ?>
<?php page_end(['js' => ['assets/js/sync-rescue.js', 'assets/js/countdown.js']]);
