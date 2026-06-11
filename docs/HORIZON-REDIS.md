# Redis + Horizon — ръководство за работа (локална dev среда)

Опашката и cache-ът на FlowAI вървят на **Redis** (Homebrew daemon, `predis`
клиент), а worker-ите се управляват от **Laravel Horizon**. Този документ обяснява
как да стартираш, наблюдаваш и поддържаш стека на ден-за-ден.

> Свързано: архитектурата е описана в [CLAUDE.md](../CLAUDE.md); стартовият
> скрипт е [scripts/start-services.sh](../scripts/start-services.sh).

---

## Какво къде живее

| Какво | Къде | Бележка |
|---|---|---|
| Опашки `flows` + `default` | Redis (db 0) | управляват се от Horizon |
| Cache (worker heartbeat, `OllamaSemaphore` locks) | Redis (db 1) | `CACHE_STORE=redis` |
| `job_batches`, `failed_jobs` | SQLite (релационната БД) | batching стои тук нарочно |
| Sessions | file | НЕ са в Redis |
| Horizon supervisors | [config/horizon.php](../config/horizon.php) | `supervisor-flows` (1-3 процеса, queue=flows, timeout 1200), `supervisor-default` (1 процес, queue=default, timeout 900) |

`REDIS_QUEUE_RETRY_AFTER=1800` трябва да е **по-голямо** от най-дългия job timeout
(`ExecuteNodeJob::$timeout` = 1200s). Иначе дълъг site-crawl възел се пре-dispatch-ва
по средата на изпълнението и гърми с `MaxAttemptsExceededException`.

---

## 1. Стартиране

### Redis (веднъж; после autostart)

```bash
brew services start redis     # стартира + autostart след reboot
redis-cli ping                # → PONG
```

Redis е споделен daemon — не зависи от `composer dev`. Веднъж пуснат през
`brew services`, се вдига сам след всеки reboot.

### Целият стек за разработка

```bash
composer dev
```

Пуска паралелно: web server, **Horizon** (в self-healing loop), scheduler
(`schedule:work`), log tailer (`pail`) и Vite. Това е препоръчителният начин —
queued jobs реално се обработват.

### Само Horizon (ръчно)

```bash
php artisan horizon
```

Вдига двата supervisor-а. Спиране: `Ctrl+C` или `php artisan horizon:terminate`
от друг терминал.

### Всичко след reboot (без `composer dev`)

```bash
./scripts/start-services.sh           # вдига Redis, Ollama, ComfyUI, Crawl4AI, web, Horizon, Vite
./scripts/start-services.sh status    # какво върви / какво не
./scripts/start-services.sh stop      # спира нашите процеси (Redis и Ollama остават)
```

---

## 2. Наблюдение

### Horizon dashboard

Отвори **`/horizon`** (напр. `http://flowai.local/horizon`). Виждаш:

- **Dashboard** — throughput (jobs/min), runtime, статус на supervisor-ите.
- **Pending / Completed / Failed Jobs** — текущи и минали jobs.
- **Recent Jobs** — последно обработените (за бърза проверка дали run-ът върви).
- **Metrics** — графики (job/queue throughput, runtime).

> На `local` env dashboard-ът е достъпен без auth (`HorizonServiceProvider::gate()`).

### От командния ред

```bash
php artisan horizon:status      # Running / Paused / Inactive
```

### Redis директно

```bash
redis-cli ping                              # PONG
redis-cli llen flowai-database-queues:flows # брой чакащи jobs на 'flows'
redis-cli --scan --pattern '*horizon*'      # Horizon ключове
redis-cli monitor                           # на живо всички команди (Ctrl+C за изход)
```

> Ключовете на опашката имат префикс `flowai-database-` (от
> `config/database.php`). Laravel го добавя автоматично, когато ползваш
> `Redis::connection()` или `FlowsQueueInspector`.

### В builder-а

Run-режимът показва банер „Изпълнението виси", ако heartbeat-ът на `flows`
worker-а липсва (`worker_alive=false` в poll endpoint-а). Heartbeat-ът се
обновява от всеки Horizon worker и има TTL 180s.

