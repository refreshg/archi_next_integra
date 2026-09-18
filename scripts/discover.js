#!/usr/bin/env node
/**
 * ეტაპი 0 — რეკოგნოსცირება.
 *
 * ორივე პორტალს ეკითხება ველების კონფიგურაციას და გამოსავალს წერს:
 *   docs/next-fields.md   — Next-ის პროდუქტის ველები + dropdown-ის მნიშვნელობების ID-ები
 *   docs/archi-fields.md  — Archi-ს გარიგების ველები + სტადიები
 *   docs/discovery/*.json — ნედლი პასუხები (სამახსოვროდ)
 *
 * გაშვება:  npm run discover
 */
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv, projectRoot, redact } from '../lib/env.js';
import { call, BitrixError } from '../lib/bitrix.js';

loadEnv();

const DOCS_DIR = path.join(projectRoot, 'docs');
const RAW_DIR = path.join(DOCS_DIR, 'discovery');

const PORTALS = [
  {
    key: 'next',
    title: 'Next — პროდუქტების პორტალი',
    envKey: 'NEXT_WEBHOOK_URL',
    outFile: 'next-fields.md',
    probes: [
      { key: 'profile', method: 'profile' },
      { key: 'productFields', method: 'crm.product.fields' },
      { key: 'productProperties', method: 'crm.productproperty.list' },
      {
        key: 'productUserFields',
        method: 'userfieldconfig.list',
        params: { moduleId: 'crm', filter: { ENTITY_ID: 'CRM_PRODUCT' } },
      },
      { key: 'catalogs', method: 'catalog.catalog.list' },
      { key: 'productSample', method: 'crm.product.list', params: { select: ['*', 'PROPERTY_*'] } },
    ],
    render: renderNext,
  },
  {
    key: 'archi',
    title: 'Archi — გარიგებების პორტალი',
    envKey: 'ARCHI_WEBHOOK_URL',
    outFile: 'archi-fields.md',
    probes: [
      { key: 'profile', method: 'profile' },
      { key: 'dealFields', method: 'crm.deal.fields' },
      { key: 'dealUserFields', method: 'crm.dealuserfield.list' },
      { key: 'categories', method: 'crm.category.list', params: { entityTypeId: 2 } },
      { key: 'stages', method: 'crm.status.list', params: { filter: { ENTITY_ID: 'DEAL_STAGE' } } },
    ],
    render: renderArchi,
  },
];

// ─── markdown-ის დამხმარეები ────────────────────────────────────────────────

const cell = (value) => String(value ?? '').replace(/\|/g, '\\|').replace(/\r?\n/g, ' ');

function table(headers, rows) {
  if (!rows.length) return '_ჩანაწერები არ მოიძებნა._\n';
  return [
    `| ${headers.join(' | ')} |`,
    `|${headers.map(() => '---').join('|')}|`,
    ...rows.map((row) => `| ${row.map(cell).join(' | ')} |`),
    '',
  ].join('\n');
}

const fieldTitle = (field) => field?.title ?? field?.listLabel ?? field?.formLabel ?? field?.editFormLabel ?? '';

function section(heading, body) {
  return `## ${heading}\n\n${body}\n`;
}

function probeError(probe) {
  return `> ⚠️ \`${probe.method}\` ვერ შესრულდა: ${probe.error}\n`;
}

// ─── Next-ის რენდერი ────────────────────────────────────────────────────────

