/**
 * 📦 حارس نشر تطبيق الطيار — «رقم النسخة الجديد لازم يكون أعلى من المنشور».
 *
 * ═══ البلاغ (صاحب النظام 2026-09-01، أول يوم شغل) ═══
 * «تطبيق الطيار اللي له لينك على الموقع — فيه لينكين، واحد منهم مش شغال،
 *  بينزل بس التطبيق مش بينزل. وجربت التاني أعطاني نفس النتيجة».
 * والرسالة على الموبايل: «لم يتم تثبيت التطبيق لأن الحزمة تبدو غير صالحة».
 *
 * ═══ السبب ═══
 * الملف كان **سليم تمامًا**: بينزل كامل بنفس الـmd5، والأرشيف صحيح،
 * والتوقيع متحقَّق منه بـapksigner، وكل النسخ بنفس المفتاح. المشكلة في
 * `versionCode`:
 *
 *     المركّب على الموبايلات (2.5.1 arm64)  → 2025
 *     dahshan-pilot-latest.apk  (شامل)      →   26   🔴 أقل بألفين
 *     dahshan-pilot-32bit.apk               → 1026   🔴 أقل بألف
 *
 * كل النسخ القديمة اتبنت بـ`--split-per-abi` اللي بيضيف ١٠٠٠×رقم
 * المعمارية (armeabi=1 · arm64=2 · x86_64=4). وبناء 2.5.2 اتعمل **شامل**
 * بالرقم الخام، فبقى **أقل** من المنشور — وأندرويد بيرفض الرجوع لنسخة
 * أقدم. عشان كده الرابطين الاتنين فشلوا على نفس الموبايل.
 *
 * (والمفارقة إن بناء `2.5.2-26-64bit` برقم 2026 كان موجود على السيرفر
 *  وكان هيشتغل — بس اللي اترُبط كـ«الأحدث» هو الشامل.)
 *
 * ═══ القاعدة ═══
 * أي بناء جديد لازم `versionCode` بتاعه يبقى **أعلى من كل** اللي اترفع
 * قبله — مش لمعماريته بس. والأأمن: نفضل على `--split-per-abi` دايمًا.
 *
 * ═══ إزاي بيشتغل ═══
 * ملفات الـAPK عايشة على السيرفر مش في المستودع، فالحارس بيقارن بسجل
 * `ops/apk-published.json` (بيتحدّث مع كل رفع) وبنواتج البناء المحلية لو
 * موجودة. الفحوص اللي محتاجة aapt2 بتتخطّى بأناقة لو مش متثبّت.
 *
 * التشغيل: node ops/test_apk_release.cjs
 */
const fs = require('fs'), path = require('path'), { execSync } = require('child_process');

let pass = 0, fail = 0, skip = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};
const skipped = what => { skip++; console.log('  ⊘ ' + what + ' (اتخطّى)'); };

const PUB = JSON.parse(fs.readFileSync('ops/apk-published.json', 'utf8'));

/* رقم البناء والمعمارية بيتقروا من **اسم الملف** — النمط ثابت:
   dahshan-pilot-<version>-<build>-<abi>.apk
   والرقم الفعلي = build + ١٠٠٠×رقم المعمارية (نفس منطق Flutter). */
const ABI_OFFSET = { '32bit': 1000, '64bit': 2000, universal: 0, '': 2000 };
function parseName(f) {
  const m = f.match(/^dahshan-pilot-([\d.]+)-(\d+)(?:-(32bit|64bit|universal))?\.apk$/);
  if (!m) return null;
  const abi = m[3] || '';
  return { file: f, version: m[1], build: +m[2], abi: abi || '64bit',
           code: +m[2] + ABI_OFFSET[abi] };
}

console.log('\n══ 1) روابط الموقع بتطابق السجل ══');
const INDEX = fs.readFileSync('public/index.html', 'utf8');
const linked = [...new Set([...INDEX.matchAll(/downloads\/([a-z0-9._-]+\.apk)/gi)].map(m => m[1]))];
ok('الموقع فيه روابط تحميل', linked.length > 0, String(linked.length));
for (const l of linked) {
  ok(`«${l}» مسجّل في apk-published.json`, !!PUB.links[l],
     'الرابط في الموقع بس مش في السجل — يا اتشال يا يتسجّل');
}
for (const l of Object.keys(PUB.links)) {
  const target = PUB.links[l];
  ok(`«${l}» → ${target}`, PUB.files.some(x => x.file === target),
     'الرابط بيوديّ لملف مش في السجل');
}

