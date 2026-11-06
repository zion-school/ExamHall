<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Exam editor: who, questions (fixed and random picks in sections), schedule and timing
 * (also each student's own times), review, grading and reward settings;
 * publish / hide / close / delete. Preview (teacher/preview.php) saves a draft first; a published
 * exam is previewed as students get it, without saving.
 * The question sections are edited by assets/js/exam_edit.js and sent as JSON.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exam = load_exam((string)($_GET['id'] ?? ''));
if (!$exam || !exam_can_manage($user, $exam)) {
    http_response_code(404);
    exit('Exam not found');
}
$self = 'teacher/exam_edit.php?id=' . rawurlencode($exam['id']);
$hasAttempts = exam_has_attempts($exam['id']);
$subjects = $user['role'] === 'admin' ? subject_names() : $user['subjects'];

/**
 * Validate the sections JSON from the editor. A question keeps the copy the exam already has,
 * unless the teacher chose its new version from the bank ("refresh"); a question added now is
 * copied from the bank. A matching question's "pairs" are the pairs the teacher chose for the exam
 * (edit_match_pairs); they change the copy.
 */
function clean_sections($data, array $user, array $exam): array
{
    $copies = [];
    foreach ($exam['sections'] as $section) {
        foreach ($section['items'] as $item) {
            if (isset($item['q'], $item['copy'])) {
                $copies[$item['q']] = $item['copy'];
            }
        }
    }
    $copied = [];
    $out = [];
    foreach (is_array($data) ? $data : [] as $section) {
        $items = [];
        foreach ((array)($section['items'] ?? []) as $item) {
            $mark = max(0.0, min(100.0, (float)($item['mark'] ?? 1)));
            if (isset($item['q']) && is_string($item['q'])) {
                $key = $item['q'];
                $copy = empty($item['refresh']) ? ($copies[$key] ?? null) : null;
                if ($copy === null) {
                    $q = load_question($key) ?? (isset($copies[$key]) ? bank_question_for($copies[$key]) : null);
                    if ($q && can_manage_subject($user, subject_from_path($q['category']))) {
                        $copy = exam_question_copy($exam['id'], $q, $copied);
                        $key = $q['id'];
                    } else {
                        $copy = $copies[$key] ?? null;
                    }
                }
                if ($copy && can_manage_subject($user, subject_from_path($copy['category']))) {
                    if (isset($item['pairs'])) {
                        $copy = edit_match_pairs($copy, $item['pairs']);   // pairs removed or added for this exam (in the version the editor showed)
                    }
                    $items[] = ['q' => $key, 'mark' => $mark, 'copy' => $copy];
                }
            } elseif (isset($item['random']) && is_array($item['random'])) {
                $r = $item['random'];
                $category = normalize_category((string)($r['category'] ?? ''));
                if ($category !== '' && can_manage_subject($user, subject_from_path($category))) {
                    $items[] = ['random' => [
                        'category' => $category, 'sub' => !empty($r['sub']), 'count' => max(1, (int)($r['count'] ?? 1)),
                        'types' => array_values(array_intersect((array)($r['types'] ?? []), ['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'multiblank', 'essay'])),
                        'tags' => array_values(array_filter(array_map('trim', (array)($r['tags'] ?? [])))),
                    ], 'mark' => $mark];
                }
            }
        }
        // "Answer any five": never more than the section offers, and "any all of them" is simply all
        $offered = count(section_question_marks(['items' => $items]));
        $choose = max(0, min($offered, (int)($section['choose'] ?? 0)));
        $out[] = ['title' => mb_substr(trim((string)($section['title'] ?? '')), 0, 120), 'shuffle' => !empty($section['shuffle']),
            'choose' => $choose >= $offered ? 0 : $choose, 'items' => $items];
    }
    return $out ?: [['title' => '', 'shuffle' => true, 'choose' => 0, 'items' => []]];
}

/** "YYYY-MM-DD" + "HH:MM" -> "YYYY-MM-DDTHH:MM" ('' when there is no date). */
function schedule_value(string $date, string $time, string $defaultTime): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return '';
    }
    return $date . 'T' . (preg_match('/^\d{2}:\d{2}$/', $time) ? $time : $defaultTime);
}

/** A datetime-local value, checked. */
function datetime_value(string $value): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) ? $value : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        if ($hasAttempts) {
            flash('err', 'Students have started this exam, so it cannot be deleted. Close it instead.');
            redirect($self);
        }
        delete_tree(exam_dir($exam['id']));
        flash('ok', 'Exam deleted.');
        redirect('teacher/exams.php');
    }
    if ($action === 'close' || $action === 'reopen') {
        $wasPastYear = exam_from_past_year($exam);
        $exam['status'] = $action === 'close' ? 'closed' : 'published';
        if ($action === 'reopen') {
            $exam['reopened'] = now_iso();   // brings an exam from an earlier school year into this one
        }
        save_exam($exam, $user['username']);
        $closeAt = exam_time($exam['close_at']);
        flash('ok', $action === 'close' ? 'Exam closed. Students can no longer start or save.'
            : 'Exam reopened' . ($wasPastYear ? ' for the ' . current_school_year() . ' school year' : '') . '.'
              . ($closeAt !== null && $closeAt <= time() ? ' Its closing time has passed: set new dates so students can start it.' : ''));
        redirect($self);
    }
    if ($action === 'unpublish') {
        if ($hasAttempts) {
            flash('err', 'Students have started this exam, so it cannot go back to draft.');
        } else {
            $exam['status'] = 'draft';
            save_exam($exam, $user['username']);
            flash('ok', 'The exam is a draft again; students cannot see it.');
        }
        redirect($self);
    }

    // Save settings (also before publishing)
    $exam['title'] = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
    if (in_array($_POST['subject'] ?? '', $subjects, true)) {
        $exam['subject'] = $_POST['subject'];
    }
    // A class or student already on the exam stays choosable after they leave school (users.csv no longer has them)
    $exam['classes'] = array_values(array_intersect($_POST['classes'] ?? [], exam_class_choices($exam)));
    $exam['users'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['users'] ?? ''))),
        fn($u) => $u !== '' && (find_user($u) || in_array($u, $exam['users'], true)))));
    $exam['instructions'] = trim((string)($_POST['instructions'] ?? ''));
    $before = [$exam['open_at'], $exam['close_at'], $exam['time_limit_min']];
    $openDate = (string)($_POST['open_date'] ?? '');
    $closeDate = (string)($_POST['close_date'] ?? '') ?: ((string)($_POST['close_time'] ?? '') !== '' ? $openDate : '');
    $exam['open_at'] = schedule_value($openDate, (string)($_POST['open_time'] ?? ''), '00:00');
    $exam['close_at'] = schedule_value($closeDate, (string)($_POST['close_time'] ?? ''), '23:59');
    if (($_POST['close_time'] ?? '') !== '' && $closeDate === '') {
        flash('err', 'Choose a date for the end time.');
    }
    if ($exam['open_at'] !== '' && $exam['close_at'] !== '' && exam_time($exam['close_at']) <= exam_time($exam['open_at'])) {
        flash('err', 'The exam ends before it starts. Check the date and times.');
        $exam['close_at'] = '';
    }
    $exam['time_limit_min'] = max(0, (int)($_POST['time_limit_min'] ?? 0));
    $exam['attempts'] = max(0, (int)($_POST['attempts'] ?? 0));
    $exam['grade_method'] = in_array($_POST['grade_method'] ?? '', ['highest', 'latest', 'first', 'average'], true) ? $_POST['grade_method'] : 'highest';
    $exam['per_page'] = ($_POST['per_page'] ?? '1') === '0' ? 0 : 1;
    $exam['shuffle_questions'] = !empty($_POST['shuffle_questions']);
    $exam['shuffle_choices'] = !empty($_POST['shuffle_choices']);
    $exam['access_code'] = mb_substr(trim((string)($_POST['access_code'] ?? '')), 0, 20);
    $exam['review'] = in_array($_POST['review'] ?? '', ['score', 'after_close', 'immediate'], true) ? $_POST['review'] : 'after_close';
    $bands = [];
    foreach (preg_split('/\R/', (string)($_POST['bands'] ?? '')) as $line) {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*%?\s*[:=]\s*(.+)$/u', $line, $m)) {
            $bands[] = ['min' => (float)$m[1], 'text' => trim($m[2])];
        }
    }
    $exam['bands'] = $bands;
    $exam['grading'] = [
        'llm' => in_array($_POST['llm'] ?? '', ['off', 'suggest', 'auto'], true) ? $_POST['llm'] : 'off',
        'fuzzy' => !empty($_POST['fuzzy']),
        'spelling_penalty' => max(0.0, min(1.0, (float)($_POST['spelling_penalty'] ?? 0) / 100)),
        'ignore_case' => !empty($_POST['ignore_case']),
        'ignore_spaces' => !empty($_POST['ignore_spaces']),
        'ignore_punctuation' => !empty($_POST['ignore_punctuation']),
    ];
    $exam['reward'] = [
        'enabled' => !empty($_POST['reward_enabled']),
        'formula' => trim((string)($_POST['reward_formula'] ?? EXAM_DEFAULTS['reward']['formula'])) ?: EXAM_DEFAULTS['reward']['formula'],
        'max' => max(0, (float)($_POST['reward_max'] ?? 10)),
        'daily_max' => max(0, (float)($_POST['reward_daily_max'] ?? 0)),
        'payout' => in_array($_POST['reward_payout'] ?? '', ['by_question_types', 'auto', 'approve'], true) ? $_POST['reward_payout'] : 'by_question_types',
        'count' => ($_POST['reward_count'] ?? 'best') === 'first' ? 'first' : 'best',
    ];
    if ($err = reward_formula_error($exam['reward']['formula'])) {
        flash('err', 'Reward formula: ' . $err);
    }
    if (!$hasAttempts) {
        $exam['sections'] = clean_sections(json_decode((string)($_POST['sections_json'] ?? '[]'), true), $user, $exam);
    }
    $exam = save_exam($exam, $user['username']);
    if ($before !== [$exam['open_at'], $exam['close_at'], $exam['time_limit_min']] && ($n = refresh_attempt_deadlines($exam))) {
        flash('ok', "$n student(s) writing now got the new end time.");
    }

    if ($action === 'preview') {
        redirect('teacher/preview.php?id=' . rawurlencode($exam['id']));
    }

    if ($action === 'hide' || $action === 'show') {
        $exam['hidden'] = $action === 'hide';
        save_exam($exam, $user['username']);
        flash('ok', $action === 'hide' ? 'Saved and hidden. Students cannot see or start it; their results stay in My results.' : 'Saved. Students can see this exam again.');
        redirect($self);
    }

    // A student's own times (saved together with the rest of the form)
    if ($action === 'override_save') {
        $who = trim((string)($_POST['ov_user'] ?? ''));
        $values = [
            'open_at' => datetime_value((string)($_POST['ov_open'] ?? '')),
            'close_at' => datetime_value((string)($_POST['ov_close'] ?? '')),
            'extra_minutes' => max(0, min(600, (int)($_POST['ov_minutes'] ?? 0))),
            'extra_attempts' => max(0, min(20, (int)($_POST['ov_tries'] ?? 0))),
        ];
        if ($who === '' || (!find_user($who) && !list_attempts($exam['id'], $who))) {
            flash('err', 'Choose a student for the own times.');
        } elseif ($values['open_at'] !== '' && $values['close_at'] !== '' && exam_time($values['close_at']) <= exam_time($values['open_at'])) {
            flash('err', 'The student\'s end time is before the start time.');
        } else {
            set_exam_override($exam['id'], $who, $values);
            refresh_attempt_deadlines($exam, $who);
            flash('ok', display_name($who) . ': ' . schedule_text(exam_window($exam, $who)['open'], exam_window($exam, $who)['close']) . '.');
        }
        redirect($self . '#own-times');
    }
    if (str_starts_with($action, 'override_remove:')) {
        $who = substr($action, strlen('override_remove:'));
        remove_exam_override($exam['id'], $who);
        refresh_attempt_deadlines($exam, $who);
        flash('ok', display_name($who) . ' follows the exam times again.');
        redirect($self . '#own-times');
    }

    if ($action === 'publish') {
        [$exam, $problems] = publish_exam($exam, $user);
        if ($problems) {
            foreach ($problems as $p) {
                flash('err', $p);
            }
        } else {
            flash('ok', $hasAttempts ? 'Settings saved. The questions stay as they were because students have started.' : 'Published! Students of the chosen classes can see it now.');
        }
    } else {
        flash('ok', 'Saved.' . ($exam['status'] === 'published' && !$hasAttempts ? ' Press "Publish" again to update the paper students get.' : ''));
    }
    redirect($self);
}

