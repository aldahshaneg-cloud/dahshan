/* بوابة المحل — بلوك المُرسِل بيتطوي لسطر واحد.
 *
 * ═══ الطلب (صاحب النظام 2026-08-31) ═══
 * «في الجزء الخاص بإرسال الطلب في تطبيق المحلات يجب أن يختفي الجزء اللي
 * في الأعلى ويكفي زرار لإظهاره حتى يتم الكتابة فيه عند الحاجة، لكي يوفر
 * مساحة».
 *
 * ═══ الوضع قبل ═══
 * بلوك المُرسِل كان بياخد ~145 بكسل حتى وهو **مطويّ**:
 *   • أيقونة + «المُرسِل — محلك» + اسم المحل + التليفون   ≈ 75px
 *   • كارت ملخّص الاستلام بسطرين                            ≈ 55px
 * وده كله قبل ما المحل يبدأ يكتب أي حاجة في الطرد.
 *
 * ═══ الوضع بعد ═══
 * سطر واحد ~44 بكسل: `📤 الاستلام من روح دمشق — حي الجامعة   [تغيير]`
 * والضغط في أي مكان عليه بيفتح البلوك كامل.
 *
 * ═══ ليه سبت المعلومة ومشلتش الكارت خالص ═══
 * زرار مكتوب عليه «بيانات المُرسِل» كان هيوفّر ٢٠ بكسل زيادة، بس بيخفي
 * **منطقة الاستلام**. ودي مش تفصيلة تجميلية: منها بيتحدّد الفرع المسؤول
 * عن الشحنة كلها. محل بيبعت ٣٠ شحنة في اليوم لو المنطقة اتغيّرت غلط
 * (أو الملف اتحدّث من الإعدادات) مش هياخد باله غير لما الشحنات تروح لفرع
 * تاني. فالسطر بيفضل شايل الاسم والمنطقة — الأمان ده تمنه ٢٠ بكسل.
 *
 * ═══ الحالات اللي البلوك بيفضل مفتوح فيها (منظومة موجودة، مالمستهاش) ═══
 * `refreshPickupSummary` بتفرض الفتح لو مافيش منطقة مختارة أو لو
 * «الاستلام من مكان آخر» مفعّل — لأن الطي ساعتها بيخبّي سبب رفض الفورم.
 * و`_pickupOpen` معناها «المحل فتحه بإيده» فمابنقفلش عليه.
 *
 * 🔒 الحارس: ops/test_pickup_collapse.cjs
 */
const fs = require('fs');
const F = 'public/store.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');

/* ═══ ① لفّ هوية المحل عشان تتخفي مع الطي ═══ */
one(
  L('        <div class="sender-label">المُرسِل — محلك</div>',
    '        <div class="sender-name"  id="sender-name">—</div>',
    '        <div class="sender-meta">',
    '          <span dir="ltr" id="sender-phone">—</span>',
    '        </div>'),
  L('        <!-- هوية المحل بتتخفي مع الطي: اسم المحل موجود أصلًا في سطر',
    '             الملخّص تحت، فعرضه مرتين كان بياخد مساحة بلا معلومة زيادة.',
    '             بترجع تبان أول ما البلوك يتفتح. -->',
    '        <div id="senderIdent">',
    '          <div class="sender-label">المُرسِل — محلك</div>',
    '          <div class="sender-name"  id="sender-name">—</div>',
    '          <div class="sender-meta">',
    '            <span dir="ltr" id="sender-phone">—</span>',
    '          </div>',
    '        </div>'),
  '① لفّ هوية المحل');

/* ═══ ② الملخّص بقى سطر واحد ودوسة في أي مكان بتفتح ═══ */
one(
  L('        <div id="pickupSummary" style="display:none;margin-top:8px">',
    '          <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:9px;',
    '                      background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.35)">',
    '            <span style="font-size:.95rem">📤</span>',
    '            <div style="flex:1;min-width:0">',
    '              <div id="pickupSummaryLine" style="font-size:.78rem;font-weight:700;color:var(--text);',
    '                   white-space:nowrap;overflow:hidden;text-overflow:ellipsis">—</div>',
    '              <div id="pickupSummarySub" style="font-size:.7rem;opacity:.7;',
    '                   white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>',
    '            </div>',
    '            <button type="button" onclick="togglePickupDetails(true)"',
    '              style="background:transparent;border:1px solid var(--border);border-radius:7px;',
    '                     padding:4px 10px;font-size:.7rem;font-weight:700;cursor:pointer;',
    '                     color:inherit;font-family:inherit;flex:0 0 auto">✏️ تغيير</button>',
    '          </div>',
    '        </div>'),
  L('        <!-- سطر واحد بدل سطرين: العنوان التفصيلي وحالة الدبوس اتنقلوا',
    '             لـ`title` — معلومة بتلزم عند المراجعة مش عند كل طلب.',
    '             والصف كله زرار: الدوسة في أي مكان بتفتح البلوك، مش على',
    '             زرار صغير لازم تصوّبه بالصباع. -->',
    '        <div id="pickupSummary" style="display:none">',
    '          <button type="button" id="pickupSummaryBtn" onclick="togglePickupDetails(true)"',
    '            style="width:100%;display:flex;align-items:center;gap:8px;padding:9px 10px;',
    '                   border-radius:9px;cursor:pointer;text-align:right;font-family:inherit;',
    '                   background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.35)">',
    '            <span style="font-size:.95rem;flex:0 0 auto">📤</span>',
    '            <span id="pickupSummaryLine" style="flex:1;min-width:0;font-size:.78rem;font-weight:700;',
    '                  color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">—</span>',
    '            <span style="flex:0 0 auto;border:1px solid var(--border);border-radius:7px;',
    '                  padding:3px 9px;font-size:.7rem;font-weight:700;opacity:.85">✏️ تغيير</span>',
    '          </button>',
    '        </div>'),
  '② الملخّص سطر واحد');

