/* `POST /api/orders` بقى يقبل «الطرد ده بيانات مستلمه على صورة الريسيت».
 *
 * ═══ الطلب (صاحب النظام 2026-08-31) ═══
 * «في تطبيق المحلات كمان عايز زرار مش معايا بيانات المستلم، أحط المنطقة
 * وصور الريسيت وخلاص».
 *
 * ═══ ليه السيرفر لازم يتعدّل هنا (بعكس تطبيق العميل) ═══
 * تطبيق العميل بيبعت على `POST /api/customer/orders` وده **بيقرا
 * `fromReceipt` من الأصل** ويكتب العمود. أما المحل فبيبعت على
 * `POST /api/orders` (OrdersController::store) واللي:
 *   ① بيفرض اسم مستلم لكل طرد ويرمي «يرجى إدخال اسم جهة التسليم لكل طرد»
 *   ② **مابيكتبش** عمود `receiver_from_receipt` خالص — مش في الـINSERT
 * فمن غير التعديل ده، طرد المحل اللي بصورة ريسيت هيتخزّن بعلامة صفر،
 * ولوحة الفرع مش هتوري شارة «العنوان على صورة الريسيت» للطيار.
 *
 * ═══ إضافة صافية — مافيش بوابة بتتفتح ═══
 * • العمود موجود في المخطط من الأصل: `tinyint(1) NOT NULL DEFAULT 0`
 *   — يعني مافيش هجرة ولا `schema:verify` بيتأثر.
 * • `fromReceipt` حقل **دخول** مش خرج، فشكل الرد ما اتغيّرش و`wire:verify`
 *   بيفضل زي ما هو. والسلك بيطلّع `receiverFromReceipt` من العمود ده
 *   أصلًا (OrderWire:164) — يعني اللوحات هتشوف الشارة من غير أي تعديل.
 * • المسار نفسه ما اتغيّرش، فـ`route:coverage` ما بيتأثرش.
 *
 * ═══ الاسم الفاضي ═══
 * الفحص القديم بيفضل شغّال للطرد العادي. الطرد اللي بريسيت بياخد نص
 * واضح بدل ما يترمي — نفس اللي تطبيق العميل بيعمله بالحرف، عشان جداول
 * الفرع والإدارة وتطبيق الطيار ما تبانش فيها خانة فاضية.
 *
 * 🔒 الحارس: ops/test_store_receipt.php
 */
const fs = require('fs');
const F = 'app/Http/Controllers/Api/OrdersController.php';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');

/* ═══ ① قراءة العلامة + الاسم البديل ═══ */
one(
  L('                    $recvName = trim((string) ($d[\'receiverName\'] ?? \'\'));',
    '                    if ($recvName === \'\') {',
    '                        throw new ApiException(\'يرجى إدخال اسم جهة التسليم لكل طرد\');',
    '                    }'),
  L('                    /* 🧾 «مش معايا بيانات المستلم» — **لكل طرد لوحده**.',
    '                       بوابة المحل بتبعتها من 2026-08-31، وتطبيق العميل',
    '                       بيبعت نفس المفتاح على مساره هو. الطرد اللي عليها',
    '                       عنوانه وبيانات مستلمه على صورة الريسيت المرفقة. */',
    '                    $fromReceipt = ! empty($d[\'fromReceipt\']);',
    '                    $recvName = trim((string) ($d[\'receiverName\'] ?? \'\'));',
    '                    if ($recvName === \'\') {',
    '                        if (! $fromReceipt) {',
    '                            throw new ApiException(\'يرجى إدخال اسم جهة التسليم لكل طرد\');',
    '                        }',
    '                        /* نص واضح بدل الفاضي — جداول الفرع والإدارة وتطبيق',
    '                           الطيار بتعرض العمود ده، وخانة فاضية بتبان غلطة.',
    '                           نفس نص تطبيق العميل بالحرف عشان الاتنين يبانوا واحد. */',
    '                        $recvName = \'🧾 البيانات على صورة الريسيت\';',
    '                    }'),
  '① قراءة العلامة');

/* ═══ ② تخزينها في مصفوفة الطرد ═══ */
one(
  L('                        \'receiver_name\'   => $recvName,',
    '                        \'receiver_phone\'  => self::trimOrNull($d[\'receiverPhone\'] ?? null),',
    '                        \'receiver_phone2\' => self::trimOrNull($d[\'receiverPhone2\'] ?? null),'),
  L('                        \'receiver_name\'   => $recvName,',
    '                        \'receiver_phone\'  => self::trimOrNull($d[\'receiverPhone\'] ?? null),',
    '                        \'receiver_phone2\' => self::trimOrNull($d[\'receiverPhone2\'] ?? null),',
    '                        \'from_receipt\'    => $fromReceipt ? 1 : 0,'),
  '② في مصفوفة الطرد');

/* ═══ ③ العمود في الـINSERT ═══ */
one(
  L('                        \'INSERT INTO order_deliveries',
    '                           (order_id, parcel_no, receiver_id, receiver_name, receiver_phone, receiver_phone2,',
    '                            zone_id, zone_name, zone_price, order_price, address, note, status, lat, lng, created_at)',
    '                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)\',',
    '                        [',
    '                            $orderId, $p[\'parcel_no\'], $p[\'receiver_id\'], $p[\'receiver_name\'],',
    '                            $p[\'receiver_phone\'], $p[\'receiver_phone2\'],',
    '                            $p[\'zone_id\'], $p[\'zone_name\'], $p[\'zone_price\'], $p[\'order_price\'],',
    '                            $p[\'address\'], $p[\'note\'], \'processing\', $p[\'lat\'], $p[\'lng\'], $now,',
    '                        ]'),
  L('                        /* `receiver_from_receipt` اتضاف 2026-08-31: العمود كان',
    '                           موجود في المخطط ومحدش بيكتبه من المسار ده، فطرود',
    '                           المحل كانت بتتخزّن دايمًا بصفر ولوحة الفرع ما بتوريش',
    '                           شارة «العنوان على صورة الريسيت». */',
    '                        \'INSERT INTO order_deliveries',
    '                           (order_id, parcel_no, receiver_id, receiver_name, receiver_phone, receiver_phone2,',
    '                            receiver_from_receipt,',
    '                            zone_id, zone_name, zone_price, order_price, address, note, status, lat, lng, created_at)',
    '                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)\',',
    '                        [',
    '                            $orderId, $p[\'parcel_no\'], $p[\'receiver_id\'], $p[\'receiver_name\'],',
    '                            $p[\'receiver_phone\'], $p[\'receiver_phone2\'],',
    '                            $p[\'from_receipt\'],',
    '                            $p[\'zone_id\'], $p[\'zone_name\'], $p[\'zone_price\'], $p[\'order_price\'],',
    '                            $p[\'address\'], $p[\'note\'], \'processing\', $p[\'lat\'], $p[\'lng\'], $now,',
    '                        ]'),
  '③ العمود في الـINSERT');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ السيرفر بقى يقبل fromReceipt من بوابة المحل');
