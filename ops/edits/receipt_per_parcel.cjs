/* «مش معايا بيانات المستلم» بقى لكل طرد لوحده مش للطلب كله.
 *
 * ═══ الطلب (صاحب النظام 2026-08-31) ═══
 * «اجعل الجزء الخاص ببيانات المستلم اللي فيه زرار مش معايا بيانات المستلم
 * داخل كل طرد، بحيث يمكن أن يكون طرد من الطرود به بيانات مستلم وطرد آخر
 * ليس به بيانات مستلم فأقوم بتصوير الريسيت».
 *
 * ═══ الخبر الحلو: العقد أصلاً كده ═══
 * الحمولة بتبعت `fromReceipt` **جوه كل طرد** من الأصل، والسيرفر بيقراها
 * جوه حلقة الطرود (CustomerAppController ~1000) وبيخزّنها في عمود
 * `order_deliveries.receiver_from_receipt`. والسلك بيطلّعها
 * `receiverFromReceipt` لكل طرد، ولوحة الفرع بتعرض الشارة على الطرد
 * المعني بس (branch.html:1279 و 1337).
 * يعني كل المنظومة تحت كانت **لكل طرد** من زمان — الواجهة بس هي اللي
 * كانت بتفرض مفتاح واحد على الطلب كله. فالتعديل ده واجهة صافية:
 * مافيش سيرفر، مافيش حقل سلك جديد، مافيش بوابة بتتفتح.
 *
 * ═══ اللي بيتغيّر ═══
 * `S.draft.receiptMode` (بوليان واحد للطلب) → `r.receipt` (بوليان لكل
 * مستلم). ومعاه الشيك بوكس نفسه بينتقل من رأس الخطوة لجوه كل طرد.
 *
 * 🔒 الحارس: ops/test_receipt_per_parcel.cjs
 */
const fs = require('fs');
const F = 'public/customer.html';
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
const B = String.fromCharCode(96);   // `

/* ═══ ① شيل الشيك بوكس العام + ملاحظته من رأس الخطوة ═══ */
one(
  L('        <!-- وضع "الريسيت": العميل معندوش بيانات المستلم، والعنوان مكتوب',
    '             على صورة الإيصال. المنطقة تفضل إجبارية لأنها بتحدد السعر.',
    '             في الوضع ده بيبقى مستلم واحد بس — مفيش بيانات نكرّرها. -->',
    '        <label class="card" style="display:flex;align-items:center;gap:11px;margin-bottom:12px;',
    '               background:var(--card2);cursor:pointer;padding:12px"',
    '               data-tip="فعّلها لو مش معاك بيانات المستلم والعنوان مكتوب على صورة الريسيت. في الحالة دي بيبقى مستلم واحد بس.">',
    '          <input type="checkbox" id="rcvNoData" style="width:20px;height:20px;flex:none;accent-color:var(--red)" />',
    '          <span style="flex:1">',
    '            <b style="font-size:13.5px;display:block">مش معايا بيانات المستلم</b>',
    '            <span style="font-size:11.5px;color:var(--muted);line-height:1.6">',
    '              هرفع صورة الريسيت والعنوان مكتوب عليها</span>',
    '          </span>',
    '          <span style="font-size:19px">🧾</span>',
    '        </label>',
    '',
    '        <div id="rcvReceiptNote" style="display:none;background:rgba(245,158,11,.1);',
    '             border:1px solid rgba(245,158,11,.35);border-radius:12px;padding:12px;',
    '             font-size:12.5px;line-height:1.8;margin-bottom:0">',
    '          🧾 <b>مطلوب صورة الريسيت</b><br />',
    '          <span style="color:var(--muted)">هنسيب بيانات المستلم فاضية ونعتمد على الصورة —',
    '          لازم ترفع صورة واضحة في خطوة المرفقات، والمنطقة تحت إجبارية عشان نحسب سعر التوصيل.</span>',
    '        </div>'),
  L('        <!-- «مش معايا بيانات المستلم» اتنقل **جوه كل طرد** 2026-08-31',
    '             بطلب صاحب النظام: طرد ممكن يبقى فيه بيانات مستلم وطرد تاني',
    '             بصورة ريسيت في نفس الطلب. المفتاح العام اتشال خالص —',
    '             شوف `rcvReceipt-${i}` جوه `receiverBlockHtml`. -->'),
  '① شيل المفتاح العام');

