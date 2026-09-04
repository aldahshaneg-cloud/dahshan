/* بوابة المحل — «مش معايا بيانات المستلم» لكل طرد.
 *
 * ═══ الطلب (صاحب النظام 2026-08-31) ═══
 * «في تطبيق المحلات كمان عايز زرار مش معايا بيانات المستلم، أحط المنطقة
 * وصور الريسيت مرة واحدة».
 *
 * ═══ نفس منظومة تطبيق العميل بالحرف ═══
 * المفتاح جوه كل طرد، بيخفي حقول المستلم للطرد ده بس، والمنطقة بتفضل
 * إجبارية (منها بيتحسب السعر)، وصورة الريسيت بتبقى إجبارية للطرد ده.
 *
 * ═══ ليه لفّتين مش واحدة ═══
 * حقول هوية المستلم في القالب ده **مش متجاورة**: الاسم والتليفونات فوق،
 * وبينهم وبين العنوان في اختيار المنطقة — والمنطقة لازم تفضل ظاهرة.
 * فبدل ما أعيد ترتيب القالب (تغيير أكبر ومخاطرة أعلى قبل الإطلاق بيوم)،
 * بلفّ الجزئين في `rIdent-${n}` و`rAddrBlock-${n}` وبخفيهم مع بعض.
 *
 * ═══ الصور ═══
 * `_rowImages[n]` مصفوفة فيها **نصوص** (روابط اترفعت) و**كائنات**
 * `{uploading:true}` (لسه بترفع). فـ«عنده صورة» = فيه نص واحد على الأقل،
 * نفس الفلتر اللي الحمولة بتستعمله.
 *
 * 🔒 الحارس: ops/test_store_receipt.php + ops/test_parcel_tabs.cjs
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
const D = String.fromCharCode(36);   // $

/* ═══ ① المفتاح + بداية لفّة الهوية ═══ */
one(
  L('        <div class="fg" style="position:relative">',
    '          <label style="display:flex;align-items:center;justify-content:space-between;gap:8px">',
    '            <span>اسم المستلِم <span class="req">*</span></span>'),
  L('        <!-- 🧾 «مش معايا بيانات المستلم» — للطرد ده لوحده. الشرح تحته',
    '             بيتغيّر مع الحالة عشان المحل يعرف المطلوب منه دلوقتي. -->',
    '        <label class="rcv-receipt" id="rReceiptBox-' + D + '{n}">',
    '          <input type="checkbox" id="rReceipt-' + D + '{n}" onchange="onRowReceipt(' + D + '{n})" />',
    '          <span>',
    '            <b>مش معايا بيانات المستلم</b>',
    '            <small id="rReceiptHint-' + D + '{n}">هرفع صورة الريسيت بدل ما أكتب الاسم والتليفون والعنوان</small>',
    '          </span>',
    '          <span class="ic">🧾</span>',
    '        </label>',
    '',
    '        <div id="rIdent-' + D + '{n}">',
    '        <div class="fg" style="position:relative">',
    '          <label style="display:flex;align-items:center;justify-content:space-between;gap:8px">',
    '            <span>اسم المستلِم <span class="req">*</span></span>'),
  '① المفتاح + بداية اللفّة');

/* ═══ ② نهاية لفّة الهوية — قبل المنطقة ═══ */
one(
  L('        <div id="rTrust-' + D + '{n}" class="trust-box" style="display:none"></div>',
    '        <div class="fg">',
    '          <label>المنطقة <span class="req">*</span></label>'),
  L('        <div id="rTrust-' + D + '{n}" class="trust-box" style="display:none"></div>',
    '        </div><!-- /rIdent — المنطقة تحت بتفضل ظاهرة في الحالتين -->',
    '        <div class="fg">',
    '          <label>المنطقة <span class="req">*</span></label>'),
  '② نهاية لفّة الهوية');

/* ═══ ③ لفّة العنوان والدبوس ═══ */
one(
  L('        <div class="fg">',
    '          <label>عنوان التوصيل</label>',
    '          <div id="rAddrWidget-' + D + '{n}"></div>',
    '          <div id="rGeoBox-' + D + '{n}" style="margin-top:8px"></div>',
    '        </div>'),
  L('        <div class="fg" id="rAddrBlock-' + D + '{n}">',
    '          <label>عنوان التوصيل</label>',
    '          <div id="rAddrWidget-' + D + '{n}"></div>',
    '          <div id="rGeoBox-' + D + '{n}" style="margin-top:8px"></div>',
    '        </div>'),
  '③ لفّة العنوان');