// The editor gets the questions' names and labels, not the copies; "state" tells whether the bank changed them since
// ("edited": only a matching question's pairs differ)
$names = [];
$editorSections = $exam['sections'];
foreach ($editorSections as $si => $section) {
    foreach ($section['items'] as $ii => $item) {
        if (!isset($item['q'])) {
            continue;
        }
        $q = $item['copy'] ?? load_question($item['q']);
        unset($editorSections[$si]['items'][$ii]['copy']);
        if ($q) {
            $names[$item['q']] = ['name' => html_to_text((string)$q['name']), 'type' => $q['type'], 'category' => $q['category'],
                'label' => question_source_label($q), 'state' => !$hasAttempts && isset($item['copy']) ? exam_copy_state($item['copy']) : 'same'];
        }
    }
}
$paper = load_paper($exam['id']);
$folders = array_keys(category_tree($user));
$bandsText = implode("\n", array_map(fn($b) => rtrim(rtrim(number_format($b['min'], 2), '0'), '.') . ': ' . $b['text'], $exam['bands']));

page_start('Edit exam', $user, ['active' => 'exams', 'css' => ['assets/css/teacher.css', 'assets/css/player.css']]);

$idParam = h(rawurlencode($exam['id']));
$pastYear = exam_from_past_year($exam);
$badges = match (true) {
    $exam['status'] === 'draft' => '<span class="badge">Draft</span>',
    $exam['status'] === 'closed' || $pastYear => '<span class="badge">Closed</span>',
    default => '<span class="badge badge-ok">Published</span>',
};
if (!empty($exam['hidden']) && $exam['status'] !== 'draft') {
    $badges .= '<span class="badge badge-warn">' . icon('eye-off', 14) . 'Hidden from students</span>';
}
if ($exam['status'] !== 'draft') {
    $badges .= '<span class="badge' . ($pastYear ? '' : ' badge-info') . '"' . ($pastYear ? ' title="From an earlier school year: students cannot start it. Reopen it, or set dates in this school year."' : '') . '>'
        . h(exam_school_year($exam)) . ' school year</span>';
}
if ($hasAttempts) {
    $badges .= '<span class="badge badge-warn" title="Students have started, so the questions can no longer change">' . icon('lock', 14) . 'Questions locked</span>';
}
page_head($exam['title'] ?: 'Untitled exam', [
    'back' => ['Exams', 'teacher/exams.php'],
    'badges' => $badges,
    'meta' => [
        $exam['status'] === 'draft' ? 'Students cannot see it yet.'
            : ($paper ? icon('file', 14) . count($paper['questions']) . ' question(s) frozen on ' . h(date('d M, h:i A', strtotime($paper['published']))) : ''),
        icon('calendar', 14) . h(schedule_text(exam_time($exam['open_at']), exam_time($exam['close_at']))),
    ],
    'actions' => $exam['status'] === 'draft'
        ? '<button class="btn" form="exam-form" name="action" value="preview" formtarget="_blank" title="Save, then try the exam as a student in a new tab">' . icon('play') . 'Preview</button>'
        : '<a class="btn" href="' . h(url('teacher/preview.php')) . '?id=' . $idParam . '" target="_blank" title="Try the exam as a student gets it, in a new tab (changes not saved are not in it)">' . icon('play') . 'Preview</a>'
        . '<a class="btn" href="' . h(url('teacher/monitor.php')) . '?id=' . $idParam . '">' . icon('activity') . 'Monitor</a>'
        . '<a class="btn" href="' . h(url('teacher/results.php')) . '?id=' . $idParam . '">' . icon('chart') . 'Results</a>'
        . '<a class="btn" href="' . h(url('teacher/print.php')) . '?id=' . $idParam . '" target="_blank">' . icon('printer') . 'Print</a>'
        . (exam_is_reward($exam) ? '<a class="btn" href="' . h(url('teacher/rewards.php')) . '?exam=' . $idParam . '">' . icon('coin') . 'Rewards</a>' : ''),
]);
?>

