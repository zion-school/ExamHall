<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Automatic grading that matches Moodle's question behaviour, plus seeded shuffling.
 *
 * Responses (as sent by the exam player):
 *   multichoice  single: answer index (int)        multi: [indices]
 *   truefalse    true | false
 *   shortanswer  "text"
 *   numerical    "text" (e.g. "1,45,000" or "3.14")
 *   match        {stem index: choice index}        choice index into match_choices($q)
 *   multiblank   {blank index: response of that blank's type}
 *   essay        "text"
 * A grade is ['fraction' => 0..1 or null, 'state' => graded|needs_review|unanswered].
 */

defined('EXAMS_APP') or exit;

/** Small deterministic PRNG (xorshift32) so a stored seed always gives the same order. */
final class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        return $this->state = $x & 0xFFFFFFFF;
    }

    /** Integer in [0, $max) */
    public function below(int $max): int
    {
        return $max <= 1 ? 0 : $this->next() % $max;
    }

    public function shuffle(array $items): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->below($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }
}

/** Choices like "All of the above" must stay last when shuffling. */
function is_pinned_choice(string $html): bool
{
    $t = mb_strtolower(html_to_text($html));
    return (bool)preg_match('/^(all|none|both|neither)\b.*\b(above|these|them|options|choices|mentioned)\b|^(both|neither)\b.+\b(and|nor)\b|^(all|none) of (the|these)\b|மேற்(கண்ட|க்கண்ட)|மேலே உள்ள|இவை அனைத்தும்|எதுவுமில்லை/u', $t);
}

/** Order of answer indices for a multiple-choice question (pinned choices keep their place at the end). */
function shuffled_choice_order(array $answers, SeededRandom $rng, bool $shuffle): array
{
    $indices = array_keys($answers);
    if (!$shuffle) {
        return $indices;
    }
    $free = array_values(array_filter($indices, fn($i) => !is_pinned_choice($answers[$i]['text'])));
    $pinned = array_values(array_filter($indices, fn($i) => is_pinned_choice($answers[$i]['text'])));
    return array_merge($rng->shuffle($free), $pinned);
}

/** Distinct right-hand answers of a matching question (including extra wrong ones), in stored order. */
function match_choices(array $q): array
{
    $choices = [];
    foreach ($q['pairs'] as $p) {
        if (!in_array($p['answer'], $choices, true)) {
            $choices[] = $p['answer'];
        }
    }
    return $choices;
}

/** Indices of pairs that are real stems (not extra wrong answers). */
function match_stems(array $q): array
{
    return array_keys(array_filter($q['pairs'], fn($p) => trim($p['question']) !== ''));
}

/** Per-attempt layout of one question: choice/stem orders used to display it. */
function question_layout(array $q, SeededRandom $rng, bool $shuffleChoices): array
{
    $shuffle = $shuffleChoices && ($q['shuffle'] ?? true);
    switch ($q['type']) {
        case 'multichoice':
            return ['choices' => shuffled_choice_order($q['answers'], $rng, $shuffle)];
        case 'match':
            $stems = match_stems($q);
            $choices = array_keys(match_choices($q));
            return [
                'stems' => $shuffle ? $rng->shuffle($stems) : $stems,
                'choices' => $shuffle ? $rng->shuffle($choices) : $choices,
            ];
        case 'multiblank':
            $blanks = [];
            foreach ($q['blanks'] as $i => $blank) {
                if ($blank['type'] === 'multichoice') {
                    $blanks[$i] = ['choices' => shuffled_choice_order($blank['answers'], $rng, $shuffleChoices && ($blank['shuffle'] ?? true))];
                }
            }
            return $blanks ? ['blanks' => $blanks] : [];
    }
    return [];
}

/** Moodle-style text normalisation for short answers: trim, Unicode NFKC, single spaces. */
function normalize_answer_text(string $s): string
{
    $s = trim($s);
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_KC) ?: $s;
    }
    return preg_replace('/\s+/u', ' ', $s);
}

/** Moodle's matching: capitals ignored (unless the question says "case"), spaces and punctuation count. */
const MOODLE_LENIENCY = ['case' => true, 'spaces' => false, 'punctuation' => false];

/** What an exam forgives in short answers and blanks (exam settings "Ignore capitalization/spacing/punctuation"). */
function answer_leniency(?array $exam): array
{
    if (!$exam) {
        return MOODLE_LENIENCY;
    }
    $g = $exam['grading'] ?? [];
    return ['case' => !empty($g['ignore_case']), 'spaces' => !empty($g['ignore_spaces']), 'punctuation' => !empty($g['ignore_punctuation'])];
}

