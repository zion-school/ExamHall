<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * The exam player on a phone: one question of the try in progress
 * (?s=slot), and saving its answer (POST, then where the student went).
 *   - single choice and true/false: a list; several choices: a multiple
 *     list; short and numerical answers: a text field, in the blank
 *     ("_____") when the question has one; a multi-blank question: a field
 *     or list in each blank; written answers: a text field. A list's title
 *     is the question, which the browser's picker shows, so it is in sight
 *     while choosing.
 *   - Picking or typing stays on the question. Leaving it saves: Next> (a
 *     link under the answer), Back (the right soft key: the question
 *     before), Options > Go to (a question number, asked by exams.wmls),
 *     Overview, Flag, Finish, and the time-up timer. Each sets "to" and goes
 *     to the card "sv" that posts.
 *   - matching: a link per left side with its answer; the link opens its
 *     choices (?p=stem), a digit each, and a choice is saved at once.
 * Answers are in the variables a0, a1, ... on every question, so going
 * through the exam doesn't add variables (an ESP32 browser keeps 16).
 * The title shows the time left; the card's timer submits at time-up, and
 * the server checks the time on every save (save_attempt_request()).
 */
require_once __DIR__ . '/lib/wap.php';

/** A phone's written answer is at most this long (ESP32: a variable holds 192 bytes) */
const WAP_ESSAY_CHARS = 120;

$user = wap_student();
[$exam, $paper, $status] = wap_exam($user, false);
$attempt = wap_playing($exam, $status);
$questions = try_questions($paper, $attempt);
$slots = array_keys($questions);
$numbers = question_numbers($questions);
$s = (int)((wap_posted() ? $_POST['s'] ?? 0 : $_GET['s'] ?? 0));
$s = isset($questions[$s]) ? $s : $slots[0];
[$slot, $q] = $questions[$s];
$id = $exam['id'];
$here = wap_url('q.php', ['id' => $id, 's' => $s]);

/**
 * The answer posted for $q in a0, a1, ..., or null for none. Matching: a0 for the left side p,
 * the others as in $old; a multi-blank question: a<i> is blank i.
 */
function posted_answer(array $q, $old)
{
    $a = fn(int $k) => trim((string)($_POST["a{$k}"] ?? ''));
    switch ($q['type']) {
        case 'multichoice':
            if ($a(0) === '') {
                return null;
            }
            return !empty($q['single']) ? (int)$a(0) : array_map('intval', explode(';', $a(0)));
        case 'truefalse':
            return $a(0) === '' ? null : $a(0) === '1';
        case 'essay':
            return $a(0) === '' ? null : StudentHtml::store(h($a(0)), ATTEMPT_ESSAY_LIMIT);
        case 'match':
            $pairs = (array)($old ?? []);
            unset($pairs[(int)($_POST['p'] ?? -1)]);
            if ($a(0) !== '') {
                $pairs[(int)$_POST['p']] = (int)$a(0);
            }
            return $pairs ?: null;
        case 'multiblank':
            $blanks = [];
            foreach ($q['blanks'] as $i => $blank) {
                if ($a($i) !== '') {
                    $blanks[$i] = $blank['type'] === 'multichoice' ? (int)$a($i) : $a($i);
                }
            }
            return $blanks ?: null;
    }
    return $a(0) === '' ? null : $a(0);
}

