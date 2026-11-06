<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 17-Sep-2026
 *
 * Students: everyone in users.csv and everyone who left school (the register, data/people.csv), with their
 * tries across all exams. Teachers see the tries of their subjects' exams, admins all.
 *   teacher/students.php?q=&class=&status=current|left      the list
 *   teacher/students.php?user=<username>                     one student's tries
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$directory = student_directory($user);
$fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 2), '0'), '.');
$day = fn(?string $iso) => $iso ? date('d M Y', strtotime($iso)) : '';

$statusBadge = function (array $s): string {
    return match ($s['status']) {
        'left' => former_badge($s['username']),
        'no_account' => '<span class="badge" title="Never in users.csv: a student from Moodle without a bank account">No account</span>',
        default => '',
    };
};

$username = (string)($_GET['user'] ?? '');
if ($username !== '') {
    $s = $directory[$username] ?? null;
    if (!$s) {
        http_response_code(404);
        exit('Student not found');
    }
    page_start($s['name'], $user, ['active' => 'students', 'css' => ['assets/css/teacher.css']]);
    page_head($s['name'], [
        'back' => ['Students', 'teacher/students.php'],
        'badges' => $statusBadge($s),
        'meta' => [
            h($username),
            $s['class'] !== '' ? 'Class ' . h($s['class']) : '',
            $s['first_seen'] !== '' ? 'In users.csv since ' . h($day($s['first_seen'])) : '',
            $s['left_on'] !== '' && $s['status'] === 'left' ? 'Left on ' . h($day($s['left_on'])) : '',
            count($s['tries']) . ' tr' . (count($s['tries']) === 1 ? 'y' : 'ies') . ($user['role'] === 'teacher' ? ' in your subjects' : ''),
        ],
    ]);
    ?>
    <?php if ($s['note'] !== ''): ?>
      <div class="alert alert-warn"><?= h($s['note']) ?>. If this is a different student, give one of them a new username in Zion Bank.</div>
    <?php endif; ?>
    <div class="card flush">
      <?php if (!$s['tries']): ?>
        <div class="empty"><span class="empty-icon"><?= icon('file', 40) ?></span>No tries<?= $user['role'] === 'teacher' ? ' in your subjects' : '' ?>.</div>
      <?php else: ?>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Exam</th><th>Try</th><th>Started</th><th>Status</th><th class="num">Score</th><th class="num">%</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($s['tries'] as ['exam' => $e, 'attempt' => $a]):
            $idParam = h(rawurlencode($e['id']));
            $done = $a['status'] === 'graded'; ?>
          <tr>
            <td><a class="row-title" href="<?= h(url('teacher/results.php')) ?>?id=<?= $idParam ?>"><?= h($e['title']) ?></a><span class="sub"><?= h(trim($e['subject'] . (($a['student']['class'] ?? '') !== '' ? ' · Class ' . $a['student']['class'] : ''), ' ·')) ?></span></td>
            <td><?= (int)$a['n'] ?></td>
            <td class="nowrap"><?= h($a['started'] ? date('d M Y, g:i A', strtotime($a['started'])) : '') ?></td>
            <td><?= match ($a['status']) {
                'in_progress' => '<span class="badge badge-warn">Not submitted</span>',
                'submitted' => '<span class="badge badge-warn">Needs marking</span>',
                default => '<span class="badge badge-ok">Done</span>',
            } ?><?= attempt_answers_lost($a) ? ' <span class="badge" title="Moodle kept only the marks">Marks only</span>' : '' ?></td>
            <td class="num nowrap"><?= $a['score'] !== null ? $fmt($a['score']) . ' / ' . $fmt($a['max']) : '' ?></td>
            <td class="num"><?= $done && $a['max'] > 0 ? round(100 * $a['score'] / $a['max']) . '%' : '' ?></td>
            <td class="nowrap">
              <a class="btn btn-sm" href="<?= h(url('teacher/mark.php')) ?>?id=<?= $idParam ?>&amp;user=<?= h(rawurlencode($username)) ?>&amp;n=<?= (int)$a['n'] ?>"><?= $a['status'] === 'submitted' ? 'Mark' : 'View' ?></a>
              <?php if ($a['status'] !== 'in_progress'): ?><a class="btn btn-sm btn-icon btn-ghost" target="_blank" title="Result sheet of this try" href="<?= h(url('teacher/result_sheets.php')) ?>?id=<?= $idParam ?>&amp;users=<?= h(rawurlencode($username)) ?>&amp;try=<?= (int)$a['n'] ?>"><?= icon('printer', 16) ?></a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$classFilter = (string)($_GET['class'] ?? '');
$statusFilter = in_array($_GET['status'] ?? '', ['current', 'left'], true) ? $_GET['status'] : '';
$classes = array_values(array_unique(array_filter(array_column($directory, 'class'), fn($c) => $c !== '')));
natsort($classes);
$left = count(array_filter($directory, fn($s) => $s['status'] !== 'current'));
$shown = array_filter($directory, function ($s) use ($search, $classFilter, $statusFilter) {
    if ($search !== '' && mb_stripos($s['name'] . ' ' . $s['username'], $search) === false) {
        return false;
    }
    if ($classFilter !== '' && $s['class'] !== $classFilter) {
        return false;
    }
    return $statusFilter === '' || ($statusFilter === 'current' ? $s['status'] === 'current' : $s['status'] !== 'current');
});

page_start('Students', $user, ['active' => 'students', 'css' => ['assets/css/teacher.css']]);
page_head('Students', [
    'meta' => [count($directory) - $left . ' in users.csv', $left . ' left school or without an account',
        $user['role'] === 'teacher' ? 'tries in your subjects' : ''],
]);
?>
<form class="toolbar" method="get">
  <label class="search-field"><?= icon('search', 16) ?><input type="search" name="q" value="<?= h($search) ?>" placeholder="Search names and usernames" aria-label="Search"></label>
  <select name="class" aria-label="Class">
    <option value="">All classes</option>
    <?php foreach ($classes as $c): ?><option value="<?= h($c) ?>" <?= $c === $classFilter ? 'selected' : '' ?>>Class <?= h($c) ?></option><?php endforeach; ?>
  </select>
  <select name="status" aria-label="Status">
    <option value="">Everyone</option>
    <option value="current" <?= $statusFilter === 'current' ? 'selected' : '' ?>>In users.csv</option>
    <option value="left" <?= $statusFilter === 'left' ? 'selected' : '' ?>>Left school</option>
  </select>
  <button class="btn" type="submit">Show</button>
</form>

<div class="card flush">
  <?php if (!$shown): ?>
    <div class="empty"><span class="empty-icon"><?= icon('users', 40) ?></span>No students match.</div>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Student</th><th>Class</th><th class="num">Tries</th><th>Last try</th></tr></thead>
    <tbody>
    <?php foreach ($shown as $s): $last = $s['tries'][0] ?? null; ?>
      <tr>
        <td><a class="row-title" href="?user=<?= h(rawurlencode($s['username'])) ?>"><?= h($s['name']) ?></a> <?= $statusBadge($s) ?><?= $s['note'] !== '' ? ' <span class="badge badge-warn" title="' . h($s['note']) . '">Check username</span>' : '' ?>
          <span class="sub"><?= h($s['username']) ?><?= $s['status'] === 'left' && $s['left_on'] !== '' ? ' · left on ' . h($day($s['left_on'])) : '' ?></span></td>
        <td><?= $s['class'] !== '' ? 'Class ' . h($s['class']) : '' ?></td>
        <td class="num"><?= count($s['tries']) ?: '' ?></td>
        <td><?php if ($last): ?><?= h($last['exam']['title']) ?><span class="sub"><?= h($day($last['attempt']['started'])) ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php page_end();
