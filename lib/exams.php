<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Exams: settings, audience, availability, unlocks (reward exams) and overrides.
 *
 *   data/exams/<exam id>/exam.json       settings (see EXAM_DEFAULTS)
 *   data/exams/<exam id>/paper.json      frozen questions (lib/paper.php)
 *   data/exams/<exam id>/unlocks.csv     no,scope,target,created,created_by,until
 *   data/exams/<exam id>/overrides.csv   username,extra_minutes,extra_attempts,open_at,close_at (per student)
 *   data/exams/<exam id>/attempts/<username>/<n>.json   (lib/attempts.php)
 *
 * Reward exams can only be started after a teacher unlock; each unlock allows one
 * attempt per student. Exams without rewards can be retaken (optional limit).
 */

defined('EXAMS_APP') or exit;

const EXAM_DEFAULTS = [
    'id' => '',
    'title' => '',
    'subject' => '',
    'classes' => [],
    'users' => [],
    'instructions' => '',
    'status' => 'draft',            // draft | published | closed
    'hidden' => false,              // published but hidden: students cannot see or start it (their results stay in My results)
    'open_at' => '',                // "YYYY-MM-DDTHH:MM" (local time) or ''
    'close_at' => '',               // tries still open at this time are submitted (a try paused by a lost connection gets its lost time after it)
    'time_limit_min' => 0,          // 0 = no limit
    'attempts' => 0,                // exams without rewards: 0 = unlimited retakes
    'grade_method' => 'highest',    // highest | latest | first | average
    'per_page' => 1,                // questions per page: 1, or 0 = all on one page
    'shuffle_questions' => true,
    'shuffle_choices' => true,
    'review' => 'after_close',      // score | after_close | immediate
    'bands' => [],                  // [{min: 90, text: "Excellent!"}]
    'access_code' => '',
    'grading' => ['llm' => 'off', 'fuzzy' => true, 'spelling_penalty' => 0.0,
        'ignore_case' => true, 'ignore_spaces' => true, 'ignore_punctuation' => true],   // short answers and blanks: full marks despite these
    'reward' => [
        'enabled' => false,
        'formula' => 'percent >= 90 ? 10 : percent >= 75 ? 5 : percent >= 50 ? 2 : 0',
        'max' => 10,
        'daily_max' => 0,
        'payout' => 'by_question_types',   // by_question_types | auto | approve
        'count' => 'best',                 // best | first
    ],
    'sections' => [['title' => '', 'shuffle' => true, 'choose' => 0, 'items' => []]],   // choose: 5 = "answer any five" (0 = all)
    'created' => '',
    'created_by' => '',
    'modified' => '',
    'modified_by' => '',
    'published' => '',
    'reopened' => '',               // "Reopen": opened again, possibly for a new school year
];

const UNLOCKS_HEADER = ['no', 'scope', 'target', 'created', 'created_by', 'until'];
const OVERRIDES_HEADER = ['username', 'extra_minutes', 'extra_attempts', 'open_at', 'close_at'];

function exam_dir(string $id): string
{
    if (!is_exam_id($id)) {
        throw new InvalidArgumentException('Bad exam id');
    }
    return data_path('exams', $id);
}

function load_exam(string $id): ?array
{
    if (!is_exam_id($id)) {
        return null;
    }
    $data = read_json(exam_dir($id) . '/exam.json');
    if (!$data) {
        return null;
    }
    $exam = array_replace(EXAM_DEFAULTS, $data);
    $exam['grading'] = array_replace(EXAM_DEFAULTS['grading'], $data['grading'] ?? []);
    $exam['reward'] = array_replace(EXAM_DEFAULTS['reward'], $data['reward'] ?? []);
    $exam['id'] = $id;
    return $exam;
}

function save_exam(array $exam, string $username): array
{
    $exam['modified'] = now_iso();
    $exam['modified_by'] = $username;
    // "migrated" (Moodle quiz id) is kept so a later migration run updates this exam instead of copying it
    write_json(exam_dir($exam['id']) . '/exam.json', array_intersect_key($exam, EXAM_DEFAULTS + ['migrated' => null]));
    return $exam;
}

