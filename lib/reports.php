<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * "Report a problem with this question" from students (many bank questions still say
 * "TODO: check all answers"). data/reports.csv: id,created,exam,question,username,text,status,handled_by,handled_at
 */

defined('EXAMS_APP') or exit;

const REPORTS_HEADER = ['id', 'created', 'exam', 'question', 'username', 'text', 'status', 'handled_by', 'handled_at'];

function reports_file(): string
{
    return data_path('reports.csv');
}

function add_question_report(string $examId, string $questionKey, string $username, string $text): string
{
    $id = sprintf('P%05d', next_counter('report'));
    append_csv(reports_file(), REPORTS_HEADER, [
        'id' => $id, 'created' => now_iso(), 'exam' => $examId, 'question' => $questionKey,
        'username' => $username, 'text' => mb_substr(trim($text), 0, 500), 'status' => 'open',
    ]);
    return $id;
}

/** Open reports the user may handle (subject of the question's bank folder or exam). */
function open_question_reports(array $user): array
{
    $out = [];
    foreach (read_csv(reports_file()) as $row) {
        if ($row['status'] !== 'open') {
            continue;
        }
        $exam = load_exam($row['exam']);
        if ($exam && exam_can_manage($user, $exam)) {
            $out[] = $row + ['exam_title' => $exam['title']];
        }
    }
    return array_reverse($out);
}

function close_question_report(string $id, string $by): void
{
    update_csv(reports_file(), REPORTS_HEADER, function ($rows) use ($id, $by) {
        foreach ($rows as &$row) {
            if ($row['id'] === $id) {
                $row['status'] = 'done';
                $row['handled_by'] = $by;
                $row['handled_at'] = now_iso();
            }
        }
        return $rows;
    });
}