console.log('\n══ 2) 🔴 الروابط بتوديّ لأعلى رقم منشور ══');
const all = PUB.files.map(x => parseName(x.file)).filter(Boolean);
ok('السجل فيه ملفات مقروءة', all.length > 0, String(all.length));
const globalMax = all.reduce((m, x) => Math.max(m, x.code), 0);
for (const [link, target] of Object.entries(PUB.links)) {
  const t = parseName(target);
  if (!t) { skipped(`قراءة «${target}»`); continue; }
  ok(`«${link}» = ${t.version} (code ${t.code}, ${t.abi})`, true);
  /* أندرويد بيقارن الرقم جوّه **نفس مسار المعمارية** (الموبايل مركّب
     بناء واحد)، فالمقارنة الصح على نفس المسار. */
  const lane = all.filter(x => x.abi === t.abi && x.file !== t.file);
  const laneMax = lane.reduce((m, x) => Math.max(m, x.code), 0);
  ok(`  ورقمه أعلى من كل نسخ «${t.abi}» (أعلى تاني: ${laneMax})`, t.code > laneMax,
     '🔴 أندرويد هيرفض التثبيت كرجوع لنسخة أقدم');
  /* 🔴 والقاعدة اللي كسرها الباج: **ممنوع بناء شامل**. الشامل بياخد الرقم
     الخام بلا إزاحة، فبيقع تحت كل مسارات الـsplit ومحدش يقدر يحدّث عليه.
     (2.5.2 الشامل = 26 مقابل arm64 المنشور 2025.) */
  ok('  ومش بناء «شامل» (universal)', t.abi !== 'universal',
     'الشامل رقمه بلا إزاحة فبيقع تحت كل الـsplits — ده الباج اللي حصل');
}
ok(`أعلى رقم منشور = ${globalMax}`, globalMax > 0);

console.log('\n══ 3) نسخة المشروع متسقة ومستقبلها آمن ══');
const PUBSPEC = fs.readFileSync('../aldahshan/pubspec.yaml', 'utf8');
const MAIN    = fs.readFileSync('../aldahshan/lib/main.dart', 'utf8');
const pv = PUBSPEC.match(/^version:\s*([0-9.]+)\+(\d+)/m) || [];
const kv = (MAIN.match(/const String kAppVersion = '([0-9.]+)'/) || [])[1];
ok('pubspec فيه نسخة ورقم بناء', !!pv[1] && !!pv[2], String(pv[0]));
ok('و`kAppVersion` مطابق لنسخة pubspec', kv === pv[1],
   `kAppVersion=${kv} · pubspec=${pv[1]}`);
/* البناء الجاي بـ`--split-per-abi` هيدّي (build+1000) لـ32بت و(build+2000)
   لـarm64. المساواة هي **الوضع الطبيعي** بعد أي إصدار (pubspec بالظبط على
   المنشور)؛ الخطر هو الرقم **الأقل** — يعني حد رجّع رقم البناء أو أعاد
   استعمال رقم قديم، وساعتها البناء الجاي مش هيتركّب على الموبايلات. */
for (const [abi, off] of [['32bit', 1000], ['64bit', 2000]]) {
  const laneMax = all.filter(x => x.abi === abi).reduce((m, x) => Math.max(m, x.code), 0);
  const next = +pv[2] + off;
  ok(`رقم البناء في pubspec (+${pv[2]}) → ${next} لمسار «${abi}» — مش أقل من المنشور ${laneMax}`,
     next >= laneMax, '🔴 البناء الجاي هيبقى أقل من المنشور ومش هيتركّب — ارفع الرقم');
  if (next === laneMax) {
    console.log(`      ℹ️ مساوي للمنشور — ارفع الرقم قبل أي بناء جديد`);
  }
}

console.log('\n══ 4) نواتج البناء المحلية (لو موجودة) ══');
const OUT = '../aldahshan/build/app/outputs/flutter-apk';
function findAapt() {
  const roots = [process.env.ANDROID_HOME, process.env.ANDROID_SDK_ROOT,
                 path.join(process.env.LOCALAPPDATA || '', 'Android', 'Sdk'),
                 path.join(process.env.HOME || '', 'Android', 'Sdk')].filter(Boolean);
  for (const r of roots) {
    const bt = path.join(r, 'build-tools');
    if (!fs.existsSync(bt)) continue;
    for (const v of fs.readdirSync(bt).sort().reverse()) {
      for (const n of ['aapt2.exe', 'aapt2']) {
        const p = path.join(bt, v, n);
        if (fs.existsSync(p)) return p;
      }
    }
  }
  return null;
}
const aapt = findAapt();
if (!fs.existsSync(OUT)) { skipped('فحص نواتج البناء — مافيش مجلد build'); }
else if (!aapt) { skipped('فحص نواتج البناء — مالقيتش aapt2'); }
else {
  const splits = fs.readdirSync(OUT).filter(f => /^app-(arm64-v8a|armeabi-v7a)-release\.apk$/.test(f));
  if (!splits.length) skipped('مافيش نواتج split-per-abi');
  for (const f of splits) {
    const o = execSync(`"${aapt}" dump badging "${path.join(OUT, f)}"`,
                       { encoding: 'utf8', maxBuffer: 1 << 26 });
    const code = +(o.match(/versionCode='(\d+)'/) || [])[1];
    const name = (o.match(/versionName='([^']+)'/) || [])[1];
    const min  = +(o.match(/minSdkVersion:'(\d+)'/) || [])[1];
    ok(`${f} = ${name} (code ${code})`, true);
    const abi = f.includes('arm64') ? '64bit' : '32bit';
    const laneMax = all.filter(x => x.abi === abi).reduce((m, x) => Math.max(m, x.code), 0);
    ok(`  أعلى من كل نسخ «${abi}» المنشورة (${laneMax})`, code >= laneMax,
       'رفعه هيكسر التحديث على موبايلات الطيارين');
    ok(`  وminSdk ${min} مش أعلى من 24`, min > 0 && min <= 24,
       'موبايلات أندرويد 7 هتتقفل بره');
  }
}

console.log('\n════════════════════════════════════════');
console.log(`APK RELEASE: ${pass} ناجح · ${fail} فاشل` + (skip ? ` · ${skip} متخطّى` : ''));
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
