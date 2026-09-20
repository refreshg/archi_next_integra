import fs from 'node:fs';
import path from 'node:path';
import { cmd } from './bitrix.js';
import { projectRoot } from './env.js';

/**
 * ორივე API-ს განსხვავებები ერთ ადგილას.
 * `crm.*` — CRM-ის პროდუქტები (ძველი, ყველა პორტალზეა).
 * `catalog.*` — სავაჭრო კატალოგის ახალი API (property<ID> ფილტრი, lowercase ველები).
 */
const API = {
  crm: {
    list: 'crm.product.list',
    update: 'crm.product.update',
    idChain: '$result[find][0][ID]',
    listParams: ({ filter, select }) => ({ order: { ID: 'ASC' }, filter, select }),
    pickFound: (result) => (Array.isArray(result?.find) ? result.find : []),
    idKey: 'ID',
  },
  catalog: {
    list: 'catalog.product.list',
    update: 'catalog.product.update',
    idChain: '$result[find][products][0][id]',
    listParams: ({ filter, select }) => ({ select: select.map((f) => f.toLowerCase()), filter }),
    pickFound: (result) => (Array.isArray(result?.find?.products) ? result.find.products : []),
    idKey: 'id',
  },
};

export const STATUS_KEYS = ['free', 'hold', 'reserved', 'sold'];

/** MAPPING_FILE გარემოს ცვლადით შეიძლება სხვა კონფიგის მითითება (ტესტებისთვის). */
export function loadMapping(file = process.env.MAPPING_FILE ?? path.join(projectRoot, 'config', 'mapping.json')) {
  if (!fs.existsSync(file)) {
    throw new Error(`კონფიგი ვერ მოიძებნა: ${file}`);
  }
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/** ამოწმებს, რომ discovery-ის შემდეგ mapping.json შევსებულია. */
export function assertMappingReady(mapping) {
  const next = mapping?.next ?? {};
  const missing = [];

  if (!next.archiIdField) missing.push('next.archiIdField');
  if (!next.statusField) missing.push('next.statusField');
  for (const key of STATUS_KEYS) {
    if (next.statusValues?.[key] === null || next.statusValues?.[key] === undefined) {
      missing.push(`next.statusValues.${key}`);
    }
  }
  if (!API[next.api ?? 'crm']) missing.push(`next.api (დასაშვები: ${Object.keys(API).join(', ')})`);

  if (missing.length) {
    throw new Error(
      `config/mapping.json შევსებული არ არის. აკლია:\n  - ${missing.join('\n  - ')}\n` +
        'ჯერ გაუშვი: npm run discover',
    );
  }
}

export function resolveStatusId(mapping, statusKey) {
  if (!STATUS_KEYS.includes(statusKey)) {
    throw new Error(`უცნობი სტატუსი "${statusKey}". დასაშვებია: ${STATUS_KEYS.join(', ')}`);
  }
  return String(mapping.next.statusValues[statusKey]);
}

/**
 * აწყობს batch-ს, რომელიც ერთ HTTP მოთხოვნაში:
 *   1. პოულობს Next-ის პროდუქტს Archi-ს ID-ით,
 *   2. ცვლის მის სტატუსს.
 * იგივე ფორმა გამოიყენება ბიზნეს პროცესის webhook-აქტივობაშიც.
 *
 * @param {object} mapping config/mapping.json
 * @param {{archiProductId: string|number, statusKey: string}} input
 */
export function buildStatusBatch(mapping, { archiProductId, statusKey }) {
  assertMappingReady(mapping);

  const api = API[mapping.next.api ?? 'crm'];
  const { archiIdField, statusField, extraFilter = {} } = mapping.next;
  const statusId = resolveStatusId(mapping, statusKey);

  const filter = { ...extraFilter, [archiIdField]: archiProductId };
  const select = [api.idKey, archiIdField, statusField];

  return {
    api,
    statusId,
    cmds: {
      find: cmd(api.list, api.listParams({ filter, select })),
      upd: cmd(api.update, { id: api.idChain, fields: { [statusField]: statusId } }),
    },
  };
}

/**
 * ცარიელი შეცდომების დროს Bitrix აბრუნებს `result_error: []` (მასივს),
 * ამიტომ პირდაპირ `errors.find`-ს ვერ ვკითხულობთ — Array.prototype.find დაგვიბრუნდება.
 */
function errorOf(errors, key) {
  if (!errors || typeof errors !== 'object') return undefined;
  if (Array.isArray(errors)) return undefined;
  return Object.prototype.hasOwnProperty.call(errors, key) ? errors[key] : undefined;
}

/**
 * batch-ის პასუხის ნორმალიზება ერთ გასაგებ ობიექტად.
 * @returns {{ok: boolean, reason?: string, productId?: string, found: number}}
 */
export function interpretBatchResult(api, batchResponse) {
  const result = batchResponse?.result ?? {};
  const errors = batchResponse?.result_error;
  const found = api.pickFound(result);

  const findError = errorOf(errors, 'find');
  if (findError) return { ok: false, reason: 'search_failed', found: 0, error: findError };

  if (!found.length) return { ok: false, reason: 'not_found', found: 0 };

  const updateError = errorOf(errors, 'upd');
  if (updateError) return { ok: false, reason: 'update_failed', found: found.length, error: updateError };

  return { ok: true, found: found.length, productId: String(found[0][api.idKey]) };
}