if (wap_posted()) {
    wap_csrf_check();
    $to = (string)($_POST['to'] ?? 'next');
    // "v": the try's saves when the page was made. A form from before a later save is one the
    // browser sent again on going Back (WML 1.3 9.2): it goes where it went, without saving.
    if ((int)($_POST['v'] ?? -1) === (int)$attempt['seq'] || $to === 'timeup') {
        $answers = (array)$attempt['answers'];
        $flags = $attempt['flags'];
        if (isset($_POST['set'])) {
            $answers[$s] = posted_answer($q, $answers[$s] ?? null);
            if ($answers[$s] === null) {
                unset($answers[$s]);
            }
        }
        if ($to === 'flag') {
            $flags = in_array($s, $flags, true) ? array_values(array_diff($flags, [$s])) : array_merge($flags, [$s]);
        }
        $res = wap_save($attempt, $answers, $flags, $to === 'timeup', $to === 'timeup');
        if (empty($res['ok'])) {
            wap_refused($res, $exam, $attempt);
        }
    }
    if ($to === 'timeup') {
        if (!empty($res['early'])) {
            wap_redirect($here);    // the phone's timer was early: carry on
        }
        wap_redirect_then_hooks(wap_url('result.php', ['id' => $id, 'n' => $attempt['n']]), $attempt);
    }
    $i = array_search($s, $slots, true);
    $next = match ($to) {
        'next' => $slots[$i + 1] ?? 'overview',
        'prev' => $slots[$i - 1] ?? 'exam',
        'goto' => array_search('Q' . (int)($_POST['g'] ?? 0), $numbers, true) ?: $s,
        'overview' => 'overview',
        default => $s,
    };
    wap_redirect(match ($next) {
        'overview' => wap_url('finish.php', ['id' => $id]),
        'exam' => wap_url('exam.php', ['id' => $id]),
        default => wap_url('q.php', ['id' => $id, 's' => $next]),
    });
}

// ---- The question

$answer = $attempt['answers'][$s] ?? null;
$count = count(array_filter($numbers));
$left = attempt_seconds_left($attempt);
$title = ($numbers[$s] !== '' ? $numbers[$s] . '/' . $count : 'Note') . ($left !== null ? ' ' . left_text($left) : '');
$brief = question_brief($q, 20);
$post = wap_url('q.php', ['id' => $id]);
// At time-up the card "t" sends the answer on the screen to "sv" (see below), which saves and submits
$timeup = '<card id="t"><onevent type="onenterforward"><go href="#sv"><setvar name="to" value="timeup"/></go></onevent>'
    . "<onevent type=\"onenterbackward\"><prev/></onevent></card>\n";

// A left side of a matching question: its choices in full, a digit each, saved at once (each
// link puts its choice in a0 and goes to the card "sv" that posts it)
if ($q['type'] === 'match' && isset($_GET['p'], $q['pairs'][(int)$_GET['p']])) {
    $stem = (int)$_GET['p'];
    $choices = match_choices($q);
    $pick = fn($choice) => '<go href="#sv"><setvar name="a0" value="' . $choice . '"/><setvar name="to" value="stay"/></go>';
    wml_start($title, '', soft_keys([['Back', go_to($here)]]), ['a0' => (string)($answer[$stem] ?? '')],
        $left !== null ? [$left + 1, '#t'] : null);
    echo '<b>' . e(answer_label($q['pairs'][$stem]['question'])) . "</b><br/>\n";
    foreach ($slot['layout']['choices'] as $k => $c) {
        $text = (isset($answer[$stem]) && (int)$answer[$stem] === $c ? '*' : '') . $choices[$c];
        echo '<anchor' . ($k < 9 ? ' accesskey="' . ($k + 1) . '">' . ($k + 1) . ' ' : '>') . e($text) . $pick($c) . "</anchor><br/>\n";
    }
    if (isset($answer[$stem])) {
        echo '<anchor accesskey="0">0 Clear' . $pick('') . "</anchor><br/>\n";
    }
    wml_end(post_card('sv', $post, ['s' => $s, 'v' => $attempt['seq'], 'p' => $stem, 'set' => 1, 'a0' => field('a0'), 'to' => field('to')]) . $timeup);
}

/** Characters of an option the browser's picker shows on its one row (128 px) */
const WAP_PICK_CHARS = 20;