function create_exam(array $user, string $title, string $subject, array $classes = []): array
{
    $id = create_exam_id($title, $subject, (string)($classes[0] ?? ''));
    $exam = array_replace(EXAM_DEFAULTS, [
        'id' => $id, 'title' => $title, 'subject' => $subject, 'classes' => $classes,
        'created' => now_iso(), 'created_by' => $user['username'],
    ]);
    return save_exam($exam, $user['username']);
}

/** All exams, newest first. */
function all_exams(): array
{
    $out = [];
    foreach (array_reverse(list_dirs(data_path('exams'))) as $dir) {
        if (is_exam_id($dir) && ($exam = load_exam($dir))) {
            $out[] = $exam;
        }
    }
    usort($out, fn($a, $b) => strcmp($b['created'], $a['created']));
    return $out;
}

function exam_can_manage(?array $user, array $exam): bool
{
    return can_manage_subject($user, $exam['subject'] !== '' ? $exam['subject'] : null);
}

function exam_is_reward(array $exam): bool
{
    return !empty($exam['reward']['enabled']);
}

/** Practice exams let students check each answer while writing (no rewards, answers shown at once). */
function exam_is_practice(array $exam): bool
{
    return !exam_is_reward($exam) && ($exam['review'] ?? '') === 'immediate';
}

/** "YYYY-MM-DDTHH:MM" in local time -> timestamp, or null. */
function exam_time(?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $t = strtotime($value);
    return $t === false ? null : $t;
}

// ---------- School years ----------

/** School year of a moment, e.g. "2026-27" (the year starts on $SCHOOL_YEAR_STARTS, MM-DD). */
function school_year(int $t): string
{
    $start = preg_match('/^\d\d-\d\d$/', (string)cfg('SCHOOL_YEAR_STARTS')) ? cfg('SCHOOL_YEAR_STARTS') : '06-01';
    $y = (int)date('Y', $t);
    $first = $y - (date('m-d', $t) < $start ? 1 : 0);
    return $first . '-' . substr((string)($first + 1), -2);
}

function current_school_year(): string
{
    return school_year(time());
}

/**
 * The school year an exam is for: the latest of its opening and closing dates, when it was published and when
 * it was reopened (so new dates or "Reopen" bring it into this year). null for a draft.
 */
function exam_school_year(array $exam): ?string
{
    if ($exam['status'] === 'draft') {
        return null;
    }
    $times = array_filter([exam_time($exam['open_at']), exam_time($exam['close_at']), exam_time($exam['published']), exam_time($exam['reopened'])]);
    $t = $times ? max($times) : exam_time($exam['created']);
    return $t ? school_year($t) : null;
}

/** An exam from an earlier school year: students can no longer start it (tries already running may be finished). */
function exam_from_past_year(array $exam): bool
{
    $year = exam_school_year($exam);
    return $year !== null && $year < current_school_year();
}

/** School year of a try (when it started). */
function attempt_school_year(array $attempt): string
{
    return school_year(exam_time($attempt['started'] ?? '') ?? time());
}

/** Opening and closing time for one student (their own times, if a teacher set them) or for everyone. */
function exam_window(array $exam, ?string $username = null): array
{
    $override = $username !== null ? (exam_overrides($exam['id'])[$username] ?? []) : [];
    return [
        'open' => exam_time($override['open_at'] ?? '') ?? exam_time($exam['open_at']),
        'close' => exam_time($override['close_at'] ?? '') ?? exam_time($exam['close_at']),
        'own' => ($override['open_at'] ?? '') !== '' || ($override['close_at'] ?? '') !== '',
    ];
}

/** "Today", "Tomorrow", "Fri 18 Dec". */
function day_text(int $t): string
{
    return match (date('Y-m-d', $t)) {
        date('Y-m-d') => 'Today',
        date('Y-m-d', strtotime('+1 day')) => 'Tomorrow',
        date('Y-m-d', strtotime('-1 day')) => 'Yesterday',
        default => date('D d M' . (date('Y', $t) !== date('Y') ? ' Y' : ''), $t),
    };
}