<div class="editor-layout">
<nav class="section-rail" aria-label="Parts of the exam">
  <a href="#part-who"><span class="n">1</span>Who and what</a>
  <a href="#part-questions"><span class="n">2</span>Questions<span class="count" id="rail-count"></span></a>
  <a href="#part-when"><span class="n">3</span>When and how</a>
  <a href="#part-after"><span class="n">4</span>After the exam</a>
  <a href="#part-checking"><span class="n">5</span>Checking short answers</a>
  <a href="#part-rewards"><span class="n">6</span>Rewards</a>
</nav>

<form method="post" id="exam-form">
  <?= csrf_field() ?>
  <input type="hidden" name="sections_json" id="sections-json">

  <section class="card part" id="part-who">
    <h2>Who and what</h2>
    <div class="form-grid">
      <label class="field"><span>Title</span><input type="text" name="title" value="<?= h($exam['title']) ?>" required></label>
      <label class="field"><span>Subject</span>
        <select name="subject"><?php foreach ($subjects as $s): ?><option <?= $s === $exam['subject'] ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select>
      </label>
      <div class="field"><span class="field-label">Classes</span>
        <div class="checks checks-field">
          <?php $current = all_classes();
          foreach (exam_class_choices($exam) as $c): ?>
            <label class="check" <?= in_array($c, $current, true) ? '' : 'title="No student in users.csv is in this class now"' ?>><input type="checkbox" name="classes[]" value="<?= h($c) ?>" <?= in_array($c, $exam['classes'], true) ? 'checked' : '' ?>> Class <?= h($c) ?><?= in_array($c, $current, true) ? '' : ' <span class="muted">(no students now)</span>' ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="field"><span id="users-label">Also these students</span>
        <?php $staff = array_merge(array_keys(load_teachers()), cfg('ADMIN_USERS', [])); ?>
        <?php $choices = array_filter(load_users(), fn($u) => !in_array($u['username'], $staff, true) || in_array($u['username'], $exam['users'], true));
        foreach ($exam['users'] as $u) {
            $choices[$u] ??= student_info($u, list_attempts($exam['id'], $u));   // left school: named from the register
        } ?>
        <?= user_picker('users', implode(', ', $exam['users']), $choices,
            'many', ['aria-labelledby' => 'users-label', 'placeholder' => 'Type a name or username']) ?>
      </div>
    </div>
    <label class="field mb-0"><span>Instructions shown before starting</span>
      <textarea name="instructions" rows="2" class="short" placeholder="e.g. Read each question carefully."><?= h($exam['instructions']) ?></textarea>
    </label>
  </section>

  <section class="card part" id="part-questions">
    <div class="card-head"><h2>Questions</h2><span class="muted small" id="sections-total"></span></div>
    <?php if ($hasAttempts): ?><div class="notice notice-warn"><div class="notice-line"><?= icon('lock', 16) ?><span class="notice-text">Students have started, so the questions can no longer change.</span></div></div><?php endif; ?>
    <div id="sections" data-locked="<?= $hasAttempts ? '1' : '' ?>" data-exam="<?= h($exam['id']) ?>"></div>
    <script type="application/json" id="sections-data"><?= json_encode(['sections' => $editorSections, 'names' => $names, 'types' => array_map('question_type_label', ['multichoice' => 'multichoice', 'truefalse' => 'truefalse', 'shortanswer' => 'shortanswer', 'numerical' => 'numerical', 'match' => 'match', 'multiblank' => 'multiblank', 'essay' => 'essay', 'description' => 'description'])], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </section>

  <section class="card part" id="part-when">
    <h2>When and how</h2>
    <?php
    $openAt = $exam['open_at'];
    $closeAt = $exam['close_at'];
    $sameDay = $openAt !== '' && $closeAt !== '' && substr($openAt, 0, 10) === substr($closeAt, 0, 10);
    ?>
    <div class="schedule" id="schedule">
      <label class="field"><span>Date</span><input type="date" name="open_date" value="<?= h(substr($openAt, 0, 10)) ?>"></label>
      <label class="field"><span>Starts at</span><input type="time" name="open_time" value="<?= h(substr($openAt, 11, 5)) ?>"></label>
      <label class="field"><span>Ends at</span><input type="time" name="close_time" value="<?= h(substr($closeAt, 11, 5)) ?>"></label>
      <label class="field"><span>End date <span class="muted small">(if another day)</span></span><input type="date" name="close_date" value="<?= h($sameDay ? '' : substr($closeAt, 0, 10)) ?>"></label>
    </div>
    <p class="schedule-summary"><?= icon('calendar', 16) ?><span id="schedule-summary"><?= h(schedule_text(exam_time($openAt), exam_time($closeAt))) ?></span></p>
    <p class="hint small muted">No date = open as soon as published and never closes. At the end time, tries still open are submitted. A computer that loses its connection for over a minute gets that time back, even after the end time.</p>
    <div class="grid grid-3">
      <label class="field"><span>Time limit (minutes)</span><input type="number" name="time_limit_min" min="0" value="<?= (int)$exam['time_limit_min'] ?>"><span class="hint">0 = no limit</span></label>
      <label class="field"><span>Tries allowed (exams without rewards)</span><input type="number" name="attempts" min="0" value="<?= (int)$exam['attempts'] ?>"><span class="hint">0 = unlimited</span></label>
      <label class="field"><span>Score that counts</span>
        <select name="grade_method">
          <?php foreach (['highest' => 'Best try', 'latest' => 'Last try', 'first' => 'First try', 'average' => 'Average of tries'] as $k => $label): ?>
            <option value="<?= $k ?>" <?= $exam['grade_method'] === $k ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Questions per screen</span>
        <select name="per_page">
          <option value="1" <?= (int)$exam['per_page'] === 1 ? 'selected' : '' ?>>One at a time</option>
          <option value="0" <?= (int)$exam['per_page'] === 0 ? 'selected' : '' ?>>All on one page (worksheet)</option>
        </select>
      </label>
      <label class="field"><span>Exam code (optional)</span><input type="text" name="access_code" value="<?= h($exam['access_code']) ?>" maxlength="20" placeholder="e.g. TIGER"><span class="hint">Write it on the board; students type it to start</span></label>
    </div>
    <label class="check"><input type="checkbox" name="shuffle_questions" value="1" <?= $exam['shuffle_questions'] ? 'checked' : '' ?>> Shuffle question order for each student (within sections that allow it)</label>
    <label class="check"><input type="checkbox" name="shuffle_choices" value="1" <?= $exam['shuffle_choices'] ? 'checked' : '' ?>> Shuffle answer choices for each student ("All of the above" stays last)</label>

    <h3 id="own-times" class="mt-16">Own times for some students</h3>
    <p class="small muted">For a student who was absent or needs more time: their own start and end, extra minutes on the time limit, or extra tries.</p>
    <?php
    $overrides = exam_overrides($exam['id']);
    $studentChoices = exam_students($exam);
    foreach (array_keys(all_attempts($exam['id'])) as $u) {
        $studentChoices[$u] ??= student_info($u, list_attempts($exam['id'], $u));
    }
    ?>
    <?php if ($overrides): ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Student</th><th>Their times</th><th class="num">Extra minutes</th><th class="num">Extra tries</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($overrides as $u => $o): $w = exam_window($exam, $u); ?>
          <tr>
            <td><strong><?= h($studentChoices[$u]['name'] ?? display_name($u)) ?></strong><span class="sub"><?= h($u) ?></span></td>
            <td class="small"><?= h(schedule_text($w['open'], $w['close'])) ?><?= !$w['own'] ? ' <span class="muted">(same as the exam)</span>' : '' ?></td>
            <td class="num"><?= (int)$o['extra_minutes'] ?: '' ?></td>
            <td class="num"><?= (int)$o['extra_attempts'] ?: '' ?></td>
            <td><button class="btn btn-sm btn-ghost" name="action" value="override_remove:<?= h($u) ?>" formnovalidate>Remove</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <div class="own-times-form">
      <div class="field"><span id="ov-user-label">Student</span>
        <?= user_picker('ov_user', '', $studentChoices, 'one', ['aria-labelledby' => 'ov-user-label', 'placeholder' => 'Type a name']) ?></div>
      <label class="field"><span>Starts</span><input type="datetime-local" name="ov_open"></label>
      <label class="field"><span>Ends</span><input type="datetime-local" name="ov_close"></label>
      <label class="field"><span>+ minutes</span><input type="number" name="ov_minutes" min="0" max="600" value="0"></label>
      <label class="field"><span>+ tries</span><input type="number" name="ov_tries" min="0" max="20" value="0"></label>
      <div class="field"><button class="btn" name="action" value="override_save"><?= icon('save') ?>Save own times</button></div>
    </div>
  </section>

  <section class="card part" id="part-after">
    <h2>After the exam</h2>
    <div class="grid grid-2">
      <label class="field"><span>Students see</span>
        <select name="review">
          <option value="score" <?= $exam['review'] === 'score' ? 'selected' : '' ?>>Only their score</option>
          <option value="after_close" <?= $exam['review'] === 'after_close' ? 'selected' : '' ?>>Score now, correct answers after the exam closes</option>
          <option value="immediate" <?= $exam['review'] === 'immediate' ? 'selected' : '' ?>>Score and correct answers straight away (practice)</option>
        </select>
      </label>
      <label class="field"><span>Messages by score (one per line)</span>
        <textarea name="bands" rows="3" placeholder="90: Excellent!&#10;60: Good work!&#10;0: Keep practising!"><?= h($bandsText) ?></textarea>
      </label>
    </div>
  </section>

  <div class="grid grid-2 align-start">
    <section class="card part" id="part-checking">
      <h2>Checking short answers</h2>
      <p class="small muted">Full marks for a fill-in-the-blank answer that differs only in:</p>
      <label class="check"><input type="checkbox" name="ignore_case" value="1" <?= $exam['grading']['ignore_case'] ? 'checked' : '' ?>> Ignore capitalization <span class="small muted">(not for questions marked "case")</span></label>
      <label class="check"><input type="checkbox" name="ignore_spaces" value="1" <?= $exam['grading']['ignore_spaces'] ? 'checked' : '' ?>> Ignore spacing <span class="small muted">(sun flower = sunflower)</span></label>
      <label class="check"><input type="checkbox" name="ignore_punctuation" value="1" <?= $exam['grading']['ignore_punctuation'] ? 'checked' : '' ?>> Ignore punctuation <span class="small muted">(. , ; : ! ? quotes hyphens brackets; not # + = / or 3.5)</span></label>
      <label class="check"><input type="checkbox" name="fuzzy" value="1" <?= $exam['grading']['fuzzy'] ? 'checked' : '' ?>> Accept small spelling slips</label>
      <label class="field"><span>Marks taken off for a spelling slip (%)</span><input type="number" name="spelling_penalty" min="0" max="100" value="<?= round($exam['grading']['spelling_penalty'] * 100) ?>"></label>
      <label class="field"><span>Local AI checking of answers</span>
        <select name="llm">
          <option value="off" <?= $exam['grading']['llm'] === 'off' ? 'selected' : '' ?>>Off</option>
          <option value="suggest" <?= $exam['grading']['llm'] === 'suggest' ? 'selected' : '' ?>>Suggest marks, teacher confirms</option>
          <option value="auto" <?= $exam['grading']['llm'] === 'auto' ? 'selected' : '' ?>>Apply automatically (unsure ones go to the teacher)</option>
        </select>
      </label>
    </section>
    <section class="card part" id="part-rewards">
      <h2>Rewards</h2>
      <label class="check"><input type="checkbox" name="reward_enabled" value="1" <?= exam_is_reward($exam) ? 'checked' : '' ?>> This exam gives Zion Bank rewards</label>
      <p class="small muted">Reward exams stay locked until you unlock them from the Monitor page (for a class or one student). Each unlock allows one try.</p>
      <label class="field"><span>Formula</span><input type="text" name="reward_formula" value="<?= h($exam['reward']['formula']) ?>" class="mono-input"><span class="hint">Names: <?= h(implode(', ', array_keys(reward_sample_variables()))) ?>. Functions: min max round floor ceil abs clamp. Example: <code>percent >= 90 ? 10 : percent >= 75 ? 5 : 0</code></span></label>
      <div class="grid grid-2">
        <label class="field"><span>Most per exam</span><input type="number" name="reward_max" min="0" step="0.5" value="<?= h($exam['reward']['max']) ?>"></label>
        <label class="field"><span>Most per day (0 = no limit)</span><input type="number" name="reward_daily_max" min="0" step="0.5" value="<?= h($exam['reward']['daily_max']) ?>"></label>
        <label class="field"><span>Pay</span>
          <select name="reward_payout">
            <option value="by_question_types" <?= $exam['reward']['payout'] === 'by_question_types' ? 'selected' : '' ?>>Automatically, unless there are essays</option>
            <option value="auto" <?= $exam['reward']['payout'] === 'auto' ? 'selected' : '' ?>>Always automatically</option>
            <option value="approve" <?= $exam['reward']['payout'] === 'approve' ? 'selected' : '' ?>>After I approve</option>
          </select>
        </label>
        <label class="field"><span>Counts</span>
          <select name="reward_count">
            <option value="best" <?= $exam['reward']['count'] === 'best' ? 'selected' : '' ?>>Best try (retakes pay the difference)</option>
            <option value="first" <?= $exam['reward']['count'] === 'first' ? 'selected' : '' ?>>First try only</option>
          </select>
        </label>
      </div>
    </section>
  </div>

  <div class="card sticky-actions">
    <div class="row row-tight">
      <button class="btn" name="action" value="save"><?= icon('save') ?>Save</button>
      <button class="btn btn-primary" name="action" value="publish"><?= icon('send') ?><?= $exam['status'] === 'draft' ? 'Publish' : 'Save and publish' ?></button>
      <span class="spacer"></span>
      <?php if ($exam['status'] !== 'draft'): ?>
        <?php if (empty($exam['hidden'])): ?><button class="btn" name="action" value="hide" formnovalidate title="Students cannot see or start it; their results stay"><?= icon('eye-off') ?>Hide from students</button>
        <?php else: ?><button class="btn" name="action" value="show" formnovalidate><?= icon('eye') ?>Show to students</button><?php endif; ?>
      <?php endif; ?>
      <?php if ($exam['status'] === 'published' && !$pastYear): ?><button class="btn" name="action" value="close" data-confirm="Click again to close"><?= icon('x-circle') ?>Close exam</button><?php endif; ?>
      <?php if ($exam['status'] === 'closed' || $pastYear): ?><button class="btn" name="action" value="reopen" formnovalidate><?= icon('repeat') ?><?= $pastYear ? 'Reopen for ' . h(current_school_year()) : 'Reopen' ?></button><?php endif; ?>
      <?php if ($exam['status'] !== 'draft' && !$hasAttempts): ?><button class="btn btn-ghost" name="action" value="unpublish"><?= icon('arrow-left') ?>Back to draft</button><?php endif; ?>
      <?php if (!$hasAttempts): ?><button class="btn btn-ghost btn-danger" name="action" value="delete" data-confirm="Click again to delete" formnovalidate><?= icon('trash') ?>Delete</button><?php endif; ?>
    </div>
  </div>
</form>
</div>

<div class="p-overlay picker" id="picker" hidden>
  <div class="p-overlay-card picker-card">
    <div class="row row-between"><h2 id="picker-title" class="m-0">Add questions</h2><button class="btn btn-icon btn-ghost" type="button" data-close title="Close"><?= icon('x') ?></button></div>
    <div id="picker-bank">
      <div class="toolbar mt-12">
        <input type="text" id="pick-folder" list="pick-folders" placeholder="Bank folder, e.g. V/Term-2/Maths" class="grow">
        <label class="check"><input type="checkbox" id="pick-sub" checked> Sub-folders</label>
        <label class="search-field pick-search"><?= icon('search', 16) ?><input type="search" id="pick-search" placeholder="Search"></label>
        <button class="btn" type="button" id="pick-load">Show</button>
      </div>
      <datalist id="pick-folders"><?php foreach ($folders as $f): ?><option value="<?= h($f) ?>"><?php endforeach; ?></datalist>
      <div class="select-all-row"><label class="check"><input type="checkbox" class="select-all"> Select all shown</label></div>
      <ul class="qlist pick-list" id="pick-list"></ul>
    </div>
    <div id="picker-random" hidden>
      <label class="field"><span>Pick from bank folder</span><input type="text" id="rand-folder" list="pick-folders" placeholder="e.g. V/Term-2/Maths"></label>
      <div class="grid grid-3">
        <label class="field"><span>How many</span><input type="number" id="rand-count" min="1" value="5"></label>
        <label class="field"><span>Marks each</span><input type="number" id="rand-mark" min="0" step="0.5" value="1"></label>
        <label class="field"><span>Only with tag</span><input type="text" id="rand-tags" placeholder="optional"></label>
      </div>
      <label class="check"><input type="checkbox" id="rand-sub" checked> Include sub-folders</label>
      <div class="field-label">Only these types (none ticked = all)</div>
      <div class="checks" id="rand-types"></div>
      <p class="small muted" id="rand-available"></p>
    </div>
    <div class="overlay-actions"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="button" id="picker-add"><?= icon('plus') ?>Add</button></div>
  </div>
</div>
<?php page_end(['js' => ['assets/js/teacher.js', 'assets/js/user_picker.js', 'assets/js/exam_edit.js']]);
