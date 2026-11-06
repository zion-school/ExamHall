<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Settings. Edit below as needed.
 * Put secrets and machine-specific overrides in config.local.php (not committed).
 */

$APP_NAME = "ExamHall";
$SCHOOL_NAME = "Sample School";
$CURRENCY_SYMBOL = "₹";

// Users: name,username,password,email,class,date_of_birth (read only).
// The bundled data/users.csv holds the sample users; to share logins with Zion Bank use
// $USERS_CSV = __DIR__ . "/../Zion_Bank/users.csv";
$USERS_CSV = __DIR__ . "/data/users.csv";
$ADMIN_USERS = ['admin'];

// Folder that holds all exam data (can be moved outside the web root)
$DATA_DIR = __DIR__ . "/data";

// The school year starts on this day (MM-DD). An exam whose dates (or, without dates, its publishing or
// reopening) fall in an earlier school year can no longer be started; "Reopen for this school year" or new dates open it again.
$SCHOOL_YEAR_STARTS = '06-01';

// Session lifetime for logged-in pages (autosave does not depend on it)
$SESSION_HOURS = 8;

// Login throttling: failed attempts allowed per username within the window
$LOGIN_MAX_FAILS = 10;
$LOGIN_FAIL_WINDOW_MIN = 10;

// Zion Bank rewards (optional; password goes in config.local.php). Empty URL = rewards are worked out but not paid.
$BANK_BULK_TRANSFER_URL = '';   // e.g. http://localhost/Zion_Bank/bulk_transfer.php
$BANK_TRANSACTIONS_DIR = __DIR__ . "/../Zion_Bank/transactions";
$REWARD_ACCOUNT = 'exam_rewards';
$REWARD_ACCOUNT_PASSWORD = '';

// Hooks run after grading ("rewards" = Zion Bank rewards; or names of your own PHP functions)
$HOOKS = ['rewards'];

// Local LLM (llama.cpp llama-server or any OpenAI-compatible endpoint)
$LLM_URL = '';          // e.g. http://192.168.1.50:8080/v1/chat/completions
$LLM_MODEL = 'local';
$LLM_TIMEOUT_SEC = 180;
$LLM_BATCH_SIZE = 8;         // student answers per request (small models, small context)
$LLM_MIN_CONFIDENCE = 0.7;   // below this, "auto" mode sends the answer to the teacher

date_default_timezone_set('Asia/Kolkata');

if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// Test and CLI overrides
if (getenv('EXAMS_DATA_DIR')) {
    $DATA_DIR = getenv('EXAMS_DATA_DIR');
}
if (getenv('EXAMS_USERS_CSV')) {
    $USERS_CSV = getenv('EXAMS_USERS_CSV');
}
if (getenv('EXAMS_LLM_URL')) {
    $LLM_URL = getenv('EXAMS_LLM_URL');
}
