/**
 * 🗂 حارس: طبقة القوائم الموحّدة (listview.js) — ٣٠ صف + كروت + فورم تفاصيل.
 *
 * طلب صاحب النظام (2026-09-10): كل قايمة في السيستم ماتعرضش غير ٣٠ صف
 * وزرارين ‹ › للباقي، وزرار يحوّل الصفوف لكروت، والضغط على الكرت يفتح
 * فورم فيه كل المعلومات. اتعملت كطبقة واحدة بتمسك أي جدول له عناوين
 * أعمدة بدل تعديل ١١٣ دالة رسم — فالحارس بيثبت إن الطبقة موجودة بقواعدها
 * وإنها متحمّلة في كل صفحة فيها جداول.
 *
 * التشغيل: node ops/test_listview.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};
const S = fs.readFileSync('public/assets/js/listview.js', 'utf8');

console.log('\n══ 1) القواعد ══');
ok('🔴 ٣٠ صف في الصفحة', /var PAGE = 30;/.test(S));
ok('الجداول الصغيرة (< ٦ صفوف) بتتساب زي ما هي', /var MIN_ROWS = 6;/.test(S));
ok('لازم عناوين أعمدة (thead) و٣ أعمدة على الأقل', /var MIN_COLS = 3;/.test(S) && /!t\.tHead/.test(S));
ok('صف «مفيش بيانات» (colspan) مش بيتحسب', /colSpan > 1\) continue;/.test(S));
ok('الطباعة بتعرض كل الصفوف وبتخفي الشريط', /@media print\{\.lv-bar,\.lv-cards\{display:none!important\}\.lv-hide\{display:table-row!important\}/.test(S));

console.log('\n══ 2) الشريط والتنقّل ══');
ok('زرارين ‹ › وعدّاد «الإجمالي | من-إلى»', /class="lv-prev"/.test(S) && /class="lv-next"/.test(S) && /"<b>" \+ total \+ "<\/b> \| " \+ \(start \+ 1\) \+ "-" \+ end/.test(S));
ok('زرارين كروت/جدول', /lv-cards-btn/.test(S) && /lv-table-btn/.test(S));
ok('الوضع (كروت/جدول) بيتحفظ لكل جدول في localStorage', /localStorage\.setItem\(LS_PREFIX \+ key, mode\)/.test(S));
ok('الصفوف برّه الصفحة بتتخفي بـdisplay:none (مش بتتشال — الرسم بتاع الصفحة بيفضل صاحب البيانات)', /\.lv-hide\{display:none!important\}/.test(S) && /classList\.toggle\("lv-hide", !on\)/.test(S));

console.log('\n══ 3) الكروت والفورم ══');
ok('الكرت من نفس الصف: عنوان + لحد ٤ حقول بأسماء الأعمدة', /fields\.length < 4/.test(S) && /openDetails\(table, row\)/.test(S));
ok('🔴 الفورم فيه كل الأعمدة + أزرار الإجراء منسوخة', /className = "lv-field"/.test(S) && /className = "lv-actions"/.test(S) && /cloneNode\(true\)/.test(S));
ok('الـid بيتشال من النسخ عشان مؤقّتات الصفحة تفضل تلاقي الأصل', /removeAttribute\("id"\)/.test(S));
ok('الجداول جوّه الفورم نفسه مابتتلمسش (مفيش حلقة)', /t\.closest\("\.lv-modal"\)/.test(S));
ok('الإغلاق بـEsc والخلفية', /e\.key === "Escape"/.test(S) && /e\.target === m\) closeDetails/.test(S));

console.log('\n══ 4) إعادة التطبيق مع كل رسم ══');
ok('MutationObserver على الصفحة كلها بتجميع', /new MutationObserver/.test(S) && /timer = setTimeout\(function \(\) \{ timer = null; var p = pend;/.test(S));
/* 🔴 2026-09-12: المراقب كان بيعيد التطبيق على **كل** الجداول مع أي تغيير في
   الصفحة (toast · عدّاد «آخر تحديث» كل ٥ث · مؤقّتات) — اتقاس على الإنتاج:
   كروت المناطق اتبنت ١٨٦ مرة في ٢٦ ثانية. بقى بنطاق: الجدول/القايمة اللي
   اتغيّرت بس، وأي حاجة تانية مايخصناش. */