/** "Today, 9:00 AM". */
function moment_text(int $t): string
{
    return day_text($t) . ', ' . date('g:i A', $t);
}

/** "Today, 9:00 AM – 11:30 AM", "Fri 18 Dec, 9:00 AM – Sun 20 Dec, 5:00 PM", "Until ...", "Any time". */
function schedule_text(?int $open, ?int $close): string
{
    if ($open !== null && $close !== null) {
        return date('Y-m-d', $open) === date('Y-m-d', $close)
            ? moment_text($open) . ' – ' . date('g:i A', $close)
            : moment_text($open) . ' – ' . moment_text($close);
    }
    if ($open !== null) {
        return 'From ' . moment_text($open);
    }
    if ($close !== null) {
        return 'Until ' . moment_text($close);
    }
    return 'Any time';
}

/** 75 -> "1 min 15 s", 3900 -> "1 h 5 min". */
function duration_text(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    if ($seconds < 3600) {
        $s = $seconds % 60;
        return intdiv($seconds, 60) . ' min' . ($s ? " $s s" : '');
    }
    $m = intdiv($seconds % 3600, 60);
    return intdiv($seconds, 3600) . ' h' . ($m ? " $m min" : '');
}

/** When a try started and finished, and how long it took without time paused by a lost connection (null parts when unknown). */
function attempt_times(array $attempt): array
{
    $started = !empty($attempt['started']) ? strtotime($attempt['started']) : null;
    $finished = !empty($attempt['submitted']) ? strtotime($attempt['submitted']) : null;
    return [
        'started' => $started, 'finished' => $finished,
        'seconds' => $started && $finished && $finished >= $started ? max(0, $finished - $started - (int)($attempt['paused_sec'] ?? 0)) : null,
    ];
}

/** Distinct classes of students in users.csv (normalised, sorted). */
function all_classes(): array
{
    $classes = [];
    foreach (load_users() as $u) {
        $c = normalize_class($u['class'] ?? '');
        if ($c !== '' && user_role($u['username']) === 'student') {
            $classes[$c] = true;
        }
    }
    $list = array_map('strval', array_keys($classes));   // numeric keys come back as ints
    natsort($list);
    return array_values($list);
}

/** Classes an exam can be for: the classes in users.csv, and the ones it already has (their students may have left). */
function exam_class_choices(array $exam): array
{
    $list = array_values(array_unique(array_merge(all_classes(), array_map('strval', $exam['classes']))));
    natsort($list);
    return array_values($list);
}

function exam_in_audience(array $exam, array $user): bool
{
    if (in_array($user['username'], $exam['users'], true)) {
        return true;
    }
    $class = normalize_class($user['class'] ?? '');
    return $class !== '' && in_array($class, array_map('normalize_class', $exam['classes']), true);
}

/** Students (from users.csv) an exam is meant for. */
function exam_students(array $exam): array
{
    $out = [];
    foreach (load_users() as $u) {
        $student = ['username' => $u['username'], 'name' => $u['name'], 'class' => normalize_class($u['class'] ?? '')];
        if (user_role($u['username']) === 'student' && exam_in_audience($exam, $student)) {
            $out[$u['username']] = $student;
        }
    }
    uasort($out, fn($a, $b) => [$a['class'], $a['name']] <=> [$b['class'], $b['name']]);
    return $out;
}

// ---------- Overrides ----------

function exam_overrides(string $examId): array
{
    $out = [];
    foreach (read_csv(exam_dir($examId) . '/overrides.csv') as $row) {
        $out[$row['username']] = [
            'extra_minutes' => (int)$row['extra_minutes'],
            'extra_attempts' => (int)$row['extra_attempts'],
            'open_at' => $row['open_at'] ?? '',
            'close_at' => $row['close_at'],
        ];
    }
    return $out;
}

/** Remove a student's own settings. */
function remove_exam_override(string $examId, string $username): void
{
    update_csv(exam_dir($examId) . '/overrides.csv', OVERRIDES_HEADER, fn($rows) => array_values(array_filter($rows, fn($r) => $r['username'] !== $username)));
}

