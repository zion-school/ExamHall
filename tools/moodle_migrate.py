#!/usr/bin/env python3
# Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
# SPDX-License-Identifier: MIT
# Date: 17-Sep-2026
"""
Migrate a Moodle 4.x backup (SQL dump + moodledata filedir) into ExamHall.

What it moves
  questions  the latest version of every question bank entry into the GIFT bank
             (bank/<class>/<subject>/.../<chapter>.gift), categories, tags, id numbers, pictures
             (@@PLUGINFILE@@ -> media/ next to the chapter file). Written through php bin/bank_tool.php.
             A question changed in Exams since it was migrated is kept as it is.
  quizzes    quizzes -> exams (sections, marks, timing, tries, shuffling, review, password),
             frozen paper.json like a published exam (quizzes being deleted are skipped). A paper keeps
             the wording each try saw, older Moodle versions included (keys m<entry>@<moodle version>).
             Each exam question keeps its own copy of the question, like one added in the exam editor.
             Quizzes hidden in Moodle become published exams hidden from students.
  attempts   finished, non-preview attempts with their responses, orders and marks,
             kept read-only ("migrated"; never rewarded). Attempts never submitted in Moodle
             come across in progress with their saved answers, so the student can carry on.
             Flagged questions stay flagged; essay answers keep their lines and lists. A finished attempt
             whose answers are gone from Moodle keeps only its mark ("answers_lost").
             A try changed in Exams since it was migrated is never overwritten.

Users
  usermap.csv (next to the backup; --usermap to choose)
                            moodle_username,name,class,bank_username,date_of_birth,action
                            action: map (existing bank user) | create | skip
  The file is generated on the first run with username matches filled in; edit and run again.
  --create-bank-users appends "create" rows to Zion Bank's users.csv and balance.csv (balance 0), after
  taking backups. Password: DDMM of the birth date; else the Moodle password, from the account lists
  uploaded to Moodle if it still works in Moodle; else 0000. Email: the Moodle account's.
  The Bank's users.csv is then copied to data/users.csv (Exams' own copy; logins still use the Bank's).
  A student who left school (a left_on day in data/people.csv) is never created again.
  Tries of students without a bank account keep their name inside the try (history only).

Safe to re-run: questions remember their Moodle entry ([zion:moodle=567] in the GIFT file),
exams and tries theirs ("migrated"), so a second run updates them instead of adding copies. Nothing Moodle-specific is kept in data/.
A try keeps the student's name and class it already has (the class they were in then, which may have been corrected in Exams).

Usage
  python3 tools/moodle_migrate.py --backup path/to/moodle-backup \\
      --data ./data --users-csv ../Zion_Bank/users.csv [--only questions,quizzes,attempts] \\
      [--usermap FILE] [--create-bank-users] [--dry-run]
  python3 tools/moodle_migrate.py --backup ... --export-logins [FILE]
      only writes each Moodle account's creation date, first visit, last login, last visit and last IP address
      (logins.csv in the backup folder by default)
"""

import argparse
import ast
import contextlib
import csv
import hashlib
import fcntl
import html
import io
import json
import os
import random
import re
import shutil
import string
import subprocess
import sys
import tarfile
import tempfile
import unicodedata
import urllib.parse
import zlib
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone

