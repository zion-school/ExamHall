<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * GIFT import: upload (single file, folder in chunks, ZIP) -> analyse -> preview -> commit.
 *
 *   data/imports/import-YYYYMMDD-HHMM-n/meta.json    who, status, options
 *   data/imports/import-YYYYMMDD-HHMM-n/files/...    uploaded files with their folder paths
 *   data/imports/import-YYYYMMDD-HHMM-n/report.json  analysis shown in the preview
 */

defined('EXAMS_APP') or exit;

const IMPORT_GIFT_EXT = ['gift', 'txt'];
const IMPORT_IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
const IMPORT_MAX_FILE_BYTES = 20 * 1024 * 1024;
const IMPORT_MAX_ZIP_BYTES = 200 * 1024 * 1024;
const IMPORT_MAX_TOTAL_BYTES = 400 * 1024 * 1024;
const IMPORT_MAX_FILES = 5000;
const IMPORT_KEEP_DAYS = 14;

function import_dir(string $id): string
{
    if (!preg_match('/^import-\d{8}-\d{4}-\d+$/', $id)) {
        throw new InvalidArgumentException('Bad import id');
    }
    return data_path('imports', $id);
}

function import_meta(string $id): ?array
{
    try {
        return read_json(import_dir($id) . '/meta.json');
    } catch (InvalidArgumentException $e) {
        return null;
    }
}

function import_can_access(array $user, ?array $meta): bool
{
    return $meta !== null && ($user['role'] === 'admin' || $meta['created_by'] === $user['username']);
}

/** Start an import. $mode: 'folders' (category from folder/file names) or 'gift' ($CATEGORY lines). */
function import_start(array $user, string $base, string $mode, bool $stripTop = false): string
{
    import_cleanup_old();
    $id = create_import_id();
    write_json(import_dir($id) . '/meta.json', [
        'id' => $id,
        'created' => now_iso(),
        'created_by' => $user['username'],
        'status' => 'uploading',
        'base' => normalize_category($base),
        'mode' => $mode === 'gift' ? 'gift' : 'folders',
        'strip_top' => $stripTop,
        'files' => 0,
        'bytes' => 0,
    ]);
    return $id;
}

function import_cleanup_old(): void
{
    foreach (list_dirs(data_path('imports')) as $dir) {
        $meta = read_json(data_path('imports', $dir, 'meta.json'));
        if ($meta && strtotime($meta['created']) < time() - IMPORT_KEEP_DAYS * 86400) {
            delete_tree(data_path('imports', $dir));
        }
    }
}

/** Safe relative path inside an upload, or null. Hidden files and macOS metadata are skipped. */
function import_safe_path(string $path): ?string
{
    $segments = [];
    foreach (preg_split('#[/\\\\]+#', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..' || $seg[0] === '.' || $seg === '__MACOSX' || str_contains($seg, "\0")) {
            return null;
        }
        $clean = preg_replace('/[^\p{L}\p{M}\p{N} ._\'()&,+-]+/u', '_', $seg);
        $segments[] = trim($clean) === '' ? '_' : $clean;
    }
    return $segments ? implode('/', $segments) : null;
}

