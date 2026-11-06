<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * Included first by every WAP page: the Exams app (its lib, data, logins and
 * session; a student logged in here is logged in as on the web) and what the
 * phone pages share: the student and their exam, a try opened on the phone,
 * saving answers, and question text as WML.
 */

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/wml.php';

start_app_session();

/** The logged-in student; staff are told to use a computer (staff.php) */
function wap_student(): array
{
    $user = current_user();
    if (!$user) {
        wap_redirect('login.php');
    }
    if (is_staff($user)) {
        wap_redirect('staff.php');
    }
    return $user;
}

/** Stop a POST without the session's CSRF token (an old page after logging in again) */
function wap_csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        wap_flash('This page had expired. Please try again.');
        wap_redirect('index.php');
    }
}

/** The exam of ?id= with its paper, as [exam, paper, status]; home when the student can't see it */
function wap_exam(array $user, bool $taken_ok = true): array
{
    $exam = load_exam((string)($_GET['id'] ?? ''));
    $paper = $exam ? load_paper($exam['id']) : null;
    $status = $exam && $paper ? exam_student_status($exam, $user) : ['state' => 'hidden', 'attempts' => []];
    // As on the web: one the student has taken but can no longer see still opens, for the results;
    // one the teacher hid does not
    $took_it = (bool)array_filter($status['attempts'], fn($a) => $a['status'] !== 'in_progress');
    if ($status['state'] === 'hidden' && (!$taken_ok || !$took_it || !empty($exam['hidden']))) {
        wap_flash('This exam is not available');
        wap_redirect('index.php');
    }
    return [$exam, $paper, $status];
}

/** "12.5", "3": marks without trailing zeros */
function fmt_num($x): string
{
    return rtrim(rtrim(number_format((float)$x, 2, '.', ''), '0'), '.');
}

/** Coins in Zion Bank: "Rs5" (phone fonts have no ₹ glyph) */
function wap_money($x): string
{
    return 'Rs' . fmt_num($x);
}

/** Time left on a phone's title line: "12 min", "45 s" */
function left_text(int $seconds): string
{
    return $seconds >= 60 ? intdiv($seconds, 60) . ' min' : $seconds . ' s';
}

/** "Today 9:00AM", "Fri 18 Dec 9:00AM": moment_text() shorter */
function when_text(int $t): string
{
    return day_text($t) . ' ' . date('g:iA', $t);
}

// ---------------------------------------------------------------- A try on the phone

/**
 * The phone takes over a try just started or opened (student_start_exam()): the web page or other
 * phone that had it is retired already (its epoch is old), and the try never pauses while the
 * phone has it (attempt_pause_due()). The phone's epoch is kept in the session.
 */
function wap_take_over(array $attempt): array
{
    $attempt = update_json(attempt_file($attempt['exam'], $attempt['user'], $attempt['n']), function ($a) {
        $a['wap_epoch'] = $a['epoch'];
        return $a;
    });
    $_SESSION['wap_try'][$attempt['exam']] = ['n' => $attempt['n'], 'epoch' => $attempt['epoch']];
    return $attempt;
}

/**
 * The try in progress of this exam, held by the phone; else to its result (finished) or to
 * the exam (not started, or opened since on a computer or another phone)
 */
function wap_playing(array $exam, array $status): array
{
    $attempt = $status['state'] === 'in_progress' ? $status['in_progress'] : null;
    if (!$attempt) {
        $done = array_filter($status['attempts'], fn($a) => $a['status'] !== 'in_progress');
        wap_redirect($done ? wap_url('result.php', ['id' => $exam['id'], 'n' => end($done)['n']]) : wap_url('exam.php', ['id' => $exam['id']]));
    }
    if (!wap_holds($attempt)) {
        wap_refused(['error' => 'other_device'], $exam, $attempt);
    }
    return $attempt;
}

/** Is the try still the phone's? (Not opened since on a computer or another phone) */
function wap_holds(array $attempt): bool
{
    $mine = $_SESSION['wap_try'][$attempt['exam']] ?? null;
    return $mine && $mine['n'] === $attempt['n'] && $mine['epoch'] === $attempt['epoch'];
}

/** The questions of a try in order, descriptions included: [slot number => [slot, question]] */
function try_questions(array $paper, array $attempt): array
{
    $out = [];
    foreach ($attempt['slots'] as $slot) {
        $out[$slot['slot']] = [$slot, $paper['questions'][$slot['key']]];
    }
    return $out;
}

/** [slot number => "Q3"]: questions numbered as on the result card (descriptions are not) */
function question_numbers(array $questions): array
{
    $out = [];
    $n = 0;
    foreach ($questions as $s => [, $q]) {
        $out[$s] = $q['type'] === 'description' ? '' : 'Q' . ++$n;
    }
    return $out;
}

/**
 * Save answers and flags of a try (the whole set, as the web player does), or submit it.
 * Returns save_attempt_request()'s reply: ok, or error finished / time_over / other_device /
 * account_removed / bad_token / not_found.
 */
