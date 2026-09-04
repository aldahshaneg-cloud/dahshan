/* 💬 صفحة «رسايل العملاء» في برنامج الكول سنتر.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «اعمل الصفحة الخاصة برسايل العملاء في برنامج الكول سنتر بحيث يبعت هو
 * الرسايل — حتى نعملها أتوميشن في المستقبل».
 *
 * ═══ مش نظام جديد ═══
 * طابور `order_notifications` موجود وشغّال من 2026-08-20: كل أوردر
 * بيتسجّل له صف pending بنص جاهز، والمسار أصلًا بيسمح للكول سنتر
 * (role:admin,branch,callcenter). الصفحة دي **نقل حرفي** لشاشة
 * «رسايل العملاء» في لوحة الإدارة (tiar.html قسم 8299) بمعرّفات cc
 * عشان ماتخبطش في مودال ما-بعد-الإنشاء الموجود (openWaNotifyForOrder).
 *
 * ═══ القواعد المنقولة زي ما هي ═══
 * • زرارين «فتحت» ≠ «بعت»: «افتح واتساب» بيفتح wa.me وبس، و«اتبعت»
 *   مقفول لحد ما الأول يتضغط — تأكيد بني آدم، والنظام مابيكدبش.
 * • كل قيمة بتعدّي على esc()، ومفيش onclick مبني بالنص (data-* + ربط
 *   بعد الرسم)، والرقم بيتصفّى قبل wa.me.
 * • لما WHATSAPP_PROVIDER=cloud_api يتفعّل، الصفوف هتيجي sent لوحدها —
 *   الصفحة تفضل للمتابعة، صفر تغيير. ده «الأتوميشن في المستقبل» اللي
 *   صاحب النظام قصده: نفس الطابور، بيتبعت بعامل بدل الموظف.
 *
 * 🔒 الحارس: ops/test_ccnotifs.cjs
 */
const fs = require('fs');
const F = 'public/callcenter.html';
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

