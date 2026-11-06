<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Check the data folder and repair what is safe to repair.
 *   php bin/fsck.php          report only
 *   php bin/fsck.php --fix    also: rebuild the question index, remove old temp files,
 *                             submit attempts whose time ran out, check stuck payments
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';

$fix = in_array('--fix', $argv, true);
$problems = 0;
$fixed = 0;
$say = function (string $level, string $message) use (&$problems) {
    if ($level === 'problem') {
        $problems++;
    }
    echo ($level === 'problem' ? '  ✗ ' : ($level === 'fixed' ? '  ✓ ' : '    ')) . $message . "\n";
};

$root = cfg('DATA_DIR');
echo "Data folder: $root\n";

// 1. Every JSON file parses; old temp files
echo "\nFiles\n";
$json = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = $file->getPathname();
    if (str_contains($path, '/.locks/') || str_contains($path, '/sessions/')) {
        continue;
    }
    if (preg_match('#/\.[^/]+\.tmp\d+_\d+$#', $path)) {
        if ($file->getMTime() < time() - 3600) {
            if ($fix) {
                unlink($path);
                $fixed++;
                $say('fixed', "Removed leftover temp file " . substr($path, strlen($root) + 1));
            } else {
                $say('problem', "Leftover temp file " . substr($path, strlen($root) + 1));
            }
        }
        continue;
    }
    if (str_ends_with($path, '.json')) {
        $json++;
        json_decode((string)file_get_contents($path));
        if (json_last_error() !== JSON_ERROR_NONE) {
            $say('problem', 'Corrupt JSON: ' . substr($path, strlen($root) + 1) . ' (' . json_last_error_msg() . ')');
        }
    }
}
$say('info', "$json JSON files checked");

// 2. Question bank: the .gift files are the truth; index.csv and .state.json are rebuilt from them
echo "\nQuestion bank\n";
$index = is_file(bank_index_file()) ? bank_read_index() : [];
$fromFiles = [];
$scan = bank_scan();
foreach (array_keys($scan) as $category) {
    foreach (bank_keyed(bank_parse(bank_file_content($category), $category)['questions'], $category) as $q) {
        $fromFiles[$q['key']] ??= bank_index_row($q, $category);
    }
}
$issues = [];
foreach ($fromFiles as $key => $row) {
    if (!isset($index[$key])) {
        $issues[] = $row['category'] . BANK_EXT . ': "' . mb_substr($row['name'], 0, 60) . '" is not in the index';
    } elseif ($index[$key] != $row) {
        $issues[] = $row['category'] . BANK_EXT . ': "' . mb_substr($row['name'], 0, 60) . '" has changed since it was indexed';
    }
}
foreach (array_diff_key($index, $fromFiles) as $row) {
    $issues[] = "Index lists \"" . mb_substr($row['name'], 0, 60) . "\" in {$row['category']} but no .gift file has it";
}
foreach (array_slice($issues, 0, 20) as $issue) {
    $say($fix ? 'info' : 'problem', $issue);
}
if (count($issues) > 20) {
    $say($fix ? 'info' : 'problem', (count($issues) - 20) . ' more like these');
}
if ($issues && $fix) {
    bank_sync(true);
    $fixed++;
    $say('fixed', 'Rebuilt the question index (' . count(bank_read_index()) . ' questions)');
}
foreach (bank_problems() as $category => $list) {
    foreach ($list as $p) {
        $say($p['level'] === 'error' ? 'problem' : 'info', $category . BANK_EXT . " line {$p['line']}: {$p['message']}");
    }
}
$say('info', count($fromFiles) . ' questions in ' . count($scan) . ' chapter files');

// 3. Exams, papers, attempts, rewards
echo "\nExams\n";
$missingStudent = $filledStudent = 0;
foreach (list_dirs(data_path('exams')) as $examId) {
    if (!is_exam_id($examId)) {
        $say('problem', "Unexpected folder exams/$examId");
        continue;
    }
    $exam = load_exam($examId);
    if (!$exam) {
        $say('problem', "exams/$examId has no exam.json");
        continue;
    }
    $paper = load_paper($examId);
    if ($exam['status'] !== 'draft' && !$paper) {
        $say('problem', "{$exam['title']}: published without a paper (publish it again)");
        continue;
    }
    foreach (all_attempts($examId) as $username => $attempts) {
        foreach ($attempts as $a) {
            if (empty($a['student']['class']) && empty($a['student']['name'])) {
                $missingStudent++;
                if ($fix) {
                    $snapshot = attempt_student_snapshot($exam, $a);
                    update_json(attempt_file($examId, $username, $a['n']), fn($x) => empty($x['student']) ? ['student' => $snapshot] + $x : false);
                    $filledStudent++;
                }
            }
            foreach ($a['slots'] as $slot) {
                if (!isset($paper['questions'][$slot['key']])) {
                    $say('problem', "{$exam['title']}: $username try {$a['n']} uses {$slot['key']}, which is not in the paper");
                    break;
                }
            }
            if (attempt_is_due($a)) {
                if ($fix) {
                    attempt_expire_if_due($exam, $a);
                    fire_hook('attempt.graded', $examId, $username);
                    $fixed++;
                    $say('fixed', "{$exam['title']}: submitted $username's try {$a['n']} (time was up)");
                } else {
                    $say('problem', "{$exam['title']}: $username's try {$a['n']} is past its time but not submitted");
                }
            }
        }
    }
    if (exam_is_reward($exam)) {
        $stuck = array_filter(load_rewards($examId), fn($r) => $r['status'] === 'unknown' || ($r['status'] === 'paying' && strtotime($r['paid_at'] ?: $r['created']) < time() - 600));
        if ($stuck) {
            if ($fix) {
                $n = rewards_reconcile($exam);
                $fixed += $n;
                $say('fixed', "{$exam['title']}: checked $n unclear payment(s) against the bank log");
            } else {
                $say('problem', "{$exam['title']}: " . count($stuck) . ' payment(s) with an unclear outcome');
            }
        }
    }
}

if ($missingStudent) {
    if ($fix) {
        $fixed += $filledStudent;
        $say('fixed', "Kept the student's name and class in $filledStudent older tries (so promotions do not change them)");
    } else {
        $say('problem', "$missingStudent older tries do not keep the student's name and class; run with --fix before students are promoted");
    }
}

// 4. Register of people (data/people.csv): who left school, usernames given to someone else
echo "\nPeople\n";
load_users();   // brings the register up to date with users.csv
$people = load_people();
$left = array_filter($people, fn($p) => $p['left_on'] !== '');
$say('info', count($people) . ' in the register, ' . count($left) . ' left school (their tries are kept)');
foreach ($people as $p) {
    if ($p['note'] !== '') {
        $say('info', "Check {$p['username']}: {$p['note']}. A different student? Give one of them a new username in Zion Bank; clear the note in data/people.csv once checked.");
    }
}

echo "\n" . ($problems ? "$problems problem(s)" : 'No problems') . ($fix ? ", $fixed repaired" : ($problems ? ' — run with --fix to repair what can be repaired' : '')) . "\n";
exit($problems && !$fix ? 1 : 0);
