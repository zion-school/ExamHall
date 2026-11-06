<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Printable question paper for offline exams. Each version (A, B, C...) has its own
 * question order, random picks and choice order; the answer key matches the version.
 *   teacher/print.php?id=<exam>&version=1[&key=1]
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_staff();
$exam = load_exam((string)($_GET['id'] ?? ''));
$paper = $exam ? load_paper($exam['id']) : null;
if (!$exam || !$paper || !exam_can_manage($user, $exam)) {
    http_response_code(404);
    exit('Publish the exam first to print it.');
}
$version = max(1, min(26, (int)($_GET['version'] ?? 1)));
$withKey = !empty($_GET['key']);
$slots = build_attempt_slots($exam, $paper, new SeededRandom(7919 * $version + crc32($exam['id'])));
$letter = chr(64 + $version);
$mediaUrl = exam_media_url($exam['id']);
$fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 2), '0'), '.');
$total = array_sum(array_column($slots, 'mark'));

/** Correct answer text for the key. */
function key_text(array $q, array $layout): string
{
    $plain = fn($html) => answer_label((string)$html);
    switch ($q['type']) {
        case 'multichoice':
            $parts = [];
            foreach ($layout['choices'] ?? array_keys($q['answers']) as $pos => $i) {
                if ((float)$q['answers'][$i]['fraction'] > 0) {
                    $parts[] = chr(97 + $pos) . ') ' . $plain($q['answers'][$i]['text']) . ((float)$q['answers'][$i]['fraction'] < 1 ? ' (' . round($q['answers'][$i]['fraction'] * 100) . '%)' : '');
                }
            }
            return implode('; ', $parts);
        case 'truefalse':
            return !empty($q['correct']) ? 'True' : 'False';
        case 'shortanswer':
            return implode(' / ', array_map(fn($a) => short_answer_text($a['text']), array_filter($q['answers'], fn($a) => (float)$a['fraction'] > 0)));
        case 'numerical':
            return implode(' / ', array_map('format_number_answer', array_filter($q['answers'], fn($a) => (float)$a['fraction'] > 0 && $a['value'] !== '*')));
        case 'match':
            $choices = match_choices($q);
            $order = $layout['choices'] ?? array_keys($choices);
            $parts = [];
            foreach ($layout['stems'] ?? match_stems($q) as $n => $stem) {
                $pos = array_search(array_search($q['pairs'][$stem]['answer'], $choices, true), $order, true);
                $parts[] = ($n + 1) . '–' . chr(97 + (int)$pos);
            }
            return implode(', ', $parts);
        case 'multiblank':
            return implode(' | ', array_map(fn($b, $i) => ($i + 1) . ': ' . key_text($b, $layout['blanks'][$i] ?? []), $q['blanks'], array_keys($q['blanks'])));
        case 'essay':
            return trim((string)($q['grader_info'] ?? '')) !== '' ? 'Key points: ' . $q['grader_info'] : '(marked by the teacher)';
    }
    return '';
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= h($exam['title']) ?> — version <?= $letter ?><?= $withKey ? ' (answer key)' : '' ?></title>
<style>
  @page { size: A4; margin: 16mm 14mm; }
  body { font-family: "Segoe UI", "Nirmala UI", "Noto Sans", "Noto Sans Tamil", sans-serif; font-size: 12.5pt; color: #111; margin: 0 auto; max-width: 800px; padding: 16px; }
  .toolbar { display: flex; gap: 8px; align-items: center; margin-bottom: 16px; font-size: 11pt; }
  .toolbar a, .toolbar button { padding: 6px 12px; border: 1px solid #bbb; border-radius: 8px; background: #fff; text-decoration: none; color: #111; cursor: pointer; font: inherit; }
  header { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 14px; }
  header h1 { font-size: 18pt; margin: 0 0 4px; }
  .meta { display: flex; justify-content: space-between; font-size: 11pt; }
  .fields { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px; margin-top: 10px; font-size: 11pt; }
  .fields span { border-bottom: 1px solid #111; padding-bottom: 14px; }
  .instructions { font-size: 11pt; margin: 10px 0; }
  h2 { font-size: 13pt; margin: 18px 0 8px; border-bottom: 1px solid #999; }
  .q { break-inside: avoid; margin: 10px 0 14px; display: grid; grid-template-columns: 30px 1fr 60px; gap: 6px; }
  .num { font-weight: 700; }
  .marks { text-align: right; font-size: 10pt; color: #444; }
  .text svg { max-width: 100%; height: auto; vertical-align: middle; }
  .text img { max-height: 200px; }
  ol.choices { margin: 6px 0 0; padding-left: 0; list-style: none; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 18px; }
  ol.choices li { display: flex; gap: 6px; }
  ol.choices li svg { max-height: 90px; width: auto; }
  .line { border-bottom: 1px solid #555; height: 26px; }
  .lines .line { margin-top: 4px; }
  table.match { border-collapse: collapse; width: 100%; margin-top: 6px; }
  table.match td { padding: 4px 8px; vertical-align: top; width: 50%; }
  table.match svg { max-height: 70px; width: auto; }
  .blank { display: inline-block; min-width: 110px; border-bottom: 1px solid #111; }
  .key { font-size: 11pt; }
  .key .q { grid-template-columns: 30px 1fr; margin: 4px 0; }
  @media print { .toolbar { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="toolbar">
  <button onclick="window.print()">🖨️ Print</button>
  <span>Version:</span>
  <?php for ($v = 1; $v <= 4; $v++): ?><a href="?id=<?= h(rawurlencode($exam['id'])) ?>&version=<?= $v ?><?= $withKey ? '&key=1' : '' ?>" style="<?= $v === $version ? 'background:#146EB4;color:#fff' : '' ?>"><?= chr(64 + $v) ?></a><?php endfor; ?>
  <a href="?id=<?= h(rawurlencode($exam['id'])) ?>&version=<?= $version ?><?= $withKey ? '' : '&key=1' ?>"><?= $withKey ? 'Question paper' : 'Answer key' ?></a>
  <a href="<?= h(url('teacher/exam_edit.php')) ?>?id=<?= h(rawurlencode($exam['id'])) ?>">Back</a>
</div>

<header>
  <h1><?= h($exam['title']) ?><?= $withKey ? ' — Answer key' : '' ?></h1>
  <div class="meta"><span><?= h(cfg('SCHOOL_NAME')) ?> · <?= h($exam['subject']) ?><?= $exam['classes'] ? ' · Class ' . h(implode(', ', $exam['classes'])) : '' ?></span>
    <span>Version <?= $letter ?> · Marks: <?= $fmt($total) ?><?= (int)$exam['time_limit_min'] > 0 ? ' · Time: ' . (int)$exam['time_limit_min'] . ' min' : '' ?></span></div>
  <?php if (!$withKey): ?><div class="fields"><span>Name:</span><span>Class:</span><span>Roll no:</span></div><?php endif; ?>
</header>
<?php if (!$withKey && trim($exam['instructions']) !== ''): ?><div class="instructions"><?= nl2br(h($exam['instructions'])) ?></div><?php endif; ?>

<div class="<?= $withKey ? 'key' : '' ?>">
<?php $number = 0;
$section = -1;
foreach ($slots as $slot):
    $q = $paper['questions'][$slot['key']];
    $layout = $slot['layout'];
    if ($slot['section'] !== $section) {
        $section = $slot['section'];
        if (($paper['sections'][$section]['title'] ?? '') !== '') {
            echo '<h2>' . h($paper['sections'][$section]['title']) . '</h2>';
        }
    }
    if ($q['type'] === 'description') {
        echo $withKey ? '' : '<div class="text">' . format_text($q['text'], $q['format'] ?? 'moodle', $mediaUrl) . '</div>';
        continue;
    }
    $number++;
    if ($withKey) {
        echo '<div class="q"><span class="num">' . $number . '.</span><span>' . h(key_text($q, $layout)) . '</span></div>';
        continue;
    }
    $format = $q['format'] ?? 'moodle';
    $html = format_text($q['text'], $format, $mediaUrl);
    if ($q['type'] === 'multiblank') {
        $html = preg_replace('/\{#\d+\}/', '<span class="blank"></span>', $html);
    }
    ?>
  <div class="q">
    <span class="num"><?= $number ?>.</span>
    <div>
      <div class="text"><?= $html ?></div>
      <?php switch ($q['type']):
          case 'multichoice': ?>
            <ol class="choices"><?php foreach ($layout['choices'] ?? array_keys($q['answers']) as $pos => $i): ?>
              <li><strong><?= chr(97 + $pos) ?>)</strong> <span><?= format_text($q['answers'][$i]['text'], $q['answers'][$i]['format'] ?? $format, $mediaUrl) ?></span></li>
            <?php endforeach; ?></ol>
            <?php break;
          case 'truefalse': ?>
            <div>True / False</div>
            <?php break;
          case 'match':
              $choices = match_choices($q);
              $stems = $layout['stems'] ?? match_stems($q);
              $order = $layout['choices'] ?? array_keys($choices); ?>
            <table class="match"><?php for ($r = 0; $r < max(count($stems), count($order)); $r++): ?>
              <tr>
                <td><?= isset($stems[$r]) ? ($r + 1) . '. ' . format_text($q['pairs'][$stems[$r]]['question'], $q['pairs'][$stems[$r]]['format'] ?? $format, $mediaUrl) . ' ____' : '' ?></td>
                <td><?= isset($order[$r]) ? chr(97 + $r) . ') ' . h($choices[$order[$r]]) : '' ?></td>
              </tr>
            <?php endfor; ?></table>
            <?php break;
          case 'shortanswer':
          case 'numerical':
              if (empty($q['missing_word'])): ?><div class="line"></div><?php endif;
              break;
          case 'essay': ?>
            <div class="lines"><?php for ($l = 0; $l < 5; $l++): ?><div class="line"></div><?php endfor; ?></div>
            <?php break;
      endswitch; ?>
    </div>
    <span class="marks">[<?= $fmt($slot['mark']) ?>]</span>
  </div>
<?php endforeach; ?>
</div>
</body>
</html>
