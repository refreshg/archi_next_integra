<!-- last-synced: 2026-09-20, commit: 6a98646 -->
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
  AC-4 failures. **RESOLVED 2026-09-20:** the field is `PROPERTY_546`, and `filter[PROPERTY_546]` works on
  this box version (verified against live data). No `XML_ID` or `catalog.*` fallback is needed, although
  the import writes the Archi id into `XML_ID` as well.

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
- **Decision:** **RESOLVED 2026-09-20 — see D-11.** The box BP exposes a "PHP Code" activity, which is used
  instead of a webhook activity. No middleware, D-1 stands.
- **Alternatives to evaluate, in order:** automation-rule webhook robot → custom PHP activity on the Archi box
  → middleware service (reverses D-1).
- **Consequences:** This is the single largest risk to the one-week deadline. Verify it first.

### D-9: სტატუსი სტრიქონია, არა dropdown — იწერება ტექსტი
- **Date:** 2026-09-20
- **Context:** PRD/SPEC აიგო დაშვებაზე, რომ Next-ის სტატუსი სიის (dropdown) ველია და ჩასაწერად
  მნიშვნელობის რიცხვითი ID სჭირდება. `npm run discover`-მა ეს დაშვება უარყო.
- **Decision:** `crm.product.fields` აბრუნებს `PROPERTY_64` → `propertyType: "S"` (სტრიქონი).
  ყველა 27 თვისება სტრიქონია, სიის ტიპის ველი პორტალზე საერთოდ არ არის.
  სტატუსი იწერება **ტექსტად**: `fields[PROPERTY_64] = "თავისუფალი"`.
- **Alternatives rejected:** სიის ტიპზე გადაკეთება Next-ის მხარეს — არსებულ 595 პროდუქტს შეეხება
  და მფლობელის გადასაწყვეტია, არა ჩვენი.
- **Consequences:** ჩაწერა გამარტივდა — enum ID-ების მოპოვება აღარ გვჭირდება.
  სამაგიეროდ **ვალიდაცია არ არსებობს**: ტექსტის შეცდომა ჩუმად შექმნის ახალ სტატუსს.
  ამიტომ მნიშვნელობები ერთ ადგილას, `config/mapping.json`-ში ფიქსირდება და BP-ში
  ხელით აღარ იწერება. იხ. აგრეთვე D-10.

### D-10: სტატუსების ლექსიკონი ორ პორტალზე არ ემთხვევა — OPEN
- **Date:** 2026-09-20
- **Context:** Archi-ს ექსპორტი და Next-ის რეალური მონაცემები სხვადასხვა ლექსიკონს იყენებს.
- **Archi:** თავისუფალი (242) · გაყიდული (29) · დაჯავშნილი (4) · **Not In Sale (550)**
- **Next:** თავისუფალი (28) · გაყიდული (561) · **ფასიანი ჯავშანი (4)** · **ინტერესი (2)**
- **Decision (2026-09-20):** `reserved` → **„ფასიანი ჯავშანი"** — დადასტურებულია მფლობელთან.
  **„Not In Sale" Next-ში არ იტვირთება** — 550 ბინა წყაროში რჩება. PRD გასწორდა რეალობის მიხედვით.
- **Open:** „ინტერესი" (2 პროდუქტი სხვა სექციებში) ვინ და როდის ცვლის — ინტეგრაციას არ ეხება.
- **Consequences:** „Not In Sale" ბინაზე გარიგების გახსნისას ინტეგრაცია `NOT_FOUND`-ს დააბრუნებს.
  სტატუსი არ შეიცვლება და მიზეზი `Send_log`-ში ჩაიწერება.

### D-11: BP-ს "PHP კოდის" აქტივობა webhook-აქტივობის ნაცვლად — D-8 იხურება
- **Date:** 2026-09-20
- **Context:** D-8 ღია იყო: არსებობდა თუ არა box-ის BP დიზაინერში „Webhook-ის გამოძახება".
- **Decision:** გამოყენებულია **„PHP Code"** აქტივობა. მოთხოვნებს `curl`-ით აგზავნის
  თავად PHP კოდი, `docs/bp/block.php`-დან.
- **Alternatives rejected:** webhook-აქტივობა — ერთ HTTP გამოძახებაზეა შეზღუდული და
  პასუხის დამუშავება არ შეუძლია. middleware — აღარ დასჭირდა.
