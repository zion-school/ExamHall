<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Rewards: what each student earned in reward exams, approvals, payments through
 * Zion Bank, retries and checking unclear payments.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exams = array_values(array_filter(all_exams(), fn($e) => exam_can_manage($user, $e) && exam_is_reward($e) && $e['status'] !== 'draft'));
$exam = isset($_GET['exam']) ? load_exam((string)$_GET['exam']) : null;
if (isset($_GET['exam']) && (!$exam || !exam_can_manage($user, $exam) || !exam_is_reward($exam))) {
    http_response_code(404);
    exit('Reward exam not found');
}
$problem = reward_setup_problem();
$currency = cfg('CURRENCY_SYMBOL', '₹');
$money = fn($x) => $currency . rtrim(rtrim(number_format((float)$x, 2), '0'), '.');

if ($exam && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    set_time_limit(120);
    $ids = array_values(array_filter((array)($_POST['ids'] ?? []), 'is_string'));
    switch ($_POST['action'] ?? '') {
        case 'approve':
            $n = rewards_decide($exam, $ids, true, $user['username']);
            $pay = rewards_pay($exam);
            flash($pay['problems'] ? 'err' : 'ok', "Approved $n. Paid {$pay['paid']}." . ($pay['problems'] ? ' ' . implode(' ', array_unique($pay['problems'])) : ''));
            break;
        case 'reject':
            $n = rewards_decide($exam, $ids, false, $user['username']);
            flash('ok', "$n reward(s) will not be paid.");
            break;
        case 'retry':
            $pay = rewards_pay($exam, true);
            flash($pay['problems'] ? 'err' : 'ok', "Paid {$pay['paid']}." . ($pay['problems'] ? ' ' . implode(' ', array_unique($pay['problems'])) : ''));
            break;
        case 'reconcile':
            $n = rewards_reconcile($exam);
            flash('ok', "Checked $n unclear payment(s) against the Zion Bank log.");
            break;
        case 'sweep':
            $r = rewards_sweep($exam);
            flash($r['problems'] ? 'err' : 'ok', "{$r['added']} new reward(s), {$r['paid']} paid." . ($r['problems'] ? ' ' . implode(' ', array_unique($r['problems'])) : ''));
            break;
    }
    redirect('teacher/rewards.php?exam=' . rawurlencode($exam['id']));
}

page_start('Rewards', $user, ['active' => 'rewards', 'css' => ['assets/css/teacher.css']]);
$balance = bank_reward_balance();
?>
<?php page_head($exam ? $exam['title'] : 'Rewards', [
    'back' => $exam ? ['Rewards', 'teacher/rewards.php'] : null,
    'meta' => ['Paid from the <strong>' . h(cfg('REWARD_ACCOUNT')) . '</strong> account in Zion Bank', $balance !== null ? 'Balance ' . h($money($balance)) : ''],
    'actions' => $exam ? '<a class="btn" href="' . h(url('teacher/exam_edit.php')) . '?id=' . h(rawurlencode($exam['id'])) . '#part-rewards">' . icon('pencil') . 'Reward settings</a>' : '',
]); ?>

<?php if ($problem): ?>
  <details class="notice notice-warn">
    <summary><?= icon('alert', 16) ?><span class="notice-text"><strong>Rewards cannot be paid yet.</strong> <?= h($problem) ?></span><span class="notice-more">More</span></summary>
    <div class="notice-body"><?= h($problem) ?> Create the <code><?= h(cfg('REWARD_ACCOUNT')) ?></code> user in Zion Bank admin and give it money first.</div>
  </details>
<?php endif; ?>

<?php if (!$exam): ?>
  <div class="card flush">
    <?php if (!$exams): ?>
      <div class="empty"><span class="empty-icon"><?= icon('coin', 40) ?></span>No reward exams yet. Tick "This exam gives Zion Bank rewards" in an exam's settings.</div>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Exam</th><th class="num">Paid</th><th class="num">Waiting for approval</th><th class="num">Problems</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($exams as $e):
          $rows = load_rewards($e['id']);
          $paid = array_sum(array_map(fn($r) => $r['status'] === 'paid' ? (float)$r['amount'] : 0, $rows));
          $waiting = count(array_filter($rows, fn($r) => $r['status'] === 'awaiting_approval'));
          $bad = count(array_filter($rows, fn($r) => in_array($r['status'], ['failed', 'unknown'], true))); ?>
        <tr>
          <td><strong><?= h($e['title']) ?></strong><span class="sub"><?= h($e['subject']) ?> · <?= h(reward_payout_mode($e, load_paper($e['id']) ?? ['questions' => []]) === 'auto' ? 'pays automatically' : 'teacher approves') ?></span></td>
          <td class="num"><?= h($money($paid)) ?></td>
          <td class="num"><?= $waiting ?: '' ?></td>
          <td class="num"><?= $bad ? '<span class="badge badge-err">' . $bad . '</span>' : '' ?></td>
          <td><div class="row-actions"><a class="btn btn-sm btn-primary" href="?exam=<?= h(rawurlencode($e['id'])) ?>">Open</a></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
