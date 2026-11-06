<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 17-Sep-2026
 *
 * The exam paper: questions frozen when an exam is published, so later edits in
 * the bank never change a running exam. Pictures are copied into the exam's own
 * flat media/ folder.
 *
 * A section may be optional: "choose": 5 means "Answer any five" of its questions, and the
 * best five a student answers are the ones that count (lib/grading.php counted_slots()).
 *
 * Exam sections hold items:  {q: "<bank key>", mark: 1, copy: {...}}
 *                        or  {random: {category, sub: true, count: 5, types: [], tags: []}, mark: 1}
 *   copy is the question as it was when it was added to the exam (exam_question_copy), so the
 *   exam keeps it when the bank question is changed or deleted. The editor shows when the bank
 *   has changed it and can take the new version.
 *   A matching question's pairs can be edited for the exam: the copy loses the pairs the teacher
 *   removed and gets the ones they added (edit_match_pairs). The bank keeps its own.
 * paper.json:
 *   {exam, published, max_marks, questions: {"<key>": {...}},
 *    sections: [{title, shuffle, choose, slots: [{kind: fixed, q: key, mark} | {kind: random, pool: [keys], count, mark}]}]}
 */

defined('EXAMS_APP') or exit;

function load_paper(string $examId): ?array
{
    return read_json(exam_dir($examId) . '/paper.json');
}

function exam_has_attempts(string $examId): bool
{
    return (bool)list_dirs(exam_dir($examId) . '/attempts');
}

/** Bank questions a random item may pick from (visible, matching types/tags). */
function random_pool(array $random): array
{
    $category = normalize_category((string)($random['category'] ?? ''));
    $types = $random['types'] ?? [];
    $tags = array_map('mb_strtolower', $random['tags'] ?? []);
    $ids = [];
    foreach (bank_index() as $id => $row) {
        $inside = $row['category'] === $category || (!empty($random['sub']) && str_starts_with($row['category'], $category . '/'));
        if (!$inside || $row['hidden'] === '1' || in_array($row['type'], ['description'], true)) {
            continue;
        }
        if ($types && !in_array($row['type'], $types, true)) {
            continue;
        }
        if ($tags && !array_intersect($tags, array_map('mb_strtolower', explode(';', $row['tags'])))) {
            continue;
        }
        $ids[] = $id;
    }
    return $ids;
}

/**
 * A bank question copied for an exam: its content, the bank key and folder it came from, its
 * section and number there, and its Moodle entry. Its pictures are copied into the exam's
 * media/ folder ($copied remembers pictures already copied).
 */
function exam_question_copy(string $examId, array $q, array &$copied = []): array
{
    $mediaDir = exam_dir($examId) . '/media';
    $category = (string)$q['category'];
    $q = rewrite_media_refs($q, function ($ref) use ($category, $mediaDir, &$copied) {
        if (bank_picture_path($ref) === null) {
            return null;
        }
        $src = bank_file_dir($category) . '/' . $ref;
        if (!is_file($src)) {
            return null;
        }
        return $copied["$category/$ref"] ??= 'media/' . store_unique_file($mediaDir, basename($ref), file_get_contents($src));
    });
    return question_content($q) + array_filter([
        'id' => $q['id'], 'category' => $category, 'section' => $q['section'] ?? null, 'number' => $q['number'] ?? null, 'moodle' => $q['moodle'] ?? null,
    ], fn($v) => $v !== null);
}

/**
 * How the bank question an exam's copy came from compares with it: 'same', 'changed', 'gone', or
 * 'edited' when only a matching question's pairs differ (edited for the exam, edit_match_pairs).
 */
function exam_copy_state(array $copy): string
{
    $bank = bank_question_for($copy);
    if (!$bank) {
        return 'gone';
    }
    // Pictures are compared by name (a copy may have a -2 added in the exam's media folder)
    $plain = fn(array $q) => question_content(rewrite_media_refs($q, fn($ref) => 'media/' . preg_replace('/-\d+(\.\w+)$/', '$1', basename($ref))));
    [$bank, $copy] = [$plain($bank), $plain($copy)];
    if ($bank == $copy) {
        return 'same';
    }
    return ($copy['type'] ?? '') === 'match' && array_diff_key($bank, ['pairs' => 1]) == array_diff_key($copy, ['pairs' => 1]) ? 'edited' : 'changed';
}

/**
 * A matching question with the pairs a teacher chose for an exam. $list is the new order of
 * pairs: {keep: <index of a pair of $q>} or a pair of their own {question: "Speaker", answer:
 * "Plays sound"} (plain text). A pair of their own needs both sides and a left side and answer
 * not in the list already. Fewer than two pairs (extra wrong answers do not count) change nothing.
 */
