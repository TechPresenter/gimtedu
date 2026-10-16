<?php
/**
 * Content corrections made in the final v2 package (wording, 80G/FCRA compliance, consent). Used by
 * migration_100_v2_final_catchup(): a section is updated ONLY when it is still byte-identical to the earlier
 * package's version (i.e. nobody has edited it in the admin panel).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

return array (
  0 => 
  array (
    'page' => 'become-a-volunteer',
    'type' => 'faqs',
    'sort' => 90,
    'early_md5' => '085fbc3f1d723c48925893cc92029aa2',
    'set' => 
    array (
      'title' => 'Questions about volunteering',
      'intro' => NULL,
      'body' => NULL,
      'items' => '[{"q":"Who can volunteer with the foundation?","a":"Anyone who shares our values and can give some time regularly - students, working professionals, homemakers and retired people. Our coordinator will discuss which activities suit your skills, age and availability."},{"q":"Do I need previous experience?","a":"No. Most activities need enthusiasm and reliability more than experience. You attend an orientation first and work alongside experienced team members."},{"q":"How much time do I need to give?","a":"As much as you can offer regularly - a few hours a month, on weekends or on weekdays. Choose your availability in the form. Some roles, such as design or social media, can also be done online."},{"q":"Is volunteering a paid role?","a":"Volunteering is not a paid role. If you are looking for a job with the foundation, please see the Volunteer Staff Application page (Job Apply in the top bar)."},{"q":"Will I receive a certificate?","a":"Yes. After verified service, volunteers can receive a certificate issued by the foundation. Approved certificates appear in your dashboard when you have a website account with the same email address."},{"q":"How do I check my application?","a":"Use the reference number from your acknowledgement email on the Application Status page, together with the email address or mobile number you used when applying."}]',
      'settings' => '{"width":"narrow","open_first":true}',
      'image' => NULL,
      'pill' => 'FAQ',
      'background' => 'cream',
    ),
  ),
  1 => 
  array (
    'page' => 'contact',
    'type' => 'features',
    'sort' => 30,
    'early_md5' => 'ecc10e4fae364e08865002180309b775',
    'set' => 
    array (
      'title' => 'Work with us',
      'intro' => 'We welcome organisations, institutions and media who share our commitment to communities.',
      'body' => NULL,
      'items' => '[{"icon":"briefcase","title":"Partnerships & CSR","text":"Companies and foundations can collaborate on education, health, women empowerment, environment and skill development. Choose \\"Partnership / CSR collaboration\\" in the form and tell us about your interest.","tone":"green"},{"icon":"book","title":"Schools & communities","text":"Invite us for awareness sessions, health camps, plantation drives or learning activities. Share the topic, place and possible dates.","tone":"blue"},{"icon":"megaphone","title":"Media","text":"Journalists and content creators can choose \\"Media / press\\" in the form for information about our work and programmes.","tone":"gold"}]',
      'settings' => '{"cols":"3"}',
      'image' => NULL,
      'pill' => 'Partnerships',
      'background' => 'white',
    ),
  ),
  2 => 
  array (
    'page' => 'donate',
    'type' => 'steps',
    'sort' => 40,
    'early_md5' => '0da7391acde4e4b0bd6bd75edd8f8b93',
    'set' => 
    array (
      'title' => 'How online donations are verified',
      'intro' => NULL,
      'body' => NULL,
      'items' => '[{"icon":"lock","title":"Pay on Razorpay","text":"When online donation is open, you pay on Razorpay\'s secure checkout - card, UPI or bank details are never entered on our website."},{"icon":"shield","title":"We verify the payment","text":"Our server checks the payment signature and confirms the amount with Razorpay."},{"icon":"file-text","title":"Receipt issued","text":"A receipt is generated once the payment is verified, and the link is emailed to you."},{"icon":"pie-chart","title":"Track the impact","text":"Follow campaign updates and the expenses we publish in utilisation reports."}]',
      'settings' => '{"requires":"online_donations"}',
      'image' => NULL,
      'pill' => 'Safe giving',
      'background' => 'cream',
    ),
  ),
  3 => 
  array (
    'page' => 'donate',
    'type' => 'callout',
    'sort' => 50,
    'early_md5' => 'afcdad2a0a38a5a90bf77424dc032ed8',
    'set' => 
    array (
      'title' => 'Beware of fraud',
      'intro' => 'Donate only through this website or to the foundation\'s own bank account - never to a personal account or to details shared by an unknown caller. If no bank details are shown on the Donate page, write to us before transferring any money. Our team will never ask for your OTP, PIN or card details over a call or message.',
      'body' => NULL,
      'items' => NULL,
      'settings' => '{"tone":"gold","icon":"alert-triangle","width":"narrow"}',
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  4 => 
  array (
    'page' => 'donate',
    'type' => 'faqs',
    'sort' => 80,
    'early_md5' => '4768e0ea47f58d366e6af71d55c7586f',
    'set' => 
    array (
      'title' => 'Donation questions',
      'intro' => NULL,
      'body' => NULL,
      'items' => '[{"q":"Is it safe to donate online?","a":"Yes. When online donation is open on this page, payments are processed by Razorpay. Your card, UPI or bank details are entered on Razorpay secure checkout and never reach our website. We confirm every payment with Razorpay on our server before counting it."},{"q":"When will I get my receipt?","a":"For online donations, as soon as the payment is verified - usually within a minute. Bank transfers and cheques are receipted after our finance team has matched them with the bank statement. The receipt link is shown on the thank-you page and emailed to you. If you donated while signed in, it is also available in your dashboard."},{"q":"Can I claim a tax deduction under Section 80G?","a":"A deduction under Section 80G is available only if the foundation holds a valid 80G registration. When a registration number is available it is shown on the donation page and printed on the receipt. The deduction is claimed on the basis of the Form 10BE certificate that the foundation issues after filing its statement of donations (Form 10BD), so please give your PAN when donating. Cash donations above ₹2,000 do not qualify for deduction under Section 80G."},{"q":"Can I donate if I am not an Indian citizen or live outside India?","a":"Indian citizens, including those living abroad, can donate from their own funds through Indian bank accounts, cards or UPI. Unless an FCRA registration is listed on our NGO Details page, the foundation cannot accept foreign contributions - donations from foreign nationals (including OCI card holders), foreign companies or any other foreign source - under the Foreign Contribution (Regulation) Act, 2010. If you are unsure, please contact us before donating."},{"q":"Money was deducted but I did not see a thank-you page. What should I do?","a":"Do not worry. If the payment was captured, it will be confirmed automatically when Razorpay notifies our server. If it was not captured, your bank will reverse it as per its usual timelines. You can contact us with your donation reference at any time."},{"q":"Can I donate anonymously?","a":"Yes. Tick the anonymous option on the form and your name will not appear in the public list of supporters. We still need your details to issue a receipt."},{"q":"What is your refund policy?","a":"Please read our Donation & Refund Policy. If you made a mistake, for example a duplicate payment or a wrong amount, contact us quickly with your donation reference."}]',
      'settings' => NULL,
      'image' => NULL,
      'pill' => 'FAQs',
      'background' => 'white',
    ),
  ),
  5 => 
  array (
    'page' => 'donation-refund-policy',
    'type' => 'richtext',
    'sort' => 20,
    'early_md5' => '82254c9815bf5e156b1365878b0c72be',
    'set' => 
    array (
      'title' => 'How you can donate',
      'intro' => NULL,
      'body' => '<h3>Online</h3>
<p>When online donation is available, donate on the <a href="/donate/">Donate</a> page or on a campaign page. Payments are processed securely by our payment gateway, Razorpay, using UPI, cards, net banking or other methods offered in the checkout. Your card and UPI details are never seen or stored by the foundation.</p>
<h3>Offline</h3>
<p>Bank transfers and cheques are accepted only to the bank details published by the foundation on the Donate page. Never transfer money to a personal account or to account details sent to you by phone, WhatsApp, email or social media. After an offline donation, please write to us with the date, amount and transaction reference or cheque number so that we can record it.</p>
<h3>Who can donate</h3>
<p>Unless an FCRA registration is shown on our <a href="/ngo-details/">NGO Details</a> page, the foundation cannot accept foreign contributions under the Foreign Contribution (Regulation) Act, 2010. We can therefore accept donations only from Indian citizens, including Indian citizens living abroad who give from their own funds through Indian bank accounts, cards or UPI. We cannot accept donations from foreign nationals (including OCI card holders), foreign companies, foreign trusts or any other foreign source. Even when an FCRA registration is listed, foreign contributions can be received only in our designated FCRA bank account, so please contact us first. A foreign contribution received by mistake will be returned to the original payment method.</p>',
      'items' => NULL,
      'settings' => NULL,
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  6 => 
  array (
    'page' => 'donation-refund-policy',
    'type' => 'richtext',
    'sort' => 40,
    'early_md5' => '43bf4a822d84f24c839972c8c620f68d',
    'set' => 
    array (
      'title' => 'Tax benefits',
      'intro' => NULL,
      'body' => '<p>A deduction under Section 80G of the Income Tax Act is available only if the foundation holds a valid 80G registration. When it does, the registration number is shown on the <a href="/ngo-details/">NGO Details</a> page and printed on your receipt, and your PAN is required. If no 80G registration is listed, no tax benefit should be assumed. Tax benefits are subject to the rules and limits of the Income Tax Act. Cash donations above ₹2,000 do not qualify for deduction under Section 80G. The deduction is claimed on the basis of the Form 10BE certificate that the foundation issues after filing its statement of donations in Form 10BD, so please give your PAN when donating.</p>',
      'items' => NULL,
      'settings' => NULL,
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  7 => 
  array (
    'page' => 'donation-refund-policy',
    'type' => 'richtext',
    'sort' => 70,
    'early_md5' => 'af66a7d2c897ebdec8161aa213456053',
    'set' => 
    array (
      'title' => 'Refunds',
      'intro' => NULL,
      'body' => '<p>Donations are generally not refundable, because they are applied to programmes once received. We will, however, refund:</p>
<ul>
<li><strong>duplicate payments</strong> - the same donation charged more than once;</li>
<li><strong>erroneous donations</strong> - for example, a wrong amount entered by mistake or a technical error during payment;</li>
<li><strong>unauthorised transactions</strong> - a payment made without the account holder\'s permission;</li>
<li><strong>donations from a foreign source</strong> that we are not permitted to accept.</li>
</ul>
<p>Please report the problem as soon as possible, preferably within 30 days of the transaction, so that we can check it with the payment gateway. Approved refunds are made <strong>to the original payment method through the payment gateway</strong>; we do not refund in cash or to a different account. The time the amount takes to reach you depends on your bank and the payment method. Any receipt issued for a refunded donation is cancelled.</p>',
      'items' => NULL,
      'settings' => NULL,
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  8 => 
  array (
    'page' => 'faq',
    'type' => 'cta',
    'sort' => 30,
    'early_md5' => 'b8cbdd1797958322d17f771afc5c558c',
    'set' => 
    array (
      'title' => 'Found what you were looking for?',
      'intro' => 'If you would like to help, you can donate, volunteer or share our programmes with people who need them.',
      'body' => NULL,
      'items' => NULL,
      'settings' => '{"hindi_tagline":true}',
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  9 => 
  array (
    'page' => 'home',
    'type' => 'hero_slider',
    'sort' => 10,
    'early_md5' => '9290f1c9a73da46d3043760a44ba8d71',
    'set' => 
    array (
      'title' => NULL,
      'intro' => NULL,
      'body' => NULL,
      'items' => '[{"eyebrow":"Prerit Welfare Foundation · New Delhi","title":"मिलकर बनाये बेहतर कल – Together, let us build a better tomorrow","text":"We work to empower underprivileged communities through education, healthcare, women empowerment, environmental care and skill development – so that everyone can live with dignity and equal opportunity.","image":"assets/images/hero/community.svg","image_alt":"","button1_label":"Donate Now","button1_url":"/donate/","button2_label":"Become a Volunteer","button2_url":"/become-a-volunteer/"},
{"eyebrow":"Education & literacy","title":"Every child deserves the chance to learn","text":"Through literacy drives, community learning circles, study material distribution and school support, we help children from underserved families keep learning.","image":"assets/images/programs/education.svg","image_alt":"","button1_label":"Our Programmes","button1_url":"/programs/","button2_label":"Teach with us","button2_url":"/volunteer-teacher/"},
{"eyebrow":"Women & youth empowerment","title":"Skills, confidence and opportunity for women and young people","text":"Skill development for women, Self-Help Group support, career guidance, mentoring and digital skills – and volunteers like you help make it possible.","image":"assets/images/programs/women-empowerment.svg","image_alt":"","button1_label":"Become a Volunteer","button1_url":"/become-a-volunteer/","button2_label":"Donate Now","button2_url":"/donate/"}]',
      'settings' => '{"label":"Highlights from our work"}',
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  10 => 
  array (
    'page' => 'home',
    'type' => 'marquee_cta',
    'sort' => 15,
    'early_md5' => 'db56049c53b1b9a3d8ae4db47da1f797',
    'set' => 
    array (
      'title' => NULL,
      'intro' => NULL,
      'body' => NULL,
      'items' => '["Education & Literacy","Healthcare","Women Empowerment","Youth Empowerment","Rural Development","Environment Protection","Skill Development","Digital Learning","Scholarships","Food Distribution","Disaster Relief"]',
      'settings' => '{"message":"Our areas of work","icon":"leaf","tone":"gold","size":"sm","buttons":[{"label":"Explore our programmes","url":"/programs/","style":"primary","size":"sm","icon":"arrow-right"}]}',
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  11 => 
  array (
    'page' => 'home',
    'type' => 'programs_grid',
    'sort' => 40,
    'early_md5' => 'aaaefa765a6a480537375dc68791a6d4',
    'set' => 
    array (
      'title' => 'Our programmes',
      'intro' => 'From classrooms to community health camps, our programmes respond to the everyday needs of the communities we serve.',
      'body' => NULL,
      'items' => NULL,
      'settings' => '{"limit":8,"home_only":true,"align":"center"}',
      'image' => NULL,
      'pill' => 'Our work',
      'background' => 'tint',
    ),
  ),
  12 => 
  array (
    'page' => 'impact-reports',
    'type' => 'link_cards',
    'sort' => 20,
    'early_md5' => '9cfcee17a41a9af941da925e06eac543',
    'set' => 
    array (
      'title' => 'Related reports',
      'intro' => NULL,
      'body' => NULL,
      'items' => '[{"icon":"pie-chart","title":"Campaign Utilisation Reports","text":"How campaign funds were used","url":"/utilization-reports/"},{"icon":"shield","title":"Financial Transparency","text":"Verified donation totals","url":"/financial-transparency/"},{"icon":"star","title":"Success Stories","text":"Stories shared with consent","url":"/success-stories/"},{"icon":"award","title":"Certificates","text":"Registrations and recognition","url":"/certifications/"}]',
      'settings' => NULL,
      'image' => NULL,
      'pill' => NULL,
      'background' => 'tint',
    ),
  ),
  13 => 
  array (
    'page' => 'privacy-policy',
    'type' => 'richtext',
    'sort' => 40,
    'early_md5' => '1ee86a45824f51d06e9eacd707b1ba15',
    'set' => 
    array (
      'title' => 'Consent and legal basis',
      'intro' => NULL,
      'body' => '<p>We process your personal data on the basis of your <strong>consent</strong>, which you give when you submit a form, create an account, donate or subscribe, and for the <strong>legitimate uses</strong> permitted under the DPDP Act - for example, using the details you voluntarily provided for the purpose you provided them, or complying with a legal obligation such as keeping donation records.</p>
<p>You may withdraw your consent at any time - for example, by unsubscribing from the newsletter with the link in any email, by changing your analytics choice with the Cookie settings link (when Google Analytics is in use), or by asking us to close your account. Withdrawal does not affect processing already carried out, and we may need to keep certain records where the law requires it.</p>',
      'items' => NULL,
      'settings' => NULL,
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  14 => 
  array (
    'page' => 'privacy-policy',
    'type' => 'richtext',
    'sort' => 60,
    'early_md5' => '2acae83f7755ce35b47b52f3e1efc3f4',
    'set' => 
    array (
      'title' => 'Website analytics and cookies',
      'intro' => NULL,
      'body' => '<h3>Privacy-friendly analytics</h3>
<p>We use our own first-party statistics to count page visits, the pages people find useful and how they reached us (for example a search engine or a campaign link). <strong>These statistics do not store your IP address</strong>: a visitor is represented by a code that changes every day and cannot be used to identify you. We respect your browser\'s "Do Not Track" and "Global Privacy Control" signals.</p>
<h3>Google Analytics (optional)</h3>
<p>If the foundation turns on Google Analytics, it loads only after you agree in the cookie notice. If you choose "No, thanks", it is not loaded and the website works fully.</p>
<h3>Cookies and browser storage</h3>
<ul>
<li><strong>Session cookie</strong> - keeps you signed in while you use the website and protects forms against misuse. It is deleted when you close the browser.</li>
<li><strong>"Remember me" cookie</strong> - only if you choose it when signing in, so you stay signed in on that device. It can be removed by signing out.</li>
<li><strong>Your choices</strong> - your analytics choice and whether you have closed a notice are remembered in your browser. If Google Analytics is turned on, you can change your analytics choice at any time with the "Cookie settings" link at the bottom of every page.</li>
<li><strong>Google Analytics cookies</strong> (_ga, _ga_*) - set only if Google Analytics is turned on and you choose "Allow analytics"; removed if you later change your choice to "No, thanks".</li>
</ul>
<p>We do not use advertising cookies, and we do not set any analytics cookies unless you allow Google Analytics.</p>',
      'items' => NULL,
      'settings' => NULL,
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  15 => 
  array (
    'page' => 'programs',
    'type' => 'cta',
    'sort' => 70,
    'early_md5' => '7b4919f20a4e5f8c04cb30febc9f8d31',
    'set' => 
    array (
      'title' => 'Find a way to support a programme',
      'intro' => 'Support a programme, give your time as a volunteer, or tell someone who needs help about our work.',
      'body' => NULL,
      'items' => '[]',
      'settings' => '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}',
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  16 => 
  array (
    'page' => 'schemes',
    'type' => 'schemes_list',
    'sort' => 40,
    'early_md5' => 'bceceb44b08feab1a30ca05eb43e4372',
    'set' => 
    array (
      'title' => 'Find a scheme',
      'intro' => 'Every scheme listed here has been checked against its official source.',
      'body' => NULL,
      'items' => '[]',
      'settings' => '{"limit":9}',
      'image' => NULL,
      'pill' => 'Directory',
      'background' => 'cream',
    ),
  ),
  17 => 
  array (
    'page' => 'utilization-reports',
    'type' => 'faqs',
    'sort' => 40,
    'early_md5' => 'bacca2eae44b2269c0990661e83b94e3',
    'set' => 
    array (
      'title' => 'Utilisation questions',
      'intro' => NULL,
      'body' => NULL,
      'items' => '[{"q":"Why can spending be lower than the amount received?","a":"Expenses are published only after bills are checked and approved, so recent spending may take some time to appear. Work may also still be in progress."},{"q":"Are bills published?","a":"Bills and invoices are kept on record for audit but are not published, to protect the privacy of suppliers and beneficiaries."},{"q":"Who approves expenses?","a":"Expenses are approved by a different staff member from the one who recorded or last edited them, and every approval is logged with the approver\'s name."}]',
      'settings' => NULL,
      'image' => NULL,
      'pill' => 'FAQs',
      'background' => 'white',
    ),
  ),
  18 => 
  array (
    'page' => 'video-gallery',
    'type' => 'callout',
    'sort' => 20,
    'early_md5' => '743af3e26c22680c3484918ff020a6cb',
    'set' => 
    array (
      'title' => 'Privacy-friendly videos',
      'intro' => 'Videos are hosted on YouTube. Until you press play, only a preview image is shown from the YouTube image server; the video player itself loads only when you press play, in the privacy-enhanced (youtube-nocookie.com) mode. People appear in our videos only with their consent.',
      'body' => NULL,
      'items' => NULL,
      'settings' => '{"tone":"blue","icon":"shield","width":"narrow","size":"sm"}',
      'image' => NULL,
      'pill' => NULL,
      'background' => 'white',
    ),
  ),
  19 => 
  array (
    'page' => 'volunteer-teacher',
    'type' => 'split',
    'sort' => 10,
    'early_md5' => '9e0910441fbc976d271a76bdb2f076dd',
    'set' => 
    array (
      'title' => 'Teach, mentor and inspire',
      'intro' => 'Through our education work, children and adult learners in underserved communities get help with reading, school subjects and life skills. Volunteer teachers make this possible.',
      'body' => '<p>You do not need to be a professional teacher. If you can explain a subject clearly, are patient with learners and can commit regular time, we would love to hear from you.</p>',
      'items' => '["College students and graduates","Working professionals and retired teachers","Homemakers with time to share","Anyone who enjoys teaching in Hindi, English or a local language"]',
      'settings' => '{"image_alt":"Illustration of a volunteer teacher with learners","buttons":[{"label":"Apply to teach","url":"#apply","style":"primary","icon":"send"},{"label":"Child Protection Policy","url":"/child-protection-policy/","style":"outline","icon":"shield"}]}',
      'image' => 'assets/images/hero/teacher.svg',
      'pill' => 'Volunteer Teacher Programme',
      'background' => 'white',
    ),
  ),
);
