<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * Student home: exams to do now, locked ones, coming up and finished, as on
 * the web's student/index.php (student_home_exams()), one line each. Options: My results, Logout.
 */
require_once __DIR__ . '/lib/wap.php';

$user = wap_student();

$groups = student_home_exams($user);

/** What a student can do with an exam, after its title */
function state_text(array $exam, array $status, ?array $result): string
{
    switch ($status['state']) {
        case 'in_progress':
            $left = attempt_seconds_left($status['in_progress']);
            return 'go on' . ($left !== null ? ', ' . left_text($left) . ' left' : '');
        case 'open':
            return 'ready' . ((int)$exam['time_limit_min'] > 0 ? ', ' . (int)$exam['time_limit_min'] . ' min' : '');
        case 'locked':
            return 'ask your teacher';
        case 'upcoming':
            return 'opens ' . when_text($status['open']);
    }
    if ($result !== null) {
        return $result['pending'] ? 'being marked' : round($result['percent']) . '%';
    }
    return 'closed';
}

$rows = [];
foreach ($groups as $group => $entries) {
    foreach ($entries as $entry) {
        $rows[] = [$group, $entry];
    }
}
$headings = ['now' => 'To do', 'locked' => 'Locked', 'upcoming' => 'Coming up', 'done' => 'Done'];
$heading_icons = ['now' => 'pencil', 'locked' => 'lock', 'upcoming' => 'cal', 'done' => 'tick'];
[$page, $pages] = page_number(count($rows));

wml_start('My exams', '', soft_keys([['My results', go_to('results.php')], ['Logout', go_to('logout.php')]]));
if ($page === 1) {
    echo 'Hi ' . e($user['name']) . "<br/>\n";
    if (!$groups['now']) {
        echo "Nothing to do now<br/>\n";
    }
}
$heading = null;
foreach (page_slice($rows, $page) as [$group, ['exam' => $exam, 'status' => $status, 'result' => $result]]) {
    if ($group !== $heading) {
        echo wap_icon($heading_icons[$group]) . '<b>' . e($headings[$group]) . "</b><br/>\n";
        $heading = $group;
    }
    echo link_to($exam['title'] . ': ' . state_text($exam, $status, $result), wap_url('exam.php', ['id' => $exam['id']])) . "\n";
}
echo pager('index.php', [], $page, $pages);
wml_end();
