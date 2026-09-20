import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const projectRoot = path.resolve(fileURLToPath(import.meta.url), '..', '..');

/**
 * .env-ის მინიმალისტური წამკითხველი — გარე ბიბლიოთეკის გარეშე.
 * უკვე დაყენებულ process.env ცვლადს არ გადააწერს.
 */
export function loadEnv(fileName = '.env') {
  const file = path.join(projectRoot, fileName);
  if (!fs.existsSync(file)) return false;

  for (const rawLine of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) continue;

    const eq = line.indexOf('=');
    if (eq === -1) continue;

    const key = line.slice(0, eq).trim();
    let value = line.slice(eq + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (process.env[key] === undefined) process.env[key] = value;
  }
  return true;
}

/** აბრუნებს webhook-ის URL-ს ან ხსნადი შეცდომით ჩერდება. */
export function requireWebhook(envKey) {
  loadEnv();
  const value = process.env[envKey];
  if (!value) {
    throw new Error(
      `${envKey} არ არის განსაზღვრული. დააკოპირე .env.example -> .env და ჩასვი webhook-ის URL.`,
    );
  }
  // ბიტრიქსი webhook-ის გვერდზე URL-ს მაგალითი მეთოდით აჩვენებს
  // (…/rest/1/token/crm.catalog.fields.json) — მხოლოდ საბაზისო ნაწილს ვტოვებთ.
  return value.trim()
    .replace(/\/+$/, '')
    .replace(/\/[a-z][a-z0-9_.]*\.json$/i, '');
}

/** ლოგისთვის: ტოკენს ვმალავთ, რომ შემთხვევით არ დაიბეჭდოს. */
export function redact(webhookUrl) {
  return webhookUrl.replace(/\/rest\/(\d+)\/[^/]+/, '/rest/$1/***');
}
