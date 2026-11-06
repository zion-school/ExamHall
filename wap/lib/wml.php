<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 30-Oct-2026
 *
 * WML 1.3 output helpers, as in the WAP GiftShop and Zion Bank: deck/card
 * skeleton, escaping, links, soft keys, forms posted by a card, paging, and
 * messages shown in a dialog. Every page is in wap/, so links are plain
 * file names. Named wap_url(), wap_redirect() and wap_flash() where the
 * Exams lib already has url(), redirect() and flash() for its web pages.
 */

defined('EXAMS_APP') or exit;

/** Rows per page in lists. A deck must stay under ~3.5 KB for an ESP32 WAP browser. */
const WAP_PAGE_SIZE = 8;

/**
 * Escape text for a WML attribute or #PCDATA. '$' starts a variable
 * reference in WML (section 10.3), so it is doubled.
 */
function e($s)
{
    $s = htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    return str_replace('$', '$$', $s);
}

/** Build "page.php?a=1&b=2" (unescaped; pass through e() when printing) */
function wap_url($path, $params = [])
{
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return empty($params) ? $path : $path . '?' . http_build_query($params);
}

/** Absolute URL of a page in wap/ (for redirects) */
function wap_abs_url($path)
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return "{$scheme}://{$_SERVER['HTTP_HOST']}" . url('wap/' . $path);
}

function wap_redirect($path)
{
    header('Location: ' . wap_abs_url($path));
    exit();
}

/**
 * Message for the next page shown, in a dialog (a browser variable: <= 192
 * bytes). Only for what the student has to act on: an error, a refusal.
 * What went well, the next page shows.
 */
function wap_flash($message)
{
    $_SESSION['wap_flash'] = mb_strimwidth($message, 0, 150, '...', 'UTF-8');
}

/**
 * Start a deck and its first card, leaving a <p> open.
 * $card_attrs: extra card attributes, e.g. 'newcontext="true"'
 * $pre: card-level <do> elements
 * $preset: form variables to set on entering the card (see on_enter())
 * $timer: [seconds, URL] of a card timer (time up, "#t": a post_card()),
 * used when no message needs the timer
 */
function wml_start($title, $card_attrs = '', $pre = '', $preset = [], $timer = null)
{
    header('Content-Type: text/vnd.wap.wml; charset=utf-8');
    header('Cache-Control: no-cache');
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    echo '<!DOCTYPE wml PUBLIC "-//WAPFORUM//DTD WML 1.3//EN" "http://www.wapforum.org/DTD/wml13.dtd">' . "\n";
    echo "<wml>\n";
    // Variables a POST asked to forget (a password), see clear_vars_next()
    if (isset($_SESSION['wap_clear_vars'])) {
        $preset += array_fill_keys($_SESSION['wap_clear_vars'], '');
        unset($_SESSION['wap_clear_vars']);
    }
    $message = $_SESSION['wap_flash'] ?? null;
    unset($_SESSION['wap_flash']);
    [$attrs, $events] = on_enter($preset, $message, $timer);
    wml_card('c', $title, trim("{$card_attrs} {$attrs}"), $events . $pre);
}

/**
 * The first card's entry: [card attributes, its onevent and timer].
 * onenterforward (and onenterbackward) sets form variables to fresh values, so an input's
 * initial value isn't a stale one from an earlier card (an input's value
 * attribute is ignored once its variable is set), and $message into "m".
 * A timer then shows it in a dialog (exams.wmls): the timer runs on its
 * own, where a <go> to the script in onenterforward would be one more
 * navigation inside the one that brought the page, which a browser may
 * cap (wap-browser-c drops the fourth: its bugs.md #107). Each message has
 * its own id, which exams.wmls shows once: the timer starts again when
 * Back returns to the card. Without a message, $timer may use the card's
 * one timer: its ontimer attribute, as wap-browser-c runs no
 * <onevent type="ontimer"> (its bugs.md #109).
 */
function on_enter($vars, $message, $timer = null)
{
    $attrs = '';
    $events = '';
    $timer_el = '';
    if ($message !== null) {
        $vars['m'] = $message;
        $attrs = 'ontimer="' . e("exams.wmls#show('" . bin2hex(random_bytes(3)) . "')") . '"';
        $timer_el = '<timer value="1"/>' . "\n";
    } elseif ($timer !== null) {
        $attrs = 'ontimer="' . e($timer[1]) . '"';
        $timer_el = '<timer value="' . max(1, (int)$timer[0] * 10) . '"/>' . "\n";
    }
    if ($vars) {
        $setvars = '';
        foreach ($vars as $name => $value) {
            $setvars .= '<setvar name="' . $name . '" value="' . e($value) . '"/>';
        }
        // Back to the card too: its variables may be shared with other cards (the answer's a0, a1, ...),
        // and on Back the deck is fetched again, so these are the values as saved
        $events = '<onevent type="onenterforward"><refresh>' . $setvars . "</refresh></onevent>\n"
            . '<onevent type="onenterbackward"><refresh>' . $setvars . "</refresh></onevent>\n" . $events;
    }
    return [$attrs, $events . $timer_el];
}

/**
 * Empty these browser variables on the next page shown. A <setvar> in
 * the posting <go> would be the WML way (applied after the fetch,
 * WML 1.3 section 12.5.1), but some browsers apply it before the
 * postfields are read and post an empty value.
 */
