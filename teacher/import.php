<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Import GIFT questions: a folder, a ZIP file or a single .gift file (with pictures).
 * Uploading is done by assets/js/import.js; the preview is teacher/import_review.php.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();

function ini_bytes(string $value): int
{
    $value = trim($value);
    $n = (int)$value;
    return match (strtolower(substr($value, -1))) {
        'g' => $n << 30,
        'm' => $n << 20,
        'k' => $n << 10,
        default => $n,
    };
}

$uploadMax = ini_bytes(ini_get('upload_max_filesize') ?: '2M');
$postMax = ini_bytes(ini_get('post_max_size') ?: '8M') ?: PHP_INT_MAX;
$chunkBytes = max(256 << 10, min(8 << 20, (int)(min($uploadMax, $postMax) * 0.8)));
$batchFiles = max(1, min(15, (int)ini_get('max_file_uploads') - 1));

$categories = array_keys(category_tree($user));
$recent = [];
foreach (array_reverse(list_dirs(data_path('imports'))) as $dir) {
    $meta = read_json(data_path('imports', $dir, 'meta.json'));
    if ($meta && import_can_access($user, $meta)) {
        $recent[] = $meta;
    }
    if (count($recent) >= 8) {
        break;
    }
}
$base = normalize_category((string)($_GET['base'] ?? ''));

page_start('Import questions', $user, ['active' => 'bank', 'css' => ['assets/css/teacher.css']]);
page_head('Import questions', [
    'back' => ['Question bank', 'teacher/bank.php'],
    'meta' => ['Moodle GIFT files, with their pictures. You will see a preview before anything is added.',
        '<a href="' . h(url('docs/gift.md')) . '" target="_blank">GIFT format guide</a>'],
]);
?>

<form id="import-form" class="card" data-chunk="<?= $chunkBytes ?>" data-batch-files="<?= $batchFiles ?>" data-batch-bytes="<?= $chunkBytes ?>">
  <div class="field-label">1. What are you uploading?</div>
  <div class="source-tabs">
    <label><input type="radio" name="source" value="folder" checked><?= icon('folder') ?>A folder</label>
    <label><input type="radio" name="source" value="zip"><?= icon('archive') ?>A ZIP file</label>
    <label><input type="radio" name="source" value="gift"><?= icon('file') ?>GIFT file(s)</label>
  </div>

  <input type="file" id="pick-folder" webkitdirectory directory multiple hidden>
  <input type="file" id="pick-zip" accept=".zip" hidden>
  <input type="file" id="pick-gift" accept=".gift,.txt,.png,.jpg,.jpeg,.gif,.webp,.svg" multiple hidden>

  <div class="dropzone" id="dropzone" tabindex="0" role="button">
    <span class="big"><?= icon('upload', 32) ?></span>
    <strong id="drop-title">Click to choose a folder, or drop it here</strong>
    <span class="muted small">GIFT files (.gift, .txt) and pictures (png, jpg, gif, webp, svg). Other files are ignored.</span>
    <div class="picked" id="picked"></div>
  </div>

  <div class="grid grid-2 mt-16">
    <div>
      <div class="field-label">2. Where should the questions go?</div>
      <label class="field"><span>Put everything inside this bank folder (optional)</span>
        <input type="text" name="base" value="<?= h($base) ?>" list="bank-folders" placeholder="e.g. V/Term-2/Maths">
        <span class="hint">The folders and file names you upload are added below this. Folder names tell the subject.</span>
      </label>
      <datalist id="bank-folders">
        <?php foreach ($categories as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?>
      </datalist>
    </div>
    <div>
      <div class="field-label">&nbsp;</div>
      <label class="check"><input type="radio" name="mode" value="folders" checked> Name bank folders after my <b>folders and file names</b> (recommended)</label>
      <label class="check"><input type="radio" name="mode" value="gift"> Use the <code>$CATEGORY:</code> lines inside the files</label>
      <label class="check" id="strip-label"><input type="checkbox" name="strip" value="1" checked> Leave out the name of the top folder (e.g. <code>question_bank/</code>)</label>
    </div>
  </div>

  <div id="upload-status" class="mt-12" hidden>
    <div class="progress"><div id="progress-bar"></div></div>
    <p class="small muted mt-4" id="progress-text"></p>
    <ul class="msgs" id="upload-errors"></ul>
  </div>

  <div class="row mt-16">
    <button class="btn btn-primary" type="submit" id="start" disabled><?= icon('upload') ?>Upload and preview</button>
    <span class="muted small">Nothing is added to the bank until you confirm on the next page.</span>
  </div>
</form>

<?php if ($recent): ?>
<div class="card flush">
  <div class="card-head"><h2>Recent imports</h2></div>
  <table class="table">
    <thead><tr><th>Import</th><th>By</th><th>Status</th><th class="num">Files</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $m): ?>
      <tr>
        <td><code><?= h($m['id']) ?></code></td>
        <td><?= h(display_name($m['created_by'])) ?></td>
        <td>
          <?php if ($m['status'] === 'committed'): ?>
            <span class="badge badge-ok">Added <?= (int)($m['result']['added'] ?? 0) + (int)($m['result']['updated'] ?? 0) ?></span>
          <?php elseif ($m['status'] === 'analyzed'): ?>
            <span class="badge badge-warn">Waiting for review</span>
          <?php else: ?>
            <span class="badge">Not finished</span>
          <?php endif; ?>
        </td>
        <td class="num"><?= (int)$m['files'] ?></td>
        <td class="nowrap"><?php if ($m['status'] !== 'uploading'): ?><a href="<?= h(url('teacher/import_review.php')) ?>?id=<?= h(rawurlencode($m['id'])) ?>">Open</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php page_end(['js' => ['assets/js/import.js']]);
