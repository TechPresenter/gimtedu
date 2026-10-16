# Hostinger पर वेबसाइट लगाने और अपडेट करने के आसान स्टेप्स (हिंदी)

> पूरी अंग्रेज़ी गाइड: **README.md**

---

## A. वेबसाइट पहले से चल रही है? → Upgrade 1 लगाने का तरीका (डेटा सुरक्षित रहेगा)

आपकी live वेबसाइट पर donations, users और applications हैं, इसलिए **`database.sql` दोबारा import नहीं करना है**। सिर्फ़ नई फ़ाइलें अपलोड करनी हैं, database अपने-आप अपडेट हो जाएगा।

1. **बैकअप लें (ज़रूरी):**
   - hPanel → Websites → Manage → **Backups** → files + database का बैकअप डाउनलोड करें।
   - Admin → System → **Backup & Data Retention** → database backup डाउनलोड करें।
2. **फ़ाइलें अपलोड करें:**
   - File Manager → `public_html` खोलें → `prerit-upgrade-1.zip` (इसमें सिर्फ़ `public_html` की फ़ाइलें हैं) अपलोड करें → **Extract** करें → पूछे तो **Replace / Overwrite** चुनें।
   - कुछ भी डिलीट न करें: `uploads/` फ़ोल्डर (आपकी photos/documents) और public_html के बाहर रखी `pwf-config.php` वैसी ही रहेंगी।
   - इस zip में मुख्य `.htaccess` भी नहीं है, ताकि अगर आपने उसमें "Force HTTPS" चालू किया है तो वह वैसा ही रहे।
   - इस zip में `install.php` नहीं है, इसलिए installer दोबारा नहीं आएगा। अगर पुरानी `install.php` सर्वर पर पड़ी है तो वह अब सिर्फ़ "already installed" दिखाएगी — फिर भी उसे डिलीट कर दें।
3. **वेबसाइट एक बार खोलें:** `https://preritwelfarefoundation.org/` खोलते ही database अपने-आप अपडेट हो जाता है (नई tables, settings, pages)। पुराना कोई डेटा न हटता है, न बदलता है। जाँचने के लिए: Admin → System → **Audit Log** में "database_upgraded" दिखेगा (Security Centre में "Database up to date")। अगर कोई update fail हो, तो Dashboard पर लाल सूचना दिखेगी और वह हर 10 मिनट में अपने-आप दोबारा चलेगा।
   - जानबूझकर होने वाले बदलाव: `/events-page/` वाले menu links, page links और redirects अब `/events/` पर जाते हैं (पुराना पता redirect होता है); Donate और Volunteer Teacher pages पर अब सिर्फ़ form दिखता है — बाकी sections (आपके जोड़े हुए भी) **hidden** हो जाते हैं, डिलीट नहीं होते (Admin → Pages में फिर से चालू कर सकते हैं); होम पेज का "Our impact" हिस्सा hidden हो जाता है।
4. **(सुझाव) `APP_KEY` जोड़ें:** `pwf-config.php` (public_html के बाहर वाली) में यह लाइन जोड़ें, कोई लंबी random value (40+ अक्षर/अंक) के साथ:
   `define('APP_KEY', 'यहाँ-लंबी-random-value');`
   Admin में डाली गई payment/AI keys इसी से encrypt होती हैं। इसे बाद में **कभी न बदलें** (बदलने पर keys दोबारा डालनी पड़ेंगी)। APP_KEY न हो तो keys database password से बनी key से encrypt होती हैं — तब database password बदलने पर keys दोबारा डालनी पड़ेंगी, इसलिए APP_KEY keys डालने **से पहले** जोड़ें।
5. **Razorpay (ज़रूरी):** आपने live secret key chat में भेजी थी, इसलिए:
   - Razorpay Dashboard → Account & Settings → **API Keys → Regenerate** करके नई Key Secret बनाएँ।
   - Admin → Fundraising → **Payment Gateways** → Key ID और नई Key Secret डालें → अपना admin password डालें → **Save** → **Test connection**।
   - Razorpay → Webhooks → URL `https://preritwelfarefoundation.org/payment-webhook.php`, एक लंबा secret (वही Admin में "Webhook Secret" में डालें), events: payment.authorized, payment.captured, order.paid, payment.failed, refund.processed।
   - "Which gateway do donors use?" में Razorpay चुनें। फिर ₹1 या ₹10 का एक असली test donation करके receipt जाँच लें।
6. **Cashfree (वैकल्पिक):** merchant account approve होने के बाद, पहले **Sandbox** keys से — Admin → Payment Gateways → Cashfree App ID + Secret Key → Test connection → Cashfree Dashboard → Webhooks: `https://preritwelfarefoundation.org/cashfree-webhook.php`। एक sandbox payment और refund जाँचने के बाद ही Production keys डालें।
7. **AI Chatbot (वैकल्पिक):** platform.openai.com → API keys → नई key बनाएँ और **monthly budget limit** लगाएँ → Admin → Communication → **AI Assistant** → key डालें → "Switch on" → Save → Test connection। बंद करने पर पुराना FAQ helper वापस आ जाता है।
8. **Seal और letters:** Admin → People → **Authorization Letters → Settings** → foundation की **seal** (PNG, transparent background) अपलोड करें — यही donate page पर form के साथ दिखेगी। Signature image अलग से (private रहती है, सिर्फ़ letters में)। Letter number prefix (EIF) और अगला नंबर जाँच लें।
9. **Hero slider की right image:** Admin → Dashboard → "Homepage hero slider" कार्ड → हर slide में **Upload new** → alt text → Save।
10. **Programs & Events:** Admin → Content → **Programme Categories** (10 categories तैयार हैं — text, images, SEO बदलें) → **Events & Calendar** में असली workshop/seminar/hackathon डालें (बिना असली तारीख़/speaker के कुछ publish न करें)।
11. **Deep Analytics:** Admin → Insights → **Deep Analytics & Visitors** → Settings → location source चुनें (Cloudflare हो तो Cloudflare, वरना ipinfo/ipapi token या "Off")।
12. **Volunteer teacher terms:** Admin → Settings → Recruitment → "Volunteer teacher terms & conditions" में शर्तें जाँच/बदल लें।

