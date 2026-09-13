/**
 * trust.js — منظومة الثقة في الواجهات (بوابة المحل + تطبيق العميل)
 *
 * الفكرة (قرار صاحب النظام): النظام منصة ثقة — أي طرف يعرف مين قدامه قبل
 * ما يتعامل. الملف ده هو الجزء اللي بيلمسه المستخدم من المنظومة:
 *
 *   Trust.bindPhoneField()  — أول ما يخلص كتابة رقم المستلم (debounce 400ms
 *                             أو عند blur) بينده GET /api/lookup?phone= ويعرض
 *                             بادچ الثقة جنب الخانة، وبيسلّم النتيجة للواجهة
 *                             عشان تصحّح الاسم/العنوان.
 *   Trust.badgeHtml()       — «⭐ 4.6 · ممتاز · 34 شحنة» / «🆕 عميل جديد» /
 *                             «⚠️ 2.1 · محتاج حذر · 9 شحنات»
 *   Trust.ratingModal()     — مودال 5 نجوم + ملاحظة اختيارية
 *   Trust.rateReceiver()    — POST /api/orders/{id}/rate-receiver
 *   Trust.markRated/getRated— حالة «الأوردر ده اتقيّم» محليًا عشان الزرار
 *                             يتحوّل لعرض التقييم بعد الإرسال (الـ API
 *                             بيمنع التكرار بمفتاح فريد، فأي 409 بيتسجّل هنا كمان)
 *
 * قاعدة الخصوصية بتتطبق **سيرفر-سايد** في api/routes/trust.php — الملف ده
 * بيعرض اللي الرد بيسمح بيه بس (fullAccess). التقييم وعدد الشحنات بيظهروا
 * دايمًا، والاسم الكامل والعنوان بس لمن اتعامل مع الرقم قبل كده.
 */
