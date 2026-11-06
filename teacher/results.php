<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Exam results: per student (the counted try, when each try started and finished),
 * per question (how many got it right, common wrong answers; hover one to see who gave it), CSV download, links to marking.
 * Tries not submitted show their marks so far (from the saved answers) and can be opened;
 * they are not counted in the average, highest or lowest until submitted.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exam = load_exam((string)($_GET['id'] ?? ''));
if (!$exam || !exam_can_manage($user, $exam)) {
    http_response_code(404);
    exit('Exam not found');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remark') {
    csrf_check();
    set_time_limit(300);
    $count = 0;
    foreach (all_attempts($exam['id']) as $username => $list) {
        foreach ($list as $a) {
            if ($a['status'] !== 'in_progress' && !attempt_has_moodle_marks($a) && regrade_attempt($exam, $username, $a['n'])) {
                $count++;
            }
        }
    }
    fire_hook('grading.changed', $exam['id']);
    flash('ok', "Re-marked $count tries with the current answer key and settings. Teacher marks were kept.");
    redirect('teacher/results.php?id=' . rawurlencode($exam['id']));
}
$paper = load_paper($exam['id']);
exam_expire_due_attempts($exam);
$attempts = all_attempts($exam['id']);
// School years: an exam reopened in a later year has tries from several; one year is shown at a time (?year=2025-26 or all).
// Students who have not started are listed only for this school year (the class lists in users.csv are this year's).
$examYear = exam_school_year($exam) ?? current_school_year();
$years = array_values(array_unique(array_merge([$examYear], array_map('attempt_school_year', array_merge([], ...array_values($attempts))))));
rsort($years);
$year = ($_GET['year'] ?? '') === 'all' ? 'all' : (in_array($_GET['year'] ?? '', $years, true) ? (string)$_GET['year'] : $examYear);
if ($year !== 'all') {
    $attempts = array_filter(array_map(fn($list) => array_values(array_filter($list, fn($a) => attempt_school_year($a) === $year)), $attempts));
}
$overrides = exam_overrides($exam['id']);
$students = $year === current_school_year() || ($year === 'all' && !exam_from_past_year($exam)) ? exam_students($exam) : [];
foreach (array_keys($attempts) as $username) {
    if (!isset($students[$username])) {
        $students[$username] = student_info($username, $attempts[$username]);
    }
}
// The class each student was in when they wrote the exam (it may be last year's)
foreach ($attempts as $username => $list) {
    $students[$username]['class'] = attempt_student_class($username, $list);
}
// Students who left school (no longer in users.csv) are listed with a badge; ?former=hide leaves them out
$formerCount = count(array_filter(array_keys($students), 'is_former_user'));
$hideFormer = ($_GET['former'] ?? '') === 'hide' && ($_GET['format'] ?? '') !== 'csv';
if ($hideFormer) {
    $students = array_filter($students, fn($s) => !is_former_user($s['username']));
}