function renderNext(results) {
  const parts = [];

  // 1. სიის ტიპის თვისებები — სწორედ აქ უნდა იყოს სტატუსი
  const properties = results.productProperties?.value ?? [];
  const listProps = properties.filter((p) => Array.isArray(p.VALUES) && p.VALUES.length);

  if (results.productProperties?.error) {
    parts.push(section('პროდუქტის თვისებები', probeError(results.productProperties)));
  } else {
    let body = table(
      ['კოდი (mapping-ისთვის)', 'ID', 'დასახელება', 'ტიპი'],
      properties.map((p) => [`PROPERTY_${p.ID}`, p.ID, p.NAME, p.PROPERTY_TYPE]),
    );

    if (listProps.length) {
      body += '\n### სიის (dropdown) ტიპის თვისებები და მათი მნიშვნელობების ID-ები\n\n';
      body += '> ❗ სტატუსის ჩასაწერად გვჭირდება სწორედ `VALUE ID` და არა ტექსტი.\n\n';
      for (const prop of listProps) {
        body += `**PROPERTY_${prop.ID} — ${prop.NAME}**\n\n`;
        body += table(
          ['VALUE ID', 'მნიშვნელობა', 'ნაგულისხმევი'],
          prop.VALUES.map((v) => [v.ID, v.VALUE, v.DEF === 'Y' ? 'კი' : '']),
        );
      }
    }
    parts.push(section('პროდუქტის თვისებები (crm.productproperty.list)', body));
  }

  // 2. მომხმარებლის ველები (UF_)
  const userFields = results.productUserFields?.value ?? [];
  parts.push(
    section(
      'პროდუქტის მომხმარებლის ველები (UF_)',
      results.productUserFields?.error
        ? probeError(results.productUserFields)
        : table(
            ['კოდი', 'ტიპი', 'სავალდებულო'],
            userFields.map((f) => [f.FIELD_NAME, f.USER_TYPE_ID, f.MANDATORY]),
          ),
    ),
  );

  // 3. ყველა ველი (ფილტრაციისთვის რომელი გამოგვადგება)
  const fields = results.productFields?.value ?? {};
  parts.push(
    section(
      'პროდუქტის ყველა ველი (crm.product.fields)',
      results.productFields?.error
        ? probeError(results.productFields)
        : table(
            ['კოდი', 'დასახელება', 'ტიპი'],
            Object.entries(fields).map(([code, f]) => [code, fieldTitle(f), f.type]),
          ),
    ),
  );

  // 4. კატალოგები
  const catalogs = results.catalogs?.value?.catalogs ?? [];
  parts.push(
    section(
      'კატალოგები',
      results.catalogs?.error
        ? probeError(results.catalogs)
        : table(['id', 'iblockId', 'name'], catalogs.map((c) => [c.id, c.iblockId, c.name])),
    ),
  );

  // 5. პროდუქტის ნიმუში — ვხედავთ, სად წერია Archi-ს ID
  const sample = (results.productSample?.value ?? []).slice(0, 3);
  let sampleBody = results.productSample?.error ? probeError(results.productSample) : '';
  if (!results.productSample?.error) {
    sampleBody = sample.length
      ? sample
          .map(
            (product) =>
              `**ID ${product.ID} — ${product.NAME}**\n\n\`\`\`json\n${JSON.stringify(product, null, 2)}\n\`\`\`\n`,
          )
          .join('\n')
      : '_პროდუქტები ვერ მოიძებნა._\n';
  }
  parts.push(section('პროდუქტების ნიმუში (პირველი 3)', sampleBody));

  // 6. mapping.json-ის შესავსები ნაწილი
  parts.push(section('შემდეგი ნაბიჯი — config/mapping.json', suggestMapping(listProps, userFields)));

  return parts.join('\n');
}

/** ევრისტიკა: 2–5 მნიშვნელობიანი სია, სავარაუდოდ, სტატუსია. */
function suggestMapping(listProps, userFields) {
  const candidates = listProps.filter((p) => p.VALUES.length >= 2 && p.VALUES.length <= 6);
  const idFieldGuesses = [
    'XML_ID',
    ...userFields.map((f) => f.FIELD_NAME).filter((name) => /ARCHI|EXTERNAL|SOURCE/i.test(name)),
  ];

  let body = 'სტატუსის ველის კანდიდატები (2–6 მნიშვნელობიანი სიები):\n\n';
  body += candidates.length
    ? candidates.map((p) => `- \`PROPERTY_${p.ID}\` — ${p.NAME}: ${p.VALUES.map((v) => `${v.VALUE} (ID ${v.ID})`).join(', ')}`).join('\n')
    : '- _ვერ მოიძებნა; შეამოწმე ზემოთ სრული სია._';

  body += `\n\nArchi-ს ID-ის ველის კანდიდატები: ${idFieldGuesses.map((g) => `\`${g}\``).join(', ')}\n`;
  body += '\nაირჩიე სწორი ვარიანტები და ჩაწერე `config/mapping.json`-ში:\n\n';
  body += '```json\n';
  body += JSON.stringify(
    {
      next: {
        api: 'crm',
        archiIdField: idFieldGuesses[0] ?? 'XML_ID',
        statusField: candidates[0] ? `PROPERTY_${candidates[0].ID}` : 'PROPERTY_???',
        statusValues: {
          free: candidates[0]?.VALUES?.[0]?.ID ?? null,
          reserved: candidates[0]?.VALUES?.[1]?.ID ?? null,
          sold: candidates[0]?.VALUES?.[2]?.ID ?? null,
        },
      },
    },
    null,
    2,
  );
  body += '\n```\n\n⚠️ `statusValues`-ის თანმიმდევრობა ავტომატურად გამოცნობილია — აუცილებლად გადაამოწმე.\n';
  return body;
}

// ─── Archi-ს რენდერი ────────────────────────────────────────────────────────

