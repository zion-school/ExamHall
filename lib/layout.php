<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Page shell: <head>, top bar with role-based navigation, page header, icons, flash messages.
 */

defined('EXAMS_APP') or exit;

/** Navigation entries: [key, label, path, roles]. Entries whose page doesn't exist yet are hidden. */
function nav_items(): array
{
    return [
        ['home', 'My exams', 'student/index.php', ['student']],
        ['results', 'My results', 'student/results.php', ['student']],
        ['dashboard', 'Dashboard', 'teacher/index.php', ['teacher', 'admin']],
        ['bank', 'Question bank', 'teacher/bank.php', ['teacher', 'admin']],
        ['exams', 'Exams', 'teacher/exams.php', ['teacher', 'admin']],
        ['students', 'Students', 'teacher/students.php', ['teacher', 'admin']],
        ['grading', 'Grading', 'teacher/grading.php', ['teacher', 'admin']],
        ['rewards', 'Rewards', 'teacher/rewards.php', ['teacher', 'admin']],
        ['teachers', 'Teachers', 'teacher/teachers.php', ['admin']],
    ];
}

function home_path_for(array $user): string
{
    return is_staff($user) ? 'teacher/index.php' : 'student/index.php';
}

/** A WAP phone: it asks for WML and not for HTML (wap/ is the app for phones) */
function wants_wml(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return stripos($accept, 'vnd.wap.wml') !== false && stripos($accept, 'text/html') === false;
}

function flash(string $type, string $message): void
{
    start_app_session();
    $_SESSION['flash'][] = [$type, $message];
}

