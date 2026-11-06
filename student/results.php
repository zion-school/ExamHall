<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * A student's progress: finished exams by the class and school year they were taken in (newest first), then by
 * subject, with scores and coins earned.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_login();
if (is_staff($user)) {
    redirect('teacher/index.php');
}
$currency = cfg('CURRENCY_SYMBOL', '₹');
[$byYear, $coins] = student_results($user);
$finished = array_sum(array_map(fn($s) => array_sum(array_map('count', $s)), $byYear));

page_start('My results', $user, ['active' => 'results', 'css' => ['assets/css/player.css']]);
?>
<?php page_head('My results', ['meta' => [$finished . ' exam(s) finished',
    $coins > 0 ? icon('coin', 14) . h($currency . rtrim(rtrim(number_format($coins, 2), '0'), '.')) . ' earned in Zion Bank' : '']]); ?>

<?php if (!$byYear): ?>
  <div class="card empty"><span class="empty-icon"><?= icon('chart', 40) ?></span>No results yet. Finish an exam to see it here.</div>
<?php endif; ?>

<?php foreach ($byYear as $group => $bySubject):
    [$year, $class] = explode('|', $group, 2); ?>
  <h2 class="group-title"><?= $class !== '' ? 'Class ' . h($class) . ' · ' : '' ?><?= h($year) ?></h2>
<?php foreach ($bySubject as $subject => $entries):
    $percents = array_values(array_filter(array_map(fn($e) => $e['result'] && !$e['result']['pending'] ? $e['result']['percent'] : null, $entries), fn($p) => $p !== null));
    ?>
  <div class="card flush">
    <div class="card-head">
      <h2><?= h($subject) ?></h2>
      <span class="spacer"></span>
      <?php if ($percents): ?><span class="badge badge-info">Average <?= round(array_sum($percents) / count($percents)) ?>%</span><?php endif; ?>
    </div>
    <table class="table">
      <thead><tr><th>Exam</th><th>Date</th><th class="num">Tries</th><th class="num">Score</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($entries as $e):
          $r = $e['result'];
          $pct = $r ? round($r['percent']) : null;
          // Marks scored / total; "Average of tries" grading has no single try, so its average percent in marks
          $fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 2), '0'), '.');
          $marks = $r ? $fmt($r['score'] ?? $r['percent'] * $r['max'] / 100) . ' / ' . $fmt($r['max']) : ''; ?>
        <tr>
          <td><strong><?= h($e['exam']['title']) ?></strong>
            <?php if ($e['reward']['paid'] > 0): ?> <span class="badge badge-coin"><?= icon('coin', 14) ?><?= h($currency . rtrim(rtrim(number_format($e['reward']['paid'], 2), '0'), '.')) ?></span><?php endif; ?></td>
          <td class="small nowrap"><?= h(date('d M Y', strtotime($e['last']['submitted'] ?? $e['last']['started']))) ?></td>
          <td class="num"><?= $e['tries'] ?></td>
          <td class="num">
            <?php if (!$r || $r['pending']): ?><span class="badge badge-warn">Being marked</span>
            <?php else: ?>
              <span class="progress-cell"><span class="progress progress-short"><span style="width:<?= $pct ?>%"></span></span><strong title="<?= $pct ?>%"><?= $marks ?></strong></span>
            <?php endif; ?>
          </td>
          <td><div class="row-actions"><a class="btn btn-sm" href="<?= h(url('student/result.php')) ?>?id=<?= h(rawurlencode($e['exam']['id'])) ?>&amp;n=<?= (int)($r['attempt'] ?? $e['last']['n']) ?>">See</a></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php page_end();
