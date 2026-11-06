import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BookOpen, Globe, KeyRound, Webhook, Zap } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, CardSkeleton, Select, Tabs } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import type { DocsData } from '../lib';
import { CodeBlock, CopyField } from '../ui';

type Lang = 'php' | 'js' | 'curl';
const LANGS = [
  { key: 'php', label: 'PHP' },
  { key: 'js', label: 'JavaScript' },
  { key: 'curl', label: 'cURL' },
];

const j = (v: unknown, indent = 2) => JSON.stringify(v, null, indent);
const phpArray = (v: unknown, pad = '    '): string => {
  if (v === null || v === undefined) return 'null';
  if (Array.isArray(v)) return `[${v.map((x) => phpArray(x, pad)).join(', ')}]`;
  if (typeof v === 'object') {
    const entries = Object.entries(v as Record<string, unknown>);
    if (!entries.length) return '[]';
    return `[\n${entries.map(([k, x]) => `${pad}    '${k}' => ${phpArray(x, pad + '    ')},`).join('\n')}\n${pad}]`;
  }
  if (typeof v === 'number' || typeof v === 'boolean') return String(v);
  return `'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
};

function eventExamples(d: DocsData, code: string, lang: Lang): string {
  const ev = d.events.find((e) => e.code === code) ?? d.sample_event;
  const sample = (ev?.sample_payload ?? {}) as Record<string, unknown>;
  const { phone = '+919876543210', name = 'Rahul Sharma', email, ...rest } = sample;
  const body: Record<string, unknown> = { event: code, phone, name, ...(email ? { email } : {}), data: rest, dedupe_key: `${code.toLowerCase()}-12345` };
  if (lang === 'curl') {
    return `curl -X POST "${d.ingest_event_url}" \\
  -H "Content-Type: application/json" \\
  -H "X-GIMT-Token: $GIMT_WA_TOKEN" \\
  -d '${j(body).replace(/'/g, "'\\''")}'`;
  }
  if (lang === 'js') {
    return `// Node.js 18+ (server side only — never expose the token in a browser)
const res = await fetch('${d.ingest_event_url}', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-GIMT-Token': process.env.GIMT_WA_TOKEN,
  },
  body: JSON.stringify(${j(body).replace(/\n/g, '\n  ')}),
});
const json = await res.json();
if (!json.ok) throw new Error(json.message);
console.log(json.data); // { event_log_id, status, duplicate, outcome }`;
  }
  return `<?php
// Fire a GIMT WhatsApp event from another system (PHP 7.4+ with curl)
$payload = ${phpArray(body, '')};

$ch = curl_init('${d.ingest_event_url}');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-GIMT-Token: ' . getenv('GIMT_WA_TOKEN'),
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);
$response = json_decode((string) curl_exec($ch), true);
curl_close($ch);

if (empty($response['ok'])) {
    error_log('GIMT WhatsApp event failed: ' . ($response['message'] ?? 'no response'));
}`;
}

function trackExamples(d: DocsData, lang: Lang): string {
  const code = d.track_event?.code ?? 'GIMT_BROCHURE_DOWNLOAD';
  const body = { event: code, phone: '9876543210', name: 'Ananya Gupta', consent: true, page: '/programs/mba', data: { program: 'MBA' } };
  if (lang === 'curl') {
    return `# Website tracking is meant for same-origin pages of ${d.base_url} (session cookie + CSRF token).
curl -X POST "${d.ingest_track_url}" \\
  -H "Content-Type: application/json" \\
  -H "X-CSRF-Token: <csrf-token meta value>" \\
  -b "gimt_session=<session cookie>" \\
  -d '${j(body)}'`;
  }
  if (lang === 'php') {
    return `<?php
// Website pages render the form; the browser posts it (see the JavaScript tab).
// In a PHP page, print the consent checkbox and the CSRF meta tag that the script reads:
?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<form id="wa-brochure" data-wa-event="${code}">
  <input name="name" required placeholder="Your name">
  <input name="phone" type="tel" required placeholder="WhatsApp number">
  <label><input type="checkbox" name="consent" value="1" required>
    Send me the brochure and admission updates on WhatsApp</label>
  <button>Get it on WhatsApp</button>
</form>`;
  }
  return `// Website JS tracking — same-origin, no API token (consent is mandatory; max 5 requests / number / hour)
document.querySelector('#wa-brochure').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const res = await fetch('/api/whatsapp/ingest/track', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify({
      event: '${code}',
      name: f.get('name'),
      phone: f.get('phone'),
      consent: f.get('consent') === '1',
      page: location.pathname,
      data: { program: 'MBA' },
    }),
  });
  const json = await res.json();
  alert(json.message); // "Thank you! We will send the details on WhatsApp shortly."
});`;
}

function webhookExamples(d: DocsData, lang: Lang): string {
  if (lang === 'php') {
    return `<?php
// How GIMT verifies an Interakt webhook (done for you at ${d.webhook_url})
$raw = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $raw, $webhookSecret);
$given = $_SERVER['HTTP_INTERAKT_SIGNATURE'] ?? '';
if (!hash_equals($expected, $given)) {
    http_response_code(401);
    exit;
}
$event = json_decode($raw, true);   // see the payload samples below
http_response_code(200);           // answer within 3 seconds — Interakt never retries`;
  }
  if (lang === 'js') {
    return `// Verify an Interakt webhook signature in Node.js (express.raw body)
import crypto from 'node:crypto';

function isValid(rawBody, signatureHeader, secret) {
  const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  return signatureHeader && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signatureHeader));
}`;
  }
  const body = j(d.webhook_samples.message_api_delivered ?? {}, 0);
  return `# Replay a signed webhook against GIMT (for testing your secret)
BODY='${body}'
SIG="sha256=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | sed 's/^.* //')"
curl -X POST "${d.webhook_url}" -H "Content-Type: application/json" -H "Interakt-Signature: $SIG" -d "$BODY"`;
}

