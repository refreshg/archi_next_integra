<!-- last-synced: 2026-09-20, commit: 2113838 -->
# Technical spec — product status sync (Archi → Next)

Both portals are Bitrix24 **box / self-hosted**. Next: `https://bitrix.nextgroup.ge`. Archi: URL TBD.
No custom Bitrix module, no OAuth application. Everything runs on standard REST plus the BP designer
and its **PHP Code** activity (D-11). Next catalog: `CATALOG_ID 14`, section `28 = Kobuleti Beach Resort`.

## Data model

### Next — CRM product (`crm.product`)

| Field | Type | Required | Source | Notes |
|---|---|---|---|---|
| `ID` | int | yes | standard | Internal product id; unknown to Archi, resolved at runtime |
| `PROPERTY_546` | product property, **string (S)** | yes for synced products | **EXISTING**, titled `UF_ARCHI_ID` | Holds the Archi product id. The join key. Filterable — verified. |
| `PROPERTY_64` | product property, **string (S)** | yes | **EXISTING**, titled `სტატუსი` | The status. **Free text, not a dropdown** — see D-9. |
| `PROPERTY_547` | product property, text/HTML | no | **EXISTING**, titled `Interga_History` | Per-product audit trail written on every request. Holds 8000+ chars. |
| `XML_ID` | string | no | standard | The import also writes the Archi id here as a secondary key |

The status is a **string property**: the Georgian label itself is written, verbatim. There is no enum id
and no platform-side validation — a typo silently creates a new status. Values live in `config/mapping.json`.

| Status key | Written into `PROPERTY_64` | Protected? |
|---|---|---|
| `free` | თავისუფალი | no |
| `reserved` | ფასიანი ჯავშანი | yes |
| `sold` | გაყიდული | yes |

A fourth value, `ინტერესი`, exists on the portal outside section 28 and is not managed by this integration.

### Archi — CRM deal (`crm.deal`)

| Field | Type | Required | Source | Notes |
|---|---|---|---|---|
| `STAGE_ID` | enum | yes | standard | Triggers the BP; stage→status decision is made **inside the BP**, not in this repo |
| product rows | standard deal products | yes | standard | Read with `CCrmProductRow::LoadRows('D', $dealId)`. `PRODUCT_ID` is the Archi product id. **No custom field is used** — D-12. |
| BP variable `status` | string | yes | **NEW (BP)** | Input: `free` / `reserved` / `sold`, set per branch |
| BP variable `Logstat` | string | — | **NEW (BP)** | Output: one short result code |
| BP variable `Send_log` | text | — | **NEW (BP)** | Output: full per-run report |

No entity is created or deleted on either side. No new model, no new table.

## Business logic

| # | Trigger | Condition | Action | AC | Standard coverage |
|---|---|---|---|---|---|
| # | Trigger | Condition | Action | Result code | AC |
|---|---|---|---|---|---|
| BL-1 | `status` = `reserved` | deal has exactly 1 product, found in Next, current status differs | write `ფასიანი ჯავშანი` + history line | `UPDATED` | AC-1 |
| BL-2 | `status` = `sold` | same | write `გაყიდული` + history line | `UPDATED` | AC-2 |
| BL-3 | `status` = `free` | same | write `თავისუფალი` + history line | `UPDATED` | AC-3 |
| BL-4 | any | product not found by `PROPERTY_546` | nothing written; journal + `Send_log` record the failure | `NOT_FOUND` | AC-4 |
| BL-5 | any | HTTP/curl/REST failure | nothing written; deal is not blocked | `ERROR_NETWORK` / `ERROR_REST` / `ERROR_AUTH` | AC-5 |
| BL-6 | any | current status already equals the requested one | **status never rewritten**; history line only | `REJECTED_SAME` (protected) / `UNCHANGED` | AC-6 |
| BL-7 | any | deal has more than one product | nothing written at all; warning in journal | `REJECTED_MULTI` | — |
| BL-8 | any | `status` variable empty or unrecognised | nothing written | `BAD_STATUS` | — |
| BL-9 | any | deal id cannot be resolved | nothing written | `NO_DEAL` | — |
| BL-10 | any | deal has no product attached | nothing written | `NO_PRODUCT` | — |

Every run, including every rejection, appends one line to `PROPERTY_547` on the Next product
(except BL-7..BL-10, which abort before any REST call). BL-6 and BL-7 are new — see D-13.

All of the above is standard Bitrix: the BP designer, its PHP Code activity, and `crm.product.*`.

Which stage maps to which status is configured **by the owner inside the BP** (one condition branch per status).
`config/mapping.json` → `archi.stageToStatus` is documentation only; no code reads it.

## Standard-first check

