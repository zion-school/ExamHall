<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Question bank access for scripts in other languages (tools/moodle_migrate.py).
 *   php bin/bank_tool.php list     JSON lines: {id, category, hidden, moodle} (id: the hidden key)
 *   php bin/bank_tool.php store    reads JSON {"questions": [{category, versions: [question...]}]} on stdin:
 *                                  one item per Moodle question, its versions oldest first. Each has
 *                                  moodle (the entry id), content fields, tags, idnumber and hidden.
 *                                  A question already brought from that entry is found by its moodle= setting.
 *                                  The last version goes into the category's .gift file, unless the
 *                                  bank's copy was changed in Exams (it matches none of the versions) or
 *                                  moved to another folder of the same subject: those stay as they are.
 *                                  A question in a folder of another subject is moved (with its pictures);
 *                                  folders left without questions are removed.
 *                                  Prints {"stored": n, "moved": n, "kept_edited": n, "kept_moved": n,
 *                                  "removed_folders": [...], "keys": {entry: key}}.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';

$command = $argv[1] ?? '';

/** [moodle entry => category] of every question brought from Moodle. */
function moodle_places(): array
{
    $out = [];
    foreach (array_unique(array_column(bank_index(), 'category')) as $category) {
        foreach (bank_file_questions($category) as $parsed) {
            if (isset($parsed['zion']['moodle'])) {
                $out[(int)$parsed['zion']['moodle']] = $category;
            }
        }
    }
    return $out;
}

/** The question of Moodle entry $entry in $category, or null. */
function moodle_question(string $category, int $entry): ?array
{
    foreach (bank_file_questions($category) as $parsed) {
        if (($parsed['zion']['moodle'] ?? null) === $entry) {
            return bank_load_record($parsed, $category);
        }
    }
    return null;
}

if ($command === 'list') {
    foreach (array_unique(array_column(bank_index(), 'category')) as $category) {
        foreach (category_questions($category) as $q) {
            echo json_encode([
                'id' => $q['id'], 'category' => $category, 'hidden' => $q['hidden'], 'moodle' => $q['moodle'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        }
    }
    exit(0);
}

if ($command === 'store') {
    $input = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($input) || !is_array($input['questions'] ?? null)) {
        fwrite(STDERR, "Expected {\"questions\": [...]} on stdin\n");
        exit(1);
    }
    $stored = $moved = $keptEdited = $keptMoved = 0;
    $emptied = [];
    $places = moodle_places();
    // What an import writes of a question, as the bank reads it back
    $written = function (array $q): ?array {
        try {
            $c = bank_canonical($q);
        } catch (InvalidArgumentException $e) {
            return null;
        }
        return [question_content($c), $c['tags'] ?? [], !empty($c['hidden']), (string)($c['idnumber'] ?? '')];
    };
    bank_batch(function () use ($input, $written, &$places, &$stored, &$moved, &$keptEdited, &$keptMoved, &$emptied) {
        foreach ($input['questions'] as $item) {
            $category = normalize_category((string)($item['category'] ?? ''));
            $versions = $item['versions'] ?? [];
            $latest = $versions ? end($versions) : [];
            $entry = (int)($latest['moodle'] ?? 0);
            if ($category === '' || !$versions || $entry < 1) {
                continue;
            }
            unset($latest['id']);
            $existing = isset($places[$entry]) ? moodle_question($places[$entry], $entry) : null;
            if ($existing) {
                $current = $written($existing);
                if (!array_filter($versions, fn($v) => $written($v) == $current)) {
                    $keptEdited++;
                    continue;
                }
                if ($existing['category'] !== $category) {
                    if (subject_from_path($existing['category']) === subject_from_path($category)) {
                        $keptMoved++;
                        continue;
                    }
                    $emptied[$existing['category']] = true;
                    move_question($existing['id'], $category);
                    $existing = moodle_question($category, $entry);
                    $moved++;
                }
            }
            $block = $existing ? (bank_find($category, $existing['id'])['block'] ?? null) : null;
            bank_put($latest, $category, $block);
            $places[$entry] = $category;
            $stored++;
        }
    });
    $removed = [];
    $emptied = array_keys($emptied);
    usort($emptied, fn($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));   // sub-folders first
    foreach ($emptied as $category) {
        if (bank_remove_empty_folder($category)) {
            $removed[] = $category;
        }
    }
    // Keys once everything is written (a title changed by this run changes its key)
    $keys = [];
    foreach (moodle_places() as $entry => $category) {
        $keys[$entry] = moodle_question($category, $entry)['id'];
    }
    echo json_encode(['stored' => $stored, 'moved' => $moved, 'kept_edited' => $keptEdited, 'kept_moved' => $keptMoved,
        'removed_folders' => $removed, 'keys' => (object)$keys]), "\n";
    exit(0);
}

fwrite(STDERR, "Usage: php bin/bank_tool.php list|store\n");
exit(1);