/**
 * Choices [value => text] for lists: [lines to show above them, the lists' labels]. Short ones
 * are only in the list; when one is longer than a picker row, all are listed in full, lettered
 * "a) ...", and the list's rows start with the letter.
 */
function choice_labels(array $choices): array
{
    if (max(array_map('mb_strlen', $choices ?: [''])) <= WAP_PICK_CHARS) {
        return ['', $choices];
    }
    $lines = '';
    $labels = [];
    foreach (array_keys($choices) as $k => $value) {
        $letter = chr(97 + $k % 26) . ') ';
        $lines .= e($letter . $choices[$value]) . "<br/>\n";
        $labels[$value] = mb_strimwidth($letter . $choices[$value], 0, WAP_PICK_CHARS, '..', 'UTF-8');
    }
    return [$lines, $labels];
}

/** A list: "-" (no answer) first, then [value => label]; $title is shown by the picker */
function answer_select(string $name, string $title, array $labels, bool $multiple = false): string
{
    $out = '<select name="' . $name . '" title="' . e($title) . '"' . ($multiple ? ' multiple="true"' : '') . '>'
        . ($multiple ? '' : '<option value="">-</option>');
    foreach ($labels as $value => $text) {
        $out .= '<option value="' . $value . '">' . e($text) . '</option>';
    }
    return $out . "</select><br/>\n";
}

// The answer's fields, in the variables a0, a1, ...; $blanks: a field to put in a blank of the
// text, by its mark there
$fields = '';
$vars = [];
$blanks = [];
$text = $q['text'];
switch ($q['type']) {
    case 'multichoice':
        $choices = [];
        foreach ($slot['layout']['choices'] as $c) {
            $choices[$c] = answer_label($q['answers'][$c]['text']);
        }
        $vars['a0'] = implode(';', array_map('strval', (array)($answer ?? [])));
        [$lines, $labels] = choice_labels($choices);
        $fields = $lines . answer_select('a0', $brief, $labels, empty($q['single']));
        break;
    case 'truefalse':
        $vars['a0'] = $answer === null ? '' : ($answer ? '1' : '0');
        $fields = answer_select('a0', $brief, [1 => 'True', 0 => 'False']);
        break;
    case 'match':
        $choices = match_choices($q);
        foreach ($slot['layout']['stems'] as $k => $stem) {
            $chosen = isset($answer[$stem]) ? $choices[(int)$answer[$stem]] ?? '?' : '-';
            $fields .= link_to(answer_label($q['pairs'][$stem]['question']) . ': ' . $chosen,
                wap_url('q.php', ['id' => $id, 's' => $s, 'p' => $stem]), $k < 9 ? $k + 1 : null) . "\n";
        }
        break;
    case 'shortanswer':
    case 'numerical':
        $vars['a0'] = (string)($answer ?? '');
        $input = '<input name="a0" maxlength="100"' . ($q['type'] === 'numerical' ? ' size="8"' : '') . '/>';
        // In the blank of "_____ is the first screen", else under the question
        $text = preg_replace('/_{3,}/', 'WAPBLANK0', $text, 1, $found);
        if ($found) {
            $blanks['WAPBLANK0'] = $input;
        } else {
            $fields = "Answer:{$input}<br/>\n";
        }
        break;
    case 'essay':
        $written = grade_is_blank($answer) ? '' : student_html_text((string)$answer);
        if (mb_strlen($written) > WAP_ESSAY_CHARS) {
            // Written on a computer: too long to edit here, and saving it from a phone would cut it
            $fields = e(mb_strimwidth($written, 0, 100, '...', 'UTF-8')) . "<br/>\n(Long answer: change it on a computer)<br/>\n";
            break;
        }
        $vars['a0'] = $written;
        $fields = 'Answer:<input name="a0" maxlength="' . WAP_ESSAY_CHARS . "\"/><br/>\n";
        break;
    case 'multiblank':
        // Each blank's field or list where it is in the text ({#1}, {#2}, ...)
        foreach ($q['blanks'] as $i => $blank) {
            $vars["a{$i}"] = (string)($answer[$i] ?? '');
            if ($blank['type'] === 'multichoice') {
                $options = [];
                foreach ($slot['layout']['blanks'][$i]['choices'] ?? array_keys($blank['answers']) as $c) {
                    $options[$c] = answer_label($blank['answers'][$c]['text']);
                }
                [$lines, $labels] = choice_labels($options);
                $fields .= $lines !== '' ? '[' . ($i + 1) . "]<br/>\n" . $lines : '';
                $widget = str_replace("<br/>\n", '', answer_select("a{$i}", '[' . ($i + 1) . "] {$brief}", $labels));
            } else {
                $widget = '<input name="a' . $i . '" maxlength="60"/>';
            }
            $blanks['WAPBLANK' . ($i + 1)] = $widget;
            $text = str_replace('{#' . ($i + 1) . '}', 'WAPBLANK' . ($i + 1), $text);
        }
        break;
}

