<!-- last-synced: 2026-09-18, commit: 2ad9894 -->
# Technical spec — product status sync (Archi → Next)

Both portals are Bitrix24 **box / self-hosted**. Next: `https://bitrix.nextgroup.ge`. Archi: URL TBD.
No custom Bitrix module, no PHP activity, no OAuth application. Everything runs on standard REST + the BP designer.

## Data model

### Next — CRM product (`crm.product`)

| Field | Type | Required | Source | Notes |
|---|---|---|---|---|
| `ID` | int | yes | standard | Internal product id; unknown to Archi, resolved at runtime |
| `UF_ARCHI_ID` | user field (string) | yes for synced products | **EXISTING** | Holds the Archi product id. The only join key. |
| `<status field>` | user field or property, list/dropdown | yes | **EXISTING** | Code TBD by discovery — `UF_...` or `PROPERTY_<id>` |
| `XML_ID` | string | no | standard | Fallback join key if `UF_ARCHI_ID` turns out not to be filterable |

Status values are a closed enum of three items. The **numeric enum id** is what gets written, never the label.
Ids are unknown until `npm run discover` runs; they land in `config/mapping.json` → `next.statusValues`.

| Status key (code) | Georgian label | Enum id |
|---|---|---|
| `free` | თავისუფალი | TBD |
| `reserved` | რეზერვი | TBD |
| `sold` | გაყიდული | TBD |

### Archi — CRM deal (`crm.deal`)

| Field | Type | Required | Source | Notes |
|---|---|---|---|---|
| `STAGE_ID` | enum | yes | standard | Triggers the BP; stage→status decision is made **inside the BP**, not in this repo |
| `<product id field>` | user field `UF_CRM_<id>` | yes | **EXISTING** | Holds the Archi product id passed to Next. Code TBD. |

No entity is created or deleted on either side. No new model, no new table.

## Business logic

| # | Trigger | Condition | Action | AC | Standard coverage |
|---|---|---|---|---|---|
| BL-1 | Archi deal enters a reservation stage | product id field is not empty | `batch`: find product by `UF_ARCHI_ID`, set status = `reserved` | AC-1 | `standard: BP designer + crm.product.update` |
| BL-2 | Archi deal enters a won stage | product id field is not empty | same, status = `sold` | AC-2 | `standard: BP designer + crm.product.update` |
| BL-3 | Archi deal is cancelled / reverts | product id field is not empty | same, status = `free` | AC-3 | `standard: BP designer + crm.product.update` |
| BL-4 | Any of BL-1..3 | `cmd[find]` returns 0 rows | `halt=1` stops the batch; BP writes a timeline comment and notifies the responsible user | AC-4 | `standard: batch halt + BP comment/notification activities` |
| BL-5 | Any of BL-1..3 | HTTP error or `result_error` non-empty | BP records the failure, deal is not blocked | AC-5 | `standard: BP webhook activity response handling` |
| BL-6 | Repeat run with the same status | — | `crm.product.update` writes the same enum id; no error, no side effect | AC-6 | `standard: idempotent REST update` |

Which stage maps to which status is configured **by the owner inside the BP** (one condition branch per status).
`config/mapping.json` → `archi.stageToStatus` is documentation only; no code reads it.

## Standard-first check

| Requirement | Standard feature checked | Covers it? | If no → approach |
|---|---|---|---|
| Trigger on deal stage change | Automation rule / BP on `crm.deal` | yes | — |
| Cross-portal HTTP call from a BP | BP activity "Webhook call" (Вызов вебхука) | **TBD on box** | If the activity is absent: automation-rule webhook robot, else D-8 (middleware). See ARCHI-BP-SETUP R-1. |
| Find a product by an external id | `crm.product.list` with `filter[UF_ARCHI_ID]` | TBD — verify at discovery | If UF is not filterable: mirror the id into `XML_ID`, or switch `next.api` to `catalog` and filter by `property<id>` |
| Two dependent calls in one BP step | `batch` with `$result[find]` chaining | yes | — |
| Write a dropdown value | `crm.product.update` with the enum id | yes | — |
| Error surfacing | BP timeline comment + notification activities | yes | — |
| Authentication | Inbound webhook, static token | yes | OAuth app rejected — see D-5 |

No row in this table currently requires custom code. Nothing in `lib/` or `scripts/` runs in production:
the repo is configuration tooling, the integration itself is 100% standard Bitrix.

## Views / UI

No custom view is delivered. Surfaces touched:

| Surface | Portal | Change |
|---|---|---|
| Deal timeline | Archi | BP writes a success/failure comment per run |
| Notification | Archi | BP notifies the responsible user on failure |
| Product card | Next | Status field changes value; no layout change |
| BP designer | Archi | One sequential business process, built by hand |

## Security

