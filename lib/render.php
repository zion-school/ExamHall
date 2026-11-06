<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Turning question text into safe HTML:
 *  - format_text(): moodle / html / plain / markdown -> sanitised HTML, picture paths rewritten
 *  - HtmlSanitizer: allowlist of tags, attributes, CSS properties and URL schemes (incl. inline SVG)
 *  - StudentHtml: the much smaller allowlist for what a student types with the formatting toolbar
 *  - render_question_preview(): read-only view of a question with its answers (for teachers)
 */

defined('EXAMS_APP') or exit;

final class HtmlSanitizer
{
    private const HTML_TAGS = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'caption', 'center', 'code', 'col', 'colgroup', 'dd', 'del', 'div', 'dl', 'dt',
        'em', 'figcaption', 'figure', 'font', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'kbd', 'li',
        'mark', 'ol', 'p', 'pre', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'tt', 'u', 'ul', 'var',
    ];
    private const SVG_TAGS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs', 'title',
        'desc', 'marker', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'pattern', 'symbol',
    ];
    /** Removed together with their content. */
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'textarea', 'select', 'button', 'input', 'noscript', 'template', 'foreignobject', 'use', 'image', 'animate', 'set', 'animatetransform', 'animatemotion', 'meta', 'link', 'base', 'frame', 'frameset'];

    private const COMMON_ATTRS = ['class', 'title', 'lang', 'dir', 'style', 'align', 'width', 'height', 'valign', 'id'];
    private const TAG_ATTRS = [
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'border'],
        'td' => ['colspan', 'rowspan', 'bgcolor'],
        'th' => ['colspan', 'rowspan', 'bgcolor', 'scope'],
        'table' => ['border', 'cellpadding', 'cellspacing', 'bgcolor'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'ol' => ['start', 'type', 'reversed'],
        'ul' => ['type'],
        'li' => ['value'],
        'font' => ['color', 'size', 'face'],
        'q' => ['cite'],
    ];
    private const SVG_ATTRS = [
        'viewbox', 'xmlns', 'fill', 'stroke', 'stroke-width', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
        'points', 'd', 'transform', 'font-size', 'font-family', 'font-weight', 'font-style', 'text-anchor',
        'dominant-baseline', 'alignment-baseline', 'opacity', 'fill-opacity', 'stroke-opacity', 'stroke-dasharray',
        'stroke-linecap', 'stroke-linejoin', 'preserveaspectratio', 'offset', 'stop-color', 'stop-opacity', 'gradientunits',
        'gradienttransform', 'marker-end', 'marker-start', 'marker-mid', 'markerwidth', 'markerheight', 'refx', 'refy',
        'orient', 'markerunits', 'dx', 'dy', 'letter-spacing', 'visibility', 'display', 'fill-rule', 'clip-path',
        'clip-rule', 'patternunits', 'rotate', 'textlength', 'vector-effect', 'version',
    ];
    private const CSS_PROPERTIES = [
        'color', 'background-color', 'text-decoration', 'text-decoration-line', 'font-weight', 'font-style', 'font-size',
        'font-family', 'text-align', 'vertical-align', 'width', 'height', 'max-width', 'border', 'border-collapse',
        'border-color', 'border-style', 'border-width', 'padding', 'margin', 'white-space', 'line-height',
        'list-style-type', 'text-transform', 'letter-spacing', 'display',
    ];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        if (!preg_match('/[<&]/', $html)) {
            return $html;
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__sanitize_root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__sanitize_root');
        if (!$root) {
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }
        self::cleanChildren($root, false);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function cleanChildren(DOMNode $node, bool $inSvg): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction || $child instanceof DOMCdataSection) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            $childInSvg = $inSvg || $tag === 'svg';
            $allowed = $childInSvg ? in_array($tag, self::SVG_TAGS, true) : in_array($tag, self::HTML_TAGS, true);
            if (!$allowed) {
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                } else {
                    // Unknown tag: keep its content, drop the tag
                    self::cleanChildren($child, $childInSvg);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                }
                continue;
            }
            self::cleanAttributes($child, $tag, $childInSvg);
            self::cleanChildren($child, $childInSvg);
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag, bool $inSvg): void
    {
        $allowed = $inSvg ? array_merge(self::SVG_ATTRS, self::COMMON_ATTRS) : array_merge(self::COMMON_ATTRS, self::TAG_ATTRS[$tag] ?? []);
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = $attr->nodeValue;
            if (!in_array($name, $allowed, true) || str_starts_with($name, 'on')) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }
            if ($name === 'style') {
                $css = self::cleanCss($value);
                $css === '' ? $el->removeAttribute($attr->nodeName) : $el->setAttribute($attr->nodeName, $css);
            } elseif ($name === 'href' || $name === 'cite') {
                if (!preg_match('#^(https?://|mailto:|\#|/|\./|[A-Za-z0-9_.-]+(/|$))#i', trim($value)) || preg_match('/^\s*(javascript|data|vbscript):/i', $value)) {
                    $el->removeAttribute($attr->nodeName);
                }
            } elseif ($name === 'src') {
                if (!self::safeImageSrc($value)) {
                    $el->removeAttribute($attr->nodeName);
                }
            } elseif ($name === 'id') {
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,40}$/', $value) || str_starts_with($value, '__')) {
                    $el->removeAttribute($attr->nodeName);
                }
            } elseif (preg_match('/url\s*\(/i', $value) && !preg_match('/^\s*url\(\s*#[A-Za-z][\w-]*\s*\)\s*$/', $value)) {
                $el->removeAttribute($attr->nodeName);
            } elseif (preg_match('/(javascript|vbscript|expression)\s*[:(]/i', $value)) {
                $el->removeAttribute($attr->nodeName);
            }
        }
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    public static function safeImageSrc(string $src): bool
    {
        $src = trim($src);
        if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#i', $src)) {
            return true;
        }
        if (preg_match('#^https?://#i', $src)) {
            return true;
        }
        // Relative paths only (no scheme, no parent segments)
        return !preg_match('#^[a-z][a-z0-9+.-]*:#i', $src) && !str_contains($src, '..') && $src !== '';
    }

    private static function cleanCss(string $css): string
    {
        $out = [];
        foreach (explode(';', $css) as $decl) {
            if (!str_contains($decl, ':')) {
                continue;
            }
            [$prop, $value] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            if (!in_array($prop, self::CSS_PROPERTIES, true) || $value === '') {
                continue;
            }
            if (preg_match('/url\s*\(|expression|javascript|@import|\\\\|<|>/i', $value)) {
                continue;
            }
            if ($prop === 'display' && !in_array(strtolower($value), ['inline', 'block', 'inline-block', 'none'], true)) {
                continue;
            }
            $out[] = "$prop: $value";
        }
        return implode('; ', $out);
    }
}

