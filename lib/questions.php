<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 17-Sep-2026
 *
 * Question bank storage. GIFT files are the bank:
 *
 *   data/bank/<folder>/<chapter>.gift   the questions of that chapter, in the teacher's order
 *   data/bank/<folder>/media/<picture>  pictures used by the chapters of that folder (written as media/<name>)
 *   data/bank/index.csv                 id,category,type,name,hidden,tags,text,section,number
 *   data/bank/.state.json               size/time and problems of each .gift file when indexed
 *
 * The layout is that of a question_bank folder kept in git (V/Computer-Science/03_Storage.gift).
 * A category is the path of its file without ".gift"; picture paths are relative to the file's folder.
 *
 * The files hold no question ids, so they can be copied over from elsewhere (bin/bank_copy.php)
 * as often as needed. A question's key is worked out from its folder and title (question_key):
 * "q" + 10 hex characters, "-2", "-3" for later questions with the same title in the file.
 * Pages never show the key; they show the question's section and its number there
 * ("Choose the correct answer · 3"), counted again from 1 in each "// === section ===".
 *
 * Settings GIFT cannot hold stay in a comment Moodle ignores (docs/gift.md):
 *   // [tag:Match] [zion:moodle=567 mark=2 hidden case noshuffle]
 *   // [key:reference answer for essays]
 *
 * The files may be edited outside the app (text editor, git, bin/bank_copy.php). The next request
 * notices it (bank_sync) and indexes the file again; the app never writes to a file for that.
 * index.csv and .state.json can always be rebuilt from the files (php bin/fsck.php --fix).
 * The app changes a file in place: saving a question rewrites only that question's block.
 * All bank writes hold one lock ("bank").
 */

defined('EXAMS_APP') or exit;

const BANK_EXT = '.gift';
const BANK_INDEX_HEADER = ['id', 'category', 'type', 'name', 'hidden', 'tags', 'text', 'section', 'number'];

/** Fields that describe the question content. */
const QUESTION_CONTENT_KEYS = [
    'type', 'name', 'text', 'format', 'general_feedback', 'general_feedback_format', 'default_mark', 'missing_word',
    'single', 'shuffle', 'answers', 'correct', 'feedback_true', 'feedback_false', 'feedback_true_format',
    'feedback_false_format', 'usecase', 'pairs', 'blanks', 'grader_info',
];

function bank_path(string $category = ''): string
{
    return $category === '' ? data_path('bank') : data_path('bank', $category);
}

/** Clean a category path: safe folder names joined by '/'. */
function normalize_category(string $path): string
{
    $segments = [];
    foreach (preg_split('#[/\\\\]+#', $path) as $seg) {
        $seg = trim($seg);
        if ($seg === '' || $seg === '.' || $seg === '..') {
            continue;
        }
        $seg = sanitize_filename($seg);
        if ($seg !== 'media' && $seg[0] !== '.') {
            $segments[] = $seg;
        }
    }
    return implode('/', $segments);
}

/** Category of a Moodle $CATEGORY value, without $course$/ style prefixes and "top". */
function category_from_gift(string $value): string
{
    $value = str_replace('//', "\u{E200}", trim($value));   // "//" escapes a slash in Moodle
    $parts = array_values(array_filter(explode('/', $value), fn($p) => trim($p) !== ''));
    while ($parts && (preg_match('/^\$[a-z0-9]+\$$/i', trim($parts[0])) || strtolower(trim($parts[0])) === 'top')) {
        array_shift($parts);
    }
    return normalize_category(str_replace("\u{E200}", '-', implode('/', $parts)));
}

/** The .gift file of a category: V/Maths/04_Shapes -> data/bank/V/Maths/04_Shapes.gift */
function bank_gift_file(string $category): string
{
    return bank_path($category) . BANK_EXT;
}

/** The folder a category's file is in; its pictures are found from there (media/icon.png, images/ch-1/vga.jpg). */
function bank_file_dir(string $category): string
{
    $parent = dirname($category);
    return bank_path($parent === '.' ? '' : $parent);
}

function bank_index_file(): string
{
    return data_path('bank', 'index.csv');
}

function bank_state_file(): string
{
    return data_path('bank', '.state.json');
}

// ---------- Keys, sections and numbers ----------

/**
 * A title as the key sees it. Upper/lower case, spacing, HTML entities, curly quotes and dashes,
 * backslashes and the length of a blank (___ or ______) make no difference. Punctuation and
 * digits do: "(9 + 4) > 12" and "(9 + 4) < 12" are different questions.
 */