- **Consequences:** D-2-ის `batch` აღარ არის საჭირო: PHP თანმიმდევრულად ორ გამოძახებას
  აკეთებს (`crm.product.list` → `crm.product.update`), რაც ისტორიის დამატებას შესაძლებელს ხდის.
  სამაგიეროდ კოდი Archi-ს BP შაბლონში ცხოვრობს და არა ამ რეპოში — `docs/bp/block.php`
  ეტალონია, და ცვლილებისას ხელით უნდა გადაიტანო.

### D-12: პროდუქტი გარიგების პოზიციებიდან, და არა ცალკე ველიდან
- **Date:** 2026-09-20
- **Context:** საჭირო იყო Archi-ს პროდუქტის ID. ვარაუდობდა ცალკე `UF_CRM_*` ველს.
- **Decision:** `CCrmProductRow::LoadRows('D', $dealId)` — გარიგების საქონლის პოზიციები
  იკითხება პირდაპირ. `PRODUCT_ID` სწორედ ის ID-ია, რომელიც Next-ში `PROPERTY_546`-შია.
- **Alternatives rejected:** ახალი ველი გარიგებაზე — ხელით შევსებას მოითხოვდა და
  სინქრონიზაციის მეორე წყარო გახდებოდა.
- **Consequences:** მენეჯერს დამატებითი მოქმედება არ სჭირდება — ბინას ისედაც ამაგრებს
  გარიგებაზე. მხოლოდ კითხვაა, Archi-ს მხარეს არაფერი იცვლება. სანაცვლოდ საჭირო გახდა
  წესი მრავალი პროდუქტის შემთხვევისთვის — იხ. D-13.

### D-13: ორი დაცვა — იგივე სტატუსი და მრავალი პროდუქტი
- **Date:** 2026-09-20
- **Context:** Next-ს დამოუკიდებლადაც ცვლიან სტატუსს; გარიგებას რამდენიმე ბინა შეიძლება ჰქონდეს.
- **Decision:** (1) **იგივე სტატუსი არასდროს იწერება ხელახლა.** თუ დაცულია
  (`ფასიანი ჯავშანი`, `გაყიდული`) → `REJECTED_SAME`; თუ არა → `UNCHANGED`.
  (2) გარიგებაზე **ერთზე მეტი პროდუქტი** → `REJECTED_MULTI`, Next-ში არაფერი იგზავნება.
- **Alternatives rejected:** პირველი პროდუქტის აღება (ჩუმად არასწორ ბინას შეცვლიდა);
  იგივე მნიშვნელობის ხელახლა ჩაწერა (უაზრო ჩანაწერი ისტორიაში).
- **Consequences:** ორივე შემთხვევა ისტორიაშიც ჩაიწერება — მცდელობა ჩანს, შედეგი არა.
  `Logstat` ცვლადში 11 კოდიდან ერთი ჩაიწერება, რითაც BP-ში პირობის დადება შეიძლება.
  **გადაწყდა 2026-09-20:** გაყიდულ ბინაზე რეზერვის დაწერა **რჩება დაშვებული** — იერარქია არ ინერგება.

### D-14: სტატუსი BP ცვლადიდან, შედეგი ორ ცვლადში
- **Date:** 2026-09-20
- **Context:** სამივე ტოტისთვის ცალკე კოდის შენახვა სამჯერ მეტ შეცდომის შანსს ქმნიდა.
- **Decision:** ერთი და იგივე PHP ბლოკი სამივე ტოტში. შესატანი — BP ცვლადი `status`
  (`free` / `reserved` / `sold`, ქართული ტექსტიც მიიღება). გამოსატანი — `Logstat`
  (მოკლე კოდი) და `Send_log` (სრული ანგარიში).
- **Alternatives rejected:** სამი ცალკე ბლოკი — ყოველი ცვლილება სამჯერ უნდა გადატანილიყო.
- **Consequences:** ტოტებს შორის სხვაობა მხოლოდ „ცვლადის შეცვლის" ბლოკშია.
  არასწორ მნიშვნელობაზე `BAD_STATUS` და არაფერი არ იგზავნება.

### D-15: Next-ის მხარესაც ცალკე ბიზნეს პროცესი, იმავე ველში ლოგით
- **Date:** 2026-09-20
- **Context:** Next-ში სტატუსს ხელით ან საკუთარი პროცესებით ცვლიდნენ და ეს არსად ფიქსირდებოდა.
  Archi ვერ არჩევდა, სტატუსი მან დააყენა თუ Next-მა.
- **Decision:** Next-ზე შეიქმნა ცალკე BP — **ID 39 „Archi_integra"** — იგივე სტრუქტურით
  (`Start → Set Variables → PHP Code → End`) და იგივე ველში (`PROPERTY_547`) ლოგით.
  კოდი: `docs/bp/block-next.php`. ხელმოწერა: **`Next BP: <სახელი> #<id>`**.