function renderArchi(results) {
  const parts = [];

  const categories = results.categories?.value?.categories ?? [];
  parts.push(
    section(
      'გარიგების მიმართულებები (ძაბრები)',
      results.categories?.error
        ? probeError(results.categories)
        : table(['ID', 'დასახელება'], categories.map((c) => [c.id, c.name])),
    ),
  );

  const stages = results.stages?.value ?? [];
  parts.push(
    section(
      'გარიგების სტადიები',
      results.stages?.error
        ? probeError(results.stages)
        : table(
            ['STATUS_ID (mapping-ისთვის)', 'დასახელება', 'ENTITY_ID'],
            stages.map((s) => [s.STATUS_ID, s.NAME, s.ENTITY_ID]),
          ),
    ),
  );

  const userFields = results.dealUserFields?.value ?? [];
  parts.push(
    section(
      'გარიგების მომხმარებლის ველები (UF_CRM_*)',
      results.dealUserFields?.error
        ? probeError(results.dealUserFields)
        : table(
            ['კოდი', 'დასახელება', 'ტიპი'],
            userFields.map((f) => [f.FIELD_NAME, f.EDIT_FORM_LABEL?.en ?? f.LIST_COLUMN_LABEL?.en ?? '', f.USER_TYPE_ID]),
          ),
    ),
  );

  const fields = results.dealFields?.value ?? {};
  parts.push(
    section(
      'გარიგების ყველა ველი (crm.deal.fields)',
      results.dealFields?.error
        ? probeError(results.dealFields)
        : table(
            ['კოდი', 'დასახელება', 'ტიპი'],
            Object.entries(fields).map(([code, f]) => [code, fieldTitle(f), f.type]),
          ),
    ),
  );

  parts.push(
    section(
      'შემდეგი ნაბიჯი',
      'აირჩიე ველი, სადაც პროდუქტის ID წერია, და სტადიები, რომლებზეც სტატუსი უნდა შეიცვალოს — ' +
        'და ჩაწერე `config/mapping.json`-ის `archi` სექციაში.\n',
    ),
  );

  return parts.join('\n');
}

// ─── გაშვება ────────────────────────────────────────────────────────────────

async function runPortal(portal) {
  const baseUrl = process.env[portal.envKey]?.replace(/\/+$/, '');
  if (!baseUrl) {
    console.log(`⏭  ${portal.title}: ${portal.envKey} არ არის .env-ში — გამოტოვებულია`);
    return;
  }

  console.log(`\n🔍 ${portal.title}  (${redact(baseUrl)})`);
  const results = {};

  for (const probe of portal.probes) {
    try {
      const payload = await call(baseUrl, probe.method, probe.params ?? {});
      results[probe.key] = { method: probe.method, value: payload.result };
      const count = Array.isArray(payload.result)
        ? `${payload.result.length} ჩანაწერი`
        : typeof payload.result === 'object'
          ? `${Object.keys(payload.result ?? {}).length} ველი`
          : 'ok';
      console.log(`   ✓ ${probe.method} — ${count}`);
    } catch (error) {
      const message = error instanceof BitrixError ? error.message : String(error?.message ?? error);
      results[probe.key] = { method: probe.method, error: message };
      console.log(`   ✗ ${probe.method} — ${message}`);
    }
  }

  fs.mkdirSync(RAW_DIR, { recursive: true });
  fs.writeFileSync(path.join(RAW_DIR, `${portal.key}.json`), JSON.stringify(results, null, 2), 'utf8');

  const header = `# ${portal.title}\n\n_ავტომატურად გენერირებული: ${new Date().toISOString()}_\n_პორტალი: ${redact(baseUrl)}_\n\n`;
  const outPath = path.join(DOCS_DIR, portal.outFile);
  fs.writeFileSync(outPath, header + portal.render(results), 'utf8');
  console.log(`   📄 ჩაიწერა: docs/${portal.outFile}`);
}

async function main() {
  fs.mkdirSync(DOCS_DIR, { recursive: true });

  const configured = PORTALS.filter((p) => process.env[p.envKey]);
  if (!configured.length) {
    console.error('❌ .env-ში არც NEXT_WEBHOOK_URL და არც ARCHI_WEBHOOK_URL არ არის მითითებული.');
    console.error('   დააკოპირე .env.example -> .env და შეავსე.');
    process.exit(1);
  }

  for (const portal of PORTALS) await runPortal(portal);
  console.log('\n✅ მზადაა. გახსენი docs/ და შეავსე config/mapping.json.');
}

main().catch((error) => {
  console.error(`\n❌ ${error?.message ?? error}`);
  process.exit(1);
});
