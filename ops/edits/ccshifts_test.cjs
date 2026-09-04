/* بيضيف حرس «جزيرة الورديات» لـ ops/test_cc_noadmin.cjs.
 *
 * ليه الحرس ده مش زيادة: الجزيرة (page-shifts + page-shift-orders) عاشت
 * بعد مسح 2026-08-30 لأنها **مش في السايدبار** — والمسح كان بيمشي على
 * القايمة. والاختبار نفسه عدّى عليها كمان: الفحص بيتابع الزرار للمسار
 * اللي بيضربه عن طريق `openModal(...)`، ومودال التسوية مبني بـ
 * `document.createElement` مش بـ`modal-*` — فالتتبّع وقف عند
 * `adminEndPilotShift` ولقى صفر مسارات، فعدّاها بدل ما يقع.
 *
 * فالحرس هنا نصّي وصريح: أسامي + بلوكات + المسار الخطر.
 */
const fs = require('fs');
const path = require('path');

const FILE = path.resolve(__dirname, '../../ops/test_cc_noadmin.cjs');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

const edits = [];

/* ── ١) الأفعال المتشالة تنضم لقايمة GONE ──
   لو الاسم ده رجع بأي شكل (تعريف window أو نداء onclick) الاختبار يقع. */
edits.push({
  label: 'GONE += أفعال الورديات',
  old: "  'deletePilot', 'deleteUser', 'deleteExpense', 'deleteManualEmployee'];",
  new: "  'deletePilot', 'deleteUser', 'deleteExpense', 'deleteManualEmployee',\n"
     + "  /* جزيرة الورديات — اتشالت 2026-08-31 */\n"
     + "  'adminEndPilotShift', 'openShiftOrders', 'viewOldShiftReport', 'renderShiftsPage',\n"
     + "  'finalizeShiftEnd', 'showShiftReport', 'recomputeShiftDue', 'sendShiftReportWhatsApp'];",
});

/* ── ٢) قسم كامل للجزيرة ── */
const SECTION = `
console.log('\\n══ 7) 🔴 جزيرة الورديات اتشالت بالكامل ══');
/* ═══ الحكاية ═══
   \`page-shifts\` و\`page-shift-orders\` اتنسخوا مع باقي الشاشات من لوحة
   الإدارة وقت ما الكول سنتر اتبنى. مافيش زرار في السايدبار بيوصلهم —
   الـ\`navigateTo('shifts')\` الوحيد كان زرار «رجوع للورديات» **جوه**
   page-shift-orders نفسها. يعني جزيرة بتوصل لنفسها بس.

   ═══ ليه ده مهم ═══
   جوّاها كان فيه زرار «🔴 إنهاء الوردية» → مودال تسوية →
   \`POST /api/shifts/{id}/end\`. المسار \`role:admin,branch\` فالكول سنتر
   كان هياخد 403 — مش خطر تنفيذي، لكنه بيغش أي تدقيق: مسح للكود قرا
   الجزيرة كأنها طريق حقيقي لإنهاء الوردية عند الكول سنتر.

   ═══ ليه فحص نصّي هنا وبس ═══
   القسم (١) بيتابع الزرار → المسار، بس تتبّعه بيعدّي من \`openModal\` بس.
   مودال التسوية كان مبني بـ\`document.createElement\` — فالتتبّع وقف عند
   \`adminEndPilotShift\` ولقى صفر مسارات وعدّاها. الفحص ده بيسدّ الفتحة
   دي بالاسم الصريح. */
{
  for (const [what, needle] of [
    ['صفحة الورديات',        'id="page-shifts"'],
    ['صفحة طلبات الوردية',   'id="page-shift-orders"'],
    ['جدول الورديات',        'id="shiftsBody"'],
    ['لوحة «لم يفتحوا وردية»', 'id="notOpenedShiftBox"'],
    ['مودال التسوية',        '_shiftEndBox'],
    ['نافذة التقفيلة',       '_shiftReportBox'],
  ]) ok(what + ' اتشال', !html.includes(needle), 'لسه موجود');

  /* 🔴 دي الأهم: مسارات الكتابة على الوردية (role:admin,branch) مالهاش
     أي ذكر في ملف الكول سنتر خالص — لا نداء ولا نص. */
  ok('🔴 مافيش نداء لـ /shifts/{id}/end', !/shifts\\/\\$\\{[^}]*\\}\\/end|shifts\\/[^"'\`]*\\/end/.test(html),
     (html.match(/[^"'\`]*shifts[^"'\`]*\\/end/) || [])[0]);
  ok('🔴 مافيش نداء لـ /shifts/{id}/settlement', !/shifts\\/[^"'\`]*\\/settlement/.test(html));

  /* الجزيرة مالهاش مدخل: لا من السايدبار ولا من أي navigateTo */
  const navBlock = html.slice(html.indexOf('<nav class="sidebar-nav">'),
                              html.indexOf('</nav>', html.indexOf('<nav class="sidebar-nav">')));
  const navPages = [...navBlock.matchAll(/navigateTo\\('([\\w-]+)'\\)/g)].map(m => m[1]);
  ok('السايدبار مافيهوش الورديات', !navPages.includes('shifts') && !navPages.includes('shift-orders'));
  ok('مافيش أي navigateTo للورديات',
     ![...html.matchAll(/navigateTo\\(\\s*['"](shifts|shift-orders)['"]\\s*\\)/g)].length);
  ok('الورديات اتشالت من مصفوفة PAGES',
     !/const PAGES = \\[[^\\]]*"shift/.test(html));

  /* حارس الحارس: لو الفحص فوق بيقرا ملف فاضي أو غلط، الشيكات كلها
     هتعدّي كذب. فبنتأكد إن السايدبار الحقيقي لسه هنا. */
  ok('الفحص شغّال فعلًا (السايدبار مقروء)', navPages.length >= 10, navPages.length + ' صفحة');
}
`;

edits.push({
  label: 'قسم ٧: جزيرة الورديات',
  old: "\nconsole.log('\\n════════════════════════════════════════');\nconsole.log('CC NOADMIN: ' + pass + ' ناجح · ' + fail + ' فاشل');",
  new: SECTION + "\nconsole.log('\\n════════════════════════════════════════');\nconsole.log('CC NOADMIN: ' + pass + ' ناجح · ' + fail + ' فاشل');",
});

/* ── فحص قبل أي كتابة ── */
let bad = 0;
for (const e of edits) {
  const n = s.split(e.old).length - 1;
  if (n !== 1) { bad++; console.log('✗ «' + e.label + '»: متوقّع ١ لقى ' + n); }
}
if (bad) { console.log('\n⛔ مافيش بايت اتكتب.'); process.exit(1); }

for (const e of edits) { s = s.split(e.old).join(e.new); console.log('  ✓ ' + e.label); }

/* الملف لازم يفضل جافاسكربت سليم */
{
  const os = require('os');
  const { execFileSync } = require('child_process');
  const tmp = path.join(os.tmpdir(), 'ccshifts-test-' + process.pid + '.cjs');
  fs.writeFileSync(tmp, s);
  try { execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); console.log('  ✓ التركيب سليم'); }
  catch (err) {
    console.log('  ✗ التركيب مكسور: ' + ((err.stderr || '').toString().match(/SyntaxError:.*/) || ['?'])[0]);
    console.log('\n⛔ مافيش بايت اتكتب.'); process.exit(1);
  } finally { try { fs.unlinkSync(tmp); } catch (e) {} }
}

fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ اتكتب ' + path.basename(FILE));