function edit_match_pairs(array $q, $list): array
{
    if (($q['type'] ?? '') !== 'match' || !is_array($list)) {
        return $q;
    }
    $pairs = [];
    $kept = [];
    $plain = fn(string $html) => mb_strtolower(html_to_text($html));
    $taken = function (string $side, string $html) use (&$pairs, $plain): bool {
        return in_array($plain($html), array_map(fn($p) => $plain($p[$side]), $pairs), true);
    };
    foreach ($list as $p) {
        if (!is_array($p)) {
            continue;
        }
        if (isset($p['keep']) && is_int($p['keep']) && isset($q['pairs'][$p['keep']]) && !isset($kept[$p['keep']])) {
            $kept[$p['keep']] = true;
            $pairs[] = $q['pairs'][$p['keep']];
        } elseif (is_string($p['question'] ?? null) && is_string($p['answer'] ?? null)) {
            $left = mb_substr(trim(preg_replace('/\s+/u', ' ', $p['question'])), 0, 200);
            $right = mb_substr(trim(preg_replace('/\s+/u', ' ', $p['answer'])), 0, 200);
            if ($left !== '' && $right !== '' && !$taken('question', htmlspecialchars($left)) && !$taken('answer', htmlspecialchars($right))) {
                $pairs[] = ['question' => $left, 'format' => 'plain', 'answer' => $right];
            }
        }
    }
    if (count(array_filter($pairs, fn($p) => trim($p['question']) !== '')) < 2) {
        return $q;
    }
    $q['pairs'] = $pairs;
    return $q;
}

/** "Answer any N" of a section's questions (0: every question is compulsory). */
function section_choose(array $section): int
{
    return max(0, (int)($section['choose'] ?? 0));
}

/** The marks of every question a student gets from a section (a random pick, once per question). */
function section_question_marks(array $section): array
{
    $marks = [];
    foreach ($section['slots'] ?? $section['items'] ?? [] as $item) {
        $n = match (true) {
            isset($item['random']) => (int)$item['random']['count'],
            ($item['kind'] ?? '') === 'random' => (int)$item['count'],
            default => 1,
        };
        $marks = array_merge($marks, array_fill(0, max(0, $n), max(0.0, (float)($item['mark'] ?? 1))));
    }
    return $marks;
}

/**
 * The most a section can give: all its questions, or with "answer any N" the N largest marks,
 * because the best answers are the ones that count.
 */
function section_max_marks(array $marks, int $choose): float
{
    if ($choose > 0 && $choose < count($marks)) {
        rsort($marks);
        $marks = array_slice($marks, 0, $choose);
    }
    return (float)array_sum($marks);
}

/** "Answer any N" per section of a paper, for the sections that have one: [section index => N]. */
function paper_choose_map(array $paper): array
{
    $out = [];
    foreach ($paper['sections'] as $i => $section) {
        $choose = section_choose($section);
        if ($choose > 0 && $choose < count(section_question_marks($section))) {
            $out[$i] = $choose;
        }
    }
    return $out;
}

/** The most an attempt's slots can give, with its optional sections capped: [section index => N]. */
function slots_max_marks(array $slots, array $choose): float
{
    $bySection = [];
    foreach ($slots as $slot) {
        $bySection[(int)($slot['section'] ?? 0)][] = (float)$slot['mark'];
    }
    $total = 0.0;
    foreach ($bySection as $si => $marks) {
        $total += section_max_marks($marks, (int)($choose[$si] ?? 0));
    }
    return $total;
}

/** Problems that stop an exam from being published (empty when fine). */
function exam_publish_problems(array $exam, ?array $user): array
{
    $problems = [];
    if (trim($exam['title']) === '') {
        $problems[] = 'Give the exam a title.';
    }
    if (!$exam['classes'] && !$exam['users']) {
        $problems[] = 'Choose at least one class or student.';
    }
    $count = 0;
    foreach ($exam['sections'] as $si => $section) {
        $offered = count(section_question_marks($section));
        if (section_choose($section) > $offered) {
            $problems[] = 'Section ' . ($si + 1) . ' asks for any ' . section_choose($section) . ' answers but has only '
                . $offered . ' question' . ($offered === 1 ? '' : 's') . '.';
        }
        foreach ($section['items'] as $item) {
            if (isset($item['q'])) {
                $q = $item['copy'] ?? load_question($item['q']);
                if (!$q) {
                    $problems[] = 'A question in section ' . ($si + 1) . ' is no longer in the question bank. Remove it.';
                } elseif ($user && !can_manage_subject($user, subject_from_path($q['category']))) {
                    $problems[] = 'The question "' . mb_substr(html_to_text((string)$q['name']), 0, 60) . '" is not in your subjects.';
                }
                $count++;
            } elseif (isset($item['random'])) {
                $pool = random_pool($item['random']);
                $need = (int)$item['random']['count'];
                if ($need < 1) {
                    $problems[] = 'A random pick needs a count of at least 1.';
                } elseif (count($pool) < $need) {
                    $problems[] = "Only " . count($pool) . " questions match the random pick from {$item['random']['category']} (needs $need).";
                }
                $count += $need;
            }
        }
    }
    if ($count === 0) {
        $problems[] = 'Add some questions.';
    }
    $open = exam_time($exam['open_at']);
    $close = exam_time($exam['close_at']);
    if ($open !== null && $close !== null && $close <= $open) {
        $problems[] = 'The closing time must be after the opening time.';
    }
    return $problems;
}

