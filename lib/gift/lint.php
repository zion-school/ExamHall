<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Quality checks on parsed GIFT questions: things that import fine but are
 * likely mistakes, or break rules in the Moodle GIFT documentation.
 */

defined('EXAMS_APP') or exit;

final class GiftLint
{
    /** Unambiguous tag names used in our bank and the question type they imply. */
    private const TAG_TYPES = [
        'true-false' => 'truefalse', 'true false' => 'truefalse', 'truefalse' => 'truefalse', 'true or false' => 'truefalse',
        'match' => 'match', 'matching' => 'match', 'match the following' => 'match',
        'numerical' => 'numerical',
        'choose' => 'multichoice', 'multiple choice' => 'multichoice', 'mcq' => 'multichoice',
    ];

    /** Phrases that ask the student to look at something that must be shown. */
    private const PICTURE_WORDS = '/\b(look at|observe|see|in|from|using|study|about|name|label) (the|this|these) (given |following |above |below )?(picture|pictures|figure|figures|diagram|image|images|photo|map|calendar|clock|graph|chart|table)\b|\b(picture|figure|diagram|image|photo)s? (below|above|given|shown)\b|\bshown (below|above)\b|\bidentify (this|these|the (picture|image|object|part))\b|\bfig\.\s*\d/i';

    /** @return array list of messages (same shape as GiftParser messages) */
    public static function check(array $questions, string $file = ''): array
    {
        $messages = [];
        $add = function (string $level, array $q, string $message) use (&$messages, $file) {
            $messages[] = [
                'level' => $level, 'file' => $file, 'line' => $q['line'] ?? 0,
                'message' => $message, 'excerpt' => mb_substr(self::plain($q['name'] ?? ''), 0, 160),
            ];
        };

        $seenText = [];
        foreach ($questions as $q) {
            $type = $q['type'];
            $text = $q['text'] ?? '';
            $plain = self::plain($text);

            // Same question twice in one file
            $key = $type . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', $plain))
                . '|' . json_encode([$q['answers'] ?? null, $q['pairs'] ?? null, $q['correct'] ?? null], JSON_UNESCAPED_UNICODE);
            if ($plain !== '' && isset($seenText[$key])) {
                $add('warning', $q, "Same question text as line {$seenText[$key]}.");
            } else {
                $seenText[$key] = $q['line'] ?? 0;
            }

            // Tag says one type, syntax gives another
            foreach ($q['tags'] ?? [] as $tag) {
                $expected = self::TAG_TYPES[mb_strtolower(trim($tag))] ?? null;
                if ($expected !== null && $expected !== $type) {
                    $add('warning', $q, "Tagged \"$tag\" but the answer syntax makes it a " . self::typeLabel($type) . ' question.');
                }
            }

            // Mentions a picture but has none
            if (preg_match(self::PICTURE_WORDS, $plain) && !self::hasPicture($q)) {
                $add('warning', $q, 'Mentions a picture/figure but the question has no image.');
            }

            // Missing-word blank inserted into text that already has a blank
            if (!empty($q['_blank_in_text'])) {
                $add('warning', $q, 'The answer block is in the middle of the sentence and the text also has ___ blanks, so students see two blanks.');
            }

            // A multi-blank question is checked blank by blank
            $parts = $type === 'multiblank'
                ? array_map(fn($blank) => $blank + ['line' => $q['line'] ?? 0, 'name' => $q['name'] ?? '', 'text' => ''], $q['blanks'])
                : [$q];
            foreach ($parts as $part) {
                self::checkAnswers($part, $plain, $add);
            }
        }
        return $messages;
    }

    private static function checkAnswers(array $q, string $plain, callable $add): void
    {
        $type = $q['type'];
        switch ($type) {
            case 'essay':
                if (preg_match('/_{3,}/', $plain)) {
                    $add('warning', $q, 'Has a ___ blank but no answers {} , so it becomes an essay that must be marked by hand.');
                }
                break;

            case 'multichoice':
                $texts = [];
                foreach ($q['answers'] as $a) {
                    $k = mb_strtolower(trim(self::plain($a['text']))) ?: $a['text'];
                    if (isset($texts[$k])) {
                        $add('warning', $q, 'Two choices have the same text: "' . self::plain($a['text']) . '".');
                    }
                    $texts[$k] = true;
                    if (($a['feedback'] ?? '') !== '') {
                        $add('info', $q, 'Choice "' . self::plain($a['text']) . '" has feedback "' . self::plain($a['feedback']) . '" after #. If # is part of the choice, write \\#.');
                    }
                }
                if (empty($q['single'])) {
                    $sum = array_sum(array_map(fn($a) => max(0, (float)$a['fraction']), $q['answers']));
                    if ($sum > 1.0001) {
                        $add('warning', $q, 'Multiple-answer weights add up to ' . round($sum * 100, 2) . '% (the GIFT documentation requires at most 100%).');
                    }
                }
                break;

            case 'match':
                $count = count(array_filter($q['pairs'], fn($p) => $p['question'] !== ''));
                if ($count < 3) {
                    $add('warning', $q, "Only $count matching pairs; the GIFT documentation asks for at least three.");
                }
                $lefts = [];
                foreach ($q['pairs'] as $p) {
                    $k = mb_strtolower(trim(self::plain($p['question'])));
                    if ($k !== '' && isset($lefts[$k])) {
                        $add('warning', $q, 'The left side "' . self::plain($p['question']) . '" appears twice.');
                    }
                    $lefts[$k] = true;
                }
                break;

            case 'shortanswer':
                $longest = 0;
                foreach ($q['answers'] as $a) {
                    if (preg_match('#\p{L}\s*/\s*\p{L}|\sor\s#iu', $a['text']) && !str_contains($a['text'], '*') && mb_strlen($a['text']) < 60) {
                        $add('info', $q, 'Answer "' . $a['text'] . '" contains "/" or "or". If these are alternatives, list them as separate =answers; otherwise students must type it exactly.');
                    }
                    if (($a['feedback'] ?? '') !== '') {
                        $add('info', $q, 'Answer "' . $a['text'] . '" has feedback "' . $a['feedback'] . '" after #. If # is part of the answer, write \\#.');
                    }
                    $longest = max($longest, count(preg_split('/\s+/u', trim($a['text']), -1, PREG_SPLIT_NO_EMPTY)));
                }
                if ($longest > 6) {
                    $add('info', $q, "Long exact-match answer ($longest words). Students rarely type it word for word; consider LLM grading or an essay.");
                }
                break;
        }
    }

    public static function typeLabel(string $type): string
    {
        return [
            'multichoice' => 'multiple choice', 'truefalse' => 'true/false', 'shortanswer' => 'short answer',
            'numerical' => 'numerical', 'match' => 'matching', 'essay' => 'essay', 'description' => 'description',
            'multiblank' => 'multi-blank',
        ][$type] ?? $type;
    }

    private static function plain(string $html): string
    {
        return html_to_text($html);
    }

    private static function hasPicture(array $q): bool
    {
        $all = json_encode($q, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (bool)preg_match('/<(img|svg)\b|!\[[^\]]*\]\(|@@PLUGINFILE@@/i', $all);
    }
}