- **Alternatives rejected:** Next-ში ცვლილების აღმოჩენა `ONCRMPRODUCTUPDATE` მოვლენით —
  OAuth აპლიკაციას მოითხოვს (D-5-ით უარყოფილი). მხოლოდ ხელით ცვლილების დაშვება —
  მაშინ „ვინ" არასდროს დაფიქსირდებოდა.
- **Consequences:** ორივე მხარე ერთსა და იმავე ველს კითხულობს და წერს. მომხმარებელი
  ჟურნალში სახელით ჩანს (`user.get`, fallback — გარიგების `MODIFIED_BY_ID`).
  Next-ის ბლოკი პროდუქტს **არ ეძებს** — `PRODUCT_ID` იმავე პორტალის შიდა ID-ია.

### D-16: `curl`-ის ნაცვლად HttpClient-ზე ავტომატური გადართვა
- **Date:** 2026-09-20
- **Context:** Next-ის box უფრო ახალია (`Bitrix\Bizproc\Internal\Service\EvalService`) და
  BP-ის PHP სავარძელში `curl_init()` მიუწვდომელია: `Call to undefined function curl_init()`.
  Archi-ს ძველი box-ი (`codeactivity.php`) curl-ს თავისუფლად უშვებს.
- **Decision:** `$rest()` ჯერ curl-ს ცდის, მერე ბიტრიქსის `HttpClient`-ს. კლასის სრული
  სახელი `chr(92)`-ით იწყობა, რომ კოდის კოპირებისას ბექსლეშები არ დაზიანდეს.
  პასუხში `via` ველი აჩვენებს, რომელი გზა გამოიყენა.
- **Alternatives rejected:** ნატივური `CIBlockElement::SetPropertyValuesEx` — HTTP საერთოდ
  აღარ დასჭირდებოდა, მაგრამ HTML-ტიპის თვისების ჩაწერის ფორმატი გაუტესტავია და პროდუქციულ
  595 პროდუქტზე რისკი მაღალია. REST-ის ფორმა უკვე ათჯერ გატესტილია.
- **Consequences:** ერთი და იგივე ბლოკი ორივე box-ზე მუშაობს. Next თავის თავს HTTP-ით
  მიმართავს — loopback-ის რისკი რჩება, მაგრამ `ERROR_NETWORK`-ით ნათლად დაფიქსირდება.

### D-17: მფლობელობა — ბოლო ცვლილების ხელმოწერით, არა ბოლო „ჩვენი" ხაზით
- **Date:** 2026-09-20
- **Context:** Archi-ს პირველი ვერსია ისტორიაში მხოლოდ `Archi BP` ხაზებს ეძებდა და Next-ისებს
  გადაახტებოდა. სანამ Next არაფერს წერდა, უვნებელი იყო.
- **Decision:** ორივე ბლოკი იღებს **ბოლო რეალურ ცვლილებას** (ისრიან ხაზს `X → Y`), ვისიც არ
  უნდა იყოს, და ადარებს `Y`-ს ახლანდელ სტატუსს. მფლობელი ის არის, ვისი ხელმოწერაც იმ ხაზზეა.
- **Alternatives rejected:** ცალკე ველი მფლობელისთვის — Next-ში ხელით ცვლილებისას ის ველი
  არ განახლდებოდა და მოძველებულ ინფორმაციას აჩვენებდა.
- **Consequences:** ხელით ცვლილება Next-ში ისტორიაში არაფერს წერს — სწორედ ეს სიჩუმეა
  „Next-ის ხელმოწერა" და მფლობელობა მას გადადის. **თუ `Interga_History` გასუფთავდა,
  ორივე მხარე კარგავს მფლობელობას და დაცული სტატუსი იბლოკება.**

### D-18: სიმეტრიული დაცვა და მეოთხე სტატუსი „უფასო ჯავშანი"
- **Date:** 2026-09-20
- **Context:** მფლობელის მოთხოვნა: Next-ის ჯავშანს/გაყიდვას Archi ვერ შეეხოს და პირიქით.
  ასევე Next-ის მხარეს არსებობს „უფასო ჯავშანი", რომელიც ასევე უნდა დაიცვას.
- **Decision:** `$RESPECT_ARCHI = true` Next-ის ბლოკში — სრული სიმეტრია.
  `$PROTECTED = array('უფასო ჯავშანი', 'ფასიანი ჯავშანი', 'გაყიდული')` ორივე ბლოკში.
  ახალი alias: `hold` → `უფასო ჯავშანი`.
