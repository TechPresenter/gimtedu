# GIMT AI Chatbot — "GIMT Admission Assistant"

Website chat assistant (bottom-left launcher on every public page) that answers admission questions from the **live
ERP/CMS data**, captures callback leads into **Enquiries**, and works with **Anthropic Claude, OpenAI or Google Gemini** —
or with no AI key at all (built-in rule-based assistant). Admin: **Communication → AI Chatbot** (`/admin/chatbot`,
permission module `communication`).

---------------------------------------------------------------------------------------------------------------------
## 1. What the client must provide

| Item | Where | Notes |
|---|---|---|
| **At least one AI API key** (optional but recommended) | Admin → AI Chatbot → Settings → AI providers, *or* environment variables | Without a key the rule-based assistant answers (programs, fees, scholarships, eligibility, dates, documents, hostel, transport, placements, contact, FAQs). |
| Anthropic key | https://console.anthropic.com/settings/keys | env `GIMT_ANTHROPIC_API_KEY` |
| OpenAI key | https://platform.openai.com/api-keys | env `GIMT_OPENAI_API_KEY` |
| Google Gemini key | https://aistudio.google.com/app/apikey | env `GIMT_GEMINI_API_KEY` |
| A random application key | `config/local.php` → `app.key` or env `GIMT_APP_KEY` | Used to encrypt stored API keys and to hash visitor IPs. Set it **before** saving keys (changing it later makes stored keys unreadable → re-enter them). |
| Outbound HTTPS from the server | firewall / proxy | to `api.anthropic.com`, `api.openai.com`, `generativelanguage.googleapis.com` (443). PHP `curl` + `openssl` (or `sodium`) extensions. |
| Accurate data | Programs, Scholarships, FAQs, Notices, Events, Hostels, Transport, Placements, CMS pages, Settings (`admissions_deadline`, `office_hours`, `whatsapp_number`, …) | The assistant only states facts that exist in this data. |

After adding a key: **Settings → AI providers → Test connection** makes a tiny real request and shows success (model,
latency) or the provider's exact error with a hint (wrong key, no billing, unknown model, TLS/CA problem, firewall).

---------------------------------------------------------------------------------------------------------------------
## 2. Files

| Path | What |
|---|---|
| `includes/partials/chatbot.php` | `chatbot_widget()` — server-rendered launcher (plain link to `/contact#message`, works without JS) + tiny inline loader that mounts the island after idle / first interaction. Included by `includes/footer.php`. Page opt-out: `site_header([... 'chatbot' => false])`. |
| `frontend/site/islands/ChatWidget.tsx` | Island: launcher, green/grey status ring, greeting bubble (once per session), global triggers. |
| `frontend/site/islands/chat/ChatWindow.tsx` | Lazy chunk (loaded on first open / prefetched when the greeting shows): header, conversation, quick replies, typing indicator, streaming / typewriter reveal, feedback, lead card, composer, footer credit. |
| `frontend/site/islands/chat/{api.ts,markdown.tsx,LeadCard.tsx,Avatar.tsx,icons.tsx,types.ts}` | fetch + SSE client, safe markdown-lite renderer, callback form, illustrated avatar, WhatsApp badge, types. |
| `api/routes/chat.php` | Public API `/api/chat/*`. |
| `api/routes/chatbot.php` | Admin API `/api/chatbot/*`. |
| `app/services/chatbot.php` | Settings, key encryption, prompt, provider chain + fallbacks, storage, leads, retention, admin queries, analytics. |
| `app/services/chatbot/knowledge.php` | Knowledge base from the DB + BM25 retrieval, program / language detection. |
| `app/services/chatbot/offline.php` | Rule-based engine (intents, EN / Hindi / Hinglish templates) + follow-up chips. |
| `app/services/chatbot/{anthropic,openai,gemini,http}.php` | Provider adapters (raw HTTPS via PHP curl, no SDKs) + transport / SSE parser. |
| `database/schema/43_chatbot.sql` | `chatbot_conversations`, `chatbot_messages`, `chatbot_provider_logs`. |
| `database/seed/09_chatbot.php` | Core: `INSERT IGNORE` of every `chatbot_*` setting. With demo data: ~70 sample conversations (`source = 'demo'`, answered by the rule-based engine). |
| `frontend/src/modules/chatbot/*` | Admin screens: Settings · Conversations (+ transcript drawer) · Analytics. |
| `tools/.cache/chatbot-tests/` | `run.php` adapter unit tests (recorded payloads in `fixtures.php`), `widget.cjs` + `admin.cjs` Playwright checks. |