/**
 * Leave out what the exam forgives. Punctuation means sentence marks (. , ; : ! ? quotes
 * hyphens dashes brackets); symbols such as # + = * / stay (C# is not C), and so do a dot or
 * comma between digits (3.5 is not 35) and a minus sign before a number.
 */
function lenient_answer_text(string $s, array $lenient): string
{
    if ($lenient['punctuation']) {
        $s = preg_replace("/['`\u{2018}\u{2019}]/u", '', $s);   // don't = dont
        $s = preg_replace('/(?<=\d)[.,](?=\d)(*SKIP)(*FAIL)|(?<!\p{L})-(?=\d)(*SKIP)(*FAIL)|[.,;:!?"()\[\]{}\-\x{2010}-\x{2015}\x{201C}\x{201D}\x{2026}]/u', ' ', $s);
    }
    return $lenient['spaces'] ? preg_replace('/\s+/u', '', $s) : preg_replace('/\s+/u', ' ', $s);
}

/**
 * Short-answer match like Moodle: a run of * matches anything, \* is a star itself (=B2\*C2).
 * $lenient: what else to forgive (answer_leniency()); a question that says "case" always needs the right capitals.
 */
function short_answer_matches(string $response, string $answer, bool $usecase, array $lenient = MOODLE_LENIENCY): bool
{
    $bits = array_map(fn($bit) => preg_quote(lenient_answer_text(str_replace('\\*', '*', $bit), $lenient), '/'), preg_split('/(?<!\\\\)\*+/', normalize_answer_text($answer)));
    $pattern = '/^' . preg_replace('/^ | $/', '', implode('.*', $bits)) . '$/su' . (!$usecase && $lenient['case'] ? 'i' : '');
    return (bool)preg_match($pattern, trim(lenient_answer_text(normalize_answer_text($response), $lenient)));
}

/** A short answer as people read it: \* written as *. */
function short_answer_text(string $answer): string
{
    return str_replace('\\*', '*', $answer);
}

