<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Question bank: folder tree, question lists, search, previews and bulk actions.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ids = array_filter(explode(',', (string)($_POST['ids'] ?? '')), 'is_question_key');
    // Later questions with a repeated title first: moving the first one away would change their "-2" keys
    usort($ids, fn($a, $b) => (int)(explode('-', $b)[1] ?? 1) <=> (int)(explode('-', $a)[1] ?? 1));
    $action = $_POST['action'] ?? '';
    $done = 0;
    $target = normalize_category((string)($_POST['target'] ?? ''));
    foreach ($ids as $qid) {
        $q = load_question($qid);
        if (!$q || !can_manage_subject($user, subject_from_path($q['category']))) {
            continue;
        }
        if ($action === 'hide' || $action === 'show') {
            set_question_hidden($qid, $action === 'hide');
            $done++;
        } elseif ($action === 'move' && $target !== '' && can_manage_subject($user, subject_from_path($target))) {
            move_question($qid, $target);
            $done++;
        }
    }
    if ($action === 'move' && ($target === '' || !can_manage_subject($user, subject_from_path($target)))) {
        flash('err', 'Choose a folder of one of your subjects to move to.');
    } else {
        flash('ok', "$done question(s) updated.");
    }
    redirect('teacher/bank.php?' . http_build_query(array_filter([
        'c' => $action === 'move' && $done ? $target : ($_POST['c'] ?? ''),
        'q' => $_POST['q'] ?? '',
    ])));
}

$tree = category_tree($user);
$current = normalize_category((string)($_GET['c'] ?? ''));
if ($current !== '' && !isset($tree[$current])) {
    $current = '';
}
$search = trim((string)($_GET['q'] ?? ''));
$typeFilter = (string)($_GET['type'] ?? '');
$tagFilter = trim((string)($_GET['tag'] ?? ''));
$showHidden = !empty($_GET['hidden']);
$searching = $search !== '' || $typeFilter !== '' || $tagFilter !== '';

/** Nested array of the flat tree paths. */
function build_nested(array $tree): array
{
    $root = [];
    foreach ($tree as $path => $info) {
        $node = &$root;
        foreach (explode('/', $path) as $part) {
            $node[$part] ??= ['_children' => []];
            $node = &$node[$part]['_children'];
        }
        unset($node);
    }
    return $root;
}

function render_tree(array $nodes, array $tree, string $prefix, string $current): void
{
    echo '<ul class="tree">';
    foreach ($nodes as $name => $node) {
        $path = $prefix === '' ? $name : "$prefix/$name";
        $info = $tree[$path];
        $active = $path === $current ? 'active' : '';
        $link = '<a class="' . $active . '" href="' . h(url('teacher/bank.php')) . '?c=' . h(rawurlencode($path)) . '">'
            . '<span class="twisty">' . ($node['_children'] ? icon('chevron-right', 14) : '') . '</span>'
            . h(str_replace('_', ' ', $name)) . '<span class="count">' . (int)$info['total'] . '</span></a>';
        echo '<li>';
        if ($node['_children']) {
            $open = $current === $path || str_starts_with($current, $path . '/');
            echo '<details' . ($open ? ' open' : '') . '><summary>' . $link . '</summary>';
            render_tree($node['_children'], $tree, $path, $current);
            echo '</details>';
        } else {
            echo $link;
        }
        echo '</li>';
    }
    echo '</ul>';
}

/**
 * Question rows. In a folder ($inFolder) they come under their section headings with their number
 * there; elsewhere each row shows its folder, section and number. The hidden key is only in links.
 */
