/**
 * 🔄 حارس: الاستطلاع مايرسمش على الفاضي — واللي المستخدم بيكتبه مايتمسحش.
 *
 * ═══ البلاغ (صاحب النظام 2026-09-12) ═══
 * «في بوابة المحلات لما بعدّل سعر كذا طرد السعر بيرجع تلقائي لسعر المنطقة
 *  كل خمس ثواني أو عشرة… حاسس إن السيستم بيرسم نفسه كل شوية لوحده».
 * وبعدها: «السيستم كله في نفس المشكلة».
 *
 * السبب الجذري في `api.js`: البولر بـ`useSince:false` (المناطق · الفروع ·
 * الإعدادات · المحفظة) السيرفر عمره ما بيرجّعله `changed:false`، فكان بينده
 * `onChange` **كل دورة** والرد هو هو — وكل معالج بيعيد رسم منطقته من
 * الأول. في المحلات: `applyPickupZone` → `onZone(n)` لكل طرد → السعر يرجع
 * لسعر المنطقة. الإصلاح في مكانين:
 *   ① `Poller`: نفس الرد بالحرف (من غير `serverNow`) = مفيش نداء.
 *   ② `applyPickupZone`: لو المنطقة ماتغيّرتش بنحدّث الأرضية بس، مش السعر.
 *   ③ دمشق: `serverNow` كان داخل بصمة الشاشة فالمقارنة كانت بتفشل دايمًا.
 *
 * ⚠️ `force` (poke بعد كتابة) لازم يفضل بينادي دايمًا — المستدعي عايز رسمة
 *    أكيدة بعد ما كتب. لو اتخطّى، الشاشة تفضل قديمة بعد الحفظ.
 *
 * التشغيل: node ops/test_poll_nowipe.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

console.log('\n══ ① Poller — تنفيذ فعلي في صندوق ══');
const el = () => ({ style: {}, appendChild() {}, addEventListener() {}, setAttribute() {}, classList: { add() {}, remove() {}, toggle() {} }, textContent: '', innerHTML: '', onclick: null, title: '' });
const document = { hidden: false, body: el(), addEventListener() {}, getElementById: () => el(), createElement: el };
const window = { document, location: { hostname: 'x' }, addEventListener() {} };
let payload = { ok: true, serverNow: 1, items: [{ id: 1, price: 30 }] };
window.fetch = async () => ({ ok: true, status: 200, json: async () => JSON.parse(JSON.stringify(payload)) });
const SRC = fs.readFileSync('public/assets/js/api.js', 'utf8');
new Function('window', 'document', 'fetch', 'AbortController', SRC)(window, document, window.fetch, undefined);
const API = window.API;
ok('الـPoller بيقبل alwaysFire', /this\.alwaysFire = !!options\.alwaysFire;/.test(SRC));

(async () => {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  let calls = 0;
  const p = new API.Poller('/api/zones', { interval: 999999, useSince: false, immediate: false, onChange: () => calls++ });

  await p.tick(); await p.tick(); await p.tick();
  ok('🔴 نفس الرد ٣ مرات = نداء واحد بس', calls === 1, 'نداءات=' + calls);

  payload = { ...payload, serverNow: 2 };
  await p.tick();
  ok('🔴 تغيّر serverNow بس = مفيش نداء (مش تغيير حقيقي)', calls === 1, 'نداءات=' + calls);

  payload = { ...payload, items: [{ id: 1, price: 35 }] };
  await p.tick();
  ok('تغيير حقيقي في البيانات = نداء', calls === 2, 'نداءات=' + calls);

  await p.tick(true);
  ok('⚠️ force بينادي دايمًا (بعد كتابة المستخدم عايز رسمة أكيدة)', calls === 3, 'نداءات=' + calls);

  let always = 0;
  const q = new API.Poller('/api/x', { interval: 999999, useSince: false, immediate: false, alwaysFire: true, onChange: () => always++ });
  await q.tick(); await q.tick();
  ok('و alwaysFire بينادي كل دورة', always === 2, 'نداءات=' + always);

  await sleep(10);

  console.log('\n══ ② بوابة المحلات — السعر مايتلمسش لو المنطقة هي هي ══');
  const S = fs.readFileSync('public/store.html', 'utf8');
  ok('🔴 applyPickupZone مابتنديش onZone لو المنطقة ماتغيّرتش',
    /if \(keep && stillValid\) refreshPriceFloor\(n\);\s*\n\s*else onZone\(n\);/.test(S),
    'onZone بتكتب سعر المنطقة فوق اللي المحل كتبه');
  ok('و refreshPriceFloor بتحدّث الأرضية من غير القيمة',
    /function refreshPriceFloor\(n\) \{[\s\S]{0,500}el\.dataset\.floor = String\(price\);/.test(S)
    && !/function refreshPriceFloor\(n\) \{[\s\S]{0,400}if \(can[^\n]*el\.value = /.test(S));
  ok('⚠️ والمحل المقفول عن التعديل بياخد سعر المنطقة الجديد',
    /if \(!can && price\) el\.value = String\(price\);/.test(S));
  ok('🔴 ونسخة بوابة المحلات اتحرّكت (api.js اتغيّر)',
    (() => { const m = S.match(/<meta name="app-version" content="(\d+)\.(\d+)\.(\d+)"/); return !!m && (+m[1] * 1e6 + +m[2] * 1e3 + +m[3]) >= 1001007; })());
  const C = fs.readFileSync('public/customer.html', 'utf8');
  ok('🔴 ونسخة تطبيق العميل كمان', /content="1\.7\.7"/.test(C));

  console.log('\n══ ③ دمشق — البصمة من غير serverNow ══');
  const D = fs.readFileSync('public/damascus.html', 'utf8');
  ok('🔴 stripVolatile بتشيل serverNow قبل التبصيم',
    /function stripVolatile\(o\) \{[\s\S]{0,200}delete c\.serverNow; delete c\.changed;/.test(D),
    'serverNow بيتغيّر كل ثانية — البصمة كانت بتفشل دايمًا');
  ok('وداخلة على day/sheet/month',
    /daily   : \(\) => \[stripVolatile\(S\.day\)/.test(D)
    && /pilot   : \(\) => \[stripVolatile\(S\.sheet\)/.test(D)
    && /month   : \(\) => \[stripVolatile\(S\.month\)/.test(D));

  console.log('\n══ ④ الإدارة والكول سنتر — الاختيار مايتمسحش مع إعادة الرسم ══');
  for (const f of ['public/tiar.html', 'public/callcenter.html']) {
    const X = fs.readFileSync(f, 'utf8');
    const p = f.replace('public/', '');
    ok(`${p}: 🔴 فورم النقل المباشر بيقرا الاختيار قبل الرسم ويرجّعه`,
      /const _keepPilot  = document\.getElementById\("directMovePilot"\)\?\.value/.test(X)
      && /if \(_keepPilot\) \{[\s\S]{0,200}ps\.value = _keepPilot;/.test(X)
      && /if \(_keepBranch\) \{[\s\S]{0,120}bs\.value = _keepBranch;/.test(X),
      'الطيار والفرع كانوا بيتمسحوا كل دورة');
    ok(`${p}: وفرع النقل محفوظ جوه populateDirectMoveSelects كمان`,
      /const curB = branchSel\.value;/.test(X) && /if \(curB\) branchSel\.value = curB;/.test(X));
    ok(`${p}: 🔴 موظف الحضور اليدوي بيتحفظ والقايمة المفتوحة مابتتلمسش`,
      /if \(document\.activeElement === sel\) return;\s*\n\s*const cur = sel\.value;/.test(X)
      && /if \(cur\) sel\.value = cur;\s*\n\s*\}/.test(X));
    ok(`${p}: 🔴 مربعات الأوردرات بتتلمّ قبل الرسم وبتترجع بعده`,
      /const __chk = new Set\(\[\.\.\.document\.querySelectorAll\("\.row-chk:checked"\)\]/.test(X)
      && /window\.onOrderCheckChange && window\.onOrderCheckChange\(\)/.test(X),
      'الشريط بيقول «مختارين» والزرار مايعملش حاجة');
  }

  console.log('\n══ ⑤ والتاب ظاهر — الرد اللي بيتغيّر من غير بيانات جديدة ══');
  const TI = fs.readFileSync('public/tiar.html', 'utf8');
  ok('🔴 الإدارة: الحضور مايترسمش على نبضة lastSeen',
    /const _attSig = days => \{ try \{ return JSON\.stringify\(days, \(k, v\) => k === "lastSeen" \? undefined : v\); \}/.test(TI)
    && /if \(sig !== null && sig === window\._attSig\) return;/.test(TI),
    'صفحة الحضور مش بتعرض lastSeen أصلًا — كانت بتتعاد كل ١٥ث على الفاضي');
  const PL = fs.readFileSync('public/pilots.html', 'utf8');
  ok('🔴 لوحة الطيارين: التبويب مايترسمش على تحرّك موقع',
    /const _pilotsSig = list => \{ try \{ return JSON\.stringify\(list\.map\(\(\{ location, trail, locationUpdatedAt, heading, speed, updatedAt, lastSeen, \.\.\.rest \}\) => rest\)\); \}/.test(PL)
    && /if \(S\.tab !== "map" && sig !== null && sig === S\._pilotsSig\) return;/.test(PL),
    'كل تحرّك طيار كان بيعيد رسم التبويب المفتوح');
  ok('⚠️ والخريطة لسه بتترسم دايمًا (هي اللي محتاجة المواقع)', /S\.tab !== "map" &&/.test(PL));

  console.log('\n' + '─'.repeat(52));
  console.log(fail === 0 ? `✅ عدّى ${pass} فحص — الرسم لما يبقى فيه تغيير فعلًا\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
  process.exit(fail === 0 ? 0 : 1);
})();
