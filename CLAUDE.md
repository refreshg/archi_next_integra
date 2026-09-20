<!-- last-synced: 2026-09-20, commit: 8ae8331 -->
# CLAUDE.md — Archi ↔ Next Bitrix24 integration

## Stack
- Node.js >= 18.17, ESM (`"type": "module"`), **zero runtime dependencies** (built-in `fetch`)
- Bitrix24 **box / self-hosted** on both sides. Next: `https://bitrix.nextgroup.ge`. Archi: URL TBD.
- Transport: **inbound webhook** REST with a static token, called from the BP's PHP Code activity.
  No OAuth app, no custom Bitrix module.
- Scope in use: `crm` only (`catalog` and `user` proved unnecessary on this box)
- No database, no long-running process. This repo is CLI tooling + documentation only.
- The business process lives in the Archi portal UI and is built by hand by the owner. Its **PHP Code**
  activity holds the production logic; `docs/bp/block.php` is the reference copy, carried across by hand.

## Commands
```bash
npm run discover                                     # read field config from both portals -> docs/*-fields.md
npm run set-status -- --product <ID> --status <key>  # write a status (free|reserved|sold)
npm run set-status -- --product <ID> --status <key> --dry-run
npm run bp-payload                                   # batch params for a webhook-activity setup (superseded)
node scripts/parse-export.js <file.xls>              # field report for a Bitrix product export
node scripts/parse-export.js <file.xls> --column X   # value distribution of one column
node scripts/import-products.js <file.xls>           # dry run; add --execute to write, --limit N to cap
npm run check                                        # syntax check every source file
MAPPING_FILE=<path> npm run set-status -- ...        # run against a fixture config
```
No test runner and no linter are configured. `npm run check` is the only automated gate.

## Layout
- `lib/` — reusable modules: `bitrix.js` (REST client), `statusUpdate.js` (batch builder), `env.js` (.env + redaction)
- `scripts/` — `discover.js`, `set-status.js`, `bp-payload.js`, `parse-export.js`, `import-products.js`
- `config/mapping.json` — field codes, catalog/section ids, status texts; committed, contains no secrets
- `docs/` — PRD, SPEC, PLAN, ARCHITECTURE, DECISIONS, ARCHI-BP-SETUP, IMPORT-KOBULETI; `docs/*-fields.md` are generated and gitignored
- `docs/bp/` — PHP for the Archi BP. `block.php` is the current one; the rest are earlier stages
- `files/` — source exports from Archi; gitignored (commercial data)
- `.env` — webhook URLs and credentials; **gitignored, never committed**

## Conventions
- Status keys in the BPs: `free` | `hold` | `reserved` | `sold`. `hold` = უფასო ჯავშანი, `reserved` = ფასიანი ჯავშანი.
- The CLI (`STATUS_KEYS` in `lib/statusUpdate.js`) still knows only three — it cannot write `უფასო ჯავშანი`.
- Statuses are written as **text**, never as an enum id — the Next properties are string type (D-9).
- History lines in `PROPERTY_547` are separated by `<br>`; the product card renders the field as HTML.
- Bitrix field codes are used verbatim: `UF_ARCHI_ID`, `PROPERTY_<id>`, `UF_CRM_<id>`. No aliases, no renaming in code.
- Every REST method name appears as a string in `scripts/`; `lib/` stays method-agnostic.
- Code identifiers and comments in `lib/`/`scripts/`: English identifiers, Georgian explanatory comments (existing style).
- User-facing CLI output and PRD are Georgian. SPEC, ARCHITECTURE, DECISIONS, CLAUDE.md are English.
- Commit format: `<type>(<scope>): <subject>` — `feat`, `fix`, `docs`, `chore`. Subject in Georgian is accepted.

## Rules

### STANDARD FIRST
Before writing any code or adding any custom Bitrix entity, check whether the standard platform covers it:
standard REST methods → BP designer / automation rules / robots → CRM settings and user fields → custom code.
Custom code is allowed only after naming the standard feature that was checked, stating why it does not fit,
getting the owner's confirmation, and recording it as a `D-<n>` entry in `docs/DECISIONS.md`.

### MUST
- In CLI tooling, use `batch` for dependent calls and send `halt=1` so a failed lookup cannot cause a blind update.
- In the BP, use two sequential calls instead — `batch` cannot append to an existing text field (D-11).
- Back off and retry on `QUERY_LIMIT_EXCEEDED` and 5xx (`isRetriable` in `lib/bitrix.js`).
- Pass a status by its label text, exactly as `config/mapping.json` spells it.
- Send status and history in one `crm.product.update`; never write a status identical to the current one.
- Run `set-status` with `--dry-run` before the first real write against a portal.
- Keep secrets in `.env`; redact tokens in any printed output (`redact()` in `lib/env.js`).

### NEVER
- Never commit a webhook URL, token, or password. Not in code, config, docs, or `.claude/settings.json`.
- Never write to a production portal without the owner's explicit confirmation for that specific run.
- Never assume a REST method exists on box — `crm.productproperty.list` is absent here. Probe first, in `discover.js`.
- Never write to a product without an explicit `id`. `crm.product.update` only — never a filter (D-21).
- Never trust a filtered lookup: check the row count, the `total`, and that the returned id is the one asked for.
  An empty filter value makes Bitrix return the whole catalogue.
- Never assume `curl` exists in a BP sandbox — probe it, fall back to `HttpClient` (D-16).
- Never add a runtime dependency without a `D-<n>` entry; the zero-dependency property is deliberate.
- Never edit `docs/*-fields.md` by hand — they are generated by `npm run discover`.

## Workflow
PRD → SPEC → PLAN → code → manual verification on a test product → `/docs-sync`.
**`docs/PLAN.md` must be approved by the owner before implementation of a milestone starts.**
The Archi-side business process is configured manually by the owner; this repo only dictates the exact parameters.

## Docs map
| File | Purpose |
|---|---|
| `docs/PRD.md` | Business problem, users, user stories, acceptance criteria (Georgian) |
| `docs/SPEC.md` | Field-level spec, REST contract, standard-first table, traceability |
| `docs/PLAN.md` | Ordered, checkbox implementation plan per milestone |
| `docs/ARCHITECTURE.md` | Components, data flow, extension points |
| `docs/DECISIONS.md` | ADRs — why the integration is shaped this way |
| `docs/ARCHI-BP-SETUP.md` | Step-by-step BP setup for the Archi portal (Georgian, hand-operated) |
| `docs/IMPORT-KOBULETI.md` | The section-28 import: field map, what was skipped, what remains open |
| `docs/CODES.md` | Every result code: meaning, where it surfaces, what to do (Georgian) |
| `docs/bp/block.php` | Reference copy of the PHP inside the **Archi** BP |
| `docs/bp/block-next.php` | Reference copy of the PHP inside **Next BP 39 "Archi_integra"** |
| `n8n/compare-statuses.json` | n8n workflow: compares both catalogues, logs the run into Archi list 228 |
| `README.md` | Install, configure, run |
