import { useEffect, useState } from 'react';
import { Contact, Crosshair, Info, MapPin, Send } from 'lucide-react';
import { Alert, Button, Field, Input, Modal } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { WaBubble } from '../shared';
import type { SendPayload } from './types';

interface ShareProps {
  open: boolean;
  onClose: () => void;
  onSend: (payload: SendPayload, preview: { type: 'text'; body: string }) => Promise<unknown>;
}

/** Extract "lat,lng" from coordinates or a Google Maps link (…/@28.47,77.50,15z or ?q=28.47,77.50). */
function parseCoords(s: string): { lat: string; lng: string } | null {
  const m = s.match(/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/);
  return m ? { lat: m[1], lng: m[2] } : null;
}

const NOTE = 'Interakt cannot send native WhatsApp location pins or contact cards, so this goes out as a clearly labelled text message.';

export function LocationModal({ open, onClose, onSend }: ShareProps) {
  const [label, setLabel] = useState('');
  const [address, setAddress] = useState('');
  const [lat, setLat] = useState('');
  const [lng, setLng] = useState('');
  const [paste, setPaste] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [locating, setLocating] = useState(false);

  useEffect(() => {
    if (open) {
      setLabel('');
      setAddress('');
      setLat('');
      setLng('');
      setPaste('');
      setErrors({});
      setFormError(null);
    }
  }, [open]);

  const latN = Number(lat);
  const lngN = Number(lng);
  const valid = lat !== '' && lng !== '' && Number.isFinite(latN) && Number.isFinite(lngN) && Math.abs(latN) <= 90 && Math.abs(lngN) <= 180;
  const mapsUrl = valid ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${latN.toFixed(6)},${lngN.toFixed(6)}`)}` : '';
  const preview = `📍 *Location${label.trim() ? `: ${label.trim()}` : ''}*\n${address.trim() ? `${address.trim()}\n` : ''}${mapsUrl || 'https://www.google.com/maps/…'}`;

  const useMine = () => {
    if (!navigator.geolocation) {
      setFormError('This browser cannot share your location.');
      return;
    }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setLat(pos.coords.latitude.toFixed(6));
        setLng(pos.coords.longitude.toFixed(6));
        setLocating(false);
      },
      () => {
        setFormError('Location permission was denied. Paste a Google Maps link instead.');
        setLocating(false);
      },
      { timeout: 10000 },
    );
  };

  const submit = async () => {
    const e: Record<string, string> = {};
    if (lat === '' || !Number.isFinite(latN) || Math.abs(latN) > 90) e.latitude = 'Enter a latitude between -90 and 90.';
    if (lng === '' || !Number.isFinite(lngN) || Math.abs(lngN) > 180) e.longitude = 'Enter a longitude between -180 and 180.';
    setErrors(e);
    if (Object.keys(e).length) return;
    setSending(true);
    setFormError(null);
    try {
      await onSend({ type: 'location', latitude: latN, longitude: lngN, label: label.trim(), address: address.trim() }, { type: 'text', body: preview });
      onClose();
    } catch (err) {
      setFormError((err as ApiError).message);
      setErrors((err as ApiError).errors ?? {});
    } finally {
      setSending(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Share a location"
      description="Sends a Google Maps link the contact can open."
      icon={<span className="flex h-9 w-9 items-center justify-center rounded-xl bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300"><MapPin className="h-5 w-5" aria-hidden /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={sending}>Cancel</Button>
          <Button icon={Send} onClick={submit} loading={sending}>Send location</Button>
        </>
      }
    >
      <div className="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,260px)]">
        <div className="space-y-3">
          <p className="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300"><Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />{NOTE}</p>
          {formError && <Alert variant="error">{formError}</Alert>}
          <Field label="Paste a Google Maps link or coordinates" htmlFor="loc-paste" hint="We read the latitude and longitude from it.">
            <Input
              id="loc-paste"
              inputSize="sm"
              value={paste}
              placeholder="https://maps.google.com/?q=28.6139,77.2090"
              onChange={(e) => {
                setPaste(e.target.value);
                const c = parseCoords(e.target.value);
                if (c) {
                  setLat(c.lat);
                  setLng(c.lng);
                }
              }}
            />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Latitude" htmlFor="loc-lat" required error={errors.latitude}>
              <Input id="loc-lat" inputSize="sm" inputMode="decimal" value={lat} invalid={!!errors.latitude} onChange={(e) => setLat(e.target.value.trim())} />
            </Field>
            <Field label="Longitude" htmlFor="loc-lng" required error={errors.longitude}>
              <Input id="loc-lng" inputSize="sm" inputMode="decimal" value={lng} invalid={!!errors.longitude} onChange={(e) => setLng(e.target.value.trim())} />
            </Field>
          </div>
          <Button size="xs" variant="ghost" icon={Crosshair} loading={locating} onClick={useMine}>Use my current location</Button>
          <Field label="Place name" htmlFor="loc-label" error={errors.label}>
            <Input id="loc-label" inputSize="sm" value={label} maxLength={120} onChange={(e) => setLabel(e.target.value)} placeholder="Admission Cell, Block A" />
          </Field>
          <Field label="Address" htmlFor="loc-addr">
            <Input id="loc-addr" inputSize="sm" value={address} maxLength={200} onChange={(e) => setAddress(e.target.value)} placeholder="Street, city" />
          </Field>
        </div>
        <div className="rounded-xl bg-[#EFEAE2] p-3 dark:bg-[#0B141A]">
          <p className="mb-2 text-center text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Preview</p>
          <WaBubble direction="out" type="text" body={preview} status="queued" showStatus={false} />
        </div>
      </div>
    </Modal>
  );
}

export function ContactCardModal({ open, onClose, onSend }: ShareProps) {
  const { session } = useAuth();
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [org, setOrg] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  useEffect(() => {
    if (open) {
      setName('');
      setPhone('');
      setEmail('');
      setOrg('');
      setErrors({});
      setFormError(null);
    }
  }, [open]);

  const me = session?.user;
  const fillMine = () => {
    if (!me) return;
    setName(me.name);
    setPhone(me.phone ?? '');
    setEmail(me.email);
    setOrg(me.designation ? `${me.designation}, GIMT` : 'GIMT');
  };

  const preview = ['👤 *Contact card*', name.trim() || 'Name', org.trim(), `📞 ${phone.trim() || 'Phone'}`, email.trim() ? `✉️ ${email.trim()}` : ''].filter(Boolean).join('\n');

  const submit = async () => {
    const e: Record<string, string> = {};
    if (!name.trim()) e.contact_name = 'Enter the name to share.';
    if (phone.replace(/\D+/g, '').length < 8) e.contact_phone = 'Enter a valid phone number.';
    if (email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) e.contact_email = 'Enter a valid email address.';
    setErrors(e);
    if (Object.keys(e).length) return;
    setSending(true);
    setFormError(null);
    try {
      await onSend({ type: 'contact', contact_name: name.trim(), contact_phone: phone.trim(), contact_email: email.trim(), contact_org: org.trim() }, { type: 'text', body: preview });
      onClose();
    } catch (err) {
      setFormError((err as ApiError).message);
      setErrors((err as ApiError).errors ?? {});
    } finally {
      setSending(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Share a contact"
      description="Share a counsellor's or an office's contact details."
      icon={<span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200"><Contact className="h-5 w-5" aria-hidden /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={sending}>Cancel</Button>
          <Button icon={Send} onClick={submit} loading={sending}>Send contact</Button>
        </>
      }
    >
      <div className="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,260px)]">
        <div className="space-y-3">
          <p className="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300"><Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />{NOTE}</p>
          {formError && <Alert variant="error">{formError}</Alert>}
          {me && <Button size="xs" variant="ghost" onClick={fillMine}>Use my details</Button>}
          <Field label="Name" htmlFor="cc-name" required error={errors.contact_name}>
            <Input id="cc-name" inputSize="sm" value={name} maxLength={120} invalid={!!errors.contact_name} onChange={(e) => setName(e.target.value)} />
          </Field>
          <Field label="Phone" htmlFor="cc-phone" required error={errors.contact_phone}>
            <Input id="cc-phone" inputSize="sm" type="tel" value={phone} maxLength={30} invalid={!!errors.contact_phone} onChange={(e) => setPhone(e.target.value)} placeholder="+91 98765 43210" />
          </Field>
          <Field label="Email" htmlFor="cc-email" error={errors.contact_email}>
            <Input id="cc-email" inputSize="sm" type="email" value={email} maxLength={190} invalid={!!errors.contact_email} onChange={(e) => setEmail(e.target.value)} />
          </Field>
          <Field label="Designation / office" htmlFor="cc-org">
            <Input id="cc-org" inputSize="sm" value={org} maxLength={120} onChange={(e) => setOrg(e.target.value)} placeholder="Admission Counsellor, GIMT" />
          </Field>
        </div>
        <div className="rounded-xl bg-[#EFEAE2] p-3 dark:bg-[#0B141A]">
          <p className="mb-2 text-center text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Preview</p>
          <WaBubble direction="out" type="text" body={preview} status="queued" showStatus={false} />
        </div>
      </div>
    </Modal>
  );
}
