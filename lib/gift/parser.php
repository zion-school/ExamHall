<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * GIFT parser that behaves like Moodle 4.5's GIFT import (qformat_gift).
 * Written independently from the published format description and Moodle's
 * observable import behaviour; no Moodle code is included.
 *
 * Output question arrays (see lib/questions.php for the stored schema):
 *   type        description|essay|multichoice|truefalse|shortanswer|numerical|match
 *   name, text, format (moodle|html|plain|markdown), general_feedback, general_feedback_format
 *   idnumber, tags[], default_mark, category (raw $CATEGORY text or null), line, section,
 *   block [first line, last line] (comments included)
 *   + type-specific fields.
 *
 * Zion settings in comments (Moodle ignores them), see docs/gift.md:
 *   // [zion:moodle=567 mark=2 hidden case noshuffle single|multi]   // [key:reference answer]
 * are applied to the question; the Moodle entry comes back as zion => [moodle].
 *
 * Deliberate differences from Moodle (reported as messages instead of importing silently):
 *   - several {answer blocks} in one question become a "multiblank" question, a Zion Exams
 *     extension (Moodle keeps only the first block and shows the rest as text); see docs/gift.md
 *   - a non-numeric numerical answer is an error (Moodle turns it into "*": any answer is right)
 *   - wrong-case [Tag:..] comments, lowercase {true}, tags lost behind a blank line: warnings
 */

defined('EXAMS_APP') or exit;

final class GiftParser
{
    public const FORMATS = ['moodle', 'html', 'plain', 'markdown'];

    private const WEIGHT_RE = '/^%\-*([0-9]{1,2})\.?([0-9]*)%/';

    /** Escape sequences and the private-use placeholders used while parsing. */
    private const ESCAPES = [
        '\\:' => "\u{E001}",
        '\\#' => "\u{E002}",
        '\\=' => "\u{E003}",
        '\\{' => "\u{E004}",
        '\\}' => "\u{E005}",
        '\\~' => "\u{E006}",
        '\\n' => "\u{E007}",
    ];
    private const UNESCAPED = [
        "\u{E001}" => ':',
        "\u{E002}" => '#',
        "\u{E003}" => '=',
        "\u{E004}" => '{',
        "\u{E005}" => '}',
        "\u{E006}" => '~',
        "\u{E007}" => "\n",
    ];
    private const BACKSLASH = "\u{E000}";

    private string $file = '';
    private array $messages = [];

    /**
     * Parse GIFT file content.
     * @return array{questions: array, messages: array}
     */
    public function parse(string $content, string $file = ''): array
    {
        $this->file = $file;
        $this->messages = [];

        [$content, $encodingNote] = self::decode($content);
        if ($encodingNote) {
            $this->message('warning', 1, $encodingNote);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (trim($content) === '') {
            $this->message('warning', 1, 'The file is empty.');
            return ['questions' => [], 'messages' => $this->messages];
        }

        $questions = [];
        $category = null;
        $section = '';
        $block = [];
        $lines = explode("\n", $content);
        $lines[] = '';
        foreach ($lines as $i => $raw) {
            $line = trim($raw);
            if ($line !== '' && $line !== '0') {   // Moodle treats a line of just "0" as blank (PHP empty())
                $block[] = [$i + 1, $line];
                continue;
            }
            if (!$block) {
                continue;
            }
            $result = $this->readBlock($block, $section);
            $block = [];
            if ($result === null) {
                continue;
            }
            if ($result['type'] === 'category') {
                $category = $result['category'];
                $section = '';
                continue;
            }
            $result['category'] = $category;
            $questions[] = $result;
        }
        return ['questions' => $questions, 'messages' => $this->messages];
    }

    /** UTF-8 check with Windows-1252 fallback; strips a BOM. */
    public static function decode(string $bytes): array
    {
        $note = null;
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
            $bytes = is_string($converted) ? $converted : $bytes;
            $note = 'The file is not UTF-8; it was read as Windows-1252. Save GIFT files as UTF-8.';
        }
        return [$bytes, $note];
    }

    private function message(string $level, int $line, string $message, string $excerpt = ''): void
    {
        $this->messages[] = [
            'level' => $level,
            'file' => $this->file,
            'line' => $line,
            'message' => $message,
            'excerpt' => mb_substr(self::unescape($excerpt), 0, 160),
        ];
    }