/**
 * What a student may type into a written answer (the exam player's formatting toolbar).
 * Much tighter than HtmlSanitizer: no pictures, links, tables, ids or style attributes, only the
 * tags and palette classes the toolbar makes. Colours are classes, so the printed answer sheet can
 * turn them black on a mono printer (assets/css/sheets.css).
 */
final class StudentHtml
{
    /** Everything the toolbar can make. */
    private const TAGS = ['p', 'br', 'b', 'i', 'u', 's', 'sub', 'sup', 'ul', 'ol', 'li', 'h3', 'h4', 'span'];
    /** Browsers write the same thing in several ways; keep one spelling of each. */
    private const SAME_AS = [
        'strong' => 'b', 'em' => 'i', 'strike' => 's', 'del' => 's', 'div' => 'p', 'blockquote' => 'p',
        'h1' => 'h3', 'h2' => 'h3', 'h5' => 'h4', 'h6' => 'h4', 'font' => 'span',
    ];
    /** Dropped with their content; anything else unknown keeps its words and loses the tag. */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'input', 'textarea', 'select', 'button',
        'noscript', 'template', 'svg', 'img', 'video', 'audio', 'canvas', 'link', 'meta', 'base', 'frame', 'frameset',
    ];
    /** Ink, highlight, alignment and indent classes (assets/css/player.css). */
    public const CLASSES = [
        'c-blue', 'c-red', 'c-green', 'c-purple', 'c-brown',
        'hl-yellow', 'hl-green', 'hl-blue', 'hl-pink',
        'al-center', 'al-right', 'in-1', 'in-2', 'in-3',
    ];
    private const MAX_DEPTH = 12;
    private const MAX_NODES = 3000;

    /** $limit: most letters of plain text to keep (0: no limit). Anything past it is cut off. */
    public static function clean(string $html, int $limit = 0): string
    {
        $budget = ['text' => $limit > 0 ? $limit : PHP_INT_MAX, 'nodes' => self::MAX_NODES];
        if (trim($html) === '') {
            return '';
        }
        if (!preg_match('/[<&]/', $html)) {
            return htmlspecialchars(mb_substr($html, 0, $budget['text']), ENT_QUOTES, 'UTF-8');
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__student_root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__student_root');
        if (!$root) {
            return htmlspecialchars(mb_substr($html, 0, $budget['text']), ENT_QUOTES, 'UTF-8');
        }
        self::walk($root, 0, $budget);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /** True when the answer has no words in it at all, only empty tags. */
    public static function is_blank(string $html): bool
    {
        return student_html_text($html) === '';
    }

    /**
     * An answer saved from the toolbar always starts with a block tag, which is how it is told apart
     * from a plain-text answer typed before the toolbar (or migrated from Moodle), where "<" is a
     * letter the student typed and not markup.
     */
    public static function is_formatted(string $answer): bool
    {
        return (bool)preg_match('/^<(p|ul|ol|h3|h4)[\s>]/i', ltrim($answer));
    }

    /** Clean, and start it with a block tag so it is known as formatted when it is read back. */
    public static function store(string $html, int $limit = 0): string
    {
        $clean = self::clean($html, $limit);
        if ($clean === '' || self::is_formatted($clean)) {
            return $clean;
        }
        // Plain text (a page with no JavaScript, or an older answer being saved again): its line
        // ends are what the student typed, so they become the line breaks of the paragraph.
        return '<p>' . preg_replace('/\r?\n/', '<br>', $clean) . '</p>';
    }

    private static function walk(DOMNode $node, int $depth, array &$budget): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($budget['text'] <= 0 || $budget['nodes'] <= 0) {
                $node->removeChild($child);
                continue;
            }
            if ($child instanceof DOMText) {
                $len = mb_strlen($child->nodeValue);
                if ($len > $budget['text']) {
                    $child->nodeValue = mb_substr($child->nodeValue, 0, $budget['text']);
                }
                $budget['text'] -= $len;
                continue;
            }
            if (!$child instanceof DOMElement) {   // comment, processing instruction, CDATA
                $node->removeChild($child);
                continue;
            }
            $budget['nodes']--;
            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            $tag = self::SAME_AS[$tag] ?? $tag;
            $class = self::keepClasses($child->getAttribute('class'));
            // Unknown tag, nested too deeply, or a <span> left with no colour: keep the words, drop the tag
            if (!in_array($tag, self::TAGS, true) || $depth >= self::MAX_DEPTH || ($tag === 'span' && $class === '')) {
                self::walk($child, $depth, $budget);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            $el = $child;
            if ($tag !== strtolower($child->nodeName)) {
                $el = $child->ownerDocument->createElement($tag);
                while ($child->firstChild) {
                    $el->appendChild($child->firstChild);
                }
                $node->replaceChild($el, $child);
            }
            foreach (iterator_to_array($el->attributes) as $attr) {
                $el->removeAttribute($attr->nodeName);
            }
            if ($class !== '') {
                $el->setAttribute('class', $class);
            }
            self::walk($el, $depth + 1, $budget);
        }
    }

    private static function keepClasses(string $value): string
    {
        $keep = array_values(array_intersect(preg_split('/\s+/', trim($value)) ?: [], self::CLASSES));
        return implode(' ', array_unique($keep));
    }
}

