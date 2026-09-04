/* 🔒💰 مودال تقفيلة الوردية في لوحة الإدارة — نفس كتلتي الفرع + قرار الترحيل.
 *
 * الفرق الوحيد عن الفرع: خانة «قفل مع ترحيل باقي العهدة» — قرار إداري
 * السيرفر مش بيقبله غير من الأدمن وبعلم صريح، والباقي بيتسجّل على
 * الوردية عشان التقرير يقول إن ده مش إخلاء طرف كامل.
 *
 * وردّ العهدة هنا له خزنته الخاصة (`_seCustodyStore`) لأن التحصيل ممكن
 * يكون «لم يتم بعد» فمافيش خزنة مختارة أصلًا.
 *
 * 🔒 الحارس: ops/test_shift_closeout_ui.cjs
 */
const fs = require('fs');
const F = 'public/tiar.html';
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
    '      /* المودال بيفتح دايمًا — فيه إخلاء طرف العهدة والعمولة،',
    '         والسيرفر بيرفض القفل والعهدة مش صفر. */',
    '      openShiftEndSettlementModal(shiftId, pilot, activeOrders, pendingSettlementOrders);'
  ),
  '① المسار السريع'
);

/* ═══ ② الكتل — بعد كتلة التحصيل وقبل الأزرار ═══ */
one(
  L(
    '            </div>',
    '          </div>',
    '          <div style="display:flex;gap:10px;margin-top:12px">',
    '            <button id="_shiftEndConfirm" style="flex:1;background:var(--red);color:#fff;border:none;padding:11px;border-radius:8px;font-weight:700;cursor:pointer">✅ تسوية وإنهاء الوردية</button>'
  ),
  L(
    '            </div>',
    '          </div>',
    '',
    '          <!-- 🔒 إخلاء طرف العهدة -->',
    '          <div style="border:1px solid rgba(232,25,44,.35);border-radius:9px;padding:12px 14px;margin-top:10px;background:rgba(232,25,44,.05)">',
    '            <div style="font-size:.88rem;font-weight:700;color:var(--red);margin-bottom:6px">🔒 إخلاء طرف العهدة</div>',
    '            <div style="font-size:.78rem;color:var(--muted);margin-bottom:6px">',
    '              على الطيار حاليًا: <b style="color:var(--text)">' + D + '{ esc((Number(pilot.custody) || 0).toFixed(2)) } ج.م</b>',
    '              · المتوقع بعد التسوية: <b id="_seCustodyProjected" style="color:var(--red)">—</b>',
    '            </div>',
    '            <div style="display:flex;gap:8px;margin-bottom:6px">',
    '              <input type="number" id="_seCustodyReturn" min="0" step="0.01" placeholder="المبلغ المرجّع" style="flex:1;padding:8px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.83rem" />',
    '              <select id="_seCustodyStore" style="flex:1;padding:8px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.83rem">',
    '                <option value="">— خزنة الردّ —</option>',
    '                ' + D + '{(window._cashStoresData || []).map(cs => ' + B + '<option value="' + D + '{ esc(cs.id) }">' + D + '{ esc(cs.name) }</option>' + B + ').join("")}',
    '              </select>',
    '            </div>',
    '            <label style="display:flex;align-items:center;gap:7px;font-size:.78rem;color:var(--orange);cursor:pointer">',
    '              <input type="checkbox" id="_seCustodyCarry" style="accent-color:var(--orange)" />',
    '              قفل مع ترحيل باقي العهدة على الطيار (قرار إداري — بيتسجّل على الوردية)',
    '            </label>',
    '          </div>',
    '',
    '          <!-- 💰 عمولة التقفيلة -->',
    '          <div style="border:1px solid rgba(14,165,233,.35);border-radius:9px;padding:12px 14px;margin-top:10px;background:rgba(14,165,233,.05)">',
    '            <div style="font-size:.88rem;font-weight:700;color:var(--sky);margin-bottom:8px">💰 عمولة الوردية</div>',
    '            <select id="_seCommMode" style="width:100%;padding:8px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.83rem;margin-bottom:8px">',
    '              <option value="keep">حسب حساب الطيار (زي ما هو)</option>',
    '              <option value="percent">نسبة % من سعر توصيل كل أوردر</option>',
    '              <option value="fixed">مبلغ ثابت لكل أوردر</option>',
    '              <option value="custom">تحديد يدوي لكل أوردر (أوردر سفر…)</option>',
    '            </select>',
    '            <input type="number" id="_seCommValue" min="0" step="0.01" placeholder="القيمة" style="display:none;width:100%;padding:8px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.83rem;margin-bottom:8px" />',
    '            <div id="_seCommCustom" style="display:none">' + D + '{',
    '              [...activeOrders, ...pendingSettlementOrders].map(o => ' + B + '',
    '                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">',
    '                  <span style="flex:1;font-size:.8rem">' + D + '{ esc(o.orderNum || "—") } <span style="color:var(--muted)">(' + D + '{ esc(o.totalDeliveryPrice || 0) } ج)</span></span>',
    '                  <input type="number" min="0" step="0.01" data-commorder="' + D + '{ esc(o.id) }"',
    '                         value="' + D + '{ esc(pilotCommissionFor(pilot, o).toFixed(2)) }"',
    '                         style="width:100px;padding:7px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:7px;font-size:.83rem" />',
    '                </div>' + B + ').join("") || ' + B + '<div style="font-size:.78rem;color:var(--muted)">مافيش أوردرات في الوردية دي</div>' + B + '',
    '            }</div>',
    '            <div style="font-size:.72rem;color:var(--muted)">«حسب حساب الطيار» = مافيش أي تعديل. غير كده بيتسجّل لكل أوردر متسلّم وبيدخل مستحقاته.</div>',
    '          </div>',
    '          <div style="display:flex;gap:10px;margin-top:12px">',
    '            <button id="_shiftEndConfirm" style="flex:1;background:var(--red);color:#fff;border:none;padding:11px;border-radius:8px;font-weight:700;cursor:pointer">✅ تسوية وإنهاء الوردية</button>'
  ),
  '② الكتل'
);

