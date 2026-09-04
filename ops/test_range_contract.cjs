/**
 * 🔌 اختبار السلك: المفاتيح اللي التطبيق بيقراها = اللي السيرفر بيبعتها.
 *
 * ═══ ليه الملف ده موجود ═══
 * ده أكتر نوع باج اتكرر في المشروع ده: التطبيق بيقرا مفتاح السيرفر
 * مابيبعتوش، فبيرجع `null`، والكود بيحوّله صفر — **من غير أي خطأ في أي
 * لوج**. الشاشة بتشتغل، الرقم بيبان، والرقم غلط.
 *
 * الاختبارات العادية مابتمسكش ده: اختبار السيرفر بيتأكد إنه بيبعت
 * المفتاح، واختبار التطبيق بيتأكد إنه بيقراه — ومحدش بيقارن الاسمين.
 *
 * الملف ده بيقرا **الملفين الحقيقيين** ويقارن.
 *
 * التشغيل: node ops/test_range_contract.cjs
 */
const fs = require('fs');

let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const PHP  = fs.readFileSync('app/Http/Controllers/Api/PilotAppController.php', 'utf8');
const DART = fs.readFileSync('../aldahshan/lib/main.dart', 'utf8');
const API  = fs.readFileSync('../aldahshan/lib/api_client.dart', 'utf8');

console.log('\n══ 1) مفاتيح stats ══');
/* التطبيق بيقرا الأربعة دول. أي واحد فيهم يتغيّر اسمه في السيرفر
   بيرجّع صفر في الشاشة من غير ما يبان إنه غلط. */
for (const k of ['totalOrders', 'deliveredCount', 'totalCollected', 'itemsShown', 'itemsTruncated']) {
  const inPhp  = PHP.includes("'" + k + "'");
  const inDart = DART.includes("'" + k + "'");
  ok('stats.' + k + ' موجود في الاتنين', inPhp && inDart,
     'php=' + inPhp + ' dart=' + inDart);
}

console.log('\n══ 2) مفاتيح range ══');
for (const k of ['from', 'to']) {
  ok("range['" + k + "'] بيتقرا في التطبيق", DART.includes("_range['" + k + "']"));
}
ok('السيرفر بيبعت range في الرد', /'range'\s*=>\s*\$range/.test(PHP));
ok('range فيه mode و from و to',
   /'mode'\s*=>/.test(PHP) && /'from'\s*=>/.test(PHP) && /'to'\s*=>/.test(PHP));

console.log('\n══ 3) البارامترات الطالعة من التطبيق = اللي السيرفر بيقراها ══');
for (const p of ['period', 'from', 'to', 'day', 'shift']) {
  const sends = API.includes("'" + p + "':");
  const reads = PHP.includes("query('" + p + "'");
  ok("`" + p + "` بيتبعت وبيتقرا", sends && reads, 'يبعت=' + sends + ' يقرا=' + reads);
}

console.log('\n══ 4) قيم period المسموحة متطابقة ══');
/* التطبيق بيبعت `_period.key` نصًا خام. لو ضاف قيمة السيرفر مايعرفهاش،
   الرد بيبقى استثناء والشاشة بتفضل فاضية من غير سبب واضح للطيار. */
const phpAllowed = ['today', 'month', 'year'].filter(v => PHP.includes("$period === '" + v + "'"));
ok('السيرفر بيقبل today و month و year', phpAllowed.length === 3, phpAllowed.join('، '));

const dartKeys = [...DART.matchAll(/_Period\('([a-z]+)'/g)].map(m => m[1]);
const uniq = [...new Set(dartKeys)];
ok('التطبيق بيعرّف shift/today/month/year/custom',
   ['shift', 'today', 'month', 'year', 'custom'].every(k => uniq.includes(k)), uniq.join('، '));

/* `shift` و`custom` **مش** بيتبعتوا كـperiod: الأولاني معناه «بلا
   بارامترات» والتاني بيتحوّل from/to. لو اتبعتوا السيرفر هيرفضهم. */
ok('shift و custom مابيتبعتوش كـperiod',
   /period: _period\.key == 'shift' \|\| _period\.key == 'custom' \? null : _period\.key/.test(DART));

console.log('\n══ 5) 🔴 الإجماليات مابتتجمعش من القايمة ══');
/* اللسعة الأصلية: `_orders` أول 200 بس، وجمع الفلوس منها كان بيدّي
   رقم ناقص بلا علامة. */
ok('مفيش جمع لـtotalDeliveryPrice من _orders',
   ! /_orders[\s\S]{0,80}fold<double>[\s\S]{0,60}totalDeliveryPrice/.test(DART));
ok('الأرقام بتتقرا من _stats',
   DART.includes("_stats['totalOrders']") && DART.includes("_stats['totalCollected']"));
ok('السيرفر بيحسبها على استعلام مستقل',
   PHP.includes('$allRows = DB::select(') && PHP.includes('foreach ($allRows as $r)'));

console.log('\n══ 6) منتقي التواريخ هيطلع عربي ══');
/* من غير الترجمات دي المنتقي بيطلع إنجليزي بالكامل وسط تطبيق عربي
   RTL — أسماء الشهور وأزرار OK/Cancel. */
const PUB = fs.readFileSync('../aldahshan/pubspec.yaml', 'utf8');
ok('flutter_localizations مضافة', /flutter_localizations:\s*\n\s*sdk: flutter/.test(PUB));
ok('الدلائل متسجّلة في MaterialApp', DART.includes('GlobalMaterialLocalizations.delegate'));
ok('اللغة عربي', /locale: const Locale\('ar'\)/.test(DART));
ok('الاستيراد موجود', DART.includes("import 'package:flutter_localizations/flutter_localizations.dart';"));

console.log('\n══ 7) حدود المدى متطابقة بين الطرفين ══');
/* السيرفر بيرفض أكتر من سنتين. لو المنتقي سمح بأكتر، الطيار هيختار
   فترة وتترفض — تجربة مكسورة من غير سبب ظاهر. */
ok('السيرفر بيحد بسنتين', /366 \* 2 \* 86400/.test(PHP));
ok('المنتقي بيبدأ من سنتين لورا', /DateTime\(now\.year - 2, now\.month, now\.day\)/.test(DART));

console.log('\n════════════════════════════════════════');
console.log('RANGE CONTRACT: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
