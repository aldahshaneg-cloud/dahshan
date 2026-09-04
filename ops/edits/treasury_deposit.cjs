/* عهدة مسبقة للخزن — الإدارة تقدر تحطّ فلوس في خزنة.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «بالنسبة للخزن لازم نعمل إمكانية للإدارة إنها تضع بها عهدة مسبقة».
 *
 * ═══ الفجوة الحقيقية — الخزن مالهاش مدخل فلوس أصلًا ═══
 * كل خزنة بتتخلق برصيد **صفر** (`cashStoresCreate` بيعمل INSERT بـ
 * `balance = 0` صراحةً)، وشاشة الخزن في لوحة الإدارة فيها تلات أفعال بس:
 * تعديل · حذف · **تحويل بين الخزن**.
 *
 * والتحويل دايري: كل الخزن أصفار، فمافيش خزنة فيها حاجة تتحوّل منها.
 * يعني منظومة الخزن كانت **مقفولة على الصفر** — أول جنيه مالوش طريق يدخل.
 *
 * ═══ الآلية موجودة والشاشة هي الناقصة ═══
 * `POST /api/cash-stores/{id}/transactions` بـ`type: "in"` موجود من الأصل
 * وأدواره `admin,branch,accountant`. ومشرف الفرع بينده عليه من
 * `branch.html:4592`. اللي مكانش موجود هو **زرار في شاشة الإدارة**.
 * فمافيش مسار جديد ولا عمود جديد — الرصيد بيتحدّث على السيرفر بقفل صف
 * جوه معاملة زي أي حركة تانية.
 *
 * ═══ ليه «عهدة» مش «إيداع» ═══
 * السبب الافتراضي «عهدة مسبقة» بيتكتب في `reason` بتاع الحركة، فبيفضل في
 * سجل الخزنة وبيتقري في التقارير. حركة بلا سبب واضح في سجل فلوس بتبقى
 * سؤال محدش بيعرف يجاوبه بعد شهر.
 *
 * 🔒 الحارس: ops/test_treasury_deposit.cjs
 */
const fs = require('fs');
const F = 'public/tiar.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');
const D = String.fromCharCode(36);

/* ═══ ① زرار العهدة في صف كل خزنة ═══ */
one(
  L('            <button onclick="openStoreModal(\'' + D + '{ escJs(s.id) }\')" title="تعديل"',
    '              style="background:none;border:none;cursor:pointer;font-size:1rem;padding:2px 5px">✏️</button>'),
  L('            <!-- 💰 أول مدخل فلوس للخزن. قبل كده الشاشة كان فيها تعديل',
    '                 وحذف وتحويل بس — وكل خزنة بتتخلق بصفر، فالتحويل كان',
    '                 دايري ومافيش أول جنيه يدخل من أي مكان. -->',
    '            <button onclick="openDepositModal(\'' + D + '{ escJs(s.id) }\')" title="عهدة مسبقة"',
    '              style="background:none;border:none;cursor:pointer;font-size:1rem;padding:2px 5px">💰</button>',
    '            <button onclick="openStoreModal(\'' + D + '{ escJs(s.id) }\')" title="تعديل"',
    '              style="background:none;border:none;cursor:pointer;font-size:1rem;padding:2px 5px">✏️</button>'),
  '① زرار العهدة');

