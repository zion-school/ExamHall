<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * GIFT export producing what Moodle 4.5 writes (and imports back identically).
 * One improvement: a missing-word question gets its answer block written back at
 * the blank, so re-importing gives exactly the same question text.
 * Multi-blank questions (Zion Exams extension, docs/gift.md) are written with an
 * inline answer block at each blank; Moodle cannot import those.
 *
 * Options for question()/file():
 *   header  true   Moodle's "// question: <id>  name: ..." comment line
 *   meta    []     which Zion settings to write in a comment Moodle ignores:
 *                  // [zion:moodle=567 mark=2 hidden case noshuffle single|multi]  // [key:...]
 *                  names: moodle, mark, hidden, case, noshuffle, single, key
 */

defined('EXAMS_APP') or exit;

final class GiftWriter
{
    /** Escape GIFT control characters; newlines become \n. */
    public static function escape(string $text): string
    {
        return str_replace(
            ['\\', '#', '=', '~', '{', '}', ':', "\n", "\r"],
            ['\\\\', '\#', '\=', '\~', '\{', '\}', '\:', '\n', ''],
            $text
        );
    }

    /** Names are plain text; write what would be read as a tag or an entity as entities. */
    private static function plainName(string $name): string
    {
        $name = preg_replace('/&(?=#?[a-z0-9]+;)/i', '&amp;', $name);
        return preg_match('#<[a-z/!?]#i', $name) ? str_replace(['<', '>'], ['&lt;', '&gt;'], $name) : $name;
    }

    /** Short answers and matching answers are plain text: protect an "&" that looks like an entity. */
    private static function plainAnswer(string $text): string
    {
        return preg_replace('/&(?=#?[a-z0-9]+;)/i', '&amp;', $text);
    }

    private static function text(string $text, string $format, string $default = 'moodle'): string
    {
        $out = '';
        if ($text !== '' && $format !== $default) {
            $out .= '[' . $format . ']';
        }
        return $out . self::escape($text);
    }

    private static function generalFeedback(array $q, string $indent): string
    {
        $gf = self::text($q['general_feedback'] ?? '', $q['general_feedback_format'] ?? $q['format'], $q['format']);
        if ($gf === '') {
            return '';
        }
        return $indent === '' ? '####' . $gf : $indent . '####' . $gf . "\n";
    }

    private static function percent(float $fraction): string
    {
        $p = round($fraction * 100, 5);
        return rtrim(rtrim(number_format($p, 5, '.', ''), '0'), '.');
    }