/* ═══ ③ التبديل بيخفي الهوية كمان ويشيل حشو الكارت ═══ */
one(
  L('    window.togglePickupDetails = function (open, byUser) {',
    '      const det = document.getElementById("pickupDetails");',
    '      const sum = document.getElementById("pickupSummary");',
    '      if (!det || !sum) return;',
    '      if (byUser !== false) window._pickupOpen = !!open;',
    '      det.style.display = open ? "" : "none";',
    '      sum.style.display = open ? "none" : "";',
    '    };'),
  L('    window.togglePickupDetails = function (open, byUser) {',
    '      const det = document.getElementById("pickupDetails");',
    '      const sum = document.getElementById("pickupSummary");',
    '      if (!det || !sum) return;',
    '      if (byUser !== false) window._pickupOpen = !!open;',
    '      det.style.display = open ? "" : "none";',
    '      sum.style.display = open ? "none" : "";',
    '',
    '      /* هوية المحل والأيقونة وحشو الكارت بيتشالوا مع الطي — الهدف من',
    '         الطي توفير مساحة، ولو الكارت فضل بحجمه ماتوفرش حاجة. */',
    '      const ident = document.getElementById("senderIdent");',
    '      if (ident) ident.style.display = open ? "" : "none";',
    '      const icon = document.querySelector(".sender-icon");',
    '      if (icon) icon.style.display = open ? "" : "none";',
    '      document.querySelector(".sender-bar")?.classList.toggle("collapsed", !open);',
    '    };'),
  '③ التبديل بيخفي الهوية');

/* ═══ ④ سطر الملخّص بقى شايل المنطقة والتفاصيل في title ═══ */
one(
  L('      document.getElementById("pickupSummaryLine").textContent = `الاستلام من ${shop} — ${zoneName}`;',
    '      const pin = window._geoPins?.pickup;',
    '      document.getElementById("pickupSummarySub").textContent =',
    '        (addr || "من غير عنوان تفصيلي") + (pin ? " · 📍 الموقع محدّد" : "");',
    '      window.togglePickupDetails(false, false);'),
  L('      document.getElementById("pickupSummaryLine").textContent = `الاستلام من ${shop} — ${zoneName}`;',
    '      /* التفاصيل بقت في `title` بدل سطر تاني — بتلزم عند المراجعة مش',
    '         عند كل طلب، والسطر التاني كان بياخد ٢٠ بكسل من كل شاشة. */',
    '      const pin = window._geoPins?.pickup;',
    '      const btn = document.getElementById("pickupSummaryBtn");',
    '      if (btn) btn.title = (addr || "من غير عنوان تفصيلي")',
    '        + (pin ? " · 📍 الموقع محدّد" : " · مفيش دبوس") + " — اضغط للتعديل";',
    '      window.togglePickupDetails(false, false);'),
  '④ التفاصيل في title');

/* ═══ ⑤ الستايل ═══ */
one(
  L('    .sender-bar {',
    '      background: linear-gradient(135deg, var(--tint) 0%, var(--tint-s) 100%);',
    '      border: 1px solid var(--tint-bd);',
    '      border-radius: var(--R); padding: 12px 14px; margin-bottom: 16px;',
    '      display: flex; align-items: flex-start; gap: 10px;',
    '    }'),
  L('    .sender-bar {',
    '      background: linear-gradient(135deg, var(--tint) 0%, var(--tint-s) 100%);',
    '      border: 1px solid var(--tint-bd);',
    '      border-radius: var(--R); padding: 12px 14px; margin-bottom: 16px;',
    '      display: flex; align-items: flex-start; gap: 10px;',
    '    }',
    '    /* مطويّ: الكارت بيبقى إطار السطر نفسه — من غير خلفية ولا حشو،',
    '       عشان مايبانش كارت جوه كارت. */',
    '    .sender-bar.collapsed {',
    '      background: none; border: none; padding: 0; margin-bottom: 12px; gap: 0;',
    '    }'),
  '⑤ الستايل');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ بلوك المُرسِل بيتطوي لسطر واحد');