IST = timezone(timedelta(hours=5, minutes=30))
FORMATS = {0: 'moodle', 1: 'html', 2: 'plain', 4: 'markdown'}
ROMAN = {1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V', 6: 'VI', 7: 'VII', 8: 'VIII', 9: 'IX', 10: 'X', 11: 'XI', 12: 'XII'}
MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
QUESTION_CONTENT_KEYS = [
    'type', 'name', 'text', 'format', 'general_feedback', 'general_feedback_format', 'default_mark', 'missing_word',
    'single', 'shuffle', 'answers', 'correct', 'feedback_true', 'feedback_false', 'feedback_true_format',
    'feedback_false_format', 'usecase', 'pairs', 'blanks', 'grader_info',
]
USERMAP_HEADER = ['moodle_username', 'name', 'class', 'bank_username', 'date_of_birth', 'action']
LOGINS_HEADER = ['moodle_username', 'name', 'exams_username', 'class', 'account_created', 'first_visit', 'last_login', 'last_visit', 'last_ip']


# ---------------------------------------------------------------- SQL dump

class Dump:
    """Reads tables from a mysqldump / mariadb-dump file (extended or one-row-per-line INSERTs)."""

    ESCAPES = {'0': '\0', 'n': '\n', 'r': '\r', 't': '\t', 'Z': '\x1a', 'b': '\b'}

    def __init__(self, path, prefix='mdl_'):
        with open(path, encoding='utf-8', errors='replace') as f:
            self.text = f.read()
        self.prefix = prefix
        self.columns = {}
        for m in re.finditer(r'CREATE TABLE `(\w+)` \((.*?)\n\)', self.text, re.S):
            self.columns[m.group(1)] = re.findall(r'^\s+`(\w+)`', m.group(2), re.M)
        self.cache = {}

    def table(self, name):
        full = self.prefix + name
        if full in self.cache:
            return self.cache[full]
        cols = self.columns.get(full)
        rows = []
        if cols:
            for m in re.finditer(r'INSERT INTO `%s` VALUES' % re.escape(full), self.text):
                for values in self._parse_rows(m.end()):
                    rows.append(dict(zip(cols, values)))
        self.cache[full] = rows
        return rows

    def _parse_rows(self, i):
        t = self.text
        n = len(t)
        while i < n:
            c = t[i]
            if c == ';':
                return
            if c != '(':
                i += 1
                continue
            i += 1
            row = []
            while True:
                while t[i] in ' \r\n\t':
                    i += 1
                if t[i] == "'":
                    i += 1
                    buf = []
                    while True:
                        j = i
                        while t[j] != '\\' and t[j] != "'":
                            j += 1
                        buf.append(t[i:j])
                        i = j
                        if t[i] == '\\':
                            buf.append(self.ESCAPES.get(t[i + 1], t[i + 1]))
                            i += 2
                        elif t[i + 1:i + 2] == "'":
                            buf.append("'")
                            i += 2
                        else:
                            i += 1
                            break
                    row.append(''.join(buf))
                else:
                    j = i
                    while t[j] not in ',)':
                        j += 1
                    token = t[i:j].strip()
                    row.append(None if token == 'NULL' else token)
                    i = j
                while t[i] in ' \r\n\t':
                    i += 1
                if t[i] == ',':
                    i += 1
                    continue
                i += 1   # ')'
                break
            yield row


# ---------------------------------------------------------------- Exams data folder (mirrors lib/storage.php and lib/ids.php)

class DataDir:
    def __init__(self, root, dry_run):
        self.root = os.path.abspath(root)
        self.dry = dry_run
        self.written = Counter()

    def path(self, *parts):
        return os.path.join(self.root, *[p for part in parts for p in part.split('/') if p])

    def lock_file(self, key):
        rel = key[len(self.root) + 1:] if key.startswith(self.root + os.sep) else key
        name = re.sub(r'[^A-Za-z0-9._-]+', '~', rel)
        if len(name) > 180:
            name = name[:150] + '-' + str(zlib.crc32(rel.encode()) & 0xFFFFFFFF)
        os.makedirs(self.path('.locks'), exist_ok=True)
        return self.path('.locks', name + '.lock')

    @contextlib.contextmanager
    def lock(self, key):
        if self.dry:
            yield
            return
        with open(self.lock_file(key), 'a') as f:
            fcntl.flock(f, fcntl.LOCK_EX)
            try:
                yield
            finally:
                fcntl.flock(f, fcntl.LOCK_UN)

    def write_bytes(self, path, data, kind='file'):
        self.written[kind] += 1
        if self.dry:
            return
        os.makedirs(os.path.dirname(path), exist_ok=True)
        tmp = os.path.join(os.path.dirname(path), '.%s.tmp%d_%d' % (os.path.basename(path), os.getpid(), random.randint(0, 999999999)))
        with open(tmp, 'wb') as f:
            f.write(data)
        os.replace(tmp, path)

    def write_json(self, path, obj, kind='json'):
        self.write_bytes(path, (json.dumps(obj, indent=4, ensure_ascii=False) + '\n').encode(), kind)

    @staticmethod
    def read_json(path, default=None):
        if not os.path.isfile(path):
            return default
        with open(path, encoding='utf-8') as f:
            return json.load(f)

    @staticmethod
    def read_csv(path):
        if not os.path.isfile(path):
            return []
        with open(path, newline='', encoding='utf-8-sig') as f:
            return list(csv.DictReader(f))

    def write_csv(self, path, header, rows, kind='csv'):
        buf = io.StringIO()
        w = csv.writer(buf, lineterminator='\n')
        w.writerow(header)
        for r in rows:
            w.writerow([r.get(h, '') if r.get(h) is not None else '' for h in header])
        self.write_bytes(path, buf.getvalue().encode(), kind)


def sanitize_filename(name):
    name = name.replace('/', '_').replace('\\', '_')
    name = re.sub(r'\s+', '_', name.strip())
    name = ''.join(ch for ch in name if ch in '._-' or unicodedata.category(ch)[0] in 'LN')
    name = name.lstrip('.')
    return name or 'file'


def normalize_category(path):
    parts = []
    for seg in re.split(r'[/\\]+', path):
        seg = seg.strip()
        if seg in ('', '.', '..'):
            continue
        seg = sanitize_filename(seg)
        if seg != 'media' and not seg.startswith('.'):
            parts.append(seg)
    return '/'.join(parts)


def slugify(text, max_len=40):
    text = unicodedata.normalize('NFKD', text).encode('ascii', 'ignore').decode().lower()
    slug = re.sub(r'[^a-z0-9]+', '-', text).strip('-')
    return slug[:max_len].rstrip('-')


def iso(ts):
    return datetime.fromtimestamp(int(ts), IST).isoformat() if ts and int(ts) > 0 else None


def local_input(ts):
    return datetime.fromtimestamp(int(ts), IST).strftime('%Y-%m-%dT%H:%M') if ts and int(ts) > 0 else ''


def local_time(ts):
    return datetime.fromtimestamp(int(ts), IST).strftime('%Y-%m-%d %H:%M') if ts and int(ts) > 0 else ''


def plain(text):
    text = re.sub(r'<svg\b.*?</svg>', ' ', text or '', flags=re.S | re.I)
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', text))).strip()


def essay_text(text):
    """An essay answer typed in Moodle's editor -> plain text, one line per paragraph, line break or list item."""
    text = text or ''
    if re.search(r'</?[A-Za-z][^>]*>', text):
        text = re.sub(r'<svg\b.*?</svg>', ' ', text, flags=re.S | re.I)
        text = re.sub(r'\s+', ' ', text)   # line ends inside HTML are only spacing
        text = re.sub(r'<li\b[^>]*>', '\n- ', text, flags=re.I)
        text = re.sub(r'<br\s*/?>|</(p|div|ul|ol|h[1-6]|tr)>', '\n', text, flags=re.I)
        text = html.unescape(re.sub(r'<[^>]+>', '', text))
    lines = [re.sub(r'[ \t]+', ' ', line.replace('\xa0', ' ')).strip() for line in text.replace('\r', '').split('\n')]
    return '\n'.join(line for line in lines if line not in ('', '-'))   # no blank lines or empty list items


def plain_name(name):
    """Moodle keeps names HTML-escaped ("The &lt;ol&gt; tag"); Exams keeps plain text."""
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', '', name or ''))).strip()


def attempt_hash(record):
    """Fingerprint of a migrated try, to notice changes made in Exams (the token changes every run)."""
    clean = {k: v for k, v in record.items() if k not in ('token', 'student')}   # student: filled in by Exams too (bin/fsck.php --fix)
    clean['migrated'] = {k: v for k, v in (record.get('migrated') or {}).items() if k != 'hash'}
    return hashlib.sha1(json.dumps(clean, sort_keys=True, ensure_ascii=False).encode()).hexdigest()[:16]


def subject_key(text):
    return re.sub(r'[^a-z0-9]+', '', text.lower())


# ---------------------------------------------------------------- Migration

class Migration:
    def __init__(self, args):
        self.args = args
        self.data = DataDir(args.data, args.dry_run)
        self.report = defaultdict(Counter)
        self.problems = []
        backup = args.backup
        if args.from_tar:
            self.tmp = tempfile.mkdtemp(prefix='moodle-migrate-')
            with tarfile.open(args.from_tar) as tar:
                members = [m for m in tar.getmembers() if '/db/' in m.name or '/files/filedir/' in m.name]
                tar.extractall(self.tmp, members=members, filter='data')
            backup = next(os.path.join(self.tmp, d) for d in os.listdir(self.tmp))
        self.backup = backup
        self.filedir = os.path.join(backup, 'files', 'filedir')
        print('Reading %s ...' % os.path.join(backup, 'db', 'moodle.sql'))
        self.db = Dump(os.path.join(backup, 'db', 'moodle.sql'))
        self.subjects = self.load_subjects()
        self.courses_without_subject = set()
        self.usermap_path = args.usermap or (args.backup.rstrip('/') + '/usermap.csv' if args.backup else args.from_tar + '.usermap.csv')

    # ---------- helpers ----------

    def by(self, table, key):
        out = defaultdict(list)
        for row in self.db.table(table):
            out[row[key]].append(row)
        return out

    def one(self, table, key='id'):
        return {row[key]: row for row in self.db.table(table)}

    def bank_tool(self, command, payload=None):
        """Run php bin/bank_tool.php against the data folder (the PHP side owns the GIFT bank format)."""
        env = dict(os.environ, EXAMS_DATA_DIR=self.data.root)
        tool = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'bin', 'bank_tool.php')
        result = subprocess.run([self.args.php, tool, command], input=json.dumps(payload) if payload is not None else '',
                                capture_output=True, text=True, env=env)
        if result.returncode != 0:
            sys.exit('bank_tool.php %s failed:\n%s%s' % (command, result.stdout, result.stderr))
        return result.stdout

    def existing_bank(self):
        """Questions already in the bank: moodle entry id -> {id (the hidden key), category, hidden, moodle}."""
        by_entry = {}
        if not os.path.isdir(self.data.path('bank')):
            return by_entry
        for line in self.bank_tool('list').splitlines():
            row = json.loads(line)
            if row.get('moodle') is not None:
                by_entry[str(row['moodle'])] = row
        return by_entry

    def existing_exams(self):
        """Exams made by an earlier run: moodle quiz id -> exam id."""
        out = {}
        root = self.data.path('exams')
        for exam_id in (os.listdir(root) if os.path.isdir(root) else []):
            exam = DataDir.read_json(os.path.join(root, exam_id, 'exam.json')) or {}
            quiz = (exam.get('migrated') or {}).get('quiz')
            if quiz is not None:
                out[str(quiz)] = exam_id
        return out

    def load_subjects(self):
        # data/subjects.csv as edited on the Teachers page; courses of a subject not listed keep their own folder name
        rows = DataDir.read_csv(self.data.path('subjects.csv'))
        return [(r['subject'].strip(), [a.strip() for a in (r.get('aliases') or '').split(';') if a.strip()]) for r in rows if (r.get('subject') or '').strip()]

    def normalize_subject(self, text):
        key = subject_key(text)
        for candidate in [key, re.sub(r'\d+$', '', key)]:
            if not candidate:
                continue
            for name, aliases in self.subjects:
                if subject_key(name) == candidate or any(subject_key(a) == candidate for a in aliases):
                    return name
        return None

    def store_unique_file(self, directory, name, content, write=True):
        """Name the picture gets in directory (same content, same name); write=False only works it out."""
        name = sanitize_filename(name)
        base, dot, ext = name.rpartition('.')
        if not dot:
            base, ext = name, ''
        n = 1
        while True:
            candidate = name if n == 1 else '%s-%d%s' % (base, n, '.' + ext if ext else '')
            path = os.path.join(directory, candidate)
            planned = self._planned_files.get(path) if self.data.dry else None
            if not os.path.exists(path) and planned is None:
                if write:
                    self.data.write_bytes(path, content, 'picture')
                    if self.data.dry:
                        self._planned_files[path] = content
                return candidate
            existing = planned if planned is not None else open(path, 'rb').read()
            if existing == content:
                return candidate
            n += 1

    _planned_files = {}

    # ---------- courses ----------

    def course_info(self, course_id):
        course = self.courses.get(str(course_id))
        if not course:
            return {'class': '', 'roman': 'Other', 'subject': None, 'folder': 'Other'}
        cat = self.course_categories.get(course['category'], {})
        cls = cat.get('idnumber') or ''
        if not cls.isdigit():
            roman_to_int = {v: k for k, v in ROMAN.items()}
            cls = str(roman_to_int.get((cat.get('name') or '').strip().upper(), ''))
        subject = self.normalize_subject(course['fullname']) or self.normalize_subject(course['shortname'])
        if subject is None:
            self.courses_without_subject.add('%s (%s)' % (course['fullname'], course['shortname']))
        folder = (subject or course['fullname']).replace(' ', '-')
        roman = ROMAN.get(int(cls), cls) if cls.isdigit() else (cat.get('name') or 'Other')
        return {'class': cls, 'roman': roman, 'subject': subject, 'folder': folder, 'name': course['fullname']}

    def context_course(self, context_id):
        ctx = self.contexts.get(str(context_id))
        if not ctx:
            return None
        if ctx['contextlevel'] == '50':
            return ctx['instanceid']
        if ctx['contextlevel'] == '70':
            cm = self.course_modules.get(ctx['instanceid'])
            return cm['course'] if cm else None
        return None

    # ---------- questions ----------

    def category_path(self, cat_id):
        names = []
        cat = self.qcats.get(str(cat_id))
        course_id = self.context_course(cat['contextid']) if cat else None
        while cat and not (cat['parent'] == '0' and cat['name'] == 'top'):
            names.append(cat['name'])
            cat = self.qcats.get(cat['parent'])
        names.reverse()
        if names and names[0].lower().startswith('default for'):
            names = names[1:]
        info = self.course_info(course_id) if course_id else {'roman': 'System', 'folder': 'Shared'}
        return normalize_category('/'.join([info['roman'], info['folder']] + names)), course_id

    def convert_question(self, q):
        """Moodle question row -> our question array (texts still contain @@PLUGINFILE@@)."""
        qid = q['id']
        fmt = FORMATS.get(int(q['questiontextformat']), 'moodle')
        out = {
            'type': q['qtype'], 'name': plain_name(q['name']), 'text': q['questiontext'], 'format': fmt,
            'general_feedback': q['generalfeedback'] or '', 'default_mark': float(q['defaultmark']), 'missing_word': False,
        }
        gf_fmt = FORMATS.get(int(q['generalfeedbackformat'] or 0), fmt)
        if out['general_feedback'] and gf_fmt != fmt:
            out['general_feedback_format'] = gf_fmt
        answers = sorted(self.answers.get(qid, []), key=lambda a: int(a['id']))

        def item(a, text_key='text', with_format=True):
            entry = {text_key: a['answer']}
            afmt = FORMATS.get(int(a['answerformat'] or 0), fmt)
            if with_format and afmt != fmt:
                entry['format'] = afmt
            entry['fraction'] = float(a['fraction'])
            if a['feedback']:
                entry['feedback'] = a['feedback']
                ffmt = FORMATS.get(int(a['feedbackformat'] or 0), fmt)
                if ffmt != fmt:
                    entry['feedback_format'] = ffmt
            return entry

        t = q['qtype']
        if t == 'multichoice':
            opts = self.mc_options.get(qid, {})
            out['single'] = opts.get('single', '1') == '1'
            out['shuffle'] = opts.get('shuffleanswers', '1') == '1'
            out['answers'] = [item(a) for a in answers]
        elif t == 'truefalse':
            tf = self.truefalse.get(qid)
            by_id = {a['id']: a for a in answers}
            true_a, false_a = by_id.get(tf['trueanswer']) if tf else None, by_id.get(tf['falseanswer']) if tf else None
            out['correct'] = bool(true_a and float(true_a['fraction']) >= 1)
            out['feedback_true'] = true_a['feedback'] if true_a else ''
            out['feedback_false'] = false_a['feedback'] if false_a else ''
        elif t == 'shortanswer':
            out['usecase'] = self.sa_options.get(qid, {}).get('usecase', '0') == '1'
            out['answers'] = [dict(item(a, with_format=False), text=html.unescape(a['answer'])) for a in answers]
        elif t == 'numerical':
            tol = {n['answer']: float(n['tolerance'] or 0) for n in self.numerical.get(qid, [])}
            out['answers'] = []
            for a in answers:
                entry = {'value': '*' if a['answer'] == '*' else float(a['answer']), 'tolerance': tol.get(a['id'], 0.0), 'fraction': float(a['fraction'])}
                if a['feedback']:
                    entry['feedback'] = a['feedback']
                out['answers'].append(entry)
        elif t == 'match':
            out['shuffle'] = self.match_options.get(qid, {}).get('shuffleanswers', '1') == '1'
            out['pairs'] = []
            for s in sorted(self.subquestions.get(qid, []), key=lambda s: int(s['id'])):
                pair = {'question': s['questiontext'] or ''}
                sfmt = FORMATS.get(int(s['questiontextformat'] or 0), fmt)
                if sfmt != fmt:
                    pair['format'] = sfmt
                pair['answer'] = html.unescape(s['answertext'])
                out['pairs'].append(pair)
        elif t == 'essay':
            out['grader_info'] = plain(self.essay_options.get(qid, {}).get('graderinfo') or '')
        elif t == 'description':
            out['default_mark'] = 0.0
        elif t == 'calculated' and self.calculated_to_numerical(q, out, answers):
            self.report['questions']['(calculated -> numerical)'] += 1
        else:
            self.report['questions']['unsupported_%s' % t] += 1
            out = {'type': 'description', 'name': plain_name(q['name']), 'format': fmt, 'default_mark': 0.0, 'missing_word': False, 'general_feedback': '',
                   'text': q['questiontext'] + '<p><em>(This %s question could not be migrated.)</em></p>' % t}
        return {k: out[k] for k in QUESTION_CONTENT_KEYS if k in out}

    def dataset_values(self, qid):
        """Wildcard values of a calculated question: Moodle's first saved dataset item, else the range minimum."""
        values = {}
        for ds in self.datasets.get(qid, []):
            definition = self.dataset_definitions.get(ds['datasetdefinition'])
            if not definition:
                continue
            items = sorted(self.dataset_items.get(definition['id'], []), key=lambda i: int(i['itemnumber']))
            if items:
                values[definition['name']] = float(items[0]['value'])
            else:
                bits = (definition['options'] or '').split(':')
                values[definition['name']] = float(bits[1]) if len(bits) > 1 and bits[1] else 1.0
        return values

    @staticmethod
    def safe_eval(expression):
        """Arithmetic only: numbers, + - * / ** and brackets."""
        def walk(node):
            if isinstance(node, ast.Expression):
                return walk(node.body)
            if isinstance(node, ast.Constant) and isinstance(node.value, (int, float)):
                return float(node.value)
            if isinstance(node, ast.UnaryOp) and isinstance(node.op, (ast.USub, ast.UAdd)):
                return -walk(node.operand) if isinstance(node.op, ast.USub) else walk(node.operand)
            if isinstance(node, ast.BinOp) and isinstance(node.op, (ast.Add, ast.Sub, ast.Mult, ast.Div, ast.Pow)):
                a, b = walk(node.left), walk(node.right)
                return {ast.Add: a + b, ast.Sub: a - b, ast.Mult: a * b, ast.Div: a / b if b else float('nan'), ast.Pow: a ** b}[type(node.op)]
            raise ValueError('unsupported expression')
        return walk(ast.parse(expression, mode='eval'))

    def calculated_to_numerical(self, q, out, answers):
        """A calculated question becomes a numerical one with Moodle's saved wildcard values filled in."""
        values = self.dataset_values(q['id'])
        fill = lambda text: re.sub(r'\{([A-Za-z_][A-Za-z0-9_]*)\}', lambda m: ('%g' % values[m.group(1)]) if m.group(1) in values else m.group(0), text or '')
        settings = {c['answer']: c for c in self.calculated.get(q['id'], [])}
        out['type'] = 'numerical'
        out['text'] = fill(q['questiontext'])
        out['answers'] = []
        for a in answers:
            try:
                value = self.safe_eval(fill(a['answer']))
            except (ValueError, SyntaxError, ZeroDivisionError):
                return False
            c = settings.get(a['id'], {})
            decimals = int(c.get('correctanswerlength') or 2) if c.get('correctanswerformat', '1') == '1' else 6
            value = round(value, decimals)
            tolerance = float(c.get('tolerance') or 0)
            if c.get('tolerancetype', '1') in ('1', '3'):   # relative (and geometric, approximated)
                tolerance = abs(value) * tolerance
            entry = {'value': value, 'tolerance': round(tolerance, 6), 'fraction': float(a['fraction'])}
            if a['feedback']:
                entry['feedback'] = a['feedback']
            out['answers'].append(entry)
        return bool(out['answers'])

    def rewrite_pictures(self, qrow, content, target_dir, rel_prefix='media/', write=True):
        """Copy @@PLUGINFILE@@ pictures of a question into target_dir; rewrite references."""
        item_ids = {qrow['id']} | {a['id'] for a in self.answers.get(qrow['id'], [])}
        files = {}
        for iid in item_ids:
            for f in self.files_by_item.get(iid, []):
                files[f['filename']] = f

        def replace(match):
            name = urllib.parse.unquote(match.group(1).split('?')[0])   # the editor may add ?timestamp
            f = files.get(name)
            if not f:
                self.problems.append('Question %s: picture %s not found' % (qrow['id'], name))
                return match.group(0)
            h = f['contenthash']
            src = os.path.join(self.filedir, h[:2], h[2:4], h)
            if not os.path.isfile(src):
                self.problems.append('Question %s: picture %s missing in filedir' % (qrow['id'], name))
                return match.group(0)
            with open(src, 'rb') as fh:
                stored = self.store_unique_file(target_dir, name, fh.read(), write)
            if write:
                self.report['pictures']['copied'] += 1
            return rel_prefix + stored

        def walk(value):
            if isinstance(value, str):
                return re.sub(r'@@PLUGINFILE@@/([^"\'\s)<>]+)', replace, value)
            if isinstance(value, list):
                return [walk(v) for v in value]
            if isinstance(value, dict):
                return {k: walk(v) if k in ('text', 'feedback', 'question', 'general_feedback', 'feedback_true', 'feedback_false', 'grader_info', 'answers', 'pairs') else v for k, v in value.items()}
            return value

        return walk(content)

    def migrate_questions(self):
        print('Questions ...')
        existing = self.existing_bank()
        tags_by_question = defaultdict(list)
        tag_names = {t['id']: t['rawname'] for t in self.db.table('tag')}
        for ti in self.db.table('tag_instance'):
            if ti['itemtype'] == 'question':
                tags_by_question[ti['itemid']].append(tag_names.get(ti['tagid'], ''))

        self.our_question = {}      # moodle question id (any version) -> (moodle entry id, moodle version number)
        self.bank_copy = {}         # moodle entry id -> {'category': ...}
        self.bank_key = {int(e): row['id'] for e, row in existing.items()}   # moodle entry id -> the bank's key
        versions_by_entry = defaultdict(list)
        for v in self.db.table('question_versions'):
            versions_by_entry[v['questionbankentryid']].append(v)

        to_store = []
        for entry in self.db.table('question_bank_entries'):
            versions = sorted(versions_by_entry.get(entry['id'], []), key=lambda v: int(v['version']))
            versions = [v for v in versions if v['questionid'] in self.questions]
            if not versions:
                continue
            latest = versions[-1]
            category, course_id = self.category_path(entry['questioncategoryid'])
            entry_id = int(entry['id'])
            self.bank_copy[entry_id] = {'category': category}
            # Every version goes to bank_tool.php: a bank copy matching none of them was changed in Exams.
            # Only the latest one is stored, so only its pictures are copied.
            records = []
            for v in versions:
                qrow = self.questions[v['questionid']]
                self.our_question[qrow['id']] = (entry_id, int(v['version']))
                content = self.rewrite_pictures(qrow, self.convert_question(qrow), self.data.path('bank', os.path.dirname(category), 'media'), write=v is latest)
                record = {'moodle': entry_id}
                record.update(content)
                record.update({
                    'idnumber': entry.get('idnumber') or '',
                    'tags': [t for t in tags_by_question.get(qrow['id'], []) if t],
                    'hidden': v['status'] == 'hidden',
                })
                records.append(record)
                if v is latest:
                    self.report['questions'][record['type']] += 1
                    if record['hidden']:
                        self.report['questions']['(hidden)'] += 1
                else:
                    self.report['questions']['(older Moodle versions, not kept)'] += 1
            to_store.append({'category': category, 'versions': records})
        self.data.written['question'] += len(to_store)
        if to_store and not self.data.dry:
            result = json.loads(self.bank_tool('store', {'questions': to_store}))
            self.bank_key = {int(e): key for e, key in result.get('keys', {}).items()}
            kept = result.get('kept_edited', 0) + result.get('kept_moved', 0)
            if result['stored'] + kept != len(to_store):
                self.problems.append('Only %d of %d questions were stored in the bank' % (result['stored'] + kept, len(to_store)))
            if result.get('kept_edited'):
                self.report['questions']['(kept, edited in Exams)'] = result['kept_edited']
            if result.get('kept_moved'):
                self.report['questions']['(kept, moved to another folder in Exams)'] = result['kept_moved']
            if result.get('moved'):
                self.report['questions']['(moved to their subject folder)'] = result['moved']
            for folder in result.get('removed_folders', []):
                print('  removed empty bank folder %s' % folder)

    # ---------- users ----------

    def user_classes(self):
        """moodle user id -> class digit from student enrolments."""
        enrol_course = {e['id']: e['courseid'] for e in self.db.table('enrol')}
        classes = defaultdict(Counter)
        for ue in self.db.table('user_enrolments'):
            info = self.course_info(enrol_course.get(ue['enrolid']))
            if info['class']:
                classes[ue['userid']][info['class']] += 1
        return {uid: c.most_common(1)[0][0] for uid, c in classes.items()}

    def migrate_users(self):
        print('Users ...')
        bank_users = {r['username'].strip().lower(): r for r in DataDir.read_csv(self.args.users_csv)} if self.args.users_csv else {}
        usermap_path = self.usermap_path
        old_usermap = self.data.path('moodle', 'usermap.csv')   # where earlier versions kept it
        if not os.path.isfile(usermap_path) and os.path.isfile(old_usermap):
            usermap_path = old_usermap
        existing = {r['moodle_username']: r for r in DataDir.read_csv(usermap_path)}
        usermap_path = self.usermap_path
        classes = self.user_classes()
        with_attempts = {a['userid'] for a in self.db.table('quiz_attempts') if a['preview'] == '0' and a['state'] == 'finished'}
        # Students who left school (removed from users.csv): Exams' register has the day
        self.left_school = {r['username'].lower(): r['left_on'] for r in DataDir.read_csv(self.data.path('people.csv')) if r.get('left_on')}
        rows = []
        self.username_of = {}     # moodle user id -> username used in Exams (or None)
        self.name_of = {}         # username used in Exams -> name (the bank's, else the user map's)
        self.archived = []
        for u in self.db.table('user'):
            if u['deleted'] == '1' or u['username'] in ('guest', 'admin'):
                continue
            name = ('%s %s' % (u['firstname'], u['lastname'])).strip()
            row = existing.get(u['username'])
            if not row:
                match = bank_users.get(u['username'].lower())
                row = {
                    'moodle_username': u['username'], 'name': name, 'class': classes.get(u['id'], ''),
                    'bank_username': match['username'] if match else '', 'date_of_birth': '',
                    'action': 'map' if match else ('create' if u['id'] in with_attempts else 'skip'),
                }
                self.report['users']['new in usermap'] += 1
            rows.append(row)
            action = row['action'].strip().lower()
            bank = bank_users.get((row['bank_username'].strip() if action == 'map' else row['moodle_username']).lower())
            self.name_of[row['bank_username'].strip() if action == 'map' and row['bank_username'].strip() else u['username']] = (bank or {}).get('name') or row['name']
            if action == 'map' and row['bank_username'].strip():
                self.username_of[u['id']] = row['bank_username'].strip()
                self.report['users']['mapped to bank users'] += 1
            elif action == 'create':
                self.username_of[u['id']] = row['moodle_username']
                self.report['users']['to create in bank'] += 1
                if row['moodle_username'].lower() not in bank_users and (not self.args.create_bank_users or row['moodle_username'].lower() in self.left_school):
                    self.archived.append(row)
            else:
                self.username_of[u['id']] = u['username'] if u['id'] in with_attempts else None
                if u['id'] in with_attempts:
                    self.archived.append(row)
                self.report['users']['skipped'] += 1
        self.data.write_csv(usermap_path, USERMAP_HEADER, rows, 'usermap')
        print('  user map: %s' % usermap_path)
        if self.args.create_bank_users:
            self.create_bank_users([r for r in rows if r['action'].strip().lower() == 'create'], bank_users)
        self.archived_names = {r['moodle_username']: r for r in self.archived}
        self.report['users']['history only (no bank account)'] = len(self.archived)

    def create_bank_users(self, rows, bank_users):
        users_csv = self.args.users_csv
        balance_csv = os.path.join(os.path.dirname(users_csv), 'balance.csv')
        missing = [r for r in rows if r['moodle_username'].lower() not in bank_users]
        new = [r for r in missing if r['moodle_username'].lower() not in self.left_school]
        for r in missing:
            if r not in new:
                print('  not created: %s left school on %s (data/people.csv)' % (r['moodle_username'], self.left_school[r['moodle_username'].lower()]))
                self.report['users']['not created (left school)'] += 1
        if not new:
            self.copy_bank_users()
            return
        stamp = datetime.now(IST).strftime('%Y%m%d-%H%M%S')
        moodle_users = {u['username']: u for u in self.db.table('user')}
        moodle_passwords = self.moodle_passwords()
        header = next(csv.reader(open(users_csv, encoding='utf-8-sig')), None) if os.path.isfile(users_csv) else None
        header = header or ['name', 'username', 'password', 'email', 'class', 'date_of_birth']
        lines, balances = [], []
        for r in new:
            dob, password, source = '', moodle_passwords.get(r['moodle_username']), 'Moodle password'
            if r['date_of_birth'].strip():
                d = self.parse_date(r['date_of_birth'])
                if d:
                    dob = '%02d-%s-%04d' % (d.day, MONTHS[d.month - 1], d.year)
                    password, source = '%02d%02d' % (d.day, d.month), 'DDMM'
                else:
                    self.problems.append('Could not read date_of_birth "%s" for %s' % (r['date_of_birth'], r['moodle_username']))
            if not password:
                password, source = '0000', '0000'
            values = {'name': r['name'], 'username': r['moodle_username'], 'password': password,
                      'email': moodle_users.get(r['moodle_username'], {}).get('email', ''), 'class': r['class'], 'date_of_birth': dob}
            buf = io.StringIO()
            csv.writer(buf, lineterminator='\n').writerow([values.get(h.strip(), '') for h in header])
            lines.append(buf.getvalue())
            balances.append('%s,0\n' % r['moodle_username'])
            print('  bank user: %s (%s), class %s, password %s' % (r['moodle_username'], r['name'], r['class'] or '-', source))
        self.report['users']['created in bank'] = len(new)
        if self.data.dry:
            return
        for path in (users_csv, balance_csv):
            if os.path.isfile(path):
                shutil.copy2(path, '%s.bak-%s' % (path, stamp))
        for path, content in ((users_csv, lines), (balance_csv, balances)):
            with open(path, 'a+', encoding='utf-8') as f:
                f.seek(0, os.SEEK_END)
                if f.tell() > 0:
                    f.seek(f.tell() - 1)
                    if f.read(1) != '\n':
                        f.write('\n')
                f.writelines(content)
        self.copy_bank_users()

    def copy_bank_users(self):
        """Exams keeps its own copy of Zion Bank's users.csv in data/ (logins still read Zion Bank's file)."""
        with open(self.args.users_csv, 'rb') as f:
            self.data.write_bytes(self.data.path('users.csv'), f.read(), 'users.csv copy')
        if not self.data.dry:
            print('  copied %s to %s' % (self.args.users_csv, self.data.path('users.csv')))

    def moodle_passwords(self):
        """username -> password from the account lists uploaded to Moodle (CSV files kept in its file store),
        only where it is still the password of the Moodle account (checked with PHP's password_verify)."""
        found = {}
        uploads = [f for f in self.db.table('files') if f['filename'].lower().endswith('.csv') and f['filename'] != '.']
        for f in sorted(uploads, key=lambda f: int(f['timecreated'] or 0)):
            h = f['contenthash']
            path = os.path.join(self.filedir, h[:2], h[2:4], h)
            if not os.path.isfile(path):
                continue
            with open(path, encoding='utf-8-sig', errors='replace') as fh:
                for row in csv.DictReader(fh):
                    if (row.get('username') or '').strip() and (row.get('password') or '').strip():
                        found[row['username'].strip()] = row['password'].strip()
        hashes = {u['username']: u['password'] for u in self.db.table('user')}
        pairs = [[u, hashes[u], p] for u, p in sorted(found.items()) if hashes.get(u)]
        if not pairs:
            return {}
        check = 'foreach (json_decode(stream_get_contents(STDIN), true) as [$u, $h, $p]) { if (password_verify($p, $h)) echo $u, "\\n"; }'
        result = subprocess.run([self.args.php, '-r', check], input=json.dumps(pairs), capture_output=True, text=True)
        if result.returncode != 0:
            self.problems.append('Could not check Moodle passwords: %s' % result.stderr.strip())
            return {}
        still = set(result.stdout.split())
        return {u: found[u] for u in still}

    @staticmethod
    def parse_date(text):
        for fmt in ('%d-%b-%Y', '%d-%B-%Y', '%Y-%m-%d', '%d/%m/%Y', '%d-%m-%Y', '%d.%m.%Y'):
            try:
                return datetime.strptime(text.strip(), fmt)
            except ValueError:
                pass
        return None

    def migrate_teachers(self):
        teachers_path = self.data.path('teachers.csv')
        teachers = {r['username']: set(s for s in r['subjects'].split(';') if s) for r in DataDir.read_csv(teachers_path)}
        roles = {r['id']: r['shortname'] for r in self.db.table('role')}
        added = 0
        for ra in self.db.table('role_assignments'):
            if roles.get(ra['roleid']) != 'editingteacher':
                continue
            username = self.username_of.get(ra['userid'])
            ctx = self.contexts.get(ra['contextid'])
            if not username or not ctx or ctx['contextlevel'] != '50':
                continue
            subject = self.course_info(ctx['instanceid'])['subject']
            if subject and subject not in teachers.setdefault(username, set()):
                teachers[username].add(subject)
                added += 1
        if added:
            self.data.write_csv(teachers_path, ['username', 'subjects'],
                                [{'username': u, 'subjects': ';'.join(sorted(s))} for u, s in sorted(teachers.items())], 'teachers')
        self.report['users']['teacher subjects added'] = added

    # ---------- quizzes ----------

    def question_key_content(self, question_id, exam_media):
        """Frozen copy of a question as a Moodle try saw it, pictures copied into the exam.
        The key names the Moodle entry and version, so a paper can hold two wordings of one question
        and a re-run gives the same keys. id is the bank question's key ('' in a dry run)."""
        entry_id, version = self.our_question[question_id]
        qrow = self.questions[question_id]
        category = self.bank_copy.get(entry_id, {}).get('category', '')
        content = self.rewrite_pictures(qrow, self.convert_question(qrow), exam_media)
        return 'm%d@%d' % (entry_id, version), dict(content, id=self.bank_key.get(entry_id, ''), category=category, moodle=entry_id)

    def migrate_quizzes(self):
        print('Quizzes ...')
        quiz_module = next(m['id'] for m in self.db.table('modules') if m['name'] == 'quiz')
        cm_by_quiz = {cm['instance']: cm for cm in self.db.table('course_modules') if cm['module'] == quiz_module}
        slots_by_quiz = self.by('quiz_slots', 'quizid')
        sections_by_quiz = self.by('quiz_sections', 'quizid')
        refs = {r['itemid']: r for r in self.db.table('question_references') if r['component'] == 'mod_quiz' and r['questionarea'] == 'slot'}
        versions_by_entry = defaultdict(list)
        for v in self.db.table('question_versions'):
            versions_by_entry[v['questionbankentryid']].append(v)
        self.exam_of_quiz = {}
        self.paper_of_exam = {}
        earlier = self.existing_exams()
        taken = set(os.listdir(self.data.path('exams'))) if os.path.isdir(self.data.path('exams')) else set()

        for quiz in self.db.table('quiz'):
            cm = cm_by_quiz.get(quiz['id'])
            if not cm or cm['deletioninprogress'] == '1':
                self.report['quizzes']['skipped (being deleted)'] += 1
                continue
            info = self.course_info(quiz['course'])
            exam_id = earlier.get(str(quiz['id']))
            if not exam_id:
                created = datetime.fromtimestamp(int(quiz['timecreated'] or 0), IST)
                slug = slugify(quiz['name']) or slugify('%s class%s' % (info['subject'] or 'exam', info['class'])) or 'exam'
                n = 1
                while '%s-%s-%d' % (slug, created.strftime('%Y%m%d-%H%M'), n) in taken:
                    n += 1
                exam_id = '%s-%s-%d' % (slug, created.strftime('%Y%m%d-%H%M'), n)
                taken.add(exam_id)
            exam_dir = self.data.path('exams', exam_id)
            media_dir = os.path.join(exam_dir, 'media')

            sections = sorted(sections_by_quiz.get(quiz['id'], []), key=lambda s: int(s['firstslot']))
            slots = sorted(slots_by_quiz.get(quiz['id'], []), key=lambda s: int(s['slot']))
            exam_sections, paper_sections, questions = [], [], {}
            max_marks = 0.0
            for si, section in enumerate(sections or [{'firstslot': '1', 'heading': '', 'shufflequestions': '0'}]):
                last = int(sections[si + 1]['firstslot']) if si + 1 < len(sections) else 10 ** 9
                items, paper_slots = [], []
                for slot in slots:
                    if not int(section['firstslot']) <= int(slot['slot']) < last:
                        continue
                    ref = refs.get(slot['id'])
                    versions = sorted(versions_by_entry.get(ref['questionbankentryid'], []), key=lambda v: int(v['version'])) if ref else []
                    if ref and ref.get('version'):
                        versions = [v for v in versions if v['version'] == ref['version']] or versions
                    if not versions or versions[-1]['questionid'] not in self.our_question:
                        self.problems.append('Quiz %s slot %s: question not found' % (quiz['id'], slot['slot']))
                        continue
                    qid = versions[-1]['questionid']
                    key, content = self.question_key_content(qid, media_dir)
                    questions[key] = content
                    mark = float(slot['maxmark'])
                    items.append({'q': content['id'], 'mark': mark, 'copy': content})
                    paper_slots.append({'kind': 'fixed', 'q': key, 'mark': mark})
                    max_marks += mark
                exam_sections.append({'title': section['heading'] or '', 'shuffle': section['shufflequestions'] == '1', 'items': items})
                paper_sections.append({'title': section['heading'] or '', 'shuffle': section['shufflequestions'] == '1', 'slots': paper_slots})

            review_bits = int(quiz['reviewrightanswer'] or 0)
            review = 'immediate' if review_bits & (0x01000 | 0x00100) else ('after_close' if review_bits & 0x00010 else 'score')
            exam = {
                'id': exam_id, 'title': quiz['name'], 'subject': info['subject'] or '', 'classes': [info['class']] if info['class'] else [],
                'users': [], 'instructions': plain(quiz['intro'] or ''),
                'status': 'published', 'hidden': cm['visible'] != '1',
                'open_at': local_input(quiz['timeopen']), 'close_at': local_input(quiz['timeclose']),
                'time_limit_min': -(-int(quiz['timelimit'] or 0) // 60),
                'attempts': int(quiz['attempts'] or 0),
                'grade_method': {'1': 'highest', '2': 'average', '3': 'first', '4': 'latest'}.get(quiz['grademethod'], 'highest'),
                'per_page': 0 if quiz['questionsperpage'] == '0' else 1,
                'shuffle_questions': True, 'shuffle_choices': quiz['shuffleanswers'] == '1',
                'review': review, 'bands': [], 'access_code': quiz['password'] or '',
                'grading': {'llm': 'off', 'fuzzy': False, 'spelling_penalty': 0.0},
                'reward': {'enabled': False, 'formula': 'percent >= 90 ? 10 : percent >= 75 ? 5 : percent >= 50 ? 2 : 0', 'max': 10, 'daily_max': 0, 'payout': 'approve', 'count': 'best'},
                'sections': exam_sections,
                'created': iso(quiz['timecreated']) or '', 'created_by': 'moodle',
                'modified': iso(quiz['timemodified']) or '', 'modified_by': 'moodle',
                'published': iso(quiz['timemodified']) or '',
                'migrated': {'source': 'moodle', 'quiz': int(quiz['id']), 'course': info.get('name', '')},
            }
            paper = {'exam': exam_id, 'published': exam['published'], 'max_marks': max_marks, 'questions': questions, 'sections': paper_sections}
            self.exam_of_quiz[quiz['id']] = exam
            self.paper_of_exam[exam_id] = paper
            self.report['quizzes']['exams'] += 1
            self.report['quizzes']['hidden from students' if exam['hidden'] else 'visible to students'] += 1

    def write_exams(self):
        for exam in self.exam_of_quiz.values():
            exam_dir = self.data.path('exams', exam['id'])
            current = DataDir.read_json(os.path.join(exam_dir, 'exam.json'))
            if current and current.get('modified_by') not in (None, 'moodle'):
                self.report['quizzes']['(settings kept, edited in Exams)'] += 1
            else:
                self.data.write_json(os.path.join(exam_dir, 'exam.json'), exam, 'exam')
            self.data.write_json(os.path.join(exam_dir, 'paper.json'), self.paper_of_exam[exam['id']], 'paper')

    # ---------- attempts ----------

    def migrate_attempts(self):
        print('Attempts ...')
        qattempts = self.by('question_attempts', 'questionusageid')
        steps = self.by('question_attempt_steps', 'questionattemptid')
        step_data = self.by('question_attempt_step_data', 'attemptstepid')
        users = self.one('user')
        per_user_exam = defaultdict(list)
        for qa in self.db.table('quiz_attempts'):
            if qa['preview'] == '1':
                self.report['attempts']['skipped (preview)'] += 1
                continue
            if qa['state'] not in ('finished', 'inprogress', 'overdue'):
                self.report['attempts']['skipped (%s)' % qa['state']] += 1
                continue
            exam = self.exam_of_quiz.get(qa['quiz'])
            if not exam:
                self.report['attempts']['skipped (quiz not migrated)'] += 1
                continue
            if not qattempts.get(qa['uniqueid']) and (qa['state'] != 'finished' or qa['sumgrades'] in (None, '')):
                self.report['attempts']['skipped (no responses)'] += 1
                continue
            username = self.username_of.get(qa['userid'])
            if not username:
                self.report['attempts']['skipped (user not mapped)'] += 1
                continue
            per_user_exam[(exam['id'], username)].append(qa)

        for (exam_id, username), attempts in per_user_exam.items():
            exam = next(e for e in self.exam_of_quiz.values() if e['id'] == exam_id)
            paper = self.paper_of_exam[exam_id]
            media_dir = self.data.path('exams', exam_id, 'media')
            if username not in exam['users']:
                exam['users'].append(username)
            for n, qa in enumerate(sorted(attempts, key=lambda a: int(a['attempt'])), start=1):
                if qattempts.get(qa['uniqueid']):
                    record = self.convert_attempt(qa, n, username, exam_id, paper, media_dir, qattempts, steps, step_data, users, exam)
                else:
                    record = self.mark_only_attempt(qa, n, username, exam, paper)
                if not record:
                    continue
                # Who wrote it, in the class of the Moodle course (the class they were in then, not today's)
                record['student'] = {'name': self.name_of.get(username, username), 'class': exam['classes'][0] if exam['classes'] else ''}
                path = self.data.path('exams', exam_id, 'attempts', username, '%d.json' % n)
                current = DataDir.read_json(path)
                if current is not None and not current.get('migrated'):
                    self.problems.append('%s: %s already has a try %d made in Exams; Moodle attempt %s was not written' % (exam_id, username, n, qa['id']))
                    continue
                if current is not None and current['migrated'].get('hash') not in (None, attempt_hash(current)):
                    self.report['attempts']['(kept, changed in Exams)'] += 1
                    continue
                if current is not None and current.get('student'):
                    record['student'] = current['student']   # may have been corrected in Exams (a student in another class's course)
                record['migrated']['hash'] = attempt_hash(record)
                self.data.write_json(path, record, 'attempt')
                self.report['attempts']['migrated unsubmitted (student can continue)' if record['status'] == 'in_progress'
                                        else 'migrated without answers (Moodle kept only the mark)' if record['migrated'].get('answers_lost')
                                        else 'migrated'] += 1

    def convert_attempt(self, qa, n, username, exam_id, paper, media_dir, qattempts, steps, step_data, users, exam):
        unsubmitted = qa['state'] != 'finished'
        slots, answers, grades, flags = [], {}, {}, []
        score, max_marks = 0.0, 0.0
        needs_review = False
        for qatt in sorted(qattempts[qa['uniqueid']], key=lambda x: int(x['slot'])):
            if qatt['questionid'] not in self.our_question:
                self.report['attempts']['question slots skipped (question deleted)'] += 1
                continue
            key, content = self.question_key_content(qatt['questionid'], media_dir)
            paper['questions'].setdefault(key, content)
            q = paper['questions'][key]
            qsteps = sorted(steps.get(qatt['id'], []), key=lambda s: int(s['sequencenumber']))
            data_by_step = [(s, {d['name']: d['value'] for d in step_data.get(s['id'], [])}) for s in qsteps]
            order = {}
            for _, d in data_by_step:
                for k, v in d.items():
                    if k.startswith('_'):
                        order[k] = v
            response = {}
            for s, d in data_by_step:
                user_keys = {k: v for k, v in d.items() if not k.startswith('_') and not k.startswith('-')}
                if user_keys:
                    response = user_keys
            layout, value = self.convert_response(q, qatt['questionid'], order, response)
            slot_no = int(qatt['slot'])
            mark = float(qatt['maxmark'])
            slots.append({'slot': slot_no, 'section': self.section_of(paper, key), 'key': key, 'mark': mark, 'layout': layout})
            if qatt['flagged'] == '1':
                flags.append(slot_no)
            if value is not None:
                answers[str(slot_no)] = value
            max_marks += mark
            if unsubmitted:
                continue
            final_step, final_data = data_by_step[-1] if data_by_step else ({'state': 'todo', 'fraction': None}, {})
            state = final_step['state']
            fraction = float(final_step['fraction']) if final_step.get('fraction') not in (None, '') else None
            grade = {'fraction': fraction, 'state': 'graded', 'by': 'moodle'}
            if state == 'needsgrading':
                grade = {'fraction': None, 'state': 'needs_review', 'by': 'moodle'}
                needs_review = True
            elif state == 'gaveup':
                grade = {'fraction': 0.0, 'state': 'unanswered', 'by': 'moodle'}
            elif state.startswith('mangr'):
                comment = next((plain(d.get('-comment', '')) for _, d in reversed(data_by_step) if '-comment' in d), '')
                marker = users.get(final_step.get('userid'), {}).get('username', '')
                grade = {'fraction': fraction, 'state': 'graded', 'by': 'teacher', 'marked_by': self.username_of.get(final_step.get('userid')) or marker,
                         'marked_at': iso(final_step['timecreated']), 'comment': comment}
            elif fraction is None:
                grade = {'fraction': 0.0, 'state': 'graded', 'by': 'moodle'}
            grade['mark'] = None if grade['fraction'] is None else round(grade['fraction'] * mark, 4)
            if grade['mark'] is not None:
                score += grade['mark']
            grades[str(slot_no)] = grade
        if unsubmitted:
            return self.unsubmitted_attempt(qa, n, username, exam, slots, answers, max_marks, flags)
        if qa['sumgrades'] not in (None, '') and abs(score - float(qa['sumgrades'])) > 0.005:
            self.problems.append('Attempt %s (%s, %s): score %.4f differs from Moodle sumgrades %s' % (qa['id'], username, exam_id, score, qa['sumgrades']))
            self.report['checks']['score mismatches'] += 1
        else:
            self.report['checks']['scores equal to Moodle'] += 1
        return {
            'exam': exam_id, 'user': username, 'n': n, 'status': 'submitted' if needs_review else 'graded',
            'token': ''.join(random.choice(string.ascii_lowercase + '234567') for _ in range(32)), 'epoch': 1, 'seq': 0,
            'started': iso(qa['timestart']), 'deadline': None, 'submitted': iso(qa['timefinish']),
            'late': False, 'auto_submitted': False, 'seed': 0, 'unlock': None,
            'slots': slots, 'answers': answers, 'flags': flags, 'grades': grades,
            'score': round(score, 4), 'max': max_marks,
            'events': {'saves': 0, 'blur': 0, 'offline_ms': 0}, 'saved_at': iso(qa['timemodified']),
            'migrated': dict({'source': 'moodle', 'attempt': int(qa['id']), 'moodle_score': float(qa['sumgrades']) if qa['sumgrades'] not in (None, '') else None},
                             **({'name': self.archived_names[username]['name'], 'class': self.archived_names[username]['class']} if username in self.archived_names else {})),
        }

    def mark_only_attempt(self, qa, n, username, exam, paper):
        """A finished Moodle attempt whose answers are gone from Moodle (only its mark is left): the mark, no questions."""
        return {
            'exam': exam['id'], 'user': username, 'n': n, 'status': 'graded',
            'token': ''.join(random.choice(string.ascii_lowercase + '234567') for _ in range(32)), 'epoch': 1, 'seq': 0,
            'started': iso(qa['timestart']), 'deadline': None, 'submitted': iso(qa['timefinish']),
            'late': False, 'auto_submitted': False, 'seed': 0, 'unlock': None,
            'slots': [], 'answers': {}, 'flags': [], 'grades': {},
            'score': round(float(qa['sumgrades']), 4), 'max': paper['max_marks'],
            'events': {'saves': 0, 'blur': 0, 'offline_ms': 0}, 'saved_at': iso(qa['timemodified']),
            'migrated': dict({'source': 'moodle', 'attempt': int(qa['id']), 'moodle_score': float(qa['sumgrades']), 'answers_lost': True},
                             **({'name': self.archived_names[username]['name'], 'class': self.archived_names[username]['class']} if username in self.archived_names else {})),
        }

    def unsubmitted_attempt(self, qa, n, username, exam, slots, answers, max_marks, flags):
        """A Moodle attempt never submitted: in progress, with its saved answers, so the student can carry on."""
        started = int(qa['timestart'])
        ends = []
        if exam['time_limit_min'] > 0:
            ends.append(started + exam['time_limit_min'] * 60)
        if exam['close_at']:
            ends.append(int(datetime.strptime(exam['close_at'], '%Y-%m-%dT%H:%M').replace(tzinfo=IST).timestamp()))
        return {
            'exam': exam['id'], 'user': username, 'n': n, 'status': 'in_progress',
            'token': ''.join(random.choice(string.ascii_lowercase + '234567') for _ in range(32)), 'epoch': 1, 'seq': 0,
            'started': iso(qa['timestart']), 'deadline': iso(min(ends)) if ends else None, 'submitted': None,
            'late': False, 'auto_submitted': False, 'seed': 0, 'unlock': None,
            'slots': slots, 'answers': answers, 'flags': flags, 'grades': {},
            'score': None, 'max': max_marks,
            'events': {'saves': 0, 'blur': 0, 'offline_ms': 0}, 'saved_at': iso(qa['timemodified']),
            'migrated': dict({'source': 'moodle', 'attempt': int(qa['id']), 'moodle_score': None, 'unsubmitted': True},
                             **({'name': self.archived_names[username]['name'], 'class': self.archived_names[username]['class']} if username in self.archived_names else {})),
        }

    @staticmethod
    def section_of(paper, key):
        for si, section in enumerate(paper['sections']):
            if any(s.get('q') == key for s in section['slots']):
                return si
        return 0

    def convert_response(self, q, moodle_qid, order, resp):
        """Moodle step data -> (layout, response) in our player's format."""
        t = q['type']
        answers = sorted(self.answers.get(moodle_qid, []), key=lambda a: int(a['id']))
        if t == 'multichoice':
            index_of = {a['id']: i for i, a in enumerate(answers)}
            ids = [x for x in (order.get('_order') or '').split(',') if x]
            layout = {'choices': [index_of[i] for i in ids if i in index_of] or list(range(len(answers)))}
            if q.get('single'):
                if 'answer' in resp and resp['answer'].lstrip('-').isdigit() and 0 <= int(resp['answer']) < len(ids):
                    return layout, index_of.get(ids[int(resp['answer'])])
                return layout, None
            chosen = sorted(index_of[ids[int(k[6:])]] for k, v in resp.items() if k.startswith('choice') and v == '1' and int(k[6:]) < len(ids))
            return layout, chosen or None
        if t == 'truefalse':
            return [], (resp['answer'] == '1') if resp.get('answer') in ('0', '1') else None
        if t in ('shortanswer', 'numerical'):
            return [], resp.get('answer') if resp.get('answer', '') != '' else None
        if t == 'essay':
            text = resp.get('answer', '')
            return [], (essay_text(text) or None) if text else None
        if t == 'match':
            subs = sorted(self.subquestions.get(moodle_qid, []), key=lambda s: int(s['id']))
            pair_index = {s['id']: i for i, s in enumerate(subs)}
            unique = []
            for s in subs:
                if s['answertext'] not in unique:
                    unique.append(s['answertext'])
            stems = [x for x in (order.get('_stemorder') or '').split(',') if x]
            choices = [x for x in (order.get('_choiceorder') or '').split(',') if x]
            text_of = {s['id']: s['answertext'] for s in subs}
            layout = {
                'stems': [pair_index[s] for s in stems if s in pair_index],
                'choices': [unique.index(text_of[c]) for c in choices if c in text_of],
            }
            value = {}
            for k, v in resp.items():
                if k.startswith('sub') and v.isdigit() and int(v) > 0 and int(k[3:]) < len(stems):
                    choice_id = choices[int(v) - 1] if int(v) - 1 < len(choices) else None
                    stem_id = stems[int(k[3:])]
                    if choice_id in text_of and stem_id in pair_index:
                        value[str(pair_index[stem_id])] = unique.index(text_of[choice_id])
            return layout, value or None
        return [], None

    # ---------- run ----------

    def load_tables(self):
        self.courses = self.one('course')
        self.course_categories = self.one('course_categories')
        self.contexts = self.one('context')
        self.course_modules = self.one('course_modules')
        self.qcats = self.one('question_categories')
        self.questions = self.one('question')
        self.answers = self.by('question_answers', 'question')
        self.mc_options = self.one('qtype_multichoice_options', 'questionid')
        self.sa_options = self.one('qtype_shortanswer_options', 'questionid')
        self.match_options = self.one('qtype_match_options', 'questionid')
        self.essay_options = self.one('qtype_essay_options', 'questionid')
        self.truefalse = self.one('question_truefalse', 'question')
        self.subquestions = self.by('qtype_match_subquestions', 'questionid')
        self.numerical = self.by('question_numerical', 'question')
        self.calculated = self.by('question_calculated', 'question')
        self.datasets = self.by('question_datasets', 'question')
        self.dataset_definitions = self.one('question_dataset_definitions')
        self.dataset_items = self.by('question_dataset_items', 'definition')
        self.files_by_item = defaultdict(list)
        for f in self.db.table('files'):
            if f['component'] == 'question' and f['filename'] != '.':
                self.files_by_item[f['itemid']].append(f)

    def run(self):
        only = set((self.args.only or 'questions,quizzes,attempts').split(','))
        self.load_tables()
        self.migrate_questions()   # always converted (needed for papers); written each run
        if 'questions' not in only:
            print('  (questions are still read so papers and attempts can refer to them)')
        self.migrate_users()
        self.migrate_teachers()
        if 'quizzes' in only or 'attempts' in only:
            self.migrate_quizzes()
        if 'attempts' in only:
            self.migrate_attempts()
        if 'quizzes' in only or 'attempts' in only:
            self.write_exams()
        self.remove_old_moodle_folder()
        self.print_report()

    def export_logins(self, path):
        """Login history of every Moodle account to a CSV (kept next to the backup, not in data/: it has IP addresses).
        last_login is Moodle's most recent login (its "current login"); Moodle's own "last login" is the one before."""
        self.load_tables()
        usermap = {r['moodle_username']: r for r in DataDir.read_csv(self.usermap_path)}
        classes = self.user_classes()
        rows = []
        for u in sorted(self.db.table('user'), key=lambda u: u['username']):
            if u['deleted'] == '1' or u['username'] == 'guest':
                continue
            m = usermap.get(u['username'], {})
            action = (m.get('action') or '').strip().lower()
            exams_username = (m.get('bank_username') or '').strip() if action == 'map' else (u['username'] if action == 'create' else '')
            rows.append({
                'moodle_username': u['username'], 'name': m.get('name') or ('%s %s' % (u['firstname'], u['lastname'])).strip(),
                'exams_username': exams_username, 'class': m.get('class') or classes.get(u['id'], ''),
                'account_created': local_time(u['timecreated']), 'first_visit': local_time(u['firstaccess']),
                'last_login': local_time(u['currentlogin'] if int(u['currentlogin'] or 0) else u['lastlogin']),
                'last_visit': local_time(u['lastaccess']), 'last_ip': u['lastip'] or '',
            })
        self.data.write_csv(path, LOGINS_HEADER, rows, 'logins')
        print('%s %d accounts (times in IST) to %s' % ('Would export' if self.data.dry else 'Exported', len(rows), path))

    def remove_old_moodle_folder(self):
        """Earlier versions kept idmap.csv, usermap.csv and users.csv in data/moodle; nothing needs them now."""
        old = self.data.path('moodle')
        if os.path.isdir(old) and not self.data.dry:
            shutil.rmtree(old)
            print('  removed %s (no longer used)' % old)

    def print_report(self):
        print('\n' + ('DRY RUN - nothing was written' if self.data.dry else 'Migration finished') + ' (data: %s)' % self.data.root)
        for section, counts in self.report.items():
            print('\n%s' % section.capitalize())
            for k, v in sorted(counts.items(), key=lambda x: (x[0].startswith('('), x[0])):
                print('  %-45s %6d' % (k, v))
        print('\nFiles %s: %s' % ('that would be written' if self.data.dry else 'written', ', '.join('%s %d' % kv for kv in sorted(self.data.written.items()))))
        if self.courses_without_subject:
            print('\nCourses that name no subject (%d): their folders and exams have no subject, so only admins see them.'
                  % len(self.courses_without_subject))
            print('  Add the subjects (or aliases such as the course code) on the Teachers page, then run again:')
            for c in sorted(self.courses_without_subject):
                print('  - ' + c)
        if self.problems:
            print('\nProblems (%d):' % len(self.problems))
            for p in self.problems[:50]:
                print('  - ' + p)
            if len(self.problems) > 50:
                print('  ... and %d more' % (len(self.problems) - 50))
        if hasattr(self, 'tmp'):
            shutil.rmtree(self.tmp, ignore_errors=True)


def main():
    here = os.path.dirname(os.path.abspath(__file__))
    p = argparse.ArgumentParser(description='Migrate a Moodle backup into ExamHall.')
    src = p.add_mutually_exclusive_group(required=True)
    src.add_argument('--backup', help='extracted backup folder containing db/moodle.sql and files/filedir')
    src.add_argument('--from-tar', help='the backup .tar.xz (db and filedir are extracted to a temp folder)')
    p.add_argument('--data', default=os.path.join(here, '..', 'data'), help='Exams data folder (default: ../data)')
    p.add_argument('--users-csv', default=os.path.join(here, '..', '..', 'Zion_Bank', 'users.csv'), help="Zion Bank's users.csv")
    p.add_argument('--only', help='comma list of questions,quizzes,attempts (default: all)')
    p.add_argument('--usermap', help='user map CSV to create/read (default: usermap.csv in the backup folder)')
    p.add_argument('--php', default='php', help='PHP binary used to write the question bank (default: php)')
    p.add_argument('--create-bank-users', action='store_true', help='append "create" users to Zion Bank users.csv / balance.csv')
    p.add_argument('--export-logins', nargs='?', const='', metavar='FILE',
                   help='only write the Moodle accounts\' login history to FILE (default: logins.csv in the backup folder), then stop')
    p.add_argument('--dry-run', action='store_true', help='show what would happen without writing')
    args = p.parse_args()
    if args.users_csv and not os.path.isfile(args.users_csv):
        print('Note: %s not found; no users will be matched.' % args.users_csv)
        args.users_csv = None
    if args.create_bank_users and not args.users_csv:
        sys.exit('--create-bank-users needs --users-csv')
    migration = Migration(args)
    if args.export_logins is not None:
        migration.export_logins(args.export_logins or os.path.join(os.path.dirname(migration.usermap_path), 'logins.csv'))
        return
    migration.run()


if __name__ == '__main__':
    main()
