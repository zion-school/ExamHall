<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Zion Bank rewards for exams (a post-grading hook).
 *
 *  - Only reward exams, only attempts started through a teacher unlock, never migrated ones.
 *  - Amount = the exam's formula on the counted attempt (best or first), rounded to whole
 *    rupees, limited by the per-exam and per-day maximum.
 *  - A student is paid the difference between what they are entitled to and what was already
 *    paid or is on its way, so retakes and re-marks top up and nothing is paid twice.
 *  - Pay automatically, or wait for a teacher: "by_question_types" waits when the paper has essays.
 *  - Payments go through Zion Bank's bulk_transfer.php from the exam_rewards account. The bank's
 *    reason text carries the reward id; payments with an unknown outcome are checked against the
 *    bank's transaction log before anything is retried.
 *
 *   data/exams/<exam>/rewards.csv   reward_id,username,attempt,percent,amount,status,created,decided_by,decided_at,paid_at,message
 *   status: awaiting_approval | approved | paying | paid | failed | unknown | rejected | cancelled
 */

defined('EXAMS_APP') or exit;

const REWARDS_HEADER = ['reward_id', 'username', 'attempt', 'percent', 'amount', 'status', 'created', 'decided_by', 'decided_at', 'paid_at', 'message'];
const REWARD_OPEN_STATUSES = ['awaiting_approval', 'approved', 'paying', 'paid', 'unknown'];

function rewards_file(string $examId): string
{
    return exam_dir($examId) . '/rewards.csv';
}

function load_rewards(string $examId): array
{
    return read_csv(rewards_file($examId));
}

/** Why rewards cannot be paid right now, or null when set up. */
function reward_setup_problem(): ?string
{
    if (trim((string)cfg('BANK_BULK_TRANSFER_URL', '')) === '') {
        return 'Set $BANK_BULK_TRANSFER_URL in config.local.php.';
    }
    if ((string)cfg('REWARD_ACCOUNT_PASSWORD', '') === '' && !isset($GLOBALS['BANK_FAKE'])) {
        return 'Set $REWARD_ACCOUNT_PASSWORD (the password of the ' . cfg('REWARD_ACCOUNT') . ' user in Zion Bank) in config.local.php.';
    }
    return null;
}

/** Sample variables, used to check formulas and to show teachers what is available. */
function reward_sample_variables(): array
{
    return ['percent' => 80.0, 'score' => 8.0, 'max_score' => 10.0, 'correct' => 8, 'questions' => 10, 'attempt_no' => 1,
        'is_first_attempt' => 1, 'minutes_taken' => 12.0, 'on_time' => 1, 'class' => '5', 'improvement' => 0.0];
}

/** Error message for a formula, or null when it works. */
function reward_formula_error(string $formula): ?string
{
    try {
        foreach ([0, 55, 80, 100] as $p) {
            $v = Expr::evaluate($formula, ['percent' => (float)$p] + reward_sample_variables());
            if (!is_numeric($v)) {
                return 'The formula must give a number.';
            }
        }
        return null;
    } catch (ExprError $e) {
        return $e->getMessage();
    }
}

function reward_payout_mode(array $exam, array $paper): string
{
    $mode = $exam['reward']['payout'] ?? 'by_question_types';
    if ($mode === 'auto' || $mode === 'approve') {
        return $mode;
    }
    return in_array('essay', paper_question_types($paper), true) ? 'approve' : 'auto';
}

