# ExamHall

Children in primary school take tests often, and paper tests take teachers a long time to mark. ExamHall is a simple web application for online exams and tests, written for Zion Nursery & Primary School, Kovaipudur.

There is **no database**. Everything is stored in plain JSON, CSV and text files that you can read, back up and keep in git.

## Features
- Question bank in **Moodle GIFT** format, one text file per chapter, with pictures. Import a file, a folder or a ZIP ([docs/gift.md](docs/gift.md)).
- **Reliable for children**: answers save automatically and keep saving offline, and the timer pauses while a computer has lost its connection.
- Questions shuffled for each student, and practice exams that check answers while the student writes.
- Sections where students choose (**"answer any five"**). The best answers are counted.
- Marking like Moodle's, with spelling slips accepted. A **local LLM** can mark short answers ([docs/llm.md](docs/llm.md)).
- Teachers can preview an exam as a student sees it, watch exams live and print A4 result sheets.
- Students can take exams on **WAP keypad phones** ([wap/README.md](wap/README.md)).
- Optional rewards paid with Zion Bank.
- Migration from a Moodle backup ([docs/moodle.md](docs/moodle.md)).

## Try it
The app comes with sample data: users, a question bank and five exams. Some of the exams have already been taken.

```sh
git clone https://github.com/zion-school/ExamHall.git
cd ExamHall
php -S 0.0.0.0:8080 router.php
```

Open http://localhost:8080/ on this computer, or `http://<this computer's IP>:8080/` from the other computers in the school. Log in as:

| Username | Password | Role |
|---|---|---|
| `admin` | `admin` | Admin |
| `t_ondra` | `ondra` | Teacher of English and Science |
| `t_tobin` | `tobin` | Teacher of Maths and Computer Science |
| `aril` | `1111` | Class 5 student |
| `cira` | `5555` | Class 4 student |

> NOTE: Always start PHP's built-in server with `router.php`. Without it, the server would let anyone download `data/` and `config.php`, which hold the passwords and answer keys.

## Installation
- Install [XAMPP](https://www.apachefriends.org/index.html) or any other web server with PHP 8.1 or newer.
- Download [as ZIP](https://github.com/zion-school/ExamHall/archive/refs/heads/main.zip) or clone the repository.
- Place the files in `C:\xampp\htdocs\ExamHall` or in the web server's root directory (Linux: `/var/www/html/ExamHall`).
- Linux needs a few PHP extensions:
  ```
  sudo apt install php-mbstring php-curl php-intl php-zip
  ```
- Remove the sample data with `php bin/sample_data.php --clear`, and make `data/` writable by the web server.

## Customization
Create `config.local.php` for your settings. It overrides `config.php` and is not committed.

```php
<?php
$APP_NAME = 'Our School Exams';
$SCHOOL_NAME = 'Our School';
$ADMIN_USERS = ['admin'];
$USERS_CSV = __DIR__ . '/../Zion_Bank/users.csv';
```

The users CSV file should look like this:

```
name,username,password,email,class,date_of_birth
Admin,admin,admin123,,,
Alice,alice,1234,,5,12-Apr-2016
```

> NOTE: This is the same format as Zion Bank's `users.csv`, so both apps can share logins. The file is only read, never written.

Then log in as an admin. Under **Teachers**, add your subjects and choose each teacher's subjects.

> **Keep answer keys private.** Apache uses the bundled `.htaccess` files, and `router.php` blocks the same folders for `php -S`. With nginx or another server, move `$DATA_DIR` outside the web root, or block `data/`, `lib/`, `bin/` and `tools/`.

See [docs/guide.md](docs/guide.md) for the teacher's guide and the command-line tools.

## License

MIT