**सब ठीक है, कैसे पता करें:** Homepage पर "Our Impact" section नहीं होगा; footer में CIN, NGO ID, MSME दिखेंगे; top bar में "Programs & Events" dropdown; `/programs/`, `/events/`, `/verify-letter/` खुलेंगे; donate page पर सिर्फ़ form (और upload करने के बाद seal)।

**कुछ गड़बड़ हो तो:** बैकअप वाली फ़ाइलें वापस अपलोड करें (database नहीं बदलना पड़ता — नई tables पुराने code को परेशान नहीं करतीं)। Error दिखे तो Admin → Security Centre और hPanel → Error logs देखें, और मुझे बताएँ।

---

## B. बिल्कुल नई installation (खाली database) — पहली बार

> ⚠️ **सिर्फ़ नए या खाली database पर।** `database.sql` पहले सारी tables हटाता है — live site के database में import करने पर सारा डेटा मिट जाएगा। डेटा बचाना है तो भाग A (Upgrade) करें।

फ़ाइल: `prerit-website-v3-upgrade-1.zip` को कंप्यूटर पर unzip करें — अंदर `public_html-upload.zip`, `database.sql`, `pwf-config.sample.php` और guides हैं।

1. **बैकअप:** hPanel → Websites → Manage → **Backups** → files + database डाउनलोड करें।
2. **PHP version:** hPanel → Advanced → **PHP Configuration** → PHP **8.1 या 8.2**; extensions `pdo_mysql`, `mbstring`, `gd`, `curl`, `openssl` (और `sodium`) ON।
3. **पुरानी फ़ाइलें हटाएँ:** File Manager → `public_html` → सब डिलीट करें, सिर्फ़ `.well-known` रहने दें।
4. **नया Database:** hPanel → Databases → Management → नया database + user (पासवर्ड में **सिर्फ़ अक्षर और अंक**)। पूरे नाम नोट करें (जैसे `u623595918_prerit`)।
5. **database.sql import:** phpMyAdmin → वही नया database → **Import** → `database.sql` → **Go** → "successfully finished"।
6. **फ़ाइलें अपलोड:** `public_html` में `public_html-upload.zip` अपलोड → **Extract** (फ़ाइलें सीधे `public_html` में आएँ) → zip डिलीट करें → "Show hidden files" ON करके `.htaccess` मौजूद है, जाँचें।
7. **pwf-config.php:** `pwf-config.sample.php` की कॉपी `pwf-config.php` नाम से बनाएँ और भरें: `BASE_URL` (`https://preritwelfarefoundation.org`, आख़िर में `/` नहीं), `DB_NAME`, `DB_USER`, `DB_PASS`, लंबी random `INSTALL_KEY`, और `APP_KEY` (उसकी लाइन के आगे का `//` हटाकर 40+ random अक्षर/अंक — बाद में **कभी न बदलें**)। Email के लिए hPanel → Emails में `info@…` mailbox बनाकर `SMTP_PASS` भरें। Razorpay की लाइनें खाली रहने दें। यह फ़ाइल **`public_html` के अंदर नहीं, उसके एक फ़ोल्डर ऊपर** (`domains/preritwelfarefoundation.org/`) अपलोड करें।
8. **SSL:** hPanel → Security → **SSL** active → **Force HTTPS** ON।
9. **Installer:** `https://preritwelfarefoundation.org/install.php` → checklist हरी हो → INSTALL_KEY → पहला **Administrator** (12+ अक्षर का मज़बूत पासवर्ड, उसमें नाम/email का हिस्सा नहीं) → "Installation complete"। Installer database को नवीनतम version तक अपने-आप अपडेट करता है।
10. **Installer बंद करें:** `pwf-config.php` में `define('INSTALL_KEY', '');` और `public_html` से `install.php` डिलीट करें।
11. **Admin में:** `/admin/` → My Profile → **Two-step login** ON → Settings (logo, फ़ोन/WhatsApp, पता, NGO registration जाँचें, social links, Email → Send test email) → फिर ऊपर भाग A के स्टेप 5-12 (Razorpay की **नई** secret, seal, hero images, Programme Categories/Events, campaigns publish, वैकल्पिक AI/Analytics)।
12. **जाँच:** homepage, footer में CIN/NGO ID/MSME, top bar में "Programs & Events", `/donate/` पर form, `/volunteer-teacher/` पर terms pop-up, और ₹1-10 का एक असली test donation → receipt email।

**दिक्कतें:**
- "We will be back shortly" → database नाम/user/पासवर्ड गलत; hPanel में DB user का पासवर्ड reset करके वही `pwf-config.php` में डालें (`install.php` पर सही error दिखता है)।
- 500 error → PHP 8.1/8.2 चुनें।
- Homepage चले पर बाकी pages 404 → `.htaccess` अपलोड नहीं हुई।
- Installer "Forbidden" → admin पहले से बन चुका है, सीधे `/admin/` खोलें।