function render_flashes(): string
{
    start_app_session();
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $message]) {
        $html .= '<div class="alert alert-' . h($type) . '" role="status">' . h($message) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/** A drawn icon from assets/icons.svg. Decorative: the button or link around it carries the words or a title. */
function icon(string $name, int $size = 18): string
{
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false"><use href="#i-' . h($name) . '"></use></svg>';
}

/**
 * Arrow button that opens a question preview under its row (assets/js/teacher.js). $data: a bank question's key,
 * ['exam' => id, 'question' => key] (the exam's own copy) or ['import' => id, 'key' => n].
 */
function preview_toggle(string|array $data): string
{
    $attrs = !is_array($data) ? ' data-question="' . h($data) . '"'
        : (isset($data['import']) ? ' data-import="' . h($data['import']) . '" data-key="' . (int)$data['key'] . '"'
        : ' data-question="' . h($data['question']) . '" data-exam="' . h($data['exam']) . '"');
    return '<button type="button" class="btn btn-sm btn-icon btn-ghost preview-toggle" title="Preview" aria-expanded="false"' . $attrs . '>' . icon('chevron-right', 16) . '</button>';
}

/** Icon followed by text, for meta lines and badges. */
function icon_text(string $name, string $text, int $size = 14): string
{
    return '<span class="with-icon">' . icon($name, $size) . h($text) . '</span>';
}

/**
 * A username box with suggestions (assets/js/user_picker.js): typed names become chips with ×.
 * Posts the usernames joined by ", " under $name, like the plain text field it replaces (and is without JS).
 * $users: [username => ['name' => .., 'class' => ..]] to suggest. $mode: many | one.
 * $attrs: extra attributes, e.g. ['aria-labelledby' => 'label-id', 'required' => true].
 */
function user_picker(string $name, string $value, array $users, string $mode = 'many', array $attrs = []): string
{
    $list = [];
    foreach ($users as $username => $u) {
        $list[] = [(string)$username, (string)($u['name'] ?? ''), normalize_class((string)($u['class'] ?? ''))];
    }
    usort($list, fn($a, $b) => strnatcasecmp($a[1] ?: $a[0], $b[1] ?: $b[0]));
    $html = '<input type="text" name="' . h($name) . '" value="' . h($value) . '" data-user-picker="' . ($mode === 'one' ? 'one' : 'many') . '"'
        . ' data-users="' . h(json_encode($list, JSON_UNESCAPED_UNICODE)) . '" autocomplete="off"';
    foreach ($attrs as $attr => $v) {
        $html .= $v === true ? ' ' . h($attr) : ' ' . h($attr) . '="' . h((string)$v) . '"';
    }
    return $html . '>';
}

/**
 * Page header: back link, title with badges, one meta line, actions on the right (main action last).
 * Options: back => [label, path], badges => html, meta => list of html items, actions => html, title_html => html.
 */
function page_head(string $title, array $opts = []): void
{
    ?>
<div class="page-head">
  <div class="page-head-main">
    <?php if (!empty($opts['back'])): ?><a class="back" href="<?= h(url($opts['back'][1])) ?>"><?= icon('chevron-left', 14) ?><?= h($opts['back'][0]) ?></a><?php endif; ?>
    <div class="title-row"><h1><?= $opts['title_html'] ?? h($title) ?></h1><?= $opts['badges'] ?? '' ?></div>
    <?php $meta = array_filter($opts['meta'] ?? [], fn($m) => $m !== '' && $m !== null);
    if ($meta): ?><div class="meta"><?php foreach ($meta as $m): ?><span><?= $m ?></span><?php endforeach; ?></div><?php endif; ?>
  </div>
  <?php if (!empty($opts['actions'])): ?><div class="actions"><?= $opts['actions'] ?></div><?php endif; ?>
</div>
<?php
}

/**
 * Options: active (nav key), bare (no top bar and no page frame), css (extra stylesheets), body_class, wide.
 */
function page_start(string $title, ?array $user = null, array $opts = []): void
{
    $appName = cfg('APP_NAME');
    $css = array_merge(['assets/css/app.css'], $opts['css'] ?? []);
    $bodyClass = trim(($opts['body_class'] ?? '') . (empty($opts['bare']) ? '' : ' bare'));
    $mainClass = !empty($opts['bare']) ? 'page-bare' : (empty($opts['wide']) ? 'page' : 'page page-wide');
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($appName) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23146EB4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M6.5 3H14l4.5 4.5v12A1.5 1.5 0 0 1 17 21H6.5A1.5 1.5 0 0 1 5 19.5v-15A1.5 1.5 0 0 1 6.5 3zM13.5 3v5h5M8.5 14l2.5 2.5 4.5-5'/></svg>">
<?php foreach ($css as $href): ?>
<link rel="stylesheet" href="<?= h(url($href)) ?>?v=<?= @filemtime(APP_DIR . '/' . $href) ?>">
<?php endforeach; ?>
<meta name="csrf" content="<?= $user ? h(csrf_token()) : '' ?>">
<meta name="app-base" content="<?= h(url('')) ?>">
</head>
<body class="<?= h($bodyClass) ?>">
<?= @file_get_contents(APP_DIR . '/assets/icons.svg') ?>
<?php if (empty($opts['bare']) && $user): ?>
<header class="topbar">
  <a class="brand" href="<?= h(url(home_path_for($user))) ?>"><span class="brand-mark"><?= icon('sheet') ?></span><span><?= h($appName) ?></span></a>
  <nav class="topnav" aria-label="Main">
    <?php foreach (nav_items() as [$key, $label, $path, $roles]):
        if (!in_array($user['role'], $roles, true) || !is_file(APP_DIR . '/' . $path)) {
            continue;
        } ?>
      <a href="<?= h(url($path)) ?>" class="<?= ($opts['active'] ?? '') === $key ? 'active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="userchip">
    <span class="userchip-name"><?= h($user['name']) ?></span>
    <?php if ($user['role'] !== 'student'): ?><span class="badge badge-<?= h($user['role']) ?>"><?= h(ucfirst($user['role'])) ?></span><?php endif; ?>
    <a class="btn btn-ghost btn-sm" href="<?= h(url('logout.php')) ?>"><?= icon('log-out', 16) ?>Log out</a>
  </div>
</header>
<?php endif; ?>
<main class="<?= $mainClass ?>">
<?= render_flashes() ?>
<?php
}

/** Options: js (extra scripts). */
function page_end(array $opts = []): void
{
    $js = array_merge(['assets/js/app.js'], $opts['js'] ?? []);
    ?>
</main>
<?php foreach ($js as $src): ?>
<script src="<?= h(url($src)) ?>?v=<?= @filemtime(APP_DIR . '/' . $src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