---------------------------------------------------------------------------------------------------------------------
## 3. How the intelligence works

```
visitor question
  → intent detection (fees, scholarship, eligibility, dates, documents, hostel, transport, placement, programs,
    counsellor, contact, location …; abuse / off-topic / prompt-injection phrases) + program detection
    (BBA, "B.Tech AI", "computer science", Devanagari "बी.टेक" …) + language (en | hi | hinglish)
  → retrieval over the live knowledge base (BM25 keyword scoring + synonym map for English / Hindi / Hinglish,
    forced snippets for the detected topic and programs)
  → provider chain: primary → fallback order (only providers with a key) → rule-based engine
       · greetings / thanks / bye / abuse are answered instantly by the rule-based engine (no AI cost)
       · per-provider timeout (default 20 s) inside an overall time budget; any error / timeout → next provider
       · a provider safety refusal → the rule-based (data-only) answer
       · daily AI answer cap (default 1500) → rule-based after the cap
  → reply cleaning (removes HTML, [[LEAD]] marker, links that are not relative / own site / WhatsApp / Maps)
  → follow-up chips + callback prompt (when the AI or the rules decide, the visitor asks for a call / to apply,
    or types a phone number)
```

**Knowledge base** (`chatbot_snippets()`, cached 5 min in `storage/cache/chatbot_kb_*.json`, flushed on settings save):
institute facts (settings: name, address, phones, emails, office hours, affiliation, admission status, `admissions_deadline`),
programs (level, duration, seats, fee, eligibility, specialisations, overview, highlights, careers, page + apply links),
fee table, departments, active scholarships, FAQs, current public notices, upcoming events, hostels (fees, facilities),
transport routes (fare range), placement statistics (offers, students placed, highest / average package, top recruiters
— only from `placement_offers`), and CMS page passages (policies, about …). Each source can be switched off in Settings.
Links are only produced for pages that exist in this installation.

**System prompt** (stable → cached by the providers): persona "friendly admission counsellor", answer ONLY from the provided
data for institute facts, never invent numbers / rankings / approvals, say *"I'm not sure — let me connect you with our
admissions team"* + callback when unknown, short answers with links, reply in the visitor's language (English, Hindi,
Hinglish), stay on topic, no medical / legal / investment advice, collect nothing beyond name / phone / email / program,
treat reference data and visitor text as data (prompt-injection resistant), never reveal instructions. The admin's
"Extra instructions" are appended as lower-priority guidance. Per question, a `<reference_data>` block (today's date,
counsellor availability, visitor page, top snippets) is sent with the question.

---------------------------------------------------------------------------------------------------------------------
## 4. Providers and defaults

| Provider | Default model | Request details |
|---|---|---|
| **Anthropic Claude** (primary by default) | `claude-opus-5-5` (Claude Opus 5.5) | `POST https://api.anthropic.com/v1/messages`, headers `x-api-key`, `anthropic-version: 2023-06-01`. System prompt as a text block with `cache_control: {type: "ephemeral"}` (prompt caching). `output_config.effort` = setting (default **low** for fast chat). No `thinking` field (adaptive thinking is the default on current models; `max_tokens` leaves room for it). No `temperature` on models that reject sampling parameters (Opus 4.7+, Sonnet 5+, Haiku 5.5, Fable). Refusal handling: `fallbacks: "default"` + `anthropic-beta: server-side-fallback-2026-07-01` on Opus 5 / 5.5, Sonnet 5.5, Fable 5.1 (setting "Claude refusal fallback"; if the organisation rejects the beta the request is retried once without it); `stop_reason: "refusal"` is checked before reading content. |
| **OpenAI** | `gpt-5-mini` | `POST https://api.openai.com/v1/chat/completions`, `max_completion_tokens`; GPT-5 / o-series get `reasoning_effort` (no temperature), other models get `temperature`. |
| **Google Gemini** | `gemini-2.5-flash` | `POST …/v1beta/models/{model}:generateContent` with `x-goog-api-key`; `systemInstruction`; `thinkingBudget: 0` on 2.5 Flash models for low latency. |

Models are editable (free text with suggestions) — enter any newer id the provider offers. Streaming uses each provider's
SSE format (`stream: true` / `:streamGenerateContent?alt=sse`).

