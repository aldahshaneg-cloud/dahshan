/* 🎨 ترتيب شاشة صلاحيات «تقفيل الطيارين» — على نموذج روح دمشق.
 *
 * ═══ الغلطة اللي بتتصلّح ═══
 * الماركب اتكتب بكلاسات (`pa-perm-group` / `pa-chk` / `pa-badge` /
 * `warn-box`) **من غير ولا قاعدة CSS ليهم**. النتيجة: خانات خام متكومة
 * تحت بعضها بلا صناديق ولا أعمدة — صاحب النظام شافها وقال «مش مترتبة».
 * وكمان `warn-box` مستعملة في ٥ أماكن (لافتة «عرض بس»، «مالكش صلاحية»)
 * وكلها كانت بترندر نص عادي.
 *
 * ═══ المصدر ═══
 * CSS شاشة الصلاحيات في damascus.html سطر 198–211: صندوق لكل مجموعة،
 * رأس بخلفية مميزة، الخانات في شبكة أعمدة auto-fill بعرض 230px، وhover
 * خفيف على كل خانة. متنقول بنفس القيم مع تبديل الألوان لتوكنز
 * accounts.html (نفس الأسماء تقريبًا).
 *
 * ═══ وترتيب الجسم زي دمشق ═══
 * ① كارت الشخص: أفاتار بأول حرف + الاسم + السطر التحتي، والحالة بادج
 *   ملوّن (أخضر «محدّدة» / برتقالي «الافتراضي») بدل ستايل مكتوب جوه.
 * ② info-box بيشرح معنى العلامة — نفس صياغة دمشق مع فرقنا الوحيد:
 *   العمود المقفول مش بيخرج من السيرفر أصلًا.
 * ③ الفروع ببادج أزرق/أخضر زي دمشق بالظبط.
 * ④ عدّاد كل مجموعة أخضر لو فيها حاجة متعلّمة ورمادي لو فاضية.
 * ⑤ شريط الحفظ اللاصق بكلاس بدل الستايل المحشور.
 *
 * 🔒 الحارس: ops/test_pilotacct_acl.cjs (قسم «الستايل»)
 */
const fs = require('fs');
const F = 'public/accounts.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const L = (...x) => x.join('\n');
const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