- Auth is a Bitrix **inbound webhook** on Next: a static token in the URL, acting as one portal user.
- Required scopes: `crm` (product read/write), `catalog` (fallback API), `user` (`profile` connectivity probe).
- The webhook user must have write access to the product catalog and nothing beyond it.
- The token is visible to Archi portal admins inside the BP activity — accepted, rotation on staff change.
- `.env` holds all secrets and is gitignored. `config/mapping.json` is committed and contains no secrets.
- No new Bitrix group, no `ir.model.access`-style rows — the platform's own permissions apply.

## Integrations

### Archi BP → Next REST

| Property | Value |
|---|---|
| Direction | Archi → Next |
| Endpoint | `POST <NEXT_WEBHOOK_URL>/batch.json` |
| Auth | Static webhook token in the path |
| Body | `halt=1`, `cmd[find]`, `cmd[upd]` (form-urlencoded) |
| Chaining | `cmd[upd]` reads `$result[find][0][ID]` (crm API) or `$result[find][products][0][id]` (catalog API) |
| Success | `result.upd` truthy, `result_error` empty |
| Not found | `result.find` empty → `halt=1` aborts before the update |
| Retry | None in the BP path. `lib/bitrix.js` retries 5xx and `QUERY_LIMIT_EXCEEDED` for CLI use only. |

Payload shape, with `__ARCHI_PRODUCT_ID__` replaced by the BP placeholder `{=Document:UF_CRM_<id>}`:

```
cmd[find] = crm.product.list?order[ID]=ASC&filter[UF_ARCHI_ID]=<id>&select[0]=ID&select[1]=UF_ARCHI_ID&select[2]=<status field>
cmd[upd]  = crm.product.update?id=$result[find][0][ID]&fields[<status field>]=<enum id>
```
(bracket characters are percent-encoded on the wire; `scripts/bp-payload.js` prints the exact strings)

### Next → Archi (planned, not designed)

In scope per PRD but blocked: the trigger on the Next side and the target field on the Archi side are
undefined. The owner will create the inbound webhook on Archi. See PRD Open question 2.

## Bitrix specifics

- **Box, not cloud.** REST availability differs: the `rest` module must be installed, and `catalog.*`
  methods exist only on recent box versions. `scripts/discover.js` probes every method and reports failures
  instead of assuming.
- Webhook creation path on box: `Applications → Webhooks → Add webhook → Inbound webhook`.
- Business processes are configured in the Archi portal UI by the owner; this repo never writes to Archi.
- No event subscriptions (`ONCRMPRODUCTUPDATE` etc.) — they require an OAuth application, rejected in D-5.
- Rate limits are not a concern at ≤10 runs/day.

## Migration / data

- Nothing installs, nothing upgrades, no schema change.
- Existing products keep their current status; no backfill is performed (explicitly out of scope).
- **Precondition:** every product that must be synced already has `UF_ARCHI_ID` populated on the Next side.
  Products without it are invisible to the integration and will produce AC-4 failures.

## Tests

No automated test suite exists. Verification is manual against the real portals using test products.

| AC | Verification | How |
|---|---|---|
| AC-1 | status becomes `reserved` | `npm run set-status -- --product <id> --status reserved`, then check the product card |
| AC-2 | status becomes `sold` | same with `--status sold` |
| AC-3 | status becomes `free` | same with `--status free` |
| AC-4 | unknown id changes nothing | `--product 000000` → exit code `2`, no product modified |
| AC-5 | portal unreachable | temporarily wrong `NEXT_WEBHOOK_URL` → non-zero exit, clear message |
| AC-6 | repeat run is harmless | run the same command twice → both succeed, value unchanged |
| AC-7 | ≤1 min end to end | move a real test deal through a stage, watch the product card |

`npm run check` is the only automated gate (syntax of all six source files).

## Traceability

| AC | Fields / code | Verification |
|---|---|---|
| AC-1 | `next.statusValues.reserved`, `buildStatusBatch` | manual + `set-status --status reserved` |
| AC-2 | `next.statusValues.sold`, `buildStatusBatch` | manual + `set-status --status sold` |
| AC-3 | `next.statusValues.free`, `buildStatusBatch` | manual + `set-status --status free` |
| AC-4 | `halt=1`, `interpretBatchResult` → `not_found` | exit code 2; verified with fixtures |
| AC-5 | `interpretBatchResult` → `search_failed` / `update_failed`; BP error branch | manual |
| AC-6 | `crm.product.update` idempotency | run twice |
| AC-7 | single `batch` round trip | observed during manual run |

## Open questions

1. Status field code and the three enum ids — resolved by `npm run discover`.
2. Is `UF_ARCHI_ID` filterable in `crm.product.list` on this box version? Determines the fallback in the Standard-first table.
3. Archi deal field code holding the product id (`UF_CRM_<id>`).
4. Does the Archi box version expose the "Webhook call" BP activity?
5. Next → Archi direction: trigger and target — see PRD Open question 2.
