/* 🔴 رسالة واضحة لما اسم المنطقة يتكرر في نفس الفرع.
 *
 * ═══ حصل النهاردة على الإنتاج — أول يوم شغل ═══
 * سجل لارافل 2026-09-01 فيه ٨ أخطاء، كلهم نوع واحد:
 *     Duplicate entry 'طريق الفوتو سيشن-53' for key 'uq_zones_area_branch'
 *     Duplicate entry 'منشية البدوي-54'    for key 'uq_zones_area_branch'
 * يعني حد كان بيضيف مناطق، والنظام بيرفض، وهو بيعيد المحاولة — ٨ مرات.
 *
 * ═══ ليه كرّر ═══
 * `zonesCreate` و`zonesUpdate` **مش** بيمسكوا الخطأ ده، فبيوصل للمعالج
 * العام (`bootstrap/app.php:176`) اللي بيرجّع:
 *     «خطأ في قاعدة البيانات»
 * رسالة مابتقولش إيه الغلط ولا إيه الحل. المستخدم مش عارف إن الاسم
 * موجود أصلًا في نفس الفرع، فبيعيد نفس الإدخال.
 *
 * ═══ الشكل موجود في نفس الملف ═══
 * `EntitiesController` بيمسك 1062 في **خمس** دوال تانية برسالة عربية
 * واضحة («كود الفرع مستخدم قبل كده»). المناطق كانت الشاذة.
 *
 * ═══ القيد ═══
 * `UNIQUE (area_name, delivery_branch_id)` — يعني نفس الاسم مسموح في
 * فرع تاني. الرسالة بتقول ده صراحةً عشان المستخدم يعرف إن المشكلة في
 * الفرع ده بالذات مش في الاسم نفسه.
 *
 * 🔒 الحارس: ops/test_zone_dup.php
 */
const fs = require('fs');
const F = 'app/Http/Controllers/Api/EntitiesController.php';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');

/* ═══ ① الإنشاء ═══ */
one(
  L('        DB::insert(',
    "            'INSERT INTO zones (area_name, price, delivery_branch_id, source_branch_id, created_at) VALUES (?,?,?,?,?)',",
    '            [$areaName, $price, $deliveryBranchId, $sourceBranchId, WireTime::nowDb()]',
    '        );',
    '',
    "        return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);"),
  L('        /* 🔴 القيد `UNIQUE (area_name, delivery_branch_id)`. من غير',
    '           المصيدة دي الخطأ كان بيوصل للمعالج العام ويرجع «خطأ في',
    '           قاعدة البيانات» — رسالة مابتقولش الغلط ولا الحل، فالمستخدم',
    '           بيعيد نفس الإدخال. حصل فعلًا أول يوم شغل: ٨ محاولات لنفس',
    '           المنطقتين في سجل 2026-09-01. */',
    '        try {',
    '            DB::insert(',
    "                'INSERT INTO zones (area_name, price, delivery_branch_id, source_branch_id, created_at) VALUES (?,?,?,?,?)',",
    '                [$areaName, $price, $deliveryBranchId, $sourceBranchId, WireTime::nowDb()]',
    '            );',
    '        } catch (QueryException $e) {',
    '            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {',
    "                throw new ApiException('«' . \$areaName . '» موجودة قبل كده في نفس فرع التوصيل — غيّر الاسم أو اختار فرع تاني');",
    '            }',
    "            Log::error('zones_create: ' . \$e->getMessage());",
    "            throw new ApiException('خطأ أثناء حفظ المنطقة', 500);",
    '        }',
    '',
    "        return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);"),
  '① الإنشاء');

/* ═══ ② التعديل — نفس القيد بيضرب هنا كمان ═══ */
one(
  L("        DB::update('UPDATE zones SET ' . implode(', ', $fields) . ' WHERE id = ?', $vals);"),
  L('        /* نفس القيد بيضرب عند التعديل كمان: تغيير الاسم لاسم موجود',
    '           في نفس الفرع بيرمي 1062. */',
    '        try {',
    "            DB::update('UPDATE zones SET ' . implode(', ', \$fields) . ' WHERE id = ?', \$vals);",
    '        } catch (QueryException $e) {',
    '            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {',
    "                throw new ApiException('في منطقة بنفس الاسم في نفس فرع التوصيل — غيّر الاسم');",
    '            }',
    "            Log::error('zones_update: ' . \$e->getMessage());",
    "            throw new ApiException('خطأ أثناء تعديل المنطقة', 500);",
    '        }'),
  '② التعديل');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ الرسالة بقت واضحة');