// Leaving the question: each way sets "to" and goes to the card "sv", which saves the answer.
// A number answer is checked first (exams.wmls).
$last = $s === end($slots);
$flagged = in_array($s, $attempt['flags'], true);
$leave = function (string $to) use ($q): string {
    if ($q['type'] === 'numerical' && $to !== 'flag') {
        return '<go href="' . e("exams.wmls#num('{$to}','sv')") . '"/>';
    }
    return '<go href="#sv"><setvar name="to" value="' . $to . '"/></go>';
};
$save = ['s' => $s, 'v' => $attempt['seq']]
    + ($vars ? ['set' => 1] + array_map('field', array_combine(array_keys($vars), array_keys($vars))) : []);
$cards = post_card('sv', $post, $save + ['to' => field('to'), 'g' => field('gq')]) . $timeup;

$keys = soft_keys([
    ['Go to', '<go href="' . e("exams.wmls#goq({$count},'sv')") . '"/>'],     // asks for the number
    ['Overview', $leave('overview')],
    [$flagged ? 'Unflag' : 'Flag', $leave('flag')],
    ['Finish', $leave('overview')],
]) . '<do type="prev" label="Back">' . $leave('prev') . "</do>\n";

wml_start($title, '', $keys, $vars, $left !== null ? [$left + 1, '#t'] : null);
$section = $paper['sections'][$slot['section']] ?? [];
$i = array_search($s, $slots, true);
$choose = (int)($attempt['choose'][$slot['section']] ?? 0);
if ($choose > 0) {
    // "Answer any five": how many are answered so far
    $answered = 0;
    $offered = 0;
    foreach ($questions as $other => [$o, $oq]) {
        if ($o['section'] === $slot['section'] && $oq['type'] !== 'description') {
            $offered++;
            $answered += grade_is_blank($attempt['answers'][$other] ?? null) ? 0 : 1;
        }
    }
    echo '<b>' . e(trim(($section['title'] ?? '') . " Any {$choose} of {$offered}: {$answered} done")) . "</b><br/>\n";
} elseif (trim($section['title'] ?? '') !== '' && ($i === 0 || $questions[$slots[$i - 1]][0]['section'] !== $slot['section'])) {
    echo '<b>' . e($section['title']) . "</b><br/>\n";     // the first question of a section
}
if ($flagged) {
    echo wap_icon('flag') . "Flagged<br/>\n";
}
if ((float)$slot['mark'] !== 1.0 && $q['type'] !== 'description') {
    echo e(fmt_num($slot['mark']) . ' marks') . "<br/>\n";
}
echo wap_rich($text, $q['format'] ?? 'moodle', $id, $blanks) . "<br/>\n";
echo $fields;
echo '<br/><anchor>' . ($last ? 'Finish&gt;' : 'Next&gt;') . $leave('next') . "</anchor><br/>\n";
wml_end($cards);