/** Variables for the formula from one attempt. */
function reward_variables(array $paper, array $attempt, array $earlier, string $class): array
{
    $percent = $attempt['max'] > 0 ? 100 * $attempt['score'] / $attempt['max'] : 0.0;
    $correct = 0;
    $questions = 0;
    foreach ($attempt['slots'] as $slot) {
        if (($paper['questions'][$slot['key']]['type'] ?? '') === 'description') {
            continue;
        }
        $questions++;
        if ((float)($attempt['grades'][$slot['slot']]['fraction'] ?? 0) >= 1) {
            $correct++;
        }
    }
    $bestBefore = 0.0;
    foreach ($earlier as $e) {
        $bestBefore = max($bestBefore, $e['max'] > 0 ? 100 * $e['score'] / $e['max'] : 0);
    }
    $started = strtotime($attempt['started'] ?? 'now');
    $submitted = strtotime($attempt['submitted'] ?? 'now');
    return [
        'percent' => round($percent, 4), 'score' => (float)$attempt['score'], 'max_score' => (float)$attempt['max'],
        'correct' => $correct, 'questions' => $questions, 'attempt_no' => (int)$attempt['n'],
        'is_first_attempt' => $earlier ? 0 : 1, 'minutes_taken' => round(max(0, $submitted - $started - (int)($attempt['paused_sec'] ?? 0)) / 60, 2),
        'on_time' => empty($attempt['late']) && empty($attempt['auto_submitted']) ? 1 : 0,
        'class' => $class, 'improvement' => $earlier ? max(0.0, round($percent - $bestBefore, 4)) : 0.0,
    ];
}

/** The exam's formula for one try's variables: whole coins, never below 0, at most the exam's most per exam. Throws ExprError. */
function reward_amount(array $exam, array $variables): float
{
    $amount = max(0.0, round((float)Expr::evaluate((string)$exam['reward']['formula'], $variables)));
    if ((float)$exam['reward']['max'] > 0) {
        $amount = min($amount, (float)$exam['reward']['max']);
    }
    return $amount;
}

/**
 * What a student should have received for an exam.
 * Returns ['amount' => float, 'attempt' => ?int, 'percent' => ?float, 'error' => ?string]
 */
function reward_entitlement(array $exam, array $paper, string $username): array
{
    $none = ['amount' => 0.0, 'attempt' => null, 'percent' => null, 'error' => null];
    if (!exam_is_reward($exam)) {
        return $none;
    }
    $attempts = array_values(array_filter(list_attempts($exam['id'], $username),
        fn($a) => empty($a['migrated']) && ($a['unlock'] ?? null) !== null && $a['status'] !== 'in_progress'));
    if (!$attempts) {
        return $none;
    }
    if (($exam['reward']['count'] ?? 'best') === 'first') {
        $candidates = $attempts[0]['status'] === 'graded' ? [$attempts[0]] : [];
    } else {
        $candidates = array_values(array_filter($attempts, fn($a) => $a['status'] === 'graded'));
    }
    $user = find_user($username);
    $class = normalize_class($user['class'] ?? '');
    $best = $none;
    foreach ($candidates as $a) {
        $earlier = array_values(array_filter($attempts, fn($x) => $x['n'] < $a['n'] && $x['status'] === 'graded'));
        try {
            // "class" is the class the student was in when writing the try, not after a later promotion
            $tryClass = normalize_class((string)($a['student']['class'] ?? '')) ?: $class;
            $amount = reward_amount($exam, reward_variables($paper, $a, $earlier, $tryClass));
        } catch (ExprError $e) {
            return $none + ['error' => $e->getMessage()];
        }
        if ($best['attempt'] === null || $amount > $best['amount']) {
            $best = ['amount' => $amount, 'attempt' => (int)$a['n'], 'percent' => $a['max'] > 0 ? 100 * $a['score'] / $a['max'] : 0.0, 'error' => null];
        }
    }
    return $best;
}

/** Amount of rewards already given or on their way to a student today (all exams). */
function rewards_today(string $username): float
{
    $today = date('Y-m-d');
    $sum = 0.0;
    foreach (list_dirs(data_path('exams')) as $examId) {
        if (!is_exam_id($examId)) {
            continue;
        }
        foreach (read_csv(data_path('exams', $examId, 'rewards.csv')) as $row) {
            if ($row['username'] === $username && in_array($row['status'], REWARD_OPEN_STATUSES, true) && str_starts_with($row['created'], $today)) {
                $sum += (float)$row['amount'];
            }
        }
    }
    return $sum;
}

/**
 * Bring the ledger up to date for an exam (or one student): add rows for amounts due,
 * cancel waiting rows that are no longer due, then pay automatically if the exam allows it.
 * Returns ['added' => n, 'paid' => n, 'problems' => [...]].
 */