---

## 3. Ежедневна работа

### Deploy на нов код

Horizon worker-ите кешират кода в паметта. След промяна:

```bash
php artisan horizon:terminate
```

Това спира воркърите грациозно (изчаква текущия job). При `composer dev` /
`start-services.sh` self-healing loop-ът ги вдига наново с **свежия код**.

### Stuck / висящи runs

```bash
php artisan flows:watchdog          # маркира висящи runs като failed + чисти Redis payload-ите им
php artisan flows:cancel-stuck      # ръчен cleanup: проваля ВСИЧКИ pending/running runs + orphan sweep
```

`flows:watchdog` върви автоматично всяка минута през scheduler-а. Проваля run
само ако няма скорошна node активност И (heartbeat-ът липсва ИЛИ няма чакащи
jobs за този run). Чистенето на опашката минава през `FlowsQueueInspector`
(LREM/ZREM по точния payload).

### Retry на failed job

От `/horizon` → **Failed Jobs** → бутон **Retry** (или `php artisan queue:retry <id>`).

### Прочистване на опашка

```bash
php artisan horizon:clear           # изпразва 'default' опашката
php artisan horizon:clear --queue=flows
```

---

## 4. Troubleshooting

### Redis не отговаря

Симптом: всеки flow гръмва веднага; `worker_alive=false`; `composer dev`
изписва connection refused.

```bash
redis-cli ping                  # ако НЕ върне PONG:
brew services restart redis
brew services list              # статус на услугата
```

### Run виси / не тръгва

1. `php artisan horizon:status` → ако не е *Running*, стартирай `composer dev`
   или `php artisan horizon`.
2. `redis-cli llen flowai-database-queues:flows` → ако расте, но не пада, значи
   воркърите не дърпат (виж т.1).
3. `php artisan flows:watchdog` → затваря висящия run и чисти payload-ите му.

### Job заседнал в „reserved" (in-flight)

Reserved jobs седят в ZSET `flowai-database-queues:flows:reserved` със score =
момента, в който стават годни за повторен опит (`now + retry_after`). Ако worker
умре по средата, Laravel ги връща в опашката след `REDIS_QUEUE_RETRY_AFTER`
(1800s). За преглед/ръчно чистене:

```bash
redis-cli zrange flowai-database-queues:flows:reserved 0 -1 withscores
php artisan flows:cancel-stuck      # маха orphan payload-ите (LREM/ZREM)
```

### Защо `REDIS_QUEUE_RETRY_AFTER` трябва да е > job timeout

`retry_after` (1800s) казва на Redis колко да чака, преди да приеме един reserved
job за „изоставен" и да го върне в опашката. `ExecuteNodeJob::$timeout` е 1200s.
Ако retry_after беше **по-малко** от timeout-а, дълъг възел (напр. site-crawl)
още работещ, би бил пре-dispatch-нат паралелно → дублирано изпълнение и
`MaxAttemptsExceededException`. Затова: **retry_after (1800) > timeout (1200)**.

### phpredis vs predis

Ползваме `predis` (`REDIS_CLIENT=predis`) — чист PHP клиент. MAMP PHP 8.2 НЯМА
phpredis extension и build-ът срещу MAMP е крехък. Не сменяй на phpredis, освен
ако нарочно не инсталираш extension-а.

---

## Бърза справка (cheat sheet)

```bash
brew services start redis            # Redis daemon (autostart)
composer dev                         # целият dev стек (вкл. Horizon)
php artisan horizon                  # само воркърите
php artisan horizon:terminate        # рестарт за нов код (loop-ът ги вдига)
php artisan horizon:status           # Running / Paused
php artisan flows:watchdog           # затвори висящи runs
php artisan flows:cancel-stuck       # пълен cleanup на опашката
redis-cli ping                       # PONG
redis-cli llen flowai-database-queues:flows   # дълбочина на 'flows'
# Dashboard: http://flowai.local/horizon
```