| Requirement | Standard feature checked | Covers it? | If no → approach |
|---|---|---|---|
| Trigger on deal stage change | Automation rule / BP on `crm.deal` | yes | — |
| Cross-portal HTTP call from a BP | BP **PHP Code** activity + `curl` | **yes** — verified 2026-09-20 | The webhook activity was never needed. D-11 |
| Find a product by an external id | `crm.product.list` with `filter[PROPERTY_546]` | **yes** — verified on live data | No `XML_ID` or `catalog.*` fallback needed |
| Read the product attached to a deal | `CCrmProductRow::LoadRows('D', $id)` | **yes** | Read-only; no custom deal field. D-12 |
| Two dependent calls | two sequential REST calls from PHP | yes | `batch` dropped — it cannot append to existing text. D-11 |
| Write the status value | `crm.product.update` with the label text | yes | String property, no enum id. D-9 |
| Per-product audit trail | existing `PROPERTY_547` text property | yes | 8000+ chars verified |
| Result signalling back to the BP | BP variables via `SetVariable()` | yes | `Logstat` + `Send_log`. D-14 |
| Error surfacing | `WriteToTrackingService()` into the BP journal | yes | — |
| Authentication | Inbound webhook, static token | yes | OAuth app rejected — see D-5 |

No row requires a custom Bitrix module, entity or field. The PHP Code activity is a standard BP feature;
the code it runs lives in the Archi BP template, with `docs/bp/block.php` as the reference copy (D-11).
Nothing in `lib/` or `scripts/` runs in production — that is discovery, import and verification tooling.

## Views / UI

No custom view is delivered. Surfaces touched:

| Surface | Portal | Change |
|---|---|---|
| BP journal | Archi | Every run writes 2–4 lines via `WriteToTrackingService()` |
| Deal card | Archi | BP variables `Logstat` and `Send_log` hold the outcome |
| Product card | Next | `სტატუსი` changes; `Interga_History` gains a line. No layout change |
| BP designer | Archi | One sequential BP: Start → Set variable → PHP Code → End |

## Security

- Auth is a Bitrix **inbound webhook** on Next: a static token in the URL, acting as one portal user.
- Required scope: **`crm`** only. The live webhook has just that; `catalog` and `user` returned
  `insufficient_scope` at discovery and proved unnecessary.
- The webhook user must have write access to the product catalog and nothing beyond it.
- The token is visible to Archi portal admins inside the PHP Code activity — accepted, rotation on staff change.
  The code masks it (`/rest/***`) before writing it into `Send_log`, which is visible on the deal card.
- `.env` holds all secrets and is gitignored. `config/mapping.json` is committed and contains no secrets.
- No new Bitrix group, no `ir.model.access`-style rows — the platform's own permissions apply.

## Integrations

### Archi BP → Next REST

| Property | Value |
|---|---|
| Direction | Archi → Next |
| Caller | BP **PHP Code** activity, `curl`, connect timeout 10s / total 20s |
| Auth | Static webhook token in the path, scope `crm` |
| Call 1 | `POST .../crm.product.list.json` — `filter[PROPERTY_546]`, selects `ID`, `NAME`, `PROPERTY_64`, `PROPERTY_547` |
| Call 2 | `POST .../crm.product.update.json` — `fields[PROPERTY_64]` (omitted when unchanged) and `fields[PROPERTY_547][TEXT|TYPE]` |
| Body | form-urlencoded via `http_build_query()` |
| Success | `result` non-empty, no `error` key |
| Not found | call 1 returns 0 rows → call 2 never happens |
| Retry | None. Observed latency from the Archi box: 6.5–7.5 s per run. |

The history field is written as `array('TEXT' => ..., 'TYPE' => 'HTML')` and entries are separated by
`<br>`, not newlines — the product card renders the field as HTML and swallows `\n`. Newest entry first,
truncated at 7000 chars on a whole-entry boundary.

Reference implementation: `docs/bp/block.php`. `scripts/set-status.js` performs the same two operations
from the CLI for verification; `scripts/bp-payload.js` still prints the older `batch` form and is now
only useful for a webhook-activity setup.

### Next → Archi (planned, not designed)

In scope per PRD but blocked: the trigger on the Next side and the target field on the Archi side are
undefined. The owner will create the inbound webhook on Archi. See PRD Open question 2.

## Bitrix specifics

- **Box, not cloud.** Verified unavailable on the Next box: `crm.productproperty.list`
  (`ERROR_METHOD_NOT_FOUND`), `catalog.catalog.list` and `userfieldconfig.list` (`insufficient_scope`).
  Property metadata is therefore read from `crm.product.fields`, which returns `propertyType` per property.
  `scripts/discover.js` probes every method and reports failures instead of assuming.
- Webhook creation path on box: `Applications → Webhooks → Add webhook → Inbound webhook`.
- Business processes are configured in the Archi portal UI by the owner; this repo never writes to Archi.
- The PHP Code activity runs inside Archi, so Archi-side data is read through native PHP APIs
  (`CCrmProductRow`), not REST. No Archi webhook is required for the integration itself.
- No event subscriptions (`ONCRMPRODUCTUPDATE` etc.) — they require an OAuth application, rejected in D-5.
- Rate limits are not a concern at ≤10 runs/day.

