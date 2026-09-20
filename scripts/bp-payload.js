#!/usr/bin/env node
/**
 * ეტაპი 3 — ბეჭდავს ზუსტად იმ პარამეტრებს, რაც Archi-ს ბიზნეს პროცესის
 * აქტივობაში „Webhook-ის გამოძახება" უნდა ჩაისვას (თითო სტატუსზე).
 *
 * გაშვება:
 *   npm run bp-payload                 # სამივე სტატუსი
 *   npm run bp-payload -- --status sold
 */
import { parseArgs } from 'node:util';
import { loadEnv } from '../lib/env.js';
import { loadMapping, buildStatusBatch, STATUS_KEYS } from '../lib/statusUpdate.js';

loadEnv();

// სენტინელი, რომელსაც encodeURIComponent არ შეცვლის — შემდეგ BP-ს
// ჩანაცვლების შაბლონით ვცვლით, რომ ფიგურული ფრჩხილები არ დაიკოდირდეს.
const ID_SENTINEL = '__ARCHI_PRODUCT_ID__';

const { values } = parseArgs({
  options: { status: { type: 'string' } },
});

function main() {
  const mapping = loadMapping();
  const statuses = values.status ? [values.status] : STATUS_KEYS;

  const productIdField = mapping.archi?.productIdField;
  const placeholder = `{=Document:${productIdField ?? 'UF_CRM_XXXX'}}`;

  const webhook = process.env.NEXT_WEBHOOK_URL?.replace(/\/+$/, '') ?? 'https://next.bitrix24.ge/rest/<USER_ID>/<TOKEN>';

  console.log('═'.repeat(72));
  console.log('ბიზნეს პროცესის აქტივობა: „Webhook-ის გამოძახება" (Вызов вебхука)');
  console.log('═'.repeat(72));
  console.log(`\nმეთოდი : POST`);
  console.log(`URL    : ${webhook}/batch.json`);
  if (process.env.NEXT_WEBHOOK_URL) {
    console.log('         ⚠️ ზემოთ რეალური ტოკენია — სქრინშოტით ან ჩატით არ გააზიარო.');
  }

  if (!productIdField) {
    console.log('\n⚠️ config/mapping.json -> archi.productIdField ცარიელია.');
    console.log(`   ქვემოთ დროებით ჩასმულია ${placeholder} — შეცვალე რეალური ველის კოდით.`);
  }

  for (const statusKey of statuses) {
    const { cmds, statusId } = buildStatusBatch(mapping, {
      archiProductId: ID_SENTINEL,
      statusKey,
    });

    console.log(`\n${'─'.repeat(72)}`);
    console.log(`სტატუსი: ${statusKey.toUpperCase()}  (მნიშვნელობა = "${statusId}")`);
    console.log('─'.repeat(72));
    console.log('\nპარამეტრები (სახელი -> მნიშვნელობა):\n');
    console.log(`  halt      ->  1`);
    for (const [name, command] of Object.entries(cmds)) {
      console.log(`  cmd[${name}]  ->  ${command.split(ID_SENTINEL).join(placeholder)}`);
    }
  }

  console.log(`\n${'═'.repeat(72)}`);
  console.log(`შენიშვნები:
  • ფრჩხილები %5B %5D სახით ჩანს — ეს სწორია, ბიტრიქსი თვითონ გაშიფრავს.
  • ${placeholder} — ბიზნეს პროცესი ჩაანაცვლებს გარიგების ველის მნიშვნელობით.
  • cmd[find] პოულობს პროდუქტს, cmd[upd] კი $result[find]-ით იღებს ნაპოვნის ID-ს.
  • halt=1 ნიშნავს: თუ ძებნა ჩავარდა, განახლება აღარ შესრულდება.
  • აქტივობის პასუხი შეინახე BP-ს ცვლადში და შეამოწმე — თუ result_error არაცარიელია,
    დაწერე კომენტარი გარიგებაზე და აცნობე პასუხისმგებელს (ეტაპი 4).`);
  console.log('═'.repeat(72));
}

try {
  main();
} catch (error) {
  console.error(`\n❌ ${error?.message ?? error}`);
  process.exit(1);
}
