import{a as S,j as e}from"./query-CMsCoTJk.js";import{r as y}from"./react-Ce3rT28y.js";import{A as $,B as O,ay as C,a3 as N,aE as E,ag as I,an as v,cP as w,K as A,a_ as R,a as P}from"./index-C2dP1q4g.js";import{C as h,a as u}from"./Card-BWAjJATu.js";import{B as G}from"./Badge-4LXhbEwH.js";import{C as H,b as m}from"./ui-Cbir0nxF.js";import"./copy-EL0ZHrp1.js";const g=[{key:"php",label:"PHP"},{key:"js",label:"JavaScript"},{key:"curl",label:"cURL"}],l=(t,n=2)=>JSON.stringify(t,null,n),b=(t,n="    ")=>{if(t==null)return"null";if(Array.isArray(t))return`[${t.map(a=>b(a,n)).join(", ")}]`;if(typeof t=="object"){const a=Object.entries(t);return a.length?`[
${a.map(([r,o])=>`${n}    '${r}' => ${b(o,n+"    ")},`).join(`
`)}
${n}]`:"[]"}return typeof t=="number"||typeof t=="boolean"?String(t):`'${String(t).replace(/\\/g,"\\\\").replace(/'/g,"\\'")}'`};function W(t,n,a){const r=t.events.find(p=>p.code===n)??t.sample_event,o=(r==null?void 0:r.sample_payload)??{},{phone:x="+919876543210",name:k="Rahul Sharma",email:d,...c}=o,i={event:n,phone:x,name:k,...d?{email:d}:{},data:c,dedupe_key:`${n.toLowerCase()}-12345`};return a==="curl"?`curl -X POST "${t.ingest_event_url}" \\
  -H "Content-Type: application/json" \\
  -H "X-GIMT-Token: $GIMT_WA_TOKEN" \\
  -d '${l(i).replace(/'/g,"'\\''")}'`:a==="js"?`// Node.js 18+ (server side only — never expose the token in a browser)
const res = await fetch('${t.ingest_event_url}', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-GIMT-Token': process.env.GIMT_WA_TOKEN,
  },
  body: JSON.stringify(${l(i).replace(/\n/g,`
  `)}),
});
const json = await res.json();
if (!json.ok) throw new Error(json.message);
console.log(json.data); // { event_log_id, status, duplicate, outcome }`:`<?php
// Fire a GIMT WhatsApp event from another system (PHP 7.4+ with curl)
$payload = ${b(i,"")};

$ch = curl_init('${t.ingest_event_url}');
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
}`}function M(t,n){var o;const a=((o=t.track_event)==null?void 0:o.code)??"GIMT_BROCHURE_DOWNLOAD",r={event:a,phone:"9876543210",name:"Ananya Gupta",consent:!0,page:"/programs/mba",data:{program:"MBA"}};return n==="curl"?`# Website tracking is meant for same-origin pages of ${t.base_url} (session cookie + CSRF token).
curl -X POST "${t.ingest_track_url}" \\
  -H "Content-Type: application/json" \\
  -H "X-CSRF-Token: <csrf-token meta value>" \\
  -b "gimt_session=<session cookie>" \\
  -d '${l(r)}'`:n==="php"?`<?php
// Website pages render the form; the browser posts it (see the JavaScript tab).
// In a PHP page, print the consent checkbox and the CSRF meta tag that the script reads:
?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<form id="wa-brochure" data-wa-event="${a}">
  <input name="name" required placeholder="Your name">
  <input name="phone" type="tel" required placeholder="WhatsApp number">
  <label><input type="checkbox" name="consent" value="1" required>
    Send me the brochure and admission updates on WhatsApp</label>
  <button>Get it on WhatsApp</button>
</form>`:`// Website JS tracking — same-origin, no API token (consent is mandatory; max 5 requests / number / hour)
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
      event: '${a}',
      name: f.get('name'),
      phone: f.get('phone'),
      consent: f.get('consent') === '1',
      page: location.pathname,
      data: { program: 'MBA' },
    }),
  });
  const json = await res.json();
  alert(json.message); // "Thank you! We will send the details on WhatsApp shortly."
});`}function B(t,n){return n==="php"?`<?php
// How GIMT verifies an Interakt webhook (done for you at ${t.webhook_url})
$raw = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $raw, $webhookSecret);
$given = $_SERVER['HTTP_INTERAKT_SIGNATURE'] ?? '';
if (!hash_equals($expected, $given)) {
    http_response_code(401);
    exit;
}
$event = json_decode($raw, true);   // see the payload samples below
http_response_code(200);           // answer within 3 seconds — Interakt never retries`:n==="js"?`// Verify an Interakt webhook signature in Node.js (express.raw body)
import crypto from 'node:crypto';

function isValid(rawBody, signatureHeader, secret) {
  const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  return signatureHeader && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signatureHeader));
}`:`# Replay a signed webhook against GIMT (for testing your secret)
BODY='${l(t.webhook_samples.message_api_delivered??{},0)}'
SIG="sha256=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | sed 's/^.* //')"
curl -X POST "${t.webhook_url}" -H "Content-Type: application/json" -H "Interakt-Signature: $SIG" -d "$BODY"`}function K(){var p,j,f,_;const{data:t,isLoading:n,error:a,refetch:r}=S({queryKey:["wa","admin","docs"],queryFn:({signal:s})=>P.get("whatsapp/docs",void 0,{signal:s}),staleTime:3e5}),[o,x]=y.useState("php"),[k,d]=y.useState(""),c=k||((p=t==null?void 0:t.sample_event)==null?void 0:p.code)||"GIMT_PAYMENT_SUCCESS",i=y.useMemo(()=>t?{event:W(t,c,o),track:M(t,o),hook:B(t,o)}:null,[t,c,o]);return a?e.jsx($,{variant:"error",title:"Could not load the API documentation",action:e.jsx(O,{size:"sm",variant:"secondary",onClick:()=>r(),children:"Retry"}),children:a.message}):n||!t||!i?e.jsx(C,{lines:10}):e.jsxs("div",{className:"space-y-5",children:[e.jsxs(h,{children:[e.jsx(u,{title:"Endpoints",subtitle:"All requests and responses are JSON: { ok, message, data }",icon:N,actions:e.jsx(E,{tabs:g,value:o,onChange:s=>x(s),variant:"pills"})}),e.jsx("div",{className:"card-body grid grid-cols-1 gap-4 lg:grid-cols-3",children:[{icon:I,title:"Fire an event",method:"POST",url:t.ingest_event_url,note:"Header X-GIMT-Token (create one in API tokens). 300 events / min per token."},{icon:v,title:"Website tracking",method:"POST",url:t.ingest_track_url,note:"Same-origin website forms, consent required, events with source “js”."},{icon:w,title:"Interakt webhook",method:"POST",url:t.webhook_url,note:"Configured in Interakt › Developer Settings, signed with your secret."}].map(s=>e.jsxs("div",{className:"rounded-2xl border border-slate-200 p-4 dark:border-slate-700",children:[e.jsxs("p",{className:"flex items-center gap-2 font-semibold text-slate-800 dark:text-slate-100",children:[e.jsx(s.icon,{className:"h-4 w-4 text-accent-600","aria-hidden":!0})," ",s.title," ",e.jsx(G,{color:"navy",children:s.method})]}),e.jsx("div",{className:"mt-2",children:e.jsx(H,{value:s.url,label:`${s.title} URL`})}),e.jsx("p",{className:"mt-2 text-xs text-slate-500 dark:text-slate-400",children:s.note})]},s.title))})]}),e.jsxs(h,{children:[e.jsx(u,{title:"1. Fire a GIMT event",subtitle:"The event’s linked template is sent to the phone in the payload; duplicates with the same dedupe_key are ignored",icon:A,actions:e.jsx(R,{inputSize:"sm","aria-label":"Event",className:"w-full sm:w-72",value:c,onChange:s=>d(s.target.value),options:t.events.map(s=>({value:s.code,label:`${s.code}${s.active?"":" (inactive)"}`}))})}),e.jsxs("div",{className:"card-body space-y-3",children:[e.jsx(m,{code:i.event,language:(j=g.find(s=>s.key===o))==null?void 0:j.label}),e.jsxs("p",{className:"min-w-0 break-words text-xs text-slate-500 dark:text-slate-400",children:["Response: ",e.jsx("code",{className:"break-all",children:'{"ok":true,"message":"Event received.","data":{"event_log_id":812,"status":"processed","duplicate":false,"outcome":{...}}}'}),". Errors: 401 invalid token, 422 unknown event / invalid phone, 429 rate limit."]}),e.jsxs("p",{className:"min-w-0 break-words text-xs text-slate-500 dark:text-slate-400",children:["Ignored keys in ",e.jsx("code",{children:"data"}),": anything starting with ",e.jsx("code",{children:"_"})," (internal markers), ",e.jsx("code",{children:"contact_id"})," and ",e.jsx("code",{children:"fanout"})," — the contact is always resolved from ",e.jsx("code",{children:"phone"})," / the record ids in the payload."]})]})]}),e.jsxs(h,{children:[e.jsx(u,{title:"2. Website JavaScript tracking",subtitle:"Brochure requests, callbacks and other website actions — no token in the browser",icon:v}),e.jsxs("div",{className:"card-body space-y-3",children:[e.jsx(m,{code:i.track,language:(f=g.find(s=>s.key===o))==null?void 0:f.label}),e.jsxs("p",{className:"min-w-0 break-words text-xs text-slate-500 dark:text-slate-400",children:["Only events whose source is “Website JS” are accepted. Ignored keys in ",e.jsx("code",{children:"data"})," (max 20 lowercase keys, text values up to 300 characters): every ",e.jsx("code",{children:"*_id"})," key except a numeric ",e.jsx("code",{children:"program_id"}),", and any key containing ",e.jsx("code",{children:"phone"}),", ",e.jsx("code",{children:"mobile"}),", ",e.jsx("code",{children:"whatsapp"}),", ",e.jsx("code",{children:"email"}),", ",e.jsx("code",{children:"contact"}),", ",e.jsx("code",{children:"token"}),", ",e.jsx("code",{children:"secret"})," or ",e.jsx("code",{children:"fanout"})," — the message only ever goes to the visitor’s own number."]})]})]}),e.jsxs(h,{children:[e.jsx(u,{title:"3. Webhook format and signature",subtitle:t.signature,icon:w}),e.jsxs("div",{className:"card-body space-y-3",children:[e.jsx(m,{code:i.hook,language:(_=g.find(s=>s.key===o))==null?void 0:_.label}),e.jsx("div",{className:"grid grid-cols-1 gap-3 xl:grid-cols-2",children:Object.entries(t.webhook_samples).map(([s,T])=>e.jsxs("div",{children:[e.jsxs("p",{className:"form-label",children:["Sample ",e.jsx("code",{children:s})]}),e.jsx(m,{code:l(T),language:"json"})]},s))})]})]})]})}export{K as default};
