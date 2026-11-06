<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Smart grading of short answers and essays, on top of Moodle-equivalent grading:
 *
 *  1. Spelling slips (exam setting "fuzzy"): Damerau-Levenshtein on grapheme clusters
 *     (works for any script), length-based limits, off for numbers and very short words.
 *     Differences only in spaces/punctuation count as correct without a penalty (symbols such as # + count).
 *  2. Local LLM (exam setting "llm": off | suggest | auto): answers still not right are
 *     marked "pending"; bin/grade_worker.php or the grading page asks the model in batches
 *     per question. Verdicts are cached per question text and answers + normalised answer.
 *       auto:    correct/alternative -> full marks, spelling -> minus penalty,
 *                wrong -> stays wrong, unsure/low confidence -> teacher
 *       suggest: every verdict goes to the teacher as a suggestion
 *  3. Teacher review: identical answers are grouped; one decision marks everyone.
 *     Essays get an LLM suggestion (mark + reason) when a reference answer exists.
 *
 * The LLM never lowers a mark that exact matching gave, and student answers are sent
 * as JSON data with instructions to ignore anything they say.
 *
 * Grade entries (attempt.grades[slot], or .blanks[i] inside multi-blank grades) may carry:
 *   by: auto|fuzzy|llm|teacher, note, llm: pending|done, suggestion: {verdict, confidence, fraction, reason}
 */

defined('EXAMS_APP') or exit;

/** Split into grapheme clusters (a letter with its vowel sign or accent stays one unit). */
function graphemes(string $s): array
{
    return preg_split('/(\X)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
}

/** Optimal string alignment distance (Damerau-Levenshtein with adjacent swaps) on graphemes. */
function spelling_distance(string $a, string $b, int $limit = PHP_INT_MAX): int
{
    $x = graphemes($a);
    $y = graphemes($b);
    $n = count($x);
    $m = count($y);
    if (abs($n - $m) > $limit) {
        return $limit + 1;
    }
    $prev2 = [];
    $prev = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
        $cur = [$i];
        $rowMin = $i;
        for ($j = 1; $j <= $m; $j++) {
            $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                $cur[$j] = min($cur[$j], $prev2[$j - 2] + 1);
            }
            $rowMin = min($rowMin, $cur[$j]);
        }
        if ($rowMin > $limit) {
            return $limit + 1;
        }
        $prev2 = $prev;
        $prev = $cur;
    }
    return $prev[$m];
}

/** Lower case without spaces and punctuation, symbols kept: "Ctrl + C." -> "ctrl+c", "C#" stays "c#". */
function compact_answer(string $s): string
{
    return lenient_answer_text(mb_strtolower(normalize_answer_text($s)), ['case' => true, 'spaces' => true, 'punctuation' => true]);
}

/** How many slips an answer of this length may have. */
function spelling_allowance(string $answer): int
{
    $len = count(graphemes(compact_answer($answer)));
    return match (true) {
        $len <= 4 => 0,
        $len <= 7 => 1,
        $len <= 12 => 2,
        default => 3,
    };
}

/**
 * Best accepted answer within spelling allowance, or null.
 * Returns ['answer' => text, 'fraction' => f, 'distance' => d].
 */
function spelling_match(string $response, array $answers): ?array
{
    $r = compact_answer($response);
    if ($r === '' || preg_match('/^\d+$/', $r)) {
        return null;
    }
    $best = null;
    foreach ($answers as $a) {
        if (str_contains($a['text'], '*') || (float)$a['fraction'] <= 0) {
            continue;
        }
        $target = compact_answer($a['text']);
        if ($target === '' || preg_match('/^\d+$/', $target)) {
            continue;
        }
        $allow = $r === $target ? 0 : spelling_allowance($a['text']);
        $d = $r === $target ? 0 : spelling_distance($r, $target, $allow);
        if ($d <= $allow && ($best === null || [(float)$a['fraction'], -$d] > [$best['fraction'], -$best['distance']])) {
            $best = ['answer' => $a['text'], 'fraction' => (float)$a['fraction'], 'distance' => $d];
        }
    }
    return $best;
}

/** Key used to group identical answers and cache verdicts. */
function answer_group_key(string $response): string
{
    return mb_strtolower(normalize_answer_text($response));
}

