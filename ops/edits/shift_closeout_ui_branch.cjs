/* 🔒💰 مودال تقفيلة الوردية في الفرع: إخلاء طرف العهدة + عمولة المشرف.
 *
 * ═══ ليه المسار السريع اتشال ═══
 * لما الطيار ماكانش معاه أوردرات، الزرار كان بيسأل confirm ويقفل على
 * طول من غير مودال. السيرفر بقى يرفض القفل والعهدة مش صفر — فالمسار
 * السريع كان هيطلّع للمشرف خطأ من غير أي خانة يسدد منها. المودال بقى
 * بيفتح دايمًا، وجواه خانة ردّ العهدة.
 *
 * ═══ خانة ردّ العهدة ═══
 * بتعرض «على الطيار حاليًا» + «المتوقع بعد التسوية» (الحالي + المستحق
 * − المحصَّل، بيتحدث لايف مع تغيير القرارات والمبلغ)، والخانة متعبية
 * بالمتوقع عشان الطبيعي هو إخلاء الطرف الكامل. الإيداع في نفس الخزنة
 * المختارة فوق — درج واحد قدام المشرف مش اتنين.
 *
 * ═══ العمولة ═══
 * أربع اختيارات: حسب حساب الطيار (الافتراضي — ولا كتابة) · نسبة % ·
 * مبلغ ثابت للأوردر · تحديد يدوي لكل أوردر (أوردر السفر). التحديد
 * اليدوي بيعرض أوردرات الوردية بخانة لكل واحد متعبية بحساب الطيار
 * الافتراضي، واللي بيتبعت منهم المتسلّم بس.
 *
 * 🔒 الحارس: ops/test_shift_closeout_ui.cjs
 */
const fs = require('fs');
const F = 'public/branch.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const L = (...x) => x.join('\n');
const D = String.fromCharCode(36);
const B = String.fromCharCode(96);
const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