/**
 * Plain text of a written answer, one line per paragraph, line break or list item ("- item"),
 * the way Moodle answers came across in the migration. The result is text, not HTML.
 */
function student_html_text(string $html): string
{
    if (preg_match('/<[a-z!\/][^>]*>/i', $html)) {
        $html = preg_replace('/\s+/u', ' ', $html);            // line ends inside the HTML are only spacing
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html);
        $html = preg_replace('#<br\s*/?>|</(p|div|ul|ol|h[1-6]|li)>#i', "\n", $html);
        $html = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $lines = array_map(fn($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", str_replace("\r", '', $html)));
    return trim(implode("\n", array_filter($lines, fn($l) => $l !== '' && $l !== '-')));
}

/**
 * A written answer as HTML to show or print. Answers typed with the formatting toolbar are
 * sanitised; older ones (and every migrated Moodle answer) are plain text with one line per
 * paragraph or list item, and keep showing exactly as they did.
 */
function student_html(string $answer): string
{
    return StudentHtml::is_formatted($answer)
        ? StudentHtml::clean($answer)
        : nl2br(htmlspecialchars($answer, ENT_QUOTES, 'UTF-8'), false);
}

/**
 * A student's answer as HTML for a page: a written answer keeps the formatting the student gave
 * it, everything else is its plain description. Already safe; do not escape it again.
 */
function answer_html(array $q, $response): string
{
    if ($q['type'] === 'essay' && !grade_is_blank($response)) {
        return '<div class="written">' . student_html((string)$response) . '</div>';
    }
    return htmlspecialchars(describe_response($q, $response), ENT_QUOTES, 'UTF-8');
}

/** A matching answer for the teacher: one row per pair with ✓ or ✗, and the right answer where it is wrong. */
function match_marking_html(array $q, $response, ?callable $mediaUrl = null): string
{
    if (grade_is_blank($response)) {
        return '(no answer)';
    }
    $choices = match_choices($q);
    $rows = '';
    $right = 0;
    $stems = match_stems($q);
    foreach ($stems as $stem) {
        $pair = $q['pairs'][$stem];
        $pick = $response[$stem] ?? $response[(string)$stem] ?? null;
        $text = $pick === null ? null : ($choices[(int)$pick] ?? null);
        $ok = $text !== null && $text === $pair['answer'];
        $right += $ok ? 1 : 0;
        $rows .= '<tr><td>' . format_text($pair['question'], $pair['format'] ?? 'moodle', $mediaUrl) . '</td><td class="qv-arrow">→</td>'
            . '<td>' . ($text === null ? '<span class="muted">—</span>' : htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</td>'
            . '<td class="' . ($ok ? 'qv-right' : 'qv-bad') . '">' . ($ok ? '✓' : '✗ <span class="small">' . htmlspecialchars($pair['answer'], ENT_QUOTES, 'UTF-8') . '</span>') . '</td></tr>';
    }
    return '<table class="qv-match"><tbody>' . $rows . '</tbody></table><div class="small mt-8"><strong>' . $right . ' of ' . count($stems) . '</strong> matched correctly</div>';
}

/**
 * Convert question text in a GIFT/Moodle format to safe HTML.
 * $mediaUrl maps a relative picture path (e.g. "media/cable.jpg") to a URL; null leaves it.
 */
function format_text(string $text, string $format = 'moodle', ?callable $mediaUrl = null): string
{
    switch ($format) {
        case 'plain':
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
            break;
        case 'markdown':
            $html = markdown_to_html($text);
            break;
        case 'html':
            $html = $text;
            break;
        default:
            // Moodle auto-format: HTML kept, line breaks become <br> (not between two tags)
            $html = preg_replace('/(?<!>)\n|\n(?!\s*<)/', "<br>\n", $text);
            break;
    }
    $html = HtmlSanitizer::clean($html);
    if ($mediaUrl !== null) {
        $html = preg_replace_callback('/(<img\b[^>]*\bsrc=")([^"]+)(")/i', function ($m) use ($mediaUrl) {
            $src = rawurldecode(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));   // libxml percent-encodes spaces
            if (preg_match('#^(https?:|data:)#i', $src)) {
                return $m[0];
            }
            $url = $mediaUrl($src);
            return $m[1] . htmlspecialchars($url ?? $src, ENT_QUOTES, 'UTF-8') . $m[3];
        }, $html);
    }
    return $html;
}

/** Small Markdown subset: headings, bold/italic, code, links, images, lists, paragraphs. HTML passes through. */
function markdown_to_html(string $md): string
{
    $lines = explode("\n", str_replace("\r", '', $md));
    $html = [];
    $para = [];
    $list = null;
    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html[] = '<p>' . implode("<br>\n", array_map('markdown_inline', $para)) . '</p>';
            $para = [];
        }
    };
    $closeList = function () use (&$list, &$html) {
        if ($list) {
            $html[] = "</$list>";
            $list = null;
        }
    };
    foreach ($lines as $line) {
        if (trim($line) === '') {
            $flushPara();
            $closeList();
        } elseif (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $flushPara();
            $closeList();
            $n = strlen($m[1]);
            $html[] = "<h$n>" . markdown_inline($m[2]) . "</h$n>";
        } elseif (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m) || preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $m)) {
            $flushPara();
            $type = preg_match('/^\s*\d/', $line) ? 'ol' : 'ul';
            if ($list !== $type) {
                $closeList();
                $html[] = "<$type>";
                $list = $type;
            }
            $html[] = '<li>' . markdown_inline($m[1]) . '</li>';
        } else {
            $closeList();
            $para[] = $line;
        }
    }
    $flushPara();
    $closeList();
    return implode("\n", $html);
}

