/* تنضيف سايدبار لوحة الإدارة — قايمة «التطبيقات الأخرى» تتشال وزرار واحد
 * فوق للبوابة.
 *
 * ═══ الطلب (صاحب النظام، 2026-09-01) ═══
 * «أريد إزالة هذه الأزرار من السايدبار لكي أفف الزحمة — لا أحتاج إليها،
 *  ويكفي زرار في الأعلى يوصلني للصفحة الرئيسية التي بها التطبيقات».
 *
 * ═══ اللي بيتشال ═══
 * • قسم «التطبيقات الأخرى» كامل: التسمية + زرارين «قريبًا» المعطّلين
 *   (النظام المحاسبي والموارد البشرية — لسه ما اتبنوش) + الكول سنتر +
 *   الفروع + بوابة المحلات + إدارة الطيارين + البوابة الرئيسية.
 * • زرار «📱 عملاء التطبيق ↗» — وده مش خسارة: كان بيفتح
 *   `tiar_customers.html` **والملف مش موجود على القرص أصلًا** (زرار 404).
 *   الشاشة الفعلية `customers.html` «إدارة العملاء» ليها كارت في البوابة
 *   (home.html: card-customers) فمافيش وصول بيضيع.
 *
 * ═══ اللي بيتضاف ═══
 * زرار واحد أول السايدبار: «🏠 التطبيقات» بيفتح البوابة (home.html) —
 * وفيها كل التطبيقات بكروتها مقفولة حسب صلاحيات كل مستخدم.
 * بنستعمل `openOtherApp` الموجودة (تاب جديد + fallback لو النوافذ محجوبة)
 * — فبتفضل مستعملة مش يتيمة.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/tiar.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

/* (١) زرار عملاء التطبيق (الـ404) */
const CUST_BTN = `      <button class="nav-item" onclick="window.open('tiar_customers.html','_blank')">📱 عملاء التطبيق ↗</button>\n`;

/* (٢) قسم التطبيقات الأخرى كامل — من التعليق لآخر زرار */
const SECTION_START = `      <!-- روابط للتطبيقات الأخرى — تفتح في تاب جديد حتى لا تُفقد الشاشة الحالية -->`;
const SECTION_END = `        🏠 البوابة الرئيسية <span class="ext-icon">↗</span></button>\n`;

/* (٣) الزرار الجديد أول القايمة */
const NAV_OPEN = `    <nav class="sidebar-nav">\n`;
const NAV_NEU = `    <nav class="sidebar-nav">
      <!-- زرار واحد للبوابة بدل قايمة «التطبيقات الأخرى» اللي كانت آخر
           السايدبار (قرار صاحب النظام 2026-09-01: «أف الزحمة») — البوابة
           فيها كل التطبيقات بكروتها حسب صلاحيات كل مستخدم. -->
      <button class="nav-item nav-external" onclick="openOtherApp('home.html')">
        🏠 التطبيقات <span class="ext-icon">↗</span></button>
`;

/* ══ فحوص قبلية ══ */
const problems = [];
for (const [label, txt] of [['زرار عملاء التطبيق', CUST_BTN], ['بداية القسم', SECTION_START],
                            ['نهاية القسم', SECTION_END], ['فتحة الـnav', NAV_OPEN]]) {
  const n = s.split(txt).length - 1;
  if (n !== 1) problems.push(`${label}: متوقّع ١ لقى ${n}`);
}
if (s.includes('🏠 التطبيقات <span')) problems.push('الزرار الجديد موجود قبل كده');
/* البوابة لازم يكون فيها فعلًا كارت إدارة العملاء — هو البديل */
const home = fs.readFileSync(path.resolve(__dirname, '../../public/home.html'), 'utf8');
if (!home.includes('card-customers')) problems.push('البوابة مافيهاش card-customers — البديل مش موجود!');
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

/* الشيل */
s = s.split(CUST_BTN).join('');
{
  const a = s.indexOf(SECTION_START);
  const b = s.indexOf(SECTION_END, a) + SECTION_END.length;
  /* بنبلع السطر الفاضي اللي قبل التعليق */
  let head = a;
  while (s[head - 1] === '\n' && s[head - 2] === '\n') head--;
  s = s.slice(0, head) + s.slice(b);
}
s = s.split(NAV_OPEN).join(NAV_NEU);

/* ══ فحوص بعدية ══ */
const after = [];
/* الفحص على تسمية السايدبار تحديدًا — تعليق CSS فوق (سطر ~8458) فيه نفس
   العبارة وهو سليم ومايخصّناش */
if (s.includes('sidebar-section-label">التطبيقات الأخرى')) after.push('تسمية القسم لسه موجودة');
if (s.includes('tiar_customers')) after.push('زرار عملاء التطبيق لسه موجود');
if (s.includes('النظام المحاسبي <span') || s.includes('البوابة الرئيسية <span')) after.push('أزرار القسم لسه موجودة');
/* openOtherApp: التعريف + النداء الجديد بس */
{
  const n = (s.match(/openOtherApp/g) || []).length;
  if (n !== 2) after.push('عدد ذكر openOtherApp مش ٢ (تعريف + زرار البوابة) — لقى ' + n);
}
if (!s.includes('🏠 التطبيقات <span class="ext-icon">↗</span></button>')) after.push('الزرار الجديد مش موجود');
/* الزرار الجديد لازم يكون أول عنصر في الـnav */
{
  const navAt = s.indexOf('<nav class="sidebar-nav">');
  const firstBtn = s.indexOf('<button', navAt);
  const chunk = s.slice(firstBtn, firstBtn + 200);
  if (!chunk.includes('openOtherApp(\'home.html\')')) after.push('الزرار الجديد مش أول واحد');
}
/* باقي السايدبار سليم */
for (const keep of ['لوحة التحكم', 'الطيارون', 'رسايل العملاء', 'الإعدادات', 'تسجيل الخروج'])
  if (!s.includes(keep)) after.push('اختفى: ' + keep);
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'tss-' + process.pid + '-' + i + '.mjs');
    try { fs.writeFileSync(tmp, m[2]); execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); }
    catch (e) { bad++; console.log('  ✗ كتلة ' + i + ': ' + ((e.stderr || '').toString().match(/SyntaxError:.*/) || ['?'])[0]); }
    finally { try { fs.unlinkSync(tmp); } catch (e2) {} }
  }
  console.log((bad ? '  ✗' : '  ✓') + ' كل كتل الجافاسكربت سليمة (' + i + ' كتلة)');
  if (bad) after.push('كتل مكسورة');
}
if (after.length) {
  console.log('⛔ فحوص بعدية وقعت — مافيش بايت اتكتب:');
  for (const a of after) console.log('   ✗ ' + a);
  process.exit(1);
}

if (process.env.DRY) { console.log('🟦 DRY — كل الفحوص عدّت، مافيش كتابة.'); process.exit(0); }
fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ اتكتب tiar.html — السايدبار اتنضّف وزرار «التطبيقات» فوق');
console.log('  ' + raw.split('\n').length + ' → ' + s.split('\n').length + ' سطر');