function render_question_rows(array $rows, bool $inFolder): void
{
    echo '<ul class="qlist">';
    $section = null;
    foreach ($rows as $r) {
        if ($inFolder && $r['section'] !== $section) {
            $section = $r['section'];
            echo '<li class="qsection">' . h($section) . '</li>';
        }
        $hidden = ($r['hidden'] ?? '') === '1';
        $tags = is_array($r['tags']) ? $r['tags'] : array_filter(explode(';', (string)$r['tags']));
        echo '<li class="qrow' . ($hidden ? ' is-hidden' : '') . '"><div class="qrow-head">'
            . '<input type="checkbox" class="q-pick" value="' . h($r['id']) . '" aria-label="Select">'
            . ($inFolder ? '<span class="qrow-num">' . h($r['number']) . '</span>' : '')
            . preview_toggle($r['id'])
            . '<span class="chip type-badge t-' . h($r['type']) . '">' . h(question_type_label($r['type'])) . '</span>'
            . '<span class="qrow-name" title="' . h($r['name']) . '">' . h($r['name']) . '</span>';
        foreach (array_slice($tags, 0, 3) as $tag) {
            echo '<span class="chip">' . h($tag) . '</span>';
        }
        if ($hidden) {
            echo '<span class="badge">' . icon('eye-off', 14) . 'Hidden</span>';
        }
        echo '<a class="btn btn-sm btn-icon btn-ghost" href="' . h(url('teacher/question.php')) . '?id=' . h($r['id']) . '" title="Edit">' . icon('pencil', 16) . '</a></div>';
        if (!$inFolder) {
            echo '<div class="qrow-body"><a class="small mono" href="' . h(url('teacher/bank.php')) . '?c=' . h(rawurlencode($r['category'])) . '">' . h($r['category']) . '</a>'
                . ' <span class="qrow-label" title="' . h(question_label($r)) . '">' . h(question_label($r)) . '</span></div>';
        }
        echo '</li>';
    }
    echo '</ul>';
}

$rows = [];
if ($searching) {
    $needle = mb_strtolower($search);
    foreach (bank_index() as $row) {
        if (!isset($tree[$row['category']])) {
            continue;
        }
        if ($current !== '' && $row['category'] !== $current && !str_starts_with($row['category'], $current . '/')) {
            continue;
        }
        if (!$showHidden && $row['hidden'] === '1') {
            continue;
        }
        if ($typeFilter !== '' && $row['type'] !== $typeFilter) {
            continue;
        }
        if ($tagFilter !== '' && !in_array(mb_strtolower($tagFilter), array_map('mb_strtolower', explode(';', $row['tags'])), true)) {
            continue;
        }
        if ($needle !== '' && !str_contains(mb_strtolower($row['name'] . ' ' . $row['section'] . ' ' . $row['tags'] . ' ' . ($row['text'] ?? '')), $needle)) {
            continue;
        }
        $rows[] = $row;
        if (count($rows) >= 300) {
            break;
        }
    }
} elseif ($current !== '') {
    // The index keeps each folder's questions in file order
    foreach (bank_index() as $row) {
        if ($row['category'] === $current && ($showHidden || $row['hidden'] !== '1')) {
            $rows[] = $row;
        }
    }
}
$problems = array_filter(bank_problems(), fn($category) => isset($tree[$category]) || $user['role'] === 'admin', ARRAY_FILTER_USE_KEY);

/** Problems found in a chapter's .gift file (after editing it outside the app): one line, the list on "Show". */
function render_file_problems(string $category, array $list): void
{
    $errors = count(array_filter($list, fn($p) => $p['level'] === 'error'));
    $first = $list[0] ?? null;
    echo '<details class="notice ' . ($errors ? 'notice-err' : 'notice-warn') . '"><summary>' . icon($errors ? 'stop' : 'alert', 16)
        . '<span class="notice-text"><strong>' . h($category) . BANK_EXT . '</strong>: '
        . ($errors ? "$errors question(s) cannot be read and are left out until the file is fixed." : count($list) . ' warning(s) from the GIFT checker.')
        . ($first ? ' ' . ($first['line'] ? 'Line ' . (int)$first['line'] . ': ' : '') . h($first['message']) : '')
        . '</span><span class="notice-more">Show</span></summary><div class="notice-body"><ul>';
    foreach (array_slice($list, 0, 20) as $p) {
        echo '<li>' . ($p['line'] ? 'Line ' . (int)$p['line'] . ': ' : '') . h($p['message']) . '</li>';
    }
    echo '</ul></div></details>';
}

