# docs/archive

Завършени, остарели или заменени документи. Запазени за история (нищо не е изтрито),
извън активния `docs/`.

## Защо са тук

| Файл | Причина |
|---|---|
| `GRAPH-FLOW-REWRITE-PLAN.md` | Завършен — DAG/Drawflow изпълнението вече е текущата архитектура (виж `CLAUDE.md`, `DYNAMIC-AGENT-PLANNER.md`). |
| `CLIENT-PORTAL-SPEC.md` | Оригинална спецификация на портала; заменена от ревизията `CLIENT-PORTAL-REVAMP-PLAN.md` (V3). |
| `CLIENT-PORTAL-PLAN.md` | Оригинален имплементационен план; заменен от V3. |
| `CLIENT-PORTAL-IMPROVEMENTS.md` | Оптимизации, маркирани „✅ Реализирано". |
| `CLIENT-PORTAL-REVAMP-PLAN-V2.md` | Заменен от V3 (`CLIENT-PORTAL-REVAMP-PLAN.md` = V1+V2 слети и проверени спрямо кода). |
| `changes-2026-06-05_07.md` | Датиран changelog snapshot. |
| `superpowers/` | Ранни (29–30 май) планове за функции, вече реализирани (Brave search, deep scraper, agent-template picker, flows-create UX). |

## Активният каноничен план за клиентския портал

`docs/CLIENT-PORTAL-REVAMP-PLAN.md` (озаглавен **V3**) — не файлът с „-V2" суфикса.

## Връщане

```bash
git mv docs/archive/<файл> docs/<файл>
```
