/* بانر «طلب جديد وصل للفرع» كان بيكدب مع كل تفريق طرد.
 *
 * ═══ البلاغ ═══
 * صاحب النظام 2026-08-31: «في تطبيق الفروع لما بطيّر طرد واحد من عملية
 * واحدة فيها كذا طرد، بيجي فوق نوتيفيكيشن إن في أوردر جديد ومفيش أوردر
 * جديد ولا حاجة».
 *
 * ═══ السبب ═══
 * التفريق (`POST /api/orders/{id}/split`) **بيعمل صف `orders` جديد** —
 * مابينقلش الطرد. الصف الجديد بياخد معرّف جديد وحالة `delivering` على طول
 * (OrdersController.php ~720-736). والشرط هنا كان بيحكم بالمعرّف بس:
 * أي معرّف مشفناهوش = «طلب جديد». فالتفريق كان بيرن ويعرض بانر لشحنة
 * موجودة عند الفرع من الأصل.
 *
 * الكنترولر نفسه عارف إن التفريق مش شحنة جديدة، ومكتوب فيه بالحرف عند
 * رفضه ينده `notifyOrderReceivers`: «التفريق بيعمل صف orders جديد، بس
 * مابيعملش شحنة جديدة». نفس المنطق كان ناقص هنا.
 *
 * ═══ ليه `splitFrom` وبس مش كفاية ═══
 * لو استثنينا أي أوردر عليه `splitFrom`، هنخرّس حالة مشروعة: جزء مفصول
 * **اتنقل لفرع تاني**. `transferBranch` بيعمل `UPDATE orders SET branch_id`
 * (OrdersController.php:1228) — بينقل الصف مابيعملش صف جديد — فالفرع
 * المستقبِل بيشوف معرّف لأول مرة وأبوه مش عنده. دي شحنة داخلة عليه فعلًا
 * ولازم يسمعها. عشان كده الشرط بيسأل كمان: **أبوه عندنا؟**
 *
 * ═══ ليه مش من السيرفر ═══
 * `splitFrom` موجود على السلك أصلًا (OrderWire.php:284) وبيوصل هنا فعلًا
 * (`baseSql` بتعمل `SELECT o.*`، و`ID_FIELDS` سطر 107 فيها "splitFrom").
 * فالإصلاح واجهة بس — مافيش حقل جديد ولا «إضافة مقصودة» ولا بوابة تتفتح.
 *
 * ملحوظة: المصدر واحد مش اتنين. البثّ اللحظي بينده `ordersPoller.tick()`
 * (سطر ~771) يعني بيعدّي من نفس الشرط ده بالظبط.
 *
 * 🔒 الحارس: ops/test_split_banner.cjs
 */
const fs = require('fs');
const F = 'public/branch.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

/* ── ① الشرط ── */
one(
[ '        // Detect new orders for notification',
  '        if (!window._isFirstLoad) {',
  '          branchOrders.forEach(o => {',
  '            if (!window._knownOrderIds.has(o.id) && o.status !== "تم التسليم") {',
  '              playNotificationSound();',
  '              showNewOrderBanner(o);',
  '            }',
  '          });',
  '        }' ].join('\n'),
[ '        // Detect new orders for notification',
  '        if (!window._isFirstLoad) {',
  '          /* معرّفات الفرع كلها في اللحظة دي — لازمتها إن الأب والابن ممكن',
  '             يوصلوا في **نفس** النبضة (أوردر اتعمل واتفرّق جوه الـ60 ثانية)،',
  '             والأب ساعتها لسه مش في `_knownOrderIds` لأن التسجيل تحت بعد',
  '             الفحص. من غيرها الابن هيرن في الحالة دي. */',
  '          const idsNow = new Set(branchOrders.map(o => o.id));',
  '          branchOrders.forEach(o => {',
  '            if (isRealArrival(o, idsNow)) {',
  '              playNotificationSound();',
  '              showNewOrderBanner(o);',
  '            }',
  '          });',
  '        }' ].join('\n'),
'شرط البانر');

/* ── ② الدالة ── */
one(
'    function showNewOrderBanner(order) {',
[ '    /* ═══ مين يستاهل بانر «طلب جديد وصل للفرع» ═══',
  '       البانر معناه «شحنة دخلت الفرع من برّه»، مش «صف جديد في جدول',
  '       orders». التفريق بيعمل صف جديد على **نفس** الفرع بحالة «جاري',
  '       التوصيل» فورًا، فكان بيعدّي على شرط «معرّف جديد» وهو مش وصول —',
  '       الطرود اتنقلت من الأصل بـUPDATE مااتعملتش من جديد.',
  '',
  '       شرط «أبوه عندنا» مش زيادة: لو الجزء المفصول اتنقل لفرع تاني',
  '       (transferBranch بيعمل UPDATE على branch_id) فالأب فضل في فرعه',
  '       القديم والفرع المستقبِل مايعرفوش — ساعتها ده **وصول حقيقي**',
  '       ولازم يرن. */',
  '    function isRealArrival(o, idsNow) {',
  '      // شفناه قبل كده → مش وصول (نقل طيار · تغيير حالة · تعديل)',
  '      if (window._knownOrderIds.has(o.id)) return false;',
  '      // اتسلّم خلاص → مالوش لازمة يرن',
  '      if (o.status === "تم التسليم") return false;',
  '      // جزء مفصول وأبوه عند الفرع → الشحنة موجودة عندنا من الأصل',
  '      if (o.splitFrom && (window._knownOrderIds.has(o.splitFrom) || idsNow.has(o.splitFrom))) return false;',
  '      return true;',
  '    }',
  '',
  '    function showNewOrderBanner(order) {' ].join('\n'),
'دالة isRealArrival');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ الشرط اتظبط');