function rewards_sweep(array $exam, ?string $only = null): array
{
    $result = ['added' => 0, 'paid' => 0, 'problems' => []];
    if (!exam_is_reward($exam) || !($paper = load_paper($exam['id']))) {
        return $result;
    }
    $mode = reward_payout_mode($exam, $paper);
    $users = $only !== null ? [$only] : list_dirs(exam_dir($exam['id']) . '/attempts');

    with_lock(rewards_file($exam['id']), function () use ($exam, $paper, $users, $mode, &$result) {
        $rows = load_rewards($exam['id']);
        $changed = false;
        foreach ($users as $username) {
            $ent = reward_entitlement($exam, $paper, $username);
            if ($ent['error']) {
                $result['problems'][] = "Formula error: {$ent['error']}";
                continue;
            }
            $given = 0.0;
            foreach ($rows as &$row) {
                if ($row['username'] !== $username) {
                    continue;
                }
                // A teacher's "do not pay" is final, so rejected amounts count as handled
                if (in_array($row['status'], REWARD_OPEN_STATUSES, true) || $row['status'] === 'rejected') {
                    $given += (float)$row['amount'];
                }
            }
            unset($row);
            $due = $ent['amount'] - $given;
            if ($due < 0) {
                // Entitlement went down (e.g. re-marked): withdraw rows not paid yet, newest first
                for ($i = count($rows) - 1; $i >= 0 && $due < 0; $i--) {
                    if ($rows[$i]['username'] === $username && in_array($rows[$i]['status'], ['awaiting_approval', 'approved'], true)) {
                        $due += (float)$rows[$i]['amount'];
                        $rows[$i]['status'] = 'cancelled';
                        $rows[$i]['message'] = 'No longer due after re-marking';
                        $changed = true;
                    }
                }
                continue;
            }
            $dailyMax = (float)($exam['reward']['daily_max'] ?? 0);
            if ($dailyMax > 0) {
                // rewards_today() reads the saved ledgers, which include this exam's rows written so far
                $due = min($due, max(0.0, $dailyMax - rewards_today($username)));
            }
            $due = floor($due);
            if ($due < 1) {
                continue;
            }
            $rows[] = [
                'reward_id' => next_reward_id(), 'username' => $username, 'attempt' => $ent['attempt'],
                'percent' => round((float)$ent['percent'], 1), 'amount' => $due,
                'status' => $mode === 'auto' ? 'approved' : 'awaiting_approval', 'created' => now_iso(),
                'decided_by' => $mode === 'auto' ? 'auto' : '', 'decided_at' => $mode === 'auto' ? now_iso() : '',
                'paid_at' => '', 'message' => $given > 0 ? 'Top-up after a better try or re-marking' : '',
            ];
            $result['added']++;
            $changed = true;
            // Save now so the daily limit sees it for the next student of the same exam day
            write_file_atomic(rewards_file($exam['id']), csv_string(REWARDS_HEADER, $rows));
        }
        if ($changed) {
            write_file_atomic(rewards_file($exam['id']), csv_string(REWARDS_HEADER, $rows));
        }
    });

    if ($mode === 'auto' && reward_setup_problem() === null) {
        $pay = rewards_pay($exam);
        $result['paid'] = $pay['paid'];
        $result['problems'] = array_merge($result['problems'], $pay['problems']);
    }
    return $result;
}

/** Teacher approves (or rejects) waiting rewards. */
function rewards_decide(array $exam, array $rewardIds, bool $approve, string $by): int
{
    $n = 0;
    update_csv(rewards_file($exam['id']), REWARDS_HEADER, function ($rows) use ($rewardIds, $approve, $by, &$n) {
        foreach ($rows as &$row) {
            if (in_array($row['reward_id'], $rewardIds, true) && $row['status'] === 'awaiting_approval') {
                $row['status'] = $approve ? 'approved' : 'rejected';
                $row['decided_by'] = $by;
                $row['decided_at'] = now_iso();
                $n++;
            }
        }
        return $rows;
    });
    return $n;
}

