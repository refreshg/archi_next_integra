#!/usr/bin/env node
/**
 * ეტაპი 2 — იმავე REST გამოძახების ხელით ტესტირება, ბიზნეს პროცესის გარეშე.
 *
 * გაშვება:
 *   npm run set-status -- --product 1024 --status reserved
 *   npm run set-status -- --product 1024 --status sold --dry-run
 *
 * გასასვლელი კოდები: 0 = წარმატება, 2 = პროდუქტი ვერ მოიძებნა, 1 = სხვა შეცდომა
 */
import { parseArgs } from 'node:util';
import { requireWebhook, redact } from '../lib/env.js';
import { call } from '../lib/bitrix.js';
import { loadMapping, buildStatusBatch, interpretBatchResult, STATUS_KEYS } from '../lib/statusUpdate.js';

const { values } = parseArgs({
  options: {
    product: { type: 'string' },
    status: { type: 'string' },
    'dry-run': { type: 'boolean', default: false },
    help: { type: 'boolean', default: false },
  },
});

if (values.help) {
  console.log(`
გამოყენება:
  npm run set-status -- --product <ARCHI_ID> --status <${STATUS_KEYS.join('|')}> [--dry-run]

  --product   Archi-ს პროდუქტის ID (ნაგულისხმევი: .env-ის TEST_ARCHI_PRODUCT_ID)
  --status    ახალი სტატუსი: ${STATUS_KEYS.join(', ')}
  --dry-run   არაფერს აგზავნის — მხოლოდ დაბეჭდავს, რა წავიდოდა
`);
  process.exit(0);
}

/**
 * crm.product.list სიის ტიპის თვისებას აბრუნებს { value, valueId } ობიექტად,
 * catalog.* კი — lowercase გასაღებით. ორივე შემთხვევა ერთად.
 */
function readStatus(product, statusField) {
  if (!product) return '—';
  const raw = product[statusField] ?? product[statusField.toLowerCase()];
  if (raw === undefined || raw === null) return '—';
  if (Array.isArray(raw)) return raw.map((item) => readStatus({ v: item }, 'v')).join(', ');
  if (typeof raw === 'object') return String(raw.value ?? raw.valueId ?? JSON.stringify(raw));
  return String(raw);
}

async function main() {
  const baseUrl = requireWebhook('NEXT_WEBHOOK_URL');
  const archiProductId = values.product ?? process.env.TEST_ARCHI_PRODUCT_ID;
  const statusKey = values.status;

  if (!archiProductId) throw new Error('--product არ არის მითითებული (და .env-ში TEST_ARCHI_PRODUCT_ID ცარიელია).');
  if (!statusKey) throw new Error(`--status არ არის მითითებული. დასაშვებია: ${STATUS_KEYS.join(', ')}`);

  const mapping = loadMapping();
  const { api, cmds, statusId } = buildStatusBatch(mapping, { archiProductId, statusKey });
  const { archiIdField, statusField } = mapping.next;

  // `check` = იგივე ძებნა, უკვე განახლების შემდეგ — ასე ვამოწმებთ შედეგს
  const fullCmds = { ...cmds, check: cmds.find };

  console.log(`\nპორტალი : ${redact(baseUrl)}`);
  console.log(`პროდუქტი: ${archiIdField} = ${archiProductId}`);
  console.log(`სტატუსი : ${statusKey} -> ${statusField} = ${statusId}\n`);
  for (const [name, command] of Object.entries(fullCmds)) console.log(`  cmd[${name}] = ${command}`);

  if (values['dry-run']) {
    console.log('\n🧪 --dry-run: მოთხოვნა არ გაგზავნილა.');
    return;
  }

  const payload = await call(baseUrl, 'batch', { halt: 1, cmd: fullCmds });
  const outcome = interpretBatchResult(api, payload.result);

  if (!outcome.ok) {
    if (outcome.reason === 'not_found') {
      console.error(`\n❌ პროდუქტი ვერ მოიძებნა Next-ში (${archiIdField} = ${archiProductId}).`);
      process.exit(2);
    }
    console.error(`\n❌ ${outcome.reason}: ${JSON.stringify(outcome.error)}`);
    process.exit(1);
  }

  const before = api.pickFound(payload.result.result)[0];
  const after = api.pickFound({ find: payload.result.result?.check })[0];

  console.log(`\n✅ პროდუქტი ${outcome.productId} განახლდა.`);
  console.log(`   ${statusField}: ${readStatus(before, statusField)} -> ${readStatus(after, statusField)}`);
  if (outcome.found > 1) {
    console.warn(`   ⚠️ ამ ID-ით ${outcome.found} პროდუქტი მოიძებნა — განახლდა მხოლოდ პირველი. ID უნიკალური უნდა იყოს.`);
  }
}

main().catch((error) => {
  console.error(`\n❌ ${error?.message ?? error}`);
  process.exit(1);
});