function set_exam_override(string $examId, string $username, array $values): void
{
    update_csv(exam_dir($examId) . '/overrides.csv', OVERRIDES_HEADER, function ($rows) use ($username, $values) {
        $found = false;
        foreach ($rows as &$row) {
            if ($row['username'] === $username) {
                $row = array_merge($row, $values);
                $found = true;
            }
        }
        unset($row);
        if (!$found) {
            $rows[] = array_merge(['username' => $username, 'extra_minutes' => 0, 'extra_attempts' => 0, 'open_at' => '', 'close_at' => ''], $values);
        }
        return $rows;
    });
}

// ---------- Unlocks (reward exams) ----------

function exam_unlocks(string $examId): array
{
    return read_csv(exam_dir($examId) . '/unlocks.csv');
}

/** Unlock a reward exam for a class or one student for $minutes. Returns the unlock number. */
function add_exam_unlock(string $examId, string $scope, string $target, int $minutes, string $by): int
{
    $no = 0;
    update_csv(exam_dir($examId) . '/unlocks.csv', UNLOCKS_HEADER, function ($rows) use ($scope, $target, $minutes, $by, &$no) {
        $no = 1 + array_reduce($rows, fn($max, $r) => max($max, (int)$r['no']), 0);
        $rows[] = [
            'no' => $no, 'scope' => $scope === 'class' ? 'class' : 'user',
            'target' => $scope === 'class' ? normalize_class($target) : $target,
            'created' => now_iso(), 'created_by' => $by, 'until' => date('c', time() + max(1, $minutes) * 60),
        ];
        return $rows;
    });
    return $no;
}

function end_exam_unlock(string $examId, int $no): void
{
    update_csv(exam_dir($examId) . '/unlocks.csv', UNLOCKS_HEADER, function ($rows) use ($no) {
        foreach ($rows as &$row) {
            if ((int)$row['no'] === $no && strtotime($row['until']) > time()) {
                $row['until'] = now_iso();
            }
        }
        return $rows;
    });
}

/** An active unlock this student has not used yet, or null. */
function active_unlock_for(array $exam, array $user, array $attempts): ?int
{
    $used = array_filter(array_map(fn($a) => $a['unlock'] ?? null, $attempts));
    foreach (exam_unlocks($exam['id']) as $row) {
        if (strtotime($row['until']) <= time() || in_array((int)$row['no'], $used, true)) {
            continue;
        }
        $matches = $row['scope'] === 'user'
            ? $row['target'] === $user['username']
            : $row['target'] === normalize_class($user['class'] ?? '');
        if ($matches) {
            return (int)$row['no'];
        }
    }
    return null;
}

/**
 * What a student can do with an exam right now.
 * Returns state (upcoming|open|in_progress|locked|no_attempts|closed|hidden), can_start, reason, unlock, attempts.
 */
