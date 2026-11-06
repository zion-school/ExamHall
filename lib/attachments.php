<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Attachments: pages added after a student's result sheet (photos, scans or exported pages).
 *
 *   data/exams/<exam>/attachments/<username>/01.jpg, 02.png ...   one picture per printed page, in order
 *
 * A PDF upload is turned into one PNG per page with pdftoppm (poppler-utils).
 */

defined('EXAMS_APP') or exit;

const ATTACHMENT_IMAGE_EXT = ['png', 'jpg', 'jpeg', 'webp'];

function attachment_dir(string $examId, string $username): string
{
    if (!preg_match('/^[\p{L}\p{N}._@-]+$/u', $username)) {
        throw new InvalidArgumentException('Bad username');
    }
    return exam_dir($examId) . "/attachments/$username";
}

/** Page file names of a student, in print order. */
function attachment_pages(string $examId, string $username): array
{
    try {
        return list_files(attachment_dir($examId, $username), '/^\d{2,}\.(png|jpe?g|webp)$/i');
    } catch (InvalidArgumentException $e) {
        return [];
    }
}

/** Students who can have attachment pages: the exam's audience and anyone with a try, in class and name order. */
function attachment_students(array $exam): array
{
    $students = exam_students($exam);
    foreach (all_attempts($exam['id']) as $username => $list) {
        $students[$username] ??= student_info($username, $list);
    }
    uasort($students, fn($a, $b) => [$a['class'], mb_strtolower($a['name'])] <=> [$b['class'], mb_strtolower($b['name'])]);
    return $students;
}

/** [username => page count] for an exam. */
function attachment_summary(string $examId): array
{
    $out = [];
    foreach (list_dirs(exam_dir($examId) . '/attachments') as $username) {
        if ($n = count(attachment_pages($examId, $username))) {
            $out[$username] = $n;
        }
    }
    return $out;
}

function attachment_page_url(string $examId, string $username, string $file): string
{
    return url('media.php') . '?exam=' . rawurlencode($examId) . '&attachment=' . rawurlencode($username) . '&f=' . rawurlencode($file)
        . '&v=' . @filemtime(attachment_dir($examId, $username) . '/' . $file);
}

function pdftoppm_available(): bool
{
    static $found = null;
    return $found ??= trim((string)@shell_exec('command -v pdftoppm 2>/dev/null')) !== '';
}

/**
 * Add uploaded files as pages after a student's existing pages.
 * $files: [[name, tmp path]]. Pictures are kept as they are; PDFs become one PNG per page.
 * Returns [added pages, [problems]].
 */
function attachment_add_files(string $examId, string $username, array $files): array
{
    $dir = attachment_dir($examId, $username);
    return with_lock($dir, function () use ($dir, $examId, $username, $files) {
        ensure_dir($dir);
        $next = 1 + array_reduce(attachment_pages($examId, $username), fn($max, $f) => max($max, (int)$f), 0);
        $added = 0;
        $problems = [];
        foreach ($files as [$name, $tmp]) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ATTACHMENT_IMAGE_EXT, true)) {
                if (@getimagesize($tmp) === false) {
                    $problems[] = "$name is not a picture.";
                    continue;
                }
                copy($tmp, sprintf('%s/%02d.%s', $dir, $next++, $ext === 'jpeg' ? 'jpg' : $ext));
                $added++;
            } elseif ($ext === 'pdf') {
                if (!pdftoppm_available()) {
                    $problems[] = "$name: this server cannot read PDFs (pdftoppm is not installed). Upload photos or install poppler-utils.";
                    continue;
                }
                $work = $dir . '/.pdf-' . getmypid() . '-' . random_int(1000, 9999);
                ensure_dir($work);
                exec('pdftoppm -r 150 -png ' . escapeshellarg($tmp) . ' ' . escapeshellarg("$work/p") . ' 2>&1', $output, $code);
                $pages = glob("$work/p-*.png") ?: [];
                natsort($pages);
                if ($code !== 0 || !$pages) {
                    $problems[] = "$name could not be read as a PDF.";
                }
                foreach ($pages as $page) {
                    rename($page, sprintf('%s/%02d.png', $dir, $next++));
                    $added++;
                }
                delete_tree($work);
            } else {
                $problems[] = "$name: use photos (JPG, PNG) or a PDF. Save other documents as PDF first.";
            }
        }
        return [$added, $problems];
    });
}

