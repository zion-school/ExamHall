<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Result sheets to print or save as PDF (A4), for one student, selected students or all.
 * The server builds each student's sheet as blocks (lib/sheets.php); assets/js/sheets.js lays
 * them out on pages and, for double-sided printing, adds a blank page after any student who
 * ends on the front of a sheet, so every student starts on a sheet of their own.
 *   teacher/result_sheets.php?id=<exam>&users=all | users[]=a&users[]=b | users=a&try=2
 *   options: set=1&colour=1&answers=1&comments=1&duplex=1&unsubmitted=1&attachments=1
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exam = load_exam((string)($_GET['id'] ?? ''));
$paper = $exam ? load_paper($exam['id']) : null;
if (!$exam || !$paper || !exam_can_manage($user, $exam)) {
    http_response_code(404);
    exit('Exam not found');
}
$options = sheet_options($_GET);
$usersParam = $_GET['users'] ?? 'all';
$usernames = is_array($usersParam) ? array_values(array_filter(array_map('strval', $usersParam))) : ($usersParam === 'all' ? [] : array_filter(explode(',', (string)$usersParam)));
$onlyTry = count($usernames) === 1 && isset($_GET['try']) ? max(1, (int)$_GET['try']) : null;
$year = preg_match('/^\d{4}-\d\d$/', (string)($_GET['year'] ?? '')) ? (string)$_GET['year'] : null;   // from the results page; "all" = every year
$entries = result_sheet_entries($exam, $paper, $usernames, $options['unsubmitted'], $onlyTry, $year);
$formerCount = count(array_filter($entries, fn($e) => is_former_user($e['student']['username'])));
if ($usernames === [] && !$options['former']) {
    $entries = array_values(array_filter($entries, fn($e) => !is_former_user($e['student']['username'])));
}
$who = $usernames === [] ? 'All students' : (count($entries) === 1 ? $entries[0]['student']['name'] : count($usernames) . ' selected students');
$keep = array_filter(['id' => $exam['id'], 'users' => $usersParam, 'try' => $onlyTry, 'year' => $year]);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($exam['title']) ?> — result sheets</title>
<link rel="stylesheet" href="<?= h(url('assets/css/sheets.css')) ?>?v=<?= @filemtime(APP_DIR . '/assets/css/sheets.css') ?>">
</head>
<body class="<?= $options['colour'] ? 'colour' : 'mono' ?>">
<div class="toolbar">
  <div class="tb-row">
    <a class="tb-btn" href="<?= h(url('teacher/results.php')) ?>?id=<?= h(rawurlencode($exam['id'])) ?>">← Results</a>
    <div class="tb-title"><strong><?= h($exam['title']) ?></strong> · result sheets · <?= h($who) ?></div>
    <span class="tb-status" id="sheet-status">Preparing pages…</span>
    <button class="tb-btn tb-primary" id="sheet-print" type="button" disabled>🖨️ Print or save as PDF</button>
  </div>
  <form class="tb-row tb-options" method="get" id="sheet-options">
    <?php foreach ($keep as $k => $v): ?>
      <?php foreach ((array)$v as $item): ?><input type="hidden" name="<?= h($k) ?><?= is_array($v) ? '[]' : '' ?>" value="<?= h($item) ?>"><?php endforeach; ?>
    <?php endforeach; ?>
    <input type="hidden" name="set" value="1">
    <span class="tb-switch">
      <label><input type="radio" name="colour" value="1" <?= $options['colour'] ? 'checked' : '' ?>> Colour</label>
      <label><input type="radio" name="colour" value="0" <?= $options['colour'] ? '' : 'checked' ?>> Black &amp; white</label>
    </span>
    <label><input type="checkbox" name="answers" value="1" <?= $options['answers'] ? 'checked' : '' ?>> Correct answers</label>
    <label><input type="checkbox" name="comments" value="1" <?= $options['comments'] ? 'checked' : '' ?>> Teacher comments</label>
    <label title="A student who ends on the front of a sheet gets a blank page, so the next student starts on a new sheet"><input type="checkbox" name="duplex" value="1" <?= $options['duplex'] ? 'checked' : '' ?>> Double-sided (blank pages between students)</label>
    <label><input type="checkbox" name="attachments" value="1" <?= $options['attachments'] ? 'checked' : '' ?>> Attachment pages</label>
    <?php if ($onlyTry === null): ?><label><input type="checkbox" name="unsubmitted" value="1" <?= $options['unsubmitted'] ? 'checked' : '' ?>> Include tries not submitted</label><?php endif; ?>
    <?php if ($usernames === [] && $formerCount): ?><label title="Students no longer in users.csv (left school)"><input type="checkbox" name="former" value="1" <?= $options['former'] ? 'checked' : '' ?>> Students who left (<?= $formerCount ?>)</label><?php endif; ?>
  </form>
  <div class="tb-hint">In the print dialog choose <b>Paper A4</b>, <b>Margins: None</b>, <b>Scale 100%</b><?= $options['duplex'] ? ', <b>Two-sided</b>' : '' ?><?= $options['colour'] ? ' and tick <b>Background graphics</b> (under More settings) so the coloured bands print' : '' ?>. To download, choose <b>Save as PDF</b>.</div>
</div>

<?php if (!$entries): ?>
  <div class="empty">No finished tries to print for <?= h(mb_strtolower($who)) ?>.<?= !$options['unsubmitted'] && $onlyTry === null ? ' Tick "Include tries not submitted" to print marks so far.' : '' ?></div>
<?php endif; ?>

<div id="sheet-source" data-duplex="<?= $options['duplex'] ? '1' : '' ?>" data-app="<?= h(cfg('APP_NAME')) ?>">
  <?php foreach ($entries as $entry) {
      echo render_result_sheet($exam, $paper, $entry, $options);
  } ?>
</div>
<div id="sheets"></div>
<script src="<?= h(url('assets/js/sheets.js')) ?>?v=<?= @filemtime(APP_DIR . '/assets/js/sheets.js') ?>"></script>
</body>
</html>
