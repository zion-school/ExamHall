<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Subjects and their aliases (data/subjects.csv: subject,aliases), edited on the Teachers page.
 * There is no built-in list: a new install starts with no subjects.
 * Aliases let bank folder names like "Social-Science" and Moodle course shortnames like
 * "SSC5" resolve to one subject. Aliases match whole names only, so "SC" does not match "SSC".
 */

defined('EXAMS_APP') or exit;

const SUBJECTS_HEADER = ['subject', 'aliases'];

function subjects_file(): string
{
    return data_path('subjects.csv');
}

/** List of ['subject' => name, 'aliases' => [..]]. Empty until subjects are added. */
function load_subjects(): array
{
    static $cache = [];
    $file = subjects_file();
    clearstatcache(true, $file);
    if (!is_file($file)) {
        return [];
    }
    $cacheKey = $file . '@' . filemtime($file) . '@' . filesize($file);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $out = [];
    foreach (read_csv($file) as $row) {
        $name = trim($row['subject']);
        if ($name === '') {
            continue;
        }
        $aliases = array_values(array_filter(array_map('trim', explode(';', $row['aliases'] ?? ''))));
        $out[] = ['subject' => $name, 'aliases' => $aliases];
    }
    return $cache[$cacheKey] = $out;
}

function subject_names(): array
{
    return array_column(load_subjects(), 'subject');
}

function subject_key(string $text): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower($text));
}

/** Resolve a name, alias or course shortname (e.g. "ENG5") to a subject name, or null. */
function normalize_subject(string $text): ?string
{
    $key = subject_key($text);
    if ($key === '') {
        return null;
    }
    $candidates = [$key];
    $stripped = preg_replace('/\d+$/', '', $key);
    if ($stripped !== $key && $stripped !== '') {
        $candidates[] = $stripped;
    }
    foreach ($candidates as $k) {
        foreach (load_subjects() as $s) {
            if (subject_key($s['subject']) === $k) {
                return $s['subject'];
            }
            foreach ($s['aliases'] as $alias) {
                if (subject_key($alias) === $k) {
                    return $s['subject'];
                }
            }
        }
    }
    return null;
}

/** Subject of a bank category path such as "V/Term-2/Maths/04_Measurements". */
function subject_from_path(string $path): ?string
{
    foreach (explode('/', $path) as $segment) {
        $subject = normalize_subject($segment);
        if ($subject !== null) {
            return $subject;
        }
    }
    return null;
}