/* ═══ ② blankReceiver ═══ */
one(
  L('const blankReceiver = () => ({ name:"", phone:"", phone2:"", address:"", zoneId:"",',
    '                               lat:null, lng:null, cod:0, images:[] });'),
  L('/* receipt = «مش معايا بيانات المستلم» **للطرد ده لوحده**. بتتبعت',
    '   للسيرفر باسم `fromReceipt` جوه الطرد، وبتتخزّن في العمود',
    '   `order_deliveries.receiver_from_receipt`. */',
    'const blankReceiver = () => ({ name:"", phone:"", phone2:"", address:"", zoneId:"",',
    '                               lat:null, lng:null, cod:0, images:[], receipt:false });'),
  '② blankReceiver');

/* ═══ ③ readReceiverBlocks بتقرا المفتاح ═══ */
one(
  L('    r.cod     = parseFloat(' + D + '("rcvCod-" + i)?.value) || 0;',
    '  });',
    '}'),
  L('    r.cod     = parseFloat(' + D + '("rcvCod-" + i)?.value) || 0;',
    '    r.receipt = !!' + D + '("rcvReceipt-" + i)?.checked;',
    '  });',
    '}'),
  '③ readReceiverBlocks');

/* ═══ ④ الشيك بوكس جوه البلوك ═══ */
one(
  L('    <div id="rcvFields-' + D + '{i}" style="display:' + D + '{receipt ? "none" : "block"}">'),
  L('    <!-- المفتاح جوه الطرد: كل طرد بيقرّر لنفسه. الشرح تحته بيتغيّر',
    '         مع الحالة عشان العميل يعرف إيه المطلوب منه دلوقتي. -->',
    '    <label class="rcv-receipt' + D + '{receipt ? " on" : ""}" data-tip="فعّلها للطرد ده لو مش معاك بيانات المستلم والعنوان مكتوب على صورة الريسيت.">',
    '      <input type="checkbox" id="rcvReceipt-' + D + '{i}" ' + D + '{receipt ? "checked" : ""}',
    '             onchange="onReceiverMode(' + D + '{i})" />',
    '      <span>',
    '        <b>مش معايا بيانات المستلم</b>',
    '        <small>' + D + '{receipt',
    '          ? "🧾 مطلوب صورة ريسيت للطرد ده — العنوان والبيانات هيتقروا منها"',
    '          : "هرفع صورة الريسيت بدل ما أكتب الاسم والتليفون والعنوان"}</small>',
    '      </span>',
    '      <span class="ic">🧾</span>',
    '    </label>',
    '',
    '    <div id="rcvFields-' + D + '{i}" style="display:' + D + '{receipt ? "none" : "block"}">'),
  '④ الشيك بوكس جوه البلوك');

/* ═══ ⑤ ستايل المفتاح ═══ */
one(
  '  /* ── صف التنقّل بين الطرود ─────────────────────────────',
  L('  /* مفتاح «مش معايا بيانات المستلم» — جوه كل طرد */',
    '  .rcv-receipt {',
    '    display: flex; align-items: center; gap: 11px; cursor: pointer;',
    '    background: var(--card2); border: 1px solid var(--line);',
    '    border-radius: 12px; padding: 11px; margin-bottom: 12px;',
    '  }',
    '  .rcv-receipt.on { border-color: rgba(245,158,11,.45); background: rgba(245,158,11,.09); }',
    '  .rcv-receipt input { width: 20px; height: 20px; flex: none; accent-color: var(--red); }',
    '  .rcv-receipt span { flex: 1; }',
    '  .rcv-receipt b { font-size: 13px; display: block; }',
    '  .rcv-receipt small { font-size: 11.5px; color: var(--muted); line-height: 1.6; display: block; }',
    '  .rcv-receipt .ic { flex: none; font-size: 19px; }',
    '',
    '  /* ── صف التنقّل بين الطرود ─────────────────────────────'),
  '⑤ الستايل');