function wap_save(array $attempt, array $answers, array $flags, bool $final = false, bool $time_up = false): array
{
    $mine = $_SESSION['wap_try'][$attempt['exam']] ?? ['epoch' => -1];
    return save_attempt_request([
        'exam' => $attempt['exam'], 'user' => $attempt['user'], 'n' => $attempt['n'],
        'token' => $attempt['token'], 'epoch' => $mine['epoch'], 'seq' => (int)$attempt['seq'] + 1,
        'answers' => $answers, 'flags' => $flags, 'events' => $attempt['events'],
        'final' => $final, 'time_up' => $time_up,
    ]);
}

/**
 * Go to $path once the try is submitted, then run the hooks (Zion Bank rewards) without keeping
 * the phone waiting, as api/attempt_save.php does.
 */
function wap_redirect_then_hooks(string $path, array $attempt): void
{
    header('Location: ' . wap_abs_url($path));
    header('Content-Length: 0');
    header('Connection: close');
    session_write_close();
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
    fire_hook('attempt.graded', $attempt['exam'], $attempt['user']);
    exit();
}

/** After a save that was refused: where the student goes, with why */
function wap_refused(array $res, array $exam, array $attempt): void
{
    $id = $exam['id'];
    switch ($res['error'] ?? '') {
        case 'finished':
            wap_redirect(wap_url('result.php', ['id' => $id, 'n' => $attempt['n']]));
        case 'time_over':           // submitted now, with the answers saved before
            wap_redirect_then_hooks(wap_url('result.php', ['id' => $id, 'n' => $attempt['n']]), $attempt);
        case 'other_device':
            wap_flash('This exam was opened on another computer or phone. Continue to carry on here.');
            wap_redirect(wap_url('exam.php', ['id' => $id]));
        default:
            wap_flash('Your answer could not be saved');
            wap_redirect('index.php');
    }
}

// ---------------------------------------------------------------- Question text

/**
 * Text of a question as WML: paragraphs and list items on lines of their own, pictures of the
 * exam as WBMP (pic.php) with their alt text, blanks of a multi-blank question as [1], [2].
 * $fields: WML to put in place of marks in the text (an answer's field in its blank).
 */
function wap_rich(string $text, string $format, string $exam_id, array $fields = []): string
{
    $html = format_text($text, $format);
    $html = preg_replace('/\{#(\d+)\}/', '[$1]', $html);
    $out = [];
    foreach (preg_split('/(<img\b[^>]*>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
        if ($i % 2 === 0) {
            $lines = html_lines($part);
            if ($lines) {
                $out[] = implode("<br/>\n", array_map('e', $lines));
            }
            continue;
        }
        $src = preg_match('/\bsrc\s*=\s*"([^"]*)"/i', $part, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $alt = preg_match('/\balt\s*=\s*"([^"]*)"/i', $part, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        $src = rawurldecode($src);
        if (str_starts_with($src, 'media/')) {
            $href = wap_url('pic.php', ['id' => $exam_id, 'f' => substr($src, 6)]);
            $out[] = '<img src="' . e($href) . '" alt="' . e($alt !== '' ? $alt : 'Picture') . '"/>';
        } else {
            $out[] = e('[' . ($alt !== '' ? $alt : 'picture') . ']');
        }
    }
    return strtr(implode("<br/>\n", $out), $fields);
}

/**
 * The web's icons in $html (layout.php's icon(): an SVG <use href="#i-clock">)
 * as markers html_lines() keeps, for wml_line() to draw as the phone's own
 * icons. $map: web icon => phone icon; the others are left out.
 */
function icon_markers(string $html, array $map): string
{
    return preg_replace_callback('#<svg\b[^>]*>\s*<use href="\#i-([\w-]+)"\s*/?>(?:</use>)?\s*</svg>#',
        fn($m) => isset($map[$m[1]]) ? icon_marker($map[$m[1]]) : '', $html);
}

/** The phone's icon $name in a line of text, drawn by wml_line() */
function icon_marker(string $name): string
{
    return "\u{E000}{$name}\u{E001}";
}

/** A line of text as WML, with the icons icon_markers() marked in it */
function wml_line(string $line): string
{
    return preg_replace_callback('/\x{E000}(\w+)\x{E001}/u', fn($m) => wap_icon($m[1]), e($line));
}

/** Plain lines of an HTML fragment: block ends and <br> break lines, list items start with "- " */
function html_lines(string $html): array
{
    $html = preg_replace('/\s+/u', ' ', $html);
    $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html);
    $html = preg_replace('#<br\s*/?>|</(p|div|ul|ol|li|h[1-6]|tr|blockquote|pre)>#i', "\n", $html);
    $html = preg_replace('#</t[dh]>#i', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = array_map(fn($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text));
    return array_values(array_filter($lines, fn($l) => $l !== '' && $l !== '-'));
}

/** A question in one short line, for lists: "The capital of In..." */
function question_brief(array $q, int $width = 18): string
{
    $text = preg_replace('/\{#(\d+)\}/', '[$1]', html_to_text($q['text'], '(picture)'));
    return mb_strimwidth($text !== '' ? $text : '(picture)', 0, $width, '..', 'UTF-8');
}
