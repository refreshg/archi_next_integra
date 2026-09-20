<!-- last-synced: 2026-09-20, commit: 8ae8331 -->
# Architecture

Two independent Bitrix24 **box** portals. There is no server, no queue and no shared database.
Two runtime components: the business process in Archi and its mirror in Next (template 39). Both write
to the same product field `PROPERTY_547`, and each reads the other's signature to decide ownership (D-17).

## Components

| Component | Lives in | Responsibility |
|---|---|---|
| Deal automation rule | Archi portal | Fires the business process when a deal enters a configured stage |
| Business process | Archi portal | Sets the `status` variable per branch, then runs the PHP Code activity |
| **PHP Code activity** | Archi portal | Reads the deal product rows, calls Next twice over `curl`, writes `Logstat` + `Send_log`. Reference copy: `docs/bp/block.php` |
| **Next BP 39 "Archi_integra"** | Next portal | The mirror process: same structure, same history field, signed `Next BP: <name> #<id>`. Uses `curl` or `HttpClient` (D-16). Reference copy: `docs/bp/block-next.php` |
| Inbound webhook | Next portal | Authenticates the call; exposes `crm.*` REST under one portal user (scope `crm`) |
| `lib/bitrix.js` | this repo (CLI only) | REST client: param encoding, `batch`, retry, error typing |
| `lib/statusUpdate.js` | this repo (CLI only) | Builds the find+update batch; single source of truth for the payload shape |
| `lib/env.js` | this repo (CLI only) | Reads `.env`, redacts tokens in output |
| `scripts/discover.js` | this repo (CLI only) | Reads field config from both portals, generates `docs/*-fields.md` |
| `scripts/set-status.js` | this repo (CLI only) | Executes the same batch by hand for verification |
| `scripts/bp-payload.js` | this repo (CLI only) | Prints `batch` parameters for a webhook-activity setup — superseded by the PHP block |
| `scripts/parse-export.js` | this repo (CLI only) | Parses the Bitrix HTML export (`.xls`) into a field report or JSON |
| `scripts/import-products.js` | this repo (CLI only) | Imports apartments into a Next catalog section; idempotent on `PROPERTY_546` |
| `config/mapping.json` | this repo | Field codes, catalog/section ids, status texts |

Nothing in this repo runs in production. It exists to discover the configuration, prove the REST contract,
import the catalog, and supply the PHP the BP runs. Once the BP is configured, the integration runs without it.

**The production code lives in the Archi BP template.** `docs/bp/block.php` is the reference copy and has
to be carried across by hand when it changes — there is no deployment step (D-11).

## Data flow

```mermaid
flowchart TD
    A[Sales manager moves a deal] --> B[Automation rule on crm.deal]
    B --> C[Business process]
    C --> V[Set variable<br/>status = reserved / sold / free]
    V --> P[PHP Code activity]
    P --> R1[CCrmProductRow LoadRows<br/>read deal products]
    R1 -->|0 products| K[Logstat = NO_PRODUCT]
    R1 -->|2+ products| K2[Logstat = REJECTED_MULTI<br/>nothing sent]
    R1 -->|exactly 1| F1[POST crm.product.list<br/>filter PROPERTY_546]
    F1 -->|not found| K3[Logstat = NOT_FOUND]
    F1 -->|HTTP 401 or curl error| K4[Logstat = ERROR_*]
    F1 -->|found| D{current status<br/>equals requested?}
    D -->|yes, protected| S1[Logstat = REJECTED_SAME<br/>history only]
    D -->|yes, not protected| S2[Logstat = UNCHANGED<br/>history only]
    D -->|no| U[POST crm.product.update<br/>PROPERTY_64 + PROPERTY_547]
    U --> S3[Logstat = UPDATED]
    S1 --> W[Send_log + BP journal]
    S2 --> W
    S3 --> W
    K --> W
    K2 --> W
    K3 --> W
    K4 --> W

    subgraph Repo[This repo - setup only, not in the runtime path]
        L[scripts/discover.js] -.reads field config.-> F1
        N[scripts/set-status.js] -.same two calls, by hand.-> F1
        M[scripts/import-products.js] -.one-off catalog import.-> F1
    end
```

| Hop | Protocol | Auth | Payload | Failure handling |
|---|---|---|---|---|
| Deal → BP | internal | portal session | deal document | BP does not start if the rule does not match |
| BP → Archi CRM | native PHP | portal context | `CCrmProductRow::LoadRows` | read-only; 0 or 2+ rows abort the run |
| BP → Next (1) | HTTPS POST | static webhook token | `crm.product.list`, filter on `PROPERTY_546` | 0 rows → abort before any write |
| BP → Next (2) | HTTPS POST | static webhook token | `crm.product.update`, status + history | status omitted when unchanged |
| BP → owner | internal | — | `Logstat`, `Send_log`, BP journal | the audit trail on the Archi side |
| Next product | stored | — | one `PROPERTY_547` line per run | the audit trail on the Next side |
| BP → Archi product | HTTPS POST | Archi webhook token | `crm.product.get` then `crm.product.update`, both by explicit id | four guards; nothing is written unless all pass |
| Archi product | stored | — | `PROPERTY_1702` history, `PROPERTY_429` status (Next BP only) | the audit trail on the Archi side |

## Extension points

- **Status set** — `$MAP` in `docs/bp/block.php` and `STATUS_KEYS` in `lib/statusUpdate.js`. Adding a
  fourth status means a new entry in both, plus a PRD change. `$PROTECTED` decides which ones cannot be
  overwritten by an identical request.
- **Product API** — the `API` table in `lib/statusUpdate.js` holds every difference between `crm.*` and
  `catalog.*` (method names, `$result` chain path, field case). Switching is a one-line config change
  (`next.api`), not a rewrite.
- **Join key** — `next.archiIdField` in `config/mapping.json`, `$F_ID` in `block.php`. `PROPERTY_546`
  today; `XML_ID` holds the same value as a spare.
- **Import target** — `next.sectionId` / `next.catalogId`. `import-products.js` reads the section name
  from the portal, so another project needs no code change.
- **Direction** — Next→Next is implemented (BP 39). A true Next → **Archi** write still needs an inbound
  webhook on Archi and a defined target. Blocked on PRD Open question 2.
- **Symmetry switch** — `$RESPECT_ARCHI` in `block-next.php`. `true` today: neither side overwrites the
  other's protected status. `false` makes Next the authority on its own catalogue (D-18).
- **Blast radius** — every write is `crm.product.update` with an explicit `id`. Bitrix has no
  filter-based product update, so a broken filter cannot turn into a mass overwrite (D-21).
- **Middleware** — if retry and central logging become necessary, the same two calls move into a service
  without any change on the Next side. See D-1.

## Not present, deliberately

No custom Bitrix module, no OAuth application, no event subscriptions, no scheduler, no persistent state,
no runtime dependency, no `batch` in the production path.
