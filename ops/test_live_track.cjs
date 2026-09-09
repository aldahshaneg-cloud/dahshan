/**
 * 🛵 حارس التتبّع الحي — الخرايط + تطبيق الطيار (2026-09-07)
 *
 * البلاغ: «الطيار بيثبت في مكان على الخريطة ومش بيظهر بشكل جيد».
 *  • الخرايط: الماركر كان بيتحط بـsetLatLng لحظة وصول النقطة = وقفة
 *    ونطّة. دلوقتي pilotmotion.js بيزحلقه على الأثر (متأخر ٢٠ث عشان
 *    الحركة تبقى مستمرة) ومعاه سهم اتجاه وخط أثر.
 *  • التطبيق: القراءة الباردة كل ١٥-٦٠ث اتبدّلت بتيار GPS وقت الشيل
 *    (LiveTrack) بيجمّع نقطة كل ٥ث ويبعت دفعة كل ١٥ث.
 *
 * التشغيل: node ops/test_live_track.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ 🔴 ' + what + (got ? '   ← ' + got : '')); }
};
const strip = s => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

const PM = fs.readFileSync('public/assets/js/pilotmotion.js', 'utf8');
const BR = fs.readFileSync('public/branch.html', 'utf8');
const TI = fs.readFileSync('public/tiar.html', 'utf8');

console.log('══ 1) pilotmotion.js ══');
ok('بيعرّف apply/remove/arrowHtml', /w\.PilotMotion = \{ apply: apply, remove: remove, arrowHtml: arrowHtml/.test(PM));
ok('  بيزحلق على الأثر بتأخير عرض (LAG_MS ≥ ١٥ث = فاصل الدفعة)',
   /var LAG_MS\s*=\s*(\d+)/.test(PM) && +PM.match(/var LAG_MS\s*=\s*(\d+)/)[1] >= 15000,
   'التأخير أقل من فاصل الدفعة — الماركر هيوقف يستنى');
ok('  الأثر البايت مابيتزحلقش عليه (snap)', /now - last\.t > STALE_MS/.test(PM) && /s\.mode = 'snap'/.test(PM));
ok('  ومفيش أثر = زحلقة بسيطة من مكان الماركر', /s\.mode = 'simple'/.test(PM) && /SIMPLE_MS/.test(PM));
ok('  والسهم بيتلف بالاتجاه', /a\.style\.transform = 'rotate\(' \+ Math\.round\(s\.heading\)/.test(PM));
ok('  وخط الأثر polyline على طبقة الأثر', /w\.L\.polyline\(lls/.test(PM));
// القاعدة عن الكود مش التعليقات — التعليقات العربية بتستعمل الباك-تيك للأسماء
ok('  مافيش template literals في الكود (قاعدة ٦)', !/`/.test(strip(PM)));

console.log('\n══ 2) الخرايط بتستعمله ══');
for (const [name, S] of [['branch.html', BR], ['tiar.html', TI]]) {
  const C = strip(S);
  const ap = C.slice(C.indexOf('function _applyPilots('), C.indexOf('function _applyOrders('));
  ok(name + ' بيحمّل pilotmotion.js', S.includes('src="assets/js/pilotmotion.js?v='));
  // الفرع بيبعت كمان all: 1 من 2026-09-09 («كل الطيارين» على الخريطة)
  ok('  وبيطلب الأثر (?trail=1)', /"\/api\/pilots",\s*\{[^}]*params: \{ trail: 1(, all: 1)? \}/.test(S) || /path: "\/api\/pilots",[^\n]*params: \{ trail: 1(, all: 1)? \}/.test(S),
     'الخريطة مش هتاخد الأثر — رجعت نطّ');
  ok('  و_applyPilots بينده PilotMotion.apply', /PilotMotion\.apply\(_pilotMarkers\[id\], p, \{ trailLayer: window\._layerTrails/.test(ap));
  ok('  ومابينقلش الماركر الموجود بـsetLatLng (ده شغل التنعيم)', !/_pilotMarkers\[id\]\.setLatLng/.test(ap),
     'رجعت النطّة');
  ok('  وبيشيل حالة الحركة مع الماركر', /delete _pilotMarkers\[id\];[^\n]*PilotMotion\.remove\(id\)/.test(ap));
  // الطبقة بتتعمل **قبل** طبقة الطيارين (ترتيب الإضافة = ترتيب الرسم) — التعليق في آخر السطر مسموح
  ok('  وطبقة الأثر تحت الماركرات', /window\._layerTrails\s*=\s*L\.layerGroup\(\)\.addTo\(_pilotMap\);[^\n]*\n\s*_layerPilots\s*=/.test(S));
  ok('  وسهم الاتجاه جوه الأيقونة', /PilotMotion\.arrowHtml\(\)/.test(S.slice(S.indexOf('function _mkPilotIcon('), S.indexOf('function _mkPilotIcon(') + 1500)));
}

console.log('\n══ 3) تطبيق الطيار (LiveTrack) ══');
const APP = strip(fs.readFileSync('../aldahshan/lib/main.dart', 'utf8'));
const API = fs.readFileSync('../aldahshan/lib/api_client.dart', 'utf8');
const PUB = fs.readFileSync('../aldahshan/pubspec.yaml', 'utf8');
ok('LiveTrack موجودة بتيار GPS', /class LiveTrack \{/.test(APP) && /Geolocator\.getPositionStream\(locationSettings: settings\)/.test(APP),
   'رجعنا للقراءة الباردة');
ok('  نقطة كل ~٥ث وفلتر ٨ متر', /intervalDuration: const Duration\(seconds: 5\)/.test(APP) && /distanceFilter: 8/.test(APP));
ok('  ودفعة كل ١٥ث', /_flushEvery = Duration\(seconds: 15\)/.test(APP) && /Api\.sendLocationBatch\(pts\)/.test(APP));
ok('  ونبضة كل دقيقة وهو واقف', /_heartbeat = Duration\(seconds: 60\)/.test(APP) && /'at': now\}\]/.test(APP));
ok('  والفشل بيرجّع النقاط للبفر', /_buf\.insertAll\(0, pts\)/.test(APP));
ok('  وبتتشغّل/تتقفل من الدورة الخلفية بحالة الشيل', /unawaited\(LiveTrack\.ensure\(pilotId, carrying\)\);/.test(APP),
   'التيار مش بيتفتح — الحل كله ميت');
ok('  والقراءة الباردة بتسكت طول ما التيار حي', /prefs\.getInt\(LiveTrack\.kStreamMs\)/.test(APP) && /if \(nowMs - streamMs < 45000\) return;/.test(APP),
   'GPS بارد فوق التيار = بطارية ×٢');
ok('  وقفل الخدمة بيفضّي البفر ويقفل التيار', /onDestroy\(DateTime timestamp\) async \{\s*\n\s*await LiveTrack\.stop\(flush: true\);/.test(APP));
ok('api_client بيبعت الدفعة على نفس المسار', /sendLocationBatch\(List<Map<String, dynamic>> points\)/.test(API) && /_post\('\/api\/pilot\/location', \{'points': points\}\)/.test(API));
/* النسخة مش مثبّتة برقم بعينه (كانت 2.5.7+31 وقت التتبّع الحي): المهم إن pubspec وkAppVersion
   نفس الرقم، وإنه مش أقل من 2.5.7 اللي فيها التتبّع الحي. */
const pubV = (PUB.match(/^version: (\d+\.\d+\.\d+)\+(\d+)/m) || [])[1] || '';
const appV = (APP.match(/kAppVersion = '(\d+\.\d+\.\d+)'/) || [])[1] || '';
const geq  = (a, b) => { const x = a.split('.').map(Number), y = b.split('.').map(Number); for (let i = 0; i < 3; i++) { if (x[i] !== y[i]) return x[i] > y[i]; } return true; };
ok('النسخة متسقة (pubspec = kAppVersion) ومش أقل من 2.5.7', pubV !== '' && pubV === appV && geq(pubV, '2.5.7'), pubV + ' / ' + appV);

console.log('\n════════════════════════════════════════');
console.log('LIVE TRACK (ui+app): ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
