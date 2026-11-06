<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Benchmark the local LLM on real short answers before trusting it.
 *
 * Uses answers from exams migrated from Moodle (Moodle's marks are exact-match, so
 * "wrong" there also contains spelling slips) plus an optional teacher-labelled file.
 *
 *   php bin/llm_bench.php [--limit 200] [--labels labels.csv] [--out review.csv]
 *                         [--url http://host:8080/v1/chat/completions] [--model name]
 *
 * labels.csv / review.csv columns: question,accepted,answer,moodle,ai_verdict,ai_confidence,label
 *   label: right | wrong  (fill in review.csv, then pass it back with --labels)
 *
 * Key number: "wrong answers accepted" - it should be close to 0 before using auto mode.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';

$opts = getopt('', ['limit:', 'labels:', 'out:', 'url:', 'model:', 'seed:']);
if (isset($opts['url'])) {
    $GLOBALS['LLM_URL'] = $opts['url'];
}
if (isset($opts['model'])) {
    $GLOBALS['LLM_MODEL'] = $opts['model'];
}
if (trim((string)cfg('LLM_URL', '')) === '') {
    fwrite(STDERR, "Set \$LLM_URL in config.local.php or pass --url.\n");
    exit(1);
}
$limit = (int)($opts['limit'] ?? 200);
mt_srand((int)($opts['seed'] ?? 7));

// Teacher labels (question text + answer -> right/wrong)
$labels = [];
foreach (isset($opts['labels']) ? read_csv($opts['labels']) : [] as $row) {
    if (in_array($row['label'] ?? '', ['right', 'wrong'], true)) {
        $labels[$row['question'] . '|' . answer_group_key($row['answer'])] = $row['label'];
    }
}

// Collect distinct (question, answer) pairs from migrated attempts, skipping exact matches
$items = [];
foreach (all_exams() as $exam) {
    $paper = load_paper($exam['id']);
    foreach (all_attempts($exam['id']) as $attempts) {
        foreach ($attempts as $a) {
            if (!attempt_has_moodle_marks($a)) {
                continue;
            }
            foreach ($a['slots'] as $slot) {
                $q = $paper['questions'][$slot['key']];
                $answer = $a['answers'][$slot['slot']] ?? null;
                $g = $a['grades'][$slot['slot']] ?? null;
                if ($q['type'] !== 'shortanswer' || !is_string($answer) || trim($answer) === '' || !$g || $g['fraction'] === null) {
                    continue;
                }
                if (grade_response($q, $answer)['fraction'] >= 1) {
                    continue;   // exact matches never reach the model
                }
                $text = plain_text($q['text']);
                $key = $text . '|' . answer_group_key($answer);
                $items[$key] ??= ['q' => $q, 'text' => $text, 'answer' => normalize_answer_text($answer),
                    'moodle' => (float)$g['fraction'] >= 1 ? 'right' : 'wrong', 'label' => $labels[$key] ?? null];
            }
        }
    }
}
if (!$items) {
    fwrite(STDERR, "No migrated short answers found. Run tools/moodle_migrate.py first (or pass --labels).\n");
    exit(1);
}
$keys = array_keys($items);
shuffle($keys);
$keys = array_slice($keys, 0, $limit);

// Batch per question like the real grader
$byQuestion = [];
foreach ($keys as $key) {
    $byQuestion[$items[$key]['text']][] = $key;
}
printf("Checking %d answers to %d questions with %s at %s\n", count($keys), count($byQuestion), cfg('LLM_MODEL'), cfg('LLM_URL'));
$batchSize = max(1, (int)cfg('LLM_BATCH_SIZE', 8));
$seconds = [];
$errors = 0;
foreach ($byQuestion as $text => $group) {
    foreach (array_chunk($group, $batchSize) as $chunk) {
        $responses = [];
        foreach ($chunk as $key) {
            $responses[$key] = $items[$key]['answer'];
        }
        $verdicts = llm_judge_short_answers($text, $items[$chunk[0]]['q'], $responses);
        if (isset($verdicts['_error'])) {
            $errors++;
            fwrite(STDERR, "  error: {$verdicts['_error']}\n");
            continue;
        }
        $seconds[] = $verdicts['_seconds'];
        foreach ($chunk as $key) {
            $items[$key]['ai'] = $verdicts[$key] ?? ['verdict' => 'unsure', 'confidence' => 0.0];
        }
        printf("  %2d answers in %5.1f s\n", count($chunk), $verdicts['_seconds']);
    }
}

// Scores
$minConf = (float)cfg('LLM_MIN_CONFIDENCE', 0.7);
$stats = ['right' => ['n' => 0, 'accepted' => 0], 'wrong' => ['n' => 0, 'accepted' => 0], 'unsure' => 0];
$rows = [];
foreach ($keys as $key) {
    $it = $items[$key];
    if (!isset($it['ai'])) {
        continue;
    }
    $truth = $it['label'] ?? null;
    $accepted = in_array($it['ai']['verdict'], ['correct', 'spelling', 'alternative'], true) && $it['ai']['confidence'] >= $minConf;
    if ($it['ai']['verdict'] === 'unsure' || $it['ai']['confidence'] < $minConf) {
        $stats['unsure']++;
    }
    if ($truth) {
        $stats[$truth]['n']++;
        $stats[$truth]['accepted'] += $accepted ? 1 : 0;
    }
    $rows[] = [
        'question' => $it['text'], 'accepted' => implode(' / ', array_column(array_filter($it['q']['answers'], fn($a) => $a['fraction'] > 0), 'text')),
        'answer' => $it['answer'], 'moodle' => $it['moodle'], 'ai_verdict' => $it['ai']['verdict'],
        'ai_confidence' => round($it['ai']['confidence'], 2), 'label' => $truth ?? '',
    ];
}
$out = $opts['out'] ?? 'llm_bench_review.csv';
write_csv($out, ['question', 'accepted', 'answer', 'moodle', 'ai_verdict', 'ai_confidence', 'label'], $rows);

$total = array_sum($seconds);
printf("\nModel: %s\n", cfg('LLM_MODEL'));
printf("Speed: %d batches, %.1f s average, %.1f answers per minute%s\n", count($seconds), $seconds ? $total / count($seconds) : 0, $total > 0 ? 60 * count($rows) / $total : 0, $errors ? ", $errors failed" : '');
printf("Sent to teacher (unsure or confidence < %.2f): %d of %d\n", $minConf, $stats['unsure'], count($rows));
if ($stats['right']['n'] || $stats['wrong']['n']) {
    printf("Right answers accepted:  %d of %d\n", $stats['right']['accepted'], $stats['right']['n']);
    printf("WRONG answers accepted:  %d of %d  <- should be about 0 before using auto mode\n", $stats['wrong']['accepted'], $stats['wrong']['n']);
} else {
    echo "No teacher labels yet: fill the 'label' column (right/wrong) in $out and run again with --labels $out\n";
}
echo "Review sheet: $out\n";