function question_title_norm(string $name): string
{
    $s = html_to_text($name);
    $s = str_replace(['\\', "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"], ['', "'", "'", '"', '"', '-', '-'], $s);
    $s = preg_replace('/_{2,}|-{3,}/', '___', $s);
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
}

/** Hidden key of a question: q + 10 hex characters of its folder and title; -2, -3 for later ones with the same title. */
function question_key(string $category, string $name, int $occurrence = 1): string
{
    $key = 'q' . substr(sha1($category . "\n" . question_title_norm($name)), 0, 10);
    return $occurrence > 1 ? "$key-$occurrence" : $key;
}

/**
 * Parsed questions of one file with 'key', 'section' and 'number' added.
 * The number counts from 1 in each "// === section ===". Questions before any section line
 * (all of them in a file without such lines, like the Moodle folders) are grouped by their
 * first tag, or by type when they have no tag. A group that comes back later in the file
 * carries on counting, so no two questions of a file share a section and number.
 */
function bank_keyed(array $questions, string $category): array
{
    $seen = [];
    $counts = [];
    foreach ($questions as $i => $q) {
        $base = question_key($category, (string)$q['name']);
        $seen[$base] = ($seen[$base] ?? 0) + 1;
        $section = trim((string)($q['section'] ?? ''));
        if ($section === '') {
            $section = trim((string)($q['tags'][0] ?? '')) ?: question_type_label((string)$q['type']);
        }
        $counts[$section] = ($counts[$section] ?? 0) + 1;
        $questions[$i]['key'] = question_key($category, (string)$q['name'], $seen[$base]);
        $questions[$i]['section'] = $section;
        $questions[$i]['number'] = $counts[$section];
    }
    return $questions;
}

function is_question_key(string $key): bool
{
    return (bool)preg_match('/^q[0-9a-f]{10}(?:-\d+)?$/', $key);
}

/** "Choose the correct answer · 3" (section and number of a question or index row), '' when unknown. */
function question_label(array $q): string
{
    $number = (int)($q['number'] ?? 0);
    if ($number < 1) {
        return '';
    }
    $section = trim((string)($q['section'] ?? ''));
    return $section === '' ? (string)$number : "$section · $number";
}

// ---------- Index ----------

/** [key => row] */
function bank_index(): array
{
    static $cache = [];
    if (isset($GLOBALS['bank_batch'])) {
        return $GLOBALS['bank_batch']['index'];
    }
    bank_sync();
    $file = bank_index_file();
    if (!is_file($file)) {
        return [];
    }
    clearstatcache(true, $file);
    // The local write counter catches updates within the same second that keep the size
    $key = $file . '@' . filemtime($file) . '@' . filesize($file) . '@' . ($GLOBALS['bank_index_writes'] ?? 0);
    if (!isset($cache[$key])) {
        $cache = [$key => bank_read_index()];
    }
    return $cache[$key];
}

function bank_read_index(): array
{
    $rows = [];
    foreach (read_csv(bank_index_file()) as $row) {
        $rows[$row['id']] = $row;
    }
    return $rows;
}

/** Rows grouped by folder (natural order), in file order within a folder. */
function bank_sort_index(array $rows): array
{
    uasort($rows, fn($a, $b) => strnatcmp($a['category'], $b['category']));
    return $rows;
}

function bank_write_index(array $rows): void
{
    write_csv(bank_index_file(), BANK_INDEX_HEADER, array_values(bank_sort_index($rows)));
    $GLOBALS['bank_index_writes'] = ($GLOBALS['bank_index_writes'] ?? 0) + 1;
}

function bank_index_row(array $q, string $category): array
{
    return [
        'id' => $q['key'] ?? $q['id'], 'category' => $category, 'type' => $q['type'],
        'name' => mb_substr(preg_replace('/\s+/u', ' ', (string)$q['name']), 0, 200),
        'hidden' => !empty($q['hidden']) ? '1' : '', 'tags' => implode(';', $q['tags'] ?? []),
        'text' => mb_substr(html_to_text((string)($q['text'] ?? '')), 0, 300),
        'section' => mb_substr((string)($q['section'] ?? ''), 0, 200), 'number' => (string)($q['number'] ?? ''),
    ];
}