<?php page_end(); exit; endif;

$paper = load_paper($exam['id']);
$rows = load_rewards($exam['id']);
$mode = reward_payout_mode($exam, $paper);
$byUser = [];
foreach ($rows as $r) {
    $byUser[$r['username']][] = $r;
}
$students = array_unique(array_merge(array_keys(exam_students($exam)), list_dirs(exam_dir($exam['id']) . '/attempts')));
$statusBadge = [
    'awaiting_approval' => ['badge-warn', 'waiting for approval'], 'approved' => ['badge-info', 'approved'], 'paying' => ['badge-info', 'paying…'],
    'paid' => ['badge-ok', 'paid'], 'failed' => ['badge-err', 'failed'], 'unknown' => ['badge-err', 'unclear'],
    'rejected' => ['', 'not paid'], 'cancelled' => ['', 'cancelled'],
];
?>
<div class="card">
  <p class="m-0">Formula <code><?= h($exam['reward']['formula']) ?></code>, most <?= h($money($exam['reward']['max'])) ?> per exam<?= (float)$exam['reward']['daily_max'] > 0 ? ', ' . h($money($exam['reward']['daily_max'])) . ' per day' : '' ?>,
    counts the <?= $exam['reward']['count'] === 'first' ? 'first try' : 'best try' ?>.
    <strong><?= $mode === 'auto' ? 'Pays automatically.' : 'You approve each payment' . (in_array('essay', paper_question_types($paper), true) && $exam['reward']['payout'] === 'by_question_types' ? ' (the exam has essays).' : '.') ?></strong></p>
  <form method="post" class="row mt-12">
    <?= csrf_field() ?>
    <button class="btn" name="action" value="sweep"><?= icon('refresh') ?>Recalculate</button>
    <button class="btn" name="action" value="retry"><?= icon('repeat') ?>Retry failed payments</button>
    <button class="btn" name="action" value="reconcile"><?= icon('search') ?>Check unclear payments</button>
  </form>
</div>

<form method="post" class="card flush">
  <?= csrf_field() ?>
  <div class="card-head"><h2>Students</h2></div>
  <table class="table">
    <thead><tr><th class="cb"></th><th>Student</th><th class="num">Counted try</th><th class="num">Earned</th><th class="num">Paid</th><th>Payments</th></tr></thead>
    <tbody>
    <?php foreach ($students as $username):
        $ent = reward_entitlement($exam, $paper, $username);
        $mine = $byUser[$username] ?? [];
        $paid = array_sum(array_map(fn($r) => $r['status'] === 'paid' ? (float)$r['amount'] : 0, $mine));
        if (!$mine && $ent['attempt'] === null) {
            continue;
        } ?>
      <tr>
        <td class="cb"><?php foreach ($mine as $r): if ($r['status'] === 'awaiting_approval'): ?><input type="checkbox" name="ids[]" value="<?= h($r['reward_id']) ?>" checked aria-label="Approve"><?php endif; endforeach; ?></td>
        <td><strong><?= h(display_name($username)) ?></strong><span class="sub"><?= h($username) ?></span></td>
        <td class="num"><?= $ent['attempt'] !== null ? 'try ' . $ent['attempt'] . ' · ' . round($ent['percent']) . '%' : '—' ?></td>
        <td class="num"><?= h($money($ent['amount'])) ?></td>
        <td class="num"><?= h($money($paid)) ?></td>
        <td class="small">
          <?php foreach ($mine as $r): [$cls, $label] = $statusBadge[$r['status']] ?? ['', $r['status']]; ?>
            <div><code><?= h($r['reward_id']) ?></code> <?= h($money($r['amount'])) ?> <span class="badge <?= $cls ?>"><?= h($label) ?></span>
              <?php if ($r['message'] !== ''): ?><span class="muted"><?= h($r['message']) ?></span><?php endif; ?></div>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (array_filter($rows, fn($r) => $r['status'] === 'awaiting_approval')): ?>
  <div class="row card-foot">
    <button class="btn btn-primary" name="action" value="approve" <?= $problem ? 'disabled' : '' ?>><?= icon('check') ?>Approve and pay ticked</button>
    <button class="btn btn-danger" name="action" value="reject" data-confirm="Click again: do not pay ticked"><?= icon('x') ?>Do not pay ticked</button>
  </div>
  <?php endif; ?>
</form>
<?php page_end();
