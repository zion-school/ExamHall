<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Attempts: one JSON file per student attempt, written only by that student's saves.
 *
 *   data/exams/<exam>/attempts/<username>/<n>.json
 *   {exam, user, n, status (in_progress|submitted|graded), token, epoch, seq,
 *    started, deadline, submitted, late, auto_submitted, seed, unlock,
 *    slots: [{slot, section, key, mark, layout}], choose: {section: 5} ("answer any five"),
 *    answers: {slot: response}, flags: [slot],
 *    grades: {slot: {fraction, state, mark}}, score, max, events: {saves, blur, offline_ms}, saved_at, last_seen,
 *    paused_sec, pauses, student: {name, class} (as they were when the try started)}
 *   data/exams/<exam>/previews/<username>/<n>.json, paper.json
 *   A teacher's preview: a try like a student's with "preview": true, kept apart with the paper it
 *   was given (see "Teacher previews" below).
 *
 * Saving is safe against retries and out-of-order requests: a save is only written when its
 * seq is newer. Saves work with the attempt token alone, so an expired login never loses answers.
 * "epoch" changes when the attempt is opened again elsewhere; the older page is told to stop.
 *
 * Lost connection: "last_seen" is the last contact from the exam page (a save, a heartbeat or
 * opening the page). When the page is not heard from for longer than ATTEMPT_PAUSE_AFTER_SEC
 * while time is left, the try's time stands still: at the next contact the time beyond that
 * minute is added to its deadline (even past the closing time) and to paused_sec. A try that
 * never comes back stays paused until a teacher submits it. Tries from Moodle that were never
 * opened in Exams have no last_seen and never pause.
 */

defined('EXAMS_APP') or exit;

const ATTEMPT_TEXT_LIMIT = 500;
const ATTEMPT_ESSAY_LIMIT = 20000;

/** $preview: a teacher's preview try (previews/ instead of attempts/). */
function attempt_file(string $examId, string $username, int $n, bool $preview = false): string
{
    if (!preg_match('/^[\p{L}\p{N}._@-]+$/u', $username)) {
        throw new InvalidArgumentException('Bad username');
    }
    return exam_dir($examId) . '/' . ($preview ? 'previews' : 'attempts') . "/$username/$n.json";
}

function load_attempt(string $examId, string $username, int $n, bool $preview = false): ?array
{
    try {
        return read_json(attempt_file($examId, $username, $n, $preview));
    } catch (InvalidArgumentException $e) {
        return null;
    }
}

/** A student's attempts (or a teacher's previews), oldest first. */
function list_attempts(string $examId, string $username, bool $preview = false): array
{
    $dir = exam_dir($examId) . '/' . ($preview ? 'previews' : 'attempts') . '/' . $username;
    $out = [];
    foreach (list_files($dir, '/^\d+\.json$/') as $file) {
        if ($a = read_json("$dir/$file")) {
            $out[] = $a;
        }
    }
    usort($out, fn($a, $b) => $a['n'] <=> $b['n']);
    return $out;
}

/** [username => attempts] for everyone who started the exam. */
function all_attempts(string $examId): array
{
    $out = [];
    foreach (list_dirs(exam_dir($examId) . '/attempts') as $username) {
        $out[$username] = list_attempts($examId, $username);
    }
    return $out;
}

/**
 * A try that keeps Moodle's own marks (read-only). A Moodle attempt that was never submitted
 * is not: the student carries on in Exams and it is marked like any other try.
 */
function attempt_has_moodle_marks(array $attempt): bool
{
    return !empty($attempt['migrated']) && empty($attempt['migrated']['unsubmitted']);
}

/**
 * The class a student was in when they wrote their latest try of an exam (kept in the try), so last year's
 * exam shows last year's class after a promotion; for someone without tries, their class today.
 */
function attempt_student_class(string $username, array $attempts): string
{
    foreach (array_reverse($attempts) as $a) {
        $class = normalize_class((string)($a['student']['class'] ?? ''));
        if ($class !== '') {
            return $class;
        }
    }
    return student_info($username, $attempts)['class'];
}

/**
 * Name and class to keep in a try that has none (tries from before they were kept): the class of a one-class
 * exam for a Moodle try (the class of its Moodle course), else the student's class today, from users.csv or the register.
 * Run before students are promoted: bin/fsck.php --fix.
 */
function attempt_student_snapshot(array $exam, array $attempt): array
{
    $username = $attempt['user'];
    $info = student_info($username);
    $name = preg_replace('/ \(Moodle\)$/', '', display_name($username, $attempt));
    $class = !empty($attempt['migrated']) && count($exam['classes']) === 1 ? (string)$exam['classes'][0]
        : ($info['class'] !== '' ? $info['class'] : (string)($attempt['migrated']['class'] ?? (count($exam['classes']) === 1 ? $exam['classes'][0] : '')));
    return ['name' => $name, 'class' => normalize_class($class)];
}