/** Delete one page, or move it one place up/down; pages are renumbered 01, 02, ... */
function attachment_change(string $examId, string $username, string $file, string $action): void
{
    $dir = attachment_dir($examId, $username);
    with_lock($dir, function () use ($dir, $examId, $username, $file, $action) {
        $pages = attachment_pages($examId, $username);
        $i = array_search($file, $pages, true);
        if ($i === false) {
            return;
        }
        if ($action === 'delete') {
            unlink("$dir/$file");
            array_splice($pages, $i, 1);
        } elseif ($action === 'up' && $i > 0) {
            [$pages[$i - 1], $pages[$i]] = [$pages[$i], $pages[$i - 1]];
        } elseif ($action === 'down' && $i < count($pages) - 1) {
            [$pages[$i + 1], $pages[$i]] = [$pages[$i], $pages[$i + 1]];
        }
        // Renumber through temporary names so no file overwrites another
        foreach ($pages as $k => $page) {
            rename("$dir/$page", "$dir/.renumber-$k-" . $page);
        }
        foreach ($pages as $k => $page) {
            rename("$dir/.renumber-$k-" . $page, sprintf('%s/%02d.%s', $dir, $k + 1, strtolower(pathinfo($page, PATHINFO_EXTENSION))));
        }
    });
}

/** Remove all pages of a student. */
function attachment_clear(string $examId, string $username): void
{
    delete_tree(attachment_dir($examId, $username));
}

/**
 * Match uploaded file names to students for a bulk upload. Tried in turn: the username,
 * the full name, any word of the name longer than 2 letters (as a whole word of the file name, in any case),
 * then a close spelling of a word of 5 letters or more (1 letter different; 2 from 7 letters).
 * A file is matched only when exactly one student fits.
 * $students: [username => ['name' => ..]].
 * Returns [file name => ['user' => username|null, 'sure' => bool]] (not sure = close spelling).
 */
function attachment_match_files(array $fileNames, array $students): array
{
    $norm = fn(string $s) => trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
    $words = fn(string $s) => array_values(array_filter(explode(' ', $norm($s)), fn($w) => mb_strlen($w) > 2));
    $out = [];
    foreach ($fileNames as $name) {
        $stem = ' ' . $norm(pathinfo($name, PATHINFO_FILENAME)) . ' ';
        $contains = fn(string $needle) => $needle !== '' && str_contains($stem, " $needle ");
        $close = function (string $a, string $b): bool {
            $len = max(strlen($a), strlen($b));
            return min(strlen($a), strlen($b)) >= 5 && levenshtein($a, $b) <= ($len >= 7 ? 2 : 1);
        };
        $tests = [
            [true, fn($username, $s) => $contains($norm($username))],
            [true, fn($username, $s) => $contains($norm((string)$s['name']))],
            [true, fn($username, $s) => (bool)array_filter($words((string)$s['name']), $contains)],
            [false, fn($username, $s) => (bool)array_filter($words((string)$s['name']), fn($w) => (bool)array_filter(explode(' ', trim($stem)), fn($f) => $close($w, $f)))],
        ];
        $out[$name] = ['user' => null, 'sure' => false];
        foreach ($tests as [$sure, $test]) {
            $hits = array_keys(array_filter($students, fn($s, $username) => $test((string)$username, $s), ARRAY_FILTER_USE_BOTH));
            if ($hits) {
                $out[$name] = count($hits) === 1 ? ['user' => (string)$hits[0], 'sure' => $sure] : ['user' => null, 'sure' => false];
                break;
            }
        }
    }
    return $out;
}
