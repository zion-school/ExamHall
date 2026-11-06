<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Users, roles and sessions.
 *  - Users come from Zion Bank's users.csv (read only, plaintext passwords by design).
 *    Everyone ever in it stays in the register data/people.csv (lib/people.php), so students who left keep their names.
 *  - Admins are listed in config.php ($ADMIN_USERS).
 *  - Teachers are listed in data/teachers.csv (username,subjects) and only see their subjects.
 *  - Everyone else is a student.
 */

defined('EXAMS_APP') or exit;

const TEACHERS_HEADER = ['username', 'subjects'];

/** All users keyed by username: name, username, password, email, class, date_of_birth. */
function load_users(): array
{
    static $cache = [];
    $file = cfg('USERS_CSV');
    if (!is_file($file)) {
        return [];
    }
    clearstatcache(true, $file);
    $key = $file . '@' . filemtime($file) . '@' . filesize($file);
    if (!isset($cache[$key])) {
        $users = [];
        foreach (read_csv($file, '\\') as $row) {
            $username = trim($row['username'] ?? '');
            if ($username !== '') {
                $row['username'] = $username;
                $users[$username] = $row;
            }
        }
        $cache = [$key => $users];
        sync_people($users, $key);
    }
    return $cache[$key];
}

function find_user(string $username): ?array
{
    return load_users()[$username] ?? null;
}

/**
 * Name to show for a user: users.csv; for someone who left, the register (data/people.csv) or the name
 * kept in their try; a try migrated from Moodle carries the name of a student who never had a bank account.
 */
function display_name(string $username, ?array $attempt = null): string
{
    $user = find_user($username);
    if ($user && trim($user['name']) !== '') {
        return $user['name'];
    }
    foreach ([find_person($username)['name'] ?? '', $attempt['student']['name'] ?? ''] as $name) {
        if (trim((string)$name) !== '') {
            return trim((string)$name);
        }
    }
    $migrated = trim((string)($attempt['migrated']['name'] ?? ''));
    return $migrated !== '' ? "$migrated (Moodle)" : $username;
}

/**
 * Student details for result lists: the bank account; for someone who left, the name from the register
 * and the class they were in when they wrote the try (kept in the try), else their last class in the register.
 */
function student_info(string $username, array $attempts = []): array
{
    $u = find_user($username);
    $last = $attempts ? end($attempts) : null;
    return [
        'username' => $username,
        'name' => $u ? ($u['name'] ?: $username) : display_name($username, $last),
        'class' => normalize_class($u['class'] ?? ($last['student']['class'] ?? $last['migrated']['class'] ?? find_person($username)['class'] ?? '')),
    ];
}

/** Normalised class of a user ("V", "Class 5" and "5" all become "5"). */
function normalize_class(string $class): string
{
    $c = strtoupper(trim(preg_replace('/^(class|std|grade)\s*[-:]?\s*/i', '', trim($class))));
    $roman = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8,
        'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12, 'LKG' => 'LKG', 'UKG' => 'UKG'];
    if (isset($roman[$c])) {
        return (string)$roman[$c];
    }
    return $c;
}

function teachers_file(): string
{
    return data_path('teachers.csv');
}

/** [username => [subject, ...]] */
function load_teachers(): array
{
    $out = [];
    foreach (read_csv(teachers_file()) as $row) {
        $username = trim($row['username']);
        if ($username === '') {
            continue;
        }
        $subjects = [];
        foreach (explode(';', $row['subjects']) as $s) {
            $name = normalize_subject($s) ?? trim($s);
            if ($name !== '') {
                $subjects[] = $name;
            }
        }
        $out[$username] = array_values(array_unique($subjects));
    }
    return $out;
}

