<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * File storage: the "database" is folders of JSON and CSV files under $DATA_DIR.
 *
 * Rules:
 *  - Every write goes to a temp file and is renamed into place (readers never
 *    see a half-written file), so plain reads need no lock.
 *  - Read-modify-write goes through update_json()/update_csv(), which hold an
 *    exclusive flock for the whole cycle.
 */

defined('EXAMS_APP') or exit;

class StorageException extends RuntimeException
{
}

/** Absolute path inside the data folder. Rejects '..' and empty segments. */
function data_path(string ...$parts): string
{
    $clean = [];
    foreach ($parts as $part) {
        foreach (explode('/', str_replace('\\', '/', $part)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..' || str_contains($seg, "\0")) {
                throw new StorageException("Invalid path segment: $seg");
            }
            $clean[] = $seg;
        }
    }
    return rtrim(cfg('DATA_DIR'), '/') . ($clean ? '/' . implode('/', $clean) : '');
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new StorageException("Cannot create folder: $dir");
    }
}

/** Lock file for a key (usually a data path). Lock files live in data/.locks/. */
function lock_file_for(string $key): string
{
    $data = rtrim(cfg('DATA_DIR'), '/');
    if (str_starts_with($key, $data . '/')) {
        $key = substr($key, strlen($data) + 1);
    }
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '~', $key);
    if (strlen($name) > 180) {
        $name = substr($name, 0, 150) . '-' . sprintf('%u', crc32($key));
    }
    $dir = $data . '/.locks';
    ensure_dir($dir);
    return "$dir/$name.lock";
}

/**
 * Run $fn while holding an exclusive lock on $key. Re-entrant within one request.
 */
function with_lock(string $key, callable $fn)
{
    static $held = [];
    $file = lock_file_for($key);
    if (isset($held[$file])) {
        return $fn();
    }
    $fp = fopen($file, 'c');
    if ($fp === false) {
        throw new StorageException("Cannot open lock: $file");
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new StorageException("Cannot lock: $file");
    }
    $held[$file] = true;
    try {
        return $fn();
    } finally {
        unset($held[$file]);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** Write a whole file atomically (temp file + rename). */
function write_file_atomic(string $path, string $content): void
{
    $dir = dirname($path);
    ensure_dir($dir);
    $tmp = $dir . '/.' . basename($path) . '.tmp' . getmypid() . '_' . random_int(0, 999999999);
    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        throw new StorageException("Cannot write: $path");
    }
    $ok = fwrite($fp, $content) === strlen($content) && fflush($fp);
    // Bulk jobs (e.g. importing thousands of questions) may skip fsync; attempts never do
    if ($ok && function_exists('fsync') && empty($GLOBALS['storage_skip_fsync'])) {
        @fsync($fp);
    }
    fclose($fp);
    if (!$ok || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new StorageException("Cannot write: $path");
    }
}

function read_json(string $path, $default = null)
{
    if (!is_file($path)) {
        return $default;
    }
    $data = json_decode((string)file_get_contents($path), true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new StorageException("Corrupt JSON: $path (" . json_last_error_msg() . ")");
    }
    return $data;
}

function encode_json($data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function write_json(string $path, $data): void
{
    with_lock($path, function () use ($path, $data) {
        write_file_atomic($path, encode_json($data));
    });
}

/**
 * Read-modify-write a JSON file under an exclusive lock.
 * $fn receives the current data (or $default) and returns the new data.
 * Returning false leaves the file untouched. Returns what $fn returned.
 */
function update_json(string $path, callable $fn, $default = [])
{
    return with_lock($path, function () use ($path, $fn, $default) {
        $new = $fn(read_json($path, $default));
        if ($new !== false) {
            write_file_atomic($path, encode_json($new));
        }
        return $new;
    });
}

/**
 * Read a CSV with a header row into a list of associative arrays.
 * Missing trailing cells become ''. Our own files use RFC 4180 quoting ($escape '');
 * Zion Bank's users.csv is read with '\\' like the sibling apps.
 */
function read_csv(string $path, string $escape = ''): array
{
    if (!is_file($path)) {
        return [];
    }
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        return [];
    }
    $header = fgetcsv($fp, null, ',', '"', $escape);
    if (!$header) {
        fclose($fp);
        return [];
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $header = array_map('trim', $header);
    $rows = [];
    while (($cells = fgetcsv($fp, null, ',', '"', $escape)) !== false) {
        if ($cells === [null] || $cells === ['']) {
            continue;
        }
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = $cells[$i] ?? '';
        }
        $rows[] = $row;
    }
    fclose($fp);
    return $rows;
}

function csv_string(array $header, array $rows): string
{
    $fp = fopen('php://temp', 'r+');
    fputcsv($fp, $header, ',', '"', '');
    foreach ($rows as $row) {
        $line = [];
        foreach ($header as $col) {
            $line[] = $row[$col] ?? '';
        }
        fputcsv($fp, $line, ',', '"', '');
    }
    rewind($fp);
    $out = stream_get_contents($fp);
    fclose($fp);
    return $out;
}

function write_csv(string $path, array $header, array $rows): void
{
    with_lock($path, function () use ($path, $header, $rows) {
        write_file_atomic($path, csv_string($header, $rows));
    });
}

/** Read-modify-write a CSV under an exclusive lock. $fn gets and returns the row list. */
function update_csv(string $path, array $header, callable $fn)
{
    return with_lock($path, function () use ($path, $header, $fn) {
        $rows = $fn(read_csv($path));
        if ($rows !== false) {
            write_file_atomic($path, csv_string($header, $rows));
        }
        return $rows;
    });
}

/** Append one row (creating the file with its header if needed). */
function append_csv(string $path, array $header, array $row): void
{
    with_lock($path, function () use ($path, $header, $row) {
        ensure_dir(dirname($path));
        $new = !is_file($path) || filesize($path) === 0;
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new StorageException("Cannot append: $path");
        }
        if ($new) {
            fputcsv($fp, $header, ',', '"', '');
        }
        $line = [];
        foreach ($header as $col) {
            $line[] = $row[$col] ?? '';
        }
        fputcsv($fp, $line, ',', '"', '');
        fflush($fp);
        fclose($fp);
    });
}

/** Next value of a named integer counter in data/counters/<name>.txt. */
function next_counter(string $name): int
{
    $path = data_path('counters', "$name.txt");
    return with_lock($path, function () use ($path) {
        $n = is_file($path) ? (int)trim((string)file_get_contents($path)) : 0;
        $n++;
        write_file_atomic($path, "$n\n");
        return $n;
    });
}

/** Sub-folder names of $dir (sorted, hidden ones skipped). */
function list_dirs(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) as $name) {
        if ($name[0] !== '.' && is_dir("$dir/$name")) {
            $out[] = $name;
        }
    }
    natcasesort($out);
    return array_values($out);
}

/** File names in $dir matching a regex (sorted, hidden ones skipped). */
function list_files(string $dir, string $regex = '/./'): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) as $name) {
        if ($name[0] !== '.' && is_file("$dir/$name") && preg_match($regex, $name)) {
            $out[] = $name;
        }
    }
    natcasesort($out);
    return array_values($out);
}

function delete_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