$rows = [];
$questionStats = [];
foreach ($students as $username => $s) {
    $list = $attempts[$username] ?? [];
    $result = exam_student_result($exam, $username, $list);
    $needs = array_values(array_filter($list, fn($a) => $a['status'] === 'submitted'));
    // Tries not submitted: marks so far from the saved answers
    $soFar = [];
    foreach ($list as $a) {
        if ($a['status'] === 'in_progress') {
            $graded = attempt_marks_so_far($exam, $paper, $a);
            [$answered, $questions] = attempt_progress($paper, $a);
            $soFar[$a['n']] = ['score' => $graded['score'], 'max' => $a['max'], 'answered' => $answered, 'questions' => $questions,
                'active' => attempt_is_active($a), 'paused' => attempt_is_paused($a)];
        }
    }
    $rows[] = ['student' => $s, 'attempts' => $list, 'result' => $result, 'needs' => $needs, 'so_far' => $soFar];
    // Question statistics from the counted try (or latest finished one)
    $counted = null;
    if ($result && $result['attempt']) {
        $counted = $list[array_search($result['attempt'], array_column($list, 'n'), true)] ?? null;
    }
    $counted ??= array_values(array_filter($list, fn($a) => $a['status'] !== 'in_progress'))[0] ?? null;
    if ($counted) {
        foreach ($counted['slots'] as $slot) {
            $g = $counted['grades'][$slot['slot']] ?? null;
            $q = $paper['questions'][$slot['key']];
            if (!$g || $q['type'] === 'description' || $g['state'] === 'needs_review') {
                continue;
            }
            $st = &$questionStats[$slot['key']];
            $st ??= ['q' => $q, 'n' => 0, 'fraction' => 0.0, 'wrong' => []];
            $st['n']++;
            $st['fraction'] += (float)$g['fraction'];
            if ((float)$g['fraction'] < 1 && in_array($q['type'], ['shortanswer', 'numerical', 'multichoice', 'truefalse'], true)) {
                $resp = describe_response($q, $counted['answers'][$slot['slot']] ?? null);
                $st['wrong'][$resp][] = $s['name'];
            }
            unset($st);
        }
    }
}

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $exam['id'] . ($year !== 'all' ? "-$year" : '') . '-results.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['username', 'name', 'class', 'tries', 'counted_try', 'score', 'max', 'percent', 'status', 'started', 'finished', 'minutes', 'left_school'], ',', '"', '');
    foreach ($rows as $r) {
        $res = $r['result'];
        if (!$res && $r['so_far']) {
            // Only unsubmitted tries: their marks so far
            $n = array_key_last($r['so_far']);
            $sf = $r['so_far'][$n];
            $t = attempt_times($r['attempts'][array_search($n, array_column($r['attempts'], 'n'), true)]);
            fputcsv($out, [
                $r['student']['username'], $r['student']['name'], $r['student']['class'], count($r['attempts']),
                $n, $sf['score'], $sf['max'], $sf['max'] > 0 ? round(100 * $sf['score'] / $sf['max'], 1) : '',
                "not submitted ({$sf['answered']} of {$sf['questions']} answered)",
                $t['started'] ? date('Y-m-d H:i:s', $t['started']) : '', '', '', former_left_on($r['student']['username']),
            ], ',', '"', '');
            continue;
        }
        $shown = null;
        foreach ($r['attempts'] as $a) {
            if ($res && $res['attempt'] ? $a['n'] === $res['attempt'] : $a['status'] !== 'in_progress') {
                $shown = attempt_times($a);
            }
        }
        fputcsv($out, [
            $r['student']['username'], $r['student']['name'], $r['student']['class'], count($r['attempts']),
            $res['attempt'] ?? '', $res['score'] ?? '', $res['max'] ?? '', $res ? round($res['percent'], 1) : '',
            !$r['attempts'] ? 'not started' : ($r['needs'] ? 'needs marking' : ($res ? 'done' : 'in progress')),
            $shown && $shown['started'] ? date('Y-m-d H:i:s', $shown['started']) : '',
            $shown && $shown['finished'] ? date('Y-m-d H:i:s', $shown['finished']) : '',
            $shown && $shown['seconds'] !== null ? round($shown['seconds'] / 60, 1) : '',
            former_left_on($r['student']['username']),
        ], ',', '"', '');
    }
    exit;
}

$finished = array_filter($rows, fn($r) => $r['result'] !== null);
$percents = array_map(fn($r) => $r['result']['percent'], $finished);
$fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 2), '0'), '.');
uasort($questionStats, fn($a, $b) => ($a['fraction'] / max(1, $a['n'])) <=> ($b['fraction'] / max(1, $b['n'])));

page_start('Results', $user, ['active' => 'exams', 'css' => ['assets/css/teacher.css']]);

