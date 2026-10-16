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
   - इस zip में `install.php` नहीं है, इसलिए installer दोबारा नहीं आएगा।
3. **वेबसाइट एक बार खोलें:** `https://preritwelfarefoundation.org/` खोलते ही database अपने-आप अपडेट हो जाता है (नई tables, settings, pages)। पुराना कोई डेटा न हटता है, न बदलता है। जाँचने के लिए: Admin → System → **Audit Log** में "database_upgraded" दिखेगा।
4. **(सुझाव) `APP_KEY` जोड़ें:** `pwf-config.php` (public_html के बाहर वाली) में यह लाइन जोड़ें, कोई लंबी random value (40+ अक्षर/अंक) के साथ:
   `define('APP_KEY', 'यहाँ-लंबी-random-value');`
   Admin में डाली गई payment/AI keys इसी से encrypt होती हैं। इसे बाद में **कभी न बदलें** (बदलने पर keys दोबारा डालनी पड़ेंगी)।
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

1. **बैकअप लें:** hPanel → Websites → Manage → **Backups** → पुरानी साइट (files + database) का बैकअप।
2. **पुरानी WordPress फ़ाइलें हटाएँ:** File Manager → `public_html` → `wp-admin`, `wp-content`, `wp-includes`, `wp-*.php`, `index.php`, `.htaccess` आदि डिलीट करें (`.well-known` रहने दें)।
3. **नया Database बनाएँ:** hPanel → Databases → MySQL Databases → नया database + user (पासवर्ड में **सिर्फ़ अक्षर और अंक**)। पूरा नाम नोट करें (जैसे `u623595918_prerit`)।
4. **database.sql इम्पोर्ट करें:** phpMyAdmin → वही database → **Import** → `database.sql` → **Go**।
5. **फ़ाइलें अपलोड करें:** `public_html-upload.zip` को Hostinger के `public_html` में अपलोड करके **Extract** करें (छुपी `.htaccess` फ़ाइलें भी होनी चाहिए)।
6. **pwf-config.php बनाएँ:** `pwf-config.sample.php` को `pwf-config.php` नाम से कॉपी करें, `BASE_URL`, `DB_NAME`, `DB_USER`, `DB_PASS`, एक लंबी random `INSTALL_KEY` और `APP_KEY` भरें, और इसे **public_html के एक फ़ोल्डर ऊपर** अपलोड करें।
7. **Installer:** `https://preritwelfarefoundation.org/install.php` → INSTALL_KEY → पहला **Administrator** बनाएँ (कोई default पासवर्ड नहीं)। Installer database को नवीनतम version तक अपने-आप अपडेट करता है।
8. फिर `pwf-config.php` में `INSTALL_KEY` को `''` करें और `install.php` डिलीट करें।
9. **SSL:** hPanel में SSL और "Force HTTPS" चालू करें (या `public_html/.htaccess` में Force HTTPS वाली लाइनों से `#` हटाएँ)।
10. **Admin में:** `/admin/` → My Profile में **Two-step login** चालू करें → Settings में logo, फ़ोन/WhatsApp और सत्यापित रजिस्ट्रेशन विवरण भरें → ऊपर भाग A के स्टेप 5-12 करें।

**"We will be back shortly" दिखे तो:** database का नाम/यूज़र/पासवर्ड गलत है — hPanel में database user का पासवर्ड reset करके वही `pwf-config.php` में डालें।