/* ═══ ⑥ onReceiverMode بقت لطرد واحد ═══ */
one(
  L('/* وضع "مش معايا بيانات المستلم" — بنخفي حقول المستلم ونعتمد على صورة',
    '   الريسيت، والمنطقة تفضل إجبارية لأن سعر التوصيل بيتحسب منها.',
    '   في الوضع ده بيبقى مستلم واحد بس — مفيش بيانات نوزّعها على أكتر من نقطة. */',
    'function onReceiverMode() {',
    '  const on = ' + D + '("rcvNoData").checked;',
    '  readReceiverBlocks();',
    '  S.draft.receiptMode = on;',
    '  /* 🔴 كان بيقص الطرود لواحد عند تفعيل الوضع — يعني العميل اللي ضاف',
    '     تلات طرود ودوس على «مش معايا بيانات» بيلاقي اتنين اتمسحوا من غير',
    '     تحذير. الطرود بتفضل زي ما هي؛ اللي بيتخفي هو حقول الاسم والتليفون',
    '     والعنوان بس، والمنطقة والصورة بيفضلوا مطلوبين لكل طرد. */',
    '  ' + D + '("rcvReceiptNote").style.display = on ? "block" : "none";',
    '  renderReceiverBlocks();',
    '  updateAttachHint();',
    '}',
    'window.onReceiverMode = onReceiverMode;'),
  L('/* «مش معايا بيانات المستلم» — **للطرد ' + D + '{i} لوحده** من 2026-08-31.',
    '   بيخفي حقول الاسم والتليفون والعنوان للطرد ده بس، ويخلّي صورة الريسيت',
    '   إجبارية له. المنطقة بتفضل إجبارية في الحالتين — منها بيتحسب السعر.',
    '',
    '   ⚠️ `readReceiverBlocks` الأول: إعادة الرسم بتبني الـHTML من الحالة،',
    '   فأي حاجة العميل كتبها في أي طرد ولسه مش في الحالة بتضيع من غيرها. */',
    'function onReceiverMode(i) {',
    '  readReceiverBlocks();',
    '  const r = S.draft?.receivers?.[i];',
    '  if (r) r.receipt = !!' + D + '("rcvReceipt-" + i)?.checked;',
    '  S.activeRcv = i;              // نفضل على نفس الطرد بعد إعادة الرسم',
    '  renderReceiverBlocks();',
    '  updateAttachHint();',
    '}',
    'window.onReceiverMode = onReceiverMode;',
    '',
    '/* فيه طرد واحد على الأقل بصورة ريسيت؟ — للنصوص العامة بس */',
    'function anyReceipt() { return (S.draft?.receivers || []).some(r => r.receipt); }'),
  '⑥ onReceiverMode لطرد واحد');

/* ═══ ⑦ renderReceiverBlocks بتمرّر حالة كل طرد ═══ */
one(
  L('  const list = S.draft.receivers || (S.draft.receivers = [blankReceiver()]);',
    '  const receipt = !!S.draft.receiptMode;',
    '  const multi = list.length > 1;',
    '',
    '  box.innerHTML = list.map((r, i) => receiverBlockHtml(r, i, multi, receipt)).join("");'),
  L('  const list = S.draft.receivers || (S.draft.receivers = [blankReceiver()]);',
    '  const multi = list.length > 1;',
    '',
    '  // كل بلوك بياخد حالته هو — مش حالة الطلب كله',
    '  box.innerHTML = list.map((r, i) => receiverBlockHtml(r, i, multi, !!r.receipt)).join("");'),
  '⑦ renderReceiverBlocks');

