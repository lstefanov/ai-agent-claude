# Billing и кредити

Референтен документ за кредитната и билинг система.
Придружава секцията "Billing" в `CLAUDE.md` и описва моделите, услугите, жизнения цикъл на метрираните операции, ценообразуването и правилата за промяна на код.

## Обзор

Всяка `Company` харчи вътрешна валута - кредити.
Метрираните операции резервират кредити предварително, изпълняват се и после уреждат сметката по реалния разход.
Цялата билинг логика живее в `app/Services/Org/Billing/`.
Реалната консумация на платени провайдъри се натрупва в `LlmUsage` (`app/Support/LlmUsage.php`).

Каноничната входна точка за метрирана работа е `BillableOperationService`.
Кредитните портфейли никога не се дебитират директно.

## Модели и таблици

| Модел | Таблица | Роля |
|---|---|---|
| `CreditWallet` | `credit_wallets` | Текущият баланс на фирмата. |
| `CreditReservation` | `credit_reservations` | Изменяемото състояние на една метрирана операция: оценка, реален разход, изход. |
| `CreditLedgerEntry` | `credit_ledger` | Само-добавящ се одит лог на всяко движение (topup, reserve, settle, refund). |
| `Plan` | `plans` | Абонаментен план с таван `max_star_tier` и месечен grant. |
| `Subscription` | `subscriptions` | Активният план на фирмата. |

`LlmUsage` е value object в `app/Support/`, не Eloquent модел.
Той акумулира токен разхода и цената на платените провайдъри за една операция.

## Услуги

| Услуга | Роля |
|---|---|
| `BillableOperationService` | Каноничната фасада за резервиране и уреждане; методи `run`, `begin`, `finish`, `frame`. |
| `CreditMeterService` | Резервира, уравнява, връща и записва редове в леджъра; методи `reserve`, `actualFor`, `settle`, `refund`, `topup`. |
| `BillingGatePolicy` | Решава дали контекстът е hard-gated или soft best-effort. |
| `BillingService` | Админ и планови операции: `adminTopUp` и месечният grant, зад `PaymentProvider`. |
| `AutonomousBudgetService` | Налага дневните тавани за автономна работа. |
| `PaymentProvider` | Договорът на платежния слой. |
| `AdminSimulatedPaymentProvider` | Симулирани плащания за админ демонстрации (текущият дифолт). |
| `StripePaymentProvider` | Реалният Stripe слой зад същия договор. |
| `InsufficientCreditsException` | Хвърля се при hard gate без достатъчно кредити. |

## Жизнен цикъл на метрирана операция

1. Call site-ът извиква `BillableOperationService::run` (или `begin` и после `finish`).
2. `CreditMeterService::reserve` изчислява оценка и създава `CreditReservation`, ако gate-ът е hard.
3. Операцията се изпълнява; `LlmUsage` натрупва реалния токен разход и цена.
4. `CreditMeterService::settle` уравнява резервацията по реалния разход и записва ред в `credit_ledger`.
5. При провал `refund` връща резервацията; `credit_ledger` остава пълен одит.

`CreditReservation` е изменяемото състояние на точно една операция.
`credit_ledger` е само-добавящ се - редове не се променят и не се трият.

## Gate policy

`BillingGatePolicy::hardGate(string $contextType, string $origin)` решава режима.
Всяко харчене с `origin = autonomous` е hard-gated, за да не заобикаля дневния автономен таван.
За ръчните контексти решението идва от `config('billing.gate.*')` по тип на контекста (`task_run`, `generation`, `text_assist`, `avatar`, `assistant`, `client_wizard`, `research`, `interview`, `knowledge_chat`, `knowledge_ingest`).
Непознат контекст е best-effort (soft): операцията продължава, атрибутира разхода, но не резервира.
Hard gate без достатъчно кредити връща 402 или прескача операцията.

## Ценообразуване

Ценовите параметри живеят в `config/billing.php`.

- `star_multipliers`: множители по ниво на модела - `low`, `medium`, `high`, `ultra`, `god`.
- `flat_costs`: фиксирани цени за инструменти (`brave_search`, `places`, `perplexity`, `ocr_page`, `avatar`, `embedding`).
- `work_per_token`: кредити на 1000 predict токена, с `token_divisor` за конверсията.
- `credit_markup`: надценка над реалния inference като fallback за непознат flat разход.
- `estimate_ktokens`: оценка в хиляди токени по контекст (`task_run`, `generation`, `org_planning`, `interview`, `research`, `member_chat`, `director_tick`, `org_digest`).
- `min_reserve_credits` и `min_estimate_credits`: долни граници на резервацията.
- `overage_enabled` и `pricing_version`: превключвател за надхвърляне и версия на ценообразуването.

## Автономни тавани

Автономната работа трябва да мине през `AutonomousBudgetService`.
Дневният таван идва от `config('organization.php')` под `caps.daily_credits` (0 изключва автономията).
Ръчният чат, ръчните тикове и ръчните пускания на задачи заобикалят автономните тавани, но не заобикалят gate-а за кредити.

## Планове, top-up и Stripe

Админ действието "Зареди кредити плюс сложи план" минава през `BillingService::adminTopUp`.
То зачислява кредити с ledger `type = topup` и по избор сетва плана (таван `max_star_tier`).
Клиентският портал излага билинга в `routes/client.php`: `client.org.billing`, `billing/subscribe`, `billing/top-up`.
Stripe webhook-ът е в `routes/web.php` на `stripe/webhook` към `BillingController@webhook`, извън `client_auth`, CSRF-exempt и валидиран по подпис.
Реалните плащания са зад `StripePaymentProvider`; дифолтът сега е `AdminSimulatedPaymentProvider`.

## Правила при промяна на код

- Използвай `BillableOperationService` за всяка метрирана работа.
- Никога не дебитирай кредитни портфейли директно.
- Дръж `credit_ledger` само-добавящ се; не редактирай съществуващи редове.
- Прекарвай автономното харчене през `AutonomousBudgetService`.
- Остави `BillingGatePolicy` да реши hard срещу soft; call site-ът може да подаде явен override.

## Кръстосани препратки

- `CLAUDE.md`, секция "Billing" за архитектурния обзор.
- `AI-ORGANIZATION-VISION.md` и `AI-ORGANIZATION-IMPLEMENTATION-PLAN.md` за това как AI организацията консумира кредити.
- `MCP-CONNECTORS.md` за flat таксуваните конекторни инструменти.
