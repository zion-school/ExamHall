<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * CSRF protection for forms and fetch() calls.
 */

defined('EXAMS_APP') or exit;

function csrf_token(): string
{
    start_app_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_secret();
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Stop with 403 unless the request carries the session's CSRF token (form field or X-CSRF header). */
function csrf_check(): void
{
    start_app_session();
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_response(['ok' => false, 'error' => 'csrf'], 403);
        }
        http_response_code(403);
        exit('This page has expired. Please go back, reload and try again.');
    }
}
