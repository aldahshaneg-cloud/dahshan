/* ═════════════════════════════════════════════════════════════════════
   v4 — المشترك بين شاشات الأوردرات: صف الجدول + نافذة التفاصيل
   شكل الأوردر = OrderWire بتاع السيرفر بالحرف (الحالة عربي، الوقت ISO).
   ═════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  "use strict";
  var V4 = w.V4, esc = V4.esc, F = V4.fmt;

  function timeOf(o) { return o.deliveredAt || o.undeliveredAt || o.cancelledAt || o.statusSince || o.createdAt; }
  function waLink(phone) { var p = String(phone || "").replace(/\D/g, ""); if (p.charAt(0) === "0") p = "2" + p; return p.length >= 10 ? "https://wa.me/" + p : ""; }

  var Orders = {
    /** صف جدول موحّد (9 أعمدة أو 8 من غير العهدة) */
    row: function (o, opt) {
      opt = opt || {};
      var ds = o.deliveries || [], first = ds[0] || {};
      var recv = ds.length > 1 ? esc(first.receiverName || "—") + ' <span class="badge gray">+' + (ds.length - 1) + "</span>" : esc(first.receiverName || "—");
      var moved = o.originBranchId && o.originBranchId !== o.branchId ? ' <span class="badge purple" title="اتعمل في ' + esc(o.originBranchName || "") + '">منقول</span>' : "";
      return '<tr class="clickable" data-order="' + esc(o.id) + '">' +
        '<td><b class="ltr">' + esc(o.orderNum || "—") + "</b></td>" +
        "<td>" + esc(o.branchName || "—") + moved + "</td>" +
        "<td>" + esc(o.senderName || "—") + '<div class="small muted">' + F.phone(o.senderPhone) + "</div></td>" +
        "<td>" + recv + '<div class="small muted">' + esc(first.zoneName || "—") + "</div></td>" +
        '<td class="green bold num">' + esc(F.money(o.totalDeliveryPrice)) + "</td>" +
        (opt.noPrepaid ? "" : '<td class="num">' + (Number(o.storePrepaid) > 0 ? '<span class="yellow bold">' + esc(F.money(o.storePrepaid)) + "</span>" : '<span class="muted">—</span>') + "</td>") +
        "<td>" + F.status(o.status) + "</td>" +
        "<td>" + (o.pilotName ? '<i class="fas fa-motorcycle muted"></i> ' + esc(o.pilotName) : '<span class="muted">—</span>') + "</td>" +
        '<td class="small"><div>' + esc(F.dt(timeOf(o))) + '</div><div class="muted">' + esc(F.ago(timeOf(o))) + "</div></td></tr>";
    },

    /** نافذة التفاصيل — عرض بس (الكول سنتر مابيعدّلش الأوردر بعد تسجيله؛ التعديل والإلغاء من الفرع) */
    open: function (id) {
      var body = d.getElementById("m-order-body"), title = d.getElementById("m-order-title");
      title.textContent = "تفاصيل الطلب"; body.innerHTML = '<div class="empty">جاري التحميل…</div>';
      V4.modal.open("m-order");
      V4.api.get("/api/orders/" + encodeURIComponent(id)).then(function (r) {
        var o = r.order || r; title.innerHTML = 'الطلب <span class="ltr">' + esc(o.orderNum || "") + "</span> " + F.status(o.status);
        body.innerHTML = Orders.detailsHtml(o);
      }).catch(function (e) { body.innerHTML = '<div class="alert err">' + esc(e.message) + "</div>"; });
    },

    detailsHtml: function (o) {
      var ds = o.deliveries || [];
      var kv = function (k, v) { return v ? '<div class="kv"><span class="muted">' + k + "</span><b>" + v + "</b></div>" : ""; };
      var times = [["اتسجّل", o.createdAt], ["الطيار استلمه", o.receivedAt], ["بدأ الرحلة", o.tripStartedAt], ["اتسلّم", o.deliveredAt], ["لم يتم التوصيل", o.undeliveredAt], ["اتلغى", o.cancelledAt]]
        .filter(function (t) { return t[1]; }).map(function (t) { return kv(t[0], esc(F.dt(t[1]))); }).join("");
      var wa = waLink(o.senderPhone);
      return '<div class="grid c2">' +
        '<div><h3><i class="fas fa-store"></i> جهة الاستلام</h3>' +
          kv("الاسم", esc(o.senderName || "—")) + kv("الهاتف", F.phone(o.senderPhone) + (wa ? ' <a href="' + wa + '" target="_blank" rel="noopener" title="واتساب"><i class="fab fa-whatsapp green"></i></a>' : "")) +
          kv("هاتف 2", o.senderPhone2 ? F.phone(o.senderPhone2) : "") + kv("العنوان", esc(o.senderAddress || "")) + kv("منطقة الاستلام", esc(o.senderZoneName || "")) +
        "</div>" +
        '<div><h3><i class="fas fa-circle-info"></i> الطلب</h3>' +
          kv("الفرع", esc(o.branchName || "—") + (o.originBranchName && o.originBranchId !== o.branchId ? ' <span class="badge purple">اتعمل في ' + esc(o.originBranchName) + "</span>" : "")) +
          kv("الطيار", o.pilotName ? esc(o.pilotName) : '<span class="muted">لسه</span>') +
          kv("إجمالي التوصيل", '<span class="green">' + esc(F.money(o.totalDeliveryPrice)) + "</span>") +
          kv("عهدة يدفعها الطيار للمحل", Number(o.storePrepaid) > 0 ? '<span class="yellow">' + esc(F.money(o.storePrepaid)) + "</span>" : "") +
          kv("ملاحظة العهدة", esc(o.storePrepaidNote || "")) +
          kv("سجّله", esc(o.addedBy || "") + (o.source ? ' <span class="badge gray">' + esc(o.source) + "</span>" : "")) +
        "</div></div>" +
        '<h3 style="margin-top:1rem"><i class="fas fa-box"></i> الطرود (' + ds.length + ")</h3>" +
        ds.map(function (p, i) {
          var pw = waLink(p.receiverPhone);
          return '<div class="card tight" style="box-shadow:none;border:1px solid var(--line);margin-bottom:.6rem">' +
            '<div class="row" style="align-items:center"><div style="flex:2"><b>' + (i + 1) + ". " + esc(p.receiverName || "—") + "</b> " + (p.status ? F.status(p.status) : "") +
            '<div class="small muted">' + F.phone(p.receiverPhone) + (p.receiverPhone2 ? " / " + F.phone(p.receiverPhone2) : "") + (pw ? ' <a href="' + pw + '" target="_blank" rel="noopener"><i class="fab fa-whatsapp green"></i></a>' : "") + "</div></div>" +
            '<div style="flex:2"><i class="fas fa-location-dot muted"></i> ' + esc(p.zoneName || "—") + '<div class="small muted">' + esc(p.address || "") + "</div></div>" +
            '<div style="flex:1;text-align:left"><div class="green bold">' + esc(F.money(p.zonePrice)) + "</div>" + (Number(p.orderPrice) > 0 ? '<div class="small yellow">عهدة ' + esc(F.money(p.orderPrice)) + "</div>" : "") + "</div></div>" +
            (p.note ? '<div class="small muted" style="margin-top:.3rem"><i class="fas fa-note-sticky"></i> ' + esc(p.note) + "</div>" : "") + "</div>";
        }).join("") +
        (o.notes ? '<div class="alert warn"><i class="fas fa-note-sticky"></i> ' + esc(o.notes) + "</div>" : "") +
        (o.undeliveredReason ? '<div class="alert err">سبب عدم التوصيل: ' + esc(o.undeliveredReason) + "</div>" : "") +
        (o.cancelledReason ? '<div class="alert err">سبب الإلغاء: ' + esc(o.cancelledReason) + (o.cancelledBy ? " — " + esc(o.cancelledBy) : "") + "</div>" : "") +
        (times ? '<h3 style="margin-top:1rem"><i class="fas fa-clock"></i> التوقيتات</h3>' + times : "") +
        '<div class="foot"><button class="btn" data-close="m-order">إغلاق</button>' +
        '<button class="btn ghost" type="button" data-copy-order="' + esc(o.orderNum || "") + '"><i class="fas fa-copy"></i> نسخ رقم الطلب</button></div>';
    },
  };

  /* أي صف أوردر في أي شاشة بيفتح نفس النافذة */
  d.addEventListener("click", function (e) {
    var cp = e.target.closest("[data-copy-order]");
    if (cp) { var t = cp.getAttribute("data-copy-order"); (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { V4.toast("اتنسخ " + t, "ok"); }, function () { V4.toast(t); }); return; }
    if (e.target.closest("a,button,input,select,label")) return;
    var tr = e.target.closest("tr[data-order]"); if (tr) Orders.open(tr.getAttribute("data-order"));
  });

  V4.Orders = Orders;
})(window, document);