(function (global) {
  "use strict";

  var CACHE_MS   = 120000;   // نتيجة البحث بتتكاش دقيقتين (حد السيرفر 60 بحث/ساعة)
  var RATED_KEY  = "tiar-receiver-ratings";
  var _cache     = {};

  function api() { return global.API; }

  function esc(v) {
    return String(v === null || v === undefined ? "" : v).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function el(x) { return typeof x === "string" ? document.getElementById(x) : x; }

  /* ═══════════════════════════════════════════════════════════
     1) تطبيع الرقم — نسخة الواجهة من trust_normalize_phone (api/trust.php)
        نفس الخطوات بالظبط عشان مفتاح الكاش يطابق مفتاح السيرفر.
  ═══════════════════════════════════════════════════════════ */
  function normPhone(p) {
    var s = String(p === null || p === undefined ? "" : p).replace(/[^0-9+]/g, "");
    if (!s) return "";
    if (s.charAt(0) === "+") s = s.slice(1);
    if (s.indexOf("00") === 0) s = s.slice(2);
    s = s.replace(/[^0-9]/g, "");
    if (!s) return "";
    if (s.indexOf("20") === 0 && s.length > 10) {
      var rest = s.slice(2);
      s = rest.charAt(0) === "0" ? rest : "0" + rest;
    }
    if (s.length === 10 && s.charAt(0) === "1") s = "0" + s;
    return s;
  }

  /* ═══════════════════════════════════════════════════════════
     2) البحث بالتليفون — GET /api/lookup
  ═══════════════════════════════════════════════════════════ */
  function lookup(phone) {
    var key = normPhone(phone);
    if (key.length < 7) return Promise.resolve(null);
    var hit = _cache[key];
    if (hit && Date.now() - hit.at < CACHE_MS) return Promise.resolve(hit.data);
    return api().get("/api/lookup", { phone: key }).then(function (d) {
      _cache[key] = { at: Date.now(), data: d };
      return d;
    });
  }

  /** نسيان نتيجة رقم (بعد تقييم مثلًا — السمعة اتغيّرت) */
  function forget(phone) {
    var key = normPhone(phone);
    if (key) delete _cache[key];
  }

  /* ═══════════════════════════════════════════════════════════
     3) بادچ الثقة
  ═══════════════════════════════════════════════════════════ */
  var LEVELS = {
    "ممتاز":     { icon: "⭐",  fg: "#22c55e", bg: "rgba(34,197,94,.12)",   bd: "rgba(34,197,94,.38)" },
    "جيد":       { icon: "⭐",  fg: "#84cc16", bg: "rgba(132,204,22,.12)",  bd: "rgba(132,204,22,.38)" },
    "متوسط":     { icon: "⭐",  fg: "#f59e0b", bg: "rgba(245,158,11,.12)",  bd: "rgba(245,158,11,.38)" },
    "محتاج حذر": { icon: "⚠️", fg: "#ef4444", bg: "rgba(239,68,68,.12)",   bd: "rgba(239,68,68,.42)" },
    "جديد":      { icon: "🆕",  fg: "#94a3b8", bg: "rgba(148,163,184,.10)", bd: "rgba(148,163,184,.32)" }
  };

  /** «شحنة واحدة» / «شحنتين» / «9 شحنات» / «34 شحنة» */
  function shipmentsText(n) {
    n = Number(n) || 0;
    if (n <= 0)  return "";
    if (n === 1) return "شحنة واحدة";
    if (n === 2) return "شحنتين";
    if (n <= 10) return n + " شحنات";
    return n + " شحنة";
  }

  /**
   * نص البادچ حسب المواصفة:
   *   «⭐ 4.6 · ممتاز · 34 شحنة» — «🆕 عميل جديد» — «⚠️ 2.1 · محتاج حذر · 9 شحنات»
   * لازم عدد الشحنات يظهر جنب الدرجة (5 نجوم من شحنتين مش زي 5 نجوم من 200).
   */
  function badgeParts(rep) {
    rep = rep || {};
    var level = rep.level || "جديد";
    var meta  = LEVELS[level] || LEVELS["جديد"];
    var ship  = shipmentsText(rep.totalOrders);
    var text;
    if (rep.score === null || rep.score === undefined) {
      text = "عميل جديد" + (ship ? " · " + ship : "");
    } else {
      text = Number(rep.score).toFixed(1) + " · " + level + (ship ? " · " + ship : "");
    }
    return { icon: meta.icon, text: text, fg: meta.fg, bg: meta.bg, bd: meta.bd, level: level };
  }

  function badgeHtml(rep, opts) {
    opts = opts || {};
    var p = badgeParts(rep);
    return '<span style="display:inline-flex;align-items:center;gap:6px;' +
      'padding:4px 10px;border-radius:999px;font-weight:800;font-size:' + (opts.size || ".76rem") + ';' +
      'line-height:1.6;background:' + p.bg + ';border:1px solid ' + p.bd + ';color:' + p.fg + ';' +
      (opts.style || "") + '">' + p.icon + " " + esc(p.text) + "</span>";
  }

  /* صندوق النتيجة تحت خانة التليفون (بادچ + سطر الخصوصية) */
  function resultHtml(res) {
    if (!res) return "";
    var out = badgeHtml(res.reputation);
    if (!res.found) {
      return '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;' +
        'border-radius:999px;font-weight:800;font-size:.76rem;background:rgba(148,163,184,.10);' +
        'border:1px solid rgba(148,163,184,.32);color:#94a3b8">🆕 رقم جديد — أول تعامل معاه</span>';
    }
    if (!res.fullAccess) {
      out += '<div style="font-size:.7rem;opacity:.75;margin-top:5px;line-height:1.7">' +
        (res.name ? "الاسم: <b>" + esc(res.name) + "</b> — " : "") +
        "البيانات الكاملة بتظهر بس لو اتعاملت مع الرقم ده قبل كده</div>";
    }
    return out;
  }

  function renderState(box, state, res, msg) {
    if (!box) return;
    if (state === "idle")    { box.innerHTML = ""; box.style.display = "none"; return; }
    box.style.display = "";
    if (state === "loading") {
      box.innerHTML = '<span style="font-size:.72rem;opacity:.6">⏳ بنشوف سجل الرقم…</span>';
    } else if (state === "error") {
      box.innerHTML = '<span style="font-size:.72rem;color:#f59e0b">' + esc(msg || "تعذّر فحص الرقم") + "</span>";
    } else {
      box.innerHTML = resultHtml(res);
    }
  }

  /* ═══════════════════════════════════════════════════════════
     4) ربط خانة التليفون بالبحث الفوري
     opts: { input, badge, delay, onResult(res), onClear() }
  ═══════════════════════════════════════════════════════════ */
  function bindPhoneField(opts) {
    var input = el(opts.input);
    if (!input || input._trustBound) return;
    input._trustBound = true;
    var box = el(opts.badge);
    var timer = null, lastKey = null, seq = 0;

    function run() {
      var key = normPhone(input.value);
      if (key.length < 7) {
        lastKey = null; seq++;
        renderState(box, "idle");
        if (opts.onClear) { try { opts.onClear(); } catch (e) {} }
        return;
      }
      if (key === lastKey) return;      // نفس الرقم — مفيش نداء تاني
      lastKey = key;
      var mine = ++seq;
      renderState(box, "loading");
      lookup(key).then(function (res) {
        if (mine !== seq) return;       // رد قديم لرقم اتغيّر — بيتتجاهل
        renderState(box, "done", res);
        if (opts.onResult) { try { opts.onResult(res); } catch (e) {} }
      }).catch(function (e) {
        if (mine !== seq) return;
        lastKey = null;                 // نسمح بإعادة المحاولة
        renderState(box, "error", null, e && e.message);
      });
    }

    input.addEventListener("input", function () {
      clearTimeout(timer);
      timer = setTimeout(run, opts.delay || 400);
    });
    input.addEventListener("blur", function () {
      clearTimeout(timer);
      timer = setTimeout(run, 60);
    });
  }

  /* ═══════════════════════════════════════════════════════════
     5) تقييم المستلم
  ═══════════════════════════════════════════════════════════ */
  var RATE_LABEL = ["", "سيئ جدًا", "ضعيف", "مقبول", "كويس", "ممتاز"];

  function rateReceiver(orderId, payload) {
    return api().post("/api/orders/" + encodeURIComponent(orderId) + "/rate-receiver", payload);
  }

  /* حالة «اتقيّم» محليًا — الـ API مفيهوش قراءة تقييم المُرسِل على الأوردر،
     والمفتاح الفريد (order_id, rater_type, rater) بيمنع التكرار سيرفر-سايد.
     فبنحتفظ باللي اتبعت هنا عشان الزرار يتحوّل لعرض التقييم فورًا وبعد الريفريش. */
  function _ratedAll() {
    try { return JSON.parse(localStorage.getItem(RATED_KEY) || "{}") || {}; }
    catch (e) { return {}; }
  }
  function getRated(scope, orderId) {
    return _ratedAll()[String(scope) + ":" + String(orderId)] || null;
  }
  function markRated(scope, orderId, data) {
    var all = _ratedAll();
    all[String(scope) + ":" + String(orderId)] = Object.assign(
      { at: new Date().toISOString() }, data || {}
    );
    try { localStorage.setItem(RATED_KEY, JSON.stringify(all)); } catch (e) {}
  }

  /**
   * مودال التقييم — 5 نجوم + ملاحظة اختيارية.
   * opts: { title, subtitle, parcels:[{value,label}], onSubmit(payload) -> Promise }
   * بيقفل لوحده لما onSubmit تنجح، وبيعرض الخطأ جواه لو فشلت.
   */
  function ratingModal(opts) {
    opts = opts || {};
    var stars = 0;
    var back = document.createElement("div");
    back.style.cssText = "position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.72);" +
      "display:flex;align-items:center;justify-content:center;padding:16px;overflow:auto";
    var parcels = opts.parcels || [];
    back.innerHTML =
      '<div style="background:#15161c;border:1px solid #2a2b33;border-radius:16px;padding:20px;' +
      'max-width:400px;width:100%;color:#e8e8ef;font-family:inherit;text-align:center">' +
        '<div style="font-weight:900;font-size:1.02rem">' + esc(opts.title || "قيّم المستلم") + "</div>" +
        (opts.subtitle ? '<div style="font-size:.78rem;opacity:.7;margin-top:4px">' + esc(opts.subtitle) + "</div>" : "") +
        (parcels.length > 1
          ? '<select id="_trParcel" style="width:100%;margin-top:12px;background:#0d0e12;color:inherit;' +
            'border:1px solid #2a2b33;border-radius:10px;padding:10px;font-family:inherit;font-size:.85rem">' +
            parcels.map(function (p) {
              return '<option value="' + esc(p.value) + '">' + esc(p.label) + "</option>";
            }).join("") + "</select>"
          : "") +
        '<div id="_trStars" style="display:flex;gap:4px;justify-content:center;margin-top:14px"></div>' +
        '<div id="_trLabel" style="font-size:.8rem;color:#f5b800;font-weight:800;margin-top:6px;min-height:19px"></div>' +
        '<textarea id="_trNote" rows="2" placeholder="ملاحظة (اختياري)" style="width:100%;margin-top:10px;' +
        'background:#0d0e12;border:1px solid #2a2b33;border-radius:10px;padding:10px;color:inherit;' +
        'font-family:inherit;font-size:.85rem;outline:none;resize:none"></textarea>' +
        '<div id="_trErr" style="font-size:.75rem;color:#ef4444;margin-top:8px;min-height:16px"></div>' +
        '<div style="display:flex;gap:8px;margin-top:8px">' +
          '<button id="_trCancel" style="flex:1;background:transparent;border:1px solid #2a2b33;color:#8b93a7;' +
          'padding:11px;border-radius:10px;cursor:pointer;font-family:inherit;font-weight:700">إلغاء</button>' +
          '<button id="_trSend" disabled style="flex:2;background:#0ea5e9;border:none;color:#fff;' +
          'padding:11px;border-radius:10px;cursor:pointer;font-family:inherit;font-weight:800">إرسال التقييم</button>' +
        "</div>" +
      "</div>";
    document.body.appendChild(back);

    var starsBox = back.querySelector("#_trStars");
    var labelBox = back.querySelector("#_trLabel");
    var sendBtn  = back.querySelector("#_trSend");
    var errBox   = back.querySelector("#_trErr");

    function drawStars() {
      var html = "";
      for (var i = 1; i <= 5; i++) {
        html += '<span data-s="' + i + '" style="cursor:pointer;font-size:32px;line-height:1;color:' +
          (i <= stars ? "#f5b800" : "#3a3a44") + '">★</span>';
      }
      starsBox.innerHTML = html;
    }
    drawStars();

    starsBox.addEventListener("click", function (ev) {
      var s = ev.target && ev.target.getAttribute && ev.target.getAttribute("data-s");
      if (!s) return;
      stars = Number(s);
      drawStars();
      labelBox.textContent = RATE_LABEL[stars] || "";
      sendBtn.disabled = false;
    });

    function close() { back.remove(); }
    back.querySelector("#_trCancel").onclick = close;
    back.addEventListener("click", function (ev) { if (ev.target === back) close(); });

    sendBtn.onclick = function () {
      if (!stars) return;
      var sel = back.querySelector("#_trParcel");
      sendBtn.disabled = true;
      sendBtn.textContent = "⏳ جاري الإرسال…";
      errBox.textContent = "";
      var payload = {
        stars: stars,
        note: (back.querySelector("#_trNote").value || "").trim(),
        deliveryId: sel ? sel.value : (parcels[0] ? parcels[0].value : null)
      };
      Promise.resolve(opts.onSubmit ? opts.onSubmit(payload) : null).then(function () {
        close();
      }).catch(function (e) {
        errBox.textContent = (e && e.message) || "تعذّر إرسال التقييم";
        sendBtn.disabled = false;
        sendBtn.textContent = "إرسال التقييم";
      });
    };
  }

  /** نجوم للعرض بس (بعد ما التقييم يتبعت) */
  function starsView(n, size) {
    var out = "";
    for (var i = 1; i <= 5; i++) {
      out += '<span style="font-size:' + (size || "18px") + ';line-height:1;color:' +
        (i <= (Number(n) || 0) ? "#f5b800" : "#3a3a44") + '">★</span>';
    }
    return out;
  }

  /* ═══════════════════════════════════════════════════════════
     6) تعارض الاسم — الرقم مسجّل باسم غير اللي المستخدم كتبه
  ═══════════════════════════════════════════════════════════ */

  /**
   * ═══ الباج اللي المودال ده اتعمل عشانه (بلاغ صاحب النظام 2026-09-12) ═══
   * «لو كتبت اسم عميل جديد فوق، وتحت كتبت رقم كان مسجّل باسم قديم، بيمسح
   *  الجديد ويسجّل القديم تلقائي».
   *
   * وكان بيحصل فعلًا: السطر كان `nameEl.value = res.name` من غير أي سؤال.
   * وأثره باين في بيانات الإنتاج — أوردرات اسمها «محل سري تون» والمحفوظ
   * «محل سويت هوم»، وتصحيحات إملائية («هيبر» → «هايبر») ضاعت.
   *
   * 🔴 القاعدة دلوقتي: **اللي المستخدم كتبه مايتمسحش من غير ما يوافق**.
   *    الخانة الفاضية بتتملى لوحدها زي الأول (مفيش حاجة بتضيع)، والتعارض
   *    بس هو اللي بيفتح المودال.
   *
   * opts: { typed, stored, source, phone, canFix, onUseStored(), onKeepTyped(), onFixed() }
   *
   * `canFix` بيظهر زرار تالت بيصحّح **الاسم المحفوظ نفسه** لكل الشركة
   * (`PUT /api/trust/{phone}/identity`). السيرفر بيرفضه لو المحل
   * مااتعاملش مع الرقم، أو لو الاسم متأكّد منه موظف — والرسالة بتتعرض
   * جوه المودال زي ما هي مش toast، عشان المستخدم يفهم ليه اترفض.
   */
  var SRC_AR = {
    verified        : "اسم متأكّد منه موظف",
    customer        : "حساب عميل مسجّل",
    delivered_orders: "أوردرات اتسلّمت قبل كده",
    receiver_book   : "دفتر المستلمين",
    shop            : "حساب محل"
  };

  function askNameConflict(opts) {
    opts = opts || {};
    var typed = String(opts.typed || "").trim();
    var stored = String(opts.stored || "").trim();

    var back = document.createElement("div");
    back.style.cssText = "position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.72);" +
      "display:flex;align-items:center;justify-content:center;padding:16px;overflow:auto";
    var why = SRC_AR[opts.source] ? "<div style='font-size:.76rem;opacity:.7;margin-top:4px'>المصدر: " +
      esc(SRC_AR[opts.source]) + "</div>" : "";

    back.innerHTML =
      "<div style='background:#fff;color:#111;border-radius:16px;max-width:420px;width:100%;" +
      "padding:22px;box-shadow:0 18px 50px rgba(0,0,0,.35)'>" +
        "<div style='font-weight:800;font-size:1.05rem;margin-bottom:6px'>⚠️ الرقم ده مسجّل باسم تاني</div>" +
        "<div style='font-size:.86rem;line-height:1.7;opacity:.85'>الرقم ده عندنا باسم:</div>" +
        "<div style='background:#f1f5f9;border-radius:10px;padding:10px 13px;margin:7px 0;font-weight:700'>" +
          esc(stored) + why + "</div>" +
        "<div style='font-size:.86rem;line-height:1.7;opacity:.85'>وإنت كتبت:</div>" +
        "<div style='background:#fff7ed;border:1px solid #fdba74;border-radius:10px;padding:10px 13px;margin:7px 0 16px;font-weight:700'>" +
          esc(typed) + "</div>" +
        "<button id='_ncKeep' style='width:100%;background:#f97316;color:#fff;border:0;padding:12px;" +
          "border-radius:10px;font-weight:800;font-size:.92rem;cursor:pointer;margin-bottom:9px'>" +
          "✏️ كمّل بالاسم اللي كتبته</button>" +
        "<button id='_ncUse' style='width:100%;background:#fff;color:#111;border:1px solid #cbd5e1;" +
          "padding:12px;border-radius:10px;font-weight:700;font-size:.92rem;cursor:pointer'>" +
          "استخدم الاسم المحفوظ</button>" +
        (opts.canFix ?
          "<button id='_ncFix' style='width:100%;background:#fff;color:#0f766e;border:1px solid #5eead4;" +
            "padding:12px;border-radius:10px;font-weight:700;font-size:.92rem;cursor:pointer;margin-top:9px'>" +
            "✅ صحّح الاسم المحفوظ لـ«" + esc(typed) + "»</button>" : "") +
        "<div id='_ncErr' style='display:none;font-size:.8rem;color:#b91c1c;background:#fef2f2;" +
          "border:1px solid #fecaca;border-radius:9px;padding:9px 11px;margin-top:10px;line-height:1.6'></div>" +
        "<div style='font-size:.73rem;opacity:.6;margin-top:12px;line-height:1.6'>" +
          (opts.canFix
            ? "أول زرارين بيأثّروا على الشحنة دي بس. «صحّح الاسم المحفوظ» بيغيّره لكل الشركة."
            : "الاسم اللي هتختاره بيتسجّل على الشحنة دي. البيانات المحفوظة مابتتغيّرش من هنا.") + "</div>" +
      "</div>";

    function close() { if (back.parentNode) { back.parentNode.removeChild(back); } }
    /* 🔴 مفيش قفل بالضغط برّه ولا Esc: لازم يختار. القفل من غير اختيار
       كان هيرجّعنا لنفس السؤال — مين كسب، اللي اتكتب ولا المحفوظ؟ */
    back.querySelector("#_ncKeep").onclick = function () { close(); if (opts.onKeepTyped) { opts.onKeepTyped(); } };
    back.querySelector("#_ncUse").onclick  = function () { close(); if (opts.onUseStored) { opts.onUseStored(); } };
    var fixBtn = back.querySelector("#_ncFix");
    if (fixBtn) {
      fixBtn.onclick = function () {
        var errBox = back.querySelector("#_ncErr");
        fixBtn.disabled = true;
        fixBtn.textContent = "⏳ بنصحّح…";
        errBox.style.display = "none";
        api().put("/api/trust/" + encodeURIComponent(normPhone(opts.phone || "")) + "/identity", { name: typed })
          .then(function () {
            /* الكاش لازم يتمسح وإلا الفحص الجاي هيرجّع الاسم القديم */
            forget(opts.phone);
            close();
            if (opts.onFixed) { opts.onFixed(); }
          })
          .catch(function (e) {
            /* 🔴 الرسالة جوه المودال مش toast: الرفض هنا ليه سبب محدد
               (مااتعاملتش مع الرقم / الاسم متأكّد منه موظف) والمستخدم
               لازم يقراه وهو شايف الاختيارين التانيين. */
            errBox.textContent = (e && e.message) || "تعذّر التصحيح";
            errBox.style.display = "";
            fixBtn.disabled = false;
            fixBtn.textContent = "✅ صحّح الاسم المحفوظ لـ«" + typed + "»";
          });
      };
    }
    document.body.appendChild(back);
  }

  global.Trust = {
    normPhone: normPhone,
    lookup: lookup,
    forget: forget,
    badgeHtml: badgeHtml,
    badgeParts: badgeParts,
    shipmentsText: shipmentsText,
    resultHtml: resultHtml,
    bindPhoneField: bindPhoneField,
    askNameConflict: askNameConflict,
    rateReceiver: rateReceiver,
    ratingModal: ratingModal,
    starsView: starsView,
    getRated: getRated,
    markRated: markRated,
    RATE_LABEL: RATE_LABEL
  };
})(window);
