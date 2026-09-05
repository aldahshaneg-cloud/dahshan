/**
 * 📦 حارس: الطرود في فورم الطلب زي بوابة المحلات — الإدارة والفروع والكول سنتر
 * (طلب صاحب النظام 2026-09-05).
 *
 * اللي بنثبّته نصّيًا في التلات صفحات:
 * • شريط تبويبات مرقّمة فوق الطرود + «➕ طرد آخر»، وطرد واحد ظاهر في كل مرة.
 * • الرقم المعروض جوه الكارت = ترتيبه دلوقتي (بيتعاد بعد الحذف) مش رقم العدّاد.
 * • الحذف بيختفي لو ده الطرد الوحيد.
 * • فشل التحقق بيودّي للطرد الناقص وبيعلّمه بنقطة حمرا (parcelFail).
 * • صور لكل طرد: input ملفات + رفع على api.upload + `images` في الطرد المبعوت —
 *   والسيرفر بيكتبها في order_images (create) وشاشة التفاصيل بتعرضها.
 * • التفريغ بعد الحفظ بيمسح الصور والتبويبات.
 *
 * التشغيل: node ops/test_parcel_tabs.cjs
 */
const fs = require('fs');
const path = require('path');
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };

const PAGES = {
  tiar:       { container: 'deliveriesContainer',  prefix: 'drow-',  add: 'addDeliveryRow',       remove: 'removeDeliveryRow' },
  callcenter: { container: 'deliveriesContainer',  prefix: 'drow-',  add: 'addDeliveryRow',       remove: 'removeDeliveryRow' },
  branch:     { container: 'bDeliveriesContainer', prefix: 'bdrow-', add: 'addBranchDeliveryRow', remove: 'removeBranchDeliveryRow' },
};
for (const [page, p] of Object.entries(PAGES)) {
  const S = fs.readFileSync(path.join(__dirname, '..', 'public', page + '.html'), 'utf8');
  console.log('\n══ ' + page + ' ══');
  ok('شريط التبويبات فوق حاوية الطرود', S.includes('<div id="parcelTabs" class="parcel-tabs"></div>') && S.indexOf('id="parcelTabs"') < S.indexOf('id="' + p.container + '"'));
  ok('إعداد الطرود بيشاور على الحاوية والبادئة والإضافة الصح', S.includes('window.PARCEL = { container: "' + p.container + '", rowPrefix: "' + p.prefix + '", tabs: "parcelTabs", add: () => window.' + p.add + '() }'));
  ok('🔴 الرقم المعروض = الترتيب الحالي مش العدّاد', /lbl\.textContent = "📦 الطرد #" \+ \(i \+ 1\);/.test(S));
  ok('🔴 طرد واحد ظاهر في كل مرة', /rows\.forEach\(r => \{ r\.style\.display = parcelNoOf\(r\) === String\(n\) \? "" : "none"; \}\);/.test(S));
  ok('الحذف مخفي لو الطرد الوحيد', /rm\.style\.display = rows\.length > 1 \? "" : "none";/.test(S));
  ok('الإضافة بتوري الطرد الجديد', new RegExp('activeSel\\.id = `' + (page === 'branch' ? 'bDZone' : 'dZone') + '-\\$\\{n\\}`;\\n      showParcel\\(n\\);').test(S));
  ok('الحذف بيرجع لأول طرد وبيعيد الحسابات', S.includes('window.' + p.remove + ' = function (n) {') && S.includes('window.' + p.remove + 'Old(n);'));
  ok('فشل التحقق بيودّي للطرد الناقص', S.includes('window.parcelFail = function(n, msg) { window._parcelChecked = true; showParcel(n); showToast(msg, "error"); }')
      && S.includes('parcelFail(n, `يرجى إدخال اسم جهة التسليم — طرد #${parcelDisplayNo(n)}`)') && S.includes('parcelFail(n, `يرجى اختيار المنطقة — طرد #${parcelDisplayNo(n)}`)'));
  ok('🔴 صور لكل طرد: خانة رفع + تبعت مع الطرد', S.includes('id="pImgInput-${n}"') && /images: parcelImagesOf\(n\)/.test(S) && /await api\.upload\(file\)/.test(S));
  ok('الصورة اللي لسه بترفع مابتتبعتش', /filter\(x => typeof x === "string"\)/.test(S));
  ok('التفريغ بيمسح الصور والتبويبات', S.includes('innerHTML = ""; window._rowImages = {}; window._parcelChecked = false; window._activeParcel = null; renderParcelTabs();'));
  ok('شاشة التفاصيل بتعرض صور الطرد', /d\.images && d\.images\.length/.test(S));
  ok('CSS التبويبات والصور موجود', S.includes('.ptab.active {') && S.includes('.img-thumb {') && S.includes('.ptab.bad::after'));
}
/* السيرفر بيكتب الصور من create نفسه */
const C = fs.readFileSync(path.join(__dirname, '..', 'app', 'Http', 'Controllers', 'Api', 'OrdersController.php'), 'utf8');
console.log('\n══ السيرفر ══');
ok('create بيقرا images لكل طرد وبيكتبها في order_images', /'images'\s*=> is_array\(\$d\['images'\] \?\? null\)/.test(C) && /foreach \(\$p\['images'\] as \$url\)/.test(C));

console.log('\n════════════════════════════════════════');
console.log('PARCEL TABS: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail ? 1 : 0);