function exam_student_status(array $exam, array $user): array
{
    $attempts = list_attempts($exam['id'], $user['username']);
    $out = ['state' => 'hidden', 'can_start' => false, 'reason' => '', 'unlock' => null, 'attempts' => $attempts, 'in_progress' => null];
    if ($exam['status'] === 'draft' || !exam_in_audience($exam, $user)) {
        return $out;
    }
    foreach ($attempts as $a) {
        if ($a['status'] === 'in_progress') {
            $a = attempt_expire_if_due($exam, $a);
            if ($a['status'] === 'in_progress') {
                $out['in_progress'] = $a;
            }
        }
    }
    $attempts = list_attempts($exam['id'], $user['username']);
    $out['attempts'] = $attempts;
    $override = exam_overrides($exam['id'])[$user['username']] ?? null;
    $now = time();
    ['open' => $open, 'close' => $close] = exam_window($exam, $user['username']);
    $out['open'] = $open;
    $out['close'] = $close;

    // A try already running may be finished even if the exam was hidden meanwhile, but only one on the clock:
    // an untimed try left open for days would keep a hidden exam showing
    if ($out['in_progress'] && (empty($exam['hidden']) || !empty($out['in_progress']['deadline']))) {
        $out['state'] = 'in_progress';
        $out['can_start'] = true;
        return $out;
    }
    if (!empty($exam['hidden'])) {
        $out['reason'] = 'Your teacher has hidden this exam.';
        return $out;
    }
    if ($exam['status'] === 'closed' || ($close !== null && $now >= $close)) {
        $out['state'] = 'closed';
        $out['reason'] = 'This exam has closed.';
        return $out;
    }
    if (exam_from_past_year($exam)) {
        $out['state'] = 'closed';
        $out['reason'] = 'This exam was for the ' . exam_school_year($exam) . ' school year.';
        return $out;
    }
    if ($open !== null && $now < $open) {
        $out['state'] = 'upcoming';
        $out['reason'] = 'Opens ' . moment_text($open) . '.';
        return $out;
    }
    $finished = array_filter($attempts, fn($a) => $a['status'] !== 'in_progress');
    if (exam_is_reward($exam)) {
        $out['unlock'] = active_unlock_for($exam, $user, $attempts);
        if ($out['unlock'] === null) {
            $out['state'] = 'locked';
            $out['reason'] = 'Ask your teacher to unlock this exam.';
            return $out;
        }
    } else {
        $limit = (int)$exam['attempts'];
        if ($limit > 0 && count($finished) >= $limit + (int)($override['extra_attempts'] ?? 0)) {
            $out['state'] = 'no_attempts';
            $out['reason'] = 'You have used all your attempts.';
            return $out;
        }
    }
    $out['state'] = 'open';
    $out['can_start'] = true;
    return $out;
}

/**
 * Marks-based result of a student for an exam, using the grade method. null when nothing is graded yet.
 * $attempts: only these tries (e.g. one school year's); default all of the student's tries.
 */
function exam_student_result(array $exam, string $username, ?array $attempts = null): ?array
{
    $graded = array_values(array_filter($attempts ?? list_attempts($exam['id'], $username), fn($a) => in_array($a['status'], ['graded', 'submitted'], true)));
    if (!$graded) {
        return null;
    }
    $percent = fn($a) => $a['max'] > 0 ? 100 * $a['score'] / $a['max'] : 0;
    switch ($exam['grade_method']) {
        case 'first':
            $chosen = $graded[0];
            break;
        case 'latest':
            $chosen = end($graded);
            break;
        case 'average':
            $avg = array_sum(array_map($percent, $graded)) / count($graded);
            return ['percent' => $avg, 'score' => null, 'max' => $graded[0]['max'], 'attempt' => null, 'pending' => in_array('submitted', array_column($graded, 'status'), true)];
        default:
            usort($graded, fn($a, $b) => [$percent($b), $a['n']] <=> [$percent($a), $b['n']]);
            $chosen = $graded[0];
    }
    return ['percent' => $percent($chosen), 'score' => $chosen['score'], 'max' => $chosen['max'], 'attempt' => $chosen['n'], 'pending' => $chosen['status'] === 'submitted'];
}

/** Message for a score from the exam's bands ([{min, text}]). */
function exam_band_text(array $exam, float $percent): string
{
    $bands = $exam['bands'];
    usort($bands, fn($a, $b) => $b['min'] <=> $a['min']);
    foreach ($bands as $band) {
        if ($percent >= (float)$band['min']) {
            return (string)$band['text'];
        }
    }
    return '';
}

/**
 * Can the student see correct answers / marks for this attempt now?
 * "after_close": only students who have finished; a try still running or paused (it may go on
 * past the closing time) keeps that student's correct answers hidden.
 */
function exam_review_allowed(array $exam, string $what, ?string $username = null): bool
{
    if ($exam['review'] === 'immediate') {
        return true;
    }
    if ($what === 'score') {
        return true;
    }
    if ($username !== null && array_filter(list_attempts($exam['id'], $username), fn($a) => $a['status'] === 'in_progress')) {
        return false;
    }
    $close = exam_window($exam, $username)['close'];
    return $exam['review'] === 'after_close' && ($exam['status'] === 'closed' || ($close !== null && time() >= $close) || exam_from_past_year($exam));
}

