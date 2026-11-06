/**
 * Address rules shared by the public forms — mirror address_*() in app/services/phone.php:
 * India's states & union territories, postal-code formats per country (India: 6-digit PIN code), field labels.
 */

export const INDIA_STATES = [
  'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand',
  'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan',
  'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
  // union territories
  'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
];

/** Postal code formats (case-insensitive) — countries not listed have no mandatory postal code. */
const POSTAL: Record<string, string> = {
  IN: '^[1-9]\\d{5}$', US: '^\\d{5}(?:-\\d{4})?$', CA: '^[ABCEGHJ-NPRSTVXY]\\d[ABCEGHJ-NPRSTV-Z] ?\\d[ABCEGHJ-NPRSTV-Z]\\d$',
  GB: '^(?:GIR ?0AA|[A-PR-UWYZ](?:\\d{1,2}|[A-HK-Y]\\d(?:\\d|[ABEHMNPRV-Y])?|\\d[A-HJKPS-UW]) ?\\d[ABD-HJLNP-UW-Z]{2})$',
  AU: '^\\d{4}$', NZ: '^\\d{4}$', SG: '^\\d{6}$', MY: '^\\d{5}$', NP: '^\\d{5}$', BD: '^\\d{4}$', LK: '^\\d{5}$', BT: '^\\d{5}$',
  PK: '^\\d{5}$', AF: '^\\d{4}$', MV: '^\\d{5}$', SA: '^\\d{5}(?:-\\d{4})?$', KW: '^\\d{5}$', OM: '^\\d{3}$', BH: '^\\d{3,4}$',
  JP: '^\\d{3}-?\\d{4}$', CN: '^\\d{6}$', KR: '^\\d{5}$', TW: '^\\d{3}(?:\\d{2,3})?$', TH: '^\\d{5}$', ID: '^\\d{5}$', PH: '^\\d{4}$',
  VN: '^\\d{6}$', DE: '^\\d{5}$', FR: '^\\d{5}$', IT: '^\\d{5}$', ES: '^\\d{5}$', NL: '^\\d{4} ?[A-Z]{2}$', BE: '^\\d{4}$',
  CH: '^\\d{4}$', AT: '^\\d{4}$', SE: '^\\d{3} ?\\d{2}$', NO: '^\\d{4}$', DK: '^\\d{4}$', FI: '^\\d{5}$', PL: '^\\d{2}-\\d{3}$',
  PT: '^\\d{4}-\\d{3}$', IE: '^(?:[AC-FHKNPRTV-Y]\\d{2}|D6W) ?[0-9AC-FHKNPRTV-Y]{4}$', RU: '^\\d{6}$', UA: '^\\d{5}$', TR: '^\\d{5}$',
  ZA: '^\\d{4}$', NG: '^\\d{6}$', KE: '^\\d{5}$', EG: '^\\d{5}$', MU: '^\\d{5}$', BR: '^\\d{5}-?\\d{3}$', MX: '^\\d{5}$',
};
const DIGITS_ONLY = ['IN', 'SG', 'CN', 'RU', 'VN', 'NG', 'AU', 'NZ', 'DE', 'FR', 'IT', 'ES', 'MX', 'NP', 'LK', 'BT', 'PK'];

/** "PIN code" (India), "ZIP code" (US), "Postcode" (UK, Australia, NZ), "Eircode" (Ireland), else "Postal code". */
export function postalLabel(country: string): string {
  return ({ IN: 'PIN code', US: 'ZIP code', GB: 'Postcode', AU: 'Postcode', NZ: 'Postcode', IE: 'Eircode' } as Record<string, string>)[country] ?? 'Postal code';
}

/** Does the country use (and require) postal codes on addresses? */
export const postalRequired = (country: string): boolean => country in POSTAL;

/** Numeric keypad for digit-only postal codes. */
export const postalNumeric = (country: string): boolean => ['IN', 'US', 'SG', 'CN', 'RU', 'VN', 'NG', 'AU', 'NZ', 'DE', 'FR', 'IT', 'ES', 'MX', 'NP', 'LK', 'BT', 'PK', 'MY', 'SA', 'KW', 'TH', 'ID'].includes(country);

export function normalizePostal(code: string, country: string): string {
  let c = (code || '').replace(/\s+/g, ' ').trim().toUpperCase();
  if (DIGITS_ONLY.includes(country)) c = c.replace(/ /g, '');
  return c;
}

/** Validation message for a postal code (null when valid / empty and optional) — same as address_postal_error(). */
export function postalError(code: string, country: string, opts: { required?: boolean; label?: string; countryName?: string } = {}): string | null {
  const label = opts.label ?? postalLabel(country);
  const c = normalizePostal(code, country);
  if (!c) return opts.required && postalRequired(country) ? `${label} is required.` : null;
  const pattern = POSTAL[country];
  // label inside a sentence: "Postal code" -> "postal code", acronyms kept ("ZIP code") — as address_label_lower()
  const inline = /^[A-Z]{2}/.test(label) ? label : `${label.charAt(0).toLowerCase()}${label.slice(1)}`;
  if (!pattern) return /^[A-Z0-9][A-Z0-9 -]{1,10}$/.test(c) ? null : `Enter a valid ${inline}.`;
  if (new RegExp(pattern, 'i').test(c)) return null;
  return country === 'IN' ? 'Enter a valid 6-digit PIN code.' : `Enter a valid ${inline} for ${opts.countryName ?? country}.`;
}

/** Canonical Indian state / UT for loose input ("up", "orissa", "NCT of Delhi"), null when unknown. */
export function normalizeIndianState(value: string): string | null {
  const key = (value || '').replace(/&/g, 'and').replace(/[^a-z]/gi, '').toLowerCase();
  if (!key) return null;
  const aliases: Record<string, string> = {
    up: 'Uttar Pradesh', mp: 'Madhya Pradesh', ap: 'Andhra Pradesh', hp: 'Himachal Pradesh', uk: 'Uttarakhand', uttaranchal: 'Uttarakhand', wb: 'West Bengal',
    tn: 'Tamil Nadu', jk: 'Jammu and Kashmir', orissa: 'Odisha', pondicherry: 'Puducherry', nctofdelhi: 'Delhi', newdelhi: 'Delhi', chattisgarh: 'Chhattisgarh',
  };
  if (aliases[key]) return aliases[key];
  return INDIA_STATES.find((s) => s.replace(/[^a-z]/gi, '').toLowerCase() === key) ?? null;
}