/** Put the questions of one folder into the index in place of its old rows ($keyed from bank_keyed). */
function bank_index_category(string $category, array $keyed): void
{
    $replace = function (array $byKey) use ($category, $keyed) {
        $byKey = array_filter($byKey, fn($row) => $row['category'] !== $category);
        foreach ($keyed as $q) {
            $byKey[$q['key']] = bank_index_row($q, $category);
        }
        return $byKey;
    };
    if (isset($GLOBALS['bank_batch'])) {
        $GLOBALS['bank_batch']['index'] = $replace($GLOBALS['bank_batch']['index']);
        $GLOBALS['bank_batch']['dirty'] = true;
        return;
    }
    update_csv(bank_index_file(), BANK_INDEX_HEADER, function ($existing) use ($replace) {
        return array_values(bank_sort_index($replace(array_column($existing, null, 'id'))));
    });
    $GLOBALS['bank_index_writes'] = ($GLOBALS['bank_index_writes'] ?? 0) + 1;
}

// ---------- Locking, batches ----------

/** Run $fn holding the bank lock, after catching up with edits made outside the app. */
function bank_locked(callable $fn)
{
    return with_lock('bank', function () use ($fn) {
        if (!isset($GLOBALS['bank_batch'])) {
            bank_sync_locked(false);
        }
        return $fn();
    });
}

/**
 * Run many bank writes as one batch (imports, migration): files and the index are kept in
 * memory and written once at the end, without fsync. bank_index() includes pending rows.
 */
function bank_batch(callable $fn)
{
    if (isset($GLOBALS['bank_batch'])) {
        return $fn();
    }
    return bank_locked(function () use ($fn) {
        $index = bank_index();
        $GLOBALS['bank_batch'] = ['files' => [], 'index' => $index, 'sections' => [], 'dirty' => false];
        $GLOBALS['storage_skip_fsync'] = true;
        try {
            return $fn();
        } finally {
            $batch = $GLOBALS['bank_batch'];
            unset($GLOBALS['bank_batch']);
            foreach ($batch['files'] as $category => $content) {
                write_file_atomic(bank_gift_file($category), $content);
            }
            if ($batch['files']) {
                bank_mark_written(array_keys($batch['files']));
            }
            if ($batch['dirty']) {
                bank_write_index($batch['index']);
            }
            $GLOBALS['storage_skip_fsync'] = false;
        }
    });
}

// ---------- Files ----------

/** Current text of a category's .gift file ('' when there is none). */
function bank_file_content(string $category): string
{
    if (isset($GLOBALS['bank_batch']['files'][$category])) {
        return $GLOBALS['bank_batch']['files'][$category];
    }
    $file = bank_gift_file($category);
    return is_file($file) ? (string)file_get_contents($file) : '';
}

/** Lines of GIFT text, numbered like the parser numbers them (line n = $lines[n - 1]). */
function bank_lines(string $content): array
{
    if ($content === '') {
        return [];
    }
    [$content] = GiftParser::decode($content);
    return explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
}

function bank_store_lines(string $category, array $lines): void
{
    while ($lines && trim(end($lines)) === '') {
        array_pop($lines);
    }
    $content = implode("\n", $lines) . "\n";
    if (isset($GLOBALS['bank_batch'])) {
        $GLOBALS['bank_batch']['files'][$category] = $content;
        return;
    }
    write_file_atomic(bank_gift_file($category), $content);
    bank_mark_written([$category]);
}

/** Size and time of a .gift file; a file changed within the last second is checked again next time. */
function bank_file_sig(string $file): string
{
    clearstatcache(true, $file);
    $mtime = (int)@filemtime($file);
    return @filesize($file) . ':' . $mtime . ($mtime >= time() - 1 ? ':recent' : '');
}

/** Remember files the app wrote, so they are not read again as outside edits. */
function bank_mark_written(array $categories): void
{
    update_json(bank_state_file(), function ($state) use ($categories) {
        foreach ($categories as $category) {
            $file = bank_gift_file($category);
            // An older time keeps the signature stable; any later edit gets a newer time
            @touch($file, time() - 2);
            $state['files'][$category]['sig'] = bank_file_sig($file);
            $state['files'][$category]['problems'] ??= [];
        }
        ksort($state['files'], SORT_NATURAL);
        return $state;
    }, []);
}