/** A Moodle try whose answers were gone from Moodle: only its mark came across, so there is nothing to mark again. */
function attempt_answers_lost(array $attempt): bool
{
    return !empty($attempt['migrated']['answers_lost']);
}

/** Minutes without a save after which a try in progress no longer counts as "writing now". */
const ATTEMPT_IDLE_MINUTES = 30;

/** No contact from the exam page for longer than this pauses the try's time; shorter drops are not counted. */
const ATTEMPT_PAUSE_AFTER_SEC = 60;

/** Saves this late after time-up are still accepted (the last save on its way when time ran out). */
const ATTEMPT_LATE_SAVE_SEC = 60;

/**
 * A time-up submit from the exam page this long before the server's deadline is refused and the
 * try carries on: the page's timer was wrong (a lab PC's clock set right after booting jumps ahead).
 */
const ATTEMPT_TIME_UP_EARLY_SEC = 5;

/** Last time the student's computer saved or checked in (timestamp), or null. */
function attempt_last_activity(array $attempt): ?int
{
    $t = $attempt['last_seen'] ?? $attempt['saved_at'] ?? $attempt['started'] ?? null;
    return $t ? strtotime($t) : null;
}

/**
 * Seconds of lost connection to add to a try's time at $now: the gap since its last contact beyond
 * ATTEMPT_PAUSE_AFTER_SEC, if time was still left at that contact. 0 without a deadline or last contact.
 * A try open on a WAP phone (wap/, "wap_epoch" is its epoch) never pauses: the phone is only heard from
 * when a page loads, so a minute spent on a question is not a lost connection.
 */
function attempt_pause_due(array $attempt, int $now): int
{
    if ($attempt['status'] !== 'in_progress' || empty($attempt['deadline']) || empty($attempt['last_seen'])) {
        return 0;
    }
    if (isset($attempt['wap_epoch']) && (int)$attempt['wap_epoch'] === (int)$attempt['epoch']) {
        return 0;
    }
    $last = strtotime($attempt['last_seen']);
    if (strtotime($attempt['deadline']) <= $last) {
        return 0;
    }
    return max(0, $now - $last - ATTEMPT_PAUSE_AFTER_SEC);
}

/** The exam page has not been heard from for over a minute while time was left: the try's time stands still. */
function attempt_is_paused(array $attempt, ?int $now = null): bool
{
    return attempt_pause_due($attempt, $now ?? time()) > 0;
}

/** Seconds left on a try at $now (frozen while paused), or null without a deadline. */
function attempt_seconds_left(array $attempt, ?int $now = null): ?int
{
    if ($attempt['status'] !== 'in_progress' || empty($attempt['deadline'])) {
        return null;
    }
    $now ??= time();
    return max(0, strtotime($attempt['deadline']) + attempt_pause_due($attempt, $now) - $now);
}

/** Contact from the exam page at $now: time lost since the last contact is added first. */
function attempt_note_contact(array $attempt, int $now): array
{
    $pause = attempt_pause_due($attempt, $now);
    if ($pause > 0) {
        $attempt['deadline'] = date('c', strtotime($attempt['deadline']) + $pause);
        $attempt['paused_sec'] = (int)($attempt['paused_sec'] ?? 0) + $pause;
        $attempt['pauses'] = (int)($attempt['pauses'] ?? 0) + 1;
    }
    $attempt['last_seen'] = date('c', $now);
    return $attempt;
}

/** Time is up and the late-save minute is over (a paused try is never due). */
function attempt_is_due(array $attempt, ?int $now = null): bool
{
    $now ??= time();
    return $attempt['status'] === 'in_progress' && !empty($attempt['deadline'])
        && !attempt_is_paused($attempt, $now) && $now > strtotime($attempt['deadline']) + ATTEMPT_LATE_SAVE_SEC;
}

/** A try in progress that saved within the last ATTEMPT_IDLE_MINUTES: the student is writing now. */
function attempt_is_active(array $attempt): bool
{
    $t = attempt_last_activity($attempt);
    return $attempt['status'] === 'in_progress' && $t !== null && $t >= time() - ATTEMPT_IDLE_MINUTES * 60;
}

/**
 * [answered, questions] of a try (descriptions are not questions). A section with "answer any
 * five" asks for five, however many of its questions the student answers.
 */
function attempt_progress(array $paper, array $attempt): array
{
    $answers = (array)$attempt['answers'];
    $choose = (array)($attempt['choose'] ?? []);
    $bySection = [];
    foreach ($attempt['slots'] as $slot) {
        if (($paper['questions'][$slot['key']]['type'] ?? '') === 'description') {
            continue;
        }
        $bySection[(int)($slot['section'] ?? 0)][] = !grade_is_blank($answers[$slot['slot']] ?? null);
    }
    $answered = 0;
    $questions = 0;
    foreach ($bySection as $si => $list) {
        $need = (int)($choose[$si] ?? 0);
        $need = $need > 0 ? min($need, count($list)) : count($list);
        $questions += $need;
        $answered += min($need, count(array_filter($list)));
    }
    return [$answered, $questions];
}

