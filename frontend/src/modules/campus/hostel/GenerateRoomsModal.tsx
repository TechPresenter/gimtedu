import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Layers } from 'lucide-react';
import { Alert, Combobox, Field, Input, Modal, Button, Select, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { formatNumber } from '@/lib/format';
import type { Option } from '@/lib/types';
import { useHostelRefresh } from './AllocationDialogs';
import { ROOM_AMENITIES, ROOM_TYPES, floorLabel, type HostelStat } from './shared';

const CAPACITY: Record<string, number> = { single: 1, double: 2, triple: 3, quad: 4, dormitory: 8 };

/** Create a whole block / floor of rooms (with beds) in one go. */
export function GenerateRoomsModal({ open, onClose, hostels, defaultHostelId }: { open: boolean; onClose: () => void; hostels: HostelStat[]; defaultHostelId?: number | null }) {
  const toast = useToast();
  const refresh = useHostelRefresh();
  const [v, setV] = useState({ hostel_id: '', block_id: '', floor_from: '1', floor_to: '1', rooms_per_floor: '10', room_type: 'double', capacity: '2', annual_fee: '', is_ac: false, pattern: '{block}-{floor}{nn}' });
  const [amenities, setAmenities] = useState<(string | number)[]>(['study_table', 'wardrobe', 'ceiling_fan']);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open) {
      setV((s) => ({ ...s, hostel_id: defaultHostelId ? String(defaultHostelId) : hostels[0] ? String(hostels[0].id) : '', block_id: '' }));
      setErrors({});
      setFormError(null);
    }
  }, [open, defaultHostelId, hostels]);

  const { data: blocks = [], isLoading: loadingBlocks } = useApi<Option[]>(['hostel', 'block-options', v.hostel_id], 'crud/hostel_rooms/options/block_id', { hostel_id: v.hostel_id }, { enabled: open && !!v.hostel_id });
  // a hostel organised in blocks needs the block picked (the server refuses block-less rooms there): preselect the first one
  useEffect(() => {
    if (open && blocks.length && !blocks.some((b) => String(b.value) === v.block_id)) setV((s) => ({ ...s, block_id: String(blocks[0].value) }));
  }, [open, blocks, v.block_id]);
  const set = (k: keyof typeof v, val: string | boolean) => {
    setV((s) => ({ ...s, [k]: val, ...(k === 'room_type' ? { capacity: String(CAPACITY[String(val)] ?? 2) } : {}), ...(k === 'hostel_id' ? { block_id: '' } : {}) }));
    setErrors((e) => ({ ...e, [k]: '' }));
  };
  const hostel = hostels.find((h) => String(h.id) === v.hostel_id);
  const blockLabel = blocks.find((b) => String(b.value) === v.block_id)?.label ?? '';
  const letter = blockLabel ? blockLabel.replace(/^(block|wing|tower)\s*/i, '').toUpperCase() : hostel?.code ?? '';
  const preview = useMemo(() => {
    const from = Number(v.floor_from) || 0;
    const per = Number(v.rooms_per_floor) || 0;
    const make = (f: number, n: number) => v.pattern.replace('{block}', letter).replace('{floor}', String(f)).replace('{nn}', String(n).padStart(2, '0')).replace('{n}', String(n)).toUpperCase();
    return per ? [make(from, 1), make(from, 2), '…', make(from, per)].filter((x, i, arr) => arr.indexOf(x) === i) : [];
  }, [v, letter]);
  const floors = Math.max(0, (Number(v.floor_to) || 0) - (Number(v.floor_from) || 0) + 1);
  const total = floors * (Number(v.rooms_per_floor) || 0);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setFormError(null);
    try {
      const res = await api.post('hostel/rooms/generate', { ...v, block_id: v.block_id || null, is_ac: v.is_ac ? 1 : 0, amenities });
      toast.success(res.message);
      await refresh();
      onClose();
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      setFormError(ae.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="lg"
      title="Add rooms in bulk"
      description="Create every room of a floor range in one step. Beds are created for each room; existing room numbers are skipped."
      icon={<span className="kpi-icon bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200"><Layers className="h-5 w-5" /></span>}
      footer={<><Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button><Button type="submit" form="hostel-generate-form" loading={saving}>Create {total > 0 ? formatNumber(total) : ''} rooms</Button></>}
    >
      <form id="hostel-generate-form" onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert variant="error">{formError}</Alert>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Hostel" required htmlFor="gen-hostel" error={errors.hostel_id}>
            <Select id="gen-hostel" value={v.hostel_id} onChange={(e) => set('hostel_id', e.target.value)} options={hostels.map((h) => ({ value: h.id, label: h.name }))} placeholder="Select hostel" invalid={!!errors.hostel_id} />
          </Field>
          <Field
            label="Block"
            required={blocks.length > 0}
            htmlFor="gen-block"
            error={errors.block_id}
            hint={loadingBlocks ? 'Loading blocks…' : blocks.length ? undefined : 'No blocks yet — rooms are added to the hostel directly.'}
          >
            <Select
              id="gen-block"
              value={v.block_id}
              onChange={(e) => set('block_id', e.target.value)}
              options={blocks}
              placeholder={loadingBlocks ? 'Loading…' : blocks.length ? undefined : '— No block —'}
              disabled={loadingBlocks}
              invalid={!!errors.block_id}
            />
          </Field>
          <Field label="From floor" required htmlFor="gen-from" error={errors.floor_from} hint={floorLabel(Number(v.floor_from) || 0)}>
            <Input id="gen-from" type="number" min={0} max={30} value={v.floor_from} onChange={(e) => set('floor_from', e.target.value)} invalid={!!errors.floor_from} />
          </Field>
          <Field label="To floor" required htmlFor="gen-to" error={errors.floor_to} hint={floorLabel(Number(v.floor_to) || 0)}>
            <Input id="gen-to" type="number" min={0} max={30} value={v.floor_to} onChange={(e) => set('floor_to', e.target.value)} invalid={!!errors.floor_to} />
          </Field>
          <Field label="Rooms per floor" required htmlFor="gen-per" error={errors.rooms_per_floor}>
            <Input id="gen-per" type="number" min={1} max={60} value={v.rooms_per_floor} onChange={(e) => set('rooms_per_floor', e.target.value)} invalid={!!errors.rooms_per_floor} />
          </Field>
          <Field label="Numbering pattern" required htmlFor="gen-pattern" error={errors.pattern} hint={preview.length ? `e.g. ${preview.join(', ')}` : 'Tokens: {block} {floor} {nn} {n}'}>
            <Input id="gen-pattern" value={v.pattern} maxLength={30} onChange={(e) => set('pattern', e.target.value)} invalid={!!errors.pattern} className="font-mono" />
          </Field>
          <Field label="Room type" required htmlFor="gen-type" error={errors.room_type}>
            <Select id="gen-type" value={v.room_type} onChange={(e) => set('room_type', e.target.value)} options={ROOM_TYPES} invalid={!!errors.room_type} />
          </Field>
          <Field label="Beds per room" required htmlFor="gen-cap" error={errors.capacity}>
            <Input id="gen-cap" type="number" min={1} max={12} value={v.capacity} onChange={(e) => set('capacity', e.target.value)} invalid={!!errors.capacity} />
          </Field>
          <Field label="Rent per year" htmlFor="gen-rent" error={errors.annual_fee} hint="Blank = hostel default rent">
            <Input id="gen-rent" type="number" min={0} step={500} prefix="₹" value={v.annual_fee} onChange={(e) => set('annual_fee', e.target.value)} invalid={!!errors.annual_fee} />
          </Field>
          <div className="flex items-end pb-2">
            <Toggle checked={v.is_ac} onChange={(x) => set('is_ac', x)} label="Air-conditioned rooms" />
          </div>
        </div>
        <Field label="Room amenities" htmlFor="gen-amenities">
          <Combobox id="gen-amenities" multiple value={amenities} onChange={setAmenities} options={Object.entries(ROOM_AMENITIES).map(([value, label]) => ({ value, label }))} placeholder="Select amenities…" />
        </Field>
        {total > 0 && (
          <p className="rounded-xl bg-brand-50 px-3 py-2 text-sm text-brand-900 dark:bg-brand-500/10 dark:text-brand-100">
            {formatNumber(total)} rooms ({floors} floor{floors === 1 ? '' : 's'} × {v.rooms_per_floor}) with {formatNumber(total * (Number(v.capacity) || 0))} beds will be created{hostel ? ` in ${hostel.name}` : ''}{blockLabel ? ` · ${blockLabel}` : ''}.
          </p>
        )}
      </form>
    </Modal>
  );
}
