/**
 * trust-admin.js — منظومة الثقة في **لوحات الإدارة والموظفين**
 * (customers.html / stores.html / branch.html / callcenter.html)
 *
 * ليه ملف منفصل عن assets/js/trust.js؟
 *   trust.js بيخدم بوابة المحل وتطبيق العميل (بادچ جنب خانة التليفون + مودال
 *   تقييم المستلم بعد الأوردر). اللوحات الإدارية محتاجة حاجات تانية خالص:
 *   كارت سمعة كامل، لستة التقييمات، تقييم مباشر بلا أوردر، تصحيح الاسم
 *   المعتمد، وسجل البحث. فصلنا النطاق (TrustAdmin) عشان الملفين ما يتصادموش
 *   ويقدر كل واحد يتغيّر لوحده.
 *
 * المسارات المستخدَمة (api/routes/trust.php):
 *   GET  /api/lookup?phone=            البحث الفوري — الخصوصية بتتطبق سيرفر-سايد
 *   GET  /api/trust/{phone}/ratings    السمعة + آخر التقييمات
 *   POST /api/trust/rate               تقييم مباشر (admin)
 *   PUT  /api/trust/{phone}/identity   تصحيح الاسم/العنوان المعتمد (موظفين)
 *   GET  /api/trust/lookup-log         سجل البحث (admin)
 *
 * قواعد ملزمة اتراعت هنا:
 *   - الواجهة **مبتقرّرش صلاحية ومبتفكّش تقنيع** — بتعرض اللي السيرفر بعته بس.
 *   - عدد الشحنات لازم يبان جنب الدرجة (5 نجوم من شحنتين مش زي 5 من 200).
 *   - «جديد» (score = null) مش «صفر» — التمييز ظاهر في كل عرض.
 *   - كل الستايلات inline عشان الملف يشتغل في أي صفحة بلا لمس CSS بتاعها.
 */
