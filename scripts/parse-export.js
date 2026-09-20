#!/usr/bin/env node
/**
 * ბიტრიქსის პროდუქტების ექსპორტის პარსერი.
 *
 * ბიტრიქსი `.xls` სახელით ინახავს ჩვეულებრივ HTML ცხრილს — ეს სკრიპტი მას
 * კითხულობს, JSON-ად აქცევს და ბეჭდავს ველების ანგარიშს: შევსებულობა,
 * უნიკალური მნიშვნელობები, ნიმუშები.
 *
 * გაშვება:
 *   node scripts/parse-export.js files/PRODUCT_*.xls
 *   node scripts/parse-export.js <file> --json out.json
 *   node scripts/parse-export.js <file> --column "სტატუსი"
 */
import fs from 'node:fs';
import { parseArgs } from 'node:util';

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

function decode(text) {
  return text
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<[^>]*>/g, '')
    .replace(/&#(\d+);/g, (_, code) => String.fromCharCode(Number(code)))
    .replace(/&([a-z]+);/gi, (match, name) => ENTITIES[name.toLowerCase()] ?? match)
    .trim();
}

/** @returns {{headers: string[], rows: string[][]}} */
export function parseExport(file) {
  const html = fs.readFileSync(file, 'utf8');
  const blocks = [...html.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/gi)].map((m) => m[1]);
  if (!blocks.length) throw new Error('ცხრილის სტრიქონები ვერ მოიძებნა — ეს ბიტრიქსის HTML ექსპორტი ნამდვილად არის?');

  const cellsOf = (block) => [...block.matchAll(/<t[dh][^>]*>([\s\S]*?)<\/t[dh]>/gi)].map((m) => decode(m[1]));

  const [headerBlock, ...rest] = blocks;
  return { headers: cellsOf(headerBlock), rows: rest.map(cellsOf).filter((row) => row.length) };
}

/** ველების ანგარიში: შევსებულობა, უნიკალურობა, ნიმუშები. */
export function fieldReport(headers, rows) {
  return headers.map((name, index) => {
    const values = rows.map((row) => row[index] ?? '');
    const filled = values.filter((value) => value !== '');
    const distinct = new Set(filled);
    const numeric = filled.length > 0 && filled.every((v) => /^-?\d+([.,]\d+)?$/.test(v.replace(/\s/g, '')));

    return {
      index,
      name,
      filled: filled.length,
      fillRate: rows.length ? Math.round((filled.length / rows.length) * 100) : 0,
      distinct: distinct.size,
      numeric,
      samples: [...distinct].slice(0, 5),
    };
  });
}

function main() {
  const { values, positionals } = parseArgs({
    allowPositionals: true,
    options: { json: { type: 'string' }, column: { type: 'string' }, all: { type: 'boolean', default: false } },
  });

  const file = positionals[0];
  if (!file) throw new Error('გამოყენება: node scripts/parse-export.js <file.xls> [--json out.json] [--column <name>] [--all]');

  const { headers, rows } = parseExport(file);
  const report = fieldReport(headers, rows);

  if (values.json) {
    const objects = rows.map((row) => Object.fromEntries(headers.map((h, i) => [h, row[i] ?? ''])));
    fs.writeFileSync(values.json, JSON.stringify(objects, null, 2), 'utf8');
    console.log(`✓ ${objects.length} ჩანაწერი ჩაიწერა: ${values.json}`);
    return;
  }

  if (values.column) {
    const field = report.find((f) => f.name === values.column);
    if (!field) throw new Error(`სვეტი "${values.column}" ვერ მოიძებნა`);
    const counts = new Map();
    for (const row of rows) {
      const value = row[field.index] ?? '';
      counts.set(value, (counts.get(value) ?? 0) + 1);
    }
    console.log(`\n"${field.name}" — ${field.distinct} უნიკალური მნიშვნელობა ${rows.length} ჩანაწერში\n`);
    for (const [value, count] of [...counts].sort((a, b) => b[1] - a[1])) {
      console.log(`  ${String(count).padStart(5)}  ${value === '' ? '(ცარიელი)' : value}`);
    }
    return;
  }

  console.log(`\nფაილი: ${file}`);
  console.log(`სვეტი: ${headers.length}   ჩანაწერი: ${rows.length}\n`);
  console.log('  #  ველი                                    შევს.%  უნიკ.  ნიმუში');
  console.log('─'.repeat(110));
  for (const field of report) {
    if (!values.all && field.filled === 0) continue;
    const sample = field.samples.join(' | ').replace(/\n/g, ' ').slice(0, 42);
    console.log(
      `${String(field.index + 1).padStart(4)}  ${field.name.slice(0, 38).padEnd(38)}  ${String(field.fillRate).padStart(5)}  ${String(field.distinct).padStart(5)}  ${sample}`,
    );
  }

  const empty = report.filter((f) => f.filled === 0);
  if (empty.length && !values.all) {
    console.log(`\nსრულიად ცარიელი ${empty.length} ველი (--all რომ ნახო):`);
    console.log('  ' + empty.map((f) => f.name).join(', '));
  }
}

// main() მხოლოდ მაშინ, როცა ფაილი პირდაპირ გაეშვა — და არა import-ისას.
const invokedDirectly = (process.argv[1] ?? '').endsWith('parse-export.js');
if (invokedDirectly) {
  try {
    main();
  } catch (error) {
    console.error(`
❌ ${error?.message ?? error}`);
    process.exit(1);
  }
}
