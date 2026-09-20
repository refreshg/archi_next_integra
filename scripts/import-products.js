#!/usr/bin/env node
/**
 * Archi-ს ექსპორტის იმპორტი Next-ის კატალოგში.
 *
 * ნაგულისხმევად მხოლოდ ბეჭდავს — რეალური ჩაწერისთვის საჭიროა --execute.
 *
 *   node scripts/import-products.js files/PRODUCT_*.xls
 *   node scripts/import-products.js <file> --limit 3 --execute
 *   node scripts/import-products.js <file> --execute
 */
import { parseArgs } from 'node:util';
import { requireWebhook, redact } from '../lib/env.js';
import { call, batch, cmd } from '../lib/bitrix.js';
import { loadMapping } from '../lib/statusUpdate.js';
import { parseExport } from './parse-export.js';

/** Archi-ს სტატუსი -> Next-ის სტატუსი. null = არ აიტვირთოს. */
const STATUS_MAP = {
  'თავისუფალი': 'თავისუფალი',
  'გაყიდული': 'გაყიდული',
  'დაჯავშნილი': 'ფასიანი ჯავშანი',
  'Not In Sale': null,
};

/**
 * წყაროს სვეტი -> Next-ის ველი.
 * დადასტურებულია მფლობელთან 2026-09-20.
 */
const FIELD_MAP = {
  // სტანდარტული ველები
  'Name': 'NAME',
  'ჯამური ფასი': 'PRICE',
  'Currency': 'CURRENCY_ID',
  'Description': 'DESCRIPTION',
  // თვისებები
  'ID': 'PROPERTY_546',              // UF_ARCHI_ID — ინტეგრაციის გასაღები
  'სართული': 'PROPERTY_61',
  'ფართის ტიპი': 'PROPERTY_62',
  'ბინის ნომერი': 'PROPERTY_63',
  'სტატუსი': 'PROPERTY_64',
  'საერთო ფართი მ2': 'PROPERTY_65',
  'შიდა ფართი': 'PROPERTY_66',
  'კვ.ფასი': 'PROPERTY_70',
  'ოთახების რაოდენობა': 'PROPERTY_506',
  'საზაფხულო ფართი': 'PROPERTY_507',  // -> აივნის ფართი
  'ხედი': 'PROPERTY_525',
};

/** ჯამური ფასი ორ ადგილას მიდის: სტანდარტულ PRICE-ში და თეთრი კარკასის ჯამურ ფასში. */
const DUPLICATED = { 'ჯამური ფასი': 'PROPERTY_531' };

/** წყაროში არის, მაგრამ განზრახ არ აიტვირთება (მფლობელის გადაწყვეტილება). */
/** პროექტის ველი (PROPERTY_60) წყაროდან არ მოდის — ივსება სექციის სახელით. */
const PROJECT_FIELD = 'PROPERTY_60';

const SKIPPED = ['Type', '1მ2 აქციის ფასი', 'აქციის ჯამური ფასი', 'გაზრდილი ფასი'];

const { values, positionals } = parseArgs({
  allowPositionals: true,
  options: {
    execute: { type: 'boolean', default: false },
    limit: { type: 'string' },
    'batch-size': { type: 'string', default: '25' },
  },
});

function buildProduct(row, mapping, sectionName) {
  const fields = {
    SECTION_ID: mapping.next.sectionId,
    CATALOG_ID: mapping.next.catalogId,
    ACTIVE: 'Y',
    SORT: 500,
    DESCRIPTION_TYPE: 'text',
    XML_ID: row['ID'],                 // სარეზერვო გასაღები
    [PROJECT_FIELD]: sectionName,      // პროექტი = სექციის სახელი
  };

  for (const [source, target] of Object.entries(FIELD_MAP)) {
    const value = (row[source] ?? '').trim();
    if (value === '') continue;
    fields[target] = target === 'PROPERTY_64' ? STATUS_MAP[value] : value;
  }
  for (const [source, target] of Object.entries(DUPLICATED)) {
    const value = (row[source] ?? '').trim();
    if (value !== '') fields[target] = value;
  }
  return fields;
}

async function findExisting(url, archiIds) {
  const found = new Map();
  for (let i = 0; i < archiIds.length; i += 25) {
    const chunk = archiIds.slice(i, i + 25);
    const cmds = Object.fromEntries(
      chunk.map((id) => [`p${id}`, cmd('crm.product.list', { filter: { PROPERTY_546: id }, select: ['ID'] })]),
    );
    const result = await batch(url, cmds, { halt: 0 });
    for (const id of chunk) {
      const hit = result.result?.[`p${id}`]?.[0];
      if (hit) found.set(id, hit.ID);
    }
  }
  return found;
}