/* ═══ ④ نجمة «مطلوب» على الصور بتظهر مع الريسيت ═══ */
one(
  '          <label>📷 صور الإيصال / العنوان (اختياري)</label>',
  '          <label id="rImgLabel-' + D + '{n}">📷 صور الإيصال / العنوان (اختياري)</label>',
  '④ تسمية الصور');

/* ═══ ⑤ الستايل ═══ */
one(
  '    /* صف التنقّل بين الطرود — بيسكرول أفقيًا لوحده لما الطرود تكتر،',
  L('    /* مفتاح «مش معايا بيانات المستلم» — جوه كل طرد */',
    '    .rcv-receipt {',
    '      display: flex; align-items: center; gap: 11px; cursor: pointer;',
    '      background: var(--panel); border: 1px solid var(--border);',
    '      border-radius: 10px; padding: 11px; margin-bottom: 12px;',
    '    }',
    '    .rcv-receipt.on { border-color: rgba(245,158,11,.5); background: rgba(245,158,11,.09); }',
    '    .rcv-receipt input { width: 19px; height: 19px; flex: none; accent-color: var(--sky); }',
    '    .rcv-receipt span { flex: 1; }',
    '    .rcv-receipt b { font-size: .82rem; display: block; }',
    '    .rcv-receipt small { font-size: .7rem; color: var(--muted); line-height: 1.6; display: block; }',
    '    .rcv-receipt .ic { flex: none; font-size: 1.15rem; }',
    '',
    '    /* صف التنقّل بين الطرود — بيسكرول أفقيًا لوحده لما الطرود تكتر،'),
  '⑤ الستايل');

/* ═══ ⑥ دالة التبديل + قارئ الحالة ═══ */
one(
  L('    /* الصفوف بترتيب ظهورها — الترتيب ده هو مصدر الترقيم المعروض */',
    '    function parcelRows() {'),
  L('    /* 🧾 الطرد ده بياناته على صورة الريسيت؟ — بيتقرا من الشيك بوكس',
    '       مباشرةً عشان يفضل مصدر واحد للحقيقة، من غير حالة موازية تسهى. */',
    '    window.rowIsReceipt = function (n) {',
    '      return !!document.getElementById("rReceipt-" + n)?.checked;',
    '    };',
    '',
    '    /* بيخفي/يظهر حقول هوية المستلم للطرد ده بس.',
    '       لفّتين مش واحدة لأن اختيار المنطقة واقع بينهم في القالب،',
    '       والمنطقة لازم تفضل ظاهرة — منها بيتحسب سعر التوصيل. */',
    '    window.onRowReceipt = function (n) {',
    '      const on = window.rowIsReceipt(n);',
    '      const set = (id, show) => {',
    '        const el = document.getElementById(id);',
    '        if (el) el.style.display = show ? "" : "none";',
    '      };',
    '      set("rIdent-" + n, !on);',
    '      set("rAddrBlock-" + n, !on);',
    '      document.getElementById("rReceiptBox-" + n)?.classList.toggle("on", on);',
    '',
    '      const hint = document.getElementById("rReceiptHint-" + n);',
    '      if (hint) hint.textContent = on',
    '        ? "🧾 مطلوب صورة ريسيت للطرد ده — العنوان والبيانات هيتقروا منها"',
    '        : "هرفع صورة الريسيت بدل ما أكتب الاسم والتليفون والعنوان";',
    '',
    '      const lbl = document.getElementById("rImgLabel-" + n);',
    '      if (lbl) lbl.innerHTML = on',
    '        ? "📷 صور الريسيت <span class=\\"req\\">*</span>"',
    '        : "📷 صور الإيصال / العنوان (اختياري)";',
    '',
    '      window.renderParcelTabs?.();',
    '    };',
    '',
    '    /* الصفوف بترتيب ظهورها — الترتيب ده هو مصدر الترقيم المعروض */',
    '    function parcelRows() {'),
  '⑥ دالة التبديل');

/* ═══ ⑦ علامة الناقص بتعفي الطرد اللي بريسيت ═══ */
one(
  L('    function parcelIsIncomplete(n) {',
    '      const v = id => (document.getElementById(id)?.value || "").trim();',
    '      return !v("rName-" + n) || !v("rPhone-" + n) || !v("rZone-" + n);',
    '    }'),
  L('    function parcelIsIncomplete(n) {',
    '      const v = id => (document.getElementById(id)?.value || "").trim();',
    '      // المنطقة إجبارية في الحالتين — منها بيتحسب سعر التوصيل',
    '      if (!v("rZone-" + n)) return true;',
    '      /* الطرد اللي بصورة ريسيت: مالوش حقول مستلم يتحقق منها، بس',
    '         الصورة بقت إجبارية له — دي المصدر الوحيد لعنوانه. */',
    '      if (window.rowIsReceipt(n)) {',
    '        return !(window._rowImages?.[n] || []).some(x => typeof x === "string");',
    '      }',
    '      return !v("rName-" + n) || !v("rPhone-" + n);',
    '    }'),
  '⑦ علامة الناقص');