/** Copy-ready API documentation (PHP, JavaScript, cURL) for events, website tracking and webhooks. */
export default function ApiDocs() {
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['wa', 'admin', 'docs'], queryFn: ({ signal }) => api.get<DocsData>('whatsapp/docs', undefined, { signal }), staleTime: 5 * 60_000 });
  const [lang, setLang] = useState<Lang>('php');
  const [code, setCode] = useState('');
  const eventCode = code || data?.sample_event?.code || 'GIMT_PAYMENT_SUCCESS';
  const ex = useMemo(() => (data ? { event: eventExamples(data, eventCode, lang), track: trackExamples(data, lang), hook: webhookExamples(data, lang) } : null), [data, eventCode, lang]);

  if (error) return <Alert variant="error" title="Could not load the API documentation" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>;
  if (isLoading || !data || !ex) return <CardSkeleton lines={10} />;

  return (
    <div className="space-y-5">
      <Card>
        <CardHeader title="Endpoints" subtitle="All requests and responses are JSON: { ok, message, data }" icon={BookOpen} actions={<Tabs tabs={LANGS} value={lang} onChange={(v) => setLang(v as Lang)} variant="pills" />} />
        <div className="card-body grid grid-cols-1 gap-4 lg:grid-cols-3">
          {[
            { icon: Zap, title: 'Fire an event', method: 'POST', url: data.ingest_event_url, note: 'Header X-GIMT-Token (create one in API tokens). 300 events / min per token.' },
            { icon: Globe, title: 'Website tracking', method: 'POST', url: data.ingest_track_url, note: 'Same-origin website forms, consent required, events with source “js”.' },
            { icon: Webhook, title: 'Interakt webhook', method: 'POST', url: data.webhook_url, note: 'Configured in Interakt › Developer Settings, signed with your secret.' },
          ].map((e) => (
            <div key={e.title} className="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
              <p className="flex items-center gap-2 font-semibold text-slate-800 dark:text-slate-100">
                <e.icon className="h-4 w-4 text-accent-600" aria-hidden /> {e.title} <Badge color="navy">{e.method}</Badge>
              </p>
              <div className="mt-2">
                <CopyField value={e.url} label={`${e.title} URL`} />
              </div>
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">{e.note}</p>
            </div>
          ))}
        </div>
      </Card>

      <Card>
        <CardHeader
          title="1. Fire a GIMT event"
          subtitle="The event’s linked template is sent to the phone in the payload; duplicates with the same dedupe_key are ignored"
          icon={KeyRound}
          actions={
            <Select
              inputSize="sm"
              aria-label="Event"
              className="w-full sm:w-72"
              value={eventCode}
              onChange={(e) => setCode(e.target.value)}
              options={data.events.map((e) => ({ value: e.code, label: `${e.code}${e.active ? '' : ' (inactive)'}` }))}
            />
          }
        />
        <div className="card-body space-y-3">
          <CodeBlock code={ex.event} language={LANGS.find((l) => l.key === lang)?.label} />
          <p className="min-w-0 break-words text-xs text-slate-500 dark:text-slate-400">
            Response: <code className="break-all">{'{"ok":true,"message":"Event received.","data":{"event_log_id":812,"status":"processed","duplicate":false,"outcome":{...}}}'}</code>. Errors: 401 invalid token, 422 unknown event / invalid phone, 429 rate limit.
          </p>
          <p className="min-w-0 break-words text-xs text-slate-500 dark:text-slate-400">
            Ignored keys in <code>data</code>: anything starting with <code>_</code> (internal markers), <code>contact_id</code> and <code>fanout</code> — the contact is always resolved from <code>phone</code> / the record ids in the payload.
          </p>
        </div>
      </Card>

      <Card>
        <CardHeader title="2. Website JavaScript tracking" subtitle="Brochure requests, callbacks and other website actions — no token in the browser" icon={Globe} />
        <div className="card-body space-y-3">
          <CodeBlock code={ex.track} language={LANGS.find((l) => l.key === lang)?.label} />
          <p className="min-w-0 break-words text-xs text-slate-500 dark:text-slate-400">
            Only events whose source is “Website JS” are accepted. Ignored keys in <code>data</code> (max 20 lowercase keys, text values up to 300 characters): every <code>*_id</code> key except a numeric <code>program_id</code>, and any key containing <code>phone</code>, <code>mobile</code>, <code>whatsapp</code>, <code>email</code>, <code>contact</code>, <code>token</code>, <code>secret</code> or <code>fanout</code> — the message only ever goes to the visitor’s own number.
          </p>
        </div>
      </Card>

      <Card>
        <CardHeader title="3. Webhook format and signature" subtitle={data.signature} icon={Webhook} />
        <div className="card-body space-y-3">
          <CodeBlock code={ex.hook} language={LANGS.find((l) => l.key === lang)?.label} />
          <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
            {Object.entries(data.webhook_samples).map(([k, v]) => (
              <div key={k}>
                <p className="form-label">
                  Sample <code>{k}</code>
                </p>
                <CodeBlock code={j(v)} language="json" />
              </div>
            ))}
          </div>
        </div>
      </Card>
    </div>
  );
}