if (s.includes('.pa-perm-group {')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① الـCSS — قبل بلوك التوست ═══ */
one(
  '/* التوست */',
  L(
    '/* ══════════ شاشة الصلاحيات — منقولة من damascus.html:198 ══════════',
    '   الماركب كان بيستعمل الكلاسات دي **من غير تعريف** فالشاشة رندرت',
    '   خانات خام متكومة. القيم نفس دمشق، والألوان بتوكنز الملف ده. */',
    '.pa-perm-group { background: var(--card); border: 1px solid var(--border);',
    '  border-radius: 12px; margin-bottom: 14px; overflow: hidden; }',
    '.pa-perm-head { display: flex; align-items: center; justify-content: space-between;',
    '  gap: 10px; padding: 11px 16px; background: var(--panel); border-bottom: 1px solid var(--border); }',
    '/* شبكة أعمدة — مش خانات تحت بعضها. 230px نفس عرض دمشق. */',
    '.pa-perm-items { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));',
    '  gap: 4px; padding: 12px 16px; }',
    '.pa-chk { display: flex; align-items: center; gap: 9px; cursor: pointer;',
    '  font-size: .86rem; font-weight: 600; padding: 6px 8px; border-radius: 8px; user-select: none; }',
    '.pa-chk:hover { background: rgba(14,165,233,.07); }',
    '.pa-chk input[type=checkbox] { width: 17px; height: 17px; accent-color: var(--sky);',
    '  cursor: pointer; flex-shrink: 0; }',
    '.pa-perm-head .pa-chk { font-weight: 800; font-size: .92rem; padding: 0; }',
    '.pa-perm-head .pa-chk:hover { background: none; }',
    '',
    '.pa-badge { padding: 3px 10px; border-radius: 20px; font-weight: 700; font-size: .74rem;',
    '  white-space: nowrap; background: rgba(139,147,167,.14); color: var(--muted); }',
    '.pa-b-green  { background: rgba(34,197,94,.15);  color: var(--green); }',
    '.pa-b-orange { background: rgba(249,115,22,.15); color: var(--orange); }',
    '.pa-b-blue   { background: rgba(14,165,233,.15); color: var(--sky); }',
    '.pa-b-muted  { background: rgba(139,147,167,.14); color: var(--muted); }',
    '',
    '.pa-user { display: flex; align-items: center; gap: 10px; min-width: 0; }',
    '.pa-avatar { width: 34px; height: 34px; border-radius: 50%; background: rgba(14,165,233,.16);',
    '  color: var(--sky); display: flex; align-items: center; justify-content: center;',
    '  font-weight: 800; flex-shrink: 0; }',
    '.pa-user-sub { font-size: .74rem; color: var(--muted); }',
    '.pa-note { padding: 10px 16px 12px; font-size: .78rem; color: var(--muted); line-height: 1.8; }',
    '.pa-sec-sub { font-size: .76rem; color: var(--muted); margin-top: 3px; }',
    '',
    '/* شريط الحفظ اللاصق — بيفضل باين مهما الشاشة طالت */',
    '.pa-savebar { position: sticky; bottom: 0; background: var(--bg); padding: 12px 0;',
    '  display: flex; gap: 8px; flex-wrap: wrap; align-items: center;',
    '  border-top: 1px solid var(--border); }',
    '',
    '/* صناديق الرسائل — كانوا مستعملين في ٥ أماكن بلا تعريف (زي دمشق:126) */',
    '.info-box { background: rgba(14,165,233,.07); border: 1px solid rgba(14,165,233,.22);',
    '  border-radius: 10px; padding: 13px 16px; font-size: .82rem; color: var(--muted);',
    '  line-height: 1.85; margin-bottom: 18px; }',
    '.warn-box { background: rgba(249,115,22,.07); border: 1px solid rgba(249,115,22,.25);',
    '  border-radius: 10px; padding: 13px 16px; font-size: .82rem; color: var(--muted);',
    '  line-height: 1.85; margin-bottom: 18px; }',
    '',
    '/* التوست */'
  ),
  '① الـCSS'
);

/* ═══ ② رأس الشاشة — عنوان + سطر شرح زي دمشق ═══ */
one(
  L(
    '      <span class="sec-title">🔐 صلاحيات البرنامج</span>',
    '      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">',
    '        <select id="paPermUser" onchange="renderPaPermsBody()"'
  ),
  L(
    '      <div>',
    '        <span class="sec-title">🔐 صلاحيات البرنامج</span>',
    '        <div class="pa-sec-sub">افتح للي تحبه واقفل اللي مش عايزه يشوفه — من غير أي تعديل في البرنامج</div>',
    '      </div>',
    '      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">',
    '        <select id="paPermUser" onchange="renderPaPermsBody()"'
  ),
  '② رأس الشاشة'
);

/* ═══ ③ عدّاد المجموعة ملوّن — أخضر لو فيها حاجة، رمادي لو فاضية ═══ */
one(
  '          <span class="pa-badge">${ on } / ${ g.items.length }</span>',
  '          <span class="pa-badge ${ on ? "pa-b-green" : "pa-b-muted" }">${ on } / ${ g.items.length }</span>',
  '③ عدّاد المجموعة'
);

/* ═══ ④ كارت الشخص — أفاتار وبادج بكلاس بدل الستايل المحشور ═══ */
one(
  L(
    '      <div class="pa-perm-group">',
    '        <div class="pa-perm-head">',
    '          <div><b>${ esc(u.name || u.username) }</b>',
    '            <div style="font-size:.74rem;color:var(--muted)">${ esc(u.username) } · ${ esc(paRole(u.role)) }${ u.branchName ? " · " + esc(u.branchName) : "" }</div>',
    '          </div>',
    '          <span class="pa-badge" style="background:${ row ? "rgba(34,197,94,.16)" : "rgba(249,115,22,.16)" }">',
    '            ${ row ? "صلاحيات محدّدة" : "الافتراضي" }</span>',
    '        </div>',
    '        <div style="padding:0 14px 12px;font-size:.78rem;color:var(--muted);line-height:1.7">${ row',
    '          ? "الصلاحيات دي محفوظة" + (row.updatedBy ? " — آخر تعديل من " + esc(row.updatedBy) : "") + "."',
    '          : "لسه مالوش صف صلاحيات، يعني شغّال بـ<b>الافتراضي</b>: كل الشاشات والأعمدة والتعديل، ماعدا السلف المؤجلة وقفل الشهر والإعدادات. أول ما تحفظ هنا، اللي معلّم عليه بس هو اللي هيشوفه." }</div>',
    '      </div>'
  ),
  L(
    '      <div class="pa-perm-group">',
    '        <div class="pa-perm-head">',
    '          <div class="pa-user">',
    '            <div class="pa-avatar">${ esc((u.name || u.username || "؟").trim().charAt(0)) }</div>',
    '            <div>',
    '              <div style="font-weight:800">${ esc(u.name || u.username) }</div>',
    '              <div class="pa-user-sub">${ esc(u.username) } · ${ esc(paRole(u.role)) }${ u.branchName ? " · " + esc(u.branchName) : "" }</div>',
    '            </div>',
    '          </div>',
    '          <span class="pa-badge ${ row ? "pa-b-green" : "pa-b-orange" }">${ row ? "صلاحيات محدّدة" : "الافتراضي" }</span>',
    '        </div>',
    '        <div class="pa-note">${ row',
    '          ? "الصلاحيات دي محفوظة" + (row.updatedBy ? " — آخر تعديل من " + esc(row.updatedBy) : "") + "."',
    '          : "لسه مالوش صف صلاحيات، يعني شغّال بـ<b>الافتراضي</b>: كل الشاشات والأعمدة والتعديل، ماعدا السلف المؤجلة وقفل الشهر والإعدادات. أول ما تحفظ هنا، اللي معلّم عليه بس هو اللي هيشوفه." }</div>',
    '      </div>',
    '',
    '      <div class="info-box">',
    '        علّم على اللي عايز الشخص ده يشوفه ويعمله. الخانة المقفولة معناها إن البند',
    '        <b>مش هيظهرله خالص</b> — العمود الممنوع مش بيخرج من السيرفر أصلًا.',
    '        <br>المدير بيشوف كل حاجة دايمًا ومش محتاج صلاحيات.',
    '      </div>'
  ),
  '④ كارت الشخص + info-box'
);

/* ═══ ⑤ الفروع — بادج أزرق/أخضر زي دمشق ═══ */
one(
  L(
    '      <div class="pa-perm-group">',
    '        <div class="pa-perm-head"><b>الفروع المسموحة</b>',
    '          <span class="pa-badge">${ brs.length ? brs.length + " فرع" : "كل الفروع" }</span></div>'
  ),
  L(
    '      <div class="pa-perm-group">',
    '        <div class="pa-perm-head"><b>الفروع المسموحة</b>',
    '          <span class="pa-badge ${ brs.length ? "pa-b-blue" : "pa-b-green" }">${ brs.length ? brs.length + " فرع" : "كل الفروع" }</span></div>'
  ),
  '⑤ بادج الفروع'
);

one(
  '        <div style="padding:0 14px 12px;font-size:.76rem;color:var(--muted)">متعلّمش على حاجة = يشوف كل الفروع.</div>',
  '        <div class="pa-note">متعلّمش على حاجة = يشوف كل الفروع.</div>',
  '⑥ ملاحظة الفروع'
);

/* ═══ ⑦ شريط الحفظ ═══ */
one(
  '      <div style="position:sticky;bottom:10px;background:var(--bg);padding:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">',
  '      <div class="pa-savebar">',
  '⑦ شريط الحفظ'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ الشاشة اتّرتّبت على نموذج دمشق');