/* ═══ ⑧ التحقق عند الإرسال ═══ */
one(
  L('        if (!name)   { _jumpToParcel(n, `rName-' + D + '{n}`, `يرجى إدخال اسم المستلِم — طرد #' + D + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-name", parcel: n }); return; }',
    '        if (!phone)  { _jumpToParcel(n, `rPhone-' + D + '{n}`, `يرجى إدخال هاتف المستلِم — طرد #' + D + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-phone", parcel: n }); return; }'),
  L('        /* 🧾 الطرد اللي بصورة ريسيت: مابنطلبش منه اسم ولا تليفون —',
    '           الحقول مخفية أصلًا فمفيش مخرج لو طلبناهم. بس بنطلب صورة',
    '           واحدة على الأقل، لأنها المصدر الوحيد لعنوانه. */',
    '        const isRcpt = window.rowIsReceipt(n);',
    '        if (isRcpt) {',
    '          const hasImg = (window._rowImages[n] || []).some(x => typeof x === "string");',
    '          if (!hasImg) {',
    '            window.showRow?.(n);',
    '            toast(`لازم ترفع صورة الريسيت — طرد #' + D + '{window.parcelDisplayNo(n)}`, "err");',
    '            document.getElementById(`rImgs-' + D + '{n}`)?.scrollIntoView({ behavior: "smooth", block: "center" });',
    '            await _storeDiag({ step: "no-receipt-image", parcel: n });',
    '            return;',
    '          }',
    '        } else {',
    '          if (!name)   { _jumpToParcel(n, `rName-' + D + '{n}`, `يرجى إدخال اسم المستلِم — طرد #' + D + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-name", parcel: n }); return; }',
    '          if (!phone)  { _jumpToParcel(n, `rPhone-' + D + '{n}`, `يرجى إدخال هاتف المستلِم — طرد #' + D + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-phone", parcel: n }); return; }',
    '        }'),
  '⑧ التحقق');

/* ═══ ⑨ الحمولة ═══ */
one(
  L('          receiverId: null, receiverName: name,',
    '          receiverPhone: phone, receiverPhone2: phone2,',
    '          zoneId, zoneName, zonePrice: price,',
    '          orderPrice, address: addr, note, status: "قيد التنفيذ",'),
  L('          receiverId: null,',
    '          /* نص واضح بدل الفاضي عشان جداول الفرع والإدارة وتطبيق الطيار',
    '             ما تبانش فيها خانة فاضية — نفس نص تطبيق العميل بالحرف. */',
    '          receiverName: isRcpt ? "🧾 البيانات على صورة الريسيت" : name,',
    '          receiverPhone: isRcpt ? "" : phone,',
    '          receiverPhone2: isRcpt ? "" : phone2,',
    '          /* 🔴 العلامة دي هي اللي بتخلّي السيرفر يقبل الاسم الفاضي',
    '             ويكتب `order_deliveries.receiver_from_receipt` — ومنها',
    '             لوحة الفرع بتوري شارة «العنوان على صورة الريسيت» للطيار. */',
    '          fromReceipt: isRcpt,',
    '          zoneId, zoneName, zonePrice: price,',
    '          orderPrice,',
    '          // العنوان بيبقى اسم المنطقة — التفاصيل على الصورة',
    '          address: isRcpt ? zoneName : addr,',
    '          note, status: "قيد التنفيذ",'),
  '⑨ الحمولة');

/* ═══ ⑩ الدبوس مايتبعتش مع الريسيت ═══ */
one(
  L('        const _rgeo = window._geoPins[`r' + D + '{n}`] || null;'),
  L('        // مع الريسيت مفيش دبوس — المحل نفسه مايعرفش العنوان',
    '        const _rgeo = isRcpt ? null : (window._geoPins[`r' + D + '{n}`] || null);'),
  '⑩ الدبوس');

/* ═══ ⑪ التبديل بيحدّث النقطة كمان لما صورة تترفع ═══ */
one(
  L('        renderRowImages(n);',
    '      }',
    '    };'),
  L('        renderRowImages(n);',
    '        // النقطة الحمرا بتروح أول ما صورة الريسيت ترفع بنجاح',
    '        window.renderParcelTabs?.();',
    '      }',
    '    };'),
  '⑪ تحديث النقطة بعد الرفع');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ بوابة المحل: الريسيت لكل طرد');