/**
 * Marks so far of a try that is not submitted, worked out from its saved answers the same way
 * as on submitting. Nothing is saved and no AI check is started; the student can carry on.
 * Returns the try with grades and score filled in, still in_progress, with so_far => true.
 */
function attempt_marks_so_far(array $exam, array $paper, array $attempt): array
{
    $a = json_decode(json_encode($attempt), true);
    $a['answers'] = $a['answers'] ?: [];
    $a['grades'] = [];
    $quiet = $exam;
    $quiet['grading']['llm'] = 'off';
    $a = smart_grade_attempt($quiet, $paper, grade_attempt($paper, $a, answer_leniency($exam)));
    $a['status'] = 'in_progress';
    $a['so_far'] = true;
    return $a;
}

/** Deadline timestamp for an attempt started at $started: time limit and closing time, with the student's own settings. */
function attempt_deadline(array $exam, string $username, int $started): ?int
{
    $override = exam_overrides($exam['id'])[$username] ?? [];
    $deadline = null;
    if ((int)$exam['time_limit_min'] > 0) {
        $deadline = $started + ((int)$exam['time_limit_min'] + (int)($override['extra_minutes'] ?? 0)) * 60;
    }
    $close = exam_window($exam, $username)['close'];
    if ($close !== null) {
        $deadline = $deadline === null ? $close : min($deadline, $close);
    }
    return $deadline;
}

/**
 * After the closing time, time limit or a student's own times change: tries in progress get
 * the new deadline (the player picks it up with its next save). Returns how many changed.
 */
function refresh_attempt_deadlines(array $exam, ?string $onlyUser = null): int
{
    $changed = 0;
    foreach (all_attempts($exam['id']) as $username => $list) {
        if ($onlyUser !== null && $username !== $onlyUser) {
            continue;
        }
        foreach ($list as $a) {
            if ($a['status'] !== 'in_progress') {
                continue;
            }
            $deadline = attempt_deadline($exam, $username, strtotime($a['started']));
            $new = $deadline ? date('c', $deadline + (int)($a['paused_sec'] ?? 0)) : null;
            if ($new !== $a['deadline']) {
                update_json(attempt_file($exam['id'], $username, $a['n']), function ($x) use ($new) {
                    if ($x['status'] !== 'in_progress') {
                        return false;
                    }
                    $x['deadline'] = $new;
                    return $x;
                });
                $changed++;
            }
        }
    }
    return $changed;
}

/** Build the per-attempt question order, random picks and choice orders. */
function build_attempt_slots(array $exam, array $paper, SeededRandom $rng): array
{
    $used = [];
    foreach ($paper['sections'] as $section) {
        foreach ($section['slots'] as $item) {
            if ($item['kind'] === 'fixed') {
                $used[$item['q']] = true;
            }
        }
    }
    $slots = [];
    foreach ($paper['sections'] as $si => $section) {
        $picked = [];
        foreach ($section['slots'] as $item) {
            if ($item['kind'] === 'fixed') {
                $picked[] = ['key' => $item['q'], 'mark' => (float)$item['mark']];
                continue;
            }
            $pool = array_values(array_filter($item['pool'], fn($k) => !isset($used[$k])));
            foreach (array_slice($rng->shuffle($pool), 0, (int)$item['count']) as $key) {
                $used[$key] = true;
                $picked[] = ['key' => $key, 'mark' => (float)$item['mark']];
            }
        }
        if (!empty($exam['shuffle_questions']) && !empty($section['shuffle'])) {
            $picked = $rng->shuffle($picked);
        }
        foreach ($picked as $p) {
            $q = $paper['questions'][$p['key']];
            $slots[] = [
                'slot' => count($slots) + 1,
                'section' => $si,
                'key' => $p['key'],
                'mark' => $q['type'] === 'description' ? 0.0 : $p['mark'],
                'layout' => question_layout($q, $rng, !empty($exam['shuffle_choices'])),
            ];
        }
    }
    return $slots;
}

/**
 * Start a new attempt, or reopen the student's attempt in progress (the old page is retired).
 */
function start_or_resume_attempt(array $exam, array $paper, array $user, ?int $unlock): array
{
    $username = $user['username'];
    $dirLock = exam_dir($exam['id']) . "/attempts/$username/.start";
    return with_lock($dirLock, function () use ($exam, $paper, $user, $username, $unlock) {
        foreach (list_attempts($exam['id'], $username) as $a) {
            if ($a['status'] === 'in_progress') {
                return reopen_attempt(attempt_file($exam['id'], $username, $a['n']));
            }
        }
        $now = time();
        $attempt = new_attempt($exam, $paper, $user, 1 + count(list_attempts($exam['id'], $username)), attempt_deadline($exam, $username, $now), $unlock, $now);
        write_json(attempt_file($exam['id'], $username, $attempt['n']), $attempt);
        return json_decode(json_encode($attempt), true);
    });
}

