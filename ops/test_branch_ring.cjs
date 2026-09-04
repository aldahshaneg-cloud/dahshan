/**
 * 🔔 حارس رنين الفرع من التاب المخفي
 *
 * البلاغ (صاحب النظام 2026-09-01): «برنامج الفروع يجب أن يرن حتى والتابة
 * مفتوحة لكن المشرف على الفرع يفتح تابة أخرى».
 *
 * التلات فجوات اللي كانت بتمنع ده:
 *  ① شبكة الأمان (بولر الأوردرات) كانت بتقف مع التاب المخفي — لو
 *    الويبسوكت وقع مافيش أي مصدر يرن. → خيار `hiddenTick` في Poller.
 *  ② سياق صوت **جديد** كل رنّة: في تاب مخفي بيبدأ suspended وبيترفض،
 *    وكمان المتصفح بيحدّد ~٦ سياقات للصفحة — بعد ٦ رنات الصوت كان بيموت
 *    في صمت لباقي اليوم. → سياق واحد دائم بيتفتح بأول ضغطة.
 *  ③ مافيش إشعار نظام للأوردر الجديد (الاستعجال ليه واحد) — البانر جوه
 *    صفحة مش باينة. → notifyNewOrder زي نمط الاستعجال.
 *
 * التشغيل: node ops/test_branch_ring.cjs
 */
const fs = require('fs');

let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ 🔴 ' + what + (got ? '   ← ' + got : '')); }
};

const API = fs.readFileSync('public/assets/js/api.js', 'utf8');
const BR  = fs.readFileSync('public/branch.html', 'utf8');

console.log('══ 1) شبكة الأمان صاحية والتاب مخفي ══');
ok('Poller بيدعم hiddenTick', API.includes('this.hiddenTick = !!options.hiddenTick;'),
   'الخيار اتشال — بولر الأوردرات هيقف مع التاب المخفي');
ok('  ودورة المؤقّت بتحترمه',
   API.includes('if (document.hidden && !self.hiddenTick) return;'),
   'رجعت توقف الكل بلا استثناء');
ok('بولر أوردرات الفرع مفعّله',
   /new P\("\/api\/orders", \{ interval: 60000, hiddenTick: true/.test(BR),
   'الفرع رجع يسكت والتاب ورا');
ok('branch.html بيحمّل نسخة api.js الجديدة (كسر كاش)',
   BR.includes('src="assets/js/api.js?v='),
   'المشرف هياخد النسخة القديمة من الكاش');

console.log('\n══ 2) الصوت بسياق واحد دائم متفتّح ══');
ok('فيه سياق دائم بيتفتح بأول ضغطة',
   BR.includes('window._brAudioCtx = window._brAudioCtx || new (window.AudioContext'),
   'رجعنا لسياق جديد كل رنّة — بيترفض مخفي وبيموت بعد ٦ رنات');
ok('  وبيتعمله resume لو suspended',
   /if \(window\._brAudioCtx\.state === "suspended"\) window\._brAudioCtx\.resume\(\);/.test(BR));
ok('playNotificationSound بيستعمل السياق الدائم',
   /const ctx = window\._brAudioCtx\n\s*\|\| \(window\._brAudioCtx = new/.test(BR),
   'رجعت new AudioContext() جوّاها');
ok('  ومافيش سياق جديد بيتعمل لكل رنّة',
   !/const ctx = new \(window\.AudioContext/.test(BR));

console.log('\n══ 3) إشعار نظام للأوردر الجديد ══');
ok('notifyNewOrder معرّفة وبتفحص الإذن',
   /function notifyNewOrder\(o\) \{\n\s*if \(typeof Notification === "undefined" \|\| Notification\.permission !== "granted"\) return;/.test(BR));
ok('  وبتتنادى مع الرنّة والبانر',
   /playNotificationSound\(\);\n\s*showNewOrderBanner\(o\);[\s\S]{0,300}notifyNewOrder\(o\);/.test(BR),
   'الإشعار اتشال من مسار الوصول الحقيقي');
ok('  والضغطة بترجّع المشرف للتاب', /n\.onclick = function \(\) \{ try \{ window\.focus\(\); \}/.test(BR));
ok('  وبتاج لكل أوردر — مفيش تكديس', BR.includes('tag: "neworder-" + o.id'));
ok('طلب إذن الإشعارات موجود من الأصل', BR.includes('Notification.requestPermission()'));

console.log('\n════════════════════════════════════════');
console.log('BRANCH RING: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