/**
 * A student's home page (student/index.php, wap/index.php): their exams in groups now (open or in
 * progress), locked, upcoming and done, each entry [exam, status, result]. A hidden exam is left out
 * (unless a try is still running; its results stay in My results); one from an earlier school year
 * only shows under done, and only when the student has taken it.
 */
function student_home_exams(array $user): array
{
    $groups = ['now' => [], 'locked' => [], 'upcoming' => [], 'done' => []];
    foreach (all_exams() as $exam) {
        $status = exam_student_status($exam, $user);
        if (!empty($exam['hidden']) && $status['state'] !== 'in_progress') {
            continue;   // hidden by the teacher: not on the home page; the student's results stay in My results
        }
        $tookIt = (bool)array_filter($status['attempts'], fn($a) => $a['status'] !== 'in_progress');
        if (($status['state'] === 'hidden' || exam_from_past_year($exam)) && !$tookIt && $status['state'] !== 'in_progress') {
            continue;   // hidden, or from an earlier school year (e.g. last year's exams of the class a student was promoted to)
        }
        $result = exam_student_result($exam, $user['username']);
        $entry = ['exam' => $exam, 'status' => $status, 'result' => $result];
        $group = match ($status['state']) {
            'open', 'in_progress' => 'now',
            'locked' => 'locked',
            'upcoming' => 'upcoming',
            default => 'done',
        };
        // A locked reward exam you already took belongs with finished ones
        if ($group === 'locked' && $result !== null) {
            $group = 'done';
        }
        $groups[$group][] = $entry;
    }
    return $groups;
}

/**
 * Start a try, or open the one in progress (student/exam.php, wap/exam.php). $code: the exam code
 * typed. Returns the try, or why it can't start.
 */
function student_start_exam(array $exam, array $paper, array $status, array $user, string $code)
{
    if (!$status['can_start']) {
        return $status['reason'] ?: 'This exam cannot be started now.';
    }
    if ($status['state'] === 'open' && $exam['access_code'] !== ''
        && !hash_equals(mb_strtolower(trim($exam['access_code'])), mb_strtolower(trim($code)))) {
        return 'That code is not right. Look at the board or ask your teacher.';
    }
    return start_or_resume_attempt($exam, $paper, $user, $status['unlock']);
}

/**
 * A student's finished exams (student/results.php, wap/results.php), by the class and school year
 * they were taken in (newest first), then by subject: ["<school year>|<class>" => [subject =>
 * entries]], each entry [exam, result, tries, last, reward]; and the coins earned in Zion Bank.
 */
function student_results(array $user): array
{
    $byYear = [];
    $coins = 0.0;
    foreach (all_exams() as $exam) {
        $attempts = array_filter(list_attempts($exam['id'], $user['username']), fn($a) => $a['status'] !== 'in_progress');
        if (!$attempts) {
            continue;
        }
        $result = exam_student_result($exam, $user['username']);
        $reward = exam_is_reward($exam) ? student_reward_summary($exam['id'], $user['username']) : ['paid' => 0.0, 'waiting' => 0.0];
        $coins += $reward['paid'];
        $last = end($attempts);
        // The class the student was in for their latest try (tries keep it), so last year's exams say "Class 3"
        $group = attempt_school_year($last) . '|' . (string)($last['student']['class'] ?? '');
        $byYear[$group][$exam['subject'] ?: 'Other'][] = ['exam' => $exam, 'result' => $result, 'tries' => count($attempts), 'last' => $last, 'reward' => $reward];
    }
    krsort($byYear);
    foreach ($byYear as &$bySubject) {
        ksort($bySubject);
        foreach ($bySubject as &$entries) {
            usort($entries, fn($a, $b) => strcmp($b['last']['submitted'] ?? '', $a['last']['submitted'] ?? ''));
        }
        unset($entries);
    }
    unset($bySubject);
    return [$byYear, $coins];
}
