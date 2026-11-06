<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Log out and return to the login page.
 */
require_once __DIR__ . '/lib/bootstrap.php';

logout();
redirect('index.php');
