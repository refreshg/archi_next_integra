/**
 * Bitrix24 REST კლიენტი შემომავალი webhook-ისთვის.
 * გარე დამოკიდებულება არ გვჭირდება — Node 18+-ის fetch საკმარისია.
 */

const DEFAULT_TIMEOUT_MS = 20_000;
const DEFAULT_RETRIES = 2;

export class BitrixError extends Error {
  constructor(method, payload, httpStatus) {
    const code = payload?.error ?? `http_${httpStatus ?? 'unknown'}`;
    const description = payload?.error_description ?? '';
    super(`${method}: ${code}${description ? ` — ${description}` : ''}`);
    this.name = 'BitrixError';
    this.method = method;
    this.code = code;
    this.httpStatus = httpStatus;
    this.payload = payload;
  }
}

/**
 * Bitrix (PHP) ელოდება ჩალაგებულ პარამეტრებს ფრჩხილებიანი ფორმით:
 *   { filter: { XML_ID: 15 }, select: ['ID'] }  ->  filter[XML_ID]=15&select[0]=ID
 * აბრუნებს [key, value] წყვილების მასივს — კოდირების გარეშე.
 */
export function flattenParams(params) {
  const pairs = [];

  const walk = (value, prefix) => {
    if (value === undefined || value === null) return;
    if (Array.isArray(value)) {
      value.forEach((item, index) => walk(item, `${prefix}[${index}]`));
      return;
    }
    if (typeof value === 'object') {
      for (const [key, item] of Object.entries(value)) walk(item, `${prefix}[${key}]`);
      return;
    }
    pairs.push([prefix, String(value)]);
  };

  for (const [key, value] of Object.entries(params)) walk(value, key);
  return pairs;
}

/** flattenParams-ის შედეგი -> `a%5Bb%5D=c&...` query string. */
export function toQueryString(params) {
  return flattenParams(params)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
    .join('&');
}

/** batch-ის ერთი ბრძანება: `crm.product.list?filter%5BXML_ID%5D=15&select%5B0%5D=ID` */
export function cmd(method, params = {}) {
  const query = toQueryString(params);
  return query ? `${method}?${query}` : method;
}

const isRetriable = (error) =>
  error instanceof BitrixError
    ? Boolean(error.httpStatus && error.httpStatus >= 500) || error.code === 'QUERY_LIMIT_EXCEEDED'
    : true; // ქსელის/timeout-ის შეცდომა

/**
 * ერთი REST მეთოდის გამოძახება.
 * @returns {Promise<object>} Bitrix-ის სრული პასუხი ({ result, time, ... })
 */
export async function call(baseUrl, method, params = {}, options = {}) {
  const { timeoutMs = DEFAULT_TIMEOUT_MS, retries = DEFAULT_RETRIES } = options;
  const url = `${baseUrl.replace(/\/+$/, '')}/${method}.json`;
  const body = toQueryString(params);

  let lastError;
  for (let attempt = 0; attempt <= retries; attempt += 1) {
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
        body,
        signal: AbortSignal.timeout(timeoutMs),
      });

      const text = await response.text();
      let payload;
      try {
        payload = JSON.parse(text);
      } catch {
        throw new BitrixError(method, { error_description: text.slice(0, 300) }, response.status);
      }

      if (payload.error || !response.ok) throw new BitrixError(method, payload, response.status);
      return payload;
    } catch (error) {
      lastError = error;
      if (attempt === retries || !isRetriable(error)) throw error;
      await new Promise((resolve) => setTimeout(resolve, 500 * 2 ** attempt));
    }
  }
  throw lastError;
}

/**
 * batch — რამდენიმე მეთოდი ერთ HTTP მოთხოვნაში, `$result[...]` ჯაჭვის მხარდაჭერით.
 * @param {Record<string, string>} cmds  cmd() -ით აწყობილი ბრძანებები
 * @param {number} halt  1 = პირველივე შეცდომაზე გაჩერება
 * @returns {Promise<{result: object, result_error: object, result_time: object}>}
 */
export async function batch(baseUrl, cmds, { halt = 1, ...options } = {}) {
  const payload = await call(baseUrl, 'batch', { halt, cmd: cmds }, options);
  return payload.result ?? {};
}

/** მოკლე ჩანაწერი: მხოლოდ `result` ველი. */
export async function callResult(baseUrl, method, params = {}, options = {}) {
  const payload = await call(baseUrl, method, params, options);
  return payload.result;
}