/** Open a try in progress again, e.g. on another computer: the page that had it is retired (its epoch is old). */
function reopen_attempt(string $file): array
{
    return update_json($file, function ($att) {
        $att['epoch']++;
        return empty($att['last_seen']) ? $att : attempt_note_contact($att, time());   // Moodle tries never opened here: no pause
    });
}

/** A new try, not written yet: its own question order, random picks and choice orders from a new seed. */
function new_attempt(array $exam, array $paper, array $user, int $n, ?int $deadline, ?int $unlock, int $now): array
{
    $username = $user['username'];
    $seed = random_int(1, 0x7FFFFFFF);
    $slots = build_attempt_slots($exam, $paper, new SeededRandom($seed));
    $choose = paper_choose_map($paper);   // kept with the try: marking later needs it without the paper
    return [
        'exam' => $exam['id'], 'user' => $username, 'n' => $n, 'status' => 'in_progress',
        'token' => random_secret(), 'epoch' => 1, 'seq' => 0,
        'started' => date('c', $now), 'deadline' => $deadline ? date('c', $deadline) : null,
        'submitted' => null, 'late' => false, 'auto_submitted' => false,
        'seed' => $seed, 'unlock' => $unlock,
        'slots' => $slots, 'choose' => $choose, 'answers' => new stdClass(), 'flags' => [],
        'grades' => new stdClass(), 'score' => null, 'max' => slots_max_marks($slots, $choose),
        'events' => ['saves' => 0, 'blur' => 0, 'offline_ms' => 0], 'saved_at' => null,
        'last_seen' => date('c', $now), 'paused_sec' => 0, 'pauses' => 0,
        'student' => ['name' => (string)($user['name'] ?? $username), 'class' => normalize_class((string)($user['class'] ?? ''))],   // who wrote it, should they leave school
    ];
}

/** Keep only well-formed responses for the attempt's questions. */
function sanitize_answers(array $paper, array $attempt, $answers): array
{
    $clean = [];
    if (!is_array($answers)) {
        return $clean;
    }
    $bySlot = array_column($attempt['slots'], null, 'slot');
    foreach ($answers as $slot => $value) {
        $slot = (int)$slot;
        if (!isset($bySlot[$slot]) || $value === null) {
            continue;
        }
        $q = $paper['questions'][$bySlot[$slot]['key']] ?? null;
        if ($q && ($v = sanitize_response($q, $value)) !== null) {
            $clean[$slot] = $v;
        }
    }
    ksort($clean);
    return $clean;
}

function sanitize_response(array $q, $value)
{
    switch ($q['type']) {
        case 'multichoice':
            if (!empty($q['single'])) {
                return is_numeric($value) && isset($q['answers'][(int)$value]) ? (int)$value : null;
            }
            if (!is_array($value)) {
                return null;
            }
            $v = array_values(array_unique(array_filter(array_map('intval', $value), fn($i) => isset($q['answers'][$i]))));
            sort($v);
            return $v;
        case 'truefalse':
            return is_bool($value) ? $value : null;
        case 'shortanswer':
        case 'numerical':
            return is_scalar($value) ? mb_substr((string)$value, 0, ATTEMPT_TEXT_LIMIT) : null;
        case 'essay':
            if (!is_string($value)) {
                return null;
            }
            // Typed with the formatting toolbar: keep only the tags and colours the toolbar makes,
            // and cut at the limit by letters typed, never in the middle of a tag.
            $html = StudentHtml::store($value, ATTEMPT_ESSAY_LIMIT);
            return StudentHtml::is_blank($html) ? null : $html;
        case 'match':
            if (!is_array($value)) {
                return null;
            }
            $choices = count(match_choices($q));
            $v = [];
            foreach ($value as $stem => $choice) {
                if (isset($q['pairs'][(int)$stem]) && is_numeric($choice) && (int)$choice >= 0 && (int)$choice < $choices) {
                    $v[(int)$stem] = (int)$choice;
                }
            }
            return $v;
        case 'multiblank':
            if (!is_array($value)) {
                return null;
            }
            $v = [];
            foreach ($value as $i => $blankValue) {
                if (isset($q['blanks'][(int)$i]) && $blankValue !== null && ($bv = sanitize_response($q['blanks'][(int)$i], $blankValue)) !== null) {
                    $v[(int)$i] = $bv;
                }
            }
            return $v;
    }
    return null;
}

/**
 * Grade all slots; sets grades, score and status (graded, or submitted when a teacher must mark something).
 * $lenient: what short answers may differ in, answer_leniency($exam).
 */