/**
 * Pay approved rewards (and retry failed ones when $retryFailed) through Zion Bank.
 * Returns ['paid' => n, 'failed' => n, 'unknown' => n, 'problems' => [...]].
 */
function rewards_pay(array $exam, bool $retryFailed = false): array
{
    $out = ['paid' => 0, 'failed' => 0, 'unknown' => 0, 'problems' => []];
    if ($problem = reward_setup_problem()) {
        $out['problems'][] = $problem;
        return $out;
    }
    rewards_reconcile($exam);   // never retry something the bank may already have done

    return with_lock(rewards_file($exam['id']), function () use ($exam, $retryFailed, $out) {
        $rows = load_rewards($exam['id']);
        $account = (string)cfg('REWARD_ACCOUNT');
        $batch = [];
        foreach ($rows as $i => $row) {
            if (!($row['status'] === 'approved' || ($retryFailed && $row['status'] === 'failed'))) {
                continue;
            }
            if (!find_user($row['username']) || $row['username'] === $account) {
                $rows[$i]['status'] = 'failed';
                $rows[$i]['message'] = 'This student has no Zion Bank account';
                $out['failed']++;
                continue;
            }
            $rows[$i]['status'] = 'paying';
            $rows[$i]['paid_at'] = now_iso();
            $rows[$i]['message'] = '';
            $batch[] = $i;
        }
        // Record "paying" before calling the bank, so a crash cannot lead to paying twice
        write_file_atomic(rewards_file($exam['id']), csv_string(REWARDS_HEADER, $rows));

        foreach (array_chunk($batch, 25) as $chunk) {
            $transfers = array_map(fn($i) => [
                'username' => $rows[$i]['username'], 'amount' => (float)$rows[$i]['amount'],
                'reason' => 'Exam reward ' . $rows[$i]['reward_id'] . ' · ' . mb_substr($exam['title'], 0, 60),
            ], $chunk);
            $res = bank_bulk_transfer($transfers);
            foreach ($chunk as $i) {
                if ($res['ok']) {
                    $rows[$i]['status'] = 'paid';
                    $rows[$i]['paid_at'] = now_iso();
                    $rows[$i]['message'] = '';
                    $out['paid']++;
                } elseif ($res['known']) {
                    $rows[$i]['status'] = 'failed';
                    $rows[$i]['message'] = $res['message'];
                    $out['failed']++;
                } else {
                    $rows[$i]['status'] = 'unknown';
                    $rows[$i]['message'] = $res['message'];
                    $out['unknown']++;
                }
            }
            if (!$res['ok']) {
                $out['problems'][] = 'Zion Bank: ' . $res['message'];
            }
            write_file_atomic(rewards_file($exam['id']), csv_string(REWARDS_HEADER, $rows));
        }
        return $out;
    });
}

/**
 * Check payments with an unknown outcome (or stuck in "paying") against the bank's log of the
 * reward account. Found -> paid; not found -> failed (safe to retry). Returns number resolved.
 */
function rewards_reconcile(array $exam): int
{
    $file = rtrim((string)cfg('BANK_TRANSACTIONS_DIR', ''), '/') . '/' . cfg('REWARD_ACCOUNT') . '.csv';
    $resolved = 0;
    update_csv(rewards_file($exam['id']), REWARDS_HEADER, function ($rows) use ($file, &$resolved) {
        $stale = time() - 120;
        $todo = array_filter($rows, fn($r) => $r['status'] === 'unknown' || ($r['status'] === 'paying' && strtotime($r['paid_at'] ?: $r['created']) < $stale));
        if (!$todo) {
            return false;
        }
        if (!is_readable($file)) {
            foreach ($rows as &$row) {
                if (in_array($row, $todo, true)) {
                    $row['status'] = 'unknown';
                    $row['message'] = 'Cannot read the Zion Bank log to check this payment; ask the bank admin.';
                }
            }
            return $rows;
        }
        $log = (string)file_get_contents($file);
        foreach ($rows as &$row) {
            if (!in_array($row, $todo, true)) {
                continue;
            }
            if (preg_match('/Exam reward ' . preg_quote($row['reward_id'], '/') . '\b/', $log)) {
                $row['status'] = 'paid';
                $row['message'] = 'Confirmed in the bank log';
            } else {
                $row['status'] = 'failed';
                $row['message'] = 'Not found in the bank log (safe to retry)';
            }
            $resolved++;
        }
        return $rows;
    });
    return $resolved;
}

