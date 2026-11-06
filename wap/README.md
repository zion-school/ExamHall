# ExamHall on WAP phones

Students take their exams on a WAP phone with a 128x64 monochrome screen, a
keypad and two soft keys: WML 1.3, small enough for a WAP browser on an ESP32
([wap-browser-c](https://github.com/jabezwinston/wap-browser-c)). Built the
same way as the WAP versions of GiftShop and Zion Bank.

It is a small front end of this app, not a copy: the same logins, exams,
tries, marking, AI checks and Zion Bank rewards. The pages call the Exams lib
(`../lib`), and the data is the app's own `data/`. A student can start an exam
on a computer and finish it on a phone, or the other way round.

## What students can do
- Log in (Zion Bank's `users.csv`, with the web's throttling of wrong passwords).
- See their exams as on the web's home page: to do, locked, coming up, done.
- Start an exam: its facts and instructions, the exam code when it has one.
- Answer one question per screen. The title shows the question and the time
  left, e.g. `Q3/20 12 min`:
  - single choice and true/false: a list (`-` is no answer); several
    choices: a list to tick; short and number answers: a text field, in the
    blank (`_____`) when the question has one; a question with several
    blanks: a field or list in each; written answers: a text field;
  - a list's picker shows the question as its title. A choice longer than a
    picker row is listed in full above the list, lettered `a) b) c)`, and
    the rows start with the letter;
  - matching: a link per left side with its answer (`1 cat: meow`); the link
    shows the choices in full, a digit each, and a choice is saved at once;
  - pictures as small WBMPs, with their alt text.
- Picking or typing stays on the question; leaving it saves the answer:
  `Next>` (a link under the answer), Back (the right soft key: the question
  before), and Options: Go to (asks for a question number), Overview, Flag,
  Finish. "Answer any five" sections say how many are done.
- Overview, which is also where the exam is finished: `Answered 5 Not 2
  Flag 1`, and a table of the questions, 16 a page, each with a filled
  box (answered), an empty box (not) or a flag (flagged), and a link to
  it; Submit asks first. `Finish>` on the last
  question leads there. At time-up the phone submits on its own (and the
  server checks the time on every save).
- See the result: score, coins earned, each question's marks with an icon
  (a tick, a half circle for some marks, a cross, an hourglass while
  being marked) and, when the exam shows answers, the answer given and
  the right one. My results lists
  every finished exam.

Teachers and admins are told to use a computer. Exams, the question bank,
marking, results and printing stay on the web.

Different on a phone:
- A written answer is at most 120 letters, plain text. A longer one written on
  a computer is shown, not edited, so a phone never cuts it short.
- A try open on a phone never pauses for a lost connection: the phone is heard
  from only when a page loads, so a minute spent reading a question is not a
  lost connection (`attempt_pause_due()`, the try's `wap_epoch`).
- Opening the try on a computer (or another phone) takes it over, as between
  two computers. The phone says so on its next save, and Continue takes it back.

## How it works
- `lib/wap.php` loads the Exams app and holds what the pages share: the
  student and their exam, the try the phone holds, saving answers through
  `save_attempt_request()` (as the web player's autosave does), and question
  text as WML. `lib/wml.php` writes the decks, as in GiftShop and Zion Bank.
- The web pages' logic is shared, not copied: `student_home_exams()`,
  `student_start_exam()` and `student_results()` in `lib/exams.php` serve both
  `student/` and `wap/`, and the start card's facts and the result's score are
  the web's own (`exam_facts_html()`, `render_result_score()`) as plain lines.
- The left soft key: Options where students move around (Go to, Overview,
  Flag, Finish on a question; Home, My results on a result); one action where
  they send something (Login, Start, Submit). Each entry is a `<do>`: the
  first `accept`, the others named `options` ones (`soft_keys()`). On a
  question the right soft key is a `<do type="prev">` that saves, then goes
  back.
- A question's ways out set the variable `to` (next, prev, flag, goto,
  overview) and go to one card of the deck, `sv`, that posts the answer, so
  the form is in the deck once. The answer is in `a0, a1, ...` on every
  question (an ESP32 browser keeps 16 variables), set again when the card is
  entered, forward or back.
- `exams.wmls` checks on the phone before a page is fetched: an exam code
  typed, a number answer that is a number, a question number to go to (it
  asks for it with `Dialogs.prompt`). It asks before Submit, and shows
  the server's errors in a dialog, started by the card's `<timer>` (not by
  `onenterforward`: wap-browser-c caps chained navigations, its bugs.md #107).
- Forms post and redirect. Going Back, some browsers send a form again: to the
  page it was redirected to (wap-browser-c, its bugs.md #108), or to where it
  was posted (WML 1.3 9.2). Neither saves anything: a form's URL has `post=1`,
  and it carries the try's save count (`v`), so an answer from a page older
  than the last save is not saved again; a Start carries the tries finished.
- Time-up: the card's `ontimer` goes to a card that sends the answer on the
  screen to `sv` with `to=timeup` (wap-browser-c runs no `<onevent type="ontimer">`,
  its bugs.md #109).
- Pictures: `pic.php` fits an exam's picture to 120x48, dithers it with GD and
  serves it as a WBMP, cached in `data/cache/wap/`.
- Icons on the home page's groups (a pencil to do, a lock, a calendar
  coming up, a tick done), on a flagged question, in the Overview and the
  result, and on the start page's facts. They are 7x7 pictures in
  `lib/icons.txt`, which `php lib/make_icons.php` turns into 9x8 WBMPs in
  `icons/`; `wap_icon()` prints one, and `link_to()` takes one as its
  last argument. An icon sits on a text line as a letter does, two pixels
  before the text. Its `alt` is the text it stands for (`*`, `-` and `?`
  in the Overview), or empty when the text next to it says it all. The
  start page's facts keep the web's own icons (`exam_facts_html()`):
  `icon_markers()` marks the ones the phone has (clock, calendar, coin)
  in the text and `wml_line()` draws them. The browser fetches an icon
  once and keeps 6 images, so a card shows no more.
- A deck stays under ~3.5 KB, 160 nodes, 2 KB of strings and 32 focusable
  items; lists show 8 rows a page and the Overview 16 questions; a phone
  variable holds 192 bytes and a post at most 256.

## Use
Nothing to set up: point the phone at `http://<server>/ExamHall/` (the login
page sends a browser that asks for WML and not for HTML here: `wants_wml()`)
or `http://<server>/ExamHall/wap/`. Apache:
`.htaccess` adds the WMLScript and WBMP content types; `lib/` is closed
(`router.php` does the same for `php -S`).
