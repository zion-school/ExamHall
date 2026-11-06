<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * My results: finished exams by class and school year, then subject, with
 * the score of each (student_results(), as on the web's student/results.php).
 */
require_once __DIR__ . '/lib/wap.php';

$user = wap_student();
[$by_year, $coins] = student_results($user);

// One row per exam, with the class/year and subject headings it comes under
$rows = [];
foreach ($by_year as $group => $by_subject) {
    [$year, $class] = explode('|', $group, 2);
    foreach ($by_subject as $subject => $entries) {
        foreach ($entries as $entry) {
            $rows[] = [($class !== '' ? "Class {$class} " : '') . $year, $subject, $entry];
        }
    }
}
[$page, $pages] = page_number(count($rows));

wml_start('My results', '', soft_keys([['Home', go_to('index.php')]]));
if ($page === 1) {
    echo e(count($rows) . ' exam(s) finished') . "<br/>\n";
    if ($coins > 0) {
        echo wap_icon('coins') . e(wap_money($coins) . ' earned in Zion Bank') . "<br/>\n";
    }
}
$heading = null;
foreach (page_slice($rows, $page) as [$year, $subject, $e]) {
    if ($heading !== "{$year}|{$subject}") {
        echo '<b>' . e($year) . '</b><br/>' . e($subject) . "<br/>\n";
        $heading = "{$year}|{$subject}";
    }
    $r = $e['result'];
    $score = !$r || $r['pending'] ? 'being marked' : round($r['percent']) . '%';
    echo link_to($e['exam']['title'] . ': ' . $score, wap_url('result.php', ['id' => $e['exam']['id'], 'n' => $r['attempt'] ?? $e['last']['n']])) . "\n";
}
echo pager('results.php', [], $page, $pages);
wml_end();