/** Parsed .gift file text (cached by content). */
function bank_parse(string $content, string $category): array
{
    static $cache = [];
    $key = md5($content);
    if (!isset($cache[$key])) {
        if (count($cache) > 200) {
            $cache = [];
        }
        $cache[$key] = (new GiftParser())->parse($content, $category . BANK_EXT);
    }
    return $cache[$key];
}

/** Questions of a category's .gift file, in file order, with key, section and number (bank_keyed). */
function bank_file_questions(string $category): array
{
    static $cache = [];
    $content = bank_file_content($category);
    $key = $category . '@' . md5($content);
    if (!isset($cache[$key])) {
        if (count($cache) > 200) {
            $cache = [];
        }
        $cache[$key] = bank_keyed(bank_parse($content, $category)['questions'], $category);
    }
    return $cache[$key];
}

/** [category => signature] of every .gift file in the bank. */
function bank_scan(): array
{
    $out = [];
    $walk = function (string $dir, string $rel) use (&$walk, &$out) {
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name[0] === '.' || $name === 'media') {
                continue;
            }
            $path = "$dir/$name";
            if (str_ends_with($name, BANK_EXT) && strlen($name) > strlen(BANK_EXT)) {
                if (is_file($path)) {
                    $out[($rel === '' ? '' : "$rel/") . substr($name, 0, -strlen(BANK_EXT))] = bank_file_sig($path);
                }
            } elseif (is_dir($path)) {
                $walk($path, $rel === '' ? $name : "$rel/$name");
            }
        }
    };
    if (is_dir(bank_path())) {
        $walk(bank_path(), '');
    }
    ksort($out, SORT_NATURAL);
    return $out;
}

/** Problems found in the bank files: [category => [[level, line, message], ...]]. */
function bank_problems(): array
{
    $out = [];
    foreach ((read_json(bank_state_file(), [])['files'] ?? []) as $category => $info) {
        if (!empty($info['problems'])) {
            $out[$category] = $info['problems'];
        }
    }
    return $out;
}

// ---------- Blocks inside a file ----------

/** Replace a question block with new GIFT; other comment lines of the block stay. */
function bank_replace_block(array $lines, array $block, string $gift): array
{
    $keep = [];
    for ($n = $block[0]; $n <= $block[1]; $n++) {
        $line = $lines[$n - 1];
        if (str_starts_with(ltrim($line), '//') && !preg_match('/\[(tag|id|zion|key):/', $line)) {
            $keep[] = $line;
        }
    }
    array_splice($lines, $block[0] - 1, $block[1] - $block[0] + 1, array_merge($keep, explode("\n", rtrim($gift, "\n"))));
    return $lines;
}

function bank_remove_block(array $lines, array $block): array
{
    $length = $block[1] - $block[0] + 1;
    if (isset($lines[$block[1]]) && trim($lines[$block[1]]) === '') {
        $length++;   // and the blank line after it
    }
    array_splice($lines, $block[0] - 1, $length);
    return $lines;
}

/** Add a question at the end of a file, with a "// === section ===" line when the section changes. */
function bank_append_block(array $lines, string $category, string $gift, string $section): array
{
    while ($lines && trim(end($lines)) === '') {
        array_pop($lines);
    }
    $last = $GLOBALS['bank_batch']['sections'][$category] ?? null;
    if ($last === null) {
        $questions = $lines ? bank_parse(implode("\n", $lines), $category)['questions'] : [];
        $last = $questions ? (string)end($questions)['section'] : '';
    }
    if (!$lines) {
        $lines = ['$CATEGORY: ' . $category];
    }
    $lines[] = '';
    if ($section !== '' && $section !== $last) {
        array_push($lines, '// === ' . str_replace(["\r", "\n"], ' ', $section) . ' ===', '');
        $last = $section;
    }
    if (isset($GLOBALS['bank_batch'])) {
        $GLOBALS['bank_batch']['sections'][$category] = $last;
    }
    return array_merge($lines, explode("\n", rtrim($gift, "\n")));
}

// ---------- Sync with the files ----------

/**
 * Catch up with .gift files changed outside the app. Cheap when nothing changed
 * (compares sizes and times); in long-running scripts it checks at most every 2 seconds
 * unless $force. Returns what was done.
 */