function grade_attempt(array $paper, array $attempt, array $lenient = MOODLE_LENIENCY): array
{
    $grades = [];
    foreach ($attempt['slots'] as $slot) {
        $q = $paper['questions'][$slot['key']];
        $g = grade_response($q, $attempt['answers'][$slot['slot']] ?? null, $lenient);
        $existing = $attempt['grades'][$slot['slot']] ?? null;
        if ($existing && ($existing['by'] ?? '') === 'teacher') {
            $g = $existing;   // manual marks are kept on regrade
        } elseif ($existing && $q['type'] === 'multiblank' && !empty($existing['blank_info'])) {
            // Keep teacher decisions on single blanks
            foreach ($existing['blank_info'] as $i => $info) {
                if (($info['by'] ?? '') === 'teacher' && isset($g['blanks'])) {
                    $g['blanks'][$i] = (float)$info['fraction'];
                    $g['blank_info'][$i] = $info;
                }
            }
            if (isset($g['blanks'])) {
                $g['fraction'] = array_sum($g['blanks']) / max(1, count($q['blanks']));
            }
        }
        $g['mark'] = $g['fraction'] === null ? null : round($g['fraction'] * $slot['mark'], 4);
        $g['by'] ??= 'auto';
        $grades[$slot['slot']] = $g;
    }
    $attempt['grades'] = $grades;
    return recompute_attempt_score($attempt);   // score, status, and the best N of an optional section
}

/**
 * Save answers from the exam player. Returns a response array for the browser.
 * $req: exam, user, n, token, epoch, seq, answers, flags, events, final, time_up
 * final + time_up: the page's timer ran out. The server's clock decides: too early, the answers are
 * saved and the reply says early => true with the right deadline; otherwise it is submitted automatically.
 * $preview: the try is a teacher's preview (its own folder and paper).
 */
function save_attempt_request(array $req, bool $preview = false): array
{
    $examId = (string)($req['exam'] ?? '');
    $username = (string)($req['user'] ?? '');
    $n = (int)($req['n'] ?? 0);
    $exam = load_exam($examId);
    try {
        $file = attempt_file($examId, $username, $n, $preview);
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'error' => 'not_found', 'http' => 404];
    }
    $paper = $exam ? ($preview ? load_preview_paper($examId, $username) : load_paper($examId)) : null;
    if (!$exam || !$paper || !is_file($file)) {
        return ['ok' => false, 'error' => 'not_found', 'http' => 404];
    }

    $response = null;
    update_json($file, function ($a) use ($req, $exam, $paper, &$response) {
        if (empty($a['token'])) {
            $response = ['ok' => false, 'error' => 'not_found', 'http' => 404];   // a preview replaced by a new one meanwhile
            return false;
        }
        if (!is_string($req['token'] ?? null) || !hash_equals($a['token'], $req['token'])) {
            $response = ['ok' => false, 'error' => 'bad_token', 'http' => 403];
            return false;
        }
        $base = ['server_time' => date('c'), 'deadline' => $a['deadline'], 'status' => $a['status'], 'ack' => $a['seq']];
        if ($a['status'] !== 'in_progress') {
            $response = $base + ['ok' => false, 'error' => 'finished'];
            return false;
        }
        if (has_left_school($a['user'])) {
            // Removed from users.csv (left school) with the exam page still open: the try stays as last saved
            $response = $base + ['ok' => false, 'error' => 'account_removed', 'http' => 403];
            return false;
        }
        if ((int)($req['epoch'] ?? 0) !== (int)$a['epoch']) {
            $response = $base + ['ok' => false, 'error' => 'other_device', 'http' => 409];
            return false;
        }
        $lastSeen = $a['last_seen'] ?? null;
        $now = time();
        $paused = attempt_pause_due($a, $now) > 0;
        $a = attempt_note_contact($a, $now);   // lost time is added before the deadline is checked
        $deadline = $a['deadline'] ? strtotime($a['deadline']) : null;
        if ($deadline !== null && $now > $deadline + ATTEMPT_LATE_SAVE_SEC) {
            // Too late for new answers: finish with what was saved before
            $a = finish_attempt($a, $paper, true);
            $response = ['ok' => false, 'error' => 'time_over', 'server_time' => date('c'), 'deadline' => $a['deadline'], 'status' => $a['status'], 'ack' => $a['seq']];
            return $a;
        }
        $seq = (int)($req['seq'] ?? 0);
        $changed = $paused;
        if ($seq > (int)$a['seq']) {
            $a['answers'] = sanitize_answers($paper, $a, $req['answers'] ?? []) ?: new stdClass();
            $a['flags'] = array_values(array_filter(array_map('intval', (array)($req['flags'] ?? [])), fn($s) => $s >= 1 && $s <= count($a['slots'])));
            $events = (array)($req['events'] ?? []);
            $a['events']['blur'] = max((int)$a['events']['blur'], (int)($events['blur'] ?? 0));
            $a['events']['offline_ms'] = max((int)$a['events']['offline_ms'], (int)($events['offline_ms'] ?? 0));
            $a['events']['saves']++;
            $a['seq'] = $seq;
            $a['saved_at'] = date('c', $now);
            if ($deadline !== null && $now > $deadline) {
                $a['late'] = true;
            }
            $changed = true;
        }
        $early = !empty($req['final']) && !empty($req['time_up'])
            && ($deadline === null || $now < $deadline - ATTEMPT_TIME_UP_EARLY_SEC);
        if (!empty($req['final']) && !$early) {
            $a = json_decode(json_encode($a), true);
            $a = finish_attempt($a, $paper, !empty($req['time_up']));
            $changed = true;
        }
        // Heartbeats without changes are written too (at most every 5 s): the time of the last contact decides pauses
        if (!$changed && $now - strtotime($lastSeen ?? '@0') >= 5) {
            $changed = true;
        }
        $response = ['ok' => true, 'ack' => $a['seq'], 'server_time' => date('c'), 'deadline' => $a['deadline'], 'status' => $a['status'], 'late' => $a['late']];
        if ($early) {
            $response['early'] = true;
        }
        return $changed ? $a : false;
    });
    return $response;
}

