<!-- last-synced: 2026-09-20, commit: 6a98646 -->
# Implementation plan

Deadline: **2026-09-25**. Owner configures both portals by hand; this repo supplies exact parameters.
Risky and unknown steps come first — every blocker is a box-version question that can invalidate the design.

## Milestone 0 — Standard-first verification ✅ done 2026-09-20

- [x] Create the inbound webhook on Next (`Applications → Webhooks → Inbound`);
      put the URL in `.env` → `NEXT_WEBHOOK_URL`. Owner action, portal UI.
      *Created with scope `crm` only — `catalog` and `user` turned out to be unnecessary.*
- [x] Run `npm run discover`; confirm `profile` succeeds — proves the `rest` module is active on box.
- [x] From `docs/next-fields.md`: record the status field code and its values in `config/mapping.json`.
      *Result: `PROPERTY_64`, string type, no enum ids — D-9.*
- [x] Verify `crm.product.list` can filter by the join field on this box version.
      *Result: `filter[PROPERTY_546]` works; no `XML_ID` or `catalog.*` fallback needed.*
- [x] Confirm `catalog.*` availability. *Result: `insufficient_scope`; not needed, path not taken.*
- [x] Establish how the BP can make an HTTP call.
      *Result: the **PHP Code** activity exists and is used — D-11. The webhook activity was never needed.*
- [x] Establish where the Archi product id comes from.
      *Result: deal product rows via `CCrmProductRow::LoadRows` — D-12. No custom deal field.*

## Milestone 1 — Verified REST contract (no BP yet) ✅ done 2026-09-20

- [x] Pick a test product on Next with `PROPERTY_546` populated — apartment 1001, Archi id `5335768`, Next id `164318`.
- [x] `npm run set-status -- --product <id> --status reserved --dry-run` — inspect the printed batch.
- [x] Real run for each of `reserved`, `sold`, `free`; confirm the product card after each. (AC-1..3)
- [ ] Negative run with a non-existent id → exit code `2`, nothing modified. (AC-4)
      *Covered by fixtures in `lib/statusUpdate.js`; **not yet run against the live portal**.*
- [x] Negative run with a broken token → readable message. (AC-5)
      *Observed live: HTTP 401 `INVALID_CREDENTIALS` → `ERROR_AUTH`.*
- [x] Repeat the same status twice → no side effect. (AC-6)
- [x] Record deviations from SPEC's Integrations section. *See SPEC `## Drift log`.*

## Milestone 1b — Catalog import ✅ done 2026-09-20

- [x] Parse the Archi export (`scripts/parse-export.js`) — 825 rows, 107 columns, 35 populated.
- [x] Agree the field map and the exclusions with the owner.
- [x] Import into Next section 28 (`scripts/import-products.js --execute`) — **275 apartments**,
      all with `PROPERTY_546` populated and unique.
- [x] Set `PROPERTY_60` (project) from the section name rather than the source column.
- [x] Decide what happens to the 550 `Not In Sale` rows. *Decided 2026-09-20: they stay out of Next.*

## Milestone 2 — Business process in Archi — in progress

Owner builds this by hand; `docs/bp/block.php` is the reference code.

- [x] Create a sequential BP on `crm.deal`: Start → PHP Code → End.
- [x] Drive the status from the BP variable `status` instead of one branch per status. (D-14)
- [x] Store the outcome in BP variables `Logstat` (short code) and `Send_log` (full report).
- [x] Read the product dynamically from the deal product rows; reject deals with 2+ products. (D-12, D-13)
- [x] Never rewrite an identical status; distinguish `REJECTED_SAME` from `UNCHANGED`. (D-13)
- [x] Paste `docs/bp/block.php` into the BP and publish it.
- [x] Add the "Set variable" block; wire the automation rules to the chosen stages.
- [x] Write the same history line into the Archi product (`PROPERTY_1702`). (D-22)
- [x] Four blast-radius guards and the `REJECTED_UNSAFE` code on both sides. (D-21)
- [ ] Error branch: on `REJECTED_*` / `NOT_FOUND` / `ERROR_*` → timeline comment + notify the responsible user. (AC-4, AC-5)
      *Deferred by the owner 2026-09-20. The outcome reaches the BP journal, `Logstat` and `Send_log` only.*
      *Branch conditions are written out in `docs/CODES.md`.*
- [x] End-to-end run on a test deal: confirmed working by the owner 2026-09-20. (AC-7)
- [x] Ownership protection: a status set by the other side is never overwritten. (D-17, D-18)
- [x] Fourth status `უფასო ჯავშანი` (`hold`) added and protected. (D-18)

