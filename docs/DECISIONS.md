<!-- last-synced: 2026-09-18, commit: 2ad9894 -->
# Decisions (ADR)

### D-1: Direct BP → webhook call, no middleware service
- **Date:** 2026-09-18
- **Context:** Archi and Next are separate box portals. The status change must cross between them.
- **Decision:** The Archi business process calls the Next inbound webhook directly. No intermediate service.
- **Alternatives rejected:** A Node/PHP middleware (retry, central log, mapping) — rejected as too much
  infrastructure for ≤10 events/day with a one-week deadline. A local OAuth application — see D-5.
- **Consequences:** No retry and no central log; the deal timeline is the only audit trail (mitigated by
  the BP error branch, BL-4/BL-5). Moving to middleware later needs no change on the Next side: the same
  webhook and the same `batch` payload move into a service.

### D-2: One `batch` call chaining find + update
- **Date:** 2026-09-18
- **Context:** Archi knows its own product id, not Next's internal `ID`. A lookup must precede the update,
  but a BP webhook activity performs exactly one HTTP call.
- **Decision:** Use `batch` with `cmd[find]` + `cmd[upd]`, chained through `$result[find][0][ID]`, and `halt=1`.
- **Alternatives rejected:** Two BP activities (doubles the places holding the token, and the BP would have
  to parse the first response). Storing Next's internal id on the Archi side (a second field to keep in sync).
- **Consequences:** A single round trip. `halt=1` guarantees that a failed lookup cannot produce a blind
  update. The `$result` chain path differs between the `crm.*` and `catalog.*` APIs — encoded in the `API`
  table in `lib/statusUpdate.js`.

### D-3: Inbound webhook, not an OAuth local application
- **Date:** 2026-09-18
- **Context:** The call needs to authenticate against the Next portal.
- **Decision:** A static inbound webhook token.
- **Alternatives rejected:** A local OAuth app with token refresh — needed only for event subscriptions
  (`ONCRMPRODUCTUPDATE`) and bidirectional push, which the current scope does not require. It also adds a
  token-refresh component that would have to run somewhere, contradicting D-1.
- **Consequences:** The token is visible to Archi portal admins inside the BP activity. Rotation is required
  on staff change. No events can be subscribed to; the reverse direction will need its own trigger design.

### D-4: Join products by `UF_ARCHI_ID` on the Next side
- **Date:** 2026-09-18
- **Context:** The same product exists in both portals with different internal ids.
- **Decision:** Next stores the Archi product id in `UF_ARCHI_ID`; the integration finds the product by
  filtering on that field.
- **Alternatives rejected:** Matching by `NAME` (breaks on typos, duplicates, renames). Assuming equal ids
  across portals (not true). Storing Next's id on the Archi side (a second sync problem).
- **Consequences:** Every syncable product must have `UF_ARCHI_ID` populated; products without it produce
  AC-4 failures. Filterability of a `UF_` field in `crm.product.list` is unverified on this box version —
  fallbacks are `XML_ID` mirroring or `next.api: "catalog"` with a `property<id>` filter.

### D-5: Stage → status mapping lives in the BP, not in this repo
- **Date:** 2026-09-18
- **Context:** The owner configures the Archi portal by hand and defines which stages trigger which status.
- **Decision:** The BP contains one condition branch per status; `config/mapping.json` → `archi.stageToStatus`
  is documentation only and no code reads it.
- **Alternatives rejected:** Driving the mapping from this repo — would require the repo to run in the
  production path, contradicting D-1.
- **Consequences:** Stage changes need no code change and no redeploy. The repo cannot validate that the BP
  branches are correct; end-to-end verification (AC-7) is the only check.

### D-6: Zero runtime dependencies
- **Date:** 2026-09-18
- **Context:** The tooling only has to speak HTTP and read a small config.
- **Decision:** Node >= 18.17 with built-in `fetch`; no `axios`, no `dotenv`, no test framework.
- **Alternatives rejected:** `dotenv` (14 lines in `lib/env.js` replace it), `axios` (`fetch` plus
  `AbortSignal.timeout` covers timeouts and retries).
- **Consequences:** `npm install` is not needed at all; the repo runs from a clone. No `package-lock.json`,
  no supply-chain surface. Adding any dependency requires a new decision entry.

### D-7: Secrets in `.env`; `config/mapping.json` is committed
- **Date:** 2026-09-18
- **Context:** The repository is public on GitHub.
- **Decision:** Webhook URLs, tokens and portal credentials live only in `.env` (gitignored). Field codes and
  status enum ids live in `config/mapping.json`, which is committed.
- **Alternatives rejected:** A single config file holding both (one mistake leaks the token). A private repo
  (does not remove the need for the split).
- **Consequences:** `config/mapping.json` is safe to review and diff. Generated `docs/*-fields.md` are
  gitignored because they mirror live portal configuration. `redact()` hides tokens in CLI output.

### D-8: Fallback if the box BP lacks a "Webhook call" activity — OPEN
- **Date:** 2026-09-18
- **Context:** Box Bitrix24 versions lag the cloud. The whole design assumes the BP can make an HTTP call.
- **Decision:** **Not made.** Pending Milestone 0 verification in the Archi BP designer.
- **Alternatives to evaluate, in order:** automation-rule webhook robot → custom PHP activity on the Archi box
  → middleware service (reverses D-1).
- **Consequences:** This is the single largest risk to the one-week deadline. Verify it first.