/** Submit: mark finished and grade (a preview is never sent to AI checking). */
function finish_attempt(array $a, array $paper, bool $auto): array
{
    $a = json_decode(json_encode($a), true);   // stdClass placeholders -> arrays
    $a['answers'] = $a['answers'] ?: [];
    $a['submitted'] = now_iso();
    $a['auto_submitted'] = $auto;
    $exam = load_exam($a['exam']);
    $a = grade_attempt($paper, $a, answer_leniency($exam));
    if ($exam && !empty($a['preview'])) {
        $exam['grading']['llm'] = 'off';
    }
    return $exam ? smart_grade_attempt($exam, $paper, $a) : $a;
}

/** Re-mark an attempt after answers or settings changed (teacher marks are kept). */
function regrade_attempt(array $exam, string $username, int $n): ?array
{
    $paper = load_paper($exam['id']);
    $file = attempt_file($exam['id'], $username, $n);
    if (!is_file($file)) {
        return null;
    }
    return update_json($file, function ($a) use ($exam, $paper) {
        if ($a['status'] === 'in_progress' || attempt_has_moodle_marks($a)) {
            return false;
        }
        return smart_grade_attempt($exam, $paper, grade_attempt($paper, $a, answer_leniency($exam)));
    });
}

/** Submit an attempt (or preview) whose time (and the late-save minute) is over; paused tries wait. Returns the (possibly updated) attempt. */
function attempt_expire_if_due(array $exam, array $attempt): array
{
    if (!attempt_is_due($attempt)) {
        return $attempt;
    }
    $preview = !empty($attempt['preview']);
    $paper = $preview ? load_preview_paper($exam['id'], $attempt['user']) : load_paper($exam['id']);
    return update_json(attempt_file($exam['id'], $attempt['user'], $attempt['n'], $preview), function ($a) use ($paper) {
        return attempt_is_due($a) ? finish_attempt($a, $paper, true) : $a;
    });
}

/** Submit every try of an exam whose time is over (closing time or time limit, not paused). Returns how many. */
function exam_expire_due_attempts(array $exam): int
{
    $count = 0;
    foreach (all_attempts($exam['id']) as $list) {
        foreach ($list as $a) {
            if ($a['status'] === 'in_progress' && attempt_expire_if_due($exam, $a)['status'] !== 'in_progress') {
                $count++;
                fire_hook('attempt.graded', $exam['id'], $a['user']);
            }
        }
    }
    return $count;
}

/** Teacher action: submit an attempt now. */
function force_submit_attempt(array $exam, string $username, int $n): ?array
{
    $paper = load_paper($exam['id']);
    $file = attempt_file($exam['id'], $username, $n);
    if (!is_file($file)) {
        return null;
    }
    return update_json($file, fn($a) => $a['status'] === 'in_progress' ? finish_attempt($a, $paper, true) : $a);
}

/**
 * Width of a typed blank in characters: the longest right answer plus 2, at least 4,
 * so the answer fits without showing its exact length.
 */
function blank_size(array $part): int
{
    $longest = 0;
    foreach ($part['answers'] as $a) {
        if ((float)$a['fraction'] > 0 && ($a['value'] ?? '') !== '*') {
            $text = $part['type'] === 'numerical' ? format_number_answer(['value' => $a['value']]) : html_to_text($a['text']);
            $longest = max($longest, mb_strlen(trim($text)));
        }
    }
    return max(4, $longest + 2);
}

