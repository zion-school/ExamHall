<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Check GIFT files against Moodle's GIFT rules and common authoring mistakes.
 * Usage: php bin/gift_lint.php <folder-or-file>... [--md] [--info]
 *   --md    Markdown report (default: plain text)
 *   --info  include info-level notes (hidden by default)
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';

$paths = [];
$markdown = false;
$showInfo = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--md') {
        $markdown = true;
    } elseif ($arg === '--info') {
        $showInfo = true;
    } else {
        $paths[] = rtrim($arg, '/');
    }
}
if (!$paths) {
    fwrite(STDERR, "Usage: php bin/gift_lint.php <folder-or-file>... [--md] [--info]\n");
    exit(2);
}

$files = [];
$others = [];
foreach ($paths as $path) {
    if (is_file($path)) {
        $files[] = [$path, basename($path)];
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($path) + 1);
        $ext = strtolower($f->getExtension());
        if (in_array($ext, ['gift', 'txt'], true)) {
            $files[] = [$f->getPathname(), $rel];
        } elseif ($f->getFilename() !== '.gitkeep' && basename(dirname($f->getPathname())) !== 'media' && !in_array($ext, IMPORT_IMAGE_EXT, true)) {
            // Pictures (and media/ folders) belong to the chapters
            $others[] = $rel;
        }
    }
}
usort($files, fn($a, $b) => strnatcasecmp($a[1], $b[1]));

$parser = new GiftParser();
$all = [];
$types = [];
$questionCount = 0;
$todoFiles = 0;
$noQuestionFiles = [];
foreach ($files as [$full, $rel]) {
    $content = file_get_contents($full);
    $result = $parser->parse($content, $rel);
    $questionCount += count($result['questions']);
    foreach ($result['questions'] as $q) {
        $types[$q['type']] = ($types[$q['type']] ?? 0) + 1;
    }
    if (str_contains($content, 'TODO')) {
        $todoFiles++;
    }
    if (!$result['questions']) {
        $noQuestionFiles[] = $rel . (trim($content) === '' ? ' (empty)' : ' (comments only)');
    }
    if (str_ends_with(strtolower($rel), '.txt')) {
        $result['messages'][] = ['level' => 'warning', 'file' => $rel, 'line' => 1, 'message' => 'GIFT file with a .txt extension; is it a stray copy of a .gift file?', 'excerpt' => ''];
    }
    $all = array_merge($all, $result['messages'], GiftLint::check($result['questions'], $rel));
}

$levels = ['error' => [], 'warning' => [], 'info' => []];
foreach ($all as $m) {
    $levels[$m['level']][] = $m;
}

/** Group key: message with quoted parts removed. */
function kind_of(string $message): string
{
    return trim(preg_replace(['/"[^"]*"/u', '/\d+(\.\d+)?/', '/\{[^}]*\}/'], ['"…"', 'N', '{…}'], $message));
}

$h = fn($t) => $markdown ? "\n## $t\n\n" : "\n=== $t ===\n";
echo $markdown ? "# GIFT check report\n\n" : "GIFT check report\n";
echo ($markdown ? '- ' : '') . 'Scanned: ' . implode(', ', $paths) . "\n";
echo ($markdown ? '- ' : '') . count($files) . " GIFT files, $questionCount questions (" . implode(', ', array_map(fn($t, $n) => GiftLint::typeLabel($t) . " $n", array_keys($types), $types)) . ")\n";
echo ($markdown ? '- ' : '') . count($levels['error']) . ' errors (question cannot be imported), ' . count($levels['warning']) . ' warnings, ' . count($levels['info']) . " notes\n";
echo ($markdown ? '- ' : '') . "$todoFiles files still contain TODO comments\n";

echo $h('Summary by problem');
$kinds = [];
foreach (['error', 'warning', 'info'] as $level) {
    foreach ($levels[$level] as $m) {
        $kinds[$level . '|' . kind_of($m['message'])][] = $m;
    }
}
uksort($kinds, function ($a, $b) use ($kinds) {
    $order = ['error' => 0, 'warning' => 1, 'info' => 2];
    return [$order[explode('|', $a)[0]], -count($kinds[$a])] <=> [$order[explode('|', $b)[0]], -count($kinds[$b])];
});
if ($markdown) {
    echo "| Level | Count | Problem |\n|---|---|---|\n";
}
foreach ($kinds as $key => $list) {
    [$level, $kind] = explode('|', $key, 2);
    echo $markdown ? "| $level | " . count($list) . " | " . str_replace('|', '\|', $kind) . " |\n" : sprintf("%-8s %5d  %s\n", $level, count($list), $kind);
}

foreach (['error' => 'Errors', 'warning' => 'Warnings', 'info' => 'Notes'] as $level => $title) {
    if (!$levels[$level] || ($level === 'info' && !$showInfo)) {
        continue;
    }
    echo $h($title);
    foreach ($levels[$level] as $m) {
        $excerpt = $m['excerpt'] !== '' ? ' — ' . preg_replace('/\s+/u', ' ', mb_substr($m['excerpt'], 0, 100)) : '';
        if ($markdown) {
            echo '- `' . $m['file'] . ':' . $m['line'] . '` ' . $m['message'] . ($excerpt !== '' ? "  \n  <sub>" . htmlspecialchars(ltrim($excerpt, ' —')) . '</sub>' : '') . "\n";
        } else {
            echo $m['file'] . ':' . $m['line'] . '  ' . $m['message'] . $excerpt . "\n";
        }
    }
}

if ($noQuestionFiles || $others) {
    echo $h('Files without questions');
    foreach ($noQuestionFiles as $f) {
        echo ($markdown ? '- `' . $f . '`' : $f) . "\n";
    }
    foreach ($others as $f) {
        echo ($markdown ? '- `' . $f . '` (not a GIFT file)' : "$f (not a GIFT file)") . "\n";
    }
}
exit($levels['error'] ? 1 : 0);