$idParam = h(rawurlencode($exam['id']));
$yearParam = $year !== $examYear ? '&amp;year=' . h(rawurlencode($year)) : '';
page_head('Results', [
    'back' => [$exam['title'], 'teacher/exam_edit.php?id=' . rawurlencode($exam['id'])],
    'actions' => '<a class="btn" href="' . h(url('teacher/monitor.php')) . '?id=' . $idParam . '">' . icon('activity') . 'Monitor</a>'
        . '<a class="btn" href="' . h(url('teacher/grading.php')) . '?exam=' . $idParam . '">' . icon('clipboard-check') . 'Grading</a>'
        . '<a class="btn" href="' . h(url('teacher/attachments.php')) . '?id=' . $idParam . '" title="Photos or PDFs printed after each student\'s result sheet">' . icon('paperclip') . 'Attachments</a>'
        . '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="remark">'
        . '<button class="btn" data-confirm="Click again to re-mark all" title="Use after changing the answer key or grading settings">' . icon('refresh') . 'Re-mark all</button></form>'
        . '<a class="btn btn-primary" href="?id=' . $idParam . $yearParam . '&amp;format=csv">' . icon('download') . 'Download CSV</a>',
    'meta' => [count($years) > 1 ? icon('calendar', 14) . ($year === 'all' ? 'All school years' : h($year) . ' school year') : ''],
]);
$marking = array_sum(array_map(fn($r) => count($r['needs']), $rows));
$unsubmitted = array_sum(array_map(fn($r) => count(array_filter($r['so_far'], fn($sf) => !$sf['active'])), $rows));
?>

<div class="card summary <?= $unsubmitted ? 'summary-6' : 'summary-5' ?>">
  <div class="summary-cell <?= $rows && count($finished) === count($rows) ? 'is-ok' : '' ?>"><span class="summary-icon"><?= icon('check-circle') ?></span><span><b><?= count($finished) ?>/<?= count($rows) ?></b><span>students finished</span></span></div>
  <div class="summary-cell"><span><b class="<?= $percents ? 'good' : '' ?>"><?= $percents ? round(array_sum($percents) / count($percents)) . '%' : '—' ?></b><span>average</span></span></div>
  <div class="summary-cell"><span><b><?= $percents ? round(max($percents)) . '%' : '—' ?></b><span>highest</span></span></div>
  <div class="summary-cell"><span><b><?= $percents ? round(min($percents)) . '%' : '—' ?></b><span>lowest</span></span></div>
  <div class="summary-cell <?= $marking ? 'is-warn' : 'is-zero' ?>"><span class="summary-icon"><?= icon('pencil') ?></span><span><b><?= $marking ?></b><span>tries need marking</span></span></div>
  <?php if ($unsubmitted): ?><div class="summary-cell is-warn" title="Marks so far are shown; not in the average until submitted"><span class="summary-icon"><?= icon('alert') ?></span><span><b><?= $unsubmitted ?></b><span>tries not submitted</span></span></div><?php endif; ?>
</div>

