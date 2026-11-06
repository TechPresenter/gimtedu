import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, Copy, KeyRound } from 'lucide-react';
import { Alert, Button, Tabs, useToast } from '@/components/ui';
import { asRecord, copyText } from './api';
import type { WaEventDetail, WaEventOptions } from './types';

/** PHP array literal of a JSON value (pretty, 4-space indent). */
function phpValue(v: unknown, indent = 1): string {
  const pad = '    '.repeat(indent);
  const end = '    '.repeat(indent - 1);
  if (v === null || v === undefined) return 'null';
  if (typeof v === 'boolean') return v ? 'true' : 'false';
  if (typeof v === 'number') return String(v);
  if (typeof v === 'string') return `'${v.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
  if (Array.isArray(v)) return v.length ? `[${v.map((x) => phpValue(x, indent + 1)).join(', ')}]` : '[]';
  const entries = Object.entries(v as Record<string, unknown>);
  if (!entries.length) return '[]';
  return `[\n${entries.map(([k, x]) => `${pad}'${k.replace(/'/g, "\\'")}' => ${phpValue(x, indent + 1)},`).join('\n')}\n${end}]`;
}

function CodeBlock({ code, label }: { code: string; label: string }) {
  const toast = useToast();
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    if (await copyText(code)) {
      setCopied(true);
      toast.success(`${label} copied to the clipboard.`);
      window.setTimeout(() => setCopied(false), 1500);
    } else {
      toast.error('Copy failed — select the code and copy it manually.');
    }
  };
  return (
    <div className="relative">
      <Button size="xs" variant="secondary" icon={copied ? Check : Copy} className="absolute right-2 top-2 z-10" onClick={copy}>
        {copied ? 'Copied' : 'Copy'}
      </Button>
      <pre className="max-h-[26rem] overflow-auto rounded-xl bg-slate-950 p-4 pr-24 text-[12px] leading-relaxed text-slate-100 dark:ring-1 dark:ring-slate-700" tabIndex={0} aria-label={label}>
        <code>{code}</code>
      </pre>
    </div>
  );
}

/** Keys the public website endpoint accepts in `data` (mirrors the filter of POST whatsapp/ingest/track). */
function trackDataKey(k: string): boolean {
  if (!/^[a-z][a-z0-9_]{0,39}$/.test(k)) return false;
  if (k.endsWith('_id') && k !== 'program_id') return false;
  if (/phone|mobile|whatsapp|email|contact|token|secret|fanout/.test(k)) return false;
  return !['name', 'page', 'consent', 'source'].includes(k);
}

/** Copy-ready code to fire the event from GIMT PHP, external servers (cURL / PHP / Node) or the website (JS). */
export function IntegrationCode({ event, options }: { event: WaEventDetail; options: WaEventOptions }) {
  const sample = asRecord(event.sample_payload);
  const { phone, name, email, ...rest } = sample as Record<string, unknown>;
  // internal markers are never accepted from outside
  const data = Object.fromEntries(Object.entries(rest).filter(([k]) => !k.startsWith('_') && k !== 'contact_id'));
  const body = { event: event.code, phone: phone ?? '+919876543210', name: name ?? 'Student Name', ...(email ? { email } : {}), data, dedupe_key: 'your-unique-id-123' };
  const json = JSON.stringify(body, null, 2);
  const webData = Object.fromEntries(Object.entries(rest).filter(([k, v]) => trackDataKey(k) && (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean')));
  // the website calls its own origin: a relative path survives www / non-www and http → https redirects
  let trackPath = options.track_url;
  try {
    trackPath = new URL(options.track_url).pathname;
  } catch {
    /* keep the absolute URL */
  }
  const tabs = [
    { key: 'php', label: 'GIMT (PHP)' },
    { key: 'curl', label: 'cURL' },
    { key: 'phpext', label: 'PHP (external)' },
    { key: 'node', label: 'JavaScript (server)' },
    ...(event.source === 'js' ? [{ key: 'web', label: 'Website JS' }] : []),
  ];
  const [tab, setTab] = useState('php');

  const codes: Record<string, string> = {
    php: `<?php
// Inside GIMT (any service / route / module), after the record is saved. Never breaks the request: the call is
// wrapped, de-duplicated by dedupe_key (use the record id so a retry or a double click does not send twice).
$recordId = 123; // the id of the record that triggered the event
try {
    require_once APP_ROOT . '/app/whatsapp_hook.php';
    gimt_wa_event('${event.code}', ${phpValue(sample, 2)}, ['dedupe_key' => 'my-record:' . $recordId, 'source' => 'admin']);
} catch (\\Throwable $e) {
    error_log('[GIMT] WhatsApp hook: ' . $e->getMessage());
}
// student_id / admission_id / enquiry_id are enough: name, phone, programme, counsellor … are filled in automatically.
// One message per student (results, notices …) runs in the background worker:
// gimt_wa_fanout('${event.code}', $commonPayload, $studentIds, 'my-batch:' . $batchId, ['source' => 'admin']);`,
    curl: `curl -sS --max-time 10 -X POST "${options.ingest_url}" \\
  -H "Content-Type: application/json" \\
  -H "X-GIMT-Token: $GIMT_WA_TOKEN" \\
  -d '${json.replace(/'/g, "'\\''")}'`,
    phpext: `<?php
// External server (PHP 7.4+ with cURL). Keep the token in an environment variable, never in browser code.
$payload = ${phpValue(body, 1)};
$ch = curl_init('${options.ingest_url}');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-GIMT-Token: ' . getenv('GIMT_WA_TOKEN')],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);
$body = curl_exec($ch);
if ($body === false) {
    throw new RuntimeException('GIMT WhatsApp event failed: ' . curl_error($ch));
}
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$response = json_decode($body, true); // {"ok":true,"message":"Event received.","data":{"event_log_id":123,"status":"processed","duplicate":false,"outcome":{...}}}
if ($status !== 200 || empty($response['ok'])) {
    error_log('GIMT WhatsApp event rejected (' . $status . '): ' . ($response['message'] ?? $body));
}`,
    node: `// Server-side only (Node 18+): save as send-gimt-event.mjs and run with GIMT_WA_TOKEN set.
// Never put the API token in browser code.
const res = await fetch('${options.ingest_url}', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-GIMT-Token': process.env.GIMT_WA_TOKEN },
  body: JSON.stringify(${json.replace(/\n/g, '\n  ')}),
  signal: AbortSignal.timeout(10_000),
});
const result = await res.json(); // { ok, message, data: { event_log_id, status, duplicate, outcome } }
if (!res.ok || !result.ok) throw new Error(\`GIMT WhatsApp event rejected (\${res.status}): \${result.message}\`);
console.log(result.data);`,
    web: `// On a GIMT website page (same origin). The form needs inputs named "name" and "phone", a WhatsApp consent
// checkbox named "consent" and an element with class "form-status" for the reply.
document.querySelector('#whatsapp-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const fields = new FormData(form);
  const res = await fetch('${trackPath}', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify({
      event: '${event.code}',
      phone: fields.get('phone'),
      name: fields.get('name'),
      consent: fields.get('consent') !== null, // must be true (checkbox ticked)
      page: location.pathname,
      data: ${JSON.stringify(webData, null, 2).replace(/\n/g, '\n      ')},
    }),
  });
  const result = await res.json();
  form.querySelector('.form-status').textContent = result.message; // "Thank you! We will send the details on WhatsApp shortly."
});`,
  };

  return (
    <div className="space-y-4">
      <Tabs tabs={tabs} value={tab} onChange={setTab} variant="pills" />
      <CodeBlock code={codes[tab] ?? codes.php} label={`${tabs.find((t) => t.key === tab)?.label ?? 'Code'} example`} />
      {tab !== 'php' && tab !== 'web' && (
        <Alert variant="info" title="API token">
          Set <code className="font-mono">GIMT_WA_TOKEN</code> to a token with the <strong>events</strong> scope on the calling server. Tokens are shown once when created and are never displayed here.
          {options.can_see_tokens && (
            <Link to="/whatsapp/api" className="ml-1 inline-flex items-center gap-1 font-medium text-brand-700 underline-offset-2 hover:underline dark:text-brand-300">
              <KeyRound className="h-3.5 w-3.5" aria-hidden /> Manage API tokens
            </Link>
          )}
        </Alert>
      )}
      {tab === 'web' && (
        <Alert variant="info" title="Website events">
          Only events whose source is <em>Website JavaScript</em> are accepted here. The endpoint checks the CSRF token, rate-limits by IP and phone, records the visitor&apos;s WhatsApp opt-in and ignores record ids and phone fields in <code className="font-mono">data</code> (the message only goes to the visitor&apos;s own number).
        </Alert>
      )}
      <div className="rounded-xl border border-slate-200 p-3 text-xs text-slate-600 dark:border-slate-700 dark:text-slate-300">
        <p className="font-semibold text-slate-800 dark:text-slate-100">Response</p>
        <p className="mt-1"><code className="font-mono">200</code> {'{ ok: true, data: { event_log_id, status, duplicate, outcome } }'} — <code className="font-mono">401</code> bad token — <code className="font-mono">422</code> unknown event / invalid phone — <code className="font-mono">429</code> rate limit (300 / min per token).</p>
        <p className="mt-1">Sending the same <code className="font-mono">dedupe_key</code> again is ignored, so retries are safe.</p>
      </div>
    </div>
  );
}