/** Questions for the exam player: no answers, choices in this attempt's order, HTML ready. */
function attempt_client_questions(array $exam, array $paper, array $attempt): array
{
    $mediaUrl = exam_media_url($exam['id']);
    $out = [];
    foreach ($attempt['slots'] as $slot) {
        $q = $paper['questions'][$slot['key']];
        $format = $q['format'] ?? 'moodle';
        $item = [
            'slot' => $slot['slot'],
            'section' => $slot['section'],
            'type' => $q['type'],
            'mark' => $slot['mark'],
            'html' => format_text($q['text'], $format, $mediaUrl),
            'missing_word' => !empty($q['missing_word']),
        ];
        $layout = $slot['layout'];
        switch ($q['type']) {
            case 'multichoice':
                $item['single'] = !empty($q['single']);
                $item['choices'] = array_map(fn($i) => ['id' => $i, 'html' => format_text($q['answers'][$i]['text'], $q['answers'][$i]['format'] ?? $format, $mediaUrl)], $layout['choices']);
                break;
            case 'match':
                $choices = match_choices($q);
                $item['stems'] = array_map(fn($i) => ['id' => $i, 'html' => format_text($q['pairs'][$i]['question'], $q['pairs'][$i]['format'] ?? $format, $mediaUrl)], $layout['stems']);
                $item['options'] = array_map(fn($i) => ['id' => $i, 'text' => $choices[$i]], $layout['choices']);
                break;
            case 'shortanswer':
            case 'numerical':
                $item['size'] = blank_size($q);
                break;
            case 'multiblank':
                $item['blanks'] = [];
                foreach ($q['blanks'] as $bi => $blank) {
                    $b = ['type' => $blank['type']];
                    if ($blank['type'] === 'shortanswer' || $blank['type'] === 'numerical') {
                        $b['size'] = blank_size($blank);
                    }
                    if ($blank['type'] === 'multichoice') {
                        $order = $layout['blanks'][$bi]['choices'] ?? array_keys($blank['answers']);
                        $b['choices'] = array_map(fn($i) => ['id' => $i, 'text' => html_to_text($blank['answers'][$i]['text'], '(picture)')], $order);
                    }
                    $item['blanks'][] = $b;
                }
                break;
        }
        $out[] = $item;
    }
    return $out;
}

/** Teacher action: give an attempt in progress more time (also stored as an override for new attempts). */
function extend_attempt_time(array $exam, string $username, int $minutes): void
{
    $current = exam_overrides($exam['id'])[$username]['extra_minutes'] ?? 0;
    set_exam_override($exam['id'], $username, ['extra_minutes' => $current + $minutes]);
    foreach (list_attempts($exam['id'], $username) as $a) {
        if ($a['status'] === 'in_progress' && $a['deadline']) {
            update_json(attempt_file($exam['id'], $username, $a['n']), function ($x) use ($minutes) {
                $base = attempt_is_paused($x) ? strtotime($x['deadline']) : max(strtotime($x['deadline']), time());
                $x['deadline'] = date('c', $base + $minutes * 60);
                return $x;
            });
        }
    }
}

/**
 * Teacher marks for slots: [slot => mark]. Marked slots are kept on regrade.
 * Returns the updated attempt.
 */
function set_manual_marks(array $exam, string $username, int $n, array $marks, string $by, array $comments = []): ?array
{
    $paper = load_paper($exam['id']);
    $file = attempt_file($exam['id'], $username, $n);
    if (!is_file($file)) {
        return null;
    }
    $saved = update_json($file, function ($a) use ($exam, $paper, $marks, $by, $comments) {
        if ($a['status'] === 'in_progress' || attempt_answers_lost($a)) {
            return false;   // nothing written
        }
        $bySlot = array_column($a['slots'], null, 'slot');
        foreach ($marks as $slot => $mark) {
            $slot = (int)$slot;
            if (!isset($bySlot[$slot]) || $mark === '' || $mark === null) {
                continue;
            }
            $max = (float)$bySlot[$slot]['mark'];
            $mark = max(0.0, min($max, (float)$mark));
            $a['grades'][$slot] = [
                'fraction' => $max > 0 ? $mark / $max : 0.0, 'state' => 'graded', 'by' => 'teacher',
                'marked_by' => $by, 'marked_at' => now_iso(), 'comment' => trim((string)($comments[$slot] ?? '')),
            ];
        }
        return grade_attempt($paper, $a, answer_leniency($exam));
    });
    return $saved === false ? load_attempt($exam['id'], $username, $n) : $saved;
}