<form class="card flush" method="get" action="<?= h(url('teacher/result_sheets.php')) ?>" target="_blank" id="print-form">
  <input type="hidden" name="id" value="<?= h($exam['id']) ?>">
  <input type="hidden" name="year" value="<?= h($year) ?>">
  <div class="card-head">
    <h2>Students</h2>
    <?php if (count($years) > 1): ?>
      <select class="select-sm" aria-label="School year" onchange="location.href='?id=<?= $idParam ?><?= $hideFormer ? '&former=hide' : '' ?>&year=' + encodeURIComponent(this.value)">
        <?php foreach (array_merge($years, ['all']) as $y): ?><option value="<?= h($y) ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y === 'all' ? 'All school years' : h($y) . ($y === current_school_year() ? ' (this year)' : '') ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php if ($formerCount): ?>
      <a class="btn btn-sm btn-ghost" href="?id=<?= $idParam ?><?= $yearParam ?><?= $hideFormer ? '' : '&amp;former=hide' ?>" title="Students no longer in users.csv (left school)"><?= icon($hideFormer ? 'eye' : 'eye-off', 16) ?><?= $hideFormer ? 'Show' : 'Hide' ?> students who left (<?= $formerCount ?>)</a>
    <?php endif; ?>
    <span class="spacer"></span>
    <span class="small muted">Result sheets to print or save as PDF</span>
    <button class="btn btn-sm" type="submit" id="print-selected" disabled><?= icon('printer', 16) ?><span>Print selected (<span id="print-count">0</span>)</span></button>
    <a class="btn btn-sm btn-primary" target="_blank" href="<?= h(url('teacher/result_sheets.php')) ?>?id=<?= $idParam ?>&amp;year=<?= h(rawurlencode($year)) ?>&amp;users=all<?= $hideFormer ? h('&set=1&' . http_build_query(array_map('intval', ['former' => false] + SHEET_OPTIONS))) : '' ?>"><?= icon('printer', 16) ?>Print all</a>
  </div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th class="cb"><input type="checkbox" id="pick-all" title="Select all"></th><th>Student</th><th class="num">Score</th><th class="num">%</th><th>Status</th><th>Tries · started → finished</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $res = $r['result']; $uParam = h(rawurlencode($r['student']['username'])); ?>
      <tr>
        <td class="cb"><?php if ($r['attempts']): ?><input type="checkbox" name="users[]" value="<?= h($r['student']['username']) ?>" class="pick" aria-label="Select"><?php endif; ?></td>
        <td><strong><?= h($r['student']['name']) ?></strong> <?= former_badge($r['student']['username']) ?> <span class="sub inline"><?= h($r['student']['username']) ?><?= $r['student']['class'] !== '' ? ' · Class ' . h($r['student']['class']) : '' ?></span>
          <?php $pp = attachment_pages($exam['id'], $r['student']['username']);
          $own = exam_window($exam, $r['student']['username']);
          if ($pp || $own['own']): ?><div class="chips mt-4">
            <?php if ($pp): ?><a class="chip" href="<?= h(url('teacher/attachments.php')) ?>?id=<?= $idParam ?>#u-<?= $uParam ?>"><?= icon('paperclip', 14) ?><?= count($pp) ?> page<?= count($pp) === 1 ? '' : 's' ?> attached</a><?php endif; ?>
            <?php if ($own['own']): ?><span class="chip" title="This student's own opening and closing time"><?= icon('clock', 14) ?><?= h(schedule_text($own['open'], $own['close'])) ?></span><?php endif; ?>
          </div><?php endif; ?></td>
        <?php $sf = !$res && $r['so_far'] ? end($r['so_far']) : null; ?>
        <?php if ($sf): ?>
          <td class="num nowrap"><?= $fmt($sf['score']) ?> / <?= $fmt($sf['max']) ?><span class="sub">so far</span></td>
          <td class="num"><?= $sf['max'] > 0 ? round(100 * $sf['score'] / $sf['max']) . '%' : '' ?></td>
        <?php else: ?>
          <td class="num nowrap"><?= $res && $res['score'] !== null ? $fmt($res['score']) . ' / ' . $fmt($res['max']) : '' ?></td>
          <td class="num"><?= $res ? round($res['percent']) . '%' : '' ?></td>
        <?php endif; ?>
        <td>
          <?php if (!$r['attempts']): ?><span class="badge">Not started</span>
          <?php elseif ($r['needs']): ?><span class="badge badge-warn">Needs marking</span>
          <?php elseif (!$res && $sf && $sf['paused']): ?><span class="badge badge-warn" title="The connection was lost; the time left stands still until it is back">Paused</span>
          <?php elseif (!$res && $sf && $sf['active']): ?><span class="badge badge-info">Writing now</span>
          <?php elseif (!$res): ?><span class="badge badge-warn">Not submitted</span>
          <?php else: ?><span class="badge badge-ok">Done</span><?php endif; ?>
        </td>
        <td>
          <?php foreach ($r['attempts'] as $a): $t = attempt_times($a);
              $markUrl = h(url('teacher/mark.php')) . '?id=' . $idParam . '&amp;user=' . h(rawurlencode($a['user'])) . '&amp;n=' . (int)$a['n']; ?>
            <div class="try-line">
              <?php if ($a['status'] === 'in_progress'): $s = $r['so_far'][$a['n']]; $last = attempt_last_activity($a); ?>
                <span class="grow">Try <?= (int)$a['n'] ?> · <span class="nowrap"><?= $t['started'] ? h(date('d M, g:i A', $t['started'])) : '' ?><?= $last ? ' → last saved ' . h(date(date('Y-m-d', $last) === date('Y-m-d', (int)$t['started']) ? 'g:i A' : 'd M, g:i A', $last)) : '' ?></span>
                  <span class="muted nowrap">(<?= (int)$s['answered'] ?> of <?= (int)$s['questions'] ?> answered)</span>
                  <span class="nowrap"><?= $s['max'] > 0 ? round(100 * $s['score'] / $s['max']) . '% so far' : '' ?></span>
                  <?= $s['paused'] ? '<span class="badge badge-warn" title="The connection was lost; the time left stands still until it is back">Paused</span>'
                      : ($s['active'] ? '<span class="badge badge-info">Writing now</span>' : '<span class="badge badge-warn">Not submitted</span>') ?></span>
                <a class="btn btn-sm" href="<?= $markUrl ?>">View</a>
                <span class="preview-spacer"></span>
              <?php else: ?>
                <span class="grow">Try <?= (int)$a['n'] ?> · <span class="nowrap"><?= $t['started'] ? h(date('d M, g:i A', $t['started'])) : '' ?><?= $t['finished'] ? ' → ' . h(date(date('Y-m-d', $t['finished']) === date('Y-m-d', (int)$t['started']) ? 'g:i A' : 'd M, g:i A', $t['finished'])) : '' ?></span>
                  <?php if ($t['seconds'] !== null): ?><span class="muted nowrap">(<?= h(duration_text($t['seconds'])) ?><?= $a['auto_submitted'] ? ', time up' : '' ?>)</span><?php endif; ?>
                  <?php if ($a['max'] > 0 && $a['status'] === 'graded'): ?><span class="nowrap"><?= round(100 * $a['score'] / $a['max']) ?>%</span><?php endif; ?></span>
                <a class="btn btn-sm <?= $a['status'] === 'submitted' ? 'btn-accent' : '' ?>" href="<?= $markUrl ?>"><?= $a['status'] === 'submitted' ? icon('pencil', 16) . 'Mark' : 'View' ?></a>
                <a class="btn btn-sm btn-icon btn-ghost" target="_blank" title="Result sheet of this try" href="<?= h(url('teacher/result_sheets.php')) ?>?id=<?= $idParam ?>&amp;users=<?= h(rawurlencode($a['user'])) ?>&amp;try=<?= (int)$a['n'] ?>"><?= icon('printer', 16) ?></a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</form>