function clear_vars_next($names)
{
    $_SESSION['wap_clear_vars'] = $names;
}

/** Close the open card (if any) and open another one in the same deck */
function wml_card($id, $title, $card_attrs = '', $pre = '')
{
    static $open = false;
    if ($open) {
        echo "</p></card>\n";
    }
    $open = true;
    echo '<card id="' . $id . '" title="' . e($title) . '"' . ($card_attrs ? " $card_attrs" : '') . ">\n";
    echo $pre;
    echo "<p>\n";
}

/** Close the deck; $cards: cards after the open one (post_card()) */
function wml_end($cards = '')
{
    echo "</p></card>\n{$cards}</wml>\n";
    exit();
}

/**
 * A 7x7 icon on a text line: icons/<name>.wbmp, made from lib/icons.txt by
 * lib/make_icons.php. $alt: the text it stands for, which a browser shows
 * without images; '' when the text next to it says it all.
 */
function wap_icon($name, $alt = '')
{
    return '<img src="' . e("icons/{$name}.wbmp") . '" alt="' . e($alt) . '"/>';
}

/**
 * <a> link on its own line. $key: accesskey digit shown as "1 ". $icon: a
 * wap_icon() before the text and its digit.
 */
function link_to($text, $href, $key = null, $icon = '')
{
    $ak = '';
    if ($key !== null) {
        $ak = ' accesskey="' . $key . '"';
        $text = "{$key} {$text}";
    }
    return '<a href="' . e($href) . "\"{$ak}>" . $icon . e($text) . '</a><br/>';
}

/**
 * Postfields of a form: [name => value]. A value may reference WML
 * variables ("$(q)"): such values are printed as is, literal ones escaped.
 */
function postfields($fields)
{
    $out = '';
    foreach ($fields as $name => $value) {
        $v = (is_array($value) && isset($value['var'])) ? '$(' . $value['var'] . ')' : e($value);
        $out .= '<postfield name="' . $name . '" value="' . $v . '"/>';
    }
    return $out;
}

/** Variable reference for postfields(): field('q') -> "$(q)" */
function field($name)
{
    return ['var' => $name];
}

/**
 * <go method="post"> with its postfields; the session's CSRF token goes with it. The URL gets
 * "post=1", which wap_posted() looks for: a browser that sends a form's body again to the page
 * it was redirected to (going Back) is not posting that page.
 */
function go_post($href, $fields)
{
    $href .= (str_contains($href, '?') ? '&' : '?') . 'post=1';
    return '<go href="' . e($href) . '" method="post">' . postfields($fields + ['csrf' => csrf_token()]) . '</go>';
}

/** A form was posted to this page (see go_post()) */
function wap_posted(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['post']);
}

/** <go> to a page */
function go_to($href)
{
    return '<go href="' . e($href) . '"/>';
}

/**
 * The left soft key: $actions is a list of [label, task] (go_to(),
 * script_go(), go_post()), the card's own action first. One shows its
 * label on the key; several show "Options", a list that opens on the
 * first. The first is the accept <do>, the others options <do>s, each
 * named: unnamed <do>s of one type would be one action (WML 1.3 9.6).
 */
function soft_keys($actions)
{
    $out = '';
    foreach (array_values($actions) as $i => [$label, $task]) {
        $type = $i === 0 ? 'type="accept"' : 'type="options" name="o' . $i . '"';
        $out .= "<do {$type} label=\"" . e($label) . "\">{$task}</do>\n";
    }
    return $out;
}

/**
 * <go> that calls an exams.wmls function, e.g. "ask('Submit','p')", after
 * setting the browser variables in $vars (the question it asks)
 */
function script_go($call, $vars = [])
{
    $setvars = '';
    foreach ($vars as $name => $value) {
        $setvars .= '<setvar name="' . $name . '" value="' . e($value) . '"/>';
    }
    return '<go href="' . e('exams.wmls#' . $call) . '">' . $setvars . '</go>';
}

/**
 * Card that posts a form as soon as it is entered: exams.wmls goes to it
 * once the student agrees. Back steps over it (onenterbackward), so going
 * back never posts the form again.
 */
function post_card($id, $href, $fields)
{
    return '<card id="' . $id . '"><onevent type="onenterforward">' . go_post($href, $fields) . '</onevent>'
        . "<onevent type=\"onenterbackward\"><prev/></onevent></card>\n";
}

/** Current page number from ?pg= (1-based, clamped) */
function page_number($total_rows)
{
    $pages = max(1, (int)ceil($total_rows / WAP_PAGE_SIZE));
    $page = isset($_GET['pg']) ? (int)$_GET['pg'] : 1;
    return [max(1, min($page, $pages)), $pages];
}

function page_slice($rows, $page, $preserve_keys = false)
{
    return array_slice($rows, ($page - 1) * WAP_PAGE_SIZE, WAP_PAGE_SIZE, $preserve_keys);
}

/** "More" / "Prev" links and "2/5"; $path and $params build the page URL */
function pager($path, $params, $page, $pages)
{
    if ($pages <= 1) {
        return '';
    }
    $out = '';
    if ($page < $pages) {
        $out .= '<a href="' . e(wap_url($path, $params + ['pg' => $page + 1])) . '">More</a> ';
    }
    if ($page > 1) {
        $out .= '<a href="' . e(wap_url($path, $params + ['pg' => $page - 1])) . '">Prev</a> ';
    }
    return $out . "{$page}/{$pages}<br/>\n";
}