/* ═══ ⑧ rcvIsIncomplete ═══ */
one(
  L('function rcvIsIncomplete(i) {',
    '  const v = id => (' + D + '(id)?.value || "").trim();',
    '  if (!v("rcvZone-" + i)) return true;',
    '  if (S.draft?.receiptMode) return false;'),
  L('function rcvIsIncomplete(i) {',
    '  const v = id => (' + D + '(id)?.value || "").trim();',
    '  if (!v("rcvZone-" + i)) return true;',
    '  // الطرد اللي بصورة ريسيت مالوش حقول مستلم يتحقق منها',
    '  if (' + D + '("rcvReceipt-" + i)?.checked) return false;'),
  '⑧ rcvIsIncomplete');

/* ═══ ⑨ نص زر الإضافة في صف الأزرار ═══ */
one(
  L('  const receipt = !!S.draft?.receiptMode;',
    '  const addLbl  = receipt ? "＋ طرد آخر (بصورة ريسيت)" : "＋ طرد آخر";'),
  L('  /* النص بقى واحد: الطرد الجديد بيبدأ عادي والعميل بيقرر جواه لو',
    '     هيبقى بصورة ريسيت — مابقاش قرار على مستوى الطلب. */',
    '  const addLbl = "＋ طرد آخر";'),
  '⑨ نص زر الإضافة');

/* ═══ ⑩ التحقق ═══ */
one(
  L('        if (!r.zoneId)                return stop(i, "اختر منطقة التسليم" + at);',
    '        if (!S.draft.receiptMode) {'),
  L('        if (!r.zoneId)                return stop(i, "اختر منطقة التسليم" + at);',
    '        // الطرد اللي بصورة ريسيت بياناته على الصورة — مش هنطلبها منه',
    '        if (!r.receipt) {'),
  '⑩ التحقق');

/* ═══ ⑪ إجبارية الصورة عند الإرسال ═══ */
one(
  L('  /* في وضع الريسيت، الصورة هي المصدر الوحيد لعنوان الطرد — فإجبارية',
    '     لكل طرد لوحده، مش صورة واحدة للطلب كله زي الأول. */',
    '  if (S.draft.receiptMode) {',
    '    const bad = receivers.findIndex(r => !(r.images || []).some(i => i.url));',
    '    if (bad !== -1) {',
    '      wizNext(2);',
    '      return toast(receivers.length > 1',
    '        ? `لازم ترفع صورة الريسيت للطرد #' + D + '{bad + 1} — العنوان مكتوب عليها`',
    '        : "لازم ترفع صورة الريسيت — العنوان مكتوب عليها", "err");',
    '    }',
    '  }'),
  L('  /* الصورة إجبارية **للطرود اللي عليها علامة الريسيت بس** — دي المصدر',
    '     الوحيد لعنوانها. الطرد اللي كاتب بياناته عادي مايتطلبش منه صورة. */',
    '  {',
    '    const bad = receivers.findIndex(r => r.receipt && !(r.images || []).some(i => i.url));',
    '    if (bad !== -1) {',
    '      wizNext(2);',
    '      showRcv(bad);',
    '      return toast(receivers.length > 1',
    '        ? `لازم ترفع صورة الريسيت للطرد #' + D + '{bad + 1} — العنوان مكتوب عليها`',
    '        : "لازم ترفع صورة الريسيت — العنوان مكتوب عليها", "err");',
    '    }',
    '  }'),
  '⑪ إجبارية الصورة');

/* ═══ ⑫ الحمولة ═══ */
one(
  L('    const receipt = !!S.draft.receiptMode;',
    '    const note = ' + D + '("odNote").value.trim();'),
  L('    const note = ' + D + '("odNote").value.trim();'),
  '⑫أ شيل المتغير العام من الحمولة');

one(
  L('    const deliveries = receivers.map((r, idx) => {',
    '      const rz = findZone(r.zoneId);',
    '      return {'),
  L('    const deliveries = receivers.map((r, idx) => {',
    '      const rz = findZone(r.zoneId);',
    '      // 🔴 لكل طرد حالته — السيرفر بيقرا `fromReceipt` جوه كل طرد',
    '      //    ويخزّنها في `order_deliveries.receiver_from_receipt`',
    '      const receipt = !!r.receipt;',
    '      return {'),
  '⑫ب حالة الطرد جوه الحمولة');

