<?php
/**
 * WhatsApp AI chatbot (Module 9): default bot settings (group "whatsapp", INSERT IGNORE — admin values are never
 * overwritten), the WhatsApp texts in English / Hindi / Hinglish, the lead-qualification questions, and the starter
 * WhatsApp knowledge base. KB answers only restate what the GIMT website already publishes and use {placeholders}
 * that are filled from live settings at answer time (phone, emails, links, admission fee, payment modes, name / year of
 * establishment / affiliation / address and the program count per level for "About GIMT" …), so no fee, date or
 * promise is hard-coded. Answers that make claims the institute must confirm are seeded as "draft".
 *
 * Production data only, safe to re-run: settings use INSERT IGNORE and a starter answer is added only when no answer
 * with the same question exists, so answers the admissions team edited, verified, archived or used are never reset.
 * Demo bot sessions and turns live in database/seed/98_wa_5_bot.php (after the 98_wa_* contacts / inbox seeds).
 */
return function (array $opts): void {
    require_once APP_ROOT . '/app/services/whatsapp.php';

    // ------------------------------------------------------------------ settings (never overwrite)
    foreach (wa_bot_defaults() as $k => $v) {
        db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', ['whatsapp', $k, (string) $v]);
    }

    // ------------------------------------------------------------------ starter knowledge base (added when missing)
    $now = date('Y-m-d H:i:s');
    $kb = [
        ['admission', 'active', 'How do I apply for admission at GIMT?', 'admission process, how to apply, apply online, application process, admission kaise le, admission kaise milega, admission process kya hai, एडमिशन कैसे, आवेदन कैसे करें, प्रवेश प्रक्रिया',
            "Applying to *{short}* is simple:\n1️⃣ Choose your program and fill the online application form: {apply_url}\n2️⃣ Upload your documents (photo, ID proof, marksheets)\n3️⃣ Our admission team verifies your documents and schedules the next step (counselling / interview)\n4️⃣ Pay the admission fee after your offer letter to confirm your seat.\nApplying online is free.",
            "*{short}* में आवेदन करना आसान है:\n1️⃣ अपना कोर्स चुनें और ऑनलाइन आवेदन फ़ॉर्म भरें: {apply_url}\n2️⃣ अपने डॉक्यूमेंट्स अपलोड करें (फ़ोटो, आईडी प्रूफ़, मार्कशीट)\n3️⃣ हमारी एडमिशन टीम डॉक्यूमेंट्स की जाँच करके अगला चरण (काउंसलिंग / इंटरव्यू) तय करती है\n4️⃣ ऑफ़र लेटर मिलने के बाद एडमिशन फीस जमा करके अपनी सीट कन्फ़र्म करें।\nऑनलाइन आवेदन निःशुल्क है।"],
        ['documents', 'active', 'Which documents are required for admission?', 'documents required, documents needed, required documents, documents list, kaunse documents, documents kya lagenge, kya kya documents, दस्तावेज़, कौन से दस्तावेज, डॉक्यूमेंट्स',
            "Documents for the online application:\n• Passport-size photograph\n• Class 10 marksheet\n• Class 12 / Diploma marksheet (admit card if results are awaited)\n• Graduation marksheet / degree (for PG programs)\n• ID proof (Aadhaar / passport / any government photo ID)\n• Category certificate (only if you claim a reserved category)\nAt enrollment, bring the originals plus Transfer Certificate, Migration Certificate (if your board / university is different), Character Certificate and 4 passport-size photos.\n⚠️ Please don't send documents on WhatsApp — upload them in the application: {apply_url}",
            "ऑनलाइन आवेदन के लिए डॉक्यूमेंट्स:\n• पासपोर्ट साइज़ फ़ोटो\n• कक्षा 10 की मार्कशीट\n• कक्षा 12 / डिप्लोमा की मार्कशीट (रिज़ल्ट न आया हो तो एडमिट कार्ड)\n• ग्रेजुएशन मार्कशीट / डिग्री (PG कोर्स के लिए)\n• आईडी प्रूफ़ (आधार / पासपोर्ट / कोई सरकारी फ़ोटो आईडी)\n• कैटेगरी सर्टिफ़िकेट (केवल आरक्षित श्रेणी के लिए)\nएनरोलमेंट के समय मूल दस्तावेज़, ट्रांसफ़र सर्टिफ़िकेट, माइग्रेशन सर्टिफ़िकेट (बोर्ड / यूनिवर्सिटी अलग हो तो), कैरेक्टर सर्टिफ़िकेट और 4 पासपोर्ट साइज़ फ़ोटो लाएँ।\n⚠️ कृपया WhatsApp पर डॉक्यूमेंट्स न भेजें — उन्हें आवेदन में अपलोड करें: {apply_url}"],
        ['fees', 'active', 'Is there an application fee?', 'application fee, form fee, registration fee, form ki fees, application charges, आवेदन शुल्क, फॉर्म फीस',
            "No — applying online is free. The admission fee ({admission_fee} for most UG programs) is payable only after your application is approved and the offer letter is issued. The admissions office confirms the final fee structure.",
            "नहीं — ऑनलाइन आवेदन निःशुल्क है। एडमिशन फीस (अधिकांश UG कोर्स के लिए {admission_fee}) आवेदन स्वीकृत होने और ऑफ़र लेटर जारी होने के बाद ही देनी होती है। अंतिम फीस संरचना एडमिशन ऑफिस कन्फ़र्म करता है।"],
        ['payment', 'active', 'How can I pay the fees?', 'payment methods, payment mode, pay fees, pay the fees, fee payment, fees kaise jama kare, fees kaise jama karein, fees kaise bhare, fees kaise bharein, online payment, upi, फीस कैसे जमा करें, फीस जमा, भुगतान',
            "Fees can be paid by: {payment_modes}.\nAlways pay only through official GIMT channels and keep your receipt. GIMT never asks for your OTP, card PIN or password on WhatsApp. For payment help call {phone}.",
            "फीस इन माध्यमों से जमा की जा सकती है: {payment_modes}।\nहमेशा केवल GIMT के आधिकारिक माध्यम से भुगतान करें और रसीद संभाल कर रखें। GIMT कभी भी WhatsApp पर आपका OTP, कार्ड PIN या पासवर्ड नहीं माँगता। भुगतान में मदद के लिए {phone} पर कॉल करें।"],
        // applicants only: keywords about applying while Class 12 / final-year results are awaited (not "when will results come")
        ['admission', 'active', 'I am awaiting my Class 12 / final-year results. Can I still apply?', 'awaiting class 12 result, awaiting 12th result, awaiting my result, waiting for 12th result, waiting for class 12 result, waiting for my result, 12th appearing, appearing in 12th, appearing in class 12, apply before result, apply before 12th result, apply without result, 12th result nahi aaya, 12th ka result nahi aaya, 12वीं का रिज़ल्ट नहीं आया',
            "Yes. Apply with your latest marksheet or admit card: {apply_url}\nYour admission stays provisional until you submit the final marksheet before the {session} cut-off date.",
            "हाँ। अपनी नवीनतम मार्कशीट या एडमिट कार्ड के साथ आवेदन करें: {apply_url}\nअंतिम मार्कशीट {session} की निर्धारित तिथि से पहले जमा करने तक आपका एडमिशन प्रोविज़नल रहेगा।"],
        ['admission', 'active', 'How can I track my application?', 'application status, track application, my application, application number, application kahan tak pahuchi, आवेदन की स्थिति',
            "Your application number and acknowledgement are emailed to you after you apply. For a status update, call {phone} or email {admission_email} with your application number.",
            "आवेदन करने के बाद आपका आवेदन नंबर और पावती ईमेल पर भेजी जाती है। स्थिति जानने के लिए अपने आवेदन नंबर के साथ {phone} पर कॉल करें या {admission_email} पर ईमेल करें।"],
        ['result', 'active', 'Where can I see my exam result?', 'exam result, results, marksheet, see my result, check result, check my result, semester result, when will results, when will the result, when will my result, results be declared, result declared, results declared, result kab aayega, result kab ayega, result kab aaega, result kab declare hoga, result kahan dekhe, result kahan dekhein, result kaise dekhe, result kaise check kare, परिणाम, रिज़ल्ट कहाँ देखें, रिज़ल्ट कब आएगा, परिणाम कब आएगा',
            "Results are published in the GIMT Student Portal once they are declared: {portal_url}\nSign in with your student login to view your marksheet. Important exam notices are posted at {notices_url}\nEvery GIMT marksheet carries a QR code that anyone can scan to verify it.",
            "परिणाम घोषित होने पर GIMT स्टूडेंट पोर्टल पर प्रकाशित होते हैं: {portal_url}\nअपनी मार्कशीट देखने के लिए स्टूडेंट लॉगिन से साइन इन करें। परीक्षा से जुड़ी ज़रूरी सूचनाएँ {notices_url} पर दी जाती हैं।\nहर GIMT मार्कशीट पर एक QR कोड होता है जिसे स्कैन करके कोई भी उसे वेरिफ़ाई कर सकता है।"],
        ['exam', 'active', 'When are the exams? Where is the date sheet?', 'exam date, date sheet, datesheet, exam schedule, exam kab hai, paper kab hai, admit card, परीक्षा कब है, डेटशीट',
            "Exam date sheets and important exam notices are published on the GIMT notices page: {notices_url}\nStudents can also check the Student Portal: {portal_url}",
            "परीक्षा की डेटशीट और ज़रूरी सूचनाएँ GIMT नोटिस पेज पर प्रकाशित होती हैं: {notices_url}\nछात्र स्टूडेंट पोर्टल भी देख सकते हैं: {portal_url}"],
        ['certificate', 'active', 'How can a GIMT certificate be verified?', 'certificate verification, verify certificate, verify degree, certificate check, certificate verify kaise kare, प्रमाणपत्र सत्यापन',
            "Anyone can verify a GIMT certificate online at {verify_url} — enter the certificate number or scan the QR code printed on the certificate.",
            "कोई भी GIMT प्रमाणपत्र को {verify_url} पर ऑनलाइन वेरिफ़ाई कर सकता है — प्रमाणपत्र नंबर डालें या उस पर छपा QR कोड स्कैन करें।"],
        ['certificate', 'active', 'How do I get a bonafide certificate, TC or other documents from GIMT?', 'bonafide certificate, transfer certificate, tc, migration certificate, character certificate, duplicate marksheet, degree certificate, get my degree, get degree, provisional certificate, course completion certificate, get certificate, get a certificate, get my certificate, certificate chahiye, degree chahiye, बोनाफाइड, डिग्री सर्टिफ़िकेट',
            "For your degree or provisional certificate, a bonafide certificate, TC, migration or duplicate documents, please contact the GIMT student support office — call {phone} or email {email} ({office_hours}). Our team will guide you through the request.",
            "डिग्री या प्रोविज़नल सर्टिफ़िकेट, बोनाफाइड सर्टिफ़िकेट, TC, माइग्रेशन या डुप्लिकेट डॉक्यूमेंट के लिए GIMT स्टूडेंट सपोर्ट ऑफिस से संपर्क करें — {phone} पर कॉल करें या {email} पर ईमेल करें ({office_hours})। हमारी टीम आपकी मदद करेगी।"],
        ['general', 'active', 'How do I log in to the Student Portal?', 'student portal, portal login, student login, portal password, login nahi ho raha, स्टूडेंट पोर्टल',
            "The Student Portal is at {portal_url}. Use the student login shared by the institute after enrollment. If you cannot sign in, email {email} or call {phone} and our team will help you.",
            "स्टूडेंट पोर्टल {portal_url} पर है। एनरोलमेंट के बाद संस्थान द्वारा दिए गए स्टूडेंट लॉगिन का उपयोग करें। साइन इन न हो पाए तो {email} पर ईमेल करें या {phone} पर कॉल करें।"],
        ['contact', 'active', 'What are the office hours and contact details?', 'office hours, office timing, contact number, phone number, college timing, email id, सम्पर्क, ऑफिस का समय',
            "📞 {phone}\n✉️ {admission_email}\n🕘 {office_hours}\n📍 {address}",
            "📞 {phone}\n✉️ {admission_email}\n🕘 {office_hours}\n📍 {address}"],
        // institute overview ("Tell me about GIMT", "GIMT ke baare mein batao" — the bot's "about" intent uses it): every
        // line quotes a setting (name, year, affiliation, address) or the live program catalogue; a line whose setting is
        // empty is left out when the answer is sent
        ['about', 'active', 'Tell me about GIMT', 'about gimt, about the college, about the institute, about your college, what is gimt, gimt overview, gimt ke baare mein, college ke baare mein, institute ke baare mein, जीआईएमटी के बारे में, कॉलेज के बारे में, संस्थान के बारे में',
            "*{institute}* — {short} (established in {established})\n🎓 {affiliation}\n📍 {address}\n📚 {program_count} programs: {program_levels}\nMore about us: {about_url}\nAsk me about programs, fees, eligibility or the admission process.",
            "*{institute}* — {short} ({established} में स्थापित)\n🎓 मान्यता: {affiliation}\n📍 {address}\n📚 {program_count} प्रोग्राम: {program_levels_hi}\nअधिक जानकारी: {about_url}\nआप कोर्स, फीस, योग्यता या एडमिशन प्रक्रिया के बारे में पूछ सकते हैं।"],
        // claims the institute must confirm before the bot may use them (website defaults): kept as drafts
        ['admission', 'draft', 'Is there an entrance test?', 'entrance test, entrance exam, aptitude test, interview, cuet, cat, mat, entrance hai kya, प्रवेश परीक्षा',
            "Applicants take the GIMT Aptitude Test and a personal interview after document verification. Valid CUET, JEE Main, CAT, MAT or CMAT scores can be submitted in place of the aptitude test.",
            "दस्तावेज़ सत्यापन के बाद आवेदकों को GIMT एप्टीट्यूड टेस्ट और पर्सनल इंटरव्यू देना होता है। एप्टीट्यूड टेस्ट की जगह वैध CUET, JEE Main, CAT, MAT या CMAT स्कोर दिए जा सकते हैं।"],
        ['payment', 'draft', 'Can I pay the fee in instalments?', 'instalment, installment, emi, semester wise fees, education loan, kist, किस्त',
            "Tuition fees can be paid semester-wise, and education-loan assistance is available through partner banks. The admissions office confirms the instalment plan for your program.",
            "ट्यूशन फीस सेमेस्टर के अनुसार जमा की जा सकती है और पार्टनर बैंकों के ज़रिए एजुकेशन लोन सहायता उपलब्ध है। आपके कोर्स का किस्त प्लान एडमिशन ऑफिस कन्फ़र्म करता है।"],
    ];
    $existing = [];
    foreach (db_all('SELECT id, question, created_by, updated_by, verified_by FROM wa_bot_knowledge') as $r) {
        $existing[mb_strtolower(trim((string) $r['question']))] = $r;
    }
    foreach ($kb as [$cat, $status, $q, $kw, $en, $hi]) {
        $old = $existing[mb_strtolower($q)] ?? null;
        if ($old) {
            // a starter answer nobody has touched follows the current wording; anything a person created, edited or
            // verified is kept exactly as it is
            if ($old['created_by'] === null && $old['updated_by'] === null && $old['verified_by'] === null) {
                db_update('wa_bot_knowledge', ['category' => $cat, 'keywords' => $kw, 'answer_en' => $en, 'answer_hi' => $hi], 'id = ?', [(int) $old['id']]);
            }
            continue;
        }
        db_insert('wa_bot_knowledge', ['category' => $cat, 'question' => $q, 'keywords' => $kw, 'answer_en' => $en, 'answer_hi' => $hi, 'status' => $status,
            'verified_at' => $status === 'active' ? $now : null, 'created_at' => $now]);
    }
};