<script>
(function () {
  const form = document.getElementById('print-form');
  const all = document.getElementById('pick-all');
  const btn = document.getElementById('print-selected');
  const update = () => {
    const n = form.querySelectorAll('input.pick:checked').length;
    btn.disabled = n === 0;
    document.getElementById('print-count').textContent = n;
  };
  all.addEventListener('change', () => { form.querySelectorAll('input.pick').forEach((c) => { c.checked = all.checked; }); update(); });
  form.addEventListener('change', update);
})();
</script>

<?php if ($questionStats): ?>
<div class="card flush">
  <div class="card-head"><h2>Questions</h2><span class="muted small">hardest first</span></div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Question</th><th class="num">Answered</th><th class="num">Got it right</th><th>Common wrong answers</th></tr></thead>
    <tbody>
    <?php foreach ($questionStats as $key => $st):
        uasort($st['wrong'], fn($a, $b) => count($b) <=> count($a));
        $pct = round(100 * $st['fraction'] / max(1, $st['n'])); ?>
      <tr>
        <td><span class="chip type-badge t-<?= h($st['q']['type']) ?>"><?= h(question_type_label($st['q']['type'])) ?></span>
          <?= h(mb_substr($st['q']['name'], 0, 90)) ?> <?php $label = question_source_label($st['q']); ?><span class="qrow-label" title="<?= h($label) ?>"><?= h($label) ?></span></td>
        <td class="num"><?= $st['n'] ?></td>
        <td class="num"><span class="badge <?= $pct >= 70 ? 'badge-ok' : ($pct >= 40 ? 'badge-warn' : 'badge-err') ?>"><?= $pct ?>%</span></td>
        <td><div class="chips"><?php foreach (array_slice($st['wrong'], 0, 4, true) as $answer => $names): natcasesort($names); ?><span class="chip" tabindex="0" data-names="<?= h(json_encode(array_values($names), JSON_UNESCAPED_UNICODE)) ?>"><?= h(mb_substr((string)$answer, 0, 40)) ?> ×<?= count($names) ?></span><?php endforeach; ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php page_end();