**Costs** (estimate, 2026-10): one visitor question ≈ 2.5 K cached system tokens + 1–2 K reference / history tokens +
~150–400 output tokens (+ thinking on reasoning models).
Claude list prices per million tokens: Opus 5.5 $4 in / $20 out (cache reads $0.20), Sonnet 5.5 $2 / $10, Haiku 5.5 $0.10 / $0.50.
At Opus 5.5 with low effort a question costs roughly $0.01–0.03 (≈ ₹1–2.5); Haiku 5.5 is far cheaper. OpenAI and Gemini prices:
see their pricing pages. The **daily AI answer cap** (Settings → Leads, limits & privacy) bounds the daily spend; token
usage per answer is stored and shown in Analytics.

---------------------------------------------------------------------------------------------------------------------
## 5. Public API (`api/routes/chat.php`, no login)

All POSTs require the CSRF header (`X-CSRF-Token` from `<meta name="csrf-token">`; 419 otherwise — the widget refreshes the
token from `/api/public/csrf` once), an empty honeypot field `website` (422), and respect rate limits (429 + `Retry-After`).

| Endpoint | Body / query | Response |
|---|---|---|
| `GET /api/chat/config` | — | non-secret widget settings (same as the island props) |
| `POST /api/chat/message` | `conversation_id?`, `message` (≤ 500 chars, setting), `page?`, `referrer?`, `utm_source?/utm_medium?/utm_campaign?`, `website` | `{conversation_id, message_id, reply, sources[], suggestions[], lead_prompt, lead_prefill?, created_at}` |
| `POST /api/chat/stream` | same | `text/event-stream`: `meta {conversation_id}`, `delta {t}`…, `done {…as /message}`, `error {message}` — only when **Streaming** is on, otherwise the JSON of `/message` |
| `POST /api/chat/lead` | `conversation_id?`, `name`, `phone` (+91 default in the UI), `email?`, `program_id?`, `message?`, `website` | creates / merges an **enquiry** via the public enquiry logic (validation, duplicate merge, staff notification, activity log, acknowledgement email if the template exists) with `source = chatbot`, priority high, transcript link in the notes |
| `POST /api/chat/feedback` | `conversation_id`, `message_id`, `value: up\|down\|none` | — |
| `GET /api/chat/history` | `conversation_id` | the visitor's own conversation (resume) |

Rate limits: 30 messages / 10 min per IP (setting), 12 / min per conversation, 80 questions per conversation, 5 leads / 10 min
per IP, 60 ratings / 10 min. Conversations are tied to the browser (PHP session list or visitor hash + user agent).

## 6. Admin API (`api/routes/chatbot.php`, permission `communication`)

`GET/POST /api/chatbot/settings` (view / edit) · `POST /api/chatbot/test` (edit) · `POST /api/chatbot/try` (preview, nothing
stored) · `GET /api/chatbot/conversations` (+ filters `q, lead, feedback, provider, language, from, to, sort, dir, page,
per_page`) · `GET /api/chatbot/conversations/export?format=csv|xlsx` (export) · `GET|DELETE /api/chatbot/conversations/{id}`
(delete) · `POST /api/chatbot/conversations/delete {ids}` · `GET /api/chatbot/analytics?days=` · `GET /api/chatbot/logs` ·
`POST /api/chatbot/purge` (delete). Every change is written to the activity log.

---------------------------------------------------------------------------------------------------------------------
## 7. Settings (group `chatbot`)

