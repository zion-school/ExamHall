# Guide

- [Roles](#roles)
- [Optional questions ("Answer any five")](#optional-questions-answer-any-five)
- [Changing the pairs of a Match question](#changing-the-pairs-of-a-match-question)
- [Previewing an exam](#previewing-an-exam)
- [Printing result sheets](#printing-result-sheets)
- [Question bank files](#question-bank-files)
- [Promotions and school years](#promotions-and-school-years)
- [Students who leave school](#students-who-leave-school)
- [More settings](#more-settings)
- [Command-line tools](#command-line-tools)
- [Sample data](#sample-data)

## Roles

| Role | Who | Can |
|---|---|---|
| Admin | `$ADMIN_USERS` in config | Everything, and manage teachers and subjects |
| Teacher | `data/teachers.csv` | Question bank, exams, grading and rewards for **their subjects** |
| Student | Everyone else in `users.csv` | Take exams for their class and see results |

## Optional questions ("Answer any five")

A section of an exam can let students choose: in the exam editor its bar says **Answer any [ 5 ] of 6** (leave it empty when every question counts). The paper's maximum then holds only the best five, so a 6 × 3 section is worth 15 marks, not 18.

- Nothing is ever blocked. A student may answer all six; **the best five count** and the sixth is kept as an *extra* that costs nothing.
- The exam player says so plainly: a card of its own the first time the section comes up, an amber band with a live count above every question of the section ("You have answered 3. 2 more to go"), the questions grouped in the dot rail, and a finish screen that asks for five, not six — an unanswered extra never counts as "not answered".
- The result card, the printed result sheet and the marking page mark an answer that did not count as **Extra · not counted**. Written answers still need marking: mark them all, and the best five are picked afresh each time a mark changes.

## Changing the pairs of a Match question

An exam can give a Match question other pairs than the bank has, e.g. to agree with the printed paper. In the exam editor, open the question's preview (▸):

- 🗑 before a pair removes it from this exam, with its answer. A Match question keeps at least 2 pairs.
- Under the pairs, type a left side and a right side and press **Add pair** to add a pair of your own (plain text; a left side or answer that is already there is refused).
- Marks of 1 a pair follow the pairs (7 marks for 7 pairs become 5 when 2 are removed). Other marks stay as they are.
- Only the exam's own copy changes; the question bank keeps its pairs. The row then says **Pairs edited for this exam**, and **Use bank version** brings back the bank's pairs. Press **Save and publish** to update the paper students get. Like the other questions, the pairs are locked once students have started.

## Previewing an exam

Anyone who can edit an exam (its teachers and admins) can take it as a new student would: **Preview** in the exam editor's header, or ▷ on the Exams list. It opens in a new tab.
- **A draft** is saved first, then previewed from its saved questions. **A published exam** is previewed as the paper students get, and nothing is saved, so trying things out never changes a live exam.
- The start card, the player and the result are the students' own. Shuffles and random picks are drawn afresh each time. Practice exams can check answers.
- The time limit counts down and submits at time-up. The closing time, unlocks for reward exams and the exam code do not apply (the start card says what students will need).
- The result shows every question with your answer, its marks, how it was marked (e.g. a spelling slip accepted) and the correct answer, and says what students will see. A reward exam shows what a student with that score would get; nothing is paid.
- A preview is kept apart from the students' tries, in `data/exams/<exam>/previews/<username>/` (not tracked in git). It is never in results, the Monitor, CSVs, result sheets or rewards, gets no AI checking and never locks the questions. Only your latest preview of an exam is kept: **Start again** or **Preview again** replaces it.

## Printing result sheets

On an exam's **Results** page, tick students and press **Print selected**, or **Print all** (🖨️ next to a try prints just that try). The print page shows every A4 page before printing:
- **Colour** or **Black & white**;
- each student's sheet: name, date, start and finish times, marks, and every question with their answer, ✓/✗, marks, the correct answer and teacher comments (both can be turned off);
- **Double-sided**: a student who ends on the front of a sheet gets a blank page, so every student starts on their own sheet;
- written answers print with the formatting the student gave them, and a long one carries on over the next page (the box stays open where it is cut). In **Black & white** the student's colours print black and highlights light grey, so nothing is lost on a mono printer;
- **Attachments**: photos or PDFs uploaded on the 📎 Attachments page are printed after the student's sheet (PDF pages need `pdftoppm` from poppler-utils).

Press **Print or save as PDF** and choose Paper A4, Margins None, Scale 100% (and Two-sided; for colour, Background graphics); choose **Save as PDF** to download.

## Question bank files

The bank is plain GIFT, laid out as one `data/bank/<class>/<subject>/<chapter>.gift` per chapter, plus the pictures its questions use next to it (in `media/`, or at the path they were copied in with, such as `images/ch-1/`). The files hold no question ids; settings Moodle ignores go in a `// [zion:mark=2 hidden ...]` comment. Pages show each question by its section and number (*Fill in the blanks · 3*), and every exam keeps its own copy of its questions. The bank keeps no earlier versions or who changed what; the files are all there is. You can edit these files in a text editor, keep them in git, or copy chapter files in with `php bin/bank_copy.php`; the app picks up the changes on the next page load. See [gift.md §9](gift.md#9-the-question-bank-is-gift-files).

## Promotions and school years

Students are promoted by changing their class in `users.csv` (on any day). What they did before keeps its class:

- Every try keeps the student's name and class from when it was written. Results, CSV downloads, result sheets and reward formulas (`class`) use that class, not today's. Tries from before this was kept get it with `php bin/fsck.php --fix` (Moodle tries: the class of their Moodle course).
- Each published exam belongs to a school year (`$SCHOOL_YEAR_STARTS` in `config.php`, default 1 June): the latest of its opening and closing dates, its publishing and its reopening. Students cannot start an exam from an earlier school year, and it only shows in their list when they took it. **Reopen for this school year** on the exam page (or new dates) opens it again.
- A results page shows one school year at a time when an exam has tries from several (reopened in a later year); students who have not started are listed only for this school year. The CSV and "Print all" follow the chosen year.

## Students who leave school

Remove their line from `users.csv`. Nothing in `data/` is deleted:

- `data/people.csv` keeps everyone who was ever in `users.csv`: name, last class, the day they were first seen and the day they left. It is updated whenever `users.csv` changes; a `users.csv` that is empty or has lost more than half of its people at once is ignored.
- Every try keeps the student's name and class from when it started, so results, marking pages and result sheets still name them, with a **Left** badge. The results page can hide them; "Print all" can leave them out.
- **Students** (staff menu) lists everyone, including students who left, with all their tries across exams (teachers: their subjects).
- An exam keeps its classes and named students when saved, even if nobody in `users.csv` is in them any more.
- An exam page still open when the student is removed stops saving; the try stays as last saved.
- If a username comes back under a different name (given to a new student?), the register notes it; `php bin/fsck.php` and the Students page show it.

## More settings

Put these in `config.local.php` as needed (see `config.php` for all of them):

```php
// Zion Bank rewards
$BANK_BULK_TRANSFER_URL = 'http://localhost/Zion_Bank/bulk_transfer.php';
$REWARD_ACCOUNT_PASSWORD = '....';
// Local LLM for short answers (docs/llm.md)
$LLM_URL = 'http://192.168.1.50:8080/v1/chat/completions';
// Recommended: keep data outside the web root
$DATA_DIR = '/home/www/exams-data';
```

Requirements: PHP 8.1+ with `json`, `mbstring`, `curl` and `fileinfo`; `intl` and `zip` are recommended. Python 3 is needed only for the Moodle migration. If the data folder can be downloaded, the admin dashboard shows a warning.

## Command-line tools

| Command | What it does |
|---|---|
| `php bin/sample_data.php [--reset\|--clear] [--yes]` | Make the sample data in an empty data folder; `--reset` deletes the data folder first, `--clear` only deletes it, both after you type `yes` |
| `php bin/grade_worker.php [--once]` | AI checking of answers and reward payouts in the background ([llm.md](llm.md)) |
| `php bin/llm_bench.php` | Benchmark the local model on real answers before trusting it |
| `php bin/fsck.php [--fix]` | Check the data folder; re-read the bank's GIFT files and rebuild the index, submit expired attempts, check stuck payments |
| `php bin/bank_copy.php <folder> [--apply] [--only=FOLDER,...]` | Copy a folder of chapter GIFT files and the pictures they use (one `<class>/<subject>/<chapter>.gift` per chapter) into the bank, again whenever it changes; `--only` limits it to some folders and is remembered ([gift.md §9](gift.md#9-the-question-bank-is-gift-files)) |
| `php bin/bank_tool.php list\|store` | Question bank access for other scripts (used by the Moodle migration) |
| `php bin/gift_lint.php <folder> [--md]` | GIFT problems report |
| `python3 tools/moodle_migrate.py ...` | Move a Moodle backup into ExamHall ([moodle.md](moodle.md)) |

## Sample data

| Log in as | Password | Try |
|---|---|---|
| `admin` | `admin` | Everything: teachers and subjects, all exams, the Students page |
| `t_ondra` | `ondra` | Teacher of English and Science: mark the written answers of the **English Term Test**, edit the draft **Living Things** exam, the question bank |
| `t_tobin` | `tobin` | Teacher of Maths and Computer Science: results of the **Fractions Unit Test**, unlock the **Computer Parts Quiz** (a reward exam) for class 4 |
| `aril` | `1111` | Class 5 student: **Plants - Practice** (check answers while writing), their results |
| `belo`, `davi`, `fenn` | `2222`, `3333`, `4444` | Class 5 students |
| `cira`, `juno` | `5555`, `6666` | Class 4 students: the Computer Parts Quiz once a teacher unlocks it |

The sample exams belong to the school year they were made in (from 1 June). In a later school year, run `php bin/sample_data.php --reset` to make them again, or press **Reopen for this school year** on an exam.

To try it with throwaway data, leaving `data/` alone (run inside the `ExamHall` folder):

```sh
EXAMS_DATA_DIR=/tmp/exams-data EXAMS_USERS_CSV=/tmp/exams-data/users.csv php bin/sample_data.php
EXAMS_DATA_DIR=/tmp/exams-data EXAMS_USERS_CSV=/tmp/exams-data/users.csv php -S 0.0.0.0:8080 router.php
```
