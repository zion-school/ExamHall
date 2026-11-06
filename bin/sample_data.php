<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 06-Nov-2026
 *
 * Make the sample data that ships with the app, so it can be tried straight after cloning:
 *
 *   php bin/sample_data.php            fill an empty data folder ($DATA_DIR)
 *   php bin/sample_data.php --reset    DELETE everything in the data folder first, then fill it
 *   php bin/sample_data.php --clear    DELETE everything in the data folder (start for real); writes nothing
 *
 * --reset and --clear ask you to type "yes" first (--yes skips the question, e.g. in scripts).
 * To leave data/ alone, point EXAMS_DATA_DIR (and EXAMS_USERS_CSV) at another folder.
 *
 * Writes users (data/users.csv), subjects, teachers, a question bank of four chapters (every
 * question type, pictures, sections) and five exams, three of them with finished tries:
 *   Plants - Practice          Science, class 5: practice, answers checked while writing
 *   Fractions Unit Test        Maths, class 5: 20 minutes, tries by four students
 *   English Term Test          English, class 5: "answer any two" written answers, one try marked,
 *                              three waiting for the teacher
 *   Computer Parts Quiz        Computer Science, class 4: reward exam, locked until a teacher unlocks it
 *   Living Things (draft)      Science, class 5: not published yet
 * Logins are listed in docs/guide.md (Sample data). Exams belong to the school year they are made in: run with
 * --reset in a later school year (or press "Reopen for this school year" on an exam).
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';

