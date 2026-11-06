import clsx from 'clsx';
import { CountrySelect } from './CountrySelect';
import { INDIA_STATES, postalLabel, postalNumeric } from './address';
import { countryName } from './phone';

const isoCache = new Map<string, string>();

/** ISO2 code for a country name / code ("India" -> "IN"); falls back to India. */
export function countryIso(value: string | undefined | null, fallback = 'IN'): string {
  const v = (value || '').trim();
  const hit = isoCache.get(v.toLowerCase());
  if (hit) return hit;
  if (/^[A-Za-z]{2}$/.test(v)) return v.toUpperCase();
  if (!v) return fallback;
  const key = v.toLowerCase();
  const alias: Record<string, string> = { india: 'IN', bharat: 'IN', usa: 'US', 'united states of america': 'US', uk: 'GB', 'great britain': 'GB', uae: 'AE', 'outside india': '' };
  if (key in alias) return alias[key] || fallback;
  // reverse lookup through the display names of every region code
  for (let a = 65; a <= 90; a++) {
    for (let b = 65; b <= 90; b++) {
      const iso = String.fromCharCode(a, b);
      if (countryName(iso).toLowerCase() === key) {
        isoCache.set(key, iso);
        return iso;
      }
    }
  }
  return fallback;
}

interface CountryFieldProps {
  id: string;
  /** submitted names: `name` = country name (as stored), `${name}_code` = ISO2 */
  name?: string;
  iso: string;
  onChange: (iso: string) => void;
  label?: string;
  invalid?: boolean;
  disabled?: boolean;
  describedBy?: string;
}

/** Searchable country select for addresses (flag + name); submits the country name plus `<name>_code` (ISO2). */
export function CountryField({ id, name, iso, onChange, label = 'Country', invalid, disabled, describedBy }: CountryFieldProps) {
  return (
    <>
      <CountrySelect id={id} mode="country" value={iso} onChange={onChange} label={label} invalid={invalid} disabled={disabled} describedBy={describedBy} />
      {name && (
        <>
          <input type="hidden" name={name} value={countryName(iso)} />
          <input type="hidden" name={`${name}_code`} value={iso} />
        </>
      )}
    </>
  );
}

interface StateFieldProps {
  id: string;
  name?: string;
  country: string;
  value: string;
  onChange: (v: string) => void;
  onBlur?: () => void;
  invalid?: boolean;
  disabled?: boolean;
  required?: boolean;
  describedBy?: string;
  className?: string;
  placeholder?: string;
}

/** State / union territory: a select of India's 36 states & UTs for India, free text (state / province / region) elsewhere. */
export function StateField({ id, name, country, value, onChange, onBlur, invalid, disabled, required, describedBy, className, placeholder }: StateFieldProps) {
  const common = {
    id,
    name,
    disabled,
    required,
    'aria-required': required || undefined,
    'aria-invalid': invalid || undefined,
    'aria-describedby': describedBy,
    onBlur,
    className: clsx('form-input', invalid && 'is-invalid', className),
  };
  if (country === 'IN') {
    const known = !value || INDIA_STATES.includes(value);
    return (
      <select {...common} value={known ? value : ''} autoComplete="address-level1" onChange={(e) => onChange(e.target.value)}>
        <option value="">{placeholder ?? 'Select state / UT'}</option>
        <optgroup label="States">
          {INDIA_STATES.slice(0, 28).map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </optgroup>
        <optgroup label="Union territories">
          {INDIA_STATES.slice(28).map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </optgroup>
      </select>
    );
  }
  return <input {...common} type="text" value={value} maxLength={80} autoComplete="address-level1" placeholder={placeholder ?? 'State / province / region'} onChange={(e) => onChange(e.target.value)} />;
}

interface PostalFieldProps {
  id: string;
  name?: string;
  country: string;
  value: string;
  onChange: (v: string) => void;
  onBlur?: () => void;
  invalid?: boolean;
  disabled?: boolean;
  required?: boolean;
  describedBy?: string;
  className?: string;
  placeholder?: string;
}

/** Postal code with the country's keypad and length (India: 6-digit PIN code, numeric keypad). */
export function PostalField({ id, name, country, value, onChange, onBlur, invalid, disabled, required, describedBy, className, placeholder }: PostalFieldProps) {
  const numeric = postalNumeric(country);
  return (
    <input
      id={id}
      name={name}
      type="text"
      value={value}
      disabled={disabled}
      required={required}
      aria-required={required || undefined}
      aria-invalid={invalid || undefined}
      aria-describedby={describedBy}
      inputMode={numeric ? 'numeric' : 'text'}
      autoComplete="postal-code"
      maxLength={country === 'IN' ? 6 : 12}
      placeholder={placeholder ?? (country === 'IN' ? '6-digit PIN code' : postalLabel(country))}
      onChange={(e) => onChange(country === 'IN' ? e.target.value.replace(/\D/g, '').slice(0, 6) : e.target.value.toUpperCase())}
      onBlur={onBlur}
      className={clsx('form-input', invalid && 'is-invalid', className)}
    />
  );
}
