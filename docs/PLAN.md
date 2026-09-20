<!-- last-synced: 2026-09-20, commit: 2113838 -->
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
- [ ] **Paste the current `docs/bp/block.php` into the BP and publish it.**
      *The last live run used the static-id version.*
- [ ] Add the "Set variable" block per branch and wire the automation rules to the chosen stages.
- [ ] Error branch: on `REJECTED_*` / `NOT_FOUND` / `ERROR_*` → timeline comment + notify the responsible user. (AC-4, AC-5)
      *Deferred by the owner 2026-09-20. The outcome reaches the BP journal, `Logstat` and `Send_log` only.*
      *Branch conditions are written out in `docs/CODES.md`.*
- [ ] End-to-end run on a test deal: stage change → status visible in Next within 1 minute. (AC-7)

## Milestone 3 — Handover

- [ ] Rotate the Next webhook token (seen in several places during setup) and update `.env` + the BP.
- [x] ~~Record the stage → `status` mapping in this repo~~ — *owner decided 2026-09-20 to keep it in the BP only.*
- [ ] Investigate the 6.5–7.5 s per-run latency from the Archi box (Next answers in ~0.4 s).
- [ ] Update `README.md` with the final field codes and the BP description.
- [ ] Run `/docs-sync` so SPEC, PLAN and DECISIONS match what was actually configured.
- [ ] One week of observation: check the BP journal daily for failures.

## Milestone 4 — Next → Archi (blocked)

Cannot be planned until PRD Open question 2 is answered (what triggers on Next, what changes on Archi).

- [ ] Define the trigger on the Next side and the target field on the Archi side.
- [ ] Owner creates the inbound webhook on Archi (scope `crm`).
- [ ] Extend `lib/statusUpdate.js` with the reverse direction, or document it as BP-only if no code is needed.

## Status

`In progress` — Milestones 0, 1 and 1b complete. Milestone 2 is one step from working end to end:
the dynamic `block.php` still has to be pasted into the BP and published.