$clear = in_array('--clear', $argv, true);
$reset = $clear || in_array('--reset', $argv, true);
$dataDir = rtrim(cfg('DATA_DIR'), '/');
$keep = ['.htaccess', 'index.html'];
$existing = is_dir($dataDir) ? array_diff(scandir($dataDir), ['.', '..'], $keep) : [];
if ($existing && !$reset) {
    fwrite(STDERR, "$dataDir is not empty. Run with --reset to delete everything in it and make the sample again\n"
        . "(or --clear to only delete it).\n");
    exit(1);
}
if ($existing && !in_array('--yes', $argv, true)) {
    $files = 0;
    foreach ($existing as $name) {
        $files += is_dir("$dataDir/$name") && !is_link("$dataDir/$name")
            ? iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dataDir/$name", FilesystemIterator::SKIP_DOTS)))
            : 1;
    }
    $exams = count(array_filter(list_dirs("$dataDir/exams"), 'is_exam_id'));
    $question = "This DELETES $files files in $dataDir ($exams exams, with all their tries). Type yes to go on: ";
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "$question\nNot asked: no terminal. Add --yes to run without the question.\n");
        exit(1);
    }
    echo $question;
    if (trim((string)fgets(STDIN)) !== 'yes') {
        fwrite(STDERR, "Nothing deleted.\n");
        exit(1);
    }
}
foreach ($existing as $name) {
    $path = "$dataDir/$name";
    if (is_dir($path) && !is_link($path)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}
ensure_dir($dataDir);
if ($clear) {
    $kept = array_filter($keep, fn($f) => file_exists("$dataDir/$f"));
    exit("Emptied $dataDir" . ($kept ? ' (kept ' . implode(' and ', $kept) . ')' : '') . ".\n");
}
mt_srand(2026);   // the same answers every run

// ---------- People ----------

$usersFile = "$dataDir/users.csv";
write_csv($usersFile, ['name', 'username', 'password', 'email', 'class', 'date_of_birth'], [
    ['name' => 'Administrator', 'username' => 'admin', 'password' => 'admin', 'email' => '', 'class' => '', 'date_of_birth' => ''],
    ['name' => 'Ondra Vale', 'username' => 't_ondra', 'password' => 'ondra', 'email' => '', 'class' => '', 'date_of_birth' => ''],
    ['name' => 'Tobin Hask', 'username' => 't_tobin', 'password' => 'tobin', 'email' => '', 'class' => '', 'date_of_birth' => ''],
    ['name' => 'Aril Tovan', 'username' => 'aril', 'password' => '1111', 'email' => '', 'class' => '5', 'date_of_birth' => '12-Mar-2016'],
    ['name' => 'Belo Marrin', 'username' => 'belo', 'password' => '2222', 'email' => '', 'class' => '5', 'date_of_birth' => '04-Jul-2016'],
    ['name' => 'Davi Orlen', 'username' => 'davi', 'password' => '3333', 'email' => '', 'class' => '5', 'date_of_birth' => '21-Oct-2015'],
    ['name' => 'Fenn Rudo', 'username' => 'fenn', 'password' => '4444', 'email' => '', 'class' => '5', 'date_of_birth' => '09-Jan-2016'],
    ['name' => 'Cira Dunvale', 'username' => 'cira', 'password' => '5555', 'email' => '', 'class' => '4', 'date_of_birth' => '15-Aug-2017'],
    ['name' => 'Juno Pellan', 'username' => 'juno', 'password' => '6666', 'email' => '', 'class' => '4', 'date_of_birth' => '30-May-2017'],
]);
if (realpath(cfg('USERS_CSV')) !== realpath($usersFile)) {
    echo "Note: logins read " . cfg('USERS_CSV') . ", not the sample users in $usersFile.\n";
    $GLOBALS['USERS_CSV'] = $usersFile;   // still make the sample with them
}

write_csv(subjects_file(), ['subject', 'aliases'], [
    ['subject' => 'English', 'aliases' => 'ENG'],
    ['subject' => 'Maths', 'aliases' => 'MAT;Math;Mathematics'],
    ['subject' => 'Science', 'aliases' => 'SCI;EVS'],
    ['subject' => 'Computer Science', 'aliases' => 'CS;Computer-Science'],
]);
save_teachers(['t_ondra' => ['English', 'Science'], 't_tobin' => ['Maths', 'Computer Science']]);

$staff = fn(string $username) => [
    'username' => $username, 'name' => find_user($username)['name'], 'class' => '',
    'role' => user_role($username), 'subjects' => load_teachers()[$username] ?? [],
];
$ondra = $staff('t_ondra');
$tobin = $staff('t_tobin');

// ---------- Question bank: one GIFT file per chapter ----------

$chapters = [];
$chapters['V/Science/01_Plants'] = <<<'GIFT'
$CATEGORY: V/Science/01_Plants

// === Choose ===

::Green part::Which part of the plant is shown in green here?<br><img src\="media/plant.svg" alt\="" width\="160">{
	=Leaf
	~Root
	~Stem
	~Flower
}

::Food factory::Plants make their food in the{
	=leaves
	~roots
	~flowers
	~seeds
}

::Needs of a plant::Which of these does a plant need to make food? (Choose all that apply){
	~%33.33333%Sunlight
	~%33.33333%Water
	~%33.33333%Air
	~%-100%Sugar
}

// === True or false ===

::Roots absorb::Roots take in water from the soil.{T}

::Night food::Plants make food only at night.{F}

// === Fill in the blanks ===

// [tag:Fill up]
::Green pigment::The green colour of leaves comes from {=chlorophyll =chlorophyl}.

// [tag:Fill up]
::Photosynthesis::Plants make food by a process called {=photosynthesis} using {=sunlight =sun light =light} and {=carbon dioxide =CO2}.

// === Match ===

::Plant parts::Match each part with its work{
	=Root -> Holds the plant in the soil
	=Stem -> Carries water to the leaves
	=Leaf -> Makes food
	=Flower -> Turns into fruit
}

// === Answer briefly ===

// [zion:mark=2]
// [key:Sunlight, water and carbon dioxide; chlorophyll in the leaves; food (sugar) and oxygen are made]
::Why green::Why are leaves green, and what do they make?{}
GIFT;

$chapters['V/Maths/03_Fractions'] = <<<'GIFT'
$CATEGORY: V/Maths/03_Fractions

// === Choose ===

::Bigger fraction::Which fraction is the biggest?{
	~1/4
	~1/3
	=1/2
	~1/5
}

::Equivalent::Which fraction is equal to 2/4?{
	=1/2
	~2/8
	~4/2
	~1/4
}

// === Work it out ===

::Half of 18::What is half of 18?{#9}

::Quarter of 20::What is 1/4 of 20?{#5}

::Decimal::Write 3/4 as a decimal.{#0.75:0.001}

::Add fractions::1/5 + 2/5 \= ? (write it as a fraction){=3/5}

// [tag:Fill up]
::Pizza::A pizza is cut into {#8} equal slices. Belo eats 3 of them, so {#5} slices are left.

// === True or false ===

::Proper fraction::7/4 is a proper fraction.{F}
GIFT;

$chapters['V/English/02_Grammar'] = <<<'GIFT'
$CATEGORY: V/English/02_Grammar

// === Choose ===

::Noun::Which word is a noun?{
	~quickly
	=river
	~beautiful
	~run
}

::Past tense of go::Yesterday we ___ to the zoo.{
	~go
	~goes
	=went
	~going
}

::Opposite::Pick the word opposite in meaning to "ancient".{
	=modern
	~old
	~antique
	~huge
}

// === Fill in the blanks ===

// [tag:Fill up]
::Plural of child::One child, many {=children}.

// [tag:Fill up]
::Articles::She ate {=an ~a ~the} apple and {=a ~an} banana.

// === Match ===

::Animal sounds::Match the animal with its sound{
	=cat -> mews
	=dog -> barks
	=lion -> roars
	=duck -> quacks
}

// === Write ===

// [zion:mark=4]
// [key:Mentions the day, at least three things that happened, past tense, full sentences]
::Holiday::Write five sentences about your last holiday.{}

// [zion:mark=4]
// [key:Describes looks and habits of a pet, why the student likes it]
::Pet::Describe your favourite pet in five sentences.{}

// [zion:mark=4]
// [key:Names the person, how they help, why the student admires them]
::Helper::Write about a person in your neighbourhood who helps others.{}
GIFT;

$chapters['IV/Computer-Science/01_Computer_Parts'] = <<<'GIFT'
$CATEGORY: IV/Computer-Science/01_Computer_Parts

// === Choose ===

::Monitor::Identify this part of a computer.<br><img src\="media/monitor.svg" alt\="" width\="160">{
	=Monitor
	~Keyboard
	~Mouse
	~Printer
}

::Brain::Which part is called the brain of the computer?{
	=CPU
	~Monitor
	~Speaker
	~Mouse
}

::Input devices::Which of these are input devices? (Choose all that apply){
	~%50%Keyboard
	~%50%Mouse
	~%-100%Printer
	~%-100%Speaker
}

// === True or false ===

::Speakers::Speakers are used to hear sound.{T}

::Mouse typing::We type letters with the mouse.{F}

// === Name it ===

// [tag:One word]
::Printer::The device that prints on paper is the {=printer}.

// === Match ===

::Devices and uses::Match the device with its use{
	=Keyboard -> Typing
	=Mouse -> Pointing and clicking
	=Monitor -> Showing pictures and text
	=Speaker -> Playing sound
}
GIFT;

$pictures = [
    'V/Science/media/plant.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160"><rect width="160" height="160" fill="#fff"/>'
        . '<rect x="0" y="118" width="160" height="42" fill="#a0785a"/><path d="M80 120 V60" stroke="#6b8e23" stroke-width="6"/>'
        . '<ellipse cx="58" cy="78" rx="24" ry="10" fill="#2e9e44" transform="rotate(-25 58 78)"/>'
        . '<ellipse cx="102" cy="66" rx="24" ry="10" fill="#2e9e44" transform="rotate(25 102 66)"/>'
        . '<circle cx="80" cy="50" r="12" fill="#f4c20d"/><path d="M80 120 l-14 22 M80 120 l14 24 M80 120 v28" stroke="#5b3a1e" stroke-width="3"/></svg>',
    'IV/Computer-Science/media/monitor.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 140"><rect width="160" height="140" fill="#fff"/>'
        . '<rect x="14" y="10" width="132" height="88" rx="6" fill="#333"/><rect x="22" y="18" width="116" height="72" fill="#7fb3ff"/>'
        . '<rect x="70" y="98" width="20" height="20" fill="#555"/><rect x="44" y="118" width="72" height="8" rx="3" fill="#444"/></svg>',
];

foreach ($chapters as $category => $gift) {
    $file = bank_gift_file($category);
    ensure_dir(dirname($file));
    file_put_contents($file, $gift . "\n");
}
foreach ($pictures as $path => $svg) {
    ensure_dir(dirname(bank_path($path)));
    file_put_contents(bank_path($path), $svg . "\n");
}
bank_sync(true);
if ($problems = bank_problems()) {
    exit("Bank problems: " . json_encode($problems) . "\n");
}

/** Bank question keys of a chapter by title (file order); $only limits to these sections. */
function chapter(string $category, array $only = []): array
{
    $out = [];
    foreach (category_questions($category) as $q) {
        if (!$only || in_array($q['section'], $only, true)) {
            $out[$q['name']] = $q['id'];
        }
    }
    return $out;
}

/** Exam items for question keys: [key, ...] (1 mark each) or [key => mark]. */
function items(array $keys, ?float $mark = null): array
{
    return array_values(array_map(fn($k) => ['q' => $k, 'mark' => $mark ?? (float)(load_question($k)['default_mark'] ?? 1)], $keys));
}

function make_exam(array $teacher, string $title, string $subject, array $classes, array $sections, array $settings = [], bool $publish = true): array
{
    $exam = create_exam($teacher, $title, $subject, $classes);
    $exam = save_exam(array_replace($exam, ['sections' => $sections] + $settings), $teacher['username']);
    if (!$publish) {
        return $exam;
    }
    [$exam, $problems] = publish_exam($exam, $teacher);
    if ($problems) {
        exit("$title: " . json_encode($problems) . "\n");
    }
    return $exam;
}

// ---------- Exams ----------

$plants = chapter('V/Science/01_Plants');
make_exam($ondra, 'Plants - Practice', 'Science', ['5'], [
    ['title' => '', 'shuffle' => true, 'choose' => 0, 'items' => items(array_values(array_diff_key($plants, ['Why green' => 1])))],
], ['review' => 'immediate', 'instructions' => 'Practice as often as you like. Press Check to see if an answer is right.']);

$fractions = chapter('V/Maths/03_Fractions');
$fractionsExam = make_exam($tobin, 'Fractions Unit Test', 'Maths', ['5'], [
    ['title' => '', 'shuffle' => true, 'choose' => 0, 'items' => items(array_values($fractions))],
], [
    'time_limit_min' => 20, 'review' => 'score', 'attempts' => 1,
    'bands' => [['min' => 90, 'text' => 'Excellent!'], ['min' => 60, 'text' => 'Good work'], ['min' => 0, 'text' => 'Keep practising']],
]);

$grammar = chapter('V/English/02_Grammar');
$englishExam = make_exam($ondra, 'English Term Test', 'English', ['5'], [
    ['title' => 'Part A - Grammar', 'shuffle' => false, 'choose' => 0, 'items' => items(array_values(chapter('V/English/02_Grammar', ['Choose', 'Fill in the blanks', 'Match'])))],
    ['title' => 'Part B - Writing', 'shuffle' => false, 'choose' => 2, 'items' => items(array_values(chapter('V/English/02_Grammar', ['Write'])))],
], ['review' => 'after_close', 'per_page' => 0, 'attempts' => 1, 'instructions' => 'Answer every question in Part A. In Part B, write about any two topics.']);

$parts = chapter('IV/Computer-Science/01_Computer_Parts');
make_exam($tobin, 'Computer Parts Quiz', 'Computer Science', ['4'], [
    ['title' => '', 'shuffle' => true, 'choose' => 0, 'items' => items(array_values($parts))],
], ['reward' => ['enabled' => true] + EXAM_DEFAULTS['reward'], 'review' => 'score']);

make_exam($ondra, 'Living Things (draft)', 'Science', ['5'], [
    ['title' => '', 'shuffle' => true, 'choose' => 0, 'items' => items(array_values(chapter('V/Science/01_Plants', ['Choose', 'True or false'])))],
], [], false);

// ---------- Finished tries ----------

/** A student's response to a question: right, or a believable wrong one. */
function sample_response(array $q, bool $right)
{
    switch ($q['type']) {
        case 'multichoice':
            $fractions = array_map(fn($a) => (float)$a['fraction'], $q['answers']);
            $good = array_keys(array_filter($fractions, fn($f) => $f > 0));
            $bad = array_keys(array_filter($fractions, fn($f) => $f <= 0));
            if (!empty($q['single'])) {
                return $right ? array_search(max($fractions), $fractions, true) : $bad[mt_rand(0, count($bad) - 1)];
            }
            return $right ? $good : [$good[0], $bad[0]];
        case 'truefalse':
            return $right ? (bool)$q['correct'] : !$q['correct'];
        case 'shortanswer':
            $text = (string)$q['answers'][0]['text'];
            return $right ? (mt_rand(0, 2) ? $text : ucfirst($text) . ' ') : ['leafs', 'water', 'sun', 'childs', 'kepboard', '2/10'][mt_rand(0, 5)];
        case 'numerical':
            $v = (float)$q['answers'][0]['value'];
            return (string)($right ? $v : $v + mt_rand(1, 3));
        case 'match':
            $choices = match_choices($q);
            $r = [];
            foreach (match_stems($q) as $stem) {
                $r[$stem] = array_search($q['pairs'][$stem]['answer'], $choices, true);
            }
            if (!$right && count($r) > 1) {
                [$r[0], $r[1]] = [$r[1], $r[0]];
            }
            return $r;
        case 'multiblank':
            $r = [];
            foreach ($q['blanks'] as $i => $blank) {
                $r[$i] = sample_response($blank, $right || $i > 0);
            }
            return $r;
    }
    return null;
}

const SAMPLE_ESSAYS = [
    'Holiday' => [
        '<p>Last summer we went to Ooty. We travelled by train. It was very cold there. We saw the botanical garden and went boating on the lake. I bought a toy elephant.</p>',
        '<p>My holiday was in my grandmother\'s village. I played in the fields with my cousins. We plucked mangoes from the tree. In the evening we sat near the pond. I was sad when we came back.</p>',
        '<p>i went to beach. we play. it was fun</p>',
    ],
    'Pet' => [
        '<p>My pet is a dog called Tommy. He is brown with a white tail. He barks when strangers come. Every evening I take him for a walk. I love him because he is my best friend.</p>',
        '<p>I have a cat. Her name is Snowy and she is white. She drinks milk and sleeps all day. She plays with a ball of wool. I like her soft fur.</p>',
    ],
    'Helper' => [
        '<p>Mr. Merrow is our postman. He brings letters to every house, even in the rain. He knows everyone\'s name. He helped my grandfather fill a form. I admire him because he is always kind.</p>',
        '<p>Our neighbour Mrs. Brisk is a nurse. When I fell down she cleaned my wound. She helps old people get their medicines. Everybody likes her.</p>',
    ],
];

/** A finished try of $username, started $daysAgo days ago at about 10:05; $skill = chance of each answer being right. */
function sample_try(array $exam, string $username, float $skill, int $daysAgo, int $minutes, array $essays = []): array
{
    $paper = load_paper($exam['id']);
    $user = find_user($username) + ['username' => $username];
    $started = strtotime("-$daysAgo days 10:05") + mt_rand(0, 240);
    $a = new_attempt($exam, $paper, $user, 1 + count(list_attempts($exam['id'], $username)), null, null, $started);
    $answers = [];
    foreach ($a['slots'] as $slot) {
        $q = $paper['questions'][$slot['key']];
        if ($q['type'] === 'essay') {
            if (isset($essays[$q['name']])) {
                $answers[$slot['slot']] = $essays[$q['name']];
            }
            continue;
        }
        if (mt_rand(1, 100) <= 95) {   // now and then a question is left out
            $answers[$slot['slot']] = sample_response($q, mt_rand(1, 1000) <= $skill * 1000);
        }
    }
    $a['answers'] = sanitize_answers($paper, $a, $answers);
    $finished = $started + $minutes * 60 + mt_rand(0, 59);
    $a['events'] = ['saves' => count($answers) + mt_rand(2, 6), 'blur' => mt_rand(0, 1), 'offline_ms' => 0];
    $a['seq'] = $a['events']['saves'];
    $a['saved_at'] = $a['last_seen'] = date('c', $finished);
    $a = finish_attempt($a, $paper, false);
    $a['submitted'] = date('c', $finished);
    write_json(attempt_file($exam['id'], $username, $a['n']), $a);
    fire_hook('attempt.graded', $exam['id'], $username);
    return $a;
}

foreach (['aril' => 0.95, 'belo' => 0.7, 'davi' => 0.8, 'fenn' => 0.45] as $username => $skill) {
    sample_try($fractionsExam, $username, $skill, 3, mt_rand(9, 18));
}

$e = SAMPLE_ESSAYS;
$aril = sample_try($englishExam, 'aril', 0.9, 1, 32, ['Holiday' => $e['Holiday'][0], 'Pet' => $e['Pet'][0]]);
sample_try($englishExam, 'belo', 0.7, 1, 28, ['Holiday' => $e['Holiday'][1], 'Helper' => $e['Helper'][0]]);
sample_try($englishExam, 'davi', 0.8, 1, 35, ['Pet' => $e['Pet'][1], 'Helper' => $e['Helper'][1]]);
sample_try($englishExam, 'fenn', 0.5, 1, 22, ['Holiday' => $e['Holiday'][2]]);

// The teacher has marked Aril's writing; the others wait on the Marking page
$marks = $comments = [];
foreach ($aril['slots'] as $slot) {
    $name = load_paper($englishExam['id'])['questions'][$slot['key']]['name'];
    if ($name === 'Holiday') {
        [$marks[$slot['slot']], $comments[$slot['slot']]] = [4, 'Lovely details!'];
    } elseif ($name === 'Pet') {
        [$marks[$slot['slot']], $comments[$slot['slot']]] = [3.5, 'Good. Check your full stops.'];
    }
}
set_manual_marks($englishExam, 'aril', $aril['n'], $marks, 't_ondra', $comments);

load_users();   // writes the register of people (data/people.csv)
echo "Sample data written to $dataDir\n";
