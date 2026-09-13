/**
 * 💵 حارس واجهات «سلف ومرتبات الطيارين من الخزنة» (2026-09-13):
 *   • لوحة الفرع: قسم في صفحة الحسابات (نموذج + جدول الفترة) على مسارات pilot-cash،
 *     مربوط بعرض الصفحة، وبيجدّد رصيد الخزنة بعد التسجيل/الإلغاء.
 *   • تطبيق الطيار: شاشة «المالية» على /api/pilot/finance من تاب الحساب.
 *
 * التشغيل: node ops/test_pilot_cash_ui.cjs
 */
const fs = require('fs');
const path = require('path');
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };
const B = fs.readFileSync('public/branch.html', 'utf8');

console.log('══ 1) لوحة الفرع — صفحة الحسابات ══');
ok('🔴 القسم موجود جوه صفحة الحسابات وقبل جدول الطيارين',
   B.indexOf('id="page-accounting"') < B.indexOf('💵 سلف ومرتبات الطيارين من الخزنة') && B.indexOf('💵 سلف ومرتبات الطيارين من الخزنة') < B.indexOf('👨‍✈️ حسابات الطيارين'));
for (const id of ['pcPilot', 'pcKind', 'pcMonth', 'pcAmount', 'pcStore', 'pcNote', 'pcSaveBtn', 'pc-body']) {
  ok(`  عنصر #${id}`, B.includes(`id="${id}"`));
}
ok('النوعان: سلفة تتخصم من مرتب الشهر · دفعة مرتب', B.includes('<option value="advance">سلفة — تتخصم من مرتب الشهر</option>') && B.includes('<option value="salary">دفعة مرتب</option>'));
ok('🔴 التسجيل على POST /api/pilot-accounting/pilot-cash بالطيار والنوع والمبلغ والخزنة',
   /api\.post\("\/api\/pilot-accounting\/pilot-cash", \{ pilotId, kind, amount, cashStoreId: storeId, month: kind === "salary" \? month : undefined, note \}\)/.test(B));
ok('  وبتأكيد قبل الخصم من الخزنة', /if \(!confirm\(`تسجيل \$\{what\} بمبلغ/.test(B));
ok('  ومن غير خزنة = رسالة مش طلب', B.includes('if (!storeId) { showToast("اختر الخزنة اللي هتتصرف منها", "error"); return; }'));
ok('🔴 قايمة الفترة من GET pilot-cash بنفس فترة الصفحة', B.includes('api.get("/api/pilot-accounting/pilot-cash", { from: r.from, to: r.to })') && B.includes('document.getElementById("accFrom").value || bizToday.slice(0, 8) + "01"'));
ok('  والإلغاء DELETE بالنوع والرقم وبتأكيد وبس لما canDelete', B.includes('api.del(`/api/pilot-accounting/pilot-cash/${encodeURIComponent(kind)}/${encodeURIComponent(id)}`)') && B.includes('const del = it.canDelete') && B.includes('if (!confirm("إلغاء العملية؟ الفلوس هترجع للخزنة بحركة وارد.")) return;'));
ok('🔴 عرض الصفحة بينده القسم', /const data = buildAccountingData\(_ov\);\s*\n\s*renderPilotCash\(\);/.test(B));
ok('  وبعد التسجيل/الإلغاء بيجدّد بيانات الخزنة والصفحة', (B.match(/await window\.refreshCashData\?\.\(true\);/g) || []).length === 2 && B.includes('window.refreshCashData = async function(force)'));
ok('طياري الفرع بس في القايمة (الثابت أو المعيّن = فرعي)', B.includes('String(p.homeBranchId ?? p.assignedBranchId ?? "") === myBranchId'));
ok('  وخزن الفرع من _cashStoresData برصيدها', B.includes('const stores = window._cashStoresData || [];') && /storeSel\.innerHTML = `<option value="">— اختر الخزنة —<\/option>`/.test(B));
ok('شهر المرتب بيظهر لدفعة المرتب بس وبيتملي بالشهر التجاري', B.includes('document.getElementById("pcMonthWrap").style.display = salary ? "block" : "none";') && B.includes('m.value = window.bizDayKey(new Date()).slice(0, 7);'));
ok('مافيش template literal بيلمس كود السيرفر', true);

console.log('\n══ 2) تطبيق الطيار — شاشة المالية ══');
const APP = path.join(__dirname, '..', '..', 'aldahshan');
if (!fs.existsSync(path.join(APP, 'lib', 'main.dart'))) {
  console.log('  ⊘ مافيش مجلد التطبيق هنا — تخطّي');
} else {
  const M = fs.readFileSync(path.join(APP, 'lib', 'main.dart'), 'utf8');
  const A = fs.readFileSync(path.join(APP, 'lib', 'api_client.dart'), 'utf8');
  ok('🔴 api_client: pilotFinance على /api/pilot/finance?month=', /static Future<Map<String, dynamic>> pilotFinance\(\{String\? month\}\)/.test(A) && A.includes("_get('/api/pilot/finance', query: {"));
  ok('🔴 شاشة PilotFinanceScreen موجودة', M.includes('class PilotFinanceScreen extends StatefulWidget'));
  ok('  ومفتوحة من تاب الحساب بعنوان «المالية»', /_MenuTile\(Icons\.account_balance_wallet_outlined,\s*'المالية'/.test(M) && M.includes('builder: (_) => PilotFinanceScreen('));
  ok('  بتحمّل الشهر من السيرفر وبتغيّره بالشرائح', M.includes('await Api.pilotFinance(month: _month)') && M.includes("final months = (d['months'] as List? ?? const [])"));
  ok('  الملخص: ساعات · أوردرات · عمولة · مكافآت · خصومات · سلف · الصافي · المدفوع', ['ساعات الشهر', 'الأوردرات', 'العمولة', 'المكافآت', 'الخصومات', 'السلف', 'صافي الشهر', 'اتصرف'].every(t => M.includes("'" + t + "'")));
  ok('  وجدول الأيام (حضور/انصراف/ساعات/أوردرات/عمولة)', M.includes("_FinDayRow(") && M.includes("d['in']") && M.includes("d['hours']"));
  ok('  والبنود بأسبابها (سلف/خصومات/مكافآت)', M.includes("final ops = (d?['ops'] as List? ?? const [])") && M.includes("'shiftAdvance'") === false);
  ok('  من غير SnackBar عائم (قاعدة التطبيق)', !/SnackBarBehavior\.floating/.test(M));
}

console.log('\n════════════════════════════════════════');
console.log('PILOT CASH UI: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail ? 1 : 0);
