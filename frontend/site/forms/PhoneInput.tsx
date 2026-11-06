import clsx from 'clsx';
import { forwardRef, useEffect, useImperativeHandle, useRef, useState, type ChangeEvent } from 'react';
import { CountrySelect } from './CountrySelect';
import { DEFAULT_COUNTRY, examplePlaceholder, formatAsYouType, fromValue, loadPhoneLib, onPhoneLibLoaded, phoneLib, toE164 } from './phone';

export interface PhoneInputProps {
  /** id of the number input (for the <label>) */
  id: string;
  /** form field name: renders hidden inputs `name` (E.164) and `${name}_country` (ISO2) for FormData */
  name?: string;
  /** controlled value (E.164 or as typed); omit for uncontrolled use with defaultValue */
  value?: string;
  defaultValue?: string;
  /** preselected country (ISO2), default India */
  defaultCountry?: string;
  /** called with the value to submit (E.164 when it parses, '' when empty) */
  onChange?: (value: string, info: { country: string; valid: boolean }) => void;
  onBlur?: () => void;
  label?: string;
  required?: boolean;
  disabled?: boolean;
  invalid?: boolean;
  describedBy?: string;
  placeholder?: string;
  autoComplete?: string;
  /** classes for the outer field box (defaults to the site's .form-input look) */
  className?: string;
  /** classes for the number input (padding / font size per form theme) */
  inputClassName?: string;
}

export interface PhoneInputHandle {
  focus: () => void;
  /** current value to submit */
  value: () => string;
  country: () => string;
}

/**
 * International phone field: searchable country-code selector (flag, name, dialling code; India first) + number
 * input with as-you-type formatting for the selected country. Pasting or typing a "+<code>" number switches the
 * country automatically. Emits E.164 ("+919876543210"); libphonenumber-js is loaded on first focus / hover.
 */
export const PhoneInput = forwardRef<PhoneInputHandle, PhoneInputProps>(function PhoneInput(
  { id, name, value, defaultValue, defaultCountry = DEFAULT_COUNTRY, onChange, onBlur, label = 'Phone number', required, disabled, invalid, describedBy, placeholder, autoComplete = 'tel', className, inputClassName },
  ref,
) {
  const initial = fromValue(value ?? defaultValue ?? '', defaultCountry);
  const [country, setCountry] = useState(initial.country);
  const [text, setText] = useState(initial.text);
  const inputRef = useRef<HTMLInputElement>(null);
  const boxRef = useRef<HTMLDivElement>(null);
  const [narrow, setNarrow] = useState(false);
  // narrow fields (half-width columns in sidebars): the selector shows the flag only
  useEffect(() => {
    const el = boxRef.current;
    if (!el || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(([entry]) => setNarrow(entry.contentRect.width < 230));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  const lastEmitted = useRef<string>(value ?? defaultValue ?? '');
  const current = toE164(text, country);

  useImperativeHandle(ref, () => ({ focus: () => inputRef.current?.focus(), value: () => toE164(text, country).value, country: () => country }), [text, country]);

  // a stored E.164 value shown before the library loaded is re-read once it has (country + national formatting)
  const needsReparse = useRef(!phoneLib() && (value ?? defaultValue ?? '').trim().startsWith('+'));
  const countryRef = useRef(country);
  countryRef.current = country;
  useEffect(
    () =>
      onPhoneLibLoaded(() => {
        if (!needsReparse.current) return;
        needsReparse.current = false;
        const parsed = fromValue(lastEmitted.current, countryRef.current);
        setCountry(parsed.country);
        setText(parsed.text);
      }),
    [],
  );

  // controlled value changed from outside (reset / draft restore)
  useEffect(() => {
    if (value === undefined || value === lastEmitted.current) return;
    lastEmitted.current = value;
    needsReparse.current = !phoneLib() && value.trim().startsWith('+');
    const parsed = fromValue(value, value ? countryRef.current : defaultCountry);
    setCountry(parsed.country);
    setText(parsed.text);
  }, [value, defaultCountry]);

  const emit = (t: string, c: string) => {
    const r = toE164(t, c);
    lastEmitted.current = r.value;
    onChange?.(r.value, { country: r.country, valid: r.valid });
  };

  const onInput = (e: ChangeEvent<HTMLInputElement>) => {
    needsReparse.current = false;
    const el = e.target;
    let t = el.value.replace(/[^\d\s()+\-.]/g, '').slice(0, 24);
    const atEnd = el.selectionStart === el.value.length;
    let c = country;
    if (t.trim().startsWith('+') && phoneLib()) {
      // international number typed / pasted: follow its country
      const r = toE164(t, c);
      if (r.country && r.country !== c && r.valid) {
        c = r.country;
        setCountry(c);
        t = fromValue(r.value, c).text;
      }
    } else if (atEnd) {
      t = formatAsYouType(t, c);
    }
    setText(t);
    emit(t, c);
  };

  const pickCountry = (iso: string) => {
    needsReparse.current = false;
    setCountry(iso);
    // a number typed with a "+code" keeps its digits; a national number is re-read for the new country
    const t = text.trim().startsWith('+') ? text.replace(/^\s*\+\d{1,3}\s*/, '') : text;
    const formatted = formatAsYouType(t.replace(/[^\d]/g, ''), iso) || t;
    setText(formatted);
    emit(formatted, iso);
  };

  const preload = () => void loadPhoneLib().catch(() => undefined);

  return (
    <div
      ref={boxRef}
      className={clsx(
        'form-input phone-field flex items-stretch !p-0 focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-500/10',
        invalid && 'is-invalid',
        disabled && 'opacity-70',
        className,
      )}
      onPointerEnter={preload}
    >
      <CountrySelect value={country} onChange={pickCountry} label={`Country calling code for ${label}`} disabled={disabled} compact={narrow} afterSelect={() => inputRef.current?.focus()} />
      <input
        ref={inputRef}
        id={id}
        type="tel"
        inputMode="tel"
        autoComplete={autoComplete}
        value={text}
        disabled={disabled}
        required={required}
        aria-required={required || undefined}
        aria-invalid={invalid || undefined}
        aria-describedby={describedBy}
        placeholder={placeholder ?? examplePlaceholder(country)}
        maxLength={24}
        onChange={onInput}
        onFocus={preload}
        onBlur={() => {
          // tidy the display once the number is complete (e.g. pasted "+91-98765-43210")
          if (phoneLib() && current.valid && current.value) {
            const tidy = fromValue(current.value, country);
            if (tidy.country === country && tidy.text !== text) setText(tidy.text);
          }
          onBlur?.();
        }}
        // a caller's own vertical padding (compact forms: py-2.5) replaces the default py-3 — both classes together would
        // let py-3 win and make the phone row taller than the other fields
        className={clsx('min-w-0 flex-1 rounded-r-[inherit] border-0 bg-transparent text-sm text-inherit placeholder:text-slate-400 focus:outline-none focus:ring-0', !/(^|\s)!?py-/.test(inputClassName ?? '') && 'py-3', narrow ? 'px-2' : 'px-3.5', inputClassName)}
      />
      {name && (
        <>
          <input type="hidden" name={name} value={current.value} />
          <input type="hidden" name={`${name}_country`} value={country} />
        </>
      )}
    </div>
  );
});