    /** Describe backslashes that are not followed by an escapable character. */
    private static function strayBackslashes(string $s): array
    {
        $found = [];
        $n = strlen($s);
        $depth = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($s[$i] === '{' || $s[$i] === '}') {
                $depth = max(0, $depth + ($s[$i] === '{' ? 1 : -1));
            }
            if ($s[$i] !== '\\') {
                continue;
            }
            $next = $i + 1 < $n ? $s[$i + 1] : '';
            // \* inside answers is a star in a short answer (not Moodle's wildcard)
            if ($next === '\\' || ($next !== '' && str_contains(':#={}~n', $next)) || ($next === '*' && $depth > 0)) {
                $i++;
                continue;
            }
            $found[] = $next === '' || $next === "\n" ? 'the end of a line' : '"' . mb_substr(substr($s, $i + 1, 4), 0, 1) . '"';
        }
        return array_values(array_unique($found));
    }

    private static function preEscape(string $s): string
    {
        $s = str_replace('\\\\', self::BACKSLASH, $s);
        $s = strtr($s, self::ESCAPES);
        return str_replace(self::BACKSLASH, '\\', $s);
    }

    private static function unescape(string $s): string
    {
        return strtr($s, self::UNESCAPED);
    }

    /** "[html]text" -> [text, format]; unknown [..] prefixes stay part of the text. */
    private static function textWithFormat(string $text, string $default): array
    {
        $format = $default;
        if (str_starts_with($text, '[')) {
            $end = strpos($text, ']');
            if ($end !== false) {
                $name = substr($text, 1, $end - 1);
                if (in_array($name, self::FORMATS, true)) {
                    $format = $name;
                    $text = substr($text, $end + 1);
                }
            }
        }
        return [trim(self::unescape($text)), $format];
    }

    private static function weight(string &$answer): float
    {
        $answer = substr($answer, 1);
        $end = strpos($answer, '%');
        $weight = ((float)substr($answer, 0, $end)) / 100;
        $answer = substr($answer, $end + 1);
        return $weight;
    }

    /** "answer#feedback" -> [answerText, answerFormat, feedbackText, feedbackFormat] */
    private static function answerAndFeedback(string $answer, string $default): array
    {
        $bits = explode('#', $answer, 2);
        [$text, $format] = self::textWithFormat(trim($bits[0]), $default);
        [$fb, $fbFormat] = isset($bits[1]) ? self::textWithFormat(trim($bits[1]), $default) : ['', $default];
        return [$text, $format, $fb, $fbFormat];
    }

    /** Split an answer block on a separator, dropping an empty first piece (Moodle behaviour). */
    private static function splitAnswers(string $answertext, string $separator): array
    {
        $answers = explode($separator, $answertext);
        $answers[0] = trim($answers[0]);
        if ($answers[0] === '') {
            array_shift($answers);
        }
        return $answers;
    }

    /**
     * A name is plain text: tags are dropped and entities decoded, so ::The &lt;ol&gt; tag::
     * is stored as "The <ol> tag" (Moodle shows it that way too).
     */
    public static function cleanName(string $name): string
    {
        return self::shortName(html_to_text($name, ''), 255);
    }

    public static function defaultName(string $text): string
    {
        $plain = html_to_text($text, '');
        if (mb_strlen($plain) > 80) {
            $cut = mb_substr($plain, 0, 77);
            $space = mb_strrpos($cut, ' ');
            $plain = ($space !== false && $space > 40 ? mb_substr($cut, 0, $space) : $cut) . '...';
        }
        return self::shortName($plain, 255) ?: 'Question';
    }

    private static function shortName(string $name, int $max): string
    {
        return mb_strlen($name) > $max ? mb_substr($name, 0, $max - 3) . '...' : $name;
    }

    /** Short answers and matching answers are plain text: decode entities such as &lt; and &amp;. */
    public static function plainAnswer(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** [idnumber, tags[]] from comment lines, like Moodle ([id:..] once, [tag:..] many; case-sensitive). */
    public static function idAndTags(string $comments): array
    {
        $id = '';
        if (preg_match('/\[id:((?:\\\\\]|[^\]\x00-\x1F\x7F])+)\]/u', $comments, $m)) {
            $id = str_replace('\]', ']', trim($m[1]));
        }
        $tags = [];
        if (preg_match_all('/\[tag:((?:\\\\\]|[^\]<>`\x00-\x1F\x7F])*)\]/u', $comments, $mm)) {
            foreach ($mm[1] as $raw) {
                $tag = str_replace('\]', ']', trim($raw));
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }
        return [$id, $tags];
    }

    /**
     * One blank-line-separated block. Returns a question array, a ['type' => 'category'] marker, or null.
     * @param array $block list of [lineNo, trimmedLine]
     */
    private function readBlock(array $block, string &$section): ?array
    {
        $firstLine = $block[0][0];
        $comments = '';
        $textLines = [];
        $textStart = null;
        foreach ($block as [$no, $line]) {
            if (str_starts_with($line, '//')) {
                $comments .= $line . "\n";
                $textLines[] = ' ';
                if (preg_match('#^//\s*={2,}\s*(.+?)\s*={2,}\s*$#u', $line, $m)) {
                    $section = $m[1];
                }
                if (preg_match_all('/\[\s*(tag|id)\s*:/i', $line, $tm)) {
                    foreach ($tm[0] as $token) {
                        if ($token !== '[tag:' && $token !== '[id:') {
                            $this->message('warning', $no, "\"$token\" is ignored: Moodle only reads lower-case [tag:...] and [id:...] with no spaces.", $line);
                        }
                    }
                }
            } else {
                $textLines[] = $line;
                $textStart ??= $no;
            }
        }
        $text = trim(implode("\n", $textLines));
        if ($text === '') {
            // Tags followed by a blank line (but not a whole commented-out question)
            if (preg_match('/\[(tag|id):/', $comments) && !preg_match('#//\s*(::|.*\{)#', $comments)) {
                $this->message('warning', $firstLine, 'These tags are separated from their question by a blank line, so Moodle ignores them.', trim($comments));
            }
            return null;
        }
        $line = $textStart ?? $firstLine;
        $original = $text;
        foreach (self::strayBackslashes($original) as $what) {
            $this->message('warning', $line, "Stray backslash before $what: it is not a GIFT escape, so the backslash will show in the question. Only \\~ \\= \\# \\{ \\} \\: \\n and \\\\ are escapes.", $original);
        }
        $text = self::preEscape($text);

        if (str_starts_with($text, '$CATEGORY:')) {
            if (str_contains($text, "\n")) {
                $this->message('warning', $line, 'Put a blank line after the $CATEGORY line; Moodle reads the following lines as part of the category name.', $original);
            }
            return ['type' => 'category', 'category' => trim(substr($text, 10))];
        }

        $name = null;
        if (str_starts_with($text, '::')) {
            $text = substr($text, 2);
            $end = strpos($text, '::');
            if ($end !== false) {
                if (str_contains(substr($text, 0, $end), "\n")) {
                    $this->message('warning', $line, 'The question name ::...:: is split over two lines.', $original);
                }
                $name = self::cleanName(self::unescape(substr($text, 0, $end)));
                $text = trim(substr($text, $end + 2));
            }
        }

        $blocks = self::findBlocks($text);
        if ($blocks === null) {
            $this->message('error', $line, 'Unmatched { or } in the question (escape literal braces as \\{ \\}).', $original);
            return null;
        }
        [$idnumber, $tags] = self::idAndTags($comments);
        if (count($blocks) > 1) {
            $q = $this->readMultiBlank($text, $blocks, $name, $idnumber, $tags, $comments, $line, $section, $original);
            return $q === null ? null : $this->applyMeta($q, $comments, $block);
        }

        $description = !$blocks;
        $start = $length = 0;
        $answertext = '';
        if (!$description) {
            [$start, $length] = $blocks[0];
            $answertext = trim(substr($text, $start + 1, $length - 1));
        }

        if ($description) {
            $questiontext = $text;
        } elseif (str_ends_with($text, '}')) {
            $questiontext = substr_replace($text, '', $start, $length + 1);
        } else {
            $questiontext = substr_replace($text, '_____', $start, $length + 1);
        }

        $generalFeedback = '';
        $gf = strrpos($answertext, '####');
        if ($gf !== false) {
            $generalFeedback = substr($answertext, $gf + 4);
            $answertext = trim(substr($answertext, 0, $gf));
        }

        [$qtext, $format] = self::textWithFormat($questiontext, 'moodle');
        [$gfText, $gfFormat] = self::textWithFormat($generalFeedback, $format);

        $q = [
            'type' => null,
            'name' => $name ?? self::defaultName($qtext),
            'text' => $qtext,
            'format' => $format,
            'general_feedback' => $gfText,
            'general_feedback_format' => $gfFormat,
            'idnumber' => $idnumber,
            'tags' => $tags,
            'default_mark' => 1,
            'line' => $line,
            'section' => $section,
            'missing_word' => !$description && !str_ends_with($text, '}'),
        ];
        if (str_contains($comments, 'TODO')) {
            $q['todo'] = true;
        }
        if ($q['missing_word'] && preg_match('/_{3,}/', substr_replace($text, '', $start, $length + 1))) {
            $q['_blank_in_text'] = true;   // transient hint for GiftLint, not stored
        }
        if ($q['general_feedback_format'] === $format || $gfText === '') {
            unset($q['general_feedback_format']);
        }

        if ($description) {
            $q['type'] = 'description';
            $q['default_mark'] = 0;
            return $this->applyMeta($q, $comments, $block);
        }
        $q['type'] = $this->detectType($answertext, $line, $original);
        $fields = $this->parseAnswers($q['type'], $answertext, $format, $line, $original);
        return $fields === null ? null : $this->applyMeta(array_merge($q, $fields), $comments, $block);
    }

    /**
     * Zion settings from comment lines:
     * ['moodle' => ?int, 'mark' => ?float, 'hidden', 'case', 'noshuffle' => bool,
     *  'single' => ?bool, 'key' => ?string, 'unknown' => [tokens]]
     */
    public static function zionMeta(string $comments): array
    {
        $meta = ['moodle' => null, 'mark' => null, 'hidden' => false, 'case' => false,
            'noshuffle' => false, 'single' => null, 'key' => null, 'unknown' => []];
        if (preg_match('/\[zion:([^\]]*)\]/', $comments, $m)) {
            foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $token) {
                if (preg_match('/^moodle=(\d+)$/', $token, $v)) {
                    $meta['moodle'] = (int)$v[1];
                } elseif (preg_match('/^mark=(\d+(?:\.\d+)?)$/', $token, $v)) {
                    $meta['mark'] = (float)$v[1];
                } elseif (in_array($token, ['hidden', 'case', 'noshuffle'], true)) {
                    $meta[$token] = true;
                } elseif ($token === 'single' || $token === 'multi') {
                    $meta['single'] = $token === 'single';
                } else {
                    $meta['unknown'][] = $token;
                }
            }
        }
        if (preg_match('/\[key:((?:\\\\.|[^\]\\\\])*)\]/', $comments, $m)) {
            $meta['key'] = strtr($m[1], ['\\\\' => '\\', '\\]' => ']', '\\n' => "\n"]);
        }
        return $meta;
    }

    /** Apply [zion:...] and [key:...] settings; remember the block's lines. */
    private function applyMeta(array $q, string $comments, array $block): array
    {
        $q['block'] = [$block[0][0], $block[count($block) - 1][0]];
        if (!str_contains($comments, '[zion:') && !str_contains($comments, '[key:')) {
            return $q;
        }
        $meta = self::zionMeta($comments);
        if ($meta['unknown']) {
            $this->message('warning', $q['line'], 'Unknown Zion setting(s) ignored: ' . implode(' ', $meta['unknown']) . '. Known: moodle=567 mark=2 hidden case noshuffle single multi.');
        }
        if ($meta['moodle'] !== null) {
            $q['zion'] = ['moodle' => $meta['moodle']];
        }
        if ($meta['mark'] !== null) {
            $q['default_mark'] = $meta['mark'];
        }
        if ($meta['hidden']) {
            $q['hidden'] = true;
        }
        $apply = function (array $part) use ($meta) {
            if ($meta['case'] && $part['type'] === 'shortanswer') {
                $part['usecase'] = true;
            }
            if ($meta['noshuffle'] && in_array($part['type'], ['multichoice', 'match'], true)) {
                $part['shuffle'] = false;
            }
            if ($meta['single'] !== null && $part['type'] === 'multichoice') {
                $part['single'] = $meta['single'];
            }
            return $part;
        };
        if ($q['type'] === 'multiblank') {
            $q['blanks'] = array_map($apply, $q['blanks']);
        } else {
            $q = $apply($q);
        }
        if ($meta['key'] !== null) {
            $q['grader_info'] = $meta['key'];
        }
        return $q;
    }

    /** Positions [start, length] of each {...} block, or null when braces don't pair up. */
    private static function findBlocks(string $text): ?array
    {
        $blocks = [];
        $pos = 0;
        while (true) {
            $open = strpos($text, '{', $pos);
            $close = strpos($text, '}', $pos);
            if ($open === false && $close === false) {
                return $blocks;
            }
            if ($open === false || $close === false || $close < $open) {
                return null;
            }
            $nested = strpos($text, '{', $open + 1);
            if ($nested !== false && $nested < $close) {
                return null;
            }
            $blocks[] = [$open, $close - $open];
            $pos = $close + 1;
        }
    }

    /** Question type from the answer block text, using Moodle's rules. */
    private function detectType(string $answertext, int $line, string $original): string
    {
        if ($answertext === '') {
            return 'essay';
        }
        if ($answertext[0] === '#') {
            return 'numerical';
        }
        if (str_contains($answertext, '~')) {
            return 'multichoice';
        }
        if (str_contains($answertext, '=') && str_contains($answertext, '->')) {
            return 'match';
        }
        $check = $answertext;
        $hash = strpos($answertext, '#');
        if ($hash !== false && $hash > 0) {
            $check = trim(substr($answertext, 0, $hash));
        }
        if (in_array($check, ['T', 'TRUE', 'F', 'FALSE'], true)) {
            return 'truefalse';
        }
        if (in_array(strtoupper($check), ['T', 'TRUE', 'F', 'FALSE'], true)) {
            $this->message('warning', $line, "{{$check}} is imported as a short answer. Write T, TRUE, F or FALSE in capitals for a true/false question.", $original);
        }
        return 'shortanswer';
    }

    /**
     * Zion Exams extension: a question with several {...} blocks becomes one question
     * with several blanks. Text keeps {#1}, {#2}... markers where the blanks go.
     */
    private function readMultiBlank(string $text, array $blocks, ?string $name, string $idnumber, array $tags, string $comments, int $line, string $section, string $original): ?array
    {
        $questiontext = $text;
        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            [$start, $length] = $blocks[$i];
            $questiontext = substr_replace($questiontext, '{#' . ($i + 1) . '}', $start, $length + 1);
        }
        [$qtext, $format] = self::textWithFormat($questiontext, 'moodle');
        if (preg_match_all('/\{#\d+\}/', $qtext) !== count($blocks)) {
            $this->message('error', $line, 'The text contains an escaped \\{#n\\} that clashes with blank markers. Reword it.', $original);
            return null;
        }

        $blanks = [];
        $generalFeedback = '';
        foreach ($blocks as $i => [$start, $length]) {
            $n = $i + 1;
            $answertext = trim(substr($text, $start + 1, $length - 1));
            $gf = strrpos($answertext, '####');
            if ($gf !== false) {
                $generalFeedback = substr($answertext, $gf + 4);
                $answertext = trim(substr($answertext, 0, $gf));
            }
            if ($answertext === '') {
                $this->message('error', $line, "Blank $n has no answers. Every {...} in a question with several blanks needs answers.", $original);
                return null;
            }
            $type = $this->detectType($answertext, $line, $original);
            if ($type === 'match') {
                $this->message('error', $line, "Blank $n is a matching block (= ... ->); matching can't be used as one of several blanks.", $original);
                return null;
            }
            $fields = $this->parseAnswers($type, $answertext, $format, $line, $original);
            if ($fields === null) {
                return null;
            }
            $blanks[] = ['type' => $type] + $fields;
        }
        [$gfText, $gfFormat] = self::textWithFormat($generalFeedback, $format);
        $this->message('info', $line, 'Question with ' . count($blanks) . ' blanks (Zion Exams extension to GIFT, see docs/gift.md). Moodle cannot import it: it keeps only the first blank.', $original);

        $q = [
            'type' => 'multiblank',
            'name' => $name ?? self::defaultName(preg_replace('/\{#\d+\}/', '_____', $qtext)),
            'text' => $qtext,
            'format' => $format,
            'general_feedback' => $gfText,
            'general_feedback_format' => $gfFormat,
            'idnumber' => $idnumber,
            'tags' => $tags,
            'default_mark' => count($blanks),
            'line' => $line,
            'section' => $section,
            'missing_word' => false,
            'blanks' => $blanks,
        ];
        if (str_contains($comments, 'TODO')) {
            $q['todo'] = true;
        }
        if ($gfFormat === $format || $gfText === '') {
            unset($q['general_feedback_format']);
        }
        return $q;
    }

    /** Type-specific fields parsed from an answer block, or null after reporting an error. */
    private function parseAnswers(string $type, string $answertext, string $format, int $line, string $original): ?array
    {
        $q = [];
        switch ($type) {
            case 'essay':
                return ['grader_info' => ''];

            case 'multichoice':
                $q['single'] = str_contains($answertext, '=');
                $q['shuffle'] = true;
                $answers = self::splitAnswers(str_replace('=', '~=', $answertext), '~');
                if (count($answers) < 2) {
                    $this->message('error', $line, 'A multiple choice question needs at least two answers.', $original);
                    return null;
                }
                $q['answers'] = [];
                foreach ($answers as $answer) {
                    $answer = trim($answer);
                    if ($answer !== '' && $answer[0] === '=') {
                        $fraction = 1.0;
                        $answer = substr($answer, 1);
                    } elseif (preg_match(self::WEIGHT_RE, $answer)) {
                        $fraction = self::weight($answer);
                    } else {
                        $fraction = 0.0;
                    }
                    [$atext, $aformat, $fb, $fbFormat] = self::answerAndFeedback($answer, $format);
                    $q['answers'][] = self::compact([
                        'text' => $atext, 'format' => $aformat, 'fraction' => $fraction,
                        'feedback' => $fb, 'feedback_format' => $fbFormat,
                    ], $format);
                }
                $correct = array_filter($q['answers'], fn($a) => $a['fraction'] > 0);
                if (!$correct) {
                    $this->message('warning', $line, 'No answer is marked correct (=) or given a positive %weight%.', $original);
                } elseif ($q['single'] && count(array_filter($q['answers'], fn($a) => $a['fraction'] >= 1)) > 1) {
                    $this->message('warning', $line, 'More than one answer is marked correct with "="; students can still pick only one.', $original);
                }
                return $q;

            case 'match':
                $q['shuffle'] = true;
                $answers = self::splitAnswers($answertext, '=');
                if (count($answers) < 2) {
                    $this->message('error', $line, 'A matching question needs at least two pairs.', $original);
                    return null;
                }
                $q['pairs'] = [];
                foreach ($answers as $answer) {
                    $answer = trim($answer);
                    $marker = strpos($answer, '->');
                    if ($marker === false) {
                        $this->message('error', $line, 'Every matching pair needs "->" (e.g. =Cat -> Mammal).', $original);
                        return null;
                    }
                    [$left, $leftFormat] = self::textWithFormat(substr($answer, 0, $marker), $format);
                    $q['pairs'][] = self::compact([
                        'question' => $left, 'format' => $leftFormat,
                        'answer' => self::plainAnswer(trim(self::unescape(substr($answer, $marker + 2)))),
                    ], $format);
                }
                return $q;

            case 'truefalse':
                $bits = explode('#', $answertext, 3);
                [$value] = self::textWithFormat(trim($bits[0]), $format);
                [$wrong, $wrongFormat] = isset($bits[1]) ? self::textWithFormat(trim($bits[1]), $format) : ['', $format];
                [$right, $rightFormat] = isset($bits[2]) ? self::textWithFormat(trim($bits[2]), $format) : ['', $format];
                $isTrue = $value === 'T' || $value === 'TRUE';
                $q['correct'] = $isTrue;
                $q['feedback_true'] = $isTrue ? $right : $wrong;
                $q['feedback_false'] = $isTrue ? $wrong : $right;
                $q['feedback_true_format'] = $isTrue ? $rightFormat : $wrongFormat;
                $q['feedback_false_format'] = $isTrue ? $wrongFormat : $rightFormat;
                foreach (['feedback_true', 'feedback_false'] as $key) {
                    if ($q[$key . '_format'] === $format || $q[$key] === '') {
                        unset($q[$key . '_format']);
                    }
                }
                return $q;

            case 'shortanswer':
                $q['usecase'] = false;
                $answers = self::splitAnswers($answertext, '=');
                if (count($answers) < 1) {
                    $this->message('error', $line, 'A short answer question needs at least one answer.', $original);
                    return null;
                }
                $q['answers'] = [];
                foreach ($answers as $answer) {
                    $answer = trim($answer);
                    $fraction = preg_match(self::WEIGHT_RE, $answer) ? self::weight($answer) : 1.0;
                    [$atext, , $fb, $fbFormat] = self::answerAndFeedback($answer, $format);
                    $atext = self::plainAnswer($atext);
                    $q['answers'][] = self::compact([
                        'text' => $atext, 'fraction' => $fraction, 'feedback' => $fb, 'feedback_format' => $fbFormat,
                    ], $format);
                    if (preg_match('/(?<!\\\\)\*/', $atext)) {
                        $this->message('info', $line, "Answer \"$atext\" contains *, which matches any characters (Moodle wildcard).", $original);
                    }
                }
                if (!str_contains($answertext, '=') && str_contains($answertext, '->')) {
                    $this->message('warning', $line, 'This looks like a matching question but has no "=" before the pairs, so it is imported as one short answer.', $original);
                }
                return $q;

            case 'numerical':
                $body = substr($answertext, 1);
                $wrongFeedback = null;
                $tilde = strpos($body, '~');
                if ($tilde !== false) {
                    $wrongFeedback = substr($body, $tilde);
                    $body = substr($body, 0, $tilde);
                }
                $answers = self::splitAnswers($body, '=');
                if (!$answers) {
                    $this->message('error', $line, 'A numerical question needs at least one number.', $original);
                    return null;
                }
                $q['answers'] = [];
                foreach ($answers as $answer) {
                    $answer = trim($answer);
                    $fraction = preg_match(self::WEIGHT_RE, $answer) ? self::weight($answer) : 1.0;
                    [$atext, , $fb, $fbFormat] = self::answerAndFeedback($answer, $format);
                    $range = strpos($atext, '..');
                    $colon = strpos($atext, ':');
                    if ($range !== false && $range > 0) {
                        $min = trim(substr($atext, 0, $range));
                        $max = trim(substr($atext, $range + 2));
                        if (!is_numeric($min) || !is_numeric($max)) {
                            $this->message('error', $line, "\"$atext\" is not a valid number range (use min..max).", $original);
                            return null;
                        }
                        $value = ((float)$max + (float)$min) / 2;
                        $tolerance = (float)$max - $value;
                    } elseif ($colon !== false && $colon > 0) {
                        $value = trim(substr($atext, 0, $colon));
                        $tolerance = trim(substr($atext, $colon + 1));
                        if (!is_numeric($value) || !is_numeric($tolerance)) {
                            $this->message('error', $line, "\"$atext\" is not a valid number with tolerance (use answer:tolerance).", $original);
                            return null;
                        }
                    } else {
                        $value = $atext;
                        $tolerance = 0;
                        if (!is_numeric($value) && $value !== '*') {
                            $this->message('error', $line, "\"$atext\" is not a number. (Moodle would silently accept any answer here.)", $original);
                            return null;
                        }
                    }
                    $q['answers'][] = self::compact([
                        'value' => $value === '*' ? '*' : (float)$value, 'tolerance' => (float)$tolerance,
                        'fraction' => $fraction, 'feedback' => $fb, 'feedback_format' => $fbFormat,
                    ], $format);
                }
                if ($wrongFeedback !== null) {
                    [, , $fb, $fbFormat] = self::answerAndFeedback($wrongFeedback, $format);
                    $q['answers'][] = self::compact([
                        'value' => '*', 'tolerance' => 0.0, 'fraction' => 0.0, 'feedback' => $fb, 'feedback_format' => $fbFormat,
                    ], $format);
                }
                return $q;
        }
        return null;
    }

    /** Drop per-field format keys that equal the question's format, and empty feedback formats. */
    private static function compact(array $item, string $questionFormat): array
    {
        foreach (['format', 'feedback_format'] as $key) {
            if (isset($item[$key]) && $item[$key] === $questionFormat) {
                unset($item[$key]);
            }
        }
        if (array_key_exists('feedback', $item) && $item['feedback'] === '') {
            unset($item['feedback'], $item['feedback_format']);
        }
        return $item;
    }
}
