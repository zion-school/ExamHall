<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 17-Sep-2026
 *
 * Copy a folder of GIFT files, one file per chapter, into the question bank, as often as needed:
 *   <source>/V/Term-2/Maths/04_Measurements.gift  ->  data/bank/V/Term-2/Maths/04_Measurements.gift
 *
 * Usage: php bin/bank_copy.php <source folder> [--apply] [--skip=V/Term-3/Science/01_Our_Environment ...]
 *                              [--unskip=PATH ...] [--only=V/Computer-Science ...] [--force]
 *   (no --apply)  only lists what would change
 *   --only        copy only these folders (remembered for later runs; --only= alone copies everything
 *                 again); chapters outside them are neither copied nor removed
 *   --skip        leave a chapter out (its path without .gift; remembered for later runs)
 *   --unskip      copy it again
 *   --force       also overwrite or remove bank files that were changed in the app since the last copy
 *
 * The bank files hold no question ids, so a question keeps its key while its folder and title stay
 * the same, and exams keep their own copies anyway. Left out: template.gift, files with no
 * questions, other file types. Characters the bank does not allow in folder names (such as
 * apostrophes) are dropped.
 * Pictures: a question writes a path relative to the .gift file, such as <img src="media/icon.png"> or
 * <img src="images/ch-1/vga.jpg"> (folders next to the chapter files, shared by them). Each picture a
 * chapter uses is copied to the same path in the bank (bank/V/Term-2/Maths/media/icon.png), so the
 * copied file finds it. A picture goes when no copied chapter of its folder uses it any more.
 * A chapter with a missing picture, or an unsafe path ("../x.png", names with spaces), is not copied
 * and stays in the bank as it was.
 * A chapter deleted, renamed or skipped in the source is removed from the bank. Only chapters this
 * command made are touched: chapters from the Moodle migration or made in the app stay as they are.
 * What was copied, and the skip list, are kept in data/bank/.copy.json.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';

$source = null;
$apply = $force = false;
$skipAdd = $skipRemove = [];
$onlyGiven = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif (str_starts_with($arg, '--skip=')) {
        $skipAdd[] = trim(preg_replace('/\.gift$/i', '', substr($arg, 7)), '/');
    } elseif (str_starts_with($arg, '--unskip=')) {
        $skipRemove[] = trim(preg_replace('/\.gift$/i', '', substr($arg, 9)), '/');
    } elseif (str_starts_with($arg, '--only=')) {
        $onlyGiven ??= [];
        foreach (explode(',', substr($arg, 7)) as $path) {
            if (($path = trim($path, '/ ')) !== '') {
                $onlyGiven[] = normalize_category($path);
            }
        }
    } elseif ($source === null && !str_starts_with($arg, '--')) {
        $source = rtrim($arg, '/');
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}
if ($source === null || !is_dir($source)) {
    fwrite(STDERR, "Usage: php bin/bank_copy.php <source folder> [--apply] [--skip=PATH ...] [--unskip=PATH ...] [--only=FOLDER ...] [--force]\n");
    exit(2);
}

$recordFile = data_path('bank', '.copy.json');
$record = read_json($recordFile, []) + ['only' => [], 'skip' => [], 'copied' => []];
$only = $onlyGiven === null ? $record['only'] : array_values(array_unique($onlyGiven));
sort($only, SORT_NATURAL);
// Whether a chapter is in the folders to copy
$inScope = function (string $category) use ($only): bool {
    foreach ($only as $folder) {
        if ($category === $folder || str_starts_with($category, $folder . '/')) {
            return true;
        }
    }
    return !$only;
};
$skip = array_values(array_diff(array_unique(array_merge($record['skip'], $skipAdd)), $skipRemove));
sort($skip, SORT_NATURAL);
$skipSet = array_flip($skip);

// Chapters in the source: category => [relative path, content, duplicate, pictures (path => source file), problem]
$chapters = [];
$notes = [];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    fn($f) => $f->getFilename()[0] !== '.'
));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($source) + 1);
    if (strtolower($f->getExtension()) !== 'gift' || $rel === 'template.gift') {
        continue;
    }
    $stem = substr($rel, 0, -5);
    if (isset($skipSet[$stem]) || !$inScope(normalize_category($stem))) {
        continue;
    }
    $content = (string)file_get_contents($f->getPathname());
    $questions = (new GiftParser())->parse($content, $rel)['questions'];
    if (!$questions) {
        $notes[] = "no questions, left out: $rel";
        continue;
    }
    $pictures = $bad = [];
    foreach ($questions as $q) {
        foreach (question_media_refs($q) as $ref) {
            if (bank_picture_path($ref) === null) {
                $bad[$ref] = "$ref is not a picture path the bank can keep (letters, digits, . _ - and / only)";
            } elseif (!is_file($file = dirname($f->getPathname()) . "/$ref")) {
                $bad[$ref] = "$ref is missing";
            } else {
                $pictures[$ref] = $file;
            }
        }
    }
    ksort($pictures, SORT_NATURAL);
    $category = normalize_category($stem);
    if (isset($chapters[$category])) {
        $notes[] = "left out: $rel and {$chapters[$category][0]} would both be bank/$category.gift";
        $chapters[$category][2] = true;
        continue;
    }
    $chapters[$category] = [$rel, $content, false, $pictures, $bad ? implode(', ', $bad) : null];
}
$chapters = array_filter($chapters, fn($c) => !$c[2]);
ksort($chapters, SORT_NATURAL);