function markdown_inline(string $s): string
{
    $codes = [];
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        return "\u{E100}" . (count($codes) - 1) . "\u{E101}";
    }, $s);
    $s = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)\)/', '<img src="$2" alt="$1">', $s);
    $s = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<a href="$2">$1</a>', $s);
    $s = preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/', '<strong>$2</strong>', $s);
    $s = preg_replace('/(?<![\*\w])[\*_](?=\S)(.+?)(?<=\S)[\*_](?![\*\w])/', '<em>$1</em>', $s);
    return preg_replace_callback("/\u{E100}(\d+)\u{E101}/", fn($m) => $codes[(int)$m[1]], $s);
}

function question_type_label(string $type): string
{
    return [
        'multichoice' => 'Multiple choice', 'truefalse' => 'True / false', 'shortanswer' => 'Short answer',
        'numerical' => 'Numerical', 'match' => 'Matching', 'essay' => 'Essay', 'description' => 'Description',
        'multiblank' => 'Multi-blank',
    ][$type] ?? $type;
}

function format_number_answer(array $a): string
{
    if ($a['value'] === '*') {
        return 'any other number';
    }
    $v = rtrim(rtrim(number_format((float)$a['value'], 6, '.', ''), '0'), '.');
    $tol = (float)($a['tolerance'] ?? 0);
    return $tol > 0 ? $v . ' ± ' . rtrim(rtrim(number_format($tol, 6, '.', ''), '0'), '.') : $v;
}