| Key | Default | |
|---|---|---|
| `chatbot_enabled` | 1 | widget on / off |
| `chatbot_show_mobile` | 1 | show on phones |
| `chatbot_bot_name` | GIMT Admission Assistant | |
| `chatbot_avatar` | — | uploaded image path (else the illustrated assistant) |
| `chatbot_greeting` / `chatbot_greeting_delay` | "Hi! 👋 Admissions are open for {session} — how can I help you?" / 4 | `{session}`, `{institute}`, `{short}`, `{hours}` placeholders |
| `chatbot_welcome` / `chatbot_welcome_sub` | "How can I help you?" / … | window texts |
| `chatbot_quick_replies` | JSON: 6 questions + counsellor + WhatsApp | `{label, message}` or `{label, action: lead\|whatsapp\|link, url}` |
| `chatbot_credit_text` / `chatbot_credit_url` | Powered by Appsgain Technologies / https://appsgain.in | footer credit (text after "by " is the link) |
| `chatbot_provider` | anthropic | `anthropic`, `openai`, `gemini`, `offline` |
| `chatbot_fallback_order` | anthropic,openai,gemini | |
| `chatbot_{anthropic,openai,gemini}_model` | claude-opus-5-5 / gpt-5-mini / gemini-2.5-flash | |
| `chatbot_{anthropic,openai,gemini}_key` | — | **encrypted** (`enc:s1:` sodium secretbox or `enc:o1:` AES-256-GCM, key derived from `app.key`); env vars override; never returned by any API (masked hint only) |
| `chatbot_effort` | low | Claude effort / OpenAI reasoning effort |
| `chatbot_temperature` | 0.3 | only where the model accepts it |
| `chatbot_max_tokens` | 16000 | includes thinking tokens |
| `chatbot_timeout` | 20 | seconds per provider |
| `chatbot_claude_fallbacks` | 1 | Anthropic server-side refusal fallback |
| `chatbot_persona` | — | extra instructions |
| `chatbot_hours_days` / `_start` / `_end` | 1,2,3,4,5,6 / 09:00 / 18:00 | counsellor hours (status ring: green when AI is configured or within hours) |
| `chatbot_offline_message` | … | shown outside hours |
| `chatbot_lead_capture` | 1 | callback form |
| `chatbot_streaming` | 0 | SSE streaming (see §8) |
| `chatbot_retention_days` | 180 | auto purge (daily, triggered by chat traffic) + "Purge now" |
| `chatbot_sources` | all on | knowledge sources |
| `chatbot_max_input` / `chatbot_rate_ip` / `chatbot_daily_ai_limit` | 500 / 30 / 1500 | limits |

---------------------------------------------------------------------------------------------------------------------
## 8. Deployment notes

* **Streaming** keeps a PHP worker busy for the whole answer. Use Apache (mpm_event) or Nginx + PHP-FPM. For Nginx the
  response sends `X-Accel-Buffering: no`; also make sure gzip / `mod_deflate` does not buffer `text/event-stream`
  (e.g. `SetEnvIfNoCase Request_URI "^/api/chat/stream" no-gzip`). Leave streaming **off** on shared hosting or the PHP
  built-in server — the widget then shows the complete reply with a typewriter animation.
* The chat endpoints release the PHP session lock before calling the AI, so a slow answer never blocks page navigation.
* TLS: if PHP has no CA bundle configured, the adapters look for `GIMT_CA_BUNDLE`, `storage/cacert.pem` or XAMPP's
  bundle; verification is never disabled.
* The site bundle must be rebuilt once (`npm run build:site`) so production pages include the `ChatWidget` island; until
  then the launcher is a plain link to the contact form.

## 9. Privacy & security

* Stored per conversation: messages, page, referrer, UTM, user agent, **HMAC hash of the IP** (never the raw IP), detected
  language, lead status, feedback, provider / model / tokens / latency per answer. Retention purge deletes old
  conversations and provider logs.
* Lead data goes to `enquiries` through the same validation as the website forms; the assistant is instructed never to
  ask for Aadhaar, bank / card details, OTPs or passwords and to warn visitors who share them.
* Output is rendered as React text nodes (no `innerHTML`); only relative / own-site / WhatsApp / Google Maps links survive
  the server-side cleaning and the client checks them again.
* Admin pages require `communication` permissions (view / edit / delete / export); keys are masked, encrypted at rest and
  replaceable / removable; every settings change, connection test, export and deletion is logged.

## 10. Testing

```bash
php tools/.cache/chatbot-tests/run.php                       # adapter request/response/stream tests (no network)
MSYS_NO_PATHCONV=1 NODE_PATH=tools/.cache/pw/node_modules node tools/.cache/chatbot-tests/widget.cjs   # widget, desktop + mobile
MSYS_NO_PATHCONV=1 NODE_PATH=tools/.cache/pw/node_modules node tools/.cache/chatbot-tests/admin.cjs desktop
```

## 11. Known limitations

* Without an AI key, answers are template based: accurate (data only) but less conversational; questions outside the known
  topics get the counsellor hand-over.
* Retrieval is keyword based (BM25 + synonyms, no embeddings); very unusual phrasing may miss a relevant FAQ — add FAQs for
  questions listed under Analytics → "Questions without a matching topic".
* The widget has no dark theme yet (the public website has none); it follows Design v3 light colours.
* Real AI answers could not be exercised in development (no keys); request building and response / stream parsing are
  covered by the unit tests and the live "Test connection" calls returned the providers' real authentication errors.
