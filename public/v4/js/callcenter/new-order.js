/* ═════════════════════════════════════════════════════════════════════
   v4 · كول سنتر — فورم «طلب شحن جديد»
   ─────────────────────────────────────────────────────────────────────
   • الحفظ على نفس `POST /api/orders` بنفس الحمولة بالحرف (source=callcenter) — الترقيم وتفريق
     الطرود وإنشاء المرسل الجديد وسقف العهدة كلهم قواعد السيرفر، مفيش منها نسخة هنا.
   • بحث العملاء في السيرفر (/v4/callcenter/contacts) بدل تحميل الدفتر كله.
   • منطقة الاستلام (صفوف «البيت» بس) هي اللي بتحدّد الفرع؛ ومناطق التسليم = مناطق الفرع ده.
   • مفتاح منع التكرار clientRef ثابت لحد ما الحفظ ينجح. وبعد الحفظ: إجبار رسالة الواتساب.
   ═════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  "use strict";
  var V4 = w.V4, esc = V4.esc, F = V4.fmt;
  var $ = function (id) { return d.getElementById(id); };
  var S = { zones: [], branches: [], sender: null, senderZone: null, branchId: null, clientRef: null, seq: 0, contactFor: null };

  /* ── مكوّن اختيار بقايمة منسدلة (بحث محلي أو من السيرفر) ── */
  function picker(input, dd, opt) {
    var items = [], on = -1, tok = 0;
    function close() { dd.hidden = true; on = -1; }
    function paint() {
      dd.innerHTML = (items.length ? items.map(function (it, i) { return '<div data-i="' + i + '" class="' + (i === on ? "on" : "") + '">' + opt.row(it) + "</div>"; }).join("") : '<div class="none">' + esc(opt.empty || "مفيش نتايج") + "</div>") +
        (opt.extra ? '<div class="add" data-extra="1">' + opt.extra(input.value.trim()) + "</div>" : "");
      dd.hidden = false;
    }
    var search = V4.debounce(function () {
      var q = input.value.trim(), my = ++tok;
      if (opt.onType) opt.onType(q);
      if (q.length < (opt.min == null ? 2 : opt.min)) { close(); return; }
      Promise.resolve(opt.source(q)).then(function (r) { if (my !== tok) return; items = r || []; on = items.length ? 0 : -1; paint(); }).catch(function () { close(); });
    }, opt.remote ? 260 : 60);
    input.addEventListener("input", search);
    input.addEventListener("focus", function () { if (opt.min === 0 && !input.readOnly && !input.disabled) search(); });
    input.addEventListener("keydown", function (e) {
      if (dd.hidden) return;
      if (e.key === "ArrowDown") { e.preventDefault(); on = Math.min(items.length - 1, on + 1); paint(); }
      else if (e.key === "ArrowUp") { e.preventDefault(); on = Math.max(0, on - 1); paint(); }
      else if (e.key === "Enter") { e.preventDefault(); if (items[on]) { opt.pick(items[on]); close(); } }
      else if (e.key === "Escape") { e.stopPropagation(); close(); }
    });
    dd.addEventListener("mousedown", function (e) {               // mousedown قبل blur
      e.preventDefault();
      var x = e.target.closest("[data-extra]"); if (x) { close(); opt.onExtra(input.value.trim()); return; }
      var r = e.target.closest("[data-i]"); if (r) { opt.pick(items[+r.getAttribute("data-i")]); close(); }
    });
    input.addEventListener("blur", function () { setTimeout(close, 120); });
    return { close: close };
  }

  function zoneSource(list) { return function (q) { var n = V4.norm(q); return list().filter(function (z) { return !n || V4.norm(z.name).indexOf(n) >= 0; }).slice(0, 40); }; }
  function branchName(id) { var b = S.branches.filter(function (x) { return x.id === id; })[0]; return b ? b : null; }

  /* ── جهة الاستلام ── */
  function setSender(c) {
    S.sender = c;
    $("s-search").value = c ? c.name : ""; $("s-search").readOnly = !!c; $("s-search").classList.toggle("picked", !!c);
    $("s-phone").value = c ? c.phone1 : ""; $("s-phone2").value = c ? c.phone2 : ""; $("s-addr").value = c ? c.address : "";
    $("s-clear").hidden = !c; $("s-new").hidden = !!c;
    $("s-hint").textContent = c ? "✓ عميل متسجّل — دوس «تغيير» لو عايز غيره" : "اكتب حرفين على الأقل — أو سجّل عميل جديد من الزرار.";
    /* آخر منطقة استلام للعميل ده بتتختار لوحدها (والموظف يقدر يغيّرها) */
    if (c && c.lastZoneId && !S.senderZone) { var z = S.zones.filter(function (x) { return x.id === c.lastZoneId && x.home; })[0]; if (z) setSenderZone(z); }
  }
  function setSenderZone(z) {
    S.senderZone = z; S.branchId = z ? z.branchId : null;
    $("s-zone").value = z ? z.name : ""; $("s-zone").classList.toggle("picked", !!z);
    var b = z ? branchName(z.branchId) : null;
    $("s-branch").innerHTML = z
      ? (b ? '🏢 الأوردر ده على <b class="green">' + esc(b.name) + "</b>" + (b.paused ? ' <span class="badge yellow">الفرع موقوف حاليًا</span>' : "") : '<span class="red">المنطقة دي مش مربوطة بفرع — كلّم الإدارة</span>')
      : '<span class="muted">💡 اختار منطقة الاستلام والفرع هيتحدّد لوحده</span>';
    /* مناطق التسليم بتتقفل على مناطق الفرع اللي طلع — أي منطقة مختارة من فرع تاني بتتصفّر */
    d.querySelectorAll("[data-parcel]").forEach(function (p) {
      var zi = p.querySelector('[data-f="zone"]'); zi.disabled = !S.branchId; zi.placeholder = S.branchId ? "ابحث باسم المنطقة…" : "اختار منطقة الاستلام الأول";
      if (p._zone && p._zone.branchId !== S.branchId) setParcelZone(p, null);
    });
  }

  /* ── الطرود ── */
  function setParcelZone(p, z) {
    p._zone = z; var zi = p.querySelector('[data-f="zone"]');
    zi.value = z ? z.name : ""; zi.classList.toggle("picked", !!z);
    p.querySelector('[data-f="price"]').value = z ? z.price : 0; totals();
  }
  function addParcel() {
    var node = $("tpl-parcel").content.firstElementChild.cloneNode(true); node._id = ++S.seq; node._zone = null; node._recvId = null;
    $("parcels").appendChild(node);
    var f = function (k) { return node.querySelector('[data-f="' + k + '"]'); };
    f("zone").disabled = !S.branchId; if (S.branchId) f("zone").placeholder = "ابحث باسم المنطقة…";
    picker(f("name"), node.querySelector('[data-dd="recv"]'), {
      remote: true, source: function (q) { return V4.api.get("/v4/callcenter/contacts", { type: "receivers", q: q }).then(function (r) { return r.items; }); },
      row: function (c) { return "<b>" + esc(c.name) + '</b> <span class="ltr meta">' + esc(c.phone1) + '</span><div class="meta">' + esc(c.lastZoneName || "") + (c.lastAddress ? " · " + esc(c.lastAddress) : "") + "</div>"; },
      empty: "مش متسجّل — كمّل اكتب بياناته وهيتسجّل في الدفتر مع الحفظ",
      onType: function () { node._recvId = null; f("name").classList.remove("picked"); },
      pick: function (c) {
        node._recvId = c.id; f("name").value = c.name; f("name").classList.add("picked"); f("phone").value = c.phone1 || ""; f("phone2").value = c.phone2 || "";
        if (!f("addr").value) f("addr").value = c.lastAddress || c.address || "";
        if (c.lastZoneId && S.branchId && !node._zone) { var z = S.zones.filter(function (x) { return x.id === c.lastZoneId && x.branchId === S.branchId; })[0]; if (z) setParcelZone(node, z); }
      },
    });
    picker(f("zone"), node.querySelector('[data-dd="zone"]'), {
      min: 0, source: zoneSource(function () { return S.zones.filter(function (z) { return z.branchId === S.branchId; }); }),
      row: function (z) { return esc(z.name) + ' <span class="meta ltr">' + esc(z.price) + " ج.م</span>"; }, empty: "مفيش منطقة بالاسم ده في الفرع",
      onType: function () { if (node._zone) { node._zone = null; f("zone").classList.remove("picked"); f("price").value = 0; totals(); } },
      pick: function (z) { setParcelZone(node, z); },
    });
    f("prepaid").addEventListener("input", totals);
    node.querySelector("[data-remove]").onclick = function () {
      if (d.querySelectorAll("[data-parcel]").length <= 1) { V4.toast("لازم وجهة واحدة على الأقل", "err"); return; }
      node.remove(); renumber(); totals();
    };
    renumber(); totals();
    return node;
  }
  function renumber() { d.querySelectorAll("[data-parcel]").forEach(function (p, i) { p.querySelector("[data-no]").textContent = "#" + (i + 1); }); }
  function totals() {
    var ps = d.querySelectorAll("[data-parcel]"), del = 0, pre = 0;
    ps.forEach(function (p) { del += p._zone ? Number(p._zone.price) || 0 : 0; pre += parseFloat(p.querySelector('[data-f="prepaid"]').value) || 0; });
    $("t-count").textContent = ps.length; $("t-delivery").textContent = F.money(del); $("t-prepaid").textContent = F.money(pre);
  }
  function fail(p, field, msg) {
    V4.toast(msg, "err");
    if (p) { p.classList.add("bad"); setTimeout(function () { p.classList.remove("bad"); }, 2600); p.scrollIntoView({ behavior: "smooth", block: "center" }); var el = p.querySelector('[data-f="' + field + '"]'); if (el) el.focus(); }
    return null;
  }

  /* ── تجميع الحمولة (نفس مفاتيح التطبيق القديم بالحرف) ── */
  function collect() {
    var name = S.sender ? S.sender.name : $("s-search").value.trim();
    if (!name) { V4.toast("اختار أو سجّل عميل الاستلام", "err"); $("s-search").focus(); return null; }
    if (!S.sender) { V4.toast("العميل ده مش متسجّل — سجّله من زرار «عميل جديد»", "err"); return null; }
    if (!S.senderZone) { V4.toast("اختار منطقة الاستلام — منها بيتحدّد الفرع", "err"); $("s-zone").focus(); return null; }
    if (!S.branchId) { V4.toast("منطقة الاستلام دي مش مربوطة بفرع — كلّم الإدارة", "err"); return null; }
    var deliveries = [], ps = d.querySelectorAll("[data-parcel]");
    for (var i = 0; i < ps.length; i++) {
      var p = ps[i], g = function (k) { return p.querySelector('[data-f="' + k + '"]').value.trim(); }, no = i + 1;
      if (!g("name")) return fail(p, "name", "اكتب اسم جهة التسليم — طرد #" + no);
      if (g("name").length > 190) return fail(p, "name", "اسم المستلم طويل جدًا — يبدو إنك لزقت الرسالة كلها، اكتب الاسم بس (طرد #" + no + ")");
      if (!p._zone) return fail(p, "zone", "اختار منطقة التسليم من القايمة — طرد #" + no);
      var loc = null, lt = g("loc");
      if (lt) { loc = w.GeoLoc ? w.GeoLoc.parse(lt) : null; if (!loc) return fail(p, "loc", "لوكيشن الطرد #" + no + " مش مفهوم — الصق إحداثيات زي 31.0187, 31.2283 أو لينك خرائط جوجل، أو سيبه فاضي"); }
      var prepaid = parseFloat(g("prepaid")) || 0; if (prepaid < 0) return fail(p, "prepaid", "العهدة مينفعش تكون بالسالب — طرد #" + no);
      deliveries.push({
        lat: loc ? loc.lat : null, lng: loc ? loc.lng : null, parcelNo: no, receiverId: p._recvId || null, receiverName: g("name"),
        receiverPhone: V4.cleanPhone(g("phone")), receiverPhone2: V4.cleanPhone(g("phone2")),
        zoneId: p._zone.id, zoneName: p._zone.name, zonePrice: p._zone.price, orderPrice: prepaid,
        address: g("addr"), note: g("note"), status: "قيد التنفيذ", images: [],
      });
    }
    if (!deliveries.length) { V4.toast("أضف وجهة تسليم واحدة على الأقل", "err"); return null; }
    if (!S.clientRef) S.clientRef = (w.crypto && crypto.randomUUID) ? crypto.randomUUID() : Date.now().toString(36) + "-" + Math.random().toString(36).slice(2);
    return {
      clientRef: S.clientRef, senderId: S.sender.id, senderName: S.sender.name, senderPhone: S.sender.phone1, senderPhone2: S.sender.phone2 || "", senderAddress: S.sender.address || "",
      senderZoneId: S.senderZone.id, senderZoneName: S.senderZone.name, branchId: S.branchId, notes: $("o-notes").value.trim(), deliveries: deliveries,
      storePrepaidNote: $("o-prepaid-note").value.trim(), goodsValue: Number($("o-goods").value) || 0, source: "callcenter",
    };
  }

  function resetForm() {
    S.clientRef = null; setSender(null); S.senderZone = null; setSenderZone(null);
    $("parcels").innerHTML = ""; ["o-notes", "o-prepaid-note", "o-goods"].forEach(function (i) { $(i).value = ""; });
    addParcel(); $("s-search").focus();
  }

  function save() {
    var body = collect(); if (!body) return;
    var btn = $("o-save"); btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ…';
    var restore = function () { btn.disabled = false; btn.innerHTML = '<i class="fas fa-floppy-disk"></i> حفظ الطلب'; };
    /* المستلم الجديد (مكتوب بإيده ومعاه رقم) بيتسجّل في دفتر عملاء التسليم قبل الأوردر — المرة الجاية
       يطلع في البحث بمنطقته وعنوانه. POST /api/receivers بيرجّع الموجود لو الرقم متسجّل (dedupe في
       السيرفر). فشل التسجيل مابيوقّفش الأوردر: الاسم والرقم رايحين مع الطرد في كل الأحوال. */
    var regs = body.deliveries.map(function (dl) {
      if (dl.receiverId || !dl.receiverPhone) return Promise.resolve();
      return V4.api.post("/api/receivers", { name: dl.receiverName, phone1: dl.receiverPhone, phone2: dl.receiverPhone2 || "", address: dl.address || dl.zoneName })
        .then(function (r) { if (r.item && r.item.id) dl.receiverId = r.item.id; }, function () {});
    });
    Promise.all(regs).then(function () { return V4.api.post("/api/orders", body); }).then(function (res) {
      /* 🔒 من هنا الأوردر متسجّل على السيرفر — ممنوع أي رسالة توحي بفشل الحفظ مهما الشاشة اتعثرت */
      S.clientRef = null;
      var made = (res.orders || (res.order ? [res.order] : [])), nums = made.map(function (o) { return o && o.orderNum; }).filter(Boolean), ids = made.map(function (o) { return o && o.id; }).filter(Boolean);
      try {
        V4.toast(res.duplicate ? "الأوردر ده كان اتسجّل خلاص من شوية (" + nums.join("، ") + ") ✓ — مافيش نسخة جديدة اتعملت"
          : nums.length > 1 ? "اتسجّلوا " + nums.length + " أوردرات — كل طرد أوردر بذاته: " + nums.join("، ") + " ✓" : "تمّ حفظ الطلب " + (nums[0] || "") + " ✓", "ok");
        resetForm();
      } catch (e) { console.error(e); V4.toast("الأوردر اتسجّل ✓ — بس الشاشة اتعثرت بعد الحفظ، اعمل تحديث للصفحة قبل أوردر جديد", "err"); }
      restore();
      return V4.waNotify(ids);
    }, function (e) { V4.toast("خطأ أثناء الحفظ: " + e.message, "err"); restore(); });
  }

  /* ── فورم العميل الجديد العائم ── */
  function openContact(prefill) {
    var digits = V4.cleanPhone(prefill), isPhone = digits.length >= 7 && /^[\d+\s\-()٠-٩]+$/.test(prefill);
    $("mc-title").textContent = "تسجيل عميل استلام جديد";
    $("mc-name").value = isPhone ? "" : prefill; $("mc-phone").value = isPhone ? digits : ""; $("mc-phone2").value = ""; $("mc-addr").value = "";
    V4.modal.open("m-contact"); setTimeout(function () { (isPhone ? $("mc-name") : $("mc-phone")).focus(); }, 60);
  }
  function saveContact() {
    var name = $("mc-name").value.trim(), phone = V4.cleanPhone($("mc-phone").value), phone2 = V4.cleanPhone($("mc-phone2").value), addr = $("mc-addr").value.trim();
    if (!name || !phone || !addr) { V4.toast("الاسم والهاتف والعنوان مطلوبين", "err"); return; }
    var b = $("mc-save"); b.disabled = true;
    /* POST /api/senders بيرجّع الموجود لو الرقم متسجّل قبل كده (dedupe على السيرفر) */
    V4.api.post("/api/senders", { name: name, phone1: phone, phone2: phone2, address: addr }).then(function (r) {
      var it = r.item || {};
      setSender({ id: it.id, name: it.name || name, phone1: it.phone1 || phone, phone2: it.phone2 || phone2, address: it.address || addr });
      V4.modal.close("m-contact");
      V4.toast(r.existing ? "الرقم موجود مسبقًا — تمّ اختيار العميل ✓" : "تمّ تسجيل العميل ✓", "ok");
      $("s-zone").focus();
    }, function (e) { V4.toast("خطأ: " + e.message, "err"); }).then(function () { b.disabled = false; });
  }

  d.addEventListener("DOMContentLoaded", function () {
    picker($("s-search"), $("s-dd"), {
      remote: true, source: function (q) { return V4.api.get("/v4/callcenter/contacts", { type: "senders", q: q }).then(function (r) { return r.items; }); },
      row: function (c) { return "<b>" + esc(c.name) + '</b> <span class="ltr meta">' + esc(c.phone1) + '</span><div class="meta">' + esc(c.address || "") + "</div>"; },
      extra: function (q) { return '<i class="fas fa-user-plus"></i> تسجيل «' + esc(q) + "» عميل جديد"; }, onExtra: openContact, pick: setSender,
    });
    picker($("s-zone"), $("s-zone-dd"), {
      min: 0, source: zoneSource(function () { return S.zones.filter(function (z) { return z.home; }); }),
      row: function (z) { var b = branchName(z.branchId); return esc(z.name) + ' <span class="meta">' + esc(b ? b.name : "") + "</span>"; }, empty: "مفيش منطقة بالاسم ده",
      /* الموظف بيعدّل النص بعد اختيار → الاختيار بيتلغى (والفرع معاه) من غير ما نمسح اللي بيكتبه */
      onType: function () { if (S.senderZone) { var t = $("s-zone").value; setSenderZone(null); $("s-zone").value = t; } }, pick: setSenderZone,
    });
    $("s-clear").onclick = function () { setSender(null); $("s-search").focus(); };
    $("s-new").onclick = function () { openContact($("s-search").value.trim()); };
    $("mc-save").onclick = saveContact;
    $("p-add").onclick = function () { var n = addParcel(); n.querySelector('[data-f="name"]').focus(); };
    $("o-save").onclick = save;
    /* المكتوب في الفورم مايضيعش بقفل التبويب بالغلط */
    w.addEventListener("beforeunload", function (e) { if (S.sender || $("s-search").value.trim() || [].some.call(d.querySelectorAll('[data-f="name"]'), function (i) { return i.value.trim(); })) { e.preventDefault(); e.returnValue = ""; } });

    V4.api.get("/v4/callcenter/form-data").then(function (r) { S.zones = r.zones || []; S.branches = r.branches || []; addParcel(); })
      .catch(function (e) { V4.toast("تعذّر تحميل المناطق: " + e.message, "err"); });
  });
})(window, document);