function save_teachers(array $teachers): void
{
    $rows = [];
    ksort($teachers, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($teachers as $username => $subjects) {
        $rows[] = ['username' => $username, 'subjects' => implode(';', $subjects)];
    }
    write_csv(teachers_file(), TEACHERS_HEADER, $rows);
}

function user_role(string $username): string
{
    if (in_array($username, cfg('ADMIN_USERS', []), true)) {
        return 'admin';
    }
    if (isset(load_teachers()[$username])) {
        return 'teacher';
    }
    return 'student';
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = data_path('sessions');
    ensure_dir($dir);
    $lifetime = (int)(cfg('SESSION_HOURS', 8) * 3600);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.sid_bits_per_character', '5');   // base32 ids, not hex
    ini_set('session.sid_length', '32');
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_save_path($dir);
    session_name('EXAMS_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    @session_start();
}

function login_throttle_file(): string
{
    return data_path('logins', 'failures.json');
}

function login_is_throttled(string $username): bool
{
    $window = cfg('LOGIN_FAIL_WINDOW_MIN', 10) * 60;
    $fails = read_json(login_throttle_file(), [])[$username] ?? [];
    $recent = array_filter($fails, fn($t) => $t > time() - $window);
    return count($recent) >= cfg('LOGIN_MAX_FAILS', 10);
}

function record_login_result(string $username, bool $success): void
{
    $window = cfg('LOGIN_FAIL_WINDOW_MIN', 10) * 60;
    update_json(login_throttle_file(), function ($all) use ($username, $success, $window) {
        foreach ($all as $u => $times) {
            $all[$u] = array_values(array_filter($times, fn($t) => $t > time() - $window));
            if (!$all[$u]) {
                unset($all[$u]);
            }
        }
        if ($success) {
            unset($all[$username]);
        } else {
            $all[$username][] = time();
        }
        return $all;
    });
}

/**
 * Check credentials and start the session. Returns null on success or an error message.
 */
function attempt_login(string $username, string $password): ?string
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return 'Please enter your username and password.';
    }
    if (login_is_throttled($username)) {
        return 'Too many wrong passwords. Please wait a few minutes and try again.';
    }
    $user = find_user($username);
    if (!$user || !hash_equals((string)$user['password'], $password)) {
        record_login_result($username, false);
        return 'Wrong username or password.';
    }
    record_login_result($username, true);
    start_app_session();
    session_regenerate_id(true);
    $_SESSION = [
        'user' => $user['username'],
        'login_time' => time(),
    ];
    return null;
}

function logout(): void
{
    start_app_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Logged-in user with role info, or null. Roles are re-read every request. */
function current_user(): ?array
{
    start_app_session();
    $username = $_SESSION['user'] ?? null;
    if ($username === null) {
        return null;
    }
    $user = find_user($username);
    if (!$user) {
        return null;
    }
    $role = user_role($username);
    return [
        'username' => $username,
        'name' => trim($user['name']) !== '' ? $user['name'] : $username,
        'class' => normalize_class($user['class'] ?? ''),
        'role' => $role,
        'subjects' => $role === 'teacher' ? (load_teachers()[$username] ?? []) : [],
    ];
}

function is_staff(?array $user): bool
{
    return $user !== null && in_array($user['role'], ['admin', 'teacher'], true);
}

/** Can this user manage content of $subject? Admins: always. Teachers: their subjects only. */
function can_manage_subject(?array $user, ?string $subject): bool
{
    if ($user === null) {
        return false;
    }
    if ($user['role'] === 'admin') {
        return true;
    }
    return $user['role'] === 'teacher' && $subject !== null && in_array($subject, $user['subjects'], true);
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_response(['ok' => false, 'error' => 'login_required'], 401);
        }
        redirect('index.php');
    }
    return $user;
}

function require_staff(): array
{
    $user = require_login();
    if (!is_staff($user)) {
        http_response_code(403);
        exit('Access denied');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Access denied');
    }
    return $user;
}

/**
 * True when files in the data folder can be downloaded over HTTP (web server ignores
 * .htaccess and DATA_DIR is inside the web root). Checked at most once per session.
 */
function data_dir_exposed(): bool
{
    start_app_session();
    if (isset($_SESSION['data_exposed_checked'])) {
        return $_SESSION['data_exposed_checked'];
    }
    $probe = data_path('probe.txt');
    $marker = 'exams-data-probe';
    if (!is_file($probe)) {
        write_file_atomic($probe, $marker);
    }
    $exposed = false;
    $dataDir = realpath(cfg('DATA_DIR'));
    $appDir = realpath(APP_DIR);
    if (!$dataDir || !$appDir || !str_starts_with($dataDir, $appDir . '/')) {
        $exposed = false;
    } elseif (PHP_SAPI === 'cli-server') {
        // The built-in server is single-threaded (a self-request would hang); it is safe only with router.php
        $exposed = !defined('EXAMS_ROUTER');
    } elseif (function_exists('curl_init') && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $ch = curl_init($scheme . '://' . $_SERVER['HTTP_HOST'] . url(substr($dataDir, strlen($appDir) + 1) . '/probe.txt'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => false]);
        $body = curl_exec($ch);
        $exposed = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && trim((string)$body) === $marker;
        curl_close($ch);
    }
    return $_SESSION['data_exposed_checked'] = $exposed;
}
