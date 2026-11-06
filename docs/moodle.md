# Moving from Moodle

```sh
# 1. Look first (nothing is written)
python3 tools/moodle_migrate.py --backup path/to/moodle-backup \
    --data ./data --users-csv ../Zion_Bank/users.csv --dry-run
# 2. Migrate questions, quizzes (as exams) and finished attempts
python3 tools/moodle_migrate.py --backup ... --data ./data --users-csv ../Zion_Bank/users.csv
# 3. Check usermap.csv in the backup folder (map / create / skip, birth dates), then optionally
python3 tools/moodle_migrate.py --backup ... --data ./data --users-csv ../Zion_Bank/users.csv --create-bank-users
# Login history of the Moodle accounts (created, first visit, last login, last visit, last IP) -> logins.csv in the backup folder
python3 tools/moodle_migrate.py --backup ... --users-csv ../Zion_Bank/users.csv --export-logins
```

- Questions go to the bank as `<class>/<subject>/<category>.gift` files (pictures in `media/` next to them), the latest Moodle version of each (marked `moodle=<entry>` in its comment). Exams keep the wording each try saw, older versions included, and each exam question has its own copy. The PHP side writes them (`php` must be on the path, or pass `--php`).
- Subjects come from the course's name or code, matched against the subjects and aliases on the **Teachers** page, so add them before migrating (e.g. a subject *Social Science* with the alias `SSC` for courses `SSC4`, `SSC5`). Aliases match whole names only. Courses that match no subject are listed at the end of the run; only admins see their folders and exams until you add the subject and run again.
- Quizzes hidden in Moodle become published exams hidden from students, so their results can be opened.
- Attempts never submitted in Moodle come across in progress with their saved answers; the student opens the exam and carries on. A try changed in ExamHall (continued, or re-marked by a teacher) is never overwritten by a later run.
- Calculated questions become numerical questions with Moodle's saved wildcard values.
- Attempts keep Moodle's marks, are read-only and never paid rewards. Tries of students with no bank account keep the student's name inside the try. Flagged questions stay flagged, and essay answers keep their paragraphs and lists (one line each).
- A finished attempt whose answers are gone from Moodle comes across with only its mark; its pages say so and it cannot be re-marked.
- `--create-bank-users` appends missing students to Zion Bank with balance 0, their Moodle class and email, after backing up `users.csv` and `balance.csv`. Password: DDMM of the birth date in `usermap.csv`; else their Moodle password, taken from the account lists uploaded to Moodle when it still works there; else `0000`. Zion Bank's `users.csv` is then copied to `data/users.csv` (logins still read Zion Bank's file).
- Running it again updates what it created before (questions, exams and tries remember their Moodle entries) and keeps anything edited in ExamHall: a question is left alone when it no longer matches any of its Moodle versions, or was moved to another folder of the same subject. Nothing Moodle-specific is left in `data/` (an old `data/moodle/` folder is removed).
