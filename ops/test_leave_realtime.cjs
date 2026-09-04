/**
 * ⚡ حارس: وصول قرار الإذن لتطبيق الطيار فورًا — ومنع ارتداده.
 *
 * ═══ الواقعة (صاحب النظام 2026-09-01) ═══
 * «الإذن بيتم بعد ٦٠ ثانية من قرار المشرف وبعدها بيرجع بلحظة من غير
 * ما المشرف يرجّع الطيار».
 *
 * ═══ اللي اتلقى بالتشريح ═══
 * ① التأخير: مسار **فرض** الإذن (pilotForceLeave) ماكانش بيبثّ حدث
 *    `pilot.request.changed` — الموافقة بتبثّ والفرض لأ. التطبيق مستني
 *    الحدث ده عشان يزامن فورًا، فالفرض كان بيستنى دورة الاستطلاع
 *    الكاملة (٦٠ث).
 * ② الارتداد: شرط التطبيق كان «onLeave **و** فيه breakStartedAt» —
 *    إذن من غير وقت بداية بينزل على فرع المسح. والمسح كان بيحصل على
 *    **أي** حالة مش onLeave، حتى رد ناقص أو حالة غريبة.
 *
 * ═══ العقود اللي الفحوص بتثبتها ═══
 * • السيرفر: الفرض بيبثّ زي الموافقة والإنهاء بالظبط.
 * • التطبيق: الحالة هي الحكم (مش breakStartedAt)، والمسح **بس** لما
 *   السيرفر يقول صراحةً waiting/delivering، والمزامنة كل ١٥ث.
 *
 * التشغيل: node ops/test_leave_realtime.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const CTL = fs.readFileSync('app/Http/Controllers/Api/BoardController.php', 'utf8');
const strip = t => t.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/[^\n]*$/gm, '');
const C = strip(CTL);

console.log('\n══ 1) السيرفر — البثّ ══');
const cutFn = (name) => {
  const i = C.indexOf('function ' + name + '(');
  if (i < 0) return '';
  const j = C.indexOf('    public function ', i + 10);
  return j < 0 ? C.slice(i) : C.slice(i, j);
};
ok('🔴 فرض الإذن بيبثّ pilot.request.changed — ده اللي كان ناقص',
  /broadcastPilotRequest\('leave', \$branchId, \$pilotId\)/.test(cutFn('pilotForceLeave')),
  'الفرض هيستنى دورة الاستطلاع ٦٠ث تاني');
ok('والموافقة لسه بتبثّ', /broadcastPilotRequest\(/.test(cutFn('leaveRequestApprove')));
ok('والإنهاء لسه بيبثّ', /broadcastPilotRequest\('leave'/.test(cutFn('leaveRequestEnd')));
ok('والطيار الممنوع من إنهاء الإيقاف الإجباري — الحارس موجود',
  /مينفعش تنهي الإذن بنفسك/.test(CTL));

console.log('\n══ 2) تطبيق الطيار ══');
const APP_PATH = '../aldahshan/lib/main.dart';
if (!fs.existsSync(APP_PATH)) {
  console.log('  ⚠️ مشروع التطبيق مش موجود جنب المشروع — تخطّي فحوصه');
} else {
  const A = fs.readFileSync(APP_PATH, 'utf8');
  const sync = (() => {
    const i = A.indexOf('Future<void> _syncPilotStatus()');
    return i < 0 ? '' : A.slice(i, A.indexOf('\n  }', i));
  })();
  ok('_syncPilotStatus اتقصّت', sync.length > 0);
  ok('🔴 الحالة هي الحكم — الإذن بيظهر حتى من غير breakStartedAt',
    /if \(status == 'onLeave'\) \{/.test(sync)
    && !/status == 'onLeave' && serverBreakIso != null/.test(sync),
    'إذن بلا وقت بداية هينزل على فرع المسح ويرتد');
  ok('🔴 والمسح بس لما السيرفر يقول صراحةً «شغّال»',
    /else if \(status == 'waiting' \|\| status == 'delivering'\) \{/.test(sync),
    'أي رد ناقص أو حالة غريبة هتمسح إذن ساري');
  ok('ووقت البداية الغايب له بديل', /: DateTime\.now\(\);/.test(sync));

  ok('🔴 المزامنة كل ١٥ث — مش ٦٠',
    /_touchForegroundHeartbeat\(\);[\s\S]{0,600}_syncPilotStatus\(\);/.test(A),
    'قرار المشرف هيستنى دقيقة لو البث واقع');
  ok('والحدث اللحظي لسه بيزامن فورًا',
    /eventPilotRequest\) \{[\s\S]{0,300}_syncPilotStatus\(\);/.test(A));
  /* النسخة بتتقارن مش بتتساوى بثابت — كل رفعة جديدة كانت هتقلب الفحص
     أحمر بلا سبب. الشرط: أحدث من 2.5.4 (اللي فيها الباج) وpubspec ماشي
     مع kAppVersion حرفيًا. */
  const ver = (A.match(/kAppVersion = '(\d+)\.(\d+)\.(\d+)'/) || []);
  const vnum = ver.length ? (+ver[1]) * 10000 + (+ver[2]) * 100 + (+ver[3]) : 0;
  ok('والنسخة أحدث من 2.5.4 (اللي فيها الباج)', vnum > 20504, ver[0] || '؟');
  const pub = fs.readFileSync('../aldahshan/pubspec.yaml', 'utf8');
  ok('وpubspec ماشي مع kAppVersion',
    ver.length > 0 && new RegExp('version: ' + ver[1] + '\\.' + ver[2] + '\\.' + ver[3] + '\\+\\d+').test(pub));
  ok('🔔 والتنبيه الناعم موجود — «فيه نسخة جديدة نزّلها» من غير إيقاف',
    /gPendingUpdateNotice = upd;/.test(A) && /maybeShowUpdateNotice\(context\);/.test(A)
    && /showMaterialBanner/.test(A));
}

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — قرار الإذن بيوصل فورًا ومش بيرتد\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