function fraction_badge(float $fraction): string
{
    if ($fraction >= 1) {
        return '<span class="qv-mark qv-right" title="Correct">' . icon('check', 16) . '</span>';
    }
    if ($fraction <= 0 && $fraction > -0.00001) {
        return '<span class="qv-mark qv-wrong" title="Wrong">' . icon('x', 16) . '</span>';
    }
    return '<span class="qv-mark qv-part">' . h(round($fraction * 100, 2)) . '%</span>';
}

/** Answers of one answerable part (a question or one blank) as HTML. */
function render_answers_preview(array $q, string $format, ?callable $mediaUrl): string
{
    $feedback = fn($a) => ($a['feedback'] ?? '') !== ''
        ? '<div class="qv-feedback">' . format_text($a['feedback'], $a['feedback_format'] ?? $format, $mediaUrl) . '</div>' : '';
    $out = '';
    switch ($q['type']) {
        case 'multichoice':
            $out .= '<ul class="qv-choices">';
            foreach ($q['answers'] as $a) {
                $out .= '<li class="' . ($a['fraction'] > 0 ? 'is-right' : '') . '">' . fraction_badge((float)$a['fraction'])
                    . '<div class="qv-choice">' . format_text($a['text'], $a['format'] ?? $format, $mediaUrl) . $feedback($a) . '</div></li>';
            }
            $out .= '</ul>';
            if (empty($q['single'])) {
                $out .= '<p class="muted small">Students may tick more than one.</p>';
            }
            break;
        case 'truefalse':
            $out .= '<ul class="qv-choices">';
            foreach ([true, false] as $value) {
                $right = $value === !empty($q['correct']);
                $fb = $value ? ($q['feedback_true'] ?? '') : ($q['feedback_false'] ?? '');
                $out .= '<li class="' . ($right ? 'is-right' : '') . '">' . fraction_badge($right ? 1.0 : 0.0)
                    . '<div class="qv-choice">' . ($value ? 'True' : 'False')
                    . ($fb !== '' ? '<div class="qv-feedback">' . format_text($fb, $q[($value ? 'feedback_true' : 'feedback_false') . '_format'] ?? $format, $mediaUrl) . '</div>' : '')
                    . '</div></li>';
            }
            $out .= '</ul>';
            break;
        case 'shortanswer':
            $out .= '<ul class="qv-accepted">';
            foreach ($q['answers'] as $a) {
                $out .= '<li>' . fraction_badge((float)$a['fraction']) . '<code>' . h(short_answer_text($a['text'])) . '</code>' . $feedback($a) . '</li>';
            }
            $out .= '</ul>';
            break;
        case 'numerical':
            $out .= '<ul class="qv-accepted">';
            foreach ($q['answers'] as $a) {
                $out .= '<li>' . fraction_badge((float)$a['fraction']) . '<code>' . h(format_number_answer($a)) . '</code>' . $feedback($a) . '</li>';
            }
            $out .= '</ul>';
            break;
        case 'match':
            $out .= '<table class="qv-match"><tbody>';
            foreach ($q['pairs'] as $i => $p) {
                // data-pair: its place in the question (the exam editor removes pairs by it)
                $left = $p['question'] === '' ? '<span class="muted">(extra choice)</span>' : format_text($p['question'], $p['format'] ?? $format, $mediaUrl);
                $out .= '<tr data-pair="' . $i . '"' . ($p['question'] === '' ? ' data-extra' : '') . '><td>' . $left . '</td><td class="qv-arrow">→</td><td>' . h($p['answer']) . '</td></tr>';
            }
            $out .= '</tbody></table>';
            break;
        case 'essay':
            $out .= '<div class="qv-essay">' . icon('pencil', 16) . ' Written answer, marked by the teacher'
                . (($q['grader_info'] ?? '') !== '' ? '<div class="qv-feedback"><strong>Reference answer:</strong> ' . format_text($q['grader_info'], 'moodle', $mediaUrl) . '</div>' : '')
                . '</div>';
            break;
    }
    return $out;
}