- **Alternatives rejected:** სტატუსების იერარქია (გაყიდული > ჯავშანი > თავისუფალი) —
  D-13-ში მფლობელმა უკვე უარყო.
- **Consequences:** **ჩიხის რისკი:** დაცულ სტატუსს მხოლოდ მისი დამდები მხარე ხსნის.
  მიტოვებული ჯავშნის გასახსნელად ან მფლობელმა უნდა გაუშვას `free`, ან ისტორია გასუფთავდეს.
  `უფასო ჯავშანი` პორტალზე ჯერ არცერთ პროდუქტზე არ გვხვდება — დაწერილობა გადასამოწმებელია.

### D-19: შედარების workflow n8n-ში, და არა ამ რეპოს სკრიპტად
- **Date:** 2026-09-20
- **Context:** საჭირო გახდა ორივე პორტალის სტატუსების პერიოდული შეჯერება.
- **Decision:** `n8n/compare-statuses.json` — 6 ნოუდიანი workflow. Archi-ს და Next-ის მონაცემებს
  პარალელურად იღებს, აერთიანებს `Merge`-ით და `Code` ნოუდში ადარებს.
- **Alternatives rejected:** ამ რეპოს CLI სკრიპტი — ვერავინ გაუშვებდა გრაფიკით; n8n-ში
  Schedule Trigger-ის დამატება ერთი ნოუდია.
- **Consequences:** Archi-ს ტოკენი n8n-ში ინახება. **ორივე გამოძახება მხოლოდ კითხვაა**
  (`crm.product.list`), ჩაწერა არსად არ ხდება. JSON-ში ტოკენების ადგილას `ARCHI_TOKEN` /
  `NEXT_TOKEN` ადგილმჭერებია — რეპო საჯაროა.

### D-20: Archi-ს სტატუსი სიის ტიპისაა, Next-ისა სტრიქონის
- **Date:** 2026-09-20
- **Context:** შედარებისთვის საჭირო გახდა Archi-ს `PROPERTY_429`-ის წაკითხვა.
- **Decision:** Archi-ზე სტატუსი **სიაა (L)** და REST enum ID-ს აბრუნებს, არა ტექსტს.
  რუკა: `810` გაყიდული · `811` თავისუფალი · `819` დაჯავშნილი · `833` Not In Sale · `1324` No Price.
- **როგორ დადგინდა:** `crm.productproperty.list` Archi-ზე არ არსებობს, `catalog.*`-ს scope არ ჰყოფნის.
  პირველი ოთხი გამოყვანილია ექსპორტის ფაილის (ID + ტექსტი) და API-ის (ID + enum) შეჯერებით —
  825/825 ჩანაწერზე ერთმნიშვნელოვანი დამთხვევა. მეხუთე (`1324`) მფლობელმა მოგვაწოდა.
- **Consequences:** workflow-ს თავისი რუკა აქვს. თუ Archi-ზე ახალი სტატუსი დაემატება,
  ეს რუკა ხელით უნდა განახლდეს — `უცნობი #<id>` გამოჩნდება შედეგში.
### D-21: ჯგუფური ჩაწერის სტრუქტურული გამორიცხვა
- **Date:** 2026-09-20
- **Context:** მფლობელმა აღწერა წარსული ინციდენტი — კოდმა ვერ იპოვა პროდუქტი ID-ით, ბიტრიქსმა
  ყველა პროდუქტი დააბრუნა და ყველას შეეცვალა სტატუსი.
- **დადასტურება:** ეს რისკი რეალურია. ცოცხალ პორტალებზე გატესტილი:
  `filter[PROPERTY_546] = ""` → Next-მა დააბრუნა **870** (მთელი კატალოგი);
  `filter[ID] = ""` → Archi-მ დააბრუნა **65 760**. `= 0` უსაფრთხოა, ცარიელი სტრიქონი — არა.
  ბიტრიქსი ცარიელ ფილტრს უბრალოდ უგულებელყოფს.
- **Decision:** ოთხი დაცვა ორივე ბლოკში, კოდი `REJECTED_UNSAFE`:
  1. `$id > 0` — ძებნამდე; `intval("")` = 0, ანუ ცარიელი ვერ გაივლის
  2. `count($found) === 1 && $total === 1` — თუ ფილტრი უგულებელყოფილია, აქ ჩერდება
  3. დაბრუნებული ჩანაწერის საიდენტიფიკაციო ველი ემთხვევა მოთხოვნილს
  4. **ბინის ნომერი ორივე პორტალზე ემთხვევა** — ბოლო სემანტიკური ბარიერი
