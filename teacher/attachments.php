<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Attachments of an exam: photos or PDFs per student, printed after each student's
 * result sheet. Upload for one student, or many files at once (matched to students by file
 * name, checked before uploading). Pages can be reordered or removed.
 * Uploads go through api/attachment_upload.php (assets/js/attachments.js).
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exam = load_exam((string)($_GET['id'] ?? ''));
if (!$exam || !exam_can_manage($user, $exam)) {
    http_response_code(404);
    exit('Exam not found');
}
$self = 'teacher/attachments.php?id=' . rawurlencode($exam['id']);
$students = attachment_students($exam);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $who = (string)($_POST['user'] ?? '');
    if (isset($students[$who])) {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'clear') {
            attachment_clear($exam['id'], $who);
            flash('ok', 'Attached pages of ' . $students[$who]['name'] . ' removed.');
        } elseif (in_array($action, ['up', 'down', 'delete'], true)) {
            attachment_change($exam['id'], $who, basename((string)($_POST['file'] ?? '')), $action);
        }
    }
    redirect($self . '#u-' . rawurlencode($who));
}

$summary = attachment_summary($exam['id']);
page_start('Attachments', $user, ['active' => 'exams', 'css' => ['assets/css/teacher.css']]);
page_head('Attachments', [
    'back' => ['Results of ' . $exam['title'], 'teacher/results.php?id=' . rawurlencode($exam['id'])],
    'meta' => [array_sum($summary) . ' page(s) for ' . count($summary) . ' of ' . count($students) . " students. They are printed after each student's result sheet."],
    'actions' => '<a class="btn btn-primary" target="_blank" href="' . h(url('teacher/result_sheets.php')) . '?id=' . h(rawurlencode($exam['id'])) . '&amp;users=all">' . icon('printer') . 'Print result sheets</a>',
]);
?>

<div id="attachments" data-exam="<?= h($exam['id']) ?>" data-students="<?= h(json_encode(array_map(fn($s) => ['username' => $s['username'], 'name' => $s['name'], 'class' => $s['class']], array_values($students)), JSON_UNESCAPED_UNICODE)) ?>">

<div class="card">
  <h2>Add files for many students</h2>
  <p class="small muted">Choose all the files at once. Each file is matched to a student by the name in the file name (e.g. <code>Aril.pdf</code>, <code>aril-page2.jpg</code>); check the matches, then upload.
    Photos (JPG, PNG) and PDFs work. <?= pdftoppm_available() ? 'Each PDF page becomes one printed page.' : '<strong>This server cannot read PDFs (pdftoppm is not installed): upload photos, or install poppler-utils.</strong>' ?>
    Save other documents as PDF first.</p>
  <input type="file" id="bulk-files" multiple accept=".pdf,image/png,image/jpeg,image/webp">
  <div id="bulk-review" hidden>
    <div class="table-wrap mt-8"><table class="table">
      <thead><tr><th>File</th><th>Student</th><th>Status</th></tr></thead>
      <tbody></tbody>
    </table></div>
    <div class="row mt-12">
      <button class="btn btn-primary" type="button" id="bulk-upload"><?= icon('upload') ?>Upload</button>
      <span class="small muted" id="bulk-note"></span>
    </div>
  </div>
</div>

<div class="card">
  <h2>Students</h2>
  <ul class="qlist">
    <?php foreach ($students as $username => $s):
        $pages = attachment_pages($exam['id'], $username); ?>
      <li class="qrow" id="u-<?= h($username) ?>">
        <div class="qrow-head">
          <span class="qrow-name"><strong><?= h($s['name']) ?></strong> <span class="small muted"><?= h($username) ?><?= $s['class'] !== '' ? ' · Class ' . h($s['class']) : '' ?></span></span>
          <span class="chip"><?= count($pages) ?> page<?= count($pages) === 1 ? '' : 's' ?></span>
          <label class="btn btn-sm"><?= icon('plus', 16) ?>Add pages<input type="file" class="add-files" data-user="<?= h($username) ?>" multiple accept=".pdf,image/png,image/jpeg,image/webp" hidden></label>
          <?php if ($pages): ?>
            <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="user" value="<?= h($username) ?>">
              <button class="btn btn-sm btn-ghost btn-danger" name="action" value="clear" data-confirm="Click again to remove all"><?= icon('trash', 16) ?>Remove all</button></form>
          <?php endif; ?>
          <span class="small muted upload-status" data-user="<?= h($username) ?>"></span>
        </div>
        <?php if ($pages): ?>
          <div class="attachment-pages">
            <?php foreach ($pages as $i => $file): ?>
              <figure class="attachment-thumb">
                <a href="<?= h(attachment_page_url($exam['id'], $username, $file)) ?>" target="_blank"><img src="<?= h(attachment_page_url($exam['id'], $username, $file)) ?>" alt="Page <?= $i + 1 ?>" loading="lazy"></a>
                <figcaption>
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="user" value="<?= h($username) ?>"><input type="hidden" name="file" value="<?= h($file) ?>">
                    <span class="small nowrap">p. <?= $i + 1 ?></span>
                    <?php if ($i > 0): ?><button class="btn btn-sm btn-icon btn-ghost" name="action" value="up" title="Move earlier"><?= icon('chevron-left', 16) ?></button><?php endif; ?>
                    <?php if ($i < count($pages) - 1): ?><button class="btn btn-sm btn-icon btn-ghost" name="action" value="down" title="Move later"><?= icon('chevron-right', 16) ?></button><?php endif; ?>
                    <button class="btn btn-sm btn-icon btn-ghost btn-danger" name="action" value="delete" title="Remove this page" data-confirm="Remove?"><?= icon('x', 16) ?></button>
                  </form>
                </figcaption>
              </figure>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
</div>
<?php page_end(['js' => ['assets/js/attachments.js']]);