/** Read-only teacher view of a question with answers marked. */
function render_question_preview(array $q, ?callable $mediaUrl = null): string
{
    $format = $q['format'] ?? 'moodle';
    $text = $q['text'] ?? '';
    $out = '<div class="qv qv-' . h($q['type']) . '">';

    if ($q['type'] === 'multiblank') {
        $html = format_text($text, $format, $mediaUrl);
        $html = preg_replace_callback('/\{#(\d+)\}/', function ($m) use ($q) {
            $blank = $q['blanks'][(int)$m[1] - 1] ?? null;
            if (!$blank) {
                return $m[0];
            }
            $label = match ($blank['type']) {
                'shortanswer' => implode(' / ', array_map(fn($a) => short_answer_text($a['text']), array_filter($blank['answers'], fn($a) => $a['fraction'] > 0))),
                'numerical' => implode(' / ', array_map('format_number_answer', array_filter($blank['answers'], fn($a) => $a['fraction'] > 0))),
                'multichoice' => implode(' / ', array_map(fn($a) => answer_label($a['text']), array_filter($blank['answers'], fn($a) => $a['fraction'] > 0))),
                'truefalse' => !empty($blank['correct']) ? 'True' : 'False',
                default => '?',
            };
            return '<span class="qv-blank" title="Blank ' . (int)$m[1] . '"><sup>' . (int)$m[1] . '</sup>' . h($label) . '</span>';
        }, $html);
        $out .= '<div class="qv-text">' . $html . '</div><ol class="qv-blanks">';
        foreach ($q['blanks'] as $blank) {
            $out .= '<li><span class="badge">' . h(question_type_label($blank['type'])) . '</span>' . render_answers_preview($blank, $format, $mediaUrl) . '</li>';
        }
        $out .= '</ol>';
    } else {
        $html = format_text($text, $format, $mediaUrl);
        if (!empty($q['missing_word'])) {
            $html = preg_replace('/_____/', '<span class="qv-blank">&nbsp;?&nbsp;</span>', $html, 1);
        }
        $out .= '<div class="qv-text">' . $html . '</div>';
        $out .= render_answers_preview($q, $format, $mediaUrl);
    }

    if (($q['general_feedback'] ?? '') !== '') {
        $out .= '<div class="qv-general"><strong>General feedback:</strong> '
            . format_text($q['general_feedback'], $q['general_feedback_format'] ?? $format, $mediaUrl) . '</div>';
    }
    return $out . '</div>';
}

