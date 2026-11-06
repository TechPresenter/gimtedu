#!/usr/bin/env node
/**
 * Generate app/data/phone_meta.json (server-side phone validation data) from libphonenumber-js "max" metadata.
 *
 *   node tools/gen-phone-meta.cjs            (run from the project root after `npm install` in frontend/)
 *
 * The browser validates phone numbers with libphonenumber-js itself (frontend/site/forms/phone.ts and
 * frontend/src/components/ui/PhoneInput.tsx, lazy-loaded); app/services/phone.php mirrors its isValid() check with the
 * same metadata so client and server agree. Re-run this script whenever the libphonenumber-js version changes.
 *
 * Output (compact keys):
 *   source, version, generated
 *   codes:   { "<calling code>": ["IN", ...] }            main region first (libphonenumber order)
 *   regions: { "<ISO2>": {
 *       n:   English country name (Intl.DisplayNames, same CLDR data browsers use)
 *       c:   calling code ("91")
 *       i:   international (IDD) prefix pattern ("00")
 *       p:   general national-number pattern
 *       l:   possible national-number lengths
 *       np:  national (trunk) prefix ("0")                     optional
 *       npp: national prefix for parsing (regex)              optional
 *       npt: national prefix transform rule ("9$1")           optional
 *       ld:  leading digits pattern (shared calling codes)    optional
 *       t:   number types { f: fixed line, m: mobile, tf: toll free, pr: premium, pn: personal, vm: voicemail,
 *            u: UAN, pg: pager, v: VoIP, sc: shared cost } -> [pattern, lengths?]; m = "" means "same as fixed line"
 *   } }
 *   nongeo:  { "<calling code>": { same keys without n } }  (international freephone, satellite …)
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const libDir = path.join(root, 'frontend', 'node_modules', 'libphonenumber-js');
const meta = require(path.join(libDir, 'metadata.max.json'));
const pkg = require(path.join(libDir, 'package.json'));

if (meta.version !== 4) {
  console.error(`Unsupported metadata version ${meta.version} (expected 4). Update tools/gen-phone-meta.cjs.`);
  process.exit(1);
}

const names = new Intl.DisplayNames(['en'], { type: 'region', fallback: 'code' });
const TYPE_KEYS = ['f', 'm', 'tf', 'pr', 'pn', 'vm', 'u', 'pg', 'v', 'sc'];
const val = (x) => (x === 0 || x === undefined || x === null ? undefined : x);

function checkPattern(p, where) {
  if (typeof p !== 'string') return;
  if (p.includes('/')) throw new Error(`Pattern for ${where} contains "/" — PHP delimiter would break.`);
  try {
    new RegExp(`^(?:${p})$`);
  } catch (e) {
    throw new Error(`Invalid pattern for ${where}: ${e.message}`);
  }
}

function convert(arr, where) {
  const out = { c: arr[0] };
  if (val(arr[1])) out.i = arr[1];
  out.p = arr[2];
  checkPattern(out.p, where);
  out.l = arr[3] || [];
  if (val(arr[5])) out.np = String(arr[5]);
  if (val(arr[7])) {
    out.npp = arr[7];
    checkPattern(out.npp, `${where} npp`);
  }
  if (val(arr[8])) out.npt = arr[8];
  if (val(arr[10])) {
    out.ld = arr[10];
    checkPattern(out.ld, `${where} ld`);
  }
  const types = Array.isArray(arr[11]) ? arr[11] : [];
  const t = {};
  types.forEach((def, i) => {
    if (!Array.isArray(def)) return;
    const pattern = def[0] === 0 || def[0] === undefined ? '' : def[0];
    checkPattern(pattern, `${where} type ${TYPE_KEYS[i]}`);
    t[TYPE_KEYS[i]] = Array.isArray(def[1]) ? [pattern, def[1]] : [pattern];
  });
  out.t = t;
  return out;
}

const regions = {};
for (const [iso, arr] of Object.entries(meta.countries)) {
  regions[iso] = { n: names.of(iso) || iso, ...convert(arr, iso) };
}
const nongeo = {};
for (const [code, arr] of Object.entries(meta.nonGeographic || {})) {
  nongeo[code] = convert(arr, `+${code}`);
}

const out = {
  source: `libphonenumber-js ${pkg.version} (metadata.max.json)`,
  version: pkg.version,
  generated: new Date().toISOString().slice(0, 10),
  codes: meta.country_calling_codes,
  regions,
  nongeo,
};

const file = path.join(root, 'app', 'data', 'phone_meta.json');
fs.mkdirSync(path.dirname(file), { recursive: true });
fs.writeFileSync(file, JSON.stringify(out));
console.log(`Wrote ${path.relative(root, file)} — ${Object.keys(regions).length} regions, ${Object.keys(meta.country_calling_codes).length} calling codes, ${(fs.statSync(file).size / 1024).toFixed(1)} KB (libphonenumber-js ${pkg.version}).`);