// Compare with the bank
$current = fn(string $category) => is_file(bank_gift_file($category)) ? (string)file_get_contents(bank_gift_file($category)) : null;
$changedInApp = fn(string $category) => isset($record['copied'][$category]) && ($now = $current($category)) !== null
    && sha1($now) !== $record['copied'][$category]['sha1'];
$folderOf = fn(string $category) => dirname($category) === '.' ? '' : dirname($category);
// Whether another chapter of the same folder uses a picture after this run (chapters in the source, or
// chapters kept as they are because they were changed in the app)
$usedByOthers = function (string $category, string $path) use ($chapters, $record, $folderOf, $changedInApp, $force): bool {
    foreach (array_keys($chapters + $record['copied']) as $other) {
        if ($other === $category || $folderOf($other) !== $folderOf($category)) {
            continue;
        }
        $pictures = isset($chapters[$other]) ? $chapters[$other][3]
            : ($changedInApp($other) && !$force ? $record['copied'][$other]['pictures'] ?? [] : []);
        if (isset($pictures[$path])) {
            return true;
        }
    }
    return false;
};
// Pictures of a chapter: what to copy (path => '+' new or '~' changed), what to remove, and paths
// already in its folder with other content that this command did not put there
$picturePlan = function (string $category, array $pictures) use ($record, $folderOf, $usedByOthers): array {
    $copied = $record['copied'][$category]['pictures'] ?? [];
    $owned = [];
    foreach ($record['copied'] as $other => $info) {
        if ($folderOf($other) === $folderOf($category)) {
            $owned += $info['pictures'] ?? [];
        }
    }
    $plan = ['copy' => [], 'remove' => [], 'foreign' => []];
    foreach ($pictures as $path => $file) {
        $target = bank_file_dir($category) . "/$path";
        if (!is_file($target)) {
            $plan['copy'][$path] = '+';
        } elseif (sha1_file($target) !== sha1_file($file)) {
            $plan['copy'][$path] = '~';
            if (!isset($owned[$path])) {
                $plan['foreign'][] = $path;
            }
        }
    }
    foreach (array_diff_key($copied, $pictures) as $path => $sha1) {
        if (is_file(bank_file_dir($category) . "/$path") && !$usedByOthers($category, $path)) {
            $plan['remove'][] = $path;
        }
    }
    return $plan;
};
$claimed = [];
$plan = ['add' => [], 'update' => [], 'remove' => [], 'kept' => [], 'same' => [], 'pictures' => []];
foreach ($chapters as $category => [$rel, $content, , $pictures, $problem]) {
    $now = $current($category);
    $pics = $picturePlan($category, $pictures);
    if ($problem !== null) {
        $plan['kept'][] = "bank/$category.gift " . ($now === null ? 'is not copied' : 'stays as it is') . ": $rel: $problem";
    } elseif ($now === $content && !$pics['copy'] && !$pics['remove']) {
        $plan['same'][$category] = $rel;
    } elseif (!isset($record['copied'][$category]) && $now !== null && $now !== $content) {
        $plan['kept'][] = "bank/$category.gift already exists and was not made by this command";
    } elseif ($changedInApp($category) && !$force) {
        $plan['kept'][] = "bank/$category.gift was changed in the app since the last copy (--force overwrites it)";
    } elseif ($pics['foreign'] && !$force) {
        $plan['kept'][] = "bank/$category.gift has other pictures at " . implode(', ', $pics['foreign']) . ' that were not made by this command (--force overwrites them)';
    } else {
        $plan[$now === null ? 'add' : 'update'][$category] = $rel;
        // A picture shared by chapters is copied (and listed) with the first of them
        foreach (array_keys($pics['copy']) as $path) {
            if (isset($claimed[$target = bank_file_dir($category) . "/$path"])) {
                unset($pics['copy'][$path]);
            }
            $claimed[$target] = true;
        }
        $plan['pictures'][$category] = $pics;
    }
}

