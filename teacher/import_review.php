<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Import preview: what will be added where, problems per file and question,
 * duplicates and pictures. Confirm to write the questions into the bank.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$id = (string)($_GET['id'] ?? '');
$meta = import_meta($id);
if (!import_can_access($user, $meta)) {
    http_response_code(404);
    exit('Import not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    set_time_limit(600);
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'analyze') {
            import_set_options($id, (string)($_POST['base'] ?? $meta['base']), (string)($_POST['mode'] ?? $meta['mode']), !empty($_POST['strip']));
            import_analyze($id, $user);
            flash('ok', 'Checked again with the new settings.');
        } elseif ($action === 'commit') {
            $skip = array_filter(explode(',', (string)($_POST['skip'] ?? '')), 'is_numeric');
            $result = import_commit($id, $user, (string)($_POST['duplicates'] ?? 'skip'), $skip);
            flash('ok', "Added {$result['added']} question(s)" . ($result['updated'] ? ", updated {$result['updated']}" : '')
                . ($result['skipped'] ? ", skipped {$result['skipped']}" : '') . ($result['pictures'] ? ", {$result['pictures']} picture(s)" : '') . '.');
            $first = $result['categories'][0] ?? '';
            redirect('teacher/bank.php' . ($first !== '' ? '?c=' . rawurlencode($first) : ''));
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    redirect('teacher/import_review.php?id=' . rawurlencode($id));
}

$report = read_json(import_dir($id) . '/report.json');
$meta = import_meta($id);
$committed = $meta['status'] === 'committed';

page_start('Import preview', $user, ['active' => 'bank', 'css' => ['assets/css/teacher.css']]);
page_head('Import preview', [
    'back' => ['Import questions', 'teacher/import.php'],
    'meta' => ['<code>' . h($id) . '</code>', (int)$meta['files'] . ' file(s) uploaded by ' . h(display_name($meta['created_by']))],
    'actions' => '<a class="btn" href="' . h(url('teacher/import.php')) . '">' . icon('upload') . 'New import</a>',
]);
?>

<?php if ($committed): ?>
  <div class="alert alert-ok">This import was added to the bank on <?= h(date('d M Y, h:i A', strtotime($meta['committed']))) ?>:
    <?= (int)$meta['result']['added'] ?> added, <?= (int)$meta['result']['updated'] ?> updated, <?= (int)$meta['result']['skipped'] ?> skipped.</div>
<?php endif; ?>

<?php if (!$report): ?>
  <div class="card">
    <p>The upload has not been checked yet.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="analyze">
      <input type="hidden" name="base" value="<?= h($meta['base']) ?>"><input type="hidden" name="mode" value="<?= h($meta['mode']) ?>">
      <?php if (!empty($meta['strip_top'])): ?><input type="hidden" name="strip" value="1"><?php endif; ?>
      <button class="btn btn-primary">Check the questions</button></form>
  </div>
<?php page_end(); exit; endif; ?>

<?php $t = $report['totals']; ?>
<div class="card summary summary-6">
  <div class="summary-cell"><span><b><?= $t['questions'] ?></b><span>questions found</span></span></div>
  <div class="summary-cell <?= $t['importable'] ? 'is-ok' : 'is-zero' ?>"><span><b><?= $t['importable'] ?></b><span>can be added</span></span></div>
  <div class="summary-cell <?= $t['duplicates'] ? 'is-warn' : 'is-zero' ?>"><span><b><?= $t['duplicates'] ?></b><span>already in the bank</span></span></div>
  <div class="summary-cell <?= $t['blocked'] ? 'is-err' : 'is-zero' ?>"><span><b><?= $t['blocked'] ?></b><span>not allowed (subject)</span></span></div>
  <div class="summary-cell <?= $t['errors'] ? 'is-err' : 'is-zero' ?>"><span><b><?= $t['errors'] ?></b><span>could not be read</span></span></div>
  <div class="summary-cell <?= $t['warnings'] ? 'is-warn' : 'is-zero' ?>"><span><b><?= $t['warnings'] ?></b><span>warnings</span></span></div>
</div>

<div class="grid grid-2 align-start">
  <div class="card">
    <h2>Bank folders</h2>
    <?php if (!$report['categories']): ?><p class="muted">No questions found.</p><?php endif; ?>
    <ul class="qlist folder-list">
      <?php foreach ($report['categories'] as $cat => $n):
          $subject = subject_from_path($cat);
          $ok = can_manage_subject($user, $subject); ?>
        <li class="qrow"><div class="qrow-head">
          <?= $ok ? icon('folder', 16) : str_replace('class="ic"', 'class="ic blocked"', icon('stop', 16)) ?>
          <span class="qrow-name mono" title="<?= $ok ? '' : 'Not one of your subjects' ?>"><?= h($cat) ?></span>
          <span class="chip"><?= h($subject ?? 'no subject') ?></span>
          <span class="count"><?= (int)$n ?></span>
        </div></li>
      <?php endforeach; ?>
    </ul>
    <div class="chips mt-8"><span class="small muted">Types:</span>
      <?php foreach ($report['types'] as $type => $n): ?><span class="chip t-<?= h($type) ?>"><?= h(question_type_label($type)) ?> <?= (int)$n ?></span><?php endforeach; ?></div>
  </div>

  <div class="card">
    <h2>Change where they go</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="analyze">
      <label class="field"><span>Inside bank folder</span>
        <input type="text" name="base" value="<?= h($report['base']) ?>" placeholder="e.g. V/Term-2/Maths" <?= $committed ? 'disabled' : '' ?>></label>
      <label class="check"><input type="radio" name="mode" value="folders" <?= $report['mode'] === 'folders' ? 'checked' : '' ?> <?= $committed ? 'disabled' : '' ?>> Folder and file names</label>
      <label class="check"><input type="radio" name="mode" value="gift" <?= $report['mode'] === 'gift' ? 'checked' : '' ?> <?= $committed ? 'disabled' : '' ?>> <code>$CATEGORY:</code> lines</label>
      <label class="check"><input type="checkbox" name="strip" value="1" <?= !empty($meta['strip_top']) ? 'checked' : '' ?> <?= $committed ? 'disabled' : '' ?>> Leave out the top folder name</label>
      <button class="btn" <?= $committed ? 'disabled' : '' ?>><?= icon('refresh') ?>Check again</button>
    </form>
    <?php if ($report['unused_pictures']): ?>
      <h3 class="mt-16">Pictures no question uses (<?= count($report['unused_pictures']) ?>)</h3>
      <ul class="msgs"><?php foreach (array_slice($report['unused_pictures'], 0, 30) as $p): ?><li class="msg-info"><code><?= h($p) ?></code></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
</div>

<form method="post" id="commit-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="commit">
  <input type="hidden" name="skip" id="skip-keys" value="">

  <h2 class="mt-8">Files</h2>
  <?php
  $byFile = [];
  foreach ($report['questions'] as $entry) {
      $byFile[$entry['file']][] = $entry;
  }
  foreach ($report['files'] as $file):
      $entries = $byFile[$file['path']] ?? [];
      $errors = count(array_filter($file['messages'], fn($m) => $m['level'] === 'error'));
      $warnings = count(array_filter(array_merge($file['messages'], ...array_map(fn($e) => $e['messages'], $entries ?: [['messages' => []]])), fn($m) => $m['level'] === 'warning'));
      $problems = count(array_filter($entries, fn($e) => $e['problems']));
      ?>
    <details class="file" <?= ($errors || $problems) && count($report['files']) < 20 ? 'open' : '' ?>>
      <summary>
        <?= icon('file', 16) ?><span class="path"><?= h($file['path']) ?></span>
        <span class="chip"><?= count($entries) ?> questions</span>
        <?php if ($errors): ?><span class="badge badge-err"><?= $errors ?> error</span><?php endif; ?>
        <?php if ($problems): ?><span class="badge badge-err"><?= $problems ?> blocked</span><?php endif; ?>
        <?php if ($warnings): ?><span class="badge badge-warn"><?= $warnings ?> warning</span><?php endif; ?>
      </summary>
      <div class="file-body">
        <?php if ($file['messages']): ?>
          <ul class="msgs"><?php foreach ($file['messages'] as $m): ?><li class="msg-<?= h($m['level']) ?>">Line <?= (int)$m['line'] ?>: <?= h($m['message']) ?><?php if ($m['excerpt'] !== ''): ?> <span class="muted">— <?= h(mb_substr($m['excerpt'], 0, 90)) ?></span><?php endif; ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($entries): ?>
        <div class="select-all-row"><label class="check"><input type="checkbox" class="select-all" checked <?= $committed ? 'disabled' : '' ?>> Select all in this file</label></div>
        <ul class="qlist">
          <?php foreach ($entries as $e): ?>
            <li class="qrow">
              <div class="qrow-head">
                <input type="checkbox" class="q-pick" value="<?= (int)$e['key'] ?>" <?= $e['allowed'] ? 'checked' : 'disabled' ?> <?= $committed ? 'disabled' : '' ?> aria-label="Add this question">
                <?= preview_toggle(['import' => $id, 'key' => $e['key']]) ?>
                <span class="chip type-badge t-<?= h($e['type']) ?>"><?= h(question_type_label($e['type'])) ?></span>
                <span class="qrow-name" title="<?= h($e['name']) ?>"><?= h($e['name']) ?></span>
                <?php if ($e['duplicate_of']): ?><span class="badge badge-warn" title="Same as <?= h(question_label(bank_index()[$e['duplicate_of']] ?? [])) ?>">In bank</span><?php endif; ?>
                <span class="qrow-label">line <?= (int)$e['line'] ?></span>
              </div>
              <div class="qrow-body">
                <span class="small muted with-icon"><?= icon('arrow-right', 14) ?><span class="mono"><?= h($e['category']) ?></span></span>
                <?php if ($e['problems'] || $e['messages']): ?>
                  <ul class="msgs">
                    <?php foreach ($e['problems'] as $p): ?><li class="msg-problem"><?= h($p) ?></li><?php endforeach; ?>
                    <?php foreach ($e['messages'] as $m): ?><li class="msg-<?= h($m['level']) ?>"><?= h($m['message']) ?></li><?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>

  <?php if (!$committed): ?>
  <div class="card sticky-actions">
    <div class="row">
      <label class="row row-tight small">Questions already in the bank:
        <select name="duplicates" class="w-auto">
          <option value="skip">Skip them</option>
          <option value="replace">Replace them</option>
          <option value="keep">Add them again</option>
        </select>
      </label>
      <span class="spacer"></span>
      <button class="btn btn-primary" id="commit-btn" <?= $t['importable'] ? '' : 'disabled' ?>><?= icon('plus') ?><span>Add <span id="commit-count"><?= $t['importable'] ?></span> questions to the bank</span></button>
    </div>
  </div>
  <?php endif; ?>
</form>
<?php page_end(['js' => ['assets/js/teacher.js']]);