/* ═══ ⑬ تلميح المرفقات ═══ */
one(
  L('  if (S.draft?.receiptMode) {',
    '    el.innerHTML = `<span style="color:var(--orange);font-weight:700">',
    '      🧾 صورة الريسيت إجبارية لكل طرد</span> — العنوان وبيانات المستلم هيتقروا منها.',
    '      الصور بتتضاف جوّه الطرد نفسه في خطوة <b>المستلمين</b>.`;'),
  L('  const nR = (S.draft?.receivers || []).filter(r => r.receipt).length;',
    '  if (nR) {',
    '    el.innerHTML = `<span style="color:var(--orange);font-weight:700">',
    '      🧾 صورة الريسيت إجبارية لـ' + D + '{nR === 1 ? "طرد واحد" : nR + " طرود"}</span> — العنوان',
    '      وبيانات المستلم هيتقروا منها. الصور بتتضاف جوّه الطرد نفسه في خطوة',
    '      <b>المستلمين</b>.`;'),
  '⑬ تلميح المرفقات');

/* ═══ ⑭ ملخّص المرفقات ═══ */
one(
  L('  const need = !!S.draft?.receiptMode;',
    '  box.innerHTML = rs.map((r, i) => {',
    '    const n = (r.images || []).filter(x => x.url).length;',
    '    const bad = need && !n;'),
  L('  box.innerHTML = rs.map((r, i) => {',
    '    const n = (r.images || []).filter(x => x.url).length;',
    '    // الصورة ناقصة = الطرد ده عليه علامة الريسيت ومالوش صورة',
    '    const bad = !!r.receipt && !n;'),
  '⑭ ملخّص المرفقات');

/* ═══ ⑮ ملخّص الطلب ═══ */
one(
  L('  const rcvRows = S.draft.receiptMode',
    '    ? list.map((r, i) => row(',
    '        list.length > 1 ? `الطرد ' + D + '{i + 1}` : "المستلم",',
    '        "🧾 من صورة الريسيت — " + (zoneLabel(findZone(r.zoneId) || {}) || "—"))).join("")',
    '    : list.map((r, i) => row(',
    '        list.length > 1 ? `الطرد ' + D + '{i + 1}` : "المستلم",',
    '        `' + D + '{r.name || "—"} — ' + D + '{zoneLabel(findZone(r.zoneId) || {}) || "—"}`)).join("");'),
  L('  /* كل طرد بيتعرض بحالته هو — ممكن يبقى في نفس الطلب طرد باسم مستلم',
    '     وطرد بصورة ريسيت، فالمراجعة قبل الإرسال لازم توري الفرق. */',
    '  const rcvRows = list.map((r, i) => row(',
    '    list.length > 1 ? `الطرد ' + D + '{i + 1}` : "المستلم",',
    '    (r.receipt ? "🧾 من صورة الريسيت" : (r.name || "—"))',
    '      + " — " + (zoneLabel(findZone(r.zoneId) || {}) || "—"))).join("");'),
  '⑮ ملخّص الطلب');

/* ═══ ⑯ تصفير المسوّدة ═══ */
one(
  L('  ' + D + '("rcvNoData").checked = false;',
    '  ' + D + '("rcvNoData").onchange = onReceiverMode;',
    '  onReceiverMode();                 // بيرسم بلوكات المستلمين جوّه'),
  L('  /* المفتاح بقى جوه كل طرد، فمافيش حاجة تتصفّر هنا — البلوكات بتتبني',
    '     من `S.draft.receivers` وكل مستلم جديد بيبدأ بـ`receipt:false`. */',
    '  S.activeRcv = 0;',
    '  renderReceiverBlocks();           // بيرسم بلوكات المستلمين',
    '  updateAttachHint();'),
  '⑯ تصفير المسوّدة');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ الريسيت بقى لكل طرد');
