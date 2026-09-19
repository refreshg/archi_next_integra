<!-- last-synced: 2026-09-18, commit: 2ad9894 -->
# Implementation plan

Deadline: **2026-09-25**. Owner configures both portals by hand; this repo supplies exact parameters.
Risky and unknown steps come first — every blocker is a box-version question that can invalidate the design.

## Milestone 0 — Standard-first verification

Nothing is built until each row of SPEC's Standard-first table has a verified answer.

- [ ] Create the inbound webhook on Next (`Applications → Webhooks → Inbound`), scopes `crm`, `catalog`, `user`;
      put the URL in `.env` → `NEXT_WEBHOOK_URL`. Owner action, portal UI.
- [ ] Run `npm run discover`; confirm `profile` succeeds — proves the `rest` module is active on box.
- [ ] From `docs/next-fields.md`: record the status field code and the three enum ids in `config/mapping.json`.
- [ ] Verify `crm.product.list` can filter by `UF_ARCHI_ID` on this box version
      (`--dry-run` then a real read). If not → decide between `XML_ID` mirroring and `next.api: "catalog"`;
      record the outcome in SPEC's Standard-first table and as a new `D-<n>`.
- [ ] Confirm `catalog.*` methods exist on this box (discover reports them) — needed only for the fallback.
- [ ] In the Archi BP designer, confirm the activity **"Webhook call" (Вызов вебхука)** exists.
      If absent → evaluate the automation-rule webhook robot; if that is also absent, escalate to D-8 (middleware).
- [ ] Identify the Archi deal field holding the product id; record it in `config/mapping.json` → `archi.productIdField`.

## Milestone 1 — Verified REST contract (no BP yet)

- [ ] Pick 1–2 test products on Next that already have `UF_ARCHI_ID` populated; note their ids in `.env`.
- [ ] `npm run set-status -- --product <id> --status reserved --dry-run` — inspect the printed batch.
- [ ] Real run for each of `reserved`, `sold`, `free`; confirm the product card in the Next UI after each. (AC-1..3)
- [ ] Negative run with a non-existent id → exit code `2`, nothing modified. (AC-4)
- [ ] Negative run with a broken `NEXT_WEBHOOK_URL` → non-zero exit, readable message. (AC-5)
- [ ] Repeat the same status twice → both succeed, no side effect. (AC-6)
- [ ] Record any deviation from SPEC's Integrations section; SPEC follows the code, not the other way round.

## Milestone 2 — Business process in Archi

Owner builds this by hand; `docs/ARCHI-BP-SETUP.md` is the dictation, `npm run bp-payload` prints the parameters.

- [ ] Create a sequential BP on `crm.deal`, launched by automation rules on the chosen stages.
- [ ] Add one condition branch per status; inside each, a "Webhook call" activity with the printed parameters.
- [ ] Store the activity response in a BP variable.
- [ ] Error branch: if `result_error` is non-empty or the product was not found → timeline comment + notification. (AC-4, AC-5)
- [ ] End-to-end run on a test deal: stage change → status visible in Next within 1 minute. (AC-7)

## Milestone 3 — Handover

- [ ] Rotate the Next webhook token (it was created during setup and seen in several places) and update `.env` + the BP.
- [ ] Update `README.md` with the final field codes and the BP screenshot/description.
- [ ] Run `/docs-sync` so SPEC, PLAN and DECISIONS match what was actually configured.
- [ ] One week of observation: check the deal timeline daily for failure comments.

## Milestone 4 — Next → Archi (blocked)

Cannot be planned until PRD Open question 2 is answered (what triggers on Next, what changes on Archi).

- [ ] Define the trigger on the Next side and the target field on the Archi side.
- [ ] Owner creates the inbound webhook on Archi (scope `crm`).
- [ ] Extend `lib/statusUpdate.js` with the reverse direction, or document it as BP-only if no code is needed.

## Status

`Not started` — waiting for owner approval of this plan and for the Next webhook URL.