/* ═══ ③ التوقع والتبديل — بعد appendChild ═══ */
one(
  L(
    '      box.querySelectorAll("input[name=\'_seCashCollect\']").forEach(r => {',
    '        r.addEventListener("change", () => {',
    '          document.getElementById("_seCashCollectDetails").style.display = r.value === "yes" ? "block" : "none";',
    '        });',
    '      });'
  ),
  L(
    '      box.querySelectorAll("input[name=\'_seCashCollect\']").forEach(r => {',
    '        r.addEventListener("change", () => {',
    '          document.getElementById("_seCashCollectDetails").style.display = r.value === "yes" ? "block" : "none";',
    '          recalcSeCustody();',
    '        });',
    '      });',
    '',
    '      /* المتوقع بعد التسوية = العهدة الحالية + (المستحق − المحصَّل).',
    '         «لم يتم بعد» = محصَّل صفر فكله بيروح عهدة والخانة بتتعبى بيه. */',
    '      const _seCustodyInput = document.getElementById("_seCustodyReturn");',
    '      let _seCustodyEdited = false;',
    '      _seCustodyInput.addEventListener("input", () => { _seCustodyEdited = true; });',
    '      function recalcSeCustody() {',
    '        const collectChoice = box.querySelector("input[name=\'_seCashCollect\']:checked")?.value || "no";',
    '        const collected = collectChoice === "yes"',
    '          ? (Number(document.getElementById("_seCollectAmount")?.value) || 0) : 0;',
    '        let expected = 0;',
    '        activeOrders.forEach((o, idx) => {',
    '          const choice = box.querySelector(' + B + 'input[name="_se_' + D + '{idx}"]:checked' + B + ')?.value || "delivered";',
    '          if (choice === "delivered") expected += Number(o.totalDeliveryPrice) || 0;',
    '        });',
    '        pendingSettlementOrders.forEach(o => { expected += Number(o.totalDeliveryPrice) || 0; });',
    '        const projected = Math.max(0, (Number(pilot.custody) || 0) + expected - collected);',
    '        const el = document.getElementById("_seCustodyProjected");',
    '        if (el) el.textContent = projected.toFixed(2) + " ج.م";',
    '        if (!_seCustodyEdited) _seCustodyInput.value = projected ? projected.toFixed(2) : "";',
    '      }',
    '      document.getElementById("_seCollectAmount")?.addEventListener("input", recalcSeCustody);',
    '      recalcSeCustody();',
    '',
    '      document.getElementById("_seCommMode").addEventListener("change", function() {',
    '        document.getElementById("_seCommValue").style.display =',
    '          (this.value === "percent" || this.value === "fixed") ? "block" : "none";',
    '        document.getElementById("_seCommCustom").style.display =',
    '          this.value === "custom" ? "block" : "none";',
    '      });'
  ),
  '③ التوقع والتبديل'
);