function llm_enabled(array $exam): bool
{
    return ($exam['grading']['llm'] ?? 'off') !== 'off' && trim((string)cfg('LLM_URL', '')) !== '';
}

/**
 * Improve one short-answer grade with spelling tolerance and LLM state.
 * $q: the short-answer question (or blank), $grade: from grade_response(). Returns the new grade.
 */
function smart_grade_short(array $exam, array $q, $response, array $grade, string $questionText = ''): array
{
    if ($q['type'] !== 'shortanswer' || !is_string($response) || trim($response) === '' || ($grade['fraction'] ?? 0) >= 1) {
        return $grade;
    }
    $grading = $exam['grading'] ?? [];
    if (!empty($grading['fuzzy'])) {
        $m = spelling_match($response, $q['answers']);
        if ($m) {
            $penalty = $m['distance'] > 0 ? (float)($grading['spelling_penalty'] ?? 0) : 0.0;
            $fraction = max(0.0, $m['fraction'] * (1 - $penalty));
            if ($fraction > ($grade['fraction'] ?? 0)) {
                return ['fraction' => $fraction, 'state' => 'graded', 'by' => 'fuzzy',
                    'note' => $m['distance'] > 0 ? "Spelling slip accepted (\"{$m['answer']}\")" : "Same as \"{$m['answer']}\" apart from spaces or punctuation"];
            }
        }
    }
    $mode = $grading['llm'] ?? 'off';
    if ($mode === 'off') {
        return $grade;
    }
    if (trim((string)cfg('LLM_URL', '')) === '') {
        return array_merge($grade, ['state' => 'needs_review', 'note' => 'AI checking is not set up: please check']);
    }
    $cached = llm_cache_get($q, $response);
    if ($cached) {
        return apply_llm_verdict($exam, $grade, $cached);
    }
    return array_merge($grade, ['llm' => 'pending']);
}

/** Turn a model verdict into a grade according to the exam's LLM mode. */
function apply_llm_verdict(array $exam, array $grade, array $verdict): array
{
    $mode = $exam['grading']['llm'] ?? 'off';
    $penalty = (float)($exam['grading']['spelling_penalty'] ?? 0);
    $v = $verdict['verdict'] ?? 'unsure';
    $confidence = (float)($verdict['confidence'] ?? 0);
    $fraction = match ($v) {
        'correct', 'alternative' => 1.0,
        'spelling' => max(0.0, 1 - $penalty),
        'wrong' => (float)($grade['fraction'] ?? 0),
        default => null,
    };
    $suggestion = ['verdict' => $v, 'confidence' => $confidence, 'fraction' => $fraction, 'reason' => (string)($verdict['reason'] ?? '')];
    unset($grade['llm']);
    if ($mode === 'auto' && $fraction !== null && $confidence >= (float)cfg('LLM_MIN_CONFIDENCE', 0.7)) {
        $new = max((float)($grade['fraction'] ?? 0), $fraction);
        return ['fraction' => $new, 'state' => 'graded', 'by' => $new > (float)($grade['fraction'] ?? 0) ? 'llm' : ($grade['by'] ?? 'auto'),
            'note' => $v === 'wrong' ? 'AI agrees it is wrong' : 'AI accepted (' . $v . ')', 'suggestion' => $suggestion];
    }
    return array_merge($grade, ['state' => 'needs_review', 'suggestion' => $suggestion]);
}

