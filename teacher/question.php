<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Create or edit one question. The question is written in GIFT with a live preview;
 * things GIFT cannot hold (reference answer for essays, default mark, folder, hidden)
 * have their own fields.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$id = (string)($_GET['id'] ?? '');
$q = $id !== '' ? load_question($id) : null;
if ($id !== '' && (!$q || !can_manage_subject($user, subject_from_path($q['category'])))) {
    http_response_code(404);
    exit('Question not found');
}
$isNew = $q === null;
$category = $isNew ? normalize_category((string)($_GET['c'] ?? '')) : $q['category'];

/** GIFT source for editing: settings with their own fields (mark, hidden, key) and the Moodle entry stay out of it. */
function editable_gift(array $q): string
{
    return GiftWriter::question($q, ['header' => false, 'meta' => ['case', 'noshuffle', 'single']]);
}

$giftText = $isNew ? "// [tag:Choose]\n::Question name::\nQuestion text\n{\n=Right answer\n~Wrong answer\n~Wrong answer\n}\n" : editable_gift($q);
$errors = [];
$form = [
    'category' => $category,
    'grader_info' => $q['grader_info'] ?? '',
    'default_mark' => $q['default_mark'] ?? 1,
    'hidden' => !empty($q['hidden']),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $giftText = (string)($_POST['gift'] ?? '');
    $form['category'] = normalize_category((string)($_POST['category'] ?? ''));
    $form['grader_info'] = trim((string)($_POST['grader_info'] ?? ''));
    $form['default_mark'] = max(0, (float)($_POST['default_mark'] ?? 1));
    $form['hidden'] = !empty($_POST['hidden']);

    $parsed = (new GiftParser())->parse($giftText, 'editor');
    foreach ($parsed['messages'] as $m) {
        if ($m['level'] === 'error') {
            $errors[] = "Line {$m['line']}: {$m['message']}";
        }
    }
    if (!$errors && count($parsed['questions']) !== 1) {
        $errors[] = count($parsed['questions']) === 0 ? 'No question found in the GIFT text.' : 'Write only one question here (found ' . count($parsed['questions']) . '). Use Import for several.';
    }
    if ($form['category'] === '') {
        $errors[] = 'Choose a bank folder.';
    } elseif (!can_manage_subject($user, subject_from_path($form['category']))) {
        $errors[] = 'You can only save questions in folders of your subjects (the folder path must name the subject, e.g. V/Maths/Chapter-1).';
    }

    if (!$errors) {
        $new = $parsed['questions'][0];
        unset($new['category'], $new['section']);
        if ($new['type'] === 'essay') {
            $new['grader_info'] = $form['grader_info'];
        }
        $new['default_mark'] = $new['type'] === 'multiblank' && empty($_POST['default_mark_changed'])
            ? $new['default_mark'] : $form['default_mark'];
        $new['hidden'] = $form['hidden'];
        if ($isNew) {
            $saved = save_question($new, $form['category']);
        } else {
            $new['id'] = $q['id'];
            $saved = save_question($new, $q['category']);
            if ($form['category'] !== $q['category']) {
                $saved = move_question($q['id'], $form['category']);
            }
        }
        flash('ok', 'Saved ' . question_label($saved) . '.');
        redirect('teacher/question.php?id=' . rawurlencode($saved['id']));
    }
}

$folders = array_keys(category_tree($user));
page_start($isNew ? 'New question' : 'Edit question', $user, ['active' => 'bank', 'css' => ['assets/css/teacher.css']]);
page_head($isNew ? 'New question' : $q['name'], [
    'back' => ['Question bank' . ($category !== '' ? ' / ' . $category : ''), 'teacher/bank.php' . ($category !== '' ? '?c=' . rawurlencode($category) : '')],
    'meta' => $isNew ? [] : [h(question_label($q)), h(question_type_label($q['type']))],
]);
?>

<?php foreach ($errors as $e): ?><div class="alert alert-err"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" id="question-form">
  <?= csrf_field() ?>
  <div class="editor-grid">
    <div class="card">
      <label class="field"><span>Question in GIFT · <a href="<?= h(url('docs/gift.md')) ?>" target="_blank">format guide</a></span>
        <textarea name="gift" id="gift" class="code" spellcheck="false" rows="16"><?= h($giftText) ?></textarea>
        <span class="hint">Tags go in a comment line: <code>// [tag:Fill up] [tag:Weight]</code>. Escape <code>= ~ # { } :</code> with a backslash.</span>
      </label>
      <div class="grid grid-2">
        <label class="field"><span>Bank folder</span>
          <input type="text" name="category" value="<?= h($form['category']) ?>" list="bank-folders" required placeholder="e.g. V/Term-2/Maths/04_Measurements">
          <datalist id="bank-folders"><?php foreach ($folders as $f): ?><option value="<?= h($f) ?>"><?php endforeach; ?></datalist>
        </label>
        <label class="field"><span>Default mark</span>
          <input type="number" name="default_mark" value="<?= h($form['default_mark']) ?>" min="0" step="0.5" oninput="this.form.default_mark_changed.value='1'">
          <input type="hidden" name="default_mark_changed" value="">
          <span class="hint">Multi-blank questions default to one mark per blank.</span>
        </label>
      </div>
      <label class="field" id="grader-info-field"><span>Reference answer / key points (essays)</span>
        <textarea name="grader_info" rows="4" placeholder="What a good answer must contain. Used by teachers and by the LLM when suggesting marks."><?= h($form['grader_info']) ?></textarea>
      </label>
      <label class="check"><input type="checkbox" name="hidden" value="1" <?= $form['hidden'] ? 'checked' : '' ?>> Hidden (not offered when building exams)</label>
      <div class="row mt-12">
        <button class="btn btn-primary" type="submit"><?= icon('save') ?>Save</button>
        <a class="btn btn-ghost" href="<?= h(url('teacher/bank.php')) ?><?= $category !== '' ? '?c=' . h(rawurlencode($category)) : '' ?>">Cancel</a>
      </div>
    </div>

    <div class="card preview-pane">
      <h2>Preview</h2>
      <ul class="msgs" id="preview-messages"></ul>
      <div id="preview"><?= $q ? render_question_preview($q, bank_media_url($q['category'])) : '' ?></div>
    </div>
  </div>
</form>

<?php page_end(['js' => ['assets/js/teacher.js', 'assets/js/question.js']]);