## Milestone 2b — Mirror process on Next — in progress

- [x] Create BP **39 "Archi_integra"** on `crm.deal` in Next: Start → Set Variables → PHP Code → End.
- [x] Write to the same `PROPERTY_547`, signed `Next BP: <name> #<id>`. (D-15)
- [x] Resolve the acting user (`$GLOBALS[USER]`, fallback `crm.deal.get` → `MODIFIED_BY_ID`).
- [x] Fall back to `HttpClient` where `curl_init()` is unavailable in the BP sandbox. (D-16)
- [x] Enable `$RESPECT_ARCHI = true` — full symmetry. (D-18)
- [x] Paste `docs/bp/block-next.php` into BP 39 and publish it.
- [x] Write the Archi status (`PROPERTY_429`) from the Next BP, with the enum map. (D-22)
- [x] End-to-end symmetry test — confirmed working by the owner 2026-09-20.

## Milestone 2c — Reconciliation workflow ✅ done 2026-09-20

- [x] n8n workflow `n8n/compare-statuses.json` — 7 nodes, reads both catalogues, compares statuses.
- [x] Read-only on both portals: `crm.product.list` filtered to section 585 / 28, nothing else.
- [x] Summary separates `შედარდა` (275) from `არ_შედარდა` (550 `Not In Sale`).
- [x] Mismatch list in both object and plain-text form.
- [x] Log each run into Archi universal list **228 `Archi_Next_log`** (`lists.element.add`).
- [x] ~~Schedule trigger~~ — *owner decided 2026-09-20: manual runs for now.*
- [x] ~~n8n retention~~ — *owner decided 2026-09-20: Archi list 228 is the durable record;
      n8n execution history stays at its default and may be pruned.*

## Milestone 3 — Handover

- [x] ~~Rotate the webhook tokens~~ — *owner decided 2026-09-20 to leave them as they are for now.*
      *Three live tokens exist: two Archi, one Next. They sit in both BP templates, in n8n and in `.env`.*
      *Rotating means updating all four places at once — worth scheduling before handover.*
- [x] CLI learned the `hold` status — `STATUS_KEYS` and `config/mapping.json` now carry four keys.
- [ ] Verify the exact spelling of `უფასო ჯავშანი` in Next (SPEC Open question 11).
- [x] ~~Record the stage → `status` mapping in this repo~~ — *owner decided 2026-09-20 to keep it in the BP only.*
- [ ] Investigate the 6.5–7.5 s per-run latency from the Archi box (Next answers in ~0.4 s).
- [x] Update `README.md` with the final field codes and the BP description.
- [x] Run `/docs-sync` — last run 2026-09-20, after both BPs were confirmed working.
- [ ] One week of observation: check the BP journal daily for failures.

## Milestone 4 — Next → Archi ✅ done 2026-09-20

PRD Open question 2 is answered: the trigger is BP 39 on the Next side, and the targets are the Archi
product's `PROPERTY_429` (status) and `PROPERTY_1702` (history).

- [x] Define the trigger on the Next side and the target fields on the Archi side. (D-22)
- [x] Owner created the Archi webhook; scopes `crm`, `lists`, `bizproc`.
- [x] Next BP writes the Archi status using the enum map, and the history unconditionally.
- [x] Guarded by the id check and the apartment-number cross-check. (D-21)
- [ ] `lib/statusUpdate.js` still only knows the Archi → Next direction. The reverse lives in the BP
      only, which is fine — the CLI is verification tooling, not a runtime path.

## Status

Last session 2026-09-20: ორივე ბიზნეს პროცესი აეწყო, გამოქვეყნდა და დადასტურდა ორივე
მიმართულებით; დაემატა ჯგუფური ჩაწერის ოთხი დაცვა და n8n-ის შედარების workflow.
next: შეტყობინება `REJECTED_*`/`ERROR_*`-ზე. watch out: `docs/bp/`-ში მხოლოდ ორი
პროდუქციული ფაილია; მოძველებული დრაფტები `docs/bp/archive/`-შია, დაცვის გარეშე.

`Working` — every milestone except handover is complete. Both business processes are published and
confirmed working by the owner on 2026-09-20, in both directions, with the blast-radius guards in place.
The reconciliation workflow runs manually and logs each run into Archi list 228.

What remains in Milestone 3: a week of observation, the Archi-box latency question, and — deferred by
the owner — token rotation. Also open: notifying the responsible user on `REJECTED_*` and `ERROR_*`.
