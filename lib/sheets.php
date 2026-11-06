<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Result sheets to print and give to students: one per student, built from the frozen
 * paper and the try that counts. The HTML is a list of blocks per student; the browser
 * (assets/js/sheets.js) lays them out on A4 pages and adds blank pages for double-sided
 * printing. Two looks, chosen when printing: colour (school blue, marks in green/amber/red)
 * and black & white. Symbols ✓ ✗ ◐ and bold carry the meaning in both, never colour alone.
 */

defined('EXAMS_APP') or exit;

const SHEET_OPTIONS = [
    'answers' => true,       // show correct answers
    'comments' => true,      // show teacher comments
    'duplex' => true,        // each student starts on a new sheet when printing both sides
    'unsubmitted' => false,  // include tries not submitted (marks so far)
    'attachments' => true,    // add attachment pages
    'colour' => true,        // colour (false = black & white)
    'former' => true,        // with every student: also students who left school (no longer in users.csv)
];

/** Colour of a score: good (75% and above), ok (50-74%), low (below 50%). */
function sheet_score_level(float $percent): string
{
    return $percent >= 75 ? 'good' : ($percent >= 50 ? 'ok' : 'low');
}

/** CSS class for how right an answer is: right, part, wrong (or '' when not marked yet). */
function sheet_result_class(?float $fraction, string $state): string
{
    if ($state === 'needs_review' || $fraction === null) {
        return '';
    }
    return $fraction >= 0.9999 ? 'right' : ($fraction > 0 && $state !== 'unanswered' ? 'part' : 'wrong');
}

/** Options from a request, with defaults. */
function sheet_options(array $query): array
{
    $out = [];
    foreach (SHEET_OPTIONS as $key => $default) {
        $out[$key] = isset($query['set']) ? !empty($query[$key]) : $default;
    }
    return $out;
}

/**
 * The students and tries to print, in class and name order.
 * $usernames: [] = every student with a try. $onlyTry: print this try number (one student). $year: only tries of that school year.
 * Returns [['student' => [username, name, class], 'attempt' => try (graded or marks so far), 'so_far' => bool]].
 */
function result_sheet_entries(array $exam, array $paper, array $usernames, bool $includeUnsubmitted, ?int $onlyTry = null, ?string $year = null): array
{
    $all = all_attempts($exam['id']);
    if ($year !== null && $onlyTry === null) {   // one school year's tries (an exam reopened in a later year)
        $all = array_filter(array_map(fn($list) => array_values(array_filter($list, fn($a) => attempt_school_year($a) === $year)), $all));
    }
    $wanted = $usernames ?: array_keys($all);
    $out = [];
    foreach ($wanted as $username) {
        $list = $all[$username] ?? [];
        if (!$list) {
            continue;
        }
        $attempt = null;
        if ($onlyTry !== null) {
            $attempt = $list[array_search($onlyTry, array_column($list, 'n'), true)] ?? null;
        } else {
            $result = exam_student_result($exam, $username, $list);
            if ($result && $result['attempt']) {
                $attempt = $list[array_search($result['attempt'], array_column($list, 'n'), true)] ?? null;
            }
            $finished = array_values(array_filter($list, fn($a) => $a['status'] !== 'in_progress'));
            $attempt ??= $finished ? end($finished) : null;   // "average" grade method: the last try
            if ($attempt === null && $includeUnsubmitted) {
                $open = array_values(array_filter($list, fn($a) => $a['status'] === 'in_progress'));
                $attempt = $open ? end($open) : null;
            }
        }
        if ($attempt === null) {
            continue;
        }
        $soFar = $attempt['status'] === 'in_progress';
        if ($soFar) {
            $attempt = attempt_marks_so_far($exam, $paper, $attempt);
        }
        $student = student_info($username, $list);
        $student['class'] = attempt_student_class($username, $list);
        // On paper just the name (no "(Moodle)" note for students without a bank account)
        $student['name'] = preg_replace('/ \(Moodle\)$/', '', display_name($username, end($list)));
        $out[] = ['student' => $student, 'attempt' => $attempt, 'so_far' => $soFar];
    }
    usort($out, fn($a, $b) => [$a['student']['class'], mb_strtolower($a['student']['name'])] <=> [$b['student']['class'], mb_strtolower($b['student']['name'])]);
    return $out;
}

