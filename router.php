<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Router for PHP's built-in server, which ignores .htaccess:
 *   php -S 0.0.0.0:8080 router.php
 * Blocks private folders (data, lib, tests, tools, bin) and config files.
 */

define('EXAMS_ROUTER', true);

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#(^|/)(data|lib|tests|tools|bin)(/|$)#', $path) || preg_match('#(^|/)(config(\.local)?\.php|router\.php|\.[^/]*)$#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
return false;