/* ═══ ② الدوال ═══ */
one(
  '    window.openTransferModal = function() {',
  L('    /**',
    '     * 💰 عهدة مسبقة — الإدارة بتحطّ فلوس في خزنة.',
    '     *',
    '     * بتتسجّل كحركة `in` عادية على نفس المسار اللي مشرف الفرع بيستعمله',
    '     * (`POST /api/cash-stores/{id}/transactions`). مافيش مسار خاص ولا',
    '     * عمود خاص: الرصيد بيتحدّث على السيرفر بقفل صف جوه معاملة، والحركة',
    '     * بتفضل في سجل الخزنة بسببها.',
    '     */',
    '    window.openDepositModal = function(id) {',
    '      const st = (window._cashStoresData || []).find(x => String(x.id) === String(id));',
    '      if (!st) { showToast("الخزنة مش موجودة — اعمل تحديث", "error"); return; }',
    '      document.getElementById("depStoreId").value = st.id;',
    '      document.getElementById("depStoreName").textContent = st.name || "—";',
    '      document.getElementById("depBal").textContent =',
    '        (Number(st.balance) || 0).toFixed(2) + " ج.م";',
    '      document.getElementById("depAmount").value = "";',
    '      /* السبب متعبّى بالافتراضي: حركة بلا سبب واضح في سجل فلوس بتبقى',
    '         سؤال محدش بيعرف يجاوبه بعد شهر. */',
    '      document.getElementById("depReason").value = "عهدة مسبقة";',
    '      document.getElementById("depNotes").value = "";',
    '      openModal("deposit");',
    '    };',
    '',
    '    window.submitDeposit = async function() {',
    '      const btn    = document.getElementById("submitDepositBtn");',
    '      const id     = document.getElementById("depStoreId").value;',
    '      const amount = parseFloat(document.getElementById("depAmount").value);',
    '      const reason = document.getElementById("depReason").value.trim();',
    '      const notes  = document.getElementById("depNotes").value.trim();',
    '',
    '      if (!id) { showToast("الخزنة مش محدّدة", "error"); return; }',
    '      if (!(amount > 0)) { showToast("اكتب مبلغ أكبر من صفر", "error"); return; }',
    '      if (!reason) { showToast("اكتب سبب العهدة", "error"); return; }',
    '',
    '      /* 🔴 الزرار بيتقفل قبل النداء ومابيترجعش غير في `finally`.',
    '         من غير كده دوسة تانية وقت التحميل بتسجّل الحركة مرتين —',
    '         والفلوس بتتضاعف في الخزنة من غير ما حد ياخد باله. */',
    '      btn.disabled = true;',
    '      try {',
    '        await api.post(`/api/cash-stores/' + D + '{encodeURIComponent(id)}/transactions`, {',
    '          type: "in", amount, reason, notes',
    '        });',
    '        closeModal("deposit");',
    '        showToast("العهدة اتسجّلت ✓", "success");',
    '        await refreshStores();',
    '      } catch (e) {',
    '        showToast(e.message || "تعذّر تسجيل العهدة", "error");',
    '      } finally { btn.disabled = false; }',
    '    };',
    '',
    '    window.openTransferModal = function() {'),
  '② الدوال');

/* ═══ ③ المودال ═══ */
one(
  '<div id="modal-cash-transfer" class="modal-overlay" onclick="if(event.target===this) closeModal(\'cash-transfer\')">',
  L('<!-- 💰 عهدة مسبقة — أول مدخل فلوس للخزن -->',
    '<div id="modal-deposit" class="modal-overlay" onclick="if(event.target===this) closeModal(\'deposit\')">',
    '  <div class="modal-box">',
    '    <div class="modal-header"><span class="modal-title">💰 عهدة مسبقة</span><button class="modal-close" onclick="closeModal(\'deposit\')">✕</button></div>',
    '    <form id="depositForm" onsubmit="event.preventDefault(); submitDeposit();">',
    '      <input type="hidden" id="depStoreId" />',
    '      <div class="form-grid">',
    '        <div class="form-group full">',
    '          <label>الخزنة</label>',
    '          <div style="background:var(--card);border:1px solid var(--border);border-radius:9px;padding:10px 12px;font-weight:700">',
    '            <span id="depStoreName">—</span>',
    '            <span style="float:left;color:var(--muted);font-weight:600;font-size:.82rem">الرصيد الحالي: <b id="depBal">0 ج.م</b></span>',
    '          </div>',
    '        </div>',
    '        <div class="form-group full"><label>المبلغ (ج.م) <span class="req">*</span></label>',
    '          <input id="depAmount" type="number" step="0.01" min="0.01" required /></div>',
    '        <div class="form-group full"><label>السبب <span class="req">*</span></label>',
    '          <input id="depReason" type="text" required /></div>',
    '        <div class="form-group full"><label>ملاحظات (اختياري)</label>',
    '          <input id="depNotes" type="text" placeholder="مثال: تسليم نقدي من المدير" /></div>',
    '      </div>',
    '      <div style="font-size:.76rem;color:var(--muted);line-height:1.8;margin-top:-4px">',
    '        بتتسجّل كحركة <b>وارد</b> على الخزنة وبتزوّد رصيدها فورًا، وبتفضل',
    '        في سجل الخزنة بسببها — فأي حد يفتح السجل بعدين يعرف الفلوس دي جت منين.',
    '      </div>',
    '      <div class="form-actions"><button type="submit" id="submitDepositBtn" class="submit-btn">تسجيل العهدة</button><button type="button" class="cancel-btn" onclick="closeModal(\'deposit\')">إلغاء</button></div>',
    '    </form>',
    '  </div>',
    '</div>',
    '',
    '<div id="modal-cash-transfer" class="modal-overlay" onclick="if(event.target===this) closeModal(\'cash-transfer\')">'),
  '③ المودال');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ العهدة المسبقة اتضافت');
