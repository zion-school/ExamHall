<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 17-Sep-2026
 *
 * The register of everyone who was ever in users.csv: data/people.csv
 *   username, name, class, first_seen, left_on, note
 * Zion Bank's users.csv only knows the people there now; a student who left school is removed from it.
 * The register keeps their name and last class, and the day they were no longer in users.csv (left_on),
 * so their tries still show who wrote them. It is brought up to date whenever users.csv changes.
 * A username that comes back under a different name gets a note (a new student given an old username?).
 */

defined('EXAMS_APP') or exit;

const PEOPLE_HEADER = ['username', 'name', 'class', 'first_seen', 'left_on', 'note'];

function people_file(): string
{
    return data_path('people.csv');
}

/** [username => row] of the register. */
function load_people(): array
{
    static $cache = [];
    $file = people_file();
    clearstatcache(true, $file);
    $key = is_file($file) ? filemtime($file) . '@' . filesize($file) : '';
    if (!isset($cache[$key])) {
        $cache = [$key => array_column(read_csv($file), null, 'username')];
    }
    return $cache[$key];
}

function find_person(string $username): ?array
{
    return load_people()[$username] ?? null;
}

/** Names that differ only in spacing, dots or case are the same name (initials written with or without a space). */
function person_name_key(string $name): string
{
    return mb_strtolower(preg_replace('/[\s.]+/u', '', $name));
}

/**
 * Bring the register up to date with users.csv ($users as load_users() returns them; $fileKey names that
 * version of the file, so the work is done once per change). Never marks people as left when users.csv
 * looks broken: empty, or fewer than half of the people in it last time.
 */
function sync_people(array $users, string $fileKey): void
{
    $state = data_path('people.state');
    if (!$users || @file_get_contents($state) === $fileKey) {
        return;
    }
    try {
        with_lock(people_file(), function () use ($users, $fileKey, $state) {
            if (@file_get_contents($state) === $fileKey) {
                return;
            }
            $rows = array_column(read_csv(people_file()), null, 'username');
            $present = count(array_filter($rows, fn($r) => $r['left_on'] === ''));
            if ($present > 0 && count($users) < $present / 2) {
                return;   // half the school gone at once: users.csv is being rewritten or is the wrong file
            }
            $today = date('Y-m-d');
            foreach ($users as $username => $u) {
                $name = trim((string)($u['name'] ?? ''));
                $class = normalize_class((string)($u['class'] ?? ''));
                $row = $rows[$username] ?? null;
                if ($row === null) {
                    $rows[$username] = ['username' => $username, 'name' => $name, 'class' => $class, 'first_seen' => $today, 'left_on' => '', 'note' => ''];
                    continue;
                }
                if ($row['left_on'] !== '' && $name !== '' && $row['name'] !== '' && person_name_key($name) !== person_name_key($row['name'])) {
                    $row['note'] = "Back in users.csv on $today under another name; before that it was {$row['name']} (left on {$row['left_on']})";
                }
                $row['left_on'] = '';
                $row['name'] = $name !== '' ? $name : $row['name'];
                $row['class'] = $class !== '' ? $class : $row['class'];
                $rows[$username] = $row;
            }
            foreach ($rows as $username => $row) {
                if (!isset($users[$username]) && $row['left_on'] === '') {
                    $rows[$username]['left_on'] = $today;
                }
            }
            ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
            write_file_atomic(people_file(), csv_string(PEOPLE_HEADER, array_values($rows)));
            write_file_atomic($state, $fileKey);
        });
    } catch (Throwable $e) {
        error_log('people register: ' . $e->getMessage());   // logins must keep working
    }
}

/** A user no longer in users.csv (left school), with a register row or tries to show for it. */
function is_former_user(string $username): bool
{
    return find_user($username) === null;
}

/** Was in users.csv and was removed from it (the register has the day); never true for a username users.csv never had. */
function has_left_school(string $username): bool
{
    return find_user($username) === null && (find_person($username)['left_on'] ?? '') !== '';
}

/** "Left on 12 Apr 2026" / "Left" for a former user; '' for someone in users.csv. */
function former_label(string $username): string
{
    if (!is_former_user($username)) {
        return '';
    }
    $left = find_person($username)['left_on'] ?? '';
    return $left !== '' ? 'Left on ' . date('j M Y', strtotime($left)) : 'Left';
}

/** "2026-04-12" for a user who left (empty when unknown or still in users.csv), for CSV downloads. */
function former_left_on(string $username): string
{
    return is_former_user($username) ? (string)(find_person($username)['left_on'] ?? '') : '';
}

/** Badge for a former user ('' for someone in users.csv). */
function former_badge(string $username): string
{
    $label = former_label($username);
    return $label === '' ? '' : '<span class="badge badge-warn" title="' . h($label . ': no longer in users.csv. Their tries are kept.') . '">Left</span>';
}

/**
 * Everyone a staff member can look up on the Students page: students in users.csv, everyone in the register
 * who was not staff, and anyone with a try. Tries are only those of exams $user manages, newest first.
 * [username => [username, name, class, status (current|left|no_account), first_seen, left_on, note,
 *   tries: [[exam, attempt]]]]
 */
function student_directory(array $user): array
{
    $staff = array_merge(array_keys(load_teachers()), cfg('ADMIN_USERS', []));
    $tries = [];
    foreach (all_exams() as $exam) {
        if (!exam_can_manage($user, $exam)) {
            continue;
        }
        foreach (all_attempts($exam['id']) as $username => $list) {
            foreach ($list as $a) {
                $tries[$username][] = ['exam' => $exam, 'attempt' => $a];
            }
        }
    }
    $usernames = array_keys($tries);
    foreach (load_users() as $username => $u) {
        $usernames[] = (string)$username;
    }
    foreach (load_people() as $username => $p) {
        $usernames[] = (string)$username;
    }
    $out = [];
    foreach (array_unique($usernames) as $username) {
        $mine = $tries[$username] ?? [];
        if (!$mine && in_array($username, $staff, true)) {
            continue;
        }
        usort($mine, fn($x, $y) => strcmp((string)$y['attempt']['started'], (string)$x['attempt']['started']));
        $info = student_info($username, $mine ? [end($mine)['attempt'], $mine[0]['attempt']] : []);
        $person = find_person($username) ?? [];
        $out[$username] = $info + [
            'status' => find_user($username) ? 'current' : (($person['left_on'] ?? '') !== '' ? 'left' : 'no_account'),
            'first_seen' => $person['first_seen'] ?? '', 'left_on' => $person['left_on'] ?? '', 'note' => $person['note'] ?? '',
            'tries' => $mine,
        ];
    }
    uasort($out, fn($a, $b) => [$a['class'] === '', strnatcasecmp($a['class'], $b['class']), mb_strtolower($a['name'])] <=> [$b['class'] === '', 0, mb_strtolower($b['name'])]);
    return $out;
}
