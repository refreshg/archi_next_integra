#!/usr/bin/env node
/**
 * საწყისი ხაზის ჩაწერა Interga_History-ში იმპორტირებულ პროდუქტებზე.
 *
 * რატომ: BP ადგენს, ვისია ახლანდელი სტატუსი, ისტორიის ბოლო "X → Y ... | Archi BP"
 * ხაზით. იმპორტირებულ ბინებს ასეთი ხაზი არ აქვთ, ამიტომ მათი სტატუსი "Next-ისად"
 * ითვლება და Archi ვერ ცვლის. ეს სკრიპტი ამ ხაზს ამატებს — ყველაზე ძველ პოზიციაზე.
 *
 *   node scripts/backfill-history.js                # სატესტო რეჟიმი
 *   node scripts/backfill-history.js --execute      # რეალური ჩაწერა
 */
import { parseArgs } from 'node:util';
import { requireWebhook, redact } from '../lib/env.js';
import { call, batch, cmd } from '../lib/bitrix.js';
import { loadMapping } from '../lib/statusUpdate.js';

const MARK = 'იმპორტი Archi-დან';

const { values } = parseArgs({
  options: { execute: { type: 'boolean', default: false }, date: { type: 'string' } },
});

const readText = (raw) => (typeof raw === 'object' && raw !== null ? (raw.TEXT ?? '') : (raw ?? ''));

async function main() {
  const url = requireWebhook('NEXT_WEBHOOK_URL');
  const mapping = loadMapping();
  const { sectionId, statusField, archiIdField } = {
    sectionId: mapping.next.sectionId,
    statusField: mapping.next.statusField,
    archiIdField: mapping.next.archiIdField,
  };
  const logField = 'PROPERTY_547';
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const stamp = values.date ?? pad(now.getDate()) + '.' + pad(now.getMonth() + 1) + '.' + now.getFullYear();

  const all = [];
  let start = 0;
  for (;;) {
    const r = await call(url, 'crm.product.list', {
      filter: { SECTION_ID: sectionId },
      select: ['ID', 'NAME', archiIdField, statusField, logField],
      start,
    });
    all.push(...(r.result ?? []));
    if (r.next === undefined) break;
    start = r.next;
  }

  console.log(`\nპორტალი : ${redact(url)}`);
  console.log(`სექცია  : ${sectionId}`);
  console.log(`პროდუქტი: ${all.length}\n`);

  const todo = [];
  for (const p of all) {
    const log = readText(p[logField]?.value);
    if (log.includes(MARK)) continue; // უკვე აქვს
    const status = p[statusField]?.value;
    if (!status) continue;
    const line = `${stamp} | — → ${status} | ${MARK} | Archi BP`;
    todo.push({ id: p.ID, name: p.NAME, status, text: log ? `${log}<br>${line}` : line });
  }

  const byStatus = new Map();
  for (const t of todo) byStatus.set(t.status, (byStatus.get(t.status) ?? 0) + 1);

  console.log(`დასამატებელი: ${todo.length} / ${all.length}`);
  for (const [s, c] of byStatus) console.log(`  ${String(c).padStart(4)}  ${s}`);
  if (todo.length) console.log(`\nნიმუში (ბინა ${todo[0].name}):\n  ${todo[0].text.split('<br>').pop()}`);

  if (!todo.length) return console.log('\nყველას უკვე აქვს საწყისი ხაზი — არაფერი გასაკეთებელია.');
  if (!values.execute) return console.log('\n🧪 სატესტო რეჟიმი — არაფერი ჩაწერილა. რეალურად: --execute');

  let done = 0;
  for (let i = 0; i < todo.length; i += 25) {
    const chunk = todo.slice(i, i + 25);
    const cmds = Object.fromEntries(
      chunk.map((t) => [`u${t.id}`, cmd('crm.product.update', {
        id: t.id,
        fields: { [logField]: { TEXT: t.text, TYPE: 'HTML' } },
      })]),
    );
    const res = await batch(url, cmds, { halt: 0 });
    done += Object.values(res.result ?? {}).filter(Boolean).length;
    process.stdout.write(`\r  ჩაწერილი: ${done} / ${todo.length}`);
  }
  console.log(`\n\n✅ ${done} პროდუქტს დაემატა საწყისი ხაზი.`);
}

main().catch((error) => {
  console.error(`\n❌ ${error?.message ?? error}`);
  process.exit(1);
});