    private static function number($value): string
    {
        if ($value === '*') {
            return '*';
        }
        $s = rtrim(rtrim(number_format((float)$value, 10, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    public const ALL_META = ['moodle', 'mark', 'hidden', 'case', 'noshuffle', 'single', 'key'];
    /** Settings kept by exports: everything except the Moodle entry. */
    public const SETTINGS_META = ['mark', 'hidden', 'case', 'noshuffle', 'single', 'key'];

    /** Write questions grouped by category: [category => [questions]] (category '' = none). */
    public static function file(array $byCategory, array $options = []): string
    {
        $out = '';
        foreach ($byCategory as $category => $questions) {
            if ($category !== '' && $category !== null) {
                $out .= '$CATEGORY: ' . $category . "\n\n";
            }
            foreach ($questions as $q) {
                $out .= self::question($q, $options) . "\n";
            }
        }
        return $out;
    }

    /** The mark a question gets when GIFT does not say (what GiftParser gives it). */
    public static function defaultMark(array $q): float
    {
        return match ($q['type'] ?? '') {
            'description' => 0.0,
            'multiblank' => (float)count($q['blanks'] ?? []),
            default => 1.0,
        };
    }

    /** Tokens of the [zion:...] comment for $q, limited to $names. */
    public static function metaTokens(array $q, array $names): array
    {
        $tokens = [];
        $parts = ($q['type'] ?? '') === 'multiblank' ? ($q['blanks'] ?? []) : [$q];
        if (in_array('moodle', $names, true) && !empty($q['moodle'])) {
            $tokens[] = 'moodle=' . (int)$q['moodle'];
        }
        if (in_array('mark', $names, true) && isset($q['default_mark']) && (float)$q['default_mark'] != self::defaultMark($q)) {
            $tokens[] = 'mark=' . self::number($q['default_mark']);
        }
        if (in_array('hidden', $names, true) && !empty($q['hidden'])) {
            $tokens[] = 'hidden';
        }
        if (in_array('case', $names, true) && array_filter($parts, fn($p) => ($p['type'] ?? '') === 'shortanswer' && !empty($p['usecase']))) {
            $tokens[] = 'case';
        }
        if (in_array('noshuffle', $names, true) && array_filter($parts, fn($p) => in_array($p['type'] ?? '', ['multichoice', 'match'], true) && isset($p['shuffle']) && !$p['shuffle'])) {
            $tokens[] = 'noshuffle';
        }
        if (in_array('single', $names, true) && ($q['type'] ?? '') === 'multichoice' && isset($q['single'])) {
            // GIFT reads "single" from an "=" answer, which is only written for a 100% answer
            $inferred = (bool)$q['single'] && (bool)array_filter($q['answers'] ?? [], fn($a) => (float)$a['fraction'] == 1.0);
            if ((bool)$q['single'] !== $inferred) {
                $tokens[] = $q['single'] ? 'single' : 'multi';
            }
        }
        return $tokens;
    }

    /** The "// [tag:..] [zion:..]" and "// [key:..]" comment lines of a question. */
    public static function commentLines(array $q, array $meta): string
    {
        $out = '';
        $bits = [];
        if (($q['idnumber'] ?? '') !== '') {
            $bits[] = '[id:' . str_replace(']', '\]', $q['idnumber']) . ']';
        }
        foreach ($q['tags'] ?? [] as $tag) {
            $bits[] = '[tag:' . str_replace(']', '\]', $tag) . ']';
        }
        $tokens = self::metaTokens($q, $meta);
        if ($tokens) {
            $bits[] = '[zion:' . implode(' ', $tokens) . ']';
        }
        if ($bits) {
            $out .= '// ' . implode(' ', $bits) . "\n";
        }
        if (in_array('key', $meta, true) && trim((string)($q['grader_info'] ?? '')) !== '') {
            $out .= '// [key:' . str_replace(['\\', ']', "\r", "\n"], ['\\\\', '\]', '', '\n'], trim($q['grader_info'])) . "]\n";
        }
        return $out;
    }

    public static function question(array $q, array $options = []): string
    {
        $format = $q['format'] ?? 'moodle';
        $name = (string)($q['name'] ?? '');
        $out = ($options['header'] ?? true) ? '// question: ' . ($q['id'] ?? '0') . '  name: ' . str_replace(["\r", "\n"], ' ', $name) . "\n" : '';
        $out .= self::commentLines($q, $options['meta'] ?? []);

        $head = '::' . self::escape(self::plainName($name)) . '::';
        $text = self::text($q['text'] ?? '', $format);

        if ($q['type'] === 'description') {
            return $out . $head . $text . "\n";
        }
        if ($q['type'] === 'multiblank') {
            // Zion Exams extension: each {#n} marker becomes an inline answer block
            $last = count($q['blanks']);
            $text = $format !== 'moodle' && ($q['text'] ?? '') !== '' ? '[' . $format . ']' : '';
            foreach (preg_split('/(\{#\d+\})/', $q['text'] ?? '', -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
                $blank = preg_match('/^\{#(\d+)\}$/', $piece, $m) ? ($q['blanks'][(int)$m[1] - 1] ?? null) : null;
                if ($blank === null) {
                    $text .= self::escape($piece);
                    continue;
                }
                $gf = (int)$m[1] === $last ? self::generalFeedback($q, '') : '';
                $text .= self::block($blank, $format, true, $gf);
            }
            return $out . $head . $text . "\n";
        }

        $inlineGf = in_array($q['type'], ['essay', 'truefalse'], true);
        $block = self::block($q, $format, false, self::generalFeedback($q, $inlineGf ? '' : "\t"));
        if ($block === null) {
            return $out . '// Unsupported question type: ' . $q['type'] . "\n";
        }
        $blankAt = !empty($q['missing_word']) ? strpos($text, '_____') : false;
        if ($blankAt !== false) {
            return $out . $head . substr_replace($text, $block, $blankAt, 5) . "\n";
        }
        return $out . $head . $text . $block . "\n";
    }

    /**
     * The {...} answer block of a question (or of one blank).
     * $inline writes everything on one line, for blanks inside a sentence.
     */
    private static function block(array $q, string $format, bool $inline, string $generalFeedback): ?string
    {
        $open = $inline ? '' : "\n";
        $item = $inline ? ' ' : "\t";
        $end = $inline ? '' : "\n";
        $tail = $inline && $generalFeedback !== '' ? ' ' . ltrim($generalFeedback) : $generalFeedback;

        switch ($q['type']) {
            case 'essay':
                return '{' . $generalFeedback . '}';

            case 'truefalse':
                $correct = !empty($q['correct']);
                $right = $correct ? ($q['feedback_true'] ?? '') : ($q['feedback_false'] ?? '');
                $wrong = $correct ? ($q['feedback_false'] ?? '') : ($q['feedback_true'] ?? '');
                $rightFormat = $correct ? ($q['feedback_true_format'] ?? $format) : ($q['feedback_false_format'] ?? $format);
                $wrongFormat = $correct ? ($q['feedback_false_format'] ?? $format) : ($q['feedback_true_format'] ?? $format);
                $rightText = self::text($right, $rightFormat, $format);
                $wrongText = self::text($wrong, $wrongFormat, $format);
                $block = '{' . ($correct ? 'TRUE' : 'FALSE');
                if ($wrongText !== '') {
                    $block .= '#' . $wrongText;
                } elseif ($rightText !== '') {
                    $block .= '#';
                }
                if ($rightText !== '') {
                    $block .= '#' . $rightText;
                }
                return $block . ltrim($generalFeedback) . '}';

            case 'multichoice':
                $parts = [];
                foreach ($q['answers'] as $a) {
                    $fraction = (float)$a['fraction'];
                    if ($fraction == 1.0 && !empty($q['single'])) {
                        $prefix = '=';
                    } elseif ($fraction == 0.0) {
                        $prefix = '~';
                    } else {
                        $prefix = '~%' . self::percent($fraction) . '%';
                    }
                    $line = $prefix . self::text($a['text'], $a['format'] ?? $format, $format);
                    if (($a['feedback'] ?? '') !== '') {
                        $line .= '#' . self::text($a['feedback'], $a['feedback_format'] ?? $format, $format);
                    }
                    $parts[] = $item . $line . $end;
                }
                return '{' . $open . implode('', $parts) . $tail . ($inline ? ' ' : '') . '}';

            case 'shortanswer':
                $parts = [];
                foreach ($q['answers'] as $a) {
                    $parts[] = $item . '=%' . self::percent((float)$a['fraction']) . '%' . self::escape(self::plainAnswer($a['text']))
                        . '#' . self::text($a['feedback'] ?? '', $a['feedback_format'] ?? $format, $format) . $end;
                }
                return '{' . $open . implode('', $parts) . $tail . ($inline ? ' ' : '') . '}';

            case 'numerical':
                $parts = [];
                foreach ($q['answers'] as $a) {
                    $feedback = self::text($a['feedback'] ?? '', $a['feedback_format'] ?? $format, $format);
                    if ($a['value'] !== '*' && $a['value'] !== '') {
                        $parts[] = $item . '=%' . self::percent((float)$a['fraction']) . '%' . self::number($a['value'])
                            . ':' . self::number($a['tolerance'] ?? 0) . '#' . $feedback . $end;
                    } else {
                        $parts[] = $item . '~#' . $feedback . $end;
                    }
                }
                return '{#' . $open . implode('', $parts) . $tail . ($inline ? ' ' : '') . '}';

            case 'match':
                $parts = [];
                foreach ($q['pairs'] as $p) {
                    $parts[] = $item . '=' . self::text($p['question'], $p['format'] ?? $format, $format)
                        . ' -> ' . self::escape(self::plainAnswer($p['answer'])) . $end;
                }
                return '{' . $open . implode('', $parts) . $tail . ($inline ? ' ' : '') . '}';
        }
        return null;
    }
}
