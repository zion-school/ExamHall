<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 02-Nov-2026
 *
 * Makes the phone pages' icons, icons/<name>.wbmp, from the pictures in
 * lib/icons.txt (7x7, '#' black):
 *
 *     php lib/make_icons.php
 *
 * A WBMP (Type 0, WAP-237 WAE section 6) is 9x8 with the picture top left:
 * two blank columns part it from the text after it, and the blank row under
 * it is where a link's underline runs, so it sits on a text line as a
 * letter of the 5x7 font does. Icons no longer in icons.txt are removed.
 */
if (PHP_SAPI !== 'cli') {
    exit();
}

const ICON_W = 9;
const ICON_H = 8;
const ART = 7;

/** [name => 7 rows of '.' and '#'] */
function read_icons($file)
{
    $icons = [];
    $name = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $n => $line) {
        $line = rtrim($line);
        if ($line === '' || strpos($line, '//') === 0) {
            continue;
        }
        if (preg_match('/^[.#]+$/', $line)) {
            if ($name === null || strlen($line) !== ART || count($icons[$name]) === ART) {
                exit("icons.txt line " . ($n + 1) . ": a picture is " . ART . " rows of " . ART . "\n");
            }
            $icons[$name][] = $line;
        } elseif (preg_match('/^([a-z0-9_]+)\b/', $line, $m)) {
            $name = $m[1];
            $icons[$name] = [];
        } else {
            exit("icons.txt line " . ($n + 1) . ": not a name or a picture row\n");
        }
    }
    foreach ($icons as $name => $rows) {
        if (count($rows) !== ART) {
            exit("icons.txt: {$name} has " . count($rows) . " rows, not " . ART . "\n");
        }
    }
    return $icons;
}

/** The WBMP of a picture: 1 is white, rows padded to whole bytes */
function wbmp($rows)
{
    $out = chr(0) . chr(0) . chr(ICON_W) . chr(ICON_H);
    for ($y = 0; $y < ICON_H; $y++) {
        $bits = '';
        for ($x = 0; $x < 16; $x++) {
            $black = $y < ART && $x < ART && $rows[$y][$x] === '#';
            $bits .= $black ? '0' : '1';
        }
        $out .= chr(bindec(substr($bits, 0, 8))) . chr(bindec(substr($bits, 8, 8)));
    }
    return $out;
}

$dir = dirname(__DIR__) . '/icons';
if (!is_dir($dir)) {
    mkdir($dir, 0775);
}
$icons = read_icons(__DIR__ . '/icons.txt');
foreach ($icons as $name => $rows) {
    file_put_contents("{$dir}/{$name}.wbmp", wbmp($rows));
}
foreach (glob("{$dir}/*.wbmp") as $file) {
    if (!isset($icons[basename($file, '.wbmp')])) {
        unlink($file);
    }
}
echo count($icons) . ' icons in ' . $dir . "\n";
