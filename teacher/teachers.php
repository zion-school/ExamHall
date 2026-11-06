<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Admin: assign teachers to subjects and edit the subject list.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $teachers = load_teachers();

    if ($action === 'save_teacher') {
        $username = trim($_POST['username'] ?? '');
        $subjects = array_values(array_intersect($_POST['subjects'] ?? [], subject_names()));
        if (!find_user($username)) {
            flash('err', "No user named \"$username\" in users.csv.");
        } elseif (in_array($username, cfg('ADMIN_USERS', []), true)) {
            flash('err', "$username is already an admin.");
        } else {
            $teachers[$username] = $subjects;
            save_teachers($teachers);
            flash('ok', display_name($username) . ' saved with ' . (count($subjects) ?: 'no') . ' subject(s).');
        }
    } elseif ($action === 'remove_teacher') {
        $username = $_POST['username'] ?? '';
        unset($teachers[$username]);
        save_teachers($teachers);
        flash('ok', "Removed $username from teachers.");
    } elseif ($action === 'save_subjects') {
        $rows = [];
        foreach ($_POST['subject'] ?? [] as $i => $name) {
            $name = trim($name);
            if ($name !== '') {
                $aliases = implode(';', array_filter(array_map('trim', explode(';', $_POST['aliases'][$i] ?? ''))));
                $rows[] = ['subject' => $name, 'aliases' => $aliases];
            }
        }
        write_csv(subjects_file(), SUBJECTS_HEADER, $rows);
        flash('ok', 'Subjects saved.');
    }
    redirect('teacher/teachers.php');
}

$teachers = load_teachers();
$subjects = load_subjects();
page_start('Teachers', $user, ['active' => 'teachers', 'css' => ['assets/css/teacher.css']]);
page_head('Teachers', ['meta' => ['Teachers can only see question bank folders, exams, grading and rewards of their subjects. Admins (set in config.php) see everything.']]);
?>

<div class="card">
  <h2>Teachers and subjects</h2>
  <?php if (!$subjects): ?>
    <div class="notice notice-warn"><div class="notice-line"><?= icon('alert', 16) ?><span class="notice-text">There are no subjects yet. Add them under <a href="#subjects">Subjects</a> first, then choose each teacher's subjects.</span></div></div>
  <?php endif; ?>
  <?php if (!$teachers): ?>
    <p class="muted">No teachers yet.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Teacher</th><th>Subjects</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($teachers as $username => $mine): ?>
      <tr>
        <td class="top"><strong><?= h(display_name($username)) ?></strong><span class="sub"><?= h($username) ?></span></td>
        <td>
          <form method="post" class="subject-checks">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_teacher">
            <input type="hidden" name="username" value="<?= h($username) ?>">
            <?php foreach ($subjects as $s): ?>
              <label class="check"><input type="checkbox" name="subjects[]" value="<?= h($s['subject']) ?>" <?= in_array($s['subject'], $mine, true) ? 'checked' : '' ?>> <?= h($s['subject']) ?></label>
            <?php endforeach; ?>
            <button class="btn btn-sm" type="submit"><?= icon('save', 16) ?>Save</button>
          </form>
        </td>
        <td class="top">
          <form method="post" class="row-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_teacher">
            <input type="hidden" name="username" value="<?= h($username) ?>">
            <button class="btn btn-sm btn-ghost btn-danger" type="submit" data-confirm="Click again to remove"><?= icon('trash', 16) ?>Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

  <h3 class="mt-16">Add a teacher</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_teacher">
    <div class="field field-narrow"><span id="new-teacher-label">User (from users.csv)</span>
      <?= user_picker('username', '', array_filter(load_users(), fn($u) => !isset($teachers[$u['username']]) && !in_array($u['username'], cfg('ADMIN_USERS', []), true)), 'one',
          ['aria-labelledby' => 'new-teacher-label', 'placeholder' => 'Type a name or username', 'required' => true]) ?>
    </div>
    <div class="subject-checks mb-12">
      <?php foreach ($subjects as $s): ?>
        <label class="check"><input type="checkbox" name="subjects[]" value="<?= h($s['subject']) ?>"> <?= h($s['subject']) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Add teacher</button>
  </form>
</div>

<div class="card" id="subjects">
  <h2>Subjects</h2>
  <p class="muted small">Every question bank folder, exam and teacher belongs to a subject. Aliases are other names for the same subject: question bank folder names (e.g. <code>Social-Science</code>) and Moodle course codes without the class number (e.g. <code>SSC</code> for <code>SSC5</code>). An alias matches a whole name only, so <code>SC</code> does not match <code>SSC</code>. Separate them with <code>;</code>.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_subjects">
    <table class="table">
      <thead><tr><th class="w-30">Subject</th><th>Aliases</th></tr></thead>
      <tbody>
      <?php foreach (array_merge($subjects, array_fill(0, $subjects ? 1 : 4, ['subject' => '', 'aliases' => []])) as $s): ?>
        <tr>
          <td><input type="text" name="subject[]" value="<?= h($s['subject']) ?>" placeholder="New subject"></td>
          <td><input type="text" name="aliases[]" value="<?= h(implode(';', $s['aliases'])) ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <button class="btn btn-primary mt-12" type="submit"><?= icon('save') ?>Save subjects</button>
  </form>
</div>
<?php page_end(['js' => ['assets/js/user_picker.js']]);
