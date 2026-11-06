<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Human-readable IDs and file names. No hashes or hex in names or on pages:
 *   exam      class5-maths-unit-test-2-20260916-1430-1
 *   import    import-20260916-1502-1
 *   reward    R00123
 * (A question's key, q3fa9c21b0e, is worked out from its folder and title and never shown: lib/questions.php.)
 * Secrets (CSRF / attempt save tokens) are random base32, never used as names.
 */

defined('EXAMS_APP') or exit;

/** Lowercase a-z0-9 words joined by '-'. Non-Latin scripts are transliterated. */
function slugify(string $text, int $max = 40): string
{
    if (class_exists('Transliterator')) {
        static $tr = null;
        $tr ??= Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        if ($tr) {
            $text = (string)$tr->transliterate($text);
        }
    }
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    if (strlen($slug) > $max) {
        $slug = rtrim(substr($slug, 0, $max), '-');
    }
    return $slug;
}

/**
 * Create the folder for a new item named <slug>-<YYYYMMDD-HHMM>-<n> under $parent
 * and return the id. <n> starts at 1 and only increases on a clash.
 */
function create_dated_id(string $parent, string $slug, ?int $time = null): string
{
    $stamp = date('Ymd-Hi', $time ?? time());
    ensure_dir($parent);
    return with_lock($parent . '/.ids', function () use ($parent, $slug, $stamp) {
        for ($n = 1; ; $n++) {
            $id = "$slug-$stamp-$n";
            if (!file_exists("$parent/$id")) {
                ensure_dir("$parent/$id");
                return $id;
            }
        }
    });
}

/** New exam id + folder. Falls back to "<subject>-class<N>" when the title has no usable letters. */
function create_exam_id(string $title, string $subject = '', string $class = '', ?int $time = null): string
{
    $slug = slugify($title);
    if ($slug === '') {
        $slug = slugify(trim($subject . ($class !== '' ? " class$class" : ''))) ?: 'exam';
    }
    return create_dated_id(data_path('exams'), $slug, $time);
}

function create_import_id(): string
{
    return create_dated_id(data_path('imports'), 'import');
}

function next_reward_id(): string
{
    return sprintf('R%05d', next_counter('reward'));
}

function is_exam_id(string $id): bool
{
    return (bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*-\d{8}-\d{4}-\d+$/', $id);
}

/** Random secret in lowercase base32 (a-z, 2-7). 32 chars = 160 bits. */
function random_secret(int $chars = 32): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
    $out = '';
    $bytes = random_bytes($chars);
    for ($i = 0; $i < $chars; $i++) {
        $out .= $alphabet[ord($bytes[$i]) & 31];
    }
    return $out;
}

/** Safe file name: spaces become '_', unsafe characters removed (like GiftShop's product names). */
function sanitize_filename(string $name): string
{
    $name = str_replace(['/', '\\'], '_', $name);
    $name = preg_replace('/\s+/u', '_', trim($name));
    $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '', $name);
    $name = ltrim($name, '.');
    return $name === '' ? 'file' : $name;
}

/**
 * Store $content as $dir/$name. Reuses an existing file with identical content;
 * on a clash with different content uses name-2.ext, name-3.ext, ...
 * Returns the file name used.
 */
function store_unique_file(string $dir, string $name, string $content): string
{
    ensure_dir($dir);
    $name = sanitize_filename($name);
    return with_lock($dir . '/.files', function () use ($dir, $name, $content) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext === '' ? $name : substr($name, 0, -strlen($ext) - 1);
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? $name : ($base . "-$n" . ($ext === '' ? '' : ".$ext"));
            $path = "$dir/$candidate";
            if (!file_exists($path)) {
                write_file_atomic($path, $content);
                return $candidate;
            }
            if (filesize($path) === strlen($content) && file_get_contents($path) === $content) {
                return $candidate;
            }
        }
    });
}