function bank_sync(bool $force = false): array
{
    static $checked = 0.0;
    if (isset($GLOBALS['bank_batch']) || (!$force && microtime(true) - $checked < 2.0 && PHP_SAPI === 'cli')) {
        return [];
    }
    $checked = microtime(true);
    if (!$force) {
        $scan = bank_scan();
        $known = array_map(fn($f) => $f['sig'] ?? '', read_json(bank_state_file(), [])['files'] ?? []);
        if ($scan === array_intersect_key($known, $scan) && count($scan) === count($known) && is_file(bank_index_file()) === (bool)$scan) {
            return [];
        }
    }
    return with_lock('bank', fn() => bank_sync_locked($force));
}

/** bank_sync() with the lock held. $force reads every file again. The files are only read. */
function bank_sync_locked(bool $force): array
{
    $scan = bank_scan();
    $state = read_json(bank_state_file(), []);
    $files = $state['files'] ?? [];
    $changed = array_keys(array_filter($scan, fn($sig, $cat) => $force || ($files[$cat]['sig'] ?? null) !== $sig, ARRAY_FILTER_USE_BOTH));
    $removed = array_values(array_diff(array_keys($files), array_keys($scan)));
    $summary = ['files' => count($changed), 'removed' => count($removed)];
    if (!$changed && !$removed && (is_file(bank_index_file()) || !$scan)) {
        return $summary;
    }

    $index = is_file(bank_index_file()) ? bank_read_index() : [];
    $touched = array_flip(array_merge($changed, $removed));
    $rows = array_filter($index, fn($row) => !isset($touched[$row['category']]));
    foreach ($changed as $category) {
        unset($files[$category]);
        if (normalize_category($category) !== $category) {
            $files[$category] = ['sig' => $scan[$category], 'problems' => [['level' => 'error', 'line' => 0,
                'message' => 'The name "' . $category . '" has characters the bank does not allow; rename it to "' . normalize_category($category) . '".']]];
            continue;
        }
        $parsed = bank_parse(bank_file_content($category), $category);
        $problems = [];
        foreach ($parsed['messages'] as $m) {
            if ($m['level'] !== 'info') {
                $problems[] = ['level' => $m['level'], 'line' => $m['line'], 'message' => $m['message']];
            }
        }
        foreach (bank_keyed($parsed['questions'], $category) as $q) {
            if (isset($rows[$q['key']])) {
                // Two titles in different folders with the same 10-character key: practically never
                $problems[] = ['level' => 'error', 'line' => $q['line'], 'message' => 'This question has the same hidden key as a question in '
                    . $rows[$q['key']]['category'] . ', so it is left out. Change its title a little.'];
                continue;
            }
            $rows[$q['key']] = bank_index_row($q, $category);
        }
        $files[$category] = ['sig' => $scan[$category], 'problems' => $problems];
    }
    foreach ($removed as $category) {
        unset($files[$category]);
    }
    ksort($files, SORT_NATURAL);
    bank_write_index($rows);
    write_json(bank_state_file(), ['files' => $files]);
    return $summary;
}

// ---------- Questions ----------

/** A question as the app uses it: key, content, id number, tags, hidden, Moodle entry, category, section and number. */
function bank_load_record(array $parsed, string $category): array
{
    $q = ['id' => $parsed['key']] + question_content($parsed);
    $q['idnumber'] = (string)($parsed['idnumber'] ?? '');
    $q['tags'] = $parsed['tags'] ?? [];
    $q['hidden'] = !empty($parsed['hidden']);
    if (isset($parsed['zion']['moodle'])) {
        $q['moodle'] = $parsed['zion']['moodle'];
    }
    $q['category'] = $category;
    $q['section'] = $parsed['section'];
    $q['number'] = $parsed['number'];
    return $q;
}

/** Questions stored in one category folder (not sub-folders), in file order. */
function category_questions(string $category, bool $includeHidden = true): array
{
    $out = [];
    foreach (bank_file_questions($category) as $parsed) {
        if ($includeHidden || empty($parsed['hidden'])) {
            $out[] = bank_load_record($parsed, $category);
        }
    }
    return $out;
}

/** The parsed block of question $key in a category file, or null. */
function bank_find(string $category, string $key): ?array
{
    foreach (bank_file_questions($category) as $parsed) {
        if ($parsed['key'] === $key) {
            return $parsed;
        }
    }
    return null;
}

/** Load a question (with 'category', 'section' and 'number') or null. */
function load_question(string $key): ?array
{
    if (!is_question_key($key)) {
        return null;
    }
    $row = bank_index()[$key] ?? null;
    $parsed = $row ? bank_find($row['category'], $key) : null;
    return $parsed ? bank_load_record($parsed, $row['category']) : null;
}