if (s.includes('_seCustodyReturn')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① المسار السريع بيفتح المودال دايمًا ═══ */
one(
  L(
    '      if (activeOrders.length || pendingSettlementOrders.length) {',
    '        openShiftEndSettlementModal(shiftId, pilot, activeOrders, pendingSettlementOrders);',
    '        return;',
    '      }',
    '      if (!confirm(`هل تريد إنهاء وردية ' + D + '{pilot.name}؟`)) return;',
    '      await finalizeShiftEnd(shiftId, pilot);'
  ),
  L(
    '      /* المودال بيفتح دايمًا — حتى من غير أوردرات: فيه إخلاء طرف',
    '         العهدة والعمولة، والسيرفر بيرفض القفل والعهدة مش صفر. */',
    '      openShiftEndSettlementModal(shiftId, pilot, activeOrders, pendingSettlementOrders);'
  ),
  '① المسار السريع'
);

/* ═══ ② كتلتا العهدة والعمولة — بعد كتلة التحصيل ═══ */
one(
  L(
    '            <select id="_seCashStoreId" style="width:100%;padding:9px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.85rem;font-family:var(--font)">',
    '              <option value="">— اختر الخزنة —</option>',
    '              ' + D + '{returnStoreOptionsHtml}',
    '            </select>',
    '          </div>'
  ),
  L(
    '            <select id="_seCashStoreId" style="width:100%;padding:9px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.85rem;font-family:var(--font)">',
    '              <option value="">— اختر الخزنة —</option>',
    '              ' + D + '{returnStoreOptionsHtml}',
    '            </select>',
    '          </div>',
    '',
    '          <!-- 🔒 إخلاء طرف العهدة — السيرفر مش هيقفل الوردية والعهدة مش صفر -->',
    '          <div style="border:1px solid rgba(232,25,44,.35);border-radius:9px;padding:10px 12px;margin-bottom:10px;background:rgba(232,25,44,.05)">',
    '            <div style="font-weight:700;font-size:.86rem;color:var(--red);margin-bottom:6px">🔒 إخلاء طرف العهدة</div>',
    '            <div style="font-size:.78rem;color:var(--muted);margin-bottom:6px">',
    '              على الطيار حاليًا: <b style="color:var(--text)">' + D + '{ esc((Number(pilot.custody) || 0).toFixed(2)) } ج.م</b>',
    '              · المتوقع بعد التسوية: <b id="_seCustodyProjected" style="color:var(--red)">—</b>',
    '            </div>',
    '            <label style="font-size:.78rem;color:var(--muted);display:block;margin-bottom:5px">المبلغ المرجّع من العهدة للخزنة المختارة فوق:</label>',
    '            <input type="number" id="_seCustodyReturn" min="0" step="0.01" placeholder="0" style="width:100%;padding:9px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.9rem;font-family:var(--font)" />',
    '            <div style="font-size:.72rem;color:var(--muted);margin-top:5px">الوردية مش هتتقفل غير والعهدة صفر — ده إثبات إخلاء الطرف.</div>',
    '          </div>',
    '',
    '          <!-- 💰 عمولة التقفيلة — بتتكتب في سجل تعديلات العمولة وبتدخل مستحقات الطيار -->',
    '          <div style="border:1px solid rgba(14,165,233,.35);border-radius:9px;padding:10px 12px;margin-bottom:10px;background:rgba(14,165,233,.05)">',
    '            <div style="font-weight:700;font-size:.86rem;color:var(--sky);margin-bottom:8px">💰 عمولة الوردية</div>',
    '            <select id="_seCommMode" style="width:100%;padding:9px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.85rem;font-family:var(--font);margin-bottom:8px">',
    '              <option value="keep">حسب حساب الطيار (زي ما هو)</option>',
    '              <option value="percent">نسبة % من سعر توصيل كل أوردر</option>',
    '              <option value="fixed">مبلغ ثابت لكل أوردر</option>',
    '              <option value="custom">تحديد يدوي لكل أوردر (أوردر سفر…)</option>',
    '            </select>',
    '            <input type="number" id="_seCommValue" min="0" step="0.01" placeholder="القيمة" style="display:none;width:100%;padding:9px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.9rem;font-family:var(--font);margin-bottom:8px" />',
    '            <div id="_seCommCustom" style="display:none">' + D + '{',
    '              [...activeOrders, ...pendingSettlementOrders].map(o => ' + B + '',
    '                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">',
    '                  <span style="flex:1;font-size:.8rem">' + D + '{ esc(o.orderNum || "—") } <span style="color:var(--muted)">(' + D + '{ esc(o.totalDeliveryPrice || 0) } ج)</span></span>',
    '                  <input type="number" min="0" step="0.01" data-commorder="' + D + '{ esc(o.id) }"',
    '                         value="' + D + '{ esc(pilotCommissionFor(pilot, o).toFixed(2)) }"',
    '                         style="width:100px;padding:7px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.85rem;font-family:var(--font)" />',
    '                </div>' + B + ').join("") || ' + B + '<div style="font-size:.78rem;color:var(--muted)">مافيش أوردرات في الوردية دي</div>' + B + '',
    '            }</div>',
    '            <div style="font-size:.72rem;color:var(--muted)">«حسب حساب الطيار» = مافيش أي تعديل. غير كده بيتسجّل لكل أوردر متسلّم وبيدخل مستحقاته.</div>',
    '          </div>'
  ),
  '② كتلتا العهدة والعمولة'
);

/* ═══ ③ توقّع العهدة بيتحدث مع الحساب ═══ */
one(
  L(
    '      function recalcSeCollected() {',
    '        if (_seCollectedEdited) return;',
    '        let sum = 0;',
    '        activeOrders.forEach((o, idx) => {',
    '          const choice = box.querySelector(' + B + 'input[name="_se_' + D + '{idx}"]:checked' + B + ')?.value || "delivered";',
    '          if (choice === "delivered") sum += Number(o.totalDeliveryPrice) || 0;',
    '        });',
    '        pendingSettlementOrders.forEach(o => { sum += Number(o.totalDeliveryPrice) || 0; });',
    '        _seCollectedInput.value = sum || "";',
    '      }'
  ),
  L(
    '      /* المتوقع بعد التسوية = العهدة الحالية + (المستحق − المحصَّل).',
    '         بيتحدث مع كل تغيير قرار أو مبلغ، وخانة الردّ بتتعبى بيه طالما',
    '         المشرف ماكتبش فيها بإيده. */',
    '      const _seCustodyInput = document.getElementById("_seCustodyReturn");',
    '      let _seCustodyEdited = false;',
    '      _seCustodyInput.addEventListener("input", () => { _seCustodyEdited = true; });',
    '      function _seExpectedSum() {',
    '        let sum = 0;',
    '        activeOrders.forEach((o, idx) => {',
    '          const choice = box.querySelector(' + B + 'input[name="_se_' + D + '{idx}"]:checked' + B + ')?.value || "delivered";',
    '          if (choice === "delivered") sum += Number(o.totalDeliveryPrice) || 0;',
    '        });',
    '        pendingSettlementOrders.forEach(o => { sum += Number(o.totalDeliveryPrice) || 0; });',
    '        return sum;',
    '      }',
    '      function recalcSeCustody() {',
    '        const expected  = _seExpectedSum();',
    '        const collected = Number(_seCollectedInput.value) || 0;',
    '        const projected = Math.max(0, (Number(pilot.custody) || 0) + expected - collected);',
    '        const el = document.getElementById("_seCustodyProjected");',
    '        if (el) el.textContent = projected.toFixed(2) + " ج.م";',
    '        if (!_seCustodyEdited) _seCustodyInput.value = projected ? projected.toFixed(2) : "";',
    '      }',
    '      _seCollectedInput.addEventListener("input", recalcSeCustody);',
    '      function recalcSeCollected() {',
    '        if (_seCollectedEdited) { recalcSeCustody(); return; }',
    '        _seCollectedInput.value = _seExpectedSum() || "";',
    '        recalcSeCustody();',
    '      }',
    '      recalcSeCustody();',
    '',
    '      /* خانة قيمة العمولة والقايمة اليدوية بيبانوا حسب الاختيار */',
    '      document.getElementById("_seCommMode").addEventListener("change", function() {',
    '        document.getElementById("_seCommValue").style.display =',
    '          (this.value === "percent" || this.value === "fixed") ? "block" : "none";',
    '        document.getElementById("_seCommCustom").style.display =',
    '          this.value === "custom" ? "block" : "none";',
    '      });'
  ),
  '③ توقّع العهدة والتبديل'
);

/* ═══ ④ الإرسال: custodyReturn + commission ═══ */
one(
  L(
    '        const collectedAmount = Number(_seCollectedInput.value) || 0;',
    '        const collectStoreId = document.getElementById("_seCashStoreId").value;',
    '        if (collectedAmount > 0 && !collectStoreId) {',
    '          showToast("اختر الخزنة لإيداع المبلغ المحصَّل من الطيار", "error"); return;',
    '        }'
  ),
  L(
    '        const collectedAmount = Number(_seCollectedInput.value) || 0;',
    '        const collectStoreId = document.getElementById("_seCashStoreId").value;',
    '        if (collectedAmount > 0 && !collectStoreId) {',
    '          showToast("اختر الخزنة لإيداع المبلغ المحصَّل من الطيار", "error"); return;',
    '        }',
    '',
    '        /* 🔒 ردّ العهدة — نفس الخزنة المختارة فوق */',
    '        const custodyAmount = Number(document.getElementById("_seCustodyReturn").value) || 0;',
    '        if (custodyAmount > 0 && !collectStoreId) {',
    '          showToast("اختر الخزنة اللي هترجع لها العهدة", "error"); return;',
    '        }',
    '',
    '        /* 💰 العمولة */',
    '        const commMode = document.getElementById("_seCommMode").value;',
    '        let commission = null;',
    '        if (commMode === "percent" || commMode === "fixed") {',
    '          const v = Number(document.getElementById("_seCommValue").value);',
    '          if (!(v >= 0)) { showToast("اكتب قيمة العمولة", "error"); return; }',
    '          if (commMode === "percent" && v > 100) { showToast("النسبة مينفعش تعدّي 100%", "error"); return; }',
    '          commission = { mode: commMode, value: v };',
    '        } else if (commMode === "custom") {',
    '          /* المتسلّم بس — اللي اتعلّم عليه «لم يتم» ماياخدش عمولة */',
    '          const deliveredIds = new Set(pendingSettlementOrders.map(o => String(o.id)));',
    '          activeOrders.forEach((o, idx) => {',
    '            const choice = box.querySelector(' + B + 'input[name="_se_' + D + '{idx}"]:checked' + B + ')?.value || "delivered";',
    '            if (choice === "delivered") deliveredIds.add(String(o.id));',
    '          });',
    '          const perOrder = [...box.querySelectorAll("[data-commorder]")]',
    '            .filter(inp => deliveredIds.has(inp.getAttribute("data-commorder")) && inp.value !== "")',
    '            .map(inp => ({ orderId: Number(inp.getAttribute("data-commorder")), amount: Number(inp.value) || 0 }));',
    '          commission = { mode: "custom", perOrder };',
    '        }'
  ),
  '④أ قراءة الخانات'
);

one(
  L(
    '          await finalizeShiftEnd(shiftId, pilot, {',
    '            orders: decisions,',
    '            collectedAmount,',
    '            cashStoreId: collectStoreId || null',
    '          });'
  ),
  L(
    '          await finalizeShiftEnd(shiftId, pilot, {',
    '            orders: decisions,',
    '            collectedAmount,',
    '            cashStoreId: collectStoreId || null,',
    '            custodyReturn: custodyAmount > 0 ? { amount: custodyAmount, cashStoreId: collectStoreId } : null,',
    '            commission,',
    '          });'
  ),
  '④ب الإرسال'
);

/* ═══ ⑤ رسالة النجاح بتقول الإثبات ═══ */
one(
  '        await api.post(`/api/shifts/' + D + '{shiftId}/end`, settlementBody || {});\n        showToast("تمّ إنهاء وردية الطيار — وأصبح متاحًا لأي فرع ✓", "success");',
  L(
    '        const endRes = await api.post(' + B + '/api/shifts/' + D + '{shiftId}/end' + B + ', settlementBody || {});',
    '        let doneMsg = "تمّ إنهاء وردية الطيار — وأصبح متاحًا لأي فرع ✓";',
    '        if (Number(endRes.custodyReturned) > 0) doneMsg += ' + B + ' — 🔒 أخلى طرف: رجّع ' + D + '{Number(endRes.custodyReturned).toFixed(2)} ج للخزنة' + B + ';',
    '        showToast(doneMsg, "success");'
  ),
  '⑤ رسالة الإثبات'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ مودال الفرع اتحدث');