if (s.includes('ccNotifBody')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① الصفحة في قايمة الصفحات ═══ */
one(
  '                 "ccsearch","ccomplaints","cczones","ccperf",',
  '                 "ccsearch","ccomplaints","cczones","ccperf","ccnotifs",',
  '① قايمة الصفحات'
);

/* ═══ ② زرار التنقل — جنب الشكاوى ═══ */
one(
  `      <button class="nav-item" onclick="navigateTo('ccomplaints')" id="navComplaints" style="position:relative">⚠️ الشكاوى</button>`,
  L(
    `      <button class="nav-item" onclick="navigateTo('ccnotifs')" id="navCcNotifs" style="position:relative">💬 رسايل العملاء`,
    '        <span id="ccNotifBadge" style="display:none;position:absolute;top:4px;left:8px;background:var(--orange);color:#fff;font-size:.62rem;font-weight:800;border-radius:12px;padding:1px 6px"></span>',
    '      </button>',
    `      <button class="nav-item" onclick="navigateTo('ccomplaints')" id="navComplaints" style="position:relative">⚠️ الشكاوى</button>`
  ),
  '② زرار التنقل'
);

/* ═══ ③ الماركب — قبل صفحة الشكاوى ═══ */
one(
  '    <div class="page" id="page-ccomplaints">',
  L(
    '    <!-- ═══ 💬 رسايل العملاء — نقل حرفي لشاشة الإدارة (طابور order_notifications) ═══ -->',
    '    <div class="page" id="page-ccnotifs">',
    '      <div class="stats">',
    '        <div class="stat-card"><span class="stat-label">رسايل معلّقة</span><span class="stat-value" id="ccNotifPendingStat" style="color:var(--orange)">0</span><span class="stat-sub">مستنية تتبعت</span></div>',
    '        <div class="stat-card"><span class="stat-label">معروض دلوقتي</span><span class="stat-value" id="ccNotifCount">0</span><span class="stat-sub">في التبويب المفتوح</span></div>',
    '      </div>',
    '',
    '      <div style="background:rgba(34,197,94,.07);border:1px solid rgba(34,197,94,.2);border-radius:10px;padding:14px 18px;margin-bottom:18px;font-size:.86rem;color:var(--muted);line-height:1.7">',
    '        📦 النظام بيجهّز رسالة واتساب <b>لكل مستلم</b> أول ما الأوردر يتعمل، فيها رقم الطلب ولينك التتبّع.',
    '        اضغط <b>«افتح واتساب»</b> عشان الرسالة تتفتح جاهزة، وبعد ما تبعتها فعلًا اضغط <b>«اتبعت»</b>.',
    '        <br />💡 الزرار التاني بيتفتح بعد الأول — النظام مش بيعرف لوحده إنك بعت، فالتأكيد منك.',
    '      </div>',
    '',
    '      <div class="sub-tabs" id="ccNotifTabs">',
    '        <button class="sub-tab active" data-ccnf="pending" onclick="ccSetNotifFilter(\'pending\')">⏳ معلّقة</button>',
    '        <button class="sub-tab"        data-ccnf="sent"    onclick="ccSetNotifFilter(\'sent\')">✅ مبعوتة</button>',
    '        <button class="sub-tab"        data-ccnf="failed"  onclick="ccSetNotifFilter(\'failed\')">⚠️ فشلت</button>',
    '        <button class="sub-tab"        data-ccnf="skipped" onclick="ccSetNotifFilter(\'skipped\')">⛔ متخطّاة</button>',
    '        <button class="sub-tab"        data-ccnf="all"     onclick="ccSetNotifFilter(\'all\')">📋 الكل</button>',
    '      </div>',
    '',
    '      <div class="table-wrap">',
    '        <table><thead><tr>',
    '          <th>رقم الطلب</th><th>المستلم</th><th>رقمه</th><th>نص الرسالة</th><th>الحالة</th><th>إجراء</th>',
    '        </tr></thead><tbody id="ccNotifBody">',
    '          <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:40px">جاري التحميل…</td></tr>',
    '        </tbody></table>',
    '      </div>',
    '    </div>',
    '',
    '    <div class="page" id="page-ccomplaints">'
  ),
  '③ الماركب'
);

/* ═══ ④ الـCSS — اتطبق يدوي بعد ما مرساته وقعت (التوست مالهوش تعليق
   مطابق هنا) — بنتأكد بس إنه موجود. */
if (!s.includes('.notif-btn {')) { console.log('  🔴 ④ الـCSS مش موجود — طبّقه الأول'); process.exit(1); }
console.log('  ✓ ④ الـCSS (متطبق)');

/* ═══ ⑤ الجافاسكربت — جنب مودال ما-بعد-الإنشاء الموجود ═══ */
one(
  '    window.openWaNotifyForOrder = async function (orderIds) {',
  L(
    '    /* ══════════ 💬 صفحة رسايل العملاء ══════════',
    '       نقل حرفي لشاشة الإدارة — نفس القواعد: «فتحت» ≠ «بعت»،',
    '       والحالة النهائية بتتاخد من رد السيرفر مش بتتخمّن. */',
    '    window._ccNotifs = [];',
    '    window._ccNotifPending = 0;',
    '    let _ccNotifFilter = "pending";',
    '    /* «فتح واتساب في الجلسة دي» — مش localStorage عن قصد: إعادة',
    '       التحميل ترجّع الزرار مقفول، وده أأمن من تعليم رسالة ماتبعتتش. */',
    '    const _ccNotifOpened = new Set();',
    '',
    '    const CC_NOTIF_BADGE = {',
    '      pending: ' + B + '<span class="status-badge status-pending">⏳ معلّقة</span>' + B + ',',
    '      sent:    ' + B + '<span class="status-badge status-done">✅ مبعوتة</span>' + B + ',',
    '      failed:  ' + B + '<span class="status-badge status-cancelled">⚠️ فشلت</span>' + B + ',',
    '      skipped: ' + B + '<span class="status-badge status-cancelled">⛔ متخطّاة</span>' + B + ',',
    '    };',
    '    const _ccNotifWhen = n => { const t = Date.parse(n.createdAt || ""); return isNaN(t) ? 0 : t; };',
    '    const _ccFmtNotifTime = v => {',
    '      const t = Date.parse(v || "");',
    '      if (isNaN(t)) return "—";',
    '      return new Date(t).toLocaleString("ar-EG", { day:"numeric", month:"short", hour:"numeric", minute:"2-digit" });',
    '    };',
    '    /* صيغة واتساب الدولية — الـ"2" قدّام الرقم كله والصفر بيفضل مكانه',
    '       (01222222222 → 201222222222). نفس _notifWaPhone في الإدارة. */',
    '    function _ccNotifWaPhone(phone) {',
    '      let p = String(phone || "").replace(/\\D/g, "");',
    '      if (p.startsWith("0")) p = "2" + p;',
    '      return p.length >= 10 ? p : "";',
    '    }',
    '    const _ccNotifById = id => (window._ccNotifs || []).find(n => String(n.id) === String(id));',
    '',
    '    window.ccSetNotifFilter = function (key) { _ccNotifFilter = key; ccRenderNotifs(); };',
    '',
    '    window.ccOpenNotifWhatsApp = function (id) {',
    '      const n = _ccNotifById(id);',
    '      if (!n) return;',
    '      const p = _ccNotifWaPhone(n.phone);',
    '      if (!p) { showToast("مفيش رقم صالح للمستلم ده", "error"); return; }',
    '      const w = window.open(' + B + 'https://wa.me/' + D + '{p}?text=' + D + '{encodeURIComponent(n.body || "")}' + B + ', "_blank");',
    '      if (!w) { showToast("المتصفح حجب فتح واتساب — اسمح بالنوافذ المنبثقة", "error"); return; }',
    '      _ccNotifOpened.add(String(id));',
    '      ccRenderNotifs();',
    '    };',
    '',
    '    window.ccMarkNotifSent = async function (id, btn) {',
    '      const n = _ccNotifById(id);',
    '      if (!n) return;',
    '      if (btn) { btn.disabled = true; btn.textContent = "…"; }',
    '      const wasPending = n.status === "pending";',
    '      try {',
    '        const d = await api.post(' + B + '/api/order-notifications/' + D + '{encodeURIComponent(id)}/sent' + B + ', {});',
    '        Object.assign(n, d.message || { status: "sent" });',
    '        if (wasPending) window._ccNotifPending = Math.max(0, (window._ccNotifPending || 0) - 1);',
    '        _ccNotifOpened.delete(String(id));',
    '        showToast("اتعلّمت مبعوتة ✓", "success");',
    '        ccRenderNotifs();',
    '        ccRenderNotifBadge();',
    '      } catch (e) {',
    '        showToast("تعذّر التعليم: " + (e.message || ""), "error");',
    '        if (btn) { btn.disabled = false; btn.textContent = "✓ اتبعت"; }',
    '      }',
    '    };',
    '',
    '    function ccRenderNotifBadge() {',
    '      const b = document.getElementById("ccNotifBadge");',
    '      if (!b) return;',
    '      const c = window._ccNotifPending || 0;',
    '      b.textContent = c > 99 ? "99+" : c;',
    '      b.style.display = c > 0 ? "inline-block" : "none";',
    '    }',
    '',
    '    function ccNotifRowHTML(n) {',
    '      const opened = _ccNotifOpened.has(String(n.id));',
    '      const canSend = n.status !== "skipped";',
    '      const parcels = Array.isArray(n.parcels) && n.parcels.length',
    '        ? ' + B + '<span style="color:var(--muted);font-size:.74rem"> (طرد ' + D + '{ esc(n.parcels.join("، ")) })</span>' + B + ' : "";',
    '      const acts = n.status === "sent"',
    '        ? ' + B + '<span style="color:var(--muted);font-size:.76rem">✓ ' + D + '{ esc(n.sentBy || "تلقائي") }<br>' + D + '{ esc(_ccFmtNotifTime(n.sentAt)) }</span>' + B + '',
    '        : !canSend',
    '          ? ' + B + '<span style="color:var(--muted);font-size:.76rem">—</span>' + B + '',
    '          : ' + B + '<div style="display:flex;gap:6px;flex-wrap:wrap">',
    '               <button class="notif-btn notif-wa" data-ccnwa="' + D + '{ esc(n.id) }">📱 افتح واتساب</button>',
    '               <button class="notif-btn notif-ok" data-ccnsent="' + D + '{ esc(n.id) }"' + D + '{ opened ? "" : " disabled" }',
    '                       title="' + D + '{ opened ? "علّم الرسالة إنها اتبعتت" : "افتح واتساب الأول" }">✓ اتبعت</button>',
    '             </div>' + B + ';',
    '      return ' + B + '<tr>',
    '        <td style="white-space:nowrap"><b>' + D + '{ esc(n.orderNum || "—") }</b>' + D + '{parcels}',
    '          <div style="color:var(--muted);font-size:.72rem">' + D + '{ esc(_ccFmtNotifTime(n.createdAt)) }</div></td>',
    '        <td>' + D + '{ esc(n.name || "—") }</td>',
    '        <td style="white-space:nowrap;direction:ltr;text-align:right">' + D + '{ esc(n.phone || "—") }</td>',
    '        <td><div class="notif-body">' + D + '{ esc(n.body || "") }</div></td>',
    '        <td style="white-space:nowrap">' + D + '{ CC_NOTIF_BADGE[n.status] || ' + B + '<span class="status-badge status-pending">' + D + '{ esc(n.status) }</span>' + B + ' }',
    '          ' + D + '{ n.error ? ' + B + '<div style="color:var(--orange);font-size:.72rem;margin-top:4px;max-width:190px">' + D + '{ esc(n.error) }</div>' + B + ' : "" }</td>',
    '        <td>' + D + '{acts}</td>',
    '      </tr>' + B + ';',
    '    }',
    '',
    '    window.ccRenderNotifs = function () {',
    '      const body = document.getElementById("ccNotifBody");',
    '      if (!body) return;',
    '      document.querySelectorAll("[data-ccnf]").forEach(el =>',
    '        el.classList.toggle("active", el.dataset.ccnf === _ccNotifFilter));',
    '      const all = window._ccNotifs || [];',
    '      const list = (_ccNotifFilter === "all" ? all.slice() : all.filter(n => n.status === _ccNotifFilter))',
    '        .sort((a, b) => _ccNotifWhen(b) - _ccNotifWhen(a) || (Number(b.id) || 0) - (Number(a.id) || 0));',
    '      const cnt = document.getElementById("ccNotifCount");',
    '      if (cnt) cnt.textContent = list.length;',
    '      const pend = document.getElementById("ccNotifPendingStat");',
    '      if (pend) pend.textContent = window._ccNotifPending || 0;',
    '      if (!list.length) {',
    '        body.innerHTML = ' + B + '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:40px">',
    '          ' + D + '{ all.length ? "مفيش رسايل في التبويب ده" : "مفيش رسايل لسه — أول ما أوردر يتعمل هتلاقي رسالة مستلمه هنا." }',
    '        </td></tr>' + B + ';',
    '        return;',
    '      }',
    '      body.innerHTML = list.map(ccNotifRowHTML).join("");',
    '      body.querySelectorAll("[data-ccnwa]").forEach(b =>',
    '        b.addEventListener("click", () => window.ccOpenNotifWhatsApp(b.dataset.ccnwa)));',
    '      body.querySelectorAll("[data-ccnsent]").forEach(b =>',
    '        b.addEventListener("click", () => window.ccMarkNotifSent(b.dataset.ccnsent, b)));',
    '    };',
    '',
    '    /* الكول سنتر هو غرفة الشغل — استطلاع كل ٣٠ث (الإدارة ٦٠).',
    '       الرد فيه `pending` معدود من القاعدة مش من طول القايمة. */',
    '    const _ccNotifPoller = ccPoller("/api/order-notifications", {',
    '      interval: 30000, useSince: false,',
    '      onChange: (d) => {',
    '        window._ccNotifs = (d.items || []);',
    '        window._ccNotifPending = Number.isFinite(d.pending)',
    '          ? d.pending',
    '          : window._ccNotifs.filter(n => n.status === "pending").length;',
    '        ccRenderNotifBadge();',
    '        ccRenderNotifs();',
    '      },',
    '    });',
    '    _ccNotifPoller.start();',
    '',
    '    window.openWaNotifyForOrder = async function (orderIds) {'
  ),
  '⑤ الجافاسكربت'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ صفحة رسايل العملاء اتحطت في الكول سنتر');