(function (global) {
  "use strict";

  var LEVELS = {
    "ممتاز":     { bg: "rgba(21,128,61,.13)",   fg: "#15803d", icon: "⭐" },
    "جيد":       { bg: "rgba(29,78,216,.13)",   fg: "#1d4ed8", icon: "👍" },
    "متوسط":     { bg: "rgba(194,65,12,.14)",   fg: "#c2410c", icon: "⚠️" },
    "محتاج حذر": { bg: "rgba(209,23,42,.14)",   fg: "#d1172a", icon: "🚩" },
    "جديد":      { bg: "rgba(128,128,128,.15)", fg: "#6b7280", icon: "🆕" }
  };

  function esc(s) {
    return String(s === null || s === undefined ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  /* ── تطبيع الرقم — نفس خطوات trust_normalize_phone في api/trust.php ──
     الواجهة بتطبّع بس عشان تعرف «ده رقم ولا اسم» وتبني مفتاح كاش —
     السيرفر هو المرجع في التخزين والمقارنة. */
  function normalizePhone(p) {
    var s = String(p === null || p === undefined ? "" : p).replace(/[^0-9+]/g, "");
    if (!s) return "";
    if (s.charAt(0) === "+") s = s.slice(1);
    if (s.indexOf("00") === 0) s = s.slice(2);
    s = s.replace(/[^0-9]/g, "");
    if (!s) return "";
    if (s.indexOf("20") === 0 && s.length > 10) {
      var rest = s.slice(2);
      s = rest.charAt(0) === "0" ? rest : ("0" + rest);
    }
    if (s.length === 10 && s.charAt(0) === "1") s = "0" + s;
    return s;
  }

  /** هل اللي المستخدم كتبه رقم تليفون (مش اسم)؟ — أرقام وفواصل بس و≥10 رقم */
  function looksLikePhone(raw) {
    var t = String(raw === null || raw === undefined ? "" : raw).trim();
    if (!t) return false;
    if (!/^[0-9+\-\s().]+$/.test(t)) return false;
    return normalizePhone(t).length >= 10;
  }

  function level(score) {
    if (score === null || score === undefined) return "جديد";
    if (score >= 4.5) return "ممتاز";
    if (score >= 3.5) return "جيد";
    if (score >= 2.5) return "متوسط";
    return "محتاج حذر";
  }

  /** «شحنة واحدة» / «شحنتين» / «9 شحنات» / «34 شحنة» */
  function shipmentsText(n) {
    n = Number(n) || 0;
    if (n <= 0) return "";
    if (n === 1) return "شحنة واحدة";
    if (n === 2) return "شحنتين";
    if (n <= 10) return n + " شحنات";
    return n + " شحنة";
  }

  /** نجوم مرئية — نص فاضي لو مفيش درجة («جديد» ملوش نجوم) */
  function starsText(score) {
    if (score === null || score === undefined) return "";
    var full = Math.round(Number(score) || 0);
    if (full < 0) full = 0;
    if (full > 5) full = 5;
    return new Array(full + 1).join("★") + new Array(5 - full + 1).join("☆");
  }

  /**
   * بادچ السمعة: «⭐ ممتاز · ★★★★★ 4.6 · 34 شحنة».
   * @param rep كائن reputation الراجع من السيرفر
   * @param opts {size:'sm'|'md'}
   */
  function badgeHtml(rep, opts) {
    opts = opts || {};
    rep = rep || {};
    var lv = rep.level || level(rep.score);
    var c = LEVELS[lv] || LEVELS["جديد"];
    var isNew = rep.score === null || rep.score === undefined;
    var fs = opts.size === "sm" ? "11.5px" : "12.5px";
    var body = isNew ? "عميل جديد" : (starsText(rep.score) + " " + Number(rep.score).toFixed(1));
    var ship = shipmentsText(rep.totalOrders);
    var manual = Number(rep.manualCount || 0);
    return '<span style="display:inline-flex;align-items:center;gap:6px;' +
      "background:" + c.bg + ";color:" + c.fg + ";border:1px solid " + c.fg + "33;" +
      "border-radius:99px;padding:3px 10px;font-size:" + fs + ";font-weight:800;" +
      'white-space:nowrap;direction:rtl;line-height:1.7">' +
      c.icon + " " + esc(lv) +
      '<span style="opacity:.85;font-weight:700">' + esc(body) + "</span>" +
      '<span style="opacity:.7;font-weight:700;font-size:11px">· ' +
      esc(ship || (isNew ? "مفيش تاريخ" : "بلا شحنات")) +
      (manual > 0 ? " · " + manual + " تقييم" : "") + "</span></span>";
  }

  /** سطر تفصيلي تحت البادچ — تم التسليم / لم يتم التوصيل / نجوم النجاح / اليدوي */
  function detailsHtml(rep) {
    rep = rep || {};
    var bits = [];
    bits.push("تم التسليم: <b>" + Number(rep.delivered || 0) + "</b>");
    bits.push("لم يتم التوصيل: <b>" + Number(rep.undelivered || 0) + "</b>");
    if (rep.successStars !== null && rep.successStars !== undefined)
      bits.push("نجوم النجاح: <b>" + Number(rep.successStars).toFixed(2) + "</b>");
    if (rep.manualCount)
      bits.push("متوسط اليدوي: <b>" + Number(rep.manualAvg).toFixed(2) + "</b> من " + rep.manualCount);
    return '<div style="font-size:11.5px;opacity:.75;line-height:1.9">' + bits.join(" · ") + "</div>";
  }

  /* ── نداءات الـ API ── */
  function api() {
    if (!global.API) throw new Error("assets/js/api.js لازم يتحمّل قبل trust-admin.js");
    return global.API;
  }
  /* كاش دقيقتين لنتيجة البحث — السيرفر بيحد 60 بحث/ساعة للطرف الواحد،
     وموظف الكول سنتر بيرجع لنفس الرقم كذا مرة في نفس المكالمة. */
  var LOOKUP_TTL = 120000;
  var _lookupCache = {};
  function lookup(phone) {
    var key = normalizePhone(phone);
    var hit = _lookupCache[key];
    if (hit && Date.now() - hit.at < LOOKUP_TTL) return Promise.resolve(hit.data);
    return api().get("/api/lookup", { phone: key }).then(function (d) {
      _lookupCache[key] = { at: Date.now(), data: d };
      return d;
    });
  }
  /** نسيان نتيجة رقم (بعد تقييم مثلًا — السمعة اتغيّرت) */
  function forget(phone) { delete _lookupCache[normalizePhone(phone)]; }
  function ratings(phone) { return api().get("/api/trust/" + encodeURIComponent(normalizePhone(phone)) + "/ratings"); }
  function rate(body) { return api().post("/api/trust/rate", body); }
  function saveIdentity(phone, body) {
    return api().put("/api/trust/" + encodeURIComponent(normalizePhone(phone)) + "/identity", body);
  }
  function lookupLog(params) { return api().get("/api/trust/lookup-log", params || {}); }

  /**
   * البحث الفوري: بيربط input بالبحث بالتليفون بعد سكوت قصير.
   * بيضيف listener مستقل — مبيلغيش أي منطق تاني على نفس الخانة.
   * opts: { delay, onResult(data, phone), onClear(), onError(e), onLoading() }
   */
  function attach(input, opts) {
    if (!input) return function () {};
    opts = opts || {};
    var delay = opts.delay || 450;
    var timer = null, lastPhone = "", seq = 0;

    function run() {
      var raw = String(input.value || "").trim();
      if (!looksLikePhone(raw)) {
        lastPhone = ""; seq++;
        if (opts.onClear) opts.onClear();
        return;
      }
      var phone = normalizePhone(raw);
      if (phone === lastPhone) return;      // نفس الرقم — مفيش نداء تاني
      lastPhone = phone;
      var mine = ++seq;
      if (opts.onLoading) opts.onLoading();
      lookup(phone).then(function (d) {
        if (mine !== seq) return;           // رد قديم لرقم اتغيّر — بيتتجاهل
        if (opts.onResult) opts.onResult(d, phone);
      }).catch(function (e) {
        if (mine !== seq) return;
        lastPhone = "";                     // نسمح بإعادة المحاولة
        if (opts.onError) opts.onError(e);
      });
    }

    input.addEventListener("input", function () {
      clearTimeout(timer);
      timer = setTimeout(run, delay);
    });
    input.addEventListener("blur", function () {
      clearTimeout(timer);
      timer = setTimeout(run, 80);
    });
    return run;
  }

  /* ── منتقي النجوم (لمودالات التقييم في اللوحات) ── */
  var _picked = {};
  function starPickerHtml(id, initial) {
    _picked[id] = Number(initial || 0);
    var out = '<div id="' + esc(id) + '" style="display:flex;gap:6px;direction:ltr">';
    for (var i = 1; i <= 5; i++) {
      out += '<button type="button" data-star="' + i + '" ' +
        "onclick=\"TrustAdmin.pick('" + esc(id) + "'," + i + ')" ' +
        'style="background:none;border:none;cursor:pointer;font-size:30px;line-height:1;padding:0;' +
        "color:" + (i <= _picked[id] ? "#f59e0b" : "#c9ced3") + '">★</button>';
    }
    return out + "</div>";
  }
  function pick(id, n) {
    _picked[id] = n;
    var box = document.getElementById(id);
    if (!box) return;
    Array.prototype.forEach.call(box.querySelectorAll("[data-star]"), function (b) {
      b.style.color = Number(b.getAttribute("data-star")) <= n ? "#f59e0b" : "#c9ced3";
    });
  }
  function picked(id) { return Number(_picked[id] || 0); }

  /** لستة آخر التقييمات — شكل موحّد في كل اللوحات */
  var RATER = { admin: "الإدارة", store: "محل", sender: "مُرسِل", customer: "عميل" };
  function ratingsListHtml(items, limit) {
    items = items || [];
    if (!items.length)
      return '<div style="padding:18px;text-align:center;font-size:12.5px;opacity:.6">مفيش تقييمات مسجّلة</div>';
    return items.slice(0, limit || 8).map(function (r) {
      var d = r.createdAt ? new Date(r.createdAt).toLocaleString("ar-EG") : "—";
      return '<div style="padding:10px 16px;border-bottom:1px solid rgba(128,128,128,.18)">' +
        '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">' +
        '<span style="color:#f59e0b;font-size:14px;letter-spacing:1px">' + starsText(r.stars) + "</span>" +
        '<b style="font-size:12.5px">' + esc(r.raterName || "—") + "</b>" +
        '<span style="font-size:11px;opacity:.65">' + esc(RATER[r.raterType] || r.raterType || "") +
        (r.orderNum ? ' · <span dir="ltr">' + esc(r.orderNum) + "</span>" : "") + "</span>" +
        '<span style="margin-inline-start:auto;font-size:11px;opacity:.6">' + esc(d) + "</span></div>" +
        (r.note ? '<div style="font-size:12.5px;opacity:.85;margin-top:4px">' + esc(r.note) + "</div>" : "") +
        "</div>";
    }).join("");
  }

  global.TrustAdmin = {
    normalizePhone: normalizePhone,
    looksLikePhone: looksLikePhone,
    level: level,
    starsText: starsText,
    shipmentsText: shipmentsText,
    badgeHtml: badgeHtml,
    detailsHtml: detailsHtml,
    ratingsListHtml: ratingsListHtml,
    starPickerHtml: starPickerHtml,
    pick: pick,
    picked: picked,
    attach: attach,
    lookup: lookup,
    forget: forget,
    ratings: ratings,
    rate: rate,
    saveIdentity: saveIdentity,
    lookupLog: lookupLog,
    esc: esc
  };
})(window);