/** Apply smart grading to a whole graded attempt (short answers, blanks, essays). */
function smart_grade_attempt(array $exam, array $paper, array $attempt): array
{
    foreach ($attempt['slots'] as $slot) {
        $s = $slot['slot'];
        $q = $paper['questions'][$slot['key']];
        $grade = $attempt['grades'][$s] ?? null;
        if (!$grade || ($grade['by'] ?? '') === 'teacher') {
            continue;
        }
        $response = $attempt['answers'][$s] ?? null;
        if ($q['type'] === 'shortanswer') {
            $attempt['grades'][$s] = smart_grade_short($exam, $q, $response, $grade);
        } elseif ($q['type'] === 'multiblank' && is_array($response)) {
            $parts = $grade['blanks'] ?? [];
            $info = $grade['blank_info'] ?? [];
            foreach ($q['blanks'] as $i => $blank) {
                if ($blank['type'] !== 'shortanswer' || ($info[$i]['by'] ?? '') === 'teacher') {
                    continue;
                }
                $blankQ = $blank + ['id' => $q['id'] ?? null];   // same cache file as the question
                $bg = smart_grade_short($exam, $blankQ, $response[$i] ?? null, ['fraction' => $parts[$i] ?? 0.0, 'state' => 'graded'] + ($info[$i] ?? []));
                $parts[$i] = $bg['fraction'] ?? 0.0;
                $info[$i] = $bg;
            }
            $attempt['grades'][$s]['blanks'] = $parts;
            $attempt['grades'][$s]['blank_info'] = $info;
            $attempt['grades'][$s]['fraction'] = $parts ? array_sum($parts) / count($q['blanks']) : 0.0;
            $states = array_column($info, 'state');
            if (in_array('needs_review', $states, true)) {
                $attempt['grades'][$s]['state'] = 'needs_review';
            }
            if (in_array('pending', array_column($info, 'llm'), true)) {
                $attempt['grades'][$s]['llm'] = 'pending';
            }
        } elseif ($q['type'] === 'essay' && $grade['state'] === 'needs_review' && llm_enabled($exam) && trim($q['grader_info'] ?? '') !== '' && empty($grade['suggestion'])) {
            $attempt['grades'][$s]['llm'] = 'pending';
        }
    }
    return recompute_attempt_score($attempt);
}

/**
 * Score and status from grades (graded only when nothing waits for the teacher or the AI).
 * In a section with "answer any five" only the best five count; an extra answer is marked
 * 'counted' => false and adds nothing (lib/grading.php counted_slots()).
 */
function recompute_attempt_score(array $attempt): array
{
    $waiting = false;
    foreach ($attempt['slots'] as $slot) {
        $g = $attempt['grades'][$slot['slot']] ?? null;
        if (!$g) {
            continue;
        }
        if ($g['state'] === 'needs_review' || ($g['llm'] ?? '') === 'pending') {
            $waiting = true;
        }
        $g['mark'] = $g['fraction'] === null ? null : round((float)$g['fraction'] * (float)$slot['mark'], 4);
        unset($g['counted']);   // worked out again below, from the marks just set
        $attempt['grades'][$slot['slot']] = $g;
    }
    $counted = counted_slots($attempt);
    $score = 0.0;
    foreach ($attempt['slots'] as $slot) {
        $g = $attempt['grades'][$slot['slot']] ?? null;
        if (!$g) {
            continue;
        }
        $pending = $g['state'] === 'needs_review' || ($g['llm'] ?? '') === 'pending';
        if ($counted[$slot['slot']] ?? true) {
            if (!$pending && $g['mark'] !== null) {
                $score += $g['mark'];
            }
        } elseif (!$pending) {
            // An answer that did not count. One still being marked is not called extra yet:
            // when its mark arrives the best N are picked afresh.
            $attempt['grades'][$slot['slot']]['counted'] = false;
        }
    }
    $attempt['score'] = round($score, 4);
    $attempt['status'] = $waiting ? 'submitted' : 'graded';
    return $attempt;
}

// ---------------------------------------------------------------- Cache

function llm_cache_file(array $q): string
{
    $id = preg_replace('/[^a-z0-9]/', '', (string)($q['id'] ?? 'q'));
    return data_path('grading', 'cache', ($id ?: 'q') . '.json');
}

function llm_cache_key(array $q, string $response): string
{
    // Text, answers and the case setting: an edited question does not reuse old verdicts
    return crc32(json_encode([$q['text'] ?? '', $q['answers'] ?? [], !empty($q['usecase'])], JSON_UNESCAPED_UNICODE)) . '|' . answer_group_key($response);
}

function llm_cache_get(array $q, string $response): ?array
{
    return read_json(llm_cache_file($q), [])[llm_cache_key($q, $response)] ?? null;
}

function llm_cache_put(array $q, string $response, array $verdict): void
{
    update_json(llm_cache_file($q), function ($all) use ($q, $response, $verdict) {
        $all[llm_cache_key($q, $response)] = $verdict + ['answer' => normalize_answer_text($response), 'at' => now_iso(), 'model' => cfg('LLM_MODEL')];
        return $all;
    });
}