function import_extension(string $path): string
{
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

/**
 * Add one uploaded file. ZIP files are extracted in place.
 * Returns a list of error strings (empty on success).
 */
function import_add_file(string $id, string $relPath, string $tmpFile): array
{
    $dir = import_dir($id);
    return with_lock($dir . '/meta.json', function () use ($id, $dir, $relPath, $tmpFile) {
        $meta = import_meta($id);
        if (!$meta || $meta['status'] !== 'uploading') {
            return ['This import is no longer accepting files.'];
        }
        $path = import_safe_path($relPath);
        $ext = $path ? import_extension($path) : '';
        $size = is_file($tmpFile) ? filesize($tmpFile) : 0;
        if ($path === null) {
            return ["Skipped \"$relPath\" (hidden or unsafe name)."];
        }
        if ($ext === 'zip') {
            if ($size > IMPORT_MAX_ZIP_BYTES) {
                return ["\"$relPath\" is larger than " . (IMPORT_MAX_ZIP_BYTES >> 20) . ' MB.'];
            }
            // Contents go next to where the ZIP was uploaded (no extra folder for the ZIP name)
            $prefix = dirname($path) === '.' ? '' : dirname($path);
            [$errors, $count, $bytes] = import_extract_zip($tmpFile, $dir . '/files', $prefix, $meta);
            $meta['files'] += $count;
            $meta['bytes'] += $bytes;
            write_file_atomic($dir . '/meta.json', encode_json($meta));
            return $errors;
        }
        if (!in_array($ext, array_merge(IMPORT_GIFT_EXT, IMPORT_IMAGE_EXT), true)) {
            return ["Skipped \"$relPath\" (only .gift, .txt, .zip and pictures are imported)."];
        }
        if ($size > IMPORT_MAX_FILE_BYTES) {
            return ["\"$relPath\" is larger than " . (IMPORT_MAX_FILE_BYTES >> 20) . ' MB.'];
        }
        if ($meta['files'] + 1 > IMPORT_MAX_FILES || $meta['bytes'] + $size > IMPORT_MAX_TOTAL_BYTES) {
            return ['The upload is too large (max ' . IMPORT_MAX_FILES . ' files, ' . (IMPORT_MAX_TOTAL_BYTES >> 20) . ' MB).'];
        }
        $target = $dir . '/files/' . $path;
        ensure_dir(dirname($target));
        if (!(is_uploaded_file($tmpFile) ? move_uploaded_file($tmpFile, $target) : copy($tmpFile, $target))) {
            return ["Could not save \"$relPath\"."];
        }
        $meta['files']++;
        $meta['bytes'] += $size;
        write_file_atomic($dir . '/meta.json', encode_json($meta));
        return [];
    });
}

/**
 * Receive one chunk of a large file (sent in pieces to stay under PHP upload limits).
 * Chunks must arrive in order; a repeated chunk (retry) overwrites from its offset.
 * When $last is true the assembled file is added like a normal upload.
 */
function import_add_chunk(string $id, string $relPath, int $offset, string $tmpFile, bool $last): array
{
    $meta = import_meta($id);
    if (!$meta || $meta['status'] !== 'uploading') {
        return ['This import is no longer accepting files.'];
    }
    $path = import_safe_path($relPath);
    if ($path === null) {
        return ["Skipped \"$relPath\" (hidden or unsafe name)."];
    }
    $part = import_dir($id) . '/partial/' . str_replace('/', '~', $path) . '.part';
    ensure_dir(dirname($part));
    return with_lock($part, function () use ($id, $relPath, $offset, $tmpFile, $last, $part) {
        $size = is_file($part) ? filesize($part) : 0;
        if ($offset > $size) {
            return ["A piece of \"$relPath\" went missing. Please upload it again."];
        }
        if ($offset + filesize($tmpFile) > IMPORT_MAX_ZIP_BYTES) {
            @unlink($part);
            return ["\"$relPath\" is larger than " . (IMPORT_MAX_ZIP_BYTES >> 20) . ' MB.'];
        }
        $fp = fopen($part, 'c');
        ftruncate($fp, $offset);
        fseek($fp, $offset);
        fwrite($fp, (string)file_get_contents($tmpFile));
        fclose($fp);
        if (!$last) {
            return [];
        }
        $errors = import_add_file($id, $relPath, $part);
        @unlink($part);
        return $errors;
    });
}

/** Extract a ZIP safely. Returns [errors, fileCount, bytes]. */
function import_extract_zip(string $zipFile, string $targetDir, string $prefix, array $meta): array
{
    if (!class_exists('ZipArchive')) {
        return [['This server cannot open ZIP files (PHP zip extension missing). Upload the folder instead.'], 0, 0];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        return [['The ZIP file could not be opened.'], 0, 0];
    }
    $errors = [];
    $count = 0;
    $bytes = 0;
    $skipped = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = $stat['name'];
        if (str_ends_with($name, '/')) {
            continue;
        }
        $path = import_safe_path(($prefix !== '' ? "$prefix/" : '') . $name);
        $ext = $path ? import_extension($path) : '';
        if ($path === null || !in_array($ext, array_merge(IMPORT_GIFT_EXT, IMPORT_IMAGE_EXT), true)) {
            $skipped++;
            continue;
        }
        if ($stat['size'] > IMPORT_MAX_FILE_BYTES) {
            $errors[] = "\"$name\" in the ZIP is larger than " . (IMPORT_MAX_FILE_BYTES >> 20) . ' MB.';
            continue;
        }
        if ($meta['files'] + $count + 1 > IMPORT_MAX_FILES || $meta['bytes'] + $bytes + $stat['size'] > IMPORT_MAX_TOTAL_BYTES) {
            $errors[] = 'The ZIP is too large to import (max ' . IMPORT_MAX_FILES . ' files, ' . (IMPORT_MAX_TOTAL_BYTES >> 20) . ' MB unpacked).';
            break;
        }
        $in = $zip->getStream($name);
        if (!$in) {
            $errors[] = "Could not read \"$name\" from the ZIP.";
            continue;
        }
        $target = $targetDir . '/' . $path;
        ensure_dir(dirname($target));
        $out = fopen($target, 'wb');
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            $written += strlen($chunk);
            if ($written > IMPORT_MAX_FILE_BYTES) {   // size in the ZIP header can lie
                break;
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
        if ($written > IMPORT_MAX_FILE_BYTES) {
            unlink($target);
            $errors[] = "\"$name\" in the ZIP is larger than " . (IMPORT_MAX_FILE_BYTES >> 20) . ' MB.';
            continue;
        }
        $count++;
        $bytes += $written;
    }
    $zip->close();
    if ($skipped) {
        $errors[] = "Skipped $skipped file(s) in the ZIP that are not GIFT files or pictures.";
    }
    return [$errors, $count, $bytes];
}

/** All files in an import: [relative path => absolute path]. */
function import_files(string $id): array
{
    $root = import_dir($id) . '/files';
    $out = [];
    if (!is_dir($root)) {
        return $out;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        $out[substr($f->getPathname(), strlen($root) + 1)] = $f->getPathname();
    }
    uksort($out, 'strnatcasecmp');
    return $out;
}

/** Resolve a picture reference from a GIFT file inside the upload. Returns the upload-relative path or null. */
function import_resolve_picture(string $ref, string $giftRelPath, array $files): ?string
{
    $ref = rawurldecode(html_entity_decode(trim($ref), ENT_QUOTES, 'UTF-8'));
    $ref = preg_replace('#^@@PLUGINFILE@@/#', '', $ref);
    $ref = preg_replace('#[?\#].*$#', '', $ref);
    $images = array_filter(array_keys($files), fn($p) => in_array(import_extension($p), IMPORT_IMAGE_EXT, true));
    $dir = dirname($giftRelPath) === '.' ? '' : dirname($giftRelPath) . '/';
    foreach ([$dir . $ref, $ref] as $candidate) {
        $candidate = import_safe_path($candidate);
        if ($candidate !== null && isset($files[$candidate]) && in_array(import_extension($candidate), IMPORT_IMAGE_EXT, true)) {
            return $candidate;
        }
    }
    $base = import_safe_path(basename($ref));
    $matches = array_values(array_filter($images, fn($p) => strcasecmp(basename($p), (string)$base) === 0));
    return count($matches) === 1 ? $matches[0] : null;
}

/** Change where questions go (before commit). The next analysis uses the new options. */
function import_set_options(string $id, string $base, string $mode, bool $stripTop): void
{
    update_json(import_dir($id) . '/meta.json', function ($m) use ($base, $mode, $stripTop) {
        if ($m['status'] === 'committed') {
            return false;
        }
        $m['base'] = normalize_category($base);
        $m['mode'] = $mode === 'gift' ? 'gift' : 'folders';
        $m['strip_top'] = $stripTop;
        return $m;
    });
}

/** Analyse an upload: parse GIFT files, work out categories, subjects, pictures and duplicates. */
function import_analyze(string $id, array $user): array
{
    $meta = import_meta($id);
    $files = import_files($id);
    $parser = new GiftParser();
    $report = [
        'id' => $id, 'analyzed' => now_iso(), 'base' => $meta['base'], 'mode' => $meta['mode'],
        'files' => [], 'questions' => [], 'categories' => [], 'types' => [],
        'unused_pictures' => [], 'totals' => ['questions' => 0, 'importable' => 0, 'errors' => 0, 'warnings' => 0, 'duplicates' => 0, 'blocked' => 0],
    ];
    $usedPictures = [];
    $existingKeys = [];

    foreach ($files as $rel => $abs) {
        if (!in_array(import_extension($rel), IMPORT_GIFT_EXT, true)) {
            continue;
        }
        $content = (string)file_get_contents($abs);
        if (import_extension($rel) === 'txt' && !preg_match('/[{}]|^::|\$CATEGORY:/m', $content)) {
            continue;   // a plain text file, not GIFT
        }
        $result = $parser->parse($content, $rel);
        $messages = array_merge($result['messages'], GiftLint::check($result['questions'], $rel));
        $fileEntry = ['path' => $rel, 'questions' => count($result['questions']), 'messages' => []];
        $byLine = [];
        foreach ($messages as $m) {
            $byLine[$m['line']][] = $m;
            if ($m['level'] === 'error') {
                $fileEntry['messages'][] = $m;   // errors belong to questions that could not be read
                $report['totals']['errors']++;
            } elseif ($m['level'] === 'warning') {
                $report['totals']['warnings']++;
            }
        }
        $fileEntry['messages'] = array_merge($fileEntry['messages'], array_values(array_filter($messages, fn($m) => $m['level'] !== 'error' && !in_array($m['line'], array_column($result['questions'], 'line'), true))));

        $fileStem = preg_replace('/\.(gift|txt)$/i', '', $rel);
        if (!empty($meta['strip_top']) && str_contains($fileStem, '/')) {
            $fileStem = substr($fileStem, strpos($fileStem, '/') + 1);
        }
        foreach ($result['questions'] as $qi => $q) {
            if ($meta['mode'] === 'gift' && $q['category'] !== null && category_from_gift($q['category']) !== '') {
                $category = normalize_category($meta['base'] . '/' . category_from_gift($q['category']));
            } else {
                $category = normalize_category($meta['base'] . '/' . $fileStem);
            }
            $subject = subject_from_path($category);
            $allowed = can_manage_subject($user, $subject);

            $pictures = [];
            map_question_texts($q, function ($text) use (&$pictures, $rel, $files, &$usedPictures) {
                foreach (find_picture_refs($text) as $ref) {
                    $found = import_resolve_picture($ref, $rel, $files);
                    $pictures[$ref] = $found;
                    if ($found) {
                        $usedPictures[$found] = true;
                    }
                }
                if (preg_match_all('#@@PLUGINFILE@@/([^"\'\s)]+)#', $text, $m)) {
                    foreach ($m[1] as $ref) {
                        $found = import_resolve_picture($ref, $rel, $files);
                        $pictures['@@PLUGINFILE@@/' . $ref] = $found;
                        if ($found) {
                            $usedPictures[$found] = true;
                        }
                    }
                }
                return $text;
            });

            if (!isset($existingKeys[$category])) {
                $existingKeys[$category] = [];
                foreach (category_questions($category) as $existing) {
                    $existingKeys[$category][question_duplicate_key($existing)] = $existing['id'];
                }
            }
            $duplicateOf = $existingKeys[$category][question_duplicate_key($q)] ?? null;

            $problems = [];
            if (!$allowed) {
                $problems[] = $subject === null
                    ? 'The folder "' . $category . '" does not name a subject. Choose a target folder such as V/Maths.'
                    : "You cannot add questions to $subject.";
            }
            foreach ($pictures as $ref => $found) {
                if ($found === null) {
                    $problems[] = "Picture \"$ref\" is not in the upload.";
                }
            }
            $qMessages = $byLine[$q['line']] ?? [];
            $entry = [
                'key' => count($report['questions']),
                'file' => $rel, 'line' => $q['line'], 'type' => $q['type'], 'name' => $q['name'],
                'category' => $category, 'subject' => $subject, 'allowed' => $allowed,
                'duplicate_of' => $duplicateOf, 'pictures' => $pictures,
                'problems' => $problems, 'messages' => $qMessages, 'question' => $q,
            ];
            $report['questions'][] = $entry;
            $report['totals']['questions']++;
            $report['types'][$q['type']] = ($report['types'][$q['type']] ?? 0) + 1;
            $report['categories'][$category] = ($report['categories'][$category] ?? 0) + 1;
            if ($duplicateOf) {
                $report['totals']['duplicates']++;
            }
            if (!$allowed) {
                $report['totals']['blocked']++;
            } else {
                $report['totals']['importable']++;
            }
        }
        $report['files'][] = $fileEntry;
    }
    foreach ($files as $rel => $abs) {
        if (in_array(import_extension($rel), IMPORT_IMAGE_EXT, true) && !isset($usedPictures[$rel])) {
            $report['unused_pictures'][] = $rel;
        }
    }
    ksort($report['categories'], SORT_NATURAL | SORT_FLAG_CASE);
    write_json(import_dir($id) . '/report.json', $report);
    update_json(import_dir($id) . '/meta.json', function ($m) {
        $m['status'] = 'analyzed';
        return $m;
    });
    return $report;
}

/**
 * Write the analysed questions into the bank.
 * $duplicateAction: 'skip' | 'replace' (overwrite the existing question) | 'keep' (add anyway)
 * $skipKeys: report keys the teacher unticked.
 */
function import_commit(string $id, array $user, string $duplicateAction = 'skip', array $skipKeys = []): array
{
    return with_lock(import_dir($id) . '/commit', function () use ($id, $user, $duplicateAction, $skipKeys) {
        $meta = import_meta($id);
        if (!$meta || $meta['status'] !== 'analyzed') {
            throw new RuntimeException('This import has already been committed or was not analysed.');
        }
        $report = read_json(import_dir($id) . '/report.json');
        $files = import_files($id);
        $skip = array_flip(array_map('intval', $skipKeys));
        $result = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'pictures' => 0, 'categories' => []];
        bank_batch(function () use ($report, $files, $skip, $duplicateAction, $user, &$result) {
        foreach ($report['questions'] as $entry) {
            if (!$entry['allowed'] || isset($skip[$entry['key']]) || ($entry['duplicate_of'] && $duplicateAction === 'skip')) {
                $result['skipped']++;
                continue;
            }
            // Re-check permission at commit time (roles may have changed)
            if (!can_manage_subject($user, subject_from_path($entry['category']))) {
                $result['skipped']++;
                continue;
            }
            $q = $entry['question'];
            $category = $entry['category'];
            $mediaDir = bank_file_dir($category) . '/media';
            $stored = [];
            foreach ($entry['pictures'] as $ref => $found) {
                if ($found !== null && isset($files[$found])) {
                    $stored[$ref] = 'media/' . store_unique_file($mediaDir, basename($found), file_get_contents($files[$found]));
                    $result['pictures']++;
                }
            }
            $q = rewrite_media_refs($q, fn($ref) => $stored[$ref] ?? null);
            if ($stored) {
                $q = map_question_texts($q, fn($t) => preg_replace_callback('#@@PLUGINFILE@@/([^"\'\s)]+)#', fn($m) => $stored['@@PLUGINFILE@@/' . $m[1]] ?? $m[0], $t));
            }
            if ($entry['duplicate_of'] && $duplicateAction === 'replace') {
                $q['id'] = $entry['duplicate_of'];
                save_question($q, $category);
                $result['updated']++;
            } else {
                save_question($q, $category);
                $result['added']++;
            }
            $result['categories'][$category] = true;
        }
        });
        $result['categories'] = array_keys($result['categories']);
        update_json(import_dir($id) . '/meta.json', function ($m) use ($result, $user) {
            $m['status'] = 'committed';
            $m['committed'] = now_iso();
            $m['committed_by'] = $user['username'];
            $m['result'] = $result;
            return $m;
        });
        return $result;
    });
}
