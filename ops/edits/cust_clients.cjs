/* شاشة «عملائي» — المستلمون المحفوظون، بنفس شكل شاشة العناوين. */
const fs = require('fs');
const f = 'public/customer.html';
let s = fs.readFileSync(f, 'utf8');
const eol = s.includes('\r\n') ? '\r\n' : '\n';
if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');

let bad = 0;
const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log(`  🔴 ${label}: اتلقت ${n} مرة`); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

/* ═══ ① الشاشة ═══ */
one(`<!-- ═══ 12. الإشعارات ═════════════════════════════════════ -->`,
`<!-- ═══ عملائي — المستلمون المحفوظون ══════════════════════
     التاجر اللي بيبعت لنفس الناس كل شوية محتاج يشوفهم ويشيل اللي
     مابيتعاملش معاه. الحفظ نفسه بيحصل تلقائيًا مع كل أوردر. -->
<section id="s-clients" class="screen">
  <div class="hd"><div class="ico-btn" onclick="go('home')">›</div><h2>عملائي</h2></div>
  <div class="scroll pad" style="padding-top:0">
    <div style="font-size:12.5px;color:var(--muted);line-height:1.8;margin-bottom:12px">
      كل عميل بتبعتله بيتحفظ هنا تلقائيًا — اضغط «ابعت له» عشان تبدأ طلب
      ببياناته جاهزة من غير ما تكتبها تاني.
    </div>
    <div id="clientList"></div>
  </div>
</section>

<!-- ═══ 12. الإشعارات ═════════════════════════════════════ -->`,
'الشاشة');

/* ═══ ② الصفوف في الرئيسية والحساب ═══ */
one(`    <div class="menu-row" onclick="go('addresses')"><div class="mi">📍</div><b>العناوين المحفوظة</b><span class="ch">‹</span></div>
    <div class="menu-row" onclick="go('account')"><div class="mi">👤</div><b>الحساب</b><span class="ch">‹</span></div>`,
`    <div class="menu-row" onclick="go('addresses')"><div class="mi">📍</div><b>العناوين المحفوظة</b><span class="ch">‹</span></div>
    <div class="menu-row" onclick="go('clients')"><div class="mi">📇</div><b>عملائي</b><span class="ch" id="clientsCount" style="color:var(--muted);font-size:12.5px"></span></div>
    <div class="menu-row" onclick="go('account')"><div class="mi">👤</div><b>الحساب</b><span class="ch">‹</span></div>`,
'صف الرئيسية');

one(`    <div class="menu-row" onclick="go('addresses')"><div class="mi">📍</div><b>العناوين المحفوظة</b><span class="ch">‹</span></div>
    <div class="menu-row" onclick="go('notifs')"><div class="mi">🔔</div><b>الإشعارات</b><span class="ch">‹</span></div>`,
`    <div class="menu-row" onclick="go('addresses')"><div class="mi">📍</div><b>العناوين المحفوظة</b><span class="ch">‹</span></div>
    <div class="menu-row" onclick="go('clients')"><div class="mi">📇</div><b>عملائي</b><span class="ch">‹</span></div>
    <div class="menu-row" onclick="go('notifs')"><div class="mi">🔔</div><b>الإشعارات</b><span class="ch">‹</span></div>`,
'صف الحساب');

/* ═══ ③ الرسم عند فتح الشاشة ═══ */
one(`  if (page === "notifs")    renderNotifs();`,
`  if (page === "clients")   renderClients();
  if (page === "notifs")    renderNotifs();`,
'ربط الشاشة بالتنقّل');