$types = [];
$totalQuestions = 0;
foreach (bank_index() as $row) {
    if (isset($tree[$row['category']]) && $row['hidden'] !== '1') {
        $types[$row['type']] = ($types[$row['type']] ?? 0) + 1;
        $totalQuestions++;
    }
}

page_start('Question bank', $user, ['active' => 'bank', 'css' => ['assets/css/teacher.css']]);

page_head('Question bank', [
    'meta' => [$totalQuestions . ' questions' . ($user['role'] === 'teacher' ? ' in your subjects' : '')],
    'actions' => '<a class="btn" href="' . h(url('teacher/question.php')) . '?new=1&amp;c=' . h(rawurlencode($current)) . '">' . icon('plus') . 'New question</a>'
        . '<a class="btn btn-primary" href="' . h(url('teacher/import.php')) . '?base=' . h(rawurlencode($current)) . '">' . icon('upload') . 'Import GIFT</a>',
]);
?>

<div class="split">
  <aside class="split-side card tree-card">
    <?php if (!$tree): ?>
      <p class="muted small">The bank is empty. Start with <a href="<?= h(url('teacher/import.php')) ?>">Import</a>.</p>
    <?php else: ?>
      <ul class="tree"><li><a class="<?= $current === '' && !$searching ? 'active' : '' ?>" href="<?= h(url('teacher/bank.php')) ?>"><?= icon('folder', 16) ?>All folders</a></li></ul>
      <?php render_tree(build_nested($tree), $tree, '', $current); ?>
    <?php endif; ?>
  </aside>

  <section>
    <form class="toolbar" method="get">
      <input type="hidden" name="c" value="<?= h($current) ?>">
      <label class="search-field"><?= icon('search', 16) ?><input type="search" name="q" value="<?= h($search) ?>" placeholder="Search question text, names, sections and tags<?= $current !== '' ? ' in this folder' : '' ?>" aria-label="Search"></label>
      <select name="type" aria-label="Type">
        <option value="">All types</option>
        <?php foreach (['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'multiblank', 'essay', 'description'] as $t): ?>
          <option value="<?= $t ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= h(question_type_label($t)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="tag" value="<?= h($tagFilter) ?>" placeholder="Tag" class="tag-input" aria-label="Tag">
      <label class="check"><input type="checkbox" name="hidden" value="1" <?= $showHidden ? 'checked' : '' ?>> Show hidden</label>
      <button class="btn">Search</button>
    </form>

    <?php if ($searching): ?>
      <div class="card">
        <div class="card-head"><h2><?= count($rows) ?><?= count($rows) >= 300 ? '+' : '' ?> matching question(s)</h2></div>
        <?php count($rows) ? render_question_rows($rows, false) : print('<p class="muted">Nothing found.</p>'); ?>
      </div>
    <?php elseif ($current !== ''): ?>
      <div class="card">
        <?php
        $parts = explode('/', $current);
        $crumbs = [];
        $acc = '';
        foreach (array_slice($parts, 0, -1) as $part) {
            $acc = $acc === '' ? $part : "$acc/$part";
            $crumbs[] = '<a href="' . h(url('teacher/bank.php')) . '?c=' . h(rawurlencode($acc)) . '">' . h($part) . '</a>';
        }
        ?>
        <?php if ($crumbs): ?><div class="crumbs"><?= implode('<span>/</span>', $crumbs) ?></div><?php endif; ?>
        <div class="folder-head">
          <div class="title-row">
            <h2><?= h(str_replace('_', ' ', basename($current))) ?></h2>
            <span class="chip"><?= h($tree[$current]['subject'] ?? 'No subject') ?></span>
            <span class="muted small"><?= (int)$tree[$current]['count'] ?> here, <?= (int)$tree[$current]['total'] ?> including sub-folders</span>
          </div>
          <div class="actions">
            <a class="btn btn-sm" href="<?= h(url('teacher/export.php')) ?>?c=<?= h(rawurlencode($current)) ?>&amp;format=gift"><?= icon('download', 16) ?>Export GIFT</a>
            <a class="btn btn-sm" href="<?= h(url('teacher/export.php')) ?>?c=<?= h(rawurlencode($current)) ?>&amp;format=zip"><?= icon('download', 16) ?>ZIP with pictures</a>
          </div>
        </div>
        <?php if (is_file(bank_gift_file($current))): ?><p class="mono folder-path">bank/<?= h($current) ?><?= BANK_EXT ?></p><?php endif; ?>
        <?php
        $children = array_filter(array_keys($tree), fn($p) => str_starts_with($p, $current . '/') && !str_contains(substr($p, strlen($current) + 1), '/'));
        if ($children): ?>
          <div class="chips mb-12">
            <?php foreach ($children as $child): ?>
              <a class="chip" href="<?= h(url('teacher/bank.php')) ?>?c=<?= h(rawurlencode($child)) ?>"><?= icon('folder', 14) ?><?= h(str_replace('_', ' ', basename($child))) ?> (<?= (int)$tree[$child]['total'] ?>)</a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (isset($problems[$current])) {
            render_file_problems($current, $problems[$current]);
        } ?>
        <?php if ($rows): ?>
          <div class="select-all-row"><label class="check"><input type="checkbox" class="select-all"> Select all</label></div>
          <?php render_question_rows($rows, true); ?>
        <?php elseif (!$children): ?>
          <p class="muted mt-12">No questions in this folder.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="card">
        <h2>Overview</h2>
        <div class="summary inset">
          <?php foreach ($types as $type => $n): ?>
            <a class="summary-cell" href="<?= h(url('teacher/bank.php')) ?>?type=<?= h($type) ?>"><span><b><?= $n ?></b><span><?= h(question_type_label($type)) ?></span></span></a>
          <?php endforeach; ?>
        </div>
        <?php $top = array_filter(array_keys($tree), fn($p) => !str_contains($p, '/')); ?>
        <?php if ($top): ?>
          <h3>Folders</h3>
          <div class="chips">
            <?php foreach ($top as $t): ?>
              <a class="chip" href="<?= h(url('teacher/bank.php')) ?>?c=<?= h(rawurlencode($t)) ?>"><?= icon('folder', 14) ?><?= h($t) ?> (<?= (int)$tree[$t]['total'] ?>)</a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php foreach ($problems as $category => $list) {
          render_file_problems($category, $list);
      } ?>
    <?php endif; ?>

    <form method="post" class="bulkbar" id="bulkbar">
      <?= csrf_field() ?>
      <input type="hidden" name="ids" value="">
      <input type="hidden" name="c" value="<?= h($current) ?>">
      <input type="hidden" name="q" value="<?= h($search) ?>">
      <strong><span class="bulk-count">0</span> selected</strong>
      <button class="btn btn-sm" name="action" value="hide"><?= icon('eye-off', 16) ?>Hide</button>
      <button class="btn btn-sm" name="action" value="show"><?= icon('eye', 16) ?>Show</button>
      <span class="spacer"></span>
      <input type="text" name="target" list="bank-folders" placeholder="Move to folder…" aria-label="Move to folder">
      <button class="btn btn-sm btn-accent" name="action" value="move"><?= icon('folder', 16) ?>Move</button>
      <datalist id="bank-folders"><?php foreach (array_keys($tree) as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
    </form>
  </section>
</div>
<?php page_end(['js' => ['assets/js/teacher.js']]);