/**
 * POST to Zion Bank bulk_transfer.php. Returns ['ok' => bool, 'known' => bool (outcome certain), 'message' => string].
 * Tests may set $GLOBALS['BANK_FAKE'] = fn(array $transfers): array.
 */
function bank_bulk_transfer(array $transfers): array
{
    if (isset($GLOBALS['BANK_FAKE']) && is_callable($GLOBALS['BANK_FAKE'])) {
        return ($GLOBALS['BANK_FAKE'])($transfers);
    }
    $ch = curl_init((string)cfg('BANK_BULK_TRANSFER_URL'));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'user' => cfg('REWARD_ACCOUNT'), 'password' => cfg('REWARD_ACCOUNT_PASSWORD'),
            'transfers' => json_encode($transfers, JSON_UNESCAPED_UNICODE),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        // No reply: the bank may or may not have paid, so the outcome is unknown until reconciled
        return ['ok' => false, 'known' => false, 'message' => 'No reply from Zion Bank: ' . $err];
    }
    $json = json_decode($raw, true);
    if (!is_array($json) && preg_match('/\{[^{}]*"success"[^{}]*\}/', $raw, $m)) {
        $json = json_decode($m[0], true);   // PHP notices may precede the JSON
    }
    if (!is_array($json) || !array_key_exists('success', $json)) {
        return ['ok' => false, 'known' => false, 'message' => "Unexpected reply from Zion Bank (HTTP $status)"];
    }
    // The bank validates everything before moving money, so a failure message means nothing was paid
    return ['ok' => (bool)$json['success'], 'known' => true, 'message' => (string)($json['message'] ?? '')];
}

/** Balance of the reward account from Zion Bank's balance.csv (read only), or null. */
function bank_reward_balance(): ?float
{
    $file = dirname(rtrim((string)cfg('BANK_TRANSACTIONS_DIR', ''), '/')) . '/balance.csv';
    foreach (is_readable($file) ? read_csv($file, '\\') : [] as $row) {
        if (($row['username'] ?? '') === cfg('REWARD_ACCOUNT')) {
            return (float)$row['balance'];
        }
    }
    return null;
}

/** Totals for a student on one exam: paid and waiting amounts. */
function student_reward_summary(string $examId, string $username): array
{
    $out = ['paid' => 0.0, 'waiting' => 0.0];
    foreach (load_rewards($examId) as $row) {
        if ($row['username'] !== $username) {
            continue;
        }
        if ($row['status'] === 'paid') {
            $out['paid'] += (float)$row['amount'];
        } elseif (in_array($row['status'], ['awaiting_approval', 'approved', 'paying', 'unknown'], true)) {
            $out['waiting'] += (float)$row['amount'];
        }
    }
    return $out;
}

// ---------------------------------------------------------------- Hooks

/**
 * Run post-grading hooks for a student (or everyone) of an exam.
 * Hooks are listed in config $HOOKS: "rewards" (built in) or names of PHP functions fn(string $event, array $exam, ?string $username).
 */
function fire_hook(string $event, string $examId, ?string $username = null): void
{
    $exam = load_exam($examId);
    if (!$exam) {
        return;
    }
    foreach ((array)cfg('HOOKS', ['rewards']) as $hook) {
        try {
            if ($hook === 'rewards') {
                if (in_array($event, ['attempt.graded', 'grading.changed'], true) && exam_is_reward($exam)) {
                    rewards_sweep($exam, $username);
                }
            } elseif (is_string($hook) && function_exists($hook)) {
                $hook($event, $exam, $username);
            }
        } catch (Throwable $e) {
            error_log("Hook $hook failed for $event on $examId: " . $e->getMessage());
        }
    }
}