## Migration / data

- Nothing installs, nothing upgrades, no schema change.
- Existing products keep their current status; no backfill is performed (explicitly out of scope).
- **Precondition:** every product that must be synced already has `PROPERTY_546` populated on the Next side.
  Products without it are invisible to the integration and will produce `NOT_FOUND`.
- **Done 2026-09-20:** 275 apartments imported into section 28 from the Archi export, all with
  `PROPERTY_546` populated and unique. 550 rows with Archi status `Not In Sale` were deliberately
  skipped and are still absent from Next. See `docs/IMPORT-KOBULETI.md`.

## Tests

No automated test suite exists. Verification is manual against the real portals using test products.

| AC | Verification | How |
|---|---|---|
| AC-1 | status becomes `reserved` | `npm run set-status -- --product <id> --status reserved`, then check the product card |
| AC-2 | status becomes `sold` | same with `--status sold` |
| AC-3 | status becomes `free` | same with `--status free` |
| AC-4 | unknown id changes nothing | `--product 000000` → exit code `2`, no product modified |
| BL-6 | same status is not rewritten | run the BP twice with the same `status` → second run gives `REJECTED_SAME` or `UNCHANGED` |
| BL-7 | two products abort the run | attach 2 products to a test deal → `REJECTED_MULTI`, nothing written |
| AC-5 | portal unreachable | temporarily wrong `NEXT_WEBHOOK_URL` → non-zero exit, clear message |
| AC-6 | repeat run is harmless | run the same command twice → both succeed, value unchanged |
| AC-7 | ≤1 min end to end | move a real test deal through a stage, watch the product card |

`npm run check` is the only automated gate (syntax of the `lib/` and `scripts/` files). The PHP in
`docs/bp/` is not linted here — no PHP runtime in the dev environment; it is validated by running the BP.

## Traceability

| AC | Fields / code | Verification |
|---|---|---|
| AC-1 | `docs/bp/block.php` $MAP[reserved]; `config/mapping.json` | ✅ verified live 2026-09-20 (BP run, apt 1001) |
| AC-2 | $MAP[sold] | ✅ verified via `scripts/set-status.js` |
| AC-3 | $MAP[free] | ✅ verified via `scripts/set-status.js` |
| AC-4 | `NOT_FOUND` branch in `block.php`; `interpretBatchResult` in `lib/statusUpdate.js` | exit code 2; fixtures |
| AC-5 | `ERROR_NETWORK` / `ERROR_REST` / `ERROR_AUTH` branches | ✅ `ERROR_AUTH` observed live (wrong token, HTTP 401) |
| AC-6 | `$skipWrite = $isSame` → `REJECTED_SAME` / `UNCHANGED` | ✅ verified live |
| AC-7 | two sequential REST calls | ⚠️ measured 6.5–7.5 s per run — within 1 min, but slow |

## Drift log

- **2026-09-20** — status is a **string** property, not a dropdown. The spec had specified writing a
  numeric enum id. Corrected throughout; see D-9.
- **2026-09-20** — field codes resolved: `PROPERTY_546` (join key), `PROPERTY_64` (status),
  `PROPERTY_547` (history). The spec had placeholders.
- **2026-09-20** — the integration uses a **PHP Code** activity, not the webhook activity, and therefore
  two sequential REST calls instead of one `batch`. D-2 no longer describes the production path; D-11 does.
- **2026-09-20** — the product id comes from the deal product rows, not a custom deal field. D-12.
- **2026-09-20** — the status vocabulary differs between portals (`დაჯავშნილი` vs `ფასიანი ჯავშანი`,
  and `Not In Sale` has no Next equivalent). D-10, still open.
- **2026-09-20** — history entries are separated by `<br>`, not newlines; the card renders HTML.

## Open questions

1. ~~Status field code and enum ids~~ — resolved: `PROPERTY_64`, string type, no enum ids.
2. ~~Is the join field filterable?~~ — resolved: `filter[PROPERTY_546]` works.
3. ~~Archi deal field holding the product id~~ — not needed; read from deal product rows (D-12).
4. ~~Does the box expose the "Webhook call" activity?~~ — not needed; PHP Code activity used (D-11).
5. Next → Archi direction: trigger and target — see PRD Open question 2. **Still open.**
6. ~~Status hierarchy~~ — decided 2026-09-20: **stays as is**, `გაყიდული` can be overwritten by
   `ფასიანი ჯავშანი`. No hierarchy. D-13.
7. ~~The 550 `Not In Sale` apartments~~ — decided 2026-09-20: **they stay out of Next**. A deal on one
   of them returns `NOT_FOUND`. D-10.
8. ~~Stage → `status` mapping is not recorded here~~ — decided 2026-09-20: **left in the BP only**.
9. Does `REJECTED_MULTI` need its own acceptance criterion? **Unanswered.**

Result codes and where they surface: `docs/CODES.md`.