/* ═══ ④ الكود ═══ */
one(`function openAddrSheet(id) {`,
`/* ══════════════════════════════════════════════════════════════
   عملائي — المستلمون المحفوظون
   ──────────────────────────────────────────────────────────────
   الحفظ بيحصل لوحده على السيرفر مع كل أوردر (upsert بالتليفون في
   \`customer_saved_receivers\`). الشاشة دي بتعرضهم وتخلّي التاجر:
     • يبعت لأي واحد فيهم بضغطة — البيانات بتتملي في الفورم على طول
     • أو يشيل اللي مابيتعاملش معاه
   الترتيب من السيرفر: الأحدث الأول، وأقصى 50.
══════════════════════════════════════════════════════════════ */
function renderClients() {
  const list = S.savedReceivers || [];
  const box = $("clientList"); if (!box) return;
  if (!list.length) {
    box.innerHTML = \`<div class="empty"><i>📇</i><b>لسه مافيش عملاء محفوظين</b>
      <span>أول ما تبعت أول طلب، بيانات المستلم هتتحفظ هنا لوحدها</span></div>\`;
    return;
  }
  box.innerHTML = list.map(r => \`
    <div class="card" style="display:flex;align-items:center;gap:12px">
      <div class="mi" style="width:36px;height:36px;border-radius:10px;background:var(--card2);
        display:grid;place-items:center;font-size:17px;flex:none">👤</div>
      <div style="flex:1;min-width:0">
        <b style="font-size:14px">\${esc(r.name || "—")}</b>
        <div style="font-size:12px;color:var(--muted);margin-top:3px" dir="ltr">\${esc(r.phone || "")}</div>
        <div style="font-size:11px;color:var(--dim);margin-top:2px">\${esc(r.zoneName || "")}\${r.address ? " · " + esc(r.address) : ""}</div>
      </div>
      <button class="btn ghost sm" style="padding:7px 12px;font-size:12px;flex:none"
        onclick="sendToClient('\${esc(r.id)}')">ابعت له</button>
      <div class="ico-btn" onclick="delClient('\${esc(r.id)}')">🗑️</div>
    </div>\`).join("");
}

/* بيفتح طلب جديد وبيملا بيانات المستلم — التاجر مش هيكتبها تاني */
function sendToClient(id) {
  const r = (S.savedReceivers || []).find(x => String(x.id) === String(id));
  if (!r) return;
  startNewOrder();
  /* الفورم بيتبني في خطوة واحدة، فبنستنى الرسمة قبل ما نملا */
  setTimeout(() => {
    const rc = (S.draft.receivers && S.draft.receivers[0]) || null;
    if (!rc) return;
    rc.name    = r.name || "";
    rc.phone   = r.phone || "";
    rc.phone2  = r.phone2 || "";
    rc.address = r.address || "";
    rc.zoneId  = r.zoneId ?? null;
    rc.lat     = r.lat ?? null;
    rc.lng     = r.lng ?? null;
    renderReceiverBlocks();
    updateRcvTotal();
    toast("اتملت بيانات " + (r.name || "العميل"), "ok");
  }, 260);
}
window.sendToClient = sendToClient;

async function delClient(id) {
  const r = (S.savedReceivers || []).find(x => String(x.id) === String(id));
  if (!confirm("تشيل " + (r?.name || "العميل") + " من عملائك؟\\nالطلبات القديمة مش هتتأثر.")) return;
  try {
    const res = await api.del("/api/customer/receivers/" + encodeURIComponent(id));
    S.savedReceivers = res.items || (S.savedReceivers || []).filter(x => String(x.id) !== String(id));
    renderClients(); renderClientsCount();
    toast("اتشال من عملائك", "ok");
  } catch (e) { toast(e.message || "مااتشالش — جرّب تاني", "err"); }
}
window.delClient = delClient;

/* العدّاد جنب الصف في الرئيسية */
function renderClientsCount() {
  const el = $("clientsCount"); if (!el) return;
  const n = (S.savedReceivers || []).length;
  el.textContent = n ? n + " عميل" : "‹";
}
window.renderClients = renderClients;

function openAddrSheet(id) {`,
'كود الشاشة');

/* ═══ ⑤ تحديث العدّاد أول ما القايمة توصل ═══ */
one(`      S.savedReceivers = d.items || [];`,
`      S.savedReceivers = d.items || [];
      renderClientsCount();
      if (S.page === "clients") renderClients();`,
'تحديث العدّاد');

/* ═══ ⑥ الشاشة في قايمة الصفحات ═══ */
const PAGES_OLD = s.match(/const PAGES = \[[^\]]*\]/);
if (PAGES_OLD && !PAGES_OLD[0].includes('clients')) {
  s = s.replace(PAGES_OLD[0], PAGES_OLD[0].replace(/\]$/, ', "clients"]'));
  console.log('  ✓ الشاشة اتسجّلت في PAGES');
} else {
  console.log('  · مفيش PAGES أو الشاشة متسجّلة');
}

fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log(bad ? `\n🔴 ${bad} مشكلة` : '\n✅ الشاشة اتعملت');
process.exit(bad ? 1 : 0);