/** "2", "1.5" */
function sheet_number($x): string
{
    return rtrim(rtrim(number_format((float)$x, 2, '.', ''), '0'), '.');
}

/** ✓ / ✗ / ◐ symbol for a fraction (null = not marked yet). */
function sheet_symbol(?float $fraction, string $state = 'graded'): string
{
    if ($state === 'needs_review' || $fraction === null) {
        return '<span class="sym sym-wait" title="To be marked">?</span>';
    }
    if ($state === 'unanswered') {
        return '<span class="sym sym-wrong" title="Not answered">✗</span>';
    }
    if ($fraction >= 0.9999) {
        return '<span class="sym sym-right" title="Right">✓</span>';
    }
    return $fraction > 0 ? '<span class="sym sym-part" title="Partly right">◐</span>' : '<span class="sym sym-wrong" title="Wrong">✗</span>';
}

/**
 * Accepted answers of a short-answer or numerical part, best first. Variants that differ only
 * in spaces or capitals ("ctrl + u", "Ctrl+U") are shown once; at most 3.
 */
function sheet_accepted(array $part): string
{
    $answers = array_filter($part['answers'], fn($a) => (float)$a['fraction'] > 0 && ($a['value'] ?? '') !== '*');
    usort($answers, fn($a, $b) => (float)$b['fraction'] <=> (float)$a['fraction']);
    $shown = [];
    foreach ($answers as $a) {
        $text = $part['type'] === 'numerical' ? format_number_answer($a) : $a['text'];
        $shown[preg_replace('/\s+/u', '', mb_strtolower($text))] ??= $text;
    }
    return implode(' / ', array_map('h', array_slice(array_values($shown), 0, 3)));
}

/**
 * A written answer as answer-sheet blocks: every paragraph, heading and list item becomes its own
 * ".part", so a long answer can carry on over the next pages (assets/js/sheets.js splits a block
 * between its parts). The pieces draw one box together: the first has its top, the last its bottom,
 * so an answer cut across pages ends open, like a page that goes on.
 */