/**
 * The paper of an exam as it is now: fixed questions from the exam's own copies, random picks
 * from the bank as it is now (their pictures copied into the exam). Only the pictures are
 * written; build_paper() freezes it, a teacher's preview of a draft uses it as it is.
 * A question that is gone from the bank without a copy is left out (publishing reports it).
 */
function make_paper(array $exam): array
{
    $paper = ['exam' => $exam['id'], 'published' => now_iso(), 'max_marks' => 0.0, 'questions' => [], 'sections' => []];
    $copied = [];
    $freeze = function (string $key, ?array $copy = null) use (&$paper, &$copied, $exam): ?string {
        if (!isset($paper['questions'][$key])) {
            $copy ??= ($q = load_question($key)) ? exam_question_copy($exam['id'], $q, $copied) : null;
            if ($copy === null) {
                return null;
            }
            $paper['questions'][$key] = $copy;
        }
        return $key;
    };

    foreach ($exam['sections'] as $section) {
        $slots = [];
        foreach ($section['items'] as $item) {
            $mark = max(0.0, (float)($item['mark'] ?? 1));
            if (isset($item['q'])) {
                if ($freeze($item['q'], $item['copy'] ?? null) !== null) {
                    $slots[] = ['kind' => 'fixed', 'q' => $item['q'], 'mark' => $mark];
                }
            } elseif (isset($item['random'])) {
                $pool = array_values(array_filter(array_map(fn($key) => $freeze($key), random_pool($item['random']))));
                $count = (int)$item['random']['count'];
                $slots[] = ['kind' => 'random', 'pool' => $pool, 'count' => $count, 'mark' => $mark, 'from' => $item['random']['category']];
            }
        }
        $choose = section_choose($section);
        $paper['max_marks'] += section_max_marks(section_question_marks(['slots' => $slots]), $choose);
        $paper['sections'][] = ['title' => (string)$section['title'], 'shuffle' => !empty($section['shuffle']), 'choose' => $choose, 'slots' => $slots];
    }
    return $paper;
}

/**
 * Freeze the questions into paper.json (make_paper). Allowed while nobody has started the
 * exam. Returns the paper.
 */
function build_paper(array $exam): array
{
    $paper = make_paper($exam);
    write_json(exam_dir($exam['id']) . '/paper.json', $paper);
    return $paper;
}

/**
 * Questions a student gets from a paper's sections, or from an exam's before it has a paper
 * (fixed ones and random picks, descriptions included), and the most marks they can get
 * (an optional section gives only its best N): [questions, marks].
 */
function paper_size(array $sections): array
{
    $count = 0;
    $marks = 0.0;
    foreach ($sections as $section) {
        $list = section_question_marks($section);
        $count += count($list);
        $marks += section_max_marks($list, section_choose($section));
    }
    return [$count, $marks];
}

/** How many questions a student has to answer: an optional section needs only its N. */
function paper_required_count(array $sections): int
{
    $need = 0;
    foreach ($sections as $section) {
        $offered = count(section_question_marks($section));
        $choose = section_choose($section);
        $need += $choose > 0 ? min($choose, $offered) : $offered;
    }
    return $need;
}

/** Publish (or re-publish before anyone starts). Returns [exam, problems]. */
function publish_exam(array $exam, array $user): array
{
    $problems = exam_publish_problems($exam, $user);
    if (!$problems && exam_has_attempts($exam['id']) && load_paper($exam['id'])) {
        // Students have started: keep the existing paper, only settings change
        $exam['status'] = 'published';
        return [save_exam($exam, $user['username']), []];
    }
    if ($problems) {
        return [$exam, $problems];
    }
    build_paper($exam);
    $exam['status'] = 'published';
    $exam['published'] = now_iso();
    return [save_exam($exam, $user['username']), []];
}

/** Types of questions a paper can contain (for reward payout rules). */
function paper_question_types(array $paper): array
{
    $types = [];
    foreach ($paper['questions'] as $q) {
        $types[$q['type']] = true;
        foreach ($q['blanks'] ?? [] as $b) {
            $types[$b['type']] = true;
        }
    }
    return array_keys($types);
}

function exam_media_url(string $examId): callable
{
    return function (string $src) use ($examId) {
        if (!str_starts_with($src, 'media/')) {
            return null;
        }
        return url('media.php') . '?exam=' . rawurlencode($examId) . '&f=' . rawurlencode(substr($src, 6));
    };
}
