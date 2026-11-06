/** WhatsApp shared building blocks — import from '@/modules/whatsapp/shared' (or '../shared'). Owned by the core part. */
export * from './types';
export * from './api';
export { StatusTicks } from './StatusTicks';
export { PhoneText, formatWaPhone, waMeHref } from './PhoneText';
export { WindowBadge, waWindow, parseServerDate, formatDuration, type WaWindowState } from './WindowBadge';
export { ModeBanner } from './ModeBanner';
export { VariableChips } from './VariableChips';
export { WaBubble, WaText, waPreviewText, type WaBubbleProps } from './WaBubble';
export { WaPhoneFrame } from './WaPhoneFrame';
