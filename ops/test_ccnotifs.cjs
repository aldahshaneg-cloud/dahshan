/**
 * 💬 حارس: صفحة «رسايل العملاء» في الكول سنتر.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «اعمل الصفحة الخاصة برسايل العملاء في برنامج الكول سنتر بحيث يبعت هو
 * الرسايل — حتى نعملها أتوميشن في المستقبل».
 *
 * نقل حرفي لشاشة الإدارة (طابور order_notifications) — الفحوص بتثبّت
 * القواعد اللي لو راحت الشاشة تكدب:
 * ① «فتحت» ≠ «بعت»: زرار «اتبعت» مقفول لحد ما «افتح واتساب» يتضغط.
 * ② صيغة الرقم: "2" **قدّام** الرقم والصفر بيفضل (استبدال الصفر = رقم
 *    ١١ خانة واتساب بيرفضه — باج اتصلح قبل كده على السيرفر).
 * ③ مافيش onclick مبني بالنص للصفوف — data-* + ربط بعد الرسم.
 * ④ الحالة النهائية من رد السيرفر مش تخمين.
 *
 * التشغيل: node ops/test_ccnotifs.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const CC = fs.readFileSync('public/callcenter.html', 'utf8');
const cut = (start, end) => {
  const i = CC.indexOf(start);
  if (i < 0) return '';
  const j = CC.indexOf(end, i + start.length);
  return j < 0 ? CC.slice(i) : CC.slice(i, j);
};

console.log('\n══ 1) الصفحة موصولة ══');
ok('في قايمة الصفحات', /"ccperf","ccnotifs",/.test(CC));
ok('وزرار التنقل بعدّاد', CC.includes(`navigateTo('ccnotifs')`) && CC.includes('id="ccNotifBadge"'));
ok('والماركب كامل', ['page-ccnotifs', 'ccNotifBody', 'ccNotifTabs', 'ccNotifPendingStat']
  .every(id => CC.includes('id="' + id + '"')));
ok('والتبويبات الخمسة', ['pending', 'sent', 'failed', 'skipped', 'all']
  .every(k => CC.includes('data-ccnf="' + k + '"')));
ok('والـCSS معرّف مش كلاسات هوا',
  /\.notif-btn \{/.test(CC) && /\.notif-wa \{/.test(CC) && /\.notif-ok \{/.test(CC));

console.log('\n══ 2) قاعدة الزرارين ══');
const rowFn = cut('function ccNotifRowHTML(n)', '\n    }');
ok('🔴 «اتبعت» مقفول لحد ما واتساب يتفتح',
  /data-ccnsent="\$\{ esc\(n\.id\) \}"\$\{ opened \? "" : " disabled" \}/.test(rowFn),
  'الموظف هيعلّم مبعوت من غير ما يفتح');
const openFn = cut('window.ccOpenNotifWhatsApp = function', '\n    };');
ok('و«افتح واتساب» مش بيلمس القاعدة — بيفتح ويعلّم محليًا بس',
  /window\.open\(/.test(openFn) && !/api\.post/.test(openFn));
ok('وحاجب النوافذ متمسك', /if \(!w\) \{ showToast/.test(openFn));
const sentFn = cut('window.ccMarkNotifSent = async function', '\n    };');
ok('🔴 والحالة النهائية من رد السيرفر مش تخمين',
  /Object\.assign\(n, d\.message \|\| \{ status: "sent" \}\)/.test(sentFn),
  'لو موظف تاني علّمها هيبان اسم غلط');
ok('والفتح المحفوظ بيتشال بعد التعليم', /_ccNotifOpened\.delete/.test(sentFn));

console.log('\n══ 3) صيغة الرقم ══');
const phoneFn = cut('function _ccNotifWaPhone(phone)', '\n    }');
ok('🔴 الـ"2" قدّام والصفر بيفضل مكانه',
  /if \(p\.startsWith\("0"\)\) p = "2" \+ p;/.test(phoneFn),
  'استبدال الصفر = رقم ١١ خانة واتساب بيرفضه');
ok('والرقم القصير بيترفض', /p\.length >= 10 \? p : ""/.test(phoneFn));

console.log('\n══ 4) الأمان والرسم ══');
ok('🔴 مافيش onclick مبني بالنص في الصفوف — data-* وربط بعد الرسم',
  /querySelectorAll\("\[data-ccnwa\]"\)\.forEach/.test(CC)
  && /querySelectorAll\("\[data-ccnsent\]"\)\.forEach/.test(CC)
  && !/onclick="ccOpenNotifWhatsApp/.test(CC));
const renderFn = cut('window.ccRenderNotifs = function', '\n    };');
ok('وكل قيمة بتعدّي على esc', (rowFn.match(/esc\(/g) || []).length >= 8,
  String((rowFn.match(/esc\(/g) || []).length));
ok('والأحدث الأول', /_ccNotifWhen\(b\) - _ccNotifWhen\(a\)/.test(renderFn));

console.log('\n══ 5) الاستطلاع ══');
ok('بولر مقيّد بالجلسة (ccPoller) كل ٣٠ث',
  /_ccNotifPoller = ccPoller\("\/api\/order-notifications", \{\n      interval: 30000/.test(CC)
  || /ccPoller\("\/api\/order-notifications"/.test(CC));
ok('والعدّاد من رد السيرفر مش من طول القايمة',
  /Number\.isFinite\(d\.pending\)/.test(CC));
ok('ومودال ما-بعد-الإنشاء الموجود مااتلمسش',
  /window\.openWaNotifyForOrder = async function/.test(CC));

console.log('\n══ 6) الصلاحيات ══');
/* 🔴 الواقعة (2026-09-01 مساءً): الصفحة اشتغلت للأدمن واختفت عن الموظفين —
   قوايم الصفحات الصريحة (user_page_permissions) مابتورثش الجديد، والصفحة
   ماكانتش في كتالوج المحرر أصلًا فمافيش طريقة تتدّى لحد. الفحص بيثبت
   إنها في الكتالوج، والموظفين الحاليين اتمنحوها بسكربت مرة واحدة. */
const TIAR = fs.readFileSync('public/tiar.html', 'utf8');
ok('🔴 الصفحة في كتالوج صلاحيات الكول سنتر — الأدمن يقدر يدّيها ويشيلها',
  /\["ccnotifs","رسايل العملاء"\]/.test(TIAR),
  'موظف بقايمة صريحة عمره ما هيشوفها');

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — صفحة الرسايل في الكول سنتر\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