ok('🔴 والمراقب بنطاق: التغيير جوه جدول → الجدول ده بس',
  /var tb = m\.target && m\.target\.nodeType === 1 && m\.target\.closest \? m\.target\.closest\("table"\) : null;/.test(S)
  && /if \(scope\.tables\.length \|\| scope\.lists\) schedule\(scope\);/.test(S),
  'أي تغيير في أي حتة كان بيرسم كل الجداول والقوايم');
ok('🔴 وبيكتب في الـDOM لما يتغيّر بس (العدّاد والكروت)',
  /if \(countEl\.innerHTML !== countHtml\) countEl\.innerHTML = countHtml;/.test(S)
  && /if \(box\.__lvSig !== csig\) \{ renderCards\(table, visible, box\); box\.__lvSig = csig; \}/.test(S),
  'الكتابة نفسها تغيير بيوصل لمراقبين تانيين');
ok('والقوايم (div) بنفس الحماية',
  /if \(!hasGen \|\| box\.__lvGenSig !== gsig\)/.test(S) && /countEl2\.innerHTML !== countHtml2/.test(S));
ok('وLISTVIEW.rescan لسه مسح كامل', /rescan: scan,/.test(S) && /if \(!scope \|\| scope\.full\) \{/.test(S));
ok('وبيتجاهل تغييراته هو (مفيش حلقة رسم)', /\.lv-bar,\.lv-cards,\.lv-modal,\.lv-gen,#lv-style/.test(S));
ok('المفتاح ثابت حتى لو الجدول اتعاد بناؤه (أقرب id + ترتيب)', /dataset\.lvKey/.test(S) && /"#" \+ idx/.test(S));

console.log('\n══ 4ب) قوايم العناصر — الصفحات اللي قوايمها مش جداول ══');
ok('🔴 محوّلات pilots/customers/stores موجودة', /"pilots\.html": \[/.test(S) && /"customers\.html": \[/.test(S) && /"stores\.html": \[/.test(S));
ok('العناصر برّه الصفحة بتتخفي بنفس lv-hide (٣٠ في الصفحة)', /items\[i\]\.classList\.toggle\("lv-hide", !on\)/.test(S));
ok('زرار الجدول بيولّد جدول من الحقول (lv-gen) ومش بيتلمس تاني (data-lv-skip)', /className = "tbl lv-gen"/.test(S) && /setAttribute\("data-lv-skip", "1"\)/.test(S));
ok('الضغط على العنصر بيفتح الفورم إلا لو الصفحة ليها تفاصيلها (clickItem)', /if \(cfg\.clickItem\) \{ el\.click\(\); return; \}/.test(S) && /!cfg\.clickItem && !box\._lvClick/.test(S));
ok('customers/stores بيحتفظوا بضغطة الصف بتاعتهم (clickItem: true)', /key: "customers", clickItem: true/.test(S) && /key: "stores", clickItem: true/.test(S));
for (const f of ['pilots','customers','stores','home','site-admin']) ok(f + '.html بتحمّل الطبقة', fs.readFileSync('public/' + f + '.html','utf8').includes('assets/js/listview.js'));

console.log('\n══ 5) متحمّلة في كل صفحة فيها جداول بعناوين ══');
for (const f of fs.readdirSync('public').filter(x => /\.html$/.test(x))) {
  const t = fs.readFileSync('public/' + f, 'utf8');
  const tables = (t.match(/<thead/g) || []).length;
  if (tables >= 3 && t.includes('assets/js/api.js')) // لوحات الطاقم
    ok(`${f} (${tables} جدول)`, t.includes('assets/js/listview.js'), 'مش متحمّلة');
}

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — القوائم ٣٠ صف وكروت وفورم\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