/** Rows for the live monitor: one per student in the audience (plus anyone else who started). */
function exam_monitor_rows(array $exam): array
{
    $students = exam_students($exam);
    $attempts = all_attempts($exam['id']);
    foreach (array_keys($attempts) as $username) {
        if (!isset($students[$username])) {
            $students[$username] = student_info($username, $attempts[$username]);
        }
    }
    $paper = load_paper($exam['id']);
    $rows = [];
    foreach ($students as $username => $s) {
        $list = $attempts[$username] ?? [];
        $latest = $list ? end($list) : null;
        if ($latest && $latest['status'] === 'in_progress') {
            $latest = attempt_expire_if_due($exam, $latest);
        }
        $row = [
            'username' => $username, 'name' => $s['name'], 'class' => $s['class'],
            'attempts' => count($list), 'status' => $latest['status'] ?? 'not_started', 'n' => $latest['n'] ?? null,
            'online' => false, 'last_seen' => null, 'answered' => 0, 'questions' => 0, 'seconds_left' => null,
            'late' => false, 'blur' => 0, 'offline_ms' => 0, 'paused' => false, 'paused_sec' => 0, 'percent' => null, 'needs_marking' => false,
        ];
        if ($latest) {
            $seen = $latest['last_seen'] ?? $latest['saved_at'] ?? $latest['started'];
            $row['last_seen'] = $seen;
            $row['online'] = $latest['status'] === 'in_progress' && strtotime($seen) >= time() - 45;
            if ($paper) {
                [$row['answered'], $row['questions']] = attempt_progress($paper, $latest);
            }
            $row['seconds_left'] = attempt_seconds_left($latest);
            $row['paused'] = attempt_is_paused($latest);
            $row['paused_sec'] = (int)($latest['paused_sec'] ?? 0) + attempt_pause_due($latest, time());
            $row['late'] = !empty($latest['late']);
            $row['blur'] = (int)($latest['events']['blur'] ?? 0);
            $row['offline_ms'] = (int)($latest['events']['offline_ms'] ?? 0);
            $row['needs_marking'] = $latest['status'] === 'submitted';
            if ($latest['status'] !== 'in_progress' && $latest['max'] > 0) {
                $row['percent'] = round(100 * $latest['score'] / $latest['max']);
            } elseif ($latest['status'] === 'in_progress' && $latest['max'] > 0 && $paper) {
                $row['percent'] = round(100 * attempt_marks_so_far($exam, $paper, $latest)['score'] / $latest['max']);
                $row['so_far'] = true;
                $row['idle'] = !attempt_is_active($latest);
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

// ---------- Teacher previews ----------
// A teacher who can edit an exam can take it as a new student would (teacher/preview.php). The
// preview is a try like a student's, marked "preview": true, kept in data/exams/<exam>/previews/<username>/
// with the paper it was given (paper.json). Nothing that looks at students' tries (results, the
// monitor, rewards, AI checking, fsck) reads that folder, so a preview never counts, never pays and
// never locks the questions. Only the latest preview is kept; its number goes on from the one
// before, so a copy an earlier preview left in the browser is never taken for it. Its time is the
// time limit alone: the closing time, unlocks, the exam code and students' own times do not apply.

function load_preview_paper(string $examId, string $username): ?array
{
    try {
        return read_json(dirname(attempt_file($examId, $username, 0, true)) . '/paper.json');
    } catch (InvalidArgumentException $e) {
        return null;
    }
}

/** The paper a preview gets: the one students get once the exam is published, the saved questions of a draft. */
function preview_paper(array $exam): array
{
    return ($exam['status'] !== 'draft' ? load_paper($exam['id']) : null) ?? make_paper($exam);
}

/** Start a new preview for a teacher; the one before is removed. null when the exam has no questions yet. */
function start_preview(array $exam, array $user): ?array
{
    $paper = preview_paper($exam);
    if (!$paper['questions']) {
        return null;
    }
    $username = $user['username'];
    $dir = dirname(attempt_file($exam['id'], $username, 0, true));
    return with_lock("$dir/.start", function () use ($exam, $paper, $user, $username, $dir) {
        $old = list_attempts($exam['id'], $username, true);
        $n = 1 + max([0, ...array_column($old, 'n')]);
        $now = time();
        $deadline = (int)$exam['time_limit_min'] > 0 ? $now + (int)$exam['time_limit_min'] * 60 : null;
        $attempt = new_attempt($exam, $paper, $user, $n, $deadline, null, $now) + ['preview' => true];
        // The old tries go before their paper does: a page still open on one gets "not found", never the new paper
        foreach ($old as $a) {
            @unlink(attempt_file($exam['id'], $username, (int)$a['n'], true));
        }
        write_json("$dir/paper.json", $paper);
        write_json(attempt_file($exam['id'], $username, $n, true), $attempt);
        return json_decode(json_encode($attempt), true);
    });
}

/** A teacher's latest preview of an exam (submitted first when its time is over), or null. */
function latest_preview(array $exam, string $username): ?array
{
    $list = list_attempts($exam['id'], $username, true);
    return $list ? attempt_expire_if_due($exam, end($list)) : null;
}

/** Open a teacher's preview in progress again (the page that had it is retired), or null when none is running. */
function resume_preview(array $exam, string $username): ?array
{
    $dir = dirname(attempt_file($exam['id'], $username, 0, true));
    return with_lock("$dir/.start", function () use ($exam, $username) {
        $latest = latest_preview($exam, $username);
        return $latest && $latest['status'] === 'in_progress' ? reopen_attempt(attempt_file($exam['id'], $username, (int)$latest['n'], true)) : null;
    });
}
