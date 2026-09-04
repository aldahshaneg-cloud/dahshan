/**
 * 🗺️ حارس: بلاطات الخرايط من OSM المجانية — ممنوع الرجوع لـCarto.
 *
 * ═══ ليه الملف ده موجود ═══
 * Carto فرضت مفاتيح API على خرايط الأساس، وتطبيق العملاء كان الوحيد
 * الشغال بيها (dark_all عشان الشكل الغامق) — فظهرت علامة «API KEY
 * REQUIRED» مطبوعة على كل بلاطة في شاشة تتبّع الطيار لايف قدام
 * العميل (2026-09-02). كل تطبيقات الطاقم كانت أصلًا على OSM ومافيهاش
 * المشكلة. الحل: OSM في كل حتة.
 *
 * ⚠️ وقرار صاحب النظام (2026-09-02): الخريطة بألوان OSM **الطبيعية**
 * في الوضعين — كان فيه فلتر invert بيغمّقها في الليلي واتشال بطلبه،
 * فالحارس بيمنع رجوعه.
 *
 * التشغيل: node ops/test_map_tiles.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const PAGES = ['customer', 'store', 'branch', 'tiar', 'callcenter', 'pilots'];

console.log('\n══ 1) مافيش صفحة بتحمّل بلاطات من Carto ══');
for (const p of PAGES) {
  const t = fs.readFileSync(`public/${p}.html`, 'utf8');
  // بندوّر على رابط البلاطات نفسه مش كلمة carto — عشان التعليقات
  // اللي بتحكي القصة (والحارس ده نفسه) مايوقّعوش الفحص
  ok(`${p}.html`, !t.includes('basemaps.cartocdn.com'),
    'رجعت بلاطات Carto — هتطبع API KEY REQUIRED على الخريطة');
}

console.log('\n══ 2) تطبيق العملاء — OSM بألوانها الطبيعية ══');
const C = fs.readFileSync('public/customer.html', 'utf8');
ok('🔴 TILES بقت OSM',
  C.includes('const TILES = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";'));
ok('وخريطة التتبّع بتستخدم نفس الثابت (مش رابط تاني منسي)',
  /L\.tileLayer\(TILES, \{ maxZoom: 19 \}\)\.addTo\(trackMap\);/.test(C));
// بندوّر على قاعدة CSS فعلية: «.leaflet-tile» ملزوقة في بلوك جواه filter —
// مش كلمة filter لوحدها، عشان التعليقات اللي بتحكي القصة ماتوقّعش الفحص
// (فخ الحارس-بيمسك-تعليقه)، ومن غير ما نعدّي بلوكات تانية بالغلط
ok('🔴 ومافيش أي فلتر CSS على بلاطات الخريطة — ألوان طبيعية في الوضعين (قرار صاحب النظام)',
  !/\.leaflet-tile\s*\{[^}]*filter/.test(C),
  'رجع فلتر على .leaflet-tile — صاحب النظام طلب شيله');

console.log('\n══ 3) صفحة الخصوصية صادقة ══');
const P = fs.readFileSync('public/privacy.html', 'utf8');
ok('CARTO اتشالت من جدول الأطراف الخارجية (مابقيناش بنبعتلها حاجة)',
  !P.includes('CARTO'));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — الخرايط كلها OSM ومفيش علامة مائية\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