/** Short text of the right answer(s), e.g. for practice checks. */
function render_answers_text(array $q): string
{
    $plain = fn($html) => answer_label((string)$html);
    switch ($q['type']) {
        case 'multichoice':
            return implode(' + ', array_map(fn($a) => $plain($a['text']), array_filter($q['answers'], fn($a) => (float)$a['fraction'] >= 1 || (!empty($q['single']) ? false : (float)$a['fraction'] > 0))));
        case 'truefalse':
            return !empty($q['correct']) ? 'True' : 'False';
        case 'shortanswer':
            return short_answer_text((string)(array_values(array_filter($q['answers'], fn($a) => (float)$a['fraction'] >= 1))[0]['text'] ?? ''));
        case 'numerical':
            return implode(' or ', array_map('format_number_answer', array_filter($q['answers'], fn($a) => (float)$a['fraction'] >= 1 && $a['value'] !== '*')));
        case 'match':
            return implode('; ', array_map(fn($p) => $plain($p['question']) . ' → ' . $p['answer'], array_filter($q['pairs'], fn($p) => trim($p['question']) !== '')));
        case 'multiblank':
            return implode(' | ', array_map(fn($b, $i) => ($i + 1) . ': ' . render_answers_text($b), $q['blanks'], array_keys($q['blanks'])));
    }
    return '';
}

/** Plain-text description of a student's response, for results and teacher review. */
function describe_response(array $q, $response): string
{
    $none = '(no answer)';
    $plain = fn($html) => answer_label((string)$html);
    if (grade_is_blank($response)) {
        return $none;
    }
    switch ($q['type']) {
        case 'multichoice':
            $ids = is_array($response) ? $response : [$response];
            $texts = array_map(fn($i) => $plain($q['answers'][(int)$i]['text'] ?? '?'), $ids);
            return implode('; ', $texts);
        case 'truefalse':
            return $response ? 'True' : 'False';
        case 'match':
            $choices = match_choices($q);
            $parts = [];
            foreach ($response as $stem => $choice) {
                $parts[] = $plain($q['pairs'][(int)$stem]['question'] ?? '?') . ' → ' . ($choices[(int)$choice] ?? '?');
            }
            return implode('; ', $parts);
        case 'essay':
            return student_html_text((string)$response);
        case 'multiblank':
            $parts = [];
            foreach ($q['blanks'] as $i => $blank) {
                $parts[] = ($i + 1) . ': ' . (isset($response[$i]) ? describe_response($blank, $response[$i]) : '—');
            }
            return implode(' | ', $parts);
        default:
            return (string)$response;
    }
}