/**
 * The bank question an exam's copy came from, or null: by its key, else by folder and title
 * (copies made before questions had keys, and Moodle papers).
 */
function bank_question_for(array $copy): ?array
{
    $category = normalize_category((string)($copy['category'] ?? ''));
    $key = (string)($copy['id'] ?? '');
    if (is_question_key($key) && ($q = load_question($key)) && ($category === '' || $q['category'] === $category)) {
        return $q;
    }
    if ($category === '' || !isset($copy['name'])) {
        return null;
    }
    $q = load_question(question_key($category, (string)$copy['name']));
    return $q && $q['type'] === ($copy['type'] ?? $q['type']) ? $q : null;
}

/** Label of the bank question an exam's copy came from: its own when it has one, else the bank's today. */
function question_source_label(array $copy): string
{
    $label = question_label($copy);
    if ($label === '' && ($q = bank_question_for($copy))) {
        $label = question_label($q);
    }
    return $label;
}

/** Only the content fields, in a fixed order (for comparisons). */
function question_content(array $q): array
{
    $out = [];
    foreach (QUESTION_CONTENT_KEYS as $k) {
        if (array_key_exists($k, $q)) {
            $out[$k] = $q[$k];
        }
    }
    return $out;
}

/** GIFT text of a question as it is kept in the bank file. */
function bank_question_gift(array $q): string
{
    return GiftWriter::question($q, ['header' => false, 'meta' => GiftWriter::ALL_META]);
}

/** $q as it reads back from its GIFT (the form the bank stores). */
function bank_canonical(array $q): array
{
    $result = (new GiftParser())->parse(bank_question_gift($q), 'bank');
    $errors = array_column(array_filter($result['messages'], fn($m) => $m['level'] === 'error'), 'message');
    if ($errors || count($result['questions']) !== 1) {
        throw new InvalidArgumentException('The question cannot be saved as GIFT: ' . ($errors ? implode(' ', $errors) : count($result['questions']) . ' questions'));
    }
    return $result['questions'][0];
}

/**
 * Write question $q into its category file.
 * $block: the block it replaces in that file (null appends). Returns the stored question,
 * whose key follows its (possibly changed) title.
 */
function bank_put(array $q, string $category, ?array $block = null, string $section = ''): array
{
    bank_canonical($q);   // throws when it cannot be written as GIFT
    $lines = bank_lines(bank_file_content($category));
    $gift = bank_question_gift($q);
    $lines = $block ? bank_replace_block($lines, $block, $gift) : bank_append_block($lines, $category, $gift, $section);
    bank_store_lines($category, $lines);
    $keyed = bank_file_questions($category);
    bank_index_category($category, $keyed);
    $stored = $block ? null : end($keyed);   // a new question is the last one of the file
    foreach ($block ? $keyed : [] as $parsed) {
        if ($parsed['block'][0] === $block[0]) {
            $stored = $parsed;
            break;
        }
    }
    if (!$stored) {
        throw new RuntimeException("The question saved in $category cannot be read back.");
    }
    return bank_load_record($stored, $category);
}

/**
 * Save a question into $category. A question with the key of an existing one replaces it in
 * place; anything else is added at the end of the file. Returns the saved question.
 */
function save_question(array $q, string $category): array
{
    $category = normalize_category($category);
    if ($category === '') {
        throw new InvalidArgumentException('A question needs a category.');
    }
    return bank_locked(function () use ($q, $category) {
        $existing = isset($q['id']) ? load_question((string)$q['id']) : null;
        if ($existing !== null && $existing['category'] !== $category) {
            throw new InvalidArgumentException('Use move_question() to change the category.');
        }
        $clean = question_content($q) + [
            'idnumber' => (string)($q['idnumber'] ?? ''), 'tags' => array_values($q['tags'] ?? []), 'hidden' => !empty($q['hidden']),
            'moodle' => $q['moodle'] ?? $existing['moodle'] ?? null,
        ];
        $block = $existing ? (bank_find($category, $existing['id'])['block'] ?? null) : null;
        return bank_put($clean, $category, $block, (string)($q['section'] ?? ''));
    });
}

function set_question_hidden(string $key, bool $hidden): ?array
{
    return bank_locked(function () use ($key, $hidden) {
        $q = load_question($key);
        if (!$q) {
            return null;
        }
        $q['hidden'] = $hidden;
        return save_question($q, $q['category']);
    });
}