/** Parse a typed number: thousands separators (1,000 and Indian 1,00,000) and spaces are ignored. */
function parse_number_response(string $s): ?float
{
    $s = trim(str_replace(["\u{2212}", "\u{00A0}", ' '], ['-', '', ''], $s));
    if (preg_match('/^[+-]?\d{1,3}(,\d{2,3})+(\.\d+)?$/', $s)) {
        $s = str_replace(',', '', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

function grade_is_blank($response): bool
{
    return $response === null || $response === '' || $response === [] || (is_string($response) && trim($response) === '');
}

/**
 * Which slots of an attempt count towards its score: [slot => true|false].
 *
 * A section with "answer any N" (attempt['choose'][section]) keeps the student's best N answers
 * and the rest are extras that add nothing. An answer still waiting for a teacher or the AI comes
 * first, so a pending answer is never pushed out by one that is already marked; when it is marked,
 * the attempt is scored again and the best N are picked afresh.
 */
function counted_slots(array $attempt): array
{
    $counted = [];
    $bySection = [];
    foreach ($attempt['slots'] as $i => $slot) {
        $counted[$slot['slot']] = true;
        $bySection[(int)($slot['section'] ?? 0)][] = $slot + ['_at' => $i];
    }
    $grades = (array)($attempt['grades'] ?? []);
    $answers = (array)($attempt['answers'] ?? []);
    foreach ((array)($attempt['choose'] ?? []) as $section => $choose) {
        $slots = $bySection[(int)$section] ?? [];
        $choose = (int)$choose;
        if ($choose < 1 || $choose >= count($slots)) {
            continue;
        }
        $rank = [];
        foreach ($slots as $slot) {
            $g = (array)($grades[$slot['slot']] ?? []);
            $pending = ($g['state'] ?? '') === 'needs_review' || ($g['llm'] ?? '') === 'pending';
            $rank[] = [
                $pending ? 1 : 0,
                $pending ? 0.0 : (float)($g['mark'] ?? 0),
                grade_is_blank($answers[$slot['slot']] ?? null) ? 0 : 1,
                -$slot['_at'],
                $slot['slot'],
            ];
        }
        usort($rank, fn($a, $b) => array_slice($b, 0, 4) <=> array_slice($a, 0, 4));
        foreach (array_slice($rank, $choose) as $out) {
            if ($out[2]) {
                $counted[$out[4]] = false;   // only an answer can be an extra; a question left blank gives 0 anyway
            }
        }
    }
    return $counted;
}

/** Grade one response. $lenient: what short answers may differ in (answer_leniency($exam)). */
function grade_response(array $q, $response, array $lenient = MOODLE_LENIENCY): array
{
    $unanswered = ['fraction' => 0.0, 'state' => 'unanswered'];
    switch ($q['type']) {
        case 'description':
            return ['fraction' => null, 'state' => 'graded'];

        case 'essay':
            return grade_is_blank($response) ? $unanswered : ['fraction' => null, 'state' => 'needs_review'];

        case 'multichoice':
            if (!empty($q['single'])) {
                if (!is_int($response) && !(is_string($response) && ctype_digit($response))) {
                    return $unanswered;
                }
                $a = $q['answers'][(int)$response] ?? null;
                return $a === null ? $unanswered : ['fraction' => max(0.0, min(1.0, (float)$a['fraction'])), 'state' => 'graded'];
            }
            if (!is_array($response) || !$response) {
                return $unanswered;
            }
            $sum = 0.0;
            foreach (array_unique(array_map('intval', $response)) as $i) {
                $sum += (float)($q['answers'][$i]['fraction'] ?? 0);
            }
            return ['fraction' => max(0.0, min(1.0, $sum)), 'state' => 'graded'];

        case 'truefalse':
            if (!is_bool($response)) {
                return $unanswered;
            }
            return ['fraction' => $response === (bool)$q['correct'] ? 1.0 : 0.0, 'state' => 'graded'];

        case 'shortanswer':
            if (!is_string($response) || trim($response) === '') {
                return $unanswered;
            }
            foreach ($q['answers'] as $a) {
                if (short_answer_matches($response, $a['text'], !empty($q['usecase']), $lenient)) {
                    $g = ['fraction' => max(0.0, (float)$a['fraction']), 'state' => 'graded', 'matched' => $a['text']];
                    if (!short_answer_matches($response, $a['text'], !empty($q['usecase']))) {
                        $only = fn($what) => short_answer_matches($response, $a['text'], !empty($q['usecase']), [$what => true] + MOODLE_LENIENCY);
                        $g['note'] = 'Same as "' . short_answer_text($a['text']) . '" apart from ' . ($only('spaces') ? 'spacing' : ($only('punctuation') ? 'punctuation' : 'spacing and punctuation'));
                    }
                    return $g;
                }
            }
            return ['fraction' => 0.0, 'state' => 'graded'];

        case 'numerical':
            if (!is_string($response) && !is_int($response) && !is_float($response)) {
                return $unanswered;
            }
            if (trim((string)$response) === '') {
                return $unanswered;
            }
            $value = parse_number_response((string)$response);
            foreach ($q['answers'] as $a) {
                if ($a['value'] === '*') {
                    return ['fraction' => max(0.0, (float)$a['fraction']), 'state' => 'graded'];
                }
                if ($value !== null && abs($value - (float)$a['value']) <= (float)($a['tolerance'] ?? 0) + 1e-9 * max(1.0, abs((float)$a['value']))) {
                    return ['fraction' => max(0.0, (float)$a['fraction']), 'state' => 'graded'];
                }
            }
            return ['fraction' => 0.0, 'state' => 'graded'];

        case 'match':
            if (!is_array($response) || !$response) {
                return $unanswered;
            }
            $stems = match_stems($q);
            $choices = match_choices($q);
            $right = 0;
            foreach ($stems as $stem) {
                $chosen = $response[$stem] ?? $response[(string)$stem] ?? null;
                if ($chosen !== null && ($choices[(int)$chosen] ?? null) === $q['pairs'][$stem]['answer']) {
                    $right++;
                }
            }
            return ['fraction' => $stems ? $right / (float)count($stems) : 0.0, 'state' => 'graded'];

        case 'multiblank':
            if (!is_array($response) || !array_filter($response, fn($r) => !grade_is_blank($r))) {
                return $unanswered;
            }
            $total = 0.0;
            $parts = [];
            foreach ($q['blanks'] as $i => $blank) {
                $g = grade_response($blank, $response[$i] ?? $response[(string)$i] ?? null, $lenient);
                $parts[$i] = $g['fraction'] ?? 0.0;
                $total += $parts[$i];
            }
            return ['fraction' => $q['blanks'] ? $total / (float)count($q['blanks']) : 0.0, 'state' => 'graded', 'blanks' => $parts];
    }
    return ['fraction' => null, 'state' => 'needs_review'];
}
