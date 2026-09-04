/* اختبار الطفرة: بيرجّع الكود المتشال بأشكال مختلفة، وبيتأكد إن
 * `ops/test_cc_noadmin.cjs` **بيقع** في كل مرة.
 *
 * ليه ده لازم: فحص بيدوّر على نص مش موجود بيعدّي دايمًا — حتى لو الملف
 * فاضي أو الشرط مقلوب. اختبار مابيعرفش يقع مش حارس، هو زينة. فبنثبت
 * إنه بيقع، ونشوف **مين** بالظبط اللي وقع في كل طفرة.
 *
 * الملف الأصلي بيترجع بعد كل طفرة، والـmd5 بيتأكّد في الآخر.
 *
 * التشغيل: node ops/edits/ccshifts_mutate.cjs
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '../..');
const FILE = path.join(ROOT, 'public/callcenter.html');
const TEST = 'ops/test_cc_noadmin.cjs';

/* النسخة الاحتياطية **برّه** المستودع — نسخة جوّه ops/edits سمّمت تدقيقًا
   قبل كده (الماسح قراها كأنها كود حيّ). */
const SAFE = path.join(os.tmpdir(), 'ccshifts-mutate-' + process.pid);
fs.mkdirSync(SAFE, { recursive: true });

const GOOD = fs.readFileSync(FILE, 'utf8');
const GOOD_MD5 = crypto.createHash('md5').update(GOOD).digest('hex');
fs.writeFileSync(path.join(SAFE, 'good.html'), GOOD);

const PRE = path.join(SAFE, 'pre.html');
const preSrc = process.argv[2];   // النسخة اللي قبل الشيل (اختيارية)
if (preSrc) fs.copyFileSync(preSrc, PRE);

const runTest = () => {
  const r = spawnSync(process.execPath, [TEST], { cwd: ROOT, encoding: 'utf8' });
  const failed = (r.stdout || '').split('\n').filter(l => l.includes('✗')).map(l => l.trim());
  return { code: r.status, failed };
};

/* ── الطفرات ── */
const MUTATIONS = [];

if (preSrc) MUTATIONS.push({
  name: 'رجوع الملف بالكامل لما قبل الشيل',
  apply: () => fs.readFileSync(PRE, 'utf8'),
});

MUTATIONS.push({
  name: 'رجوع بلوك page-shifts لوحده',
  apply: s => s.replace('    <!-- صفحة الطلبات الملغاة -->',
    '    <div class="page" id="page-shifts">\n'
  + '      <div class="table-wrap"><table><tbody id="shiftsBody"></tbody></table></div>\n'
  + '    </div>\n\n    <!-- صفحة الطلبات الملغاة -->'),
});

MUTATIONS.push({
  name: 'رجوع بلوك page-shift-orders لوحده',
  apply: s => s.replace('    <!-- صفحة الطلبات الملغاة -->',
    '    <div class="page" id="page-shift-orders">\n'
  + '      <button onclick="navigateTo(\'shifts\')">رجوع</button>\n'
  + '    </div>\n\n    <!-- صفحة الطلبات الملغاة -->'),
});

MUTATIONS.push({
  name: 'رجوع adminEndPilotShift + نداء /shifts/{id}/end',
  apply: s => s.replace('    function renderPilotsTable(pilots) {',
    '    window.adminEndPilotShift = async function(shiftId, pilotId) {\n'
  + '      await api.post(`/api/shifts/${shiftId}/end`, {});\n'
  + '    };\n\n    function renderPilotsTable(pilots) {'),
});

MUTATIONS.push({
  name: 'رجوع زرار «إنهاء الوردية» في onclick بس',
  apply: s => s.replace('<tbody id="undeliveredOrdersBody"></tbody>',
    '<tbody id="undeliveredOrdersBody"></tbody></table></div>'
  + '<div><button onclick="adminEndPilotShift(\'x\',\'y\')">🔴 إنهاء الوردية</button></div>'
  + '<div><table>'),
});

MUTATIONS.push({
  name: 'رجوع الورديات لمصفوفة PAGES',
  apply: s => s.replace('"dashboard","pilots","joinrequests","pilotleave","hr"',
                        '"dashboard","pilots","joinrequests","pilotleave","shifts","shift-orders","hr"'),
});

MUTATIONS.push({
  name: 'رجوع رابط الورديات للسايدبار',
  apply: s => s.replace('      <button class="nav-item" onclick="navigateTo(\'cczones\')">',
    '      <button class="nav-item" onclick="navigateTo(\'shifts\')">🕒 الورديات</button>\n'
  + '      <button class="nav-item" onclick="navigateTo(\'cczones\')">'),
});

MUTATIONS.push({
  name: 'رجوع مودال التسوية (_shiftEndBox)',
  apply: s => s.replace('    function renderPilotsTable(pilots) {',
    '    function _mutantSettle() { const b = document.createElement("div"); b.id = "_shiftEndBox"; }\n\n'
  + '    function renderPilotsTable(pilots) {'),
});

/* ── التشغيل ── */
console.log('══ الحالة السليمة (لازم تعدّي) ══');
{
  const r = runTest();
  console.log((r.code === 0 ? '  ✓' : '  ✗') + ' الاختبار بيعدّي على الملف المتشال منه (exit ' + r.code + ')');
  if (r.code !== 0) { r.failed.forEach(l => console.log('     ' + l)); process.exit(1); }
}

console.log('\n══ الطفرات (' + MUTATIONS.length + ') — كل واحدة لازم **توقّع** الاختبار ══');
let survivors = 0;
for (const m of MUTATIONS) {
  const mutated = m.apply(GOOD);
  if (mutated === GOOD) {
    console.log('  ⚠️  ' + m.name + ' — الطفرة ماتطبّقتش (الأنكور اتغيّر؟)');
    survivors++;
    continue;
  }
  fs.writeFileSync(FILE, mutated);
  const r = runTest();
  fs.writeFileSync(FILE, GOOD);                       // رجوع فوري

  if (r.code === 0) { survivors++; console.log('  ✗ نجت: ' + m.name + '  ← الاختبار عدّاها!'); }
  else {
    console.log('  ✓ اتمسكت: ' + m.name);
    for (const l of r.failed.slice(0, 3)) console.log('        ' + l);
    if (r.failed.length > 3) console.log('        … و' + (r.failed.length - 3) + ' كمان');
  }
}

/* ── الملف رجع زي ما كان بالظبط؟ ── */
const back = crypto.createHash('md5').update(fs.readFileSync(FILE, 'utf8')).digest('hex');
console.log('\n══ الرجوع ══');
console.log((back === GOOD_MD5 ? '  ✓' : '  ✗') + ' الملف رجع بالظبط  (md5 ' + back.slice(0, 12) + ')');

try { fs.rmSync(SAFE, { recursive: true, force: true }); } catch (e) {}

console.log('\n════════════════════════════════════════');
console.log(survivors ? '⛔ ' + survivors + ' طفرة نجت — الحرس فيه فتحة'
                      : '✅ كل الطفرات اتمسكت');
console.log('════════════════════════════════════════');
process.exit(survivors || back !== GOOD_MD5 ? 1 : 0);