/** სექციის სახელი პორტალიდან — პროექტის ველისთვის. */
async function sectionTitle(url, sectionId) {
  const r = await call(url, 'crm.productsection.list', { filter: { ID: sectionId }, select: ['ID', 'NAME'] });
  const name = r.result?.[0]?.NAME;
  if (!name) throw new Error('სექცია ' + sectionId + ' ვერ მოიძებნა Next-ში');
  return name;
}

async function main() {
  const file = positionals[0];
  if (!file) throw new Error('გამოყენება: node scripts/import-products.js <file.xls> [--limit N] [--execute]');

  const url = requireWebhook('NEXT_WEBHOOK_URL');
  const mapping = loadMapping();
  const sectionName = await sectionTitle(url, mapping.next.sectionId);
  const { headers, rows } = parseExport(file);
  const objects = rows.map((row) => Object.fromEntries(headers.map((h, i) => [h, row[i] ?? ''])));

  const skippedByStatus = objects.filter((o) => STATUS_MAP[o['სტატუსი']] === null);
  const unknownStatus = objects.filter((o) => !(o['სტატუსი'] in STATUS_MAP));
  let candidates = objects.filter((o) => STATUS_MAP[o['სტატუსი']]);

  console.log(`\nწყარო    : ${file}`);
  console.log(`პორტალი  : ${redact(url)}`);
  console.log(`სექცია   : ${mapping.next.sectionId} (კატალოგი ${mapping.next.catalogId})\n`);
  console.log(`სულ ჩანაწერი       : ${objects.length}`);
  console.log(`გამოტოვებული       : ${skippedByStatus.length}  (Not In Sale)`);
  if (unknownStatus.length) console.log(`⚠️ უცნობი სტატუსი   : ${unknownStatus.length}  ${[...new Set(unknownStatus.map((o) => o['სტატუსი']))].join(', ')}`);
  console.log(`ასატვირთი          : ${candidates.length}`);

  if (values.limit) {
    candidates = candidates.slice(0, Number(values.limit));
    console.log(`--limit            : ${candidates.length}`);
  }

  console.log(`\nუკვე არსებულის შემოწმება PROPERTY_546-ით...`);
  const existing = await findExisting(url, candidates.map((o) => o['ID']));
  const toAdd = candidates.filter((o) => !existing.has(o['ID']));
  console.log(`უკვე Next-შია      : ${existing.size}`);
  console.log(`დასამატებელი       : ${toAdd.length}`);

  if (!toAdd.length) {
    console.log('\nახალი ჩანაწერი არ არის — არაფერი გასაკეთებელია.');
    return;
  }

  console.log(`\n=== ნიმუში (პირველი ჩანაწერი) ===`);
  const sample = buildProduct(toAdd[0], mapping, sectionName);
  for (const [k, v] of Object.entries(sample)) console.log(`  ${k.padEnd(18)} = ${v}`);

  console.log(`\nარ აიტვირთება (მფლობელის გადაწყვეტილება): ${SKIPPED.join(', ')}`);

  if (!values.execute) {
    console.log(`\n🧪 სატესტო რეჟიმი — არაფერი ჩაწერილა. რეალური იმპორტისთვის: --execute`);
    return;
  }

  const size = Number(values['batch-size']);
  let added = 0;
  const errors = [];

  for (let i = 0; i < toAdd.length; i += size) {
    const chunk = toAdd.slice(i, i + size);
    const cmds = Object.fromEntries(
      chunk.map((row, n) => [`a${i + n}`, cmd('crm.product.add', { fields: buildProduct(row, mapping, sectionName) })]),
    );
    const result = await batch(url, cmds, { halt: 0 });

    for (const [key, value] of Object.entries(result.result ?? {})) if (value) added += 1;
    const failed = result.result_error;
    if (failed && !Array.isArray(failed)) {
      for (const [key, err] of Object.entries(failed)) {
        errors.push({ index: key, archiId: chunk[Number(key.slice(1)) - i]?.['ID'], error: err });
      }
    }
    process.stdout.write(`\r  დამატებული: ${added} / ${toAdd.length}`);
  }

  console.log(`\n\n✅ დაემატა ${added} პროდუქტი.`);
  if (errors.length) {
    console.log(`❌ შეცდომა ${errors.length} ჩანაწერზე. პირველი 5:`);
    for (const e of errors.slice(0, 5)) console.log(`   Archi ID ${e.archiId}: ${JSON.stringify(e.error)}`);
  }
}

main().catch((error) => {
  console.error(`\n❌ ${error?.message ?? error}`);
  process.exit(1);
});