/* التوقع بيتحدث مع تغيير قرارات الأوردرات كمان */
one(
  L(
    '        box.querySelectorAll(`input[name="_se_' + D + '{idx}"]`).forEach(radio => {',
    '          radio.addEventListener("change", () => {',
    '            const wrap = document.getElementById(`_seUndelReasonWrap_' + D + '{idx}`);',
    '            if (wrap) wrap.style.display = radio.value === "undelivered" && radio.checked ? "block" : "none";',
    '          });',
    '        });'
  ),
  L(
    '        box.querySelectorAll(`input[name="_se_' + D + '{idx}"]`).forEach(radio => {',
    '          radio.addEventListener("change", () => {',
    '            const wrap = document.getElementById(`_seUndelReasonWrap_' + D + '{idx}`);',
    '            if (wrap) wrap.style.display = radio.value === "undelivered" && radio.checked ? "block" : "none";',
    '            recalcSeCustody();',
    '          });',
    '        });'
  ),
  '③ب ربط القرارات'
);

/* ═══ ④ الإرسال ═══ */
one(
  L(
    '          // اللي مش متحصّل بيتسجّل تلقائيًا عهدة على الطيار (فرق التحصيل)',
    '',
    '          box.remove();',
    '          await finalizeShiftEnd(shiftId, pilot, {',
    '            orders: decisions,',
    '            collectedAmount,',
    '            cashStoreId: collectedAmount > 0 ? storeId : null',
    '          });'
  ),
  L(
    '          // اللي مش متحصّل بيتسجّل تلقائيًا عهدة على الطيار (فرق التحصيل)',
    '',
    '          /* 🔒 ردّ العهدة — خزنته الخاصة لأن التحصيل ممكن يكون «لم يتم» */',
    '          const custodyAmount = Number(document.getElementById("_seCustodyReturn").value) || 0;',
    '          const custodyStore  = document.getElementById("_seCustodyStore").value || storeId || "";',
    '          if (custodyAmount > 0 && !custodyStore) {',
    '            showToast("اختر الخزنة اللي هترجع لها العهدة", "error");',
    '            _btn.disabled = false; _btn.style.opacity = ""; _btn.style.cursor = ""; _btn.textContent = _origText;',
    '            return;',
    '          }',
    '          const allowCustodyCarry = !!document.getElementById("_seCustodyCarry")?.checked;',
    '',
    '          /* 💰 العمولة */',
    '          const commMode = document.getElementById("_seCommMode").value;',
    '          let commission = null;',
    '          if (commMode === "percent" || commMode === "fixed") {',
    '            const v = Number(document.getElementById("_seCommValue").value);',
    '            if (!(v >= 0)) {',
    '              showToast("اكتب قيمة العمولة", "error");',
    '              _btn.disabled = false; _btn.style.opacity = ""; _btn.style.cursor = ""; _btn.textContent = _origText;',
    '              return;',
    '            }',
    '            commission = { mode: commMode, value: v };',
    '          } else if (commMode === "custom") {',
    '            const deliveredIds = new Set(pendingSettlementOrders.map(o => String(o.id)));',
    '            activeOrders.forEach((o, idx) => {',
    '              const choice = box.querySelector(' + B + 'input[name="_se_' + D + '{idx}"]:checked' + B + ')?.value || "delivered";',
    '              if (choice === "delivered") deliveredIds.add(String(o.id));',
    '            });',
    '            const perOrder = [...box.querySelectorAll("[data-commorder]")]',
    '              .filter(inp => deliveredIds.has(inp.getAttribute("data-commorder")) && inp.value !== "")',
    '              .map(inp => ({ orderId: Number(inp.getAttribute("data-commorder")), amount: Number(inp.value) || 0 }));',
    '            commission = { mode: "custom", perOrder };',
    '          }',
    '',
    '          box.remove();',
    '          await finalizeShiftEnd(shiftId, pilot, {',
    '            orders: decisions,',
    '            collectedAmount,',
    '            cashStoreId: collectedAmount > 0 ? storeId : null,',
    '            custodyReturn: custodyAmount > 0 ? { amount: custodyAmount, cashStoreId: custodyStore } : null,',
    '            allowCustodyCarry,',
    '            commission,',
    '          });'
  ),
  '④ الإرسال'
);

/* ═══ ⑤ رسالة النجاح ═══ */
one(
  '        await api.post(`/api/shifts/' + D + '{shiftId}/end`, settlementBody || {});',
  L(
    '        const endRes = await api.post(' + B + '/api/shifts/' + D + '{shiftId}/end' + B + ', settlementBody || {});',
    '        if (Number(endRes.custodyReturned) > 0)',
    '          showToast(' + B + '🔒 أخلى طرف: رجّع ' + D + '{Number(endRes.custodyReturned).toFixed(2)} ج للخزنة' + B + ', "success");',
    '        if (Number(endRes.custodyCarried) > 0)',
    '          showToast(' + B + '⚠️ اتقفلت بترحيل ' + D + '{Number(endRes.custodyCarried).toFixed(2)} ج عهدة على الطيار' + B + ', "error");'
  ),
  '⑤ رسالة الإثبات'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ مودال الإدارة اتحدث');
