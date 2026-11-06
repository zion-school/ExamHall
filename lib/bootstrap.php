<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Loads settings and shared helpers. Every page, API endpoint and CLI script
 * starts with: require_once __DIR__ . '/../lib/bootstrap.php';
 */

define('EXAMS_APP', true);
define('APP_DIR', dirname(__DIR__));

require_once APP_DIR . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/ids.php';
require_once __DIR__ . '/subjects.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/gift/parser.php';
require_once __DIR__ . '/gift/writer.php';
require_once __DIR__ . '/gift/lint.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/questions.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/exams.php';
require_once __DIR__ . '/paper.php';
require_once __DIR__ . '/attempts.php';
require_once __DIR__ . '/player.php';
require_once __DIR__ . '/smart_grading.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/expr.php';
require_once __DIR__ . '/rewards.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/sheets.php';

/** Read a setting from config.php / config.local.php. */
function cfg(string $name, $default = null)
{
    return $GLOBALS[$name] ?? $default;
}

/** Escape text for HTML output. */
function h($text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Plain text of an HTML fragment: tags removed, entities like &lt; &amp; &nbsp; decoded,
 * white space collapsed. Pictures (<img>, <svg>) become $picture.
 * The result is text, not HTML: escape it with h() before printing.
 */
function html_to_text(string $html, string $picture = ' '): string
{
    $html = preg_replace(['#<svg\b.*?</svg>#is', '#<img\b[^>]*>#i'], $picture === ' ' ? ' ' : " $picture ", $html);
    $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th)\b[^>]*>#i', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
}

/**
 * Plain text of an answer for results, keys and practice checks: its text, or else the alt text of its
 * pictures (<img alt="Text tool">), or else "(picture)". Escape it with h() before printing.
 */
function answer_label(string $html): string
{
    // A picture between words leaves a space before punctuation: "Google <img> , Bing" -> "Google, Bing"
    $text = preg_replace('/\s+([,.;:!?])/u', '$1', html_to_text($html));
    if ($text !== '') {
        return $text;
    }
    preg_match_all('#<img\b[^>]*?\balt\s*=\s*(["\'])(.*?)\1#is', $html, $m);
    $alts = array_filter(array_map(fn($a) => html_to_text(html_entity_decode($a, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $m[2]), 'strlen');
    return $alts ? implode(', ', $alts) : '(picture)';
}

/** Current time as an ISO-8601 string in the configured timezone. */
function now_iso(): string
{
    return date('c');
}

/** Send a JSON response and stop. */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Read a JSON request body (for fetch() calls). */
function json_request(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

/** Redirect to an app-relative path and stop. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/**
 * URL for an app-relative path (e.g. 'assets/css/app.css').
 * Works whether the app is served from / or from a sub-folder like /Exams.
 */
function url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $base = '';
        $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
        $app = realpath(APP_DIR) ?: APP_DIR;
        $name = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($script !== '' && str_starts_with($script, $app)) {
            $rel = substr($script, strlen($app));          // e.g. /teacher/bank.php
            if ($rel !== '' && str_ends_with($name, $rel)) {
                $base = substr($name, 0, -strlen($rel));   // e.g. /Exams
            }
        }
    }
    return $base . '/' . ltrim($path, '/');
}