function student_html_parts(string $answer): string
{
    $html = student_html($answer);
    if (trim($html) === '') {
        return '';
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="__answer">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__answer');
    if (!$root) {
        return '<div class="ans-line part first last">' . $html . '</div>';
    }
    $blocks = ['p', 'h3', 'h4', 'ul', 'ol'];

    // Words and inline tags standing on their own (a first line with no <p> around it) become a paragraph
    $group = null;
    foreach (iterator_to_array($root->childNodes) as $node) {
        if ($node instanceof DOMElement && in_array(strtolower($node->nodeName), $blocks, true)) {
            $group = null;
            continue;
        }
        if ($node instanceof DOMText && trim($node->nodeValue) === '') {
            continue;
        }
        if (!$group) {
            $group = $doc->createElement('p');
            $root->insertBefore($group, $node);
        }
        $group->appendChild($node);
    }

    // One list item per part, so even a long list splits; a numbered list carries on counting
    foreach (iterator_to_array($root->childNodes) as $node) {
        if (!$node instanceof DOMElement || !in_array(strtolower($node->nodeName), ['ul', 'ol'], true)) {
            continue;
        }
        $tag = strtolower($node->nodeName);
        $number = max(1, (int)($node->getAttribute('start') ?: 1));
        foreach (iterator_to_array($node->childNodes) as $item) {
            if (!$item instanceof DOMElement || strtolower($item->nodeName) !== 'li') {
                continue;
            }
            $list = $doc->createElement($tag);
            $list->setAttribute('class', trim($node->getAttribute('class') . ' ans-line part'));
            if ($tag === 'ol') {
                $list->setAttribute('start', (string)$number++);
            }
            $list->appendChild($item);
            $root->insertBefore($list, $node);
        }
        $root->removeChild($node);
    }

    $out = [];
    foreach (iterator_to_array($root->childNodes) as $node) {
        if (!$node instanceof DOMElement) {
            $root->removeChild($node);
            continue;
        }
        if (!str_contains($node->getAttribute('class'), 'ans-line')) {
            $node->setAttribute('class', trim($node->getAttribute('class') . ' ans-line part'));
        }
        $out[] = $node;
    }
    if (!$out) {
        return '';
    }
    $out[0]->setAttribute('class', $out[0]->getAttribute('class') . ' first');
    $out[count($out) - 1]->setAttribute('class', $out[count($out) - 1]->getAttribute('class') . ' last');
    return implode('', array_map(fn($n) => $doc->saveHTML($n), $out));
}

/** One answer line: "Answer: xyz ✓  Correct answer: abc". $result: right, part, wrong or ''. */
function sheet_answer_line(string $answerHtml, string $symbol, string $keyHtml, bool $showKey, string $result = ''): string
{
    return '<div class="ans part"><span class="lbl">Answer:</span> <span class="given' . ($result !== '' ? " g-$result" : '') . '">' . $answerHtml . '</span> ' . $symbol
        . ($showKey && $keyHtml !== '' ? '<span class="key"><span class="lbl">Correct answer:</span> <span class="keyval">' . $keyHtml . '</span></span>' : '')
        . '</div>';
}

/** The answer part of a question block for one question or one blank. */
function sheet_answer_html(array $q, $response, array $layout, ?float $fraction, string $state, bool $showKey, string $format, callable $mediaUrl): string
{
    $blank = grade_is_blank($response);
    $none = '<span class="none">Not answered</span>';
    switch ($q['type']) {
        case 'multichoice':
            $order = $layout['choices'] ?? array_keys($q['answers']);
            $chosen = array_map('intval', is_array($response) ? $response : ($blank ? [] : [$response]));
            $short = !array_filter($order, fn($i) => mb_strlen(html_to_text($q['answers'][$i]['text'])) > 28 || preg_match('/<(img|svg)\b/i', $q['answers'][$i]['text']));
            $out = '<ol class="choices part' . ($short && count($order) > 2 ? ' two-col' : '') . '">';
            foreach ($order as $pos => $i) {
                $a = $q['answers'][$i];
                $picked = in_array($i, $chosen, true);
                $right = (float)$a['fraction'] > 0;
                $mark = $picked ? ($right ? '<span class="sym sym-right">✓</span>' : '<span class="sym sym-wrong">✗</span>')
                    : ($showKey && $right ? '<span class="tag">correct</span>' : '');
                $out .= '<li class="' . ($picked ? 'picked ' . ($right ? 'right' : 'wrong') : ($showKey && $right ? 'key-choice' : '')) . '"><span class="box">' . ($picked ? '●' : '') . '</span><span class="letter">' . chr(97 + $pos) . ')</span>'
                    . '<span class="choice">' . format_text($a['text'], $a['format'] ?? $format, $mediaUrl) . '</span>' . $mark . '</li>';
            }
            $out .= '</ol>';
            return $out . ($blank ? '<div class="ans part">' . $none . '</div>' : '');

        case 'truefalse':
            $key = !empty($q['correct']) ? 'True' : 'False';
            return sheet_answer_line($blank ? $none : ($response ? 'True' : 'False'), sheet_symbol($fraction, $state), $key, $showKey && ($fraction ?? 0) < 1, sheet_result_class($fraction, $state));

        case 'shortanswer':
        case 'numerical':
            return sheet_answer_line($blank ? $none : h((string)$response), sheet_symbol($fraction, $state), sheet_accepted($q), $showKey && ($fraction ?? 0) < 1, sheet_result_class($fraction, $state));

        case 'match':
            $choices = match_choices($q);
            $given = is_array($response) ? $response : [];
            $rows = '';
            $right = 0;
            $stems = $layout['stems'] ?? match_stems($q);
            foreach ($stems as $stem) {
                $pair = $q['pairs'][$stem];
                $pick = $given[$stem] ?? $given[(string)$stem] ?? null;
                $text = $pick === null ? null : ($choices[(int)$pick] ?? null);
                $ok = $text !== null && $text === $pair['answer'];
                $right += $ok ? 1 : 0;
                $rows .= '<tr><td class="stem">' . format_text($pair['question'], $pair['format'] ?? $format, $mediaUrl) . '</td><td class="arrow">→</td>'
                    . '<td class="' . ($ok ? 'g-right' : 'g-wrong') . '">' . ($text === null ? $none : h($text)) . '</td><td class="symcell">' . ($ok ? '<span class="sym sym-right">✓</span>' : '<span class="sym sym-wrong">✗</span>') . '</td>'
                    . ($showKey ? '<td class="keycell">' . ($ok ? '' : h($pair['answer'])) . '</td>' : '') . '</tr>';
            }
            $head = '<tr><th>Item</th><th></th><th>Answer</th><th></th>' . ($showKey ? '<th>Correct answer</th>' : '') . '</tr>';
            return '<table class="match part"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table>'
                . '<div class="match-count part">' . $right . ' of ' . count($stems) . ' matched correctly</div>';

        case 'essay':
            $out = $blank ? '<div class="ans-line part first last">' . $none . '</div>' : student_html_parts((string)$response);
            if ($showKey && trim((string)($q['grader_info'] ?? '')) !== '') {
                $out .= '<div class="keypoints part"><span class="lbl">Key points:</span> ' . format_text($q['grader_info'], 'moodle', $mediaUrl) . '</div>';
            }
            return $out;
    }
    return '';
}

/** One question as a block (number, text, mark, answer, correct answer, comment). */
function sheet_question_block(array $q, array $slot, ?array $grade, $response, int $number, array $options, callable $mediaUrl): string
{
    $format = $q['format'] ?? 'moodle';
    if ($q['type'] === 'description') {
        return '<div class="blk desc"><div class="qtext part">' . format_text($q['text'], $format, $mediaUrl) . '</div></div>';
    }
    $state = $grade['state'] ?? 'unanswered';
    $fraction = isset($grade['fraction']) ? (float)$grade['fraction'] : null;
    // An extra answer in an "answer any five" section: written, but not one of the five that counted
    $extra = ($grade['counted'] ?? true) === false;
    $markText = $extra ? '<b>Extra</b>' : ($state === 'needs_review' || ($grade['mark'] ?? null) === null
        ? '<b>–</b> / ' . sheet_number($slot['mark'])
        : '<b>' . sheet_number($grade['mark']) . '</b> / ' . sheet_number($slot['mark']));

    $text = format_text($q['text'], $format, $mediaUrl);
    if (html_to_text($text) === '' && !preg_match('/<(img|svg)\b/i', $text)) {
        $text = h($q['name']);   // e.g. a matching question with no text of its own
    }
    $layout = $slot['layout'] ?? [];
    if ($q['type'] === 'multiblank') {
        $text = preg_replace_callback('/\{#(\d+)\}/', fn($m) => '<span class="blank">(' . (int)$m[1] . ')</span>', $text);
    } elseif (!empty($q['missing_word'])) {
        $text = preg_replace('/_____/', '<span class="blank"></span>', $text, 1);
    }

    $out = '<div class="blk q' . ($extra ? ' extra' : '') . '">'
        . '<div class="qhead part"><span class="qno"><span>' . $number . '</span></span><div class="qtext">' . $text . '</div>'
        . '<div class="qmark">' . ($extra ? '' : sheet_symbol($fraction, $state)) . '<span class="marks">' . $markText . '</span></div></div>';

    if ($q['type'] === 'multiblank') {
        $parts = is_array($response) ? $response : [];
        foreach ($q['blanks'] as $i => $blank) {
            $bf = isset($grade['blanks'][$i]) ? (float)$grade['blanks'][$i] : (grade_is_blank($parts[$i] ?? null) ? 0.0 : null);
            $bstate = grade_is_blank($parts[$i] ?? null) ? 'unanswered' : ($grade['blank_info'][$i]['state'] ?? 'graded');
            $inner = sheet_answer_html($blank, $parts[$i] ?? null, $layout['blanks'][$i] ?? [], $bf, $bstate, $options['answers'], $format, $mediaUrl);
            $out .= '<div class="blank-row part"><span class="blank-no">(' . ($i + 1) . ')</span><div>' . $inner . '</div></div>';
        }
    } else {
        $out .= sheet_answer_html($q, $response, $layout, $fraction, $state, $options['answers'], $format, $mediaUrl);
    }
    if ($extra) {
        $out .= '<div class="note part">Extra answer: this section asked for fewer, and the best answers counted.</div>';
    } elseif ($state === 'needs_review') {
        $out .= '<div class="note part">To be marked by the teacher.</div>';
    }
    if ($options['comments'] && trim((string)($grade['comment'] ?? '')) !== '') {
        $out .= '<div class="comment part"><span class="lbl">Teacher:</span> ' . h($grade['comment']) . '</div>';
    }
    return $out . '</div>';
}

/** The whole sheet of one student: header block and one block per question. */
function render_result_sheet(array $exam, array $paper, array $entry, array $options): string
{
    $a = $entry['attempt'];
    $s = $entry['student'];
    $mediaUrl = exam_media_url($exam['id']);
    $t = attempt_times($a);
    $percent = $a['max'] > 0 ? 100 * (float)$a['score'] / $a['max'] : 0;
    $pending = !$entry['so_far'] && $a['status'] === 'submitted';
    $counts = ['right' => 0, 'part' => 0, 'wrong' => 0, 'none' => 0];
    foreach ($a['slots'] as $slot) {
        if (($paper['questions'][$slot['key']]['type'] ?? '') === 'description') {
            continue;
        }
        $g = $a['grades'][$slot['slot']] ?? null;
        if (($g['counted'] ?? true) === false) {
            continue;   // an extra answer is not right or wrong: it did not count
        }
        $f = $g['fraction'] ?? null;
        $key = ($g['state'] ?? 'unanswered') === 'unanswered' ? 'none' : ($f === null ? null : ($f >= 0.9999 ? 'right' : ($f > 0 ? 'part' : 'wrong')));
        if ($key) {
            $counts[$key]++;
        }
    }
    $tries = count(list_attempts($exam['id'], $s['username']));
    $band = $entry['so_far'] || $pending ? '' : (exam_band_text($exam, $percent) ?: ($percent >= 90 ? 'Excellent!' : ($percent >= 60 ? 'Good work!' : 'Keep practising!')));
    // The exam's class (a student's class in users.csv may already be next year's)
    $classes = array_map('normalize_class', $exam['classes']);
    $classText = $classes ? 'Class ' . implode(', ', $classes) : ($s['class'] !== '' ? 'Class ' . $s['class'] : '');

    $html = '<section class="student" data-name="' . h($s['name']) . '" data-meta="' . h(trim($classText . ' · ' . $exam['title'], ' ·')) . '">';
    $sameDay = $t['started'] && $t['finished'] && date('Y-m-d', $t['finished']) === date('Y-m-d', $t['started']);
    $level = $entry['so_far'] || $pending ? 'neutral' : sheet_score_level($percent);
    $html .= '<div class="blk head">'
        . '<div class="band">'
        . '<div class="school">' . h(cfg('SCHOOL_NAME')) . '</div>'
        . '<h1>' . h($exam['title']) . '</h1>'
        . '<div class="subject">' . h(trim($exam['subject'] . ($classText !== '' ? ' · ' . $classText : ''), ' ·')) . '</div>'
        . '</div>'
        . ($entry['so_far'] ? '<div class="stamp">NOT SUBMITTED · marks so far</div>' : '')
        . '<div class="head-grid">'
        . '<table class="facts">'
        . '<tr><th>Student</th><td><b>' . h($s['name']) . '</b></td></tr>'
        . '<tr><th>Try</th><td>' . (int)$a['n'] . ($tries > 1 ? ' of ' . $tries : '') . '</td></tr>'
        . '<tr><th>Date</th><td>' . ($t['started'] ? h(date('l, j F Y', $t['started'])) : '—') . '</td></tr>'
        . '<tr><th>Started</th><td>' . ($t['started'] ? h(date('g:i A', $t['started'])) : '—') . '</td></tr>'
        . '<tr><th>Finished</th><td>' . ($t['finished'] ? h(date($sameDay ? 'g:i A' : 'D j M Y, g:i A', $t['finished'])) : ($entry['so_far'] ? 'Not submitted' : '—')) . '</td></tr>'
        . ($t['seconds'] !== null ? '<tr><th>Time taken</th><td>' . h(duration_text($t['seconds'])) . '</td></tr>' : '')
        . '</table>'
        . '<div class="score lvl-' . $level . '"><div class="score-label">' . ($entry['so_far'] ? 'Marks so far' : 'Marks') . '</div>'
        . '<div class="score-big">' . sheet_number($a['score']) . '<span> / ' . sheet_number($a['max']) . '</span></div>'
        . '<div class="score-pct">' . round($percent) . '%</div>'
        . ($band !== '' ? '<div class="score-band">' . h($band) . '</div>' : '')
        . ($pending ? '<div class="score-band">Some answers are still being marked</div>' : '')
        . '</div></div>'
        . (attempt_answers_lost($a) ? '<div class="note">The answers of this try were not kept in Moodle; only the marks came across.</div>' : '<div class="tally"><span class="t-right"><span class="sym sym-right">✓</span> Right <b>' . $counts['right'] . '</b></span>'
        . ($counts['part'] ? '<span class="t-part"><span class="sym sym-part">◐</span> Partly right <b>' . $counts['part'] . '</b></span>' : '')
        . '<span class="t-wrong"><span class="sym sym-wrong">✗</span> Wrong <b>' . $counts['wrong'] . '</b></span>'
        . '<span class="t-none">Not answered <b>' . $counts['none'] . '</b></span></div>')
        . '</div>';

    $number = 0;
    $section = -1;
    foreach ($a['slots'] as $slot) {
        $q = $paper['questions'][$slot['key']] ?? null;
        if (!$q) {
            continue;
        }
        if (($slot['section'] ?? 0) !== $section) {
            $section = $slot['section'] ?? 0;
            $title = trim((string)($paper['sections'][$section]['title'] ?? ''));
            $choice = choice_note_text($paper['sections'][$section] ?? []);
            if ($title !== '' || $choice !== '') {
                $html .= '<div class="blk section-title">'
                    . ($title !== '' ? '<h2 class="part">' . h($title) . '</h2>' : '')
                    . ($choice !== '' ? '<div class="note part">' . h($choice) . '</div>' : '')
                    . '</div>';
            }
        }
        if ($q['type'] !== 'description') {
            $number++;
        }
        $answers = (array)$a['answers'];
        $html .= sheet_question_block($q, $slot, $a['grades'][$slot['slot']] ?? null, $answers[$slot['slot']] ?? null, $number, $options, $mediaUrl);
    }
    if ($options['attachments']) {
        foreach (attachment_pages($exam['id'], $s['username']) as $page) {
            $html .= '<div class="blk full-page attachment"><img src="' . h(attachment_page_url($exam['id'], $s['username'], $page)) . '" alt="Attachment"></div>';
        }
    }
    return $html . '</section>';
}