/** Move a question (and the pictures it uses) to the end of another category. Returns it with its new key. */
function move_question(string $key, string $toCategory): ?array
{
    $toCategory = normalize_category($toCategory);
    return bank_locked(function () use ($key, $toCategory) {
        $q = load_question($key);
        if (!$q || $toCategory === '' || $toCategory === $q['category']) {
            return $q;
        }
        $from = $q['category'];
        $names = [];
        foreach (question_media_refs($q) as $ref) {
            $src = bank_file_dir($from) . '/' . $ref;
            if (bank_picture_path($ref) !== null && is_file($src)) {
                $names[$ref] = 'media/' . store_unique_file(bank_file_dir($toCategory) . '/media', basename($ref), file_get_contents($src));
            }
        }
        $q = rewrite_media_refs($q, fn($ref) => $names[$ref] ?? null);
        $block = bank_find($from, $key)['block'] ?? null;
        if ($block) {
            bank_store_lines($from, bank_remove_block(bank_lines(bank_file_content($from)), $block));
            bank_index_category($from, bank_file_questions($from));
        }
        $clean = question_content($q) + ['idnumber' => $q['idnumber'], 'tags' => $q['tags'], 'hidden' => $q['hidden'], 'moodle' => $q['moodle'] ?? null];
        return bank_put($clean, $toCategory);
    });
}

/**
 * Remove a category that has no questions left: its .gift file holds nothing but $CATEGORY and
 * blank lines, and no category below it has a file. Folders left without any .gift file below
 * them go too, with the pictures in them. Returns whether it was removed.
 */
function bank_remove_empty_folder(string $category): bool
{
    return bank_locked(function () use ($category) {
        $lines = array_filter(bank_lines(bank_file_content($category)), fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '$CATEGORY:'));
        $below = array_filter(array_keys(bank_scan()), fn($c) => str_starts_with($c, "$category/"));
        if ($category === '' || !is_file(bank_gift_file($category)) || $lines || $below) {
            return false;
        }
        unlink(bank_gift_file($category));
        if (is_dir(bank_path($category))) {
            delete_tree(bank_path($category));
        }
        for ($parent = dirname($category); $parent !== '.' && $parent !== ''; $parent = dirname($parent)) {
            if (!is_dir(bank_path($parent)) || bank_dir_has_gift(bank_path($parent))) {
                break;
            }
            delete_tree(bank_path($parent));
        }
        bank_sync_locked(false);
        return true;
    });
}

/** Whether a bank folder has a .gift file in it or below it. */
function bank_dir_has_gift(string $dir): bool
{
    foreach (@scandir($dir) ?: [] as $name) {
        if ($name[0] === '.') {
            continue;
        }
        if (str_ends_with($name, BANK_EXT) ? is_file("$dir/$name") : (is_dir("$dir/$name") && bank_dir_has_gift("$dir/$name"))) {
            return true;
        }
    }
    return false;
}

/**
 * Category tree with question counts, limited to what $user may manage.
 * Returns [path => ['count' => n in folder, 'total' => n including sub-folders, 'subject' => ?string]].
 */
function category_tree(?array $user): array
{
    $counts = [];
    foreach (bank_index() as $row) {
        $counts[$row['category']] = ($counts[$row['category']] ?? 0) + ($row['hidden'] === '1' ? 0 : 1);
    }
    $tree = [];
    foreach ($counts as $path => $n) {
        $subject = subject_from_path($path);
        if (!can_manage_subject($user, $subject)) {
            continue;
        }
        $parts = explode('/', $path);
        for ($i = 1; $i <= count($parts); $i++) {
            $p = implode('/', array_slice($parts, 0, $i));
            $tree[$p] ??= ['count' => 0, 'total' => 0, 'subject' => subject_from_path($p)];
            $tree[$p]['total'] += $n;
        }
        $tree[$path]['count'] += $n;
    }
    uksort($tree, 'strnatcasecmp');
    return $tree;
}

/** Normalised content used to spot duplicates (not stored). */
function question_duplicate_key(array $q): string
{
    $norm = fn($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s)));
    $c = question_content($q);
    unset($c['name']);
    array_walk_recursive($c, function (&$v) use ($norm) {
        if (is_string($v)) {
            $v = $norm($v);
        }
    });
    return json_encode($c, JSON_UNESCAPED_UNICODE);
}