foreach (array_diff_key(array_filter($record['copied'], $inScope, ARRAY_FILTER_USE_KEY), $chapters) as $category => $info) {
    if ($changedInApp($category) && !$force) {
        $plan['kept'][] = "bank/$category.gift was changed in the app since the last copy, so it stays although {$info['from']} is gone or skipped (--force removes it)";
    } else {
        $plan['remove'][$category] = $info['from'];
        $plan['pictures'][$category] = $picturePlan($category, []);
    }
}

natcasesort($notes);
foreach ($notes as $n) {
    echo "  note: $n\n";
}
foreach ($plan['kept'] as $k) {
    echo "  kept: $k\n";
}
$pictureCount = ['copy' => 0, 'remove' => 0];
foreach (['add' => '+', 'update' => '~', 'remove' => '-'] as $what => $sign) {
    foreach ($plan[$what] as $category => $rel) {
        echo "  $sign bank/$category.gift" . ($rel !== $category . '.gift' ? "  ($rel)" : '') . "\n";
        foreach ($plan['pictures'][$category]['copy'] ?? [] as $path => $picSign) {
            echo "      $picSign $path\n";
            $pictureCount['copy']++;
        }
        foreach ($plan['pictures'][$category]['remove'] as $path) {
            echo "      - $path\n";
            $pictureCount['remove']++;
        }
    }
}
if ($only) {
    echo '  only: ' . implode(', ', $only) . "\n";
}
printf("%d to add, %d to update, %d to remove, %d unchanged, %d kept, %d skipped; %d pictures to copy, %d to remove\n", count($plan['add']), count($plan['update']),
    count($plan['remove']), count($plan['same']), count($plan['kept']), count($skip), $pictureCount['copy'], $pictureCount['remove']);

if (!$apply) {
    echo $plan['add'] || $plan['update'] || $plan['remove'] || $skip !== $record['skip'] || $only !== $record['only'] ? "Nothing written. Run again with --apply to copy.\n" : "The bank is up to date.\n";
    exit(0);
}

with_lock('bank', function () use ($plan, $chapters, &$record, $skip, $only) {
    // Remove a file and the folders left empty above it, up to $top
    $removeFile = function (string $file, string $top) {
        @unlink($file);
        for ($dir = dirname($file); $dir !== $top && str_starts_with($dir, $top . '/'); $dir = dirname($dir)) {
            if (!is_dir($dir) || count(scandir($dir)) > 2) {
                break;
            }
            rmdir($dir);
        }
    };
    foreach ($plan['add'] + $plan['update'] as $category => $rel) {
        // Pictures first, so the questions never point at a picture that is not there yet
        foreach (array_keys($plan['pictures'][$category]['copy']) as $path) {
            ensure_dir(dirname(bank_file_dir($category) . "/$path"));
            write_file_atomic(bank_file_dir($category) . "/$path", (string)file_get_contents($chapters[$category][3][$path]));
        }
        ensure_dir(dirname(bank_gift_file($category)));
        write_file_atomic(bank_gift_file($category), $chapters[$category][1]);
    }
    // Unchanged chapters count as copied too (also when .copy.json was lost)
    foreach ($plan['add'] + $plan['update'] + $plan['same'] as $category => $rel) {
        $record['copied'][$category] = ['from' => $rel, 'sha1' => sha1($chapters[$category][1])]
            + ($chapters[$category][3] ? ['pictures' => array_map('sha1_file', $chapters[$category][3])] : []);
    }
    foreach (array_keys($plan['remove']) as $category) {
        unset($record['copied'][$category]);
    }
    // Pictures no chapter uses any more, then the chapters removed; the folders they leave empty go too
    foreach ($plan['pictures'] as $category => $pics) {
        foreach ($pics['remove'] as $path) {
            $removeFile(bank_file_dir($category) . "/$path", bank_path());
        }
    }
    foreach (array_keys($plan['remove']) as $category) {
        $removeFile(bank_gift_file($category), bank_path());
    }
    ksort($record['copied'], SORT_NATURAL);
    $record['only'] = $only;
    $record['skip'] = $skip;
    write_json(data_path('bank', '.copy.json'), $record);
});
bank_sync(true);
echo 'Copied. The bank has ' . count(bank_index()) . " questions.\n";
foreach (bank_problems() as $category => $list) {
    if (isset($record['copied'][$category])) {
        foreach ($list as $p) {
            if ($p['level'] === 'error') {
                echo "  error: $category line {$p['line']}: {$p['message']}\n";
            }
        }
    }
}