- **სტრუქტურული გარანტია:** ყველა ჩაწერა `crm.product.update`-ით ხდება, რომელიც ბიტრიქსში
  **ერთი ელემენტის მეთოდია** — `id` პარამეტრს იღებს და ფილტრს ვერ მიიღებს. ჯგუფურად
  ვერაფერს შეცვლის, თუნდაც ყველა ზემოთ ჩამოთვლილი დაცვა ჩავარდეს.
- **Consequences:** Next-ის ბლოკში `crm.product.list` საერთოდ აღარ გვხვდება — პროდუქტი
  `crm.product.get`-ით იკითხება (არასწორ ID-ზე შეცდომას აბრუნებს, სიას არასდროს).
  Archi-ს ბლოკს ძებნა კვლავ სჭირდება (Archi-ს ID-ით Next-ში პოვნა) და სამივე დაცვა იქ დგას.

### D-22: Archi-ს პროდუქტზე ჩაწერა — ისტორია ორივედან, სტატუსი მხოლოდ Next-იდან
- **Date:** 2026-09-20
- **Context:** Archi-ს პროდუქტს დაემატა ველი `archi_next_history` (`PROPERTY_1702`, S/HTML).
  საჭირო გახდა, რომ ორივე მხარის ქმედება Archi-შიც ჩანდეს.
- **Decision:**
  - **ისტორია** — ორივე BP წერს `PROPERTY_1702`-ში, იმავე ფორმატით, რაც Next-ის `PROPERTY_547`-შია.
  - **სტატუსი** — Archi-ს `PROPERTY_429`-ს ცვლის **მხოლოდ Next-ის BP**. Archi-ს BP საკუთარ
    სტატუსს არ ეხება, რადგან ის ისედაც მენეჯერმა შეცვალა — ეს იყო პროცესის გაშვების მიზეზი.
- **Archi-ს სტატუსი სიის ტიპისაა** (`L`), ანუ enum ID იწერება და არა ტექსტი:
  `811` თავისუფალი · `819` დაჯავშნილი · `810` გაყიდული · `833` Not In Sale · `1324` No Price.
- **რუკა Next -> Archi** (მფლობელის დადასტურებით):
  თავისუფალი → 811 · **უფასო ჯავშანი → 819** · **ფასიანი ჯავშანი → 819** · გაყიდული → 810.
- **Consequences:** ორივე ჯავშანი `819 დაჯავშნილი`-ში ჯდება. **ეს დანაკარგი არ არის —
  მფლობელის დაზუსტებით, ამ ეტაპზე Archi-ს მხარეს ჯავშანი ერთია** და ფასიან/უფასოდ არ იყოფა.
  განსხვავება Next-ის მხარეს ინახება (`PROPERTY_64`) და ისტორიაშიც ორივეგან ჩანს.
  თუ Archi-ზე ოდესმე ცალკე „უფასო ჯავშანი" გაჩნდება, `$ARCHI_STATUS`-ში ერთი ხაზი შეიცვლება.
  სტატუსი მხოლოდ მაშინ იწერება, როცა Next-შიც შეიცვალა: `REJECTED_SAME`, `UNCHANGED` და
  `REJECTED_FOREIGN` შემთხვევებში Archi-ს სტატუსს ხელი არ ეხება, მხოლოდ ისტორია ჩაიწერება.
### D-23: მოძველებული BP-დრაფტები არქივში, წაშლის ნაცვლად
- **Date:** 2026-09-20
- **Context:** `docs/bp/`-ში ოთხი ადრეული დრაფტი იდო. `reserve-static.php`-ს სტატიკური ID აქვს
  და არცერთ დაცვას არ შეიცავს — შემთხვევით ჩასმისას ყოველ გაშვებაზე ერთსა და იმავე ბინას შეცვლიდა.
- **Decision:** გადატანილია `docs/bp/archive/`-ში, გამაფრთხილებელი `README.md`-ით, რომელიც
  თითოეულ ფაილზე ხსნის, რა აკლია. პროდუქციულია მხოლოდ `block.php` და `block-next.php`.
- **Alternatives rejected:** წაშლა — git-ის ისტორია რჩებოდა, მაგრამ განვითარების ნაბიჯები
  (სტატიკური ID → გარიგების პროდუქტი → ორმხრივი სინქრონიზაცია) თვალსაჩინო აღარ იქნებოდა.
- **Consequences:** `docs/bp/`-ში მხოლოდ ის დევს, რისი ჩასმაც უსაფრთხოა.