/** Apply $fn to every text field that may contain HTML (question text, answers, feedback, blanks). */
function map_question_texts(array $q, callable $fn): array
{
    foreach (['text', 'general_feedback', 'feedback_true', 'feedback_false', 'grader_info'] as $k) {
        if (isset($q[$k]) && is_string($q[$k])) {
            $q[$k] = $fn($q[$k]);
        }
    }
    foreach (['answers', 'pairs'] as $list) {
        foreach ($q[$list] ?? [] as $i => $item) {
            foreach (['text', 'feedback', 'question'] as $k) {
                if (isset($item[$k]) && is_string($item[$k])) {
                    $q[$list][$i][$k] = $fn($item[$k]);
                }
            }
        }
    }
    foreach ($q['blanks'] ?? [] as $i => $blank) {
        $q['blanks'][$i] = map_question_texts($blank, $fn);
    }
    return $q;
}

/** Picture references found in a text: <img src>, markdown images, @@PLUGINFILE@@. */
function find_picture_refs(string $text): array
{
    $refs = [];
    if (preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/i', $text, $m)) {
        $refs = array_merge($refs, $m[2]);
    }
    if (preg_match_all('/!\[[^\]]*\]\(\s*([^)\s]+)/', $text, $m)) {
        $refs = array_merge($refs, $m[1]);
    }
    return array_values(array_unique(array_filter($refs, fn($r) => !preg_match('#^(https?:|data:)#i', trim($r)))));
}

/** Relative picture paths (media/...) used by a stored question. */
function question_media_refs(array $q): array
{
    $refs = [];
    map_question_texts($q, function ($text) use (&$refs) {
        foreach (find_picture_refs($text) as $r) {
            $refs[] = html_entity_decode($r, ENT_QUOTES, 'UTF-8');
        }
        return $text;
    });
    return array_values(array_unique($refs));
}

/** Replace picture references using $map(ref) -> new ref (or null to keep). */
function rewrite_media_refs(array $q, callable $map): array
{
    $replace = function (string $text) use ($map) {
        $text = preg_replace_callback('/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2/i', function ($m) use ($map) {
            $new = $map(html_entity_decode($m[3], ENT_QUOTES, 'UTF-8'));
            return $new === null ? $m[0] : $m[1] . $m[2] . htmlspecialchars($new, ENT_QUOTES, 'UTF-8') . $m[2];
        }, $text);
        return preg_replace_callback('/(!\[[^\]]*\]\(\s*)([^)\s]+)/', function ($m) use ($map) {
            $new = $map($m[2]);
            return $new === null ? $m[0] : $m[1] . $new;
        }, $text);
    };
    return map_question_texts($q, $replace);
}

/**
 * A picture path from a bank category's folder, such as media/icon.png or images/ch-1/vga.jpg, or null
 * when it is not safe: every folder and file name must be kept as it is by sanitize_filename (so no
 * "..", no leading dots, no spaces), and the file must be a picture.
 */
function bank_picture_path(string $ref): ?string
{
    foreach (explode('/', $ref) as $part) {
        if ($part === '' || $part !== sanitize_filename($part)) {
            return null;
        }
    }
    return in_array(strtolower(pathinfo($ref, PATHINFO_EXTENSION)), IMPORT_IMAGE_EXT, true) ? $ref : null;
}

/** URL builder for pictures of a bank category (used by format_text). */
function bank_media_url(string $category): callable
{
    return function (string $src) use ($category) {
        if (bank_picture_path($src) === null) {
            return null;
        }
        return url('media.php') . '?c=' . rawurlencode($category) . '&f=' . rawurlencode($src);
    };
}

/**
 * GIFT text of each category's visible questions: [<category>.gift => text]. Picture paths stay
 * relative to the file's folder, as in the bank, so a ZIP keeps the pictures at the same paths.
 */
function export_categories_gift(array $categories): array
{
    $files = [];
    foreach ($categories as $category) {
        // Without the hidden keys: the "// question:" line of an export says 0, as for a new question
        $qs = array_values(array_map(fn($q) => array_diff_key($q, ['id' => 1]), array_filter(category_questions($category), fn($q) => empty($q['hidden']))));
        if (!$qs) {
            continue;
        }
        $files[$category . '.gift'] = GiftWriter::file(['$course$/' . $category => $qs], ['meta' => GiftWriter::SETTINGS_META]);
    }
    return $files;
}
