# GIFT in Zion Exams

Zion Exams reads and writes questions in **Moodle's GIFT format**. Any GIFT file that imports into Moodle 4.5 imports here with the same result. Our parser was compared with Moodle's own import of 48 real teacher files: all 1,008 questions came out identical.

Contents:
1. [Quick reference](#1-quick-reference)
2. [Question types](#2-question-types)
3. [Text formats and inline HTML](#3-text-formats-and-inline-html)
4. [Pictures](#4-pictures)
5. [Categories, tags and ids](#5-categories-tags-and-ids)
6. [Extension: several blanks in one question](#6-extension-several-blanks-in-one-question)
7. [Where we are stricter than Moodle](#7-where-we-are-stricter-than-moodle)
8. [Checker messages](#8-checker-messages)
9. [The question bank is GIFT files](#9-the-question-bank-is-gift-files)

---

## 1. Quick reference

| Write | Meaning |
|---|---|
| (blank line) | Separates questions. **Every question needs a blank line before the next.** |
| `// text` | Comment (not imported) |
| `::Name::` | Question name (optional; otherwise the start of the text is used) |
| `{ }` | Answer block. No block at all = description; empty block = essay |
| `=answer` | Correct answer |
| `~answer` | Wrong answer (multiple choice) |
| `~%50%answer` / `=%50%answer` | Partial credit (can be negative, e.g. `~%-50%`) |
| `#feedback` | Feedback for the answer just before it |
| `####text` | General feedback (last thing in the block) |
| `{T}` `{TRUE}` `{F}` `{FALSE}` | True/false. **Capitals only**: `{true}` is a short answer |
| `{#5}` `{#5:0.5}` `{#4..6}` | Numerical: exact, ± tolerance, range |
| `=left -> right` | Matching pair |
| `\~ \= \# \{ \} \:` | Literal `~ = # { } :` |
| `\n` | New line inside a question |
| `\\` | Literal backslash |
| `*` / `\*` in a short answer | `*` matches anything (Moodle wildcard); `\*` is a star itself: `{=\=B2\*C2}` |
| `[html]` `[moodle]` `[plain]` `[markdown]` | Text format, written just before the text |
| `$CATEGORY: a/b/c` | Put the following questions in this category (blank line before and after) |
| `// [id:abc] [tag:Maths] [tag:Fill up]` | Question id and tags (lower-case `tag:`/`id:`) |
| `// [zion:mark=2 hidden case noshuffle]` | Zion Exams settings Moodle ignores ([section 9](#9-the-question-bank-is-gift-files)) |
| `// [key:what a good answer contains]` | Reference answer for essays (Zion Exams; Moodle ignores it) |
| `&lt;` `&gt;` `&amp;` | `<` `>` `&` as text. In names and plain-text answers they are stored as the characters |

## 2. Question types

```gift
// Multiple choice (one correct)
::Capital:: What is the capital of Tamil Nadu? {=Chennai ~Madurai ~Coimbatore}

// Multiple answers (tick all that are right): no "=", weights add up to 100%
::Mammals:: Which are mammals? {
  ~%50%Cow
  ~%50%Whale
  ~%-50%Crow
  ~%-50%Shark
}

// True / false, with feedback for a wrong and a right response
::Sun:: The sun rises in the west. {FALSE#No, it rises in the east.#Correct!}

// Short answer (all listed answers are right; case is ignored; * matches anything;
// the exam can also ignore spacing and punctuation)
::Plural:: The plural of "child" is {=children}.

// Missing word: put the block where the blank should be
::Soil:: The soil with the biggest particles is {=sandy} soil.

// Numerical
::Place value:: The place value of 7 in 15,478 is {#70}.

// Matching (at least three pairs; "= -> extra" adds a wrong choice)
::Animals:: Match the animal to its home. {
  =Bee -> Hive
  =Bird -> Nest
  =Rabbit -> Burrow
  = -> Den
}

// Essay (marked by the teacher)
::Pollination:: Define pollination. {}

// Description (no answer; shown as information)
Read the passage below and answer the questions that follow.
```

**Missing word:** if text follows the closing `}`, the question shows a blank `_____` at that spot. If the block is at the very end, the answers appear below the question.

## 3. Text formats and inline HTML

| Format | How the text is shown |
|---|---|
| `[moodle]` (default) | HTML tags work; line breaks become new lines |
| `[html]` | HTML exactly as written |
| `[plain]` | Everything shown as typed (tags are not HTML) |
| `[markdown]` | Markdown (`*italic*`, `**bold**`, lists, links); HTML also works |

**Inline HTML is supported** in question text, multiple-choice choices, the left side of matching pairs, and all feedback. Short-answer answers and the right side of matching pairs are plain text, as in Moodle.

**Showing `<` and `>` as text.** In HTML fields write `&lt;` and `&gt;` (and `&amp;` for `&`); a bare `<h1>` would be read as a tag. Names (`::...::`), short-answer answers and the right side of matching pairs are plain text: entities there are decoded when the file is read, so all of these mean the same:

```gift
::The &lt;ol&gt; tag:: An ordered list (&lt;ol&gt;) shows numbers. {T}

The &lt;th&gt; tag stands for {=Table Header =&lt;th&gt;}

::Tags:: Match {=&lt;b&gt; -> Bold &amp; strong =&lt;i&gt; -> Italic}
```

The first question's name is stored as `The <ol> tag`; a student typing `<th>` gets the mark. When Exams writes GIFT it puts the entities back where needed, so files round-trip.

```gift
::Formula:: What is H<sub>2</sub>O commonly called? {=water}

::Bold:: Pick the <b>odd one</b> out. {~Apple ~Mango =Carrot ~Banana}

::Shape:: Name this shape: <svg width\="60" height\="60"><circle cx\="30" cy\="30" r\="25" fill\="orange"/></svg> {=circle}
```

Remember to escape `=`, `:`, `#`, `~`, `{`, `}` inside HTML attributes (`width\="60"`).

**For safety, HTML is filtered when it is shown.** Allowed: text formatting (`b i u s em strong sub sup small mark code pre span div p br hr`), lists, tables, headings, links (`http`, `https`, `mailto`), images and inline SVG shapes. Removed: `<script>`, `<iframe>`, `<object>`, forms, `on…=` event attributes and `javascript:` links.

## 4. Pictures

When you import a **folder** or a **ZIP**, pictures inside it are copied into the question bank next to the questions (`bank/<category>/media/`). Refer to them in any of these ways:

```gift
// Path relative to the .gift file (recommended)
::Cable:: Identify this cable: <img src\="images/ch-1/01-vga-cable.jpg" alt\="cable"> {=VGA cable =VGA}

// Markdown format
::Cable 2:: [markdown]Identify this cable: ![cable](images/ch-1/01-vga-cable.jpg) {=VGA cable}

// Moodle export style
::Cable 3:: <img src\="@@PLUGINFILE@@/01-vga-cable.jpg"> {=VGA cable}
```

- A picture is looked for next to the `.gift` file first, then from the top of the upload, then by file name anywhere in the upload.
- Allowed picture types: `png jpg jpeg gif webp svg`.
- The import preview lists pictures that are missing, and pictures that no question uses.
- Inline SVG needs no file at all.
- On results, printed sheets and answer keys, an answer that is only a picture is shown by its `alt` text (`<img src\="media/text.png" alt\="Text tool">` shows *Text tool*), or as *(picture)* when it has none. The browser shows the `alt` text only when the picture cannot load; leave it empty (`alt\=""`) on a picture in the question that students must name (*Identify this cable*).

## 5. Categories, tags and ids

- With a folder or ZIP upload, the question bank category comes from the **folder path and file name**: `V/Term-2/Maths/04_Measurements.gift` becomes `V/Term-2/Maths/04_Measurements`.
- You can choose to use the `$CATEGORY:` lines instead. The Moodle prefixes `$course$/`, `$system$/` and `top/` are removed.
- The category decides the **subject**, and teachers can only import into their own subjects.
- Tags: `// [tag:Fill up] [tag:Weight]` on the line(s) just before the question, **with no blank line in between**. `[Tag:` in capitals is ignored by Moodle, and the checker warns about it.
- A comment of the form `// === Section name ===` is remembered as the section of the questions after it.

## 6. Extension: several blanks in one question

> ⚠️ **This is a Zion Exams extension, not standard GIFT.** Moodle cannot import these questions correctly: it keeps only the first blank and shows the other `{…}` blocks as plain text. Don't use this syntax in files meant for Moodle (use Moodle's *Embedded answers (Cloze)* type there).

Write two or more answer blocks in one question. Each block becomes a blank at that spot:

```gift
// [tag:Fill up] [tag:Capacity]
::Kilolitre:: The largest unit of volume is the {=kilolitre =kiloliter =kl} and it equals {#1000} litres.
```

Students see:

> The largest unit of volume is the [ &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ] and it equals [ &nbsp;&nbsp;&nbsp;&nbsp; ] litres.

**Allowed blank types**

| Blank | Shown as | Example |
|---|---|---|
| Short answer | Text box | `{=kilolitre =kl}` |
| Numerical | Number box | `{#1000}` `{#3.14:0.01}` |
| Multiple choice | Drop-down list | `{=liquid ~solid ~gas}` |
| True/false | True/False drop-down | `{T}` |

**Not allowed:** an empty block `{}` (essay) and matching pairs. Both are reported as errors.

**Rules**
- Percent weights (`=%50%kl`) and per-answer feedback (`#…`) work inside each blank.
- General feedback (`####…`) goes in the **last** blank.
- **Marks:** each blank is worth 1 mark, so the question's default mark equals the number of blanks. A student gets partial credit for each blank they get right. In an exam, the question's mark is shared equally between its blanks.
- **Stored form:** the question text keeps markers `{#1}`, `{#2}`, … where the blanks go. Don't type `\{#1\}` literally in such a question.
- **Export:** questions are written back in the same inline form, so they re-import into Zion Exams unchanged.

## 7. Where we are stricter than Moodle

Moodle silently imports some mistakes. We report them instead:

| Situation | Moodle | Zion Exams |
|---|---|---|
| Several answer blocks | Keeps the first, the rest become text | Multi-blank question (section 6), with a note that Moodle can't import it |
| Numerical answer that isn't a number (`{#twelve}`, `{#15,478}`) | Accepts **any** answer as correct | Error, question not imported |
| `[Tag:` / `[TAG:` / `[tag :` | Tag silently dropped | Warning |
| `{true}` in lower case | Short answer "true" | Warning |
| `\&` or `\` before other characters | Backslash shown in the question | Warning |
| `=a -> b` pairs without `=` | One long short answer | Warning |

One place where marking is **more lenient** than Moodle: extra spaces inside a short answer are ignored (`random  access memory` matches `Random Access Memory`). Moodle marks such answers wrong. Re-marking 3,455 real responses migrated from Moodle gave identical marks except for 23 answers with a double space.

## 8. Checker messages

Run the checker on a folder:

```sh
php bin/gift_lint.php path/to/question_bank          # text
php bin/gift_lint.php path/to/question_bank --md --info > report.md
```

The same checks appear in the import preview.

| Level | Meaning |
|---|---|
| **error** | The question cannot be imported. Fix the file. |
| **warning** | It imports, but is probably not what you meant. |
| **note** | Worth a look (e.g. long exact-match answers, wildcards, multi-blank questions). |

| Message | What to do |
|---|---|
| Unmatched { or } | Escape literal braces as `\{` `\}` |
| Blank N has no answers | Every block in a multi-blank question needs answers |
| "[Tag:" is ignored | Write `[tag:…]` in lower case |
| Tags separated from their question by a blank line | Remove the blank line |
| Stray backslash | Remove it, or escape a real backslash as `\\` |
| Has a ___ blank but no answers {} | Add the answers, e.g. `{=answer}`, or it becomes an essay |
| Mentions a picture but has no image | Add `<img src\="…">` and include the picture in the upload |
| Two choices have the same text | Change one of them |
| Tagged "True-False" but the syntax makes it … | Fix the answer block (e.g. `{TRUE}` in capitals) |
| Only N matching pairs | Add pairs (Moodle expects at least three) |
| Long exact-match answer | Consider LLM grading, several accepted answers, or an essay |

## 9. The question bank is GIFT files

The bank itself is stored as GIFT, one file per chapter, laid out as `<class>/<subject>/<chapter>.gift`, so it can be read, edited, diffed and committed the same way:

```
data/bank/V/Computer-Science/Chapter-6.gift     the questions of that chapter, in your order
data/bank/V/Computer-Science/media/             pictures of the chapters in that folder (written as media/<name>)
data/bank/index.csv, data/bank/.state.json      caches rebuilt from the files
```

A chapter's path without `.gift` (`V/Computer-Science/Chapter-6`) is what the app calls its folder.

The files hold **no question ids**. Settings GIFT cannot hold go in a comment line that Moodle ignores:

```gift
$CATEGORY: V/Computer-Science/Chapter-6

// === Tags ===

// [tag:HTML] [zion:moodle=1207]
::If you write &lt;h1&gt; and &lt;h6&gt;::If you write <code>&lt;h1&gt;</code> and <code>&lt;h6&gt;</code>, which is bigger?{
	~&lt;h6&gt;
	=&lt;h1&gt;
}

// [zion:mark=5 hidden]
// [key:Mentions opening and closing tags, with an example]
::Explain HTML tags::Explain what an HTML tag is.{}
```

| Setting | Meaning | GIFT alone would give |
|---|---|---|
| `moodle=1207` | Brought from Moodle's question bank entry 1207; a new Moodle import updates this question unless it was changed here | — |
| `mark=2` | Default mark | 1 (a multi-blank question: one per blank) |
| `hidden` | Not offered when building exams | shown |
| `case` | Short answers must match upper/lower case | case ignored |
| `noshuffle` | Keep the choices / matching answers in the written order | shuffled |
| `single` / `multi` | One answer or several, when the `=` / `%` marks can't say it | from `=` |
| `// [key:...]` | Reference answer or key points for essays (`\n` new line, `\]` for `]`) | none |

**Numbers.** Pages show a question by its section and its number there, e.g. *Fill in the blanks · 3*. Numbering starts again at 1 after every `// === section ===` line. A file without section lines (the Moodle folders) is grouped by each question's first tag (*Choose · 1, 2, 3*, *True-False · 1, 2*), or by type when a question has no tag. Numbers follow the file, so adding a question renumbers the ones after it.

**How the app tells questions apart.** Inside, a question is known by its folder and its title (the `::title::`). Upper/lower case, spacing, `&amp;`-style entities, curly quotes and the length of a blank (`___` or `______`) make no difference; other punctuation and digits do. When two questions in one file have the same title, the order decides which is which. So:
- changing a question's text, answers or settings keeps it the same question;
- changing its title, or moving it to another folder, makes it a new question. Exams are not affected: each exam keeps its own copy of every question it uses. The exam editor marks questions that were changed or removed in the bank since, and **Use new version** takes the bank's version.

**Editing the files outside the app** (a text editor, `git pull`, or copying files over with `bin/bank_copy.php`) is fine. The next page load reads the changed files again; the app never writes to a file for that. A question removed from the file leaves the bank, and a question that cannot be read is left out and reported on the bank page (and by `php bin/fsck.php`) until the file is fixed.

**Copying chapter files in.** A folder with one GIFT file per chapter, such as a Moodle question bank kept in git, is copied into the bank with

```sh
php bin/bank_copy.php path/to/question_bank            # lists what would change
php bin/bank_copy.php path/to/question_bank --apply    # copies
```

`V/Term-2/Maths/04_Measurements.gift` becomes `data/bank/V/Term-2/Maths/04_Measurements.gift`, written exactly as it is. Run it whenever the source changes: changed chapters are copied again, and chapters deleted or renamed there are removed from the bank. Only chapters the command made are touched. A chapter changed in the app since the last copy is not overwritten unless you add `--force`. `--skip=V/Term-3/Science/01_Our_Environment` leaves a chapter out and is remembered (`--unskip=` undoes it). `--only=V/Computer-Science` (several folders with commas or more `--only`) copies only those folders; chapters outside them are neither copied nor removed. It is remembered too, and `--only=` alone copies everything again. For example, to copy only the Computer Science chapters:

```sh
php bin/bank_copy.php path/to/question_bank \
    --only=III/Computer-Science,IV/Computer-Science,V/Computer-Science --apply
```

`template.gift` and files without questions are not copied, and apostrophes are dropped from folder names.

**Pictures in copied chapters.** Write a picture as a path relative to the chapter file, in a folder next to the chapter files (the chapters there share it): `media/<name>` like the bank itself, or any other folder such as `images/ch-1/`:

```
V/Computer-Science/01_Computer_Hardware.gift     <img src\="media/hdmi-cable.png" alt\="">  <img src\="images/ch-1/01-vga-cable.jpg" alt\="">
V/Computer-Science/media/hdmi-cable.png
V/Computer-Science/images/ch-1/01-vga-cable.jpg
```

The pictures the chapters use are copied to the same path in the bank (`data/bank/V/Computer-Science/media/hdmi-cable.png`, `data/bank/V/Computer-Science/images/ch-1/01-vga-cable.jpg`), so the chapter file is still copied exactly as it is. Folder and file names may use letters, digits, `.`, `_` and `-` only. A changed picture is copied again, and a picture no copied chapter of the folder uses any more is removed (pictures added in the app stay). A chapter with a missing picture, or a path the bank cannot keep (`../x.png`, spaces), is not copied and stays in the bank as it was; the command says why. A picture at the same path that the command did not put there is not overwritten unless you add `--force`. An exam still copies the pictures it uses into its own `media/` folder.

The app changes files in place: saving a question in the editor rewrites only that question's lines, so your other questions, comments and `// === section ===` lines stay as you wrote them. Other comment lines inside a question's own block are kept too.

Import and export understand the same settings: an exported file keeps marks, key points and `case`/`noshuffle` when it is imported again. `moodle=` is not exported.
