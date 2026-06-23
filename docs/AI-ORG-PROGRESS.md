# AI Организация — Прогрес (Claude Code поддържа актуален)

> Отбелязвай ✅ когато фазата е завършена **И** проверена по „Критериите за приемане". Добавяй
> кратки бележки/решения (1 ред). **Commit след всяка фаза.** Това е resumable: ако прекъснеш,
> „продължи" от първата неотметната фаза.

## Фази (изпълни в този ред)

- [x] **Фаза 0** ✅ — Домейн модел + seed библиотеки (org-blueprints / persona-archetypes / plans) + билинг скелет
- [ ] **Фаза 0.5** — Изпълнение и билинг foundation (метеринг върху `llm_requests`, кредитна резервация+идемпотентност, persona injection в runtime, generation state machine, Decision Box адаптер, member memory по `org_member_id`, code-owned keys, avatar overrides, org queue)
- [ ] **Фаза 1** — Casting на Управителя + Intake + Проучване + Интервю → Бизнес профил
- [ ] **Фаза 2** — Дизайн на екипа с персони + Skill Tree/Roster UI → материализация
- [ ] **Фаза 3** — Задачи = flows; генериране per асистент; ръчно пускане; Текущ поток **(край на MVP-демо)**
- [ ] **Фаза 4** — Директор-агент (рутиране/ревю/отчети/препоръки) + график + Кутия за решения + чат с членове
- [ ] **Фаза 5** — `act` задачи през конектори + интеграции рейл + политики на одобрение
- [ ] **Фаза 6** — Stripe drop-in (заменя админ top-up) + планове/overage UI + отключване по план
- [ ] **Фаза 7** — Жива организация: периодично ревю, рефлексия/памет per член, динамично наемане/уволнение, хроника

## Проверка след всяка фаза (без тестове)

`migrate:fresh --seed` · `route:list` · `about` · Horizon running · браузър happy-path (Критерии за приемане) · `pint` · `npm run build` · логове чисти

## Решения / бележки (Claude Code пише тук)

**Фаза 0 (2026-06-24):**
- 19 миграции `2026_06_24_100001..100019` (18 нови таблици + колона `companies.active_org_version_id`). FK ред: `credit_reservations` ПРЕДИ `credit_ledger` (ledger.reservation_id сочи натам).
- `credit_ledger` + `org_events` са append-only (`const UPDATED_AT = null`, само `created_at`).
- `OrgMember::allocateKey` = детерминистичен slug + суфикс `-2,-3…`; manager → фиксиран ключ `manager`. Никога LLM.
- `AssistantTask::effectiveStarTier()` = `star_tier ?? member.default_star_tier`, после cap по `Plan::max_star_tier`. Добавени `ModelLevel::rank()/cappedAt()` (additive, non-breaking).
- Планове: Starter=medium(1000кр/$29), Professional=high(5000/$99), Business=ultra(20000/$299), Enterprise=god(100000/$999). Стойностите са разумен default — подлежат на бизнес-настройка.
- `.env`: `COMFYUI_PORTRAIT_*` са **коментирани** (празен env var презаписва config default-а с '' → коментар = важи face-friendly default-ът).
- Проверка зелена: `migrate:fresh --seed` чисто; `Plan::count()=4`, fitness blueprint + 8 архетипа + 3 blueprints; tinker smoke (персона/плейсмънти/наследяване+cap/повишение→event/cascade) минава; `pint` чисто; `about`/`route:list` OK; логове чисти. (UI/`npm build` неприложимо — Фаза 0 е само схема.)

## ⚠ Решения за човек / блокери (липсващи credentials/услуги)

- **Уеб проучване (Фаза 1):** `BRAVE_API_KEY` и `CRAWL_SERVICE_URL` са празни в `.env`. Bizнес-проучването ще деградира меко (Google Places + интервю + база знания, без жив сърч/крал). Добави `BRAVE_API_KEY` преди Фаза 1 за пълно проучване.
- **Stripe (Фаза 6):** `STRIPE_*` празни — по план; ползва се `AdminSimulatedPaymentProvider`. Реален (парола) auth = задача на собственика, предусловие за Фаза 6.
