# Local AI checking (mini PC)

Zion Exams can ask a small language model, running on the school's i3 mini PC (4 GB RAM), to check short answers and suggest marks for essays. Nothing leaves the school network.

## What it does

| Step | Who | What |
|---|---|---|
| 1 | Computer | Exact marking, like Moodle, forgiving capitals, spacing and punctuation as the exam's checkboxes say (full marks) |
| 2 | Computer | Spelling slips, if the exam allows them (a small penalty can apply) |
| 3 | AI | Answers still not right are sent to the model in small batches per question |
| 4 | Teacher | Answers the AI is unsure about, every answer in "suggest" mode, and all essays |

On the **Grading** page, identical answers are grouped, so one click marks every student who wrote the same thing. "Add to answer key" makes later attempts with that answer right straight away.

In the exam settings, "Local AI checking of answers" has three modes:
- **Off**
- **Suggest marks, teacher confirms**: recommended until the benchmark looks good
- **Apply automatically**: correct or alternative answers get full marks, spelling slips get full marks minus the penalty, and wrong answers stay wrong. Anything unsure or below `$LLM_MIN_CONFIDENCE` goes to the teacher. The AI never lowers a mark.

Answers in every language are sent to the model the same way. Small models can be weak in some languages, so run the benchmark with answers in the languages your exams use, and keep "Suggest marks" for exams where it is not reliable.

## 1. Run the model on the mini PC

Use [llama.cpp](https://github.com/ggml-org/llama.cpp)'s `llama-server`. It uses less memory than Ollama, which matters with 4 GB.

1. Install llama.cpp (a release build for Linux x64, or build it with `cmake`).
2. Download a small instruct model in GGUF format, 4-bit (`Q4_K_M`), about 1–2 GB. For example Qwen2.5 1.5B or 3B Instruct, Llama 3.2 3B Instruct, or a newer model of similar size. Models of 4B parameters and larger will not fit in 4 GB comfortably.
3. Start the server:
   ```sh
   llama-server -m ~/models/model-q4_k_m.gguf --host 0.0.0.0 --port 8080 \
       -c 4096 -t 4 --parallel 1 --mlock
   ```
4. Keep it running across reboots, e.g. with a systemd service:
   ```ini
   # /etc/systemd/system/llama.service
   [Unit]
   Description=llama.cpp server for Zion Exams
   After=network-online.target
   [Service]
   ExecStart=/opt/llama.cpp/llama-server -m /home/ai/models/model-q4_k_m.gguf --host 0.0.0.0 --port 8080 -c 4096 -t 4 --parallel 1 --mlock
   Restart=always
   User=ai
   [Install]
   WantedBy=multi-user.target
   ```
   Then run `sudo systemctl enable --now llama`.

Expect roughly 3–8 words per second on a 6th-gen i3 for a 3B model, and about double for 1.5B. A batch of 8 short answers takes 10–60 seconds.

## 2. Connect Exams

In `config.local.php` on the web server:

```php
<?php
$LLM_URL = 'http://192.168.1.50:8080/v1/chat/completions';   // the mini PC
$LLM_MODEL = 'qwen2.5-3b-instruct';                           // any label; shown in reports
// $LLM_BATCH_SIZE = 8;  $LLM_MIN_CONFIDENCE = 0.7;
```

The Grading page shows whether the server answers.

## 3. Let it work

- **Easiest:** keep the Grading page of the exam open and press **Check now**.
- **Better:** run the worker on the web server, and it checks new answers by itself:
  ```sh
  php bin/grade_worker.php          # keeps running
  php bin/grade_worker.php --once   # do everything waiting, then stop (for cron)
  ```
  Or from cron: `*/5 * * * * cd /var/www/html/ExamHall && php bin/grade_worker.php --once`.

Students see "being marked" until the checks are done. Rewards wait for the checks.

## 4. Benchmark before trusting "Apply automatically"

After migrating from Moodle (`tools/moodle_migrate.py`) there are real student answers to test with:

```sh
php bin/llm_bench.php --limit 200 --out review.csv
# open review.csv, set label = right or wrong for each row, then:
php bin/llm_bench.php --limit 200 --labels review.csv --out review2.csv
```

The report shows speed, how many answers go to the teacher, how many right answers are accepted, and **how many wrong answers are accepted**. Keep "suggest" mode until wrong answers accepted is about 0. Try another model if it is not.
