/* شاشة «عملائي» — المستلمون المحفوظون.
 *
 * ═══ البلاغ ═══
 * «لو هو تاجر أونلاين وبيبعت لمستلم، عايز بياناته تتحفظ زي عناويني
 *  الموجودة في الصفحة الرئيسية، علشان لما أحتاج أبعت لنفس العميل مرة
 *  تانية يبعت مباشرة من العنوان نفسه».
 *
 * ═══ اللي كان موجود ═══
 * الحفظ **شغّال أصلًا**: `customer_saved_receivers` بيتكتب فيه مع كل
 * أوردر (upsert على التليفون)، والفورم بيعرضهم كشيبات اختيار سريع جوه
 * بلوك المستلم. اللي كان ناقص هو **الإدارة**: مفيش شاشة يشوفهم فيها
 * ولا يمسح منهم، على عكس «العناوين المحفوظة» اللي ليها شاشة كاملة.
 *
 * ═══ اللي اتضاف ═══
 *  • مسار حذف على السيرفر (كان فيه قايمة وحفظ بس).
 *  • شاشة `s-clients` بنفس شكل شاشة العناوين.
 *  • صف في الرئيسية وفي الحساب.
 *  • زرار «ابعت له» بيفتح طلب جديد وبيملا بيانات المستلم على طول.
 */
const fs = require('fs');

let bad = 0;
const edit = (file, pairs) => {
  let s = fs.readFileSync(file, 'utf8');
  const eol = s.includes('\r\n') ? '\r\n' : '\n';
  if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');
  for (const [old] of pairs) {
    const n = s.split(old).length - 1;
    if (n !== 1) { console.log(`  🔴 ${file}: اتلقت ${n} مرة — «${old.trim().slice(0, 55)}»`); bad++; return; }
  }
  for (const [old, neu] of pairs) s = s.replace(old, neu);
  fs.writeFileSync(file, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
  console.log('  ✓ ' + file);
};

/* ═══ ① السيرفر: مسار الحذف ═══ */
edit('app/Http/Controllers/Api/CustomerAppController.php', [
  [`    /* ═══════════════════════════════════════════════════════════════
       المستلمون المحفوظون — قايمة + upsert بالتليفون (saveReceiver القديمة)
    ═══════════════════════════════════════════════════════════════ */`,
   `    /**
     * DELETE /api/customer/receivers/{id} — شيل مستلم محفوظ.
     *
     * الحذف بيشيل الاختصار بس — الأوردرات القديمة اللي راحت للمستلم ده
     * مابتتلمسش، لأن بياناتها متخزّنة في \`order_deliveries\` نفسها.
     * الشرط على \`customer_id\` هو الحارس: محدش يمسح مستلم حد تاني.
     */
    public function receiversDelete(Request $request, string $id): JsonResponse
    {
        $c = $this->customerRequire($request);

        $n = DB::delete(
            'DELETE FROM customer_saved_receivers WHERE id = ? AND customer_id = ?',
            [(int) $id, (int) $c['id']]
        );
        if ($n === 0) {
            throw new ApiException('المستلم غير موجود', 404);
        }

        $rows = DB::select(
            'SELECT * FROM customer_saved_receivers WHERE customer_id = ?
              ORDER BY created_at DESC, id DESC LIMIT 50',
            [(int) $c['id']]
        );

        return ApiResponse::ok(['items' => array_map([CustomerAppWire::class, 'savedReceiver'], $rows)]);
    }

    /* ═══════════════════════════════════════════════════════════════
       المستلمون المحفوظون — قايمة + upsert بالتليفون (saveReceiver القديمة)
    ═══════════════════════════════════════════════════════════════ */`],
]);

/* ═══ ② المسار ═══ */
edit('routes/api.php', [
  [`Route::delete('customer/addresses/{id}', [CustomerAppController::class, 'addressesDelete']);`,
   `Route::delete('customer/addresses/{id}', [CustomerAppController::class, 'addressesDelete']);
/* المستلمون المحفوظون: كان فيه قايمة وحفظ بس — الحذف اتضاف مع شاشة
   «عملائي» عشان التاجر يقدر يشيل مستلم مابيتعاملش معاه تاني. */
Route::delete('customer/receivers/{id}', [CustomerAppController::class, 'receiversDelete']);`],
]);

console.log(bad ? `\n🔴 ${bad} مشكلة` : '\n✅ السيرفر جاهز');
process.exit(bad ? 1 : 0);
