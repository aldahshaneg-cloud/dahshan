/**
 * api.js — الطبقة الموحدة لكلام الواجهات مع REST API (بديل SDK فايربيز)
 *
 * بتوفر:
 *   API.get / API.post / API.put / API.del  — fetch بجلسة كوكي same-origin
 *                                             وأخطاء عربية موحّدة (Error.message جاهزة للعرض)
 *   API.upload(file)                        — رفع صورة لـ POST /api/upload وبيرجع {url}
 *   API.login / API.logout / API.me         — الجلسة
 *   API.Poller                              — استطلاع endpoint بـ ?since=<server_ms>
 *                                             (دستور الـ API بند 5) مع إيقاف تلقائي
 *                                             لما التاب يبقى مخفي، واستئناف عند الرجوع.
 *   API.pokeAll()                           — تحديث فوري لكل الـ Pollers الشغالة
 *                                             (بيتنده تلقائيًا بعد أي كتابة ناجحة).
 */
(function (global) {
  "use strict";

  var GENERIC_ERR = "حصل خطأ في الاتصال بالسيرفر — جرّب تاني";

  function buildUrl(url, params) {
    if (!params) return url;
    var qs = [];
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (v === undefined || v === null || v === "") return;
      qs.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
    });
    if (!qs.length) return url;
    return url + (url.indexOf("?") === -1 ? "?" : "&") + qs.join("&");
  }

  async function request(method, url, body, opts) {
    opts = opts || {};
    var init = {
      method: method,
      credentials: "same-origin",
      headers: {},
    };
    if (body !== undefined && body !== null && !(body instanceof FormData)) {
      init.headers["Content-Type"] = "application/json";
      init.body = JSON.stringify(body);
    } else if (body instanceof FormData) {
      init.body = body; // المتصفح بيحط boundary بنفسه
    }

    // سقف زمني اختياري (opts.timeoutMs) — من غيره لو الشبكة ماتت بصمت
    // (الموبايل نام، راوتر متوصّل من غير إنترنت، الـNAT رمى المسار) الـfetch
    // مابترفضش، بتفضل معلّقة دقايق، فأي قفل حواليها (زي _inFlight في الـPoller)
    // يفضل مقفول للأبد والبيانات واقفة من غير أي رسالة خطأ.
    var _abort = null, _abortTimer = null;
    if (opts.timeoutMs && global.AbortController) {
      _abort = new AbortController();
      init.signal = _abort.signal;
      _abortTimer = setTimeout(function () { try { _abort.abort(); } catch (_) {} }, opts.timeoutMs);
    }

    var res, data;
    try {
      res = await fetch(url, init);
    } catch (e) {
      if (_abortTimer) clearTimeout(_abortTimer);
      throw new Error("مفيش اتصال بالسيرفر — اتأكد من النت وجرّب تاني");
    }
    try {
      // المؤقّت شغّال لحد ما نقرا الجسم كمان — رد بيوصل نُصّه ويقف بيعلّق برضه
      data = await res.json();
    } catch (e) {
      throw new Error(GENERIC_ERR);
    } finally {
      if (_abortTimer) clearTimeout(_abortTimer);
    }

    if (data && data.ok === false) {
      var err = new Error(data.error || GENERIC_ERR);
      err.status = res.status;
      err.data = data;
      // جلسة منتهية → رجوع لشاشة الدخول (إلا لو النداء نفسه استعلام جلسة)
      if (res.status === 401 && !opts.quiet401 && global._onSessionExpired) {
        try { global._onSessionExpired(); } catch (_) {}
      }
      throw err;
    }
    if (!res.ok) throw new Error(GENERIC_ERR);

    // أي كتابة ناجحة → تحديث فوري لكل القوايم المستطلعة
    if (method !== "GET" && !opts.noPoke) {
      setTimeout(pokeAll, 60);
    }
    return data;
  }

  var API = {
    get: function (url, params, opts) { return request("GET", buildUrl(url, params), undefined, opts); },
    post: function (url, body, opts) { return request("POST", url, body || {}, opts); },
    put: function (url, body, opts) { return request("PUT", url, body || {}, opts); },
    del: function (url, opts) { return request("DELETE", url, undefined, opts); },

    /** رفع صورة — بيرجع رابط الصورة (الضغط client-side مسؤولية imgcompress.js زي القديم) */
    upload: async function (file) {
      var fd = new FormData();
      fd.append("file", file);
      var d = await request("POST", "/api/upload", fd);
      return d.url;
    },

    /* `app` = كود التطبيق اللي الصفحة دي بتمثّله (callcenter/branch/admin…).
       السيرفر بيرفض الدخول لو الحساب مش مصرّح له بيه — القفل الحقيقي هناك،
       وده بس بيقوله إحنا فين. الصفحات اللي مابتبعتوش بتشتغل زي الأول
       (تطبيق الطيار المنشور بينده نفس المسار من غيره). */
    login: function (username, password, app) {
      var body = { username: username, password: password };
      if (app) body.app = app;

      return request("POST", "/api/login", body, { quiet401: true });
    },
    logout: function () {
      return request("POST", "/api/logout", {}, { quiet401: true, noPoke: true });
    },
    me: function () {
      return request("GET", "/api/me", undefined, { quiet401: true });
    },
  };

  /* ── Poller ─────────────────────────────────────────────────────────
     بيستطلع endpoint قوايم بيدعم ?since=<server_ms> ويستدعي onChange
     عند التغيير. لو الرد {changed:false} مفيش نداء للـ callback.
     بيقف تلقائيًا والتاب مخفي (visibilitychange) ويرجع فورًا عند الظهور. */
  var _pollers = [];

  function Poller(path, options) {
    options = options || {};
    this.path = path;
    this.params = options.params || {};
    this.interval = options.interval || 5000;
    this.onChange = options.onChange || function () {};
    this.onError = options.onError || function (e) { console.warn("poll " + path + ":", e.message || e); };
    this.useSince = options.useSince !== false;
    // اللوحة تقدر تضيّق السقف الزمني لنداء خفيف بتعرفه (options.timeoutMs)
    this.timeoutMs = options.timeoutMs || 0;
    /* البولر اللي رنينه مهم (أوردرات الفرع) لازم يفضل شغّال حتى والتاب
       ورا — من غيره لو الويبسوكت وقع، المشرف على تاب تاني مايسمعش أي
       حاجة خالص. الافتراضي false فباقي البولرات بتوفّر زي ما هي. */
    this.hiddenTick = !!options.hiddenTick;
    this._since = 0;
    this._timer = null;
    this._stopped = false;
    // بولر رفضته الصلاحية (403) — بطّل استطلاع ومايتحسبش في «آخر تحديث»
    this._dead = false;
    this._inFlight = false;
    this._inFlightAt = 0;
    // آخر رد وصل لهذا الـPoller بالذات — أساس حساب «آخر تحديث»
    this._lastOk = Date.now();
    _pollers.push(this);
    _autoMount();          // أول Poller بيركّب زرار التحديث
    var self = this;
    // أول نداء فوري
    this._tick = function () { self.tick(); };
    if (options.immediate !== false) this.tick();
    this._timer = setInterval(function () {
      if (document.hidden && !self.hiddenTick) return; // التاب ورا — وفّر على السيرفر
      self.tick();
    }, this.interval);
  }

  Poller.prototype.tick = async function (force) {
    // سقف زمني للطلب. الغرض منه إنقاذ الشبكة اللي ماتت بصمت (وعمرها
    // ماهتسلّم بايت) — مش قصّ تحميل بطيء لسه شغّال. عشان كده الأرضية
    // عالية: /api/orders بـ limit:1000 (customers.html و callcenter.html
    // و stores.html كلهم بيطلبوه) بيرجّع ميجا+ قبل الضغط + ثواني سيرفر،
    // وعلى خط موبايل ضعيف ممكن يعدّي 20 ثانية. بأرضية 20 كان بيتقطع كل
    // مرة، ومعندناش backoff ولا رسالة (onError الافتراضي console.warn بس)
    // فالجدول كان هيفضل فاضي للأبد بدل ما يحمّل بطيء.
    var cap = this.timeoutMs || Math.max(60000, this.interval + 10000);
    // قفل _inFlight ليه صلاحية — لو طلب اتعلّق ومااستقرّش (لا نجح ولا فشل)
    // الـfinally عمرها ما تشتغل، والاستطلاع يفضل ميت لحد reload مهما
    // المستخدم داس «تحديث» أو رجع للتاب. الحزام ده بيفكّه غصب بعد ما يبقى
    // الـabort المفروض اشتغل خلاص، فمابنقطعش على طلب لسه شغّال بشكل طبيعي.
    if (this._stopped || this._dead || (this._inFlight && Date.now() - this._inFlightAt < cap + 5000)) return;
    this._inFlight = true;
    this._inFlightAt = Date.now();
    try {
      var params = {};
      for (var k in this.params) params[k] = this.params[k];
      if (this.useSince && this._since > 0 && !force) params.since = this._since;
      var d = await API.get(this.path, params, { timeoutMs: cap });
      if (d.serverNow) this._since = d.serverNow;
      // «آخر تحديث» بيتسجّل هنا (رد وصل فعلًا) مش عند مجرد محاولة الـpoke،
      // عشان اللافتة ماتقولش «محدّث الآن» والبيانات واقفة من ساعة.
      // ولكل Poller قيمته هو — مش قيمة عامة واحدة (شوف _staleMs تحت)
      this._lastOk = Date.now();
      _paintRefreshBtn();
      if (d.changed === false) return;
      this.onChange(d);
    } catch (e) {
      // 403 مش تأخير شبكة — ده رفض صلاحية بيتكرر للأبد (دور اللوحة مش
      // في require_role بتاع المسار، أو الحساب موقوف). البولر ده _lastOk
      // بتاعه عمره ما هيتحدّث، فلو فضل متحسوب في «آخر تحديث» هيفضل
      // يكبّر رقم اللافتة بلا سقف («من 40 دقيقة») والبيانات المعروضة
      // كلها طازة — نفس الكذب اللي اللافتة اتعملت عشان تمنعه بس مقلوب.
      // فبنعلّمه ميت: نبطّل الاستطلاع العبيط ونشيله من الحساب.
      // (تغيّر الدور/فكّ الإيقاف محتاج إعادة تحميل الصفحة أصلًا.)
      if (e && e.status === 403) this._markDead();
      this.onError(e);
    } finally {
      this._inFlight = false;
    }
  };

  Poller.prototype._markDead = function () {
    if (this._dead) return;
    this._dead = true;
    if (this._timer) { clearInterval(this._timer); this._timer = null; }
    // بنسيبه في _pollers عشان نعرف نفرّق بين «مفيش بولرات» و«كلهم مرفوضين»
    _paintRefreshBtn();
  };

  Poller.prototype.stop = function () {
    this._stopped = true;
    if (this._timer) { clearInterval(this._timer); this._timer = null; }
    var i = _pollers.indexOf(this);
    if (i !== -1) _pollers.splice(i, 1);
  };

  function pokeAll() {
    // مابنلمسش عدّاد «آخر تحديث» هنا — الدوسة مش دليل إن البيانات وصلت.
    // tick بتسجّله لما الرد يوصل بجد.
    _pollers.forEach(function (p) { p.tick(); });
    _paintRefreshBtn();
  }
  API.pokeAll = pokeAll;
  API.Poller = Poller;

  /* ── زرار «تحديث» ─────────────────────────────────────────────────
     الفواصل الزمنية بقت دقيقة بدل ثواني — توفير حِمل كبير، بس معناه إن
     تغييرات الناس التانية ممكن تتأخر لحد دقيقة. الزرار ده بيدّي
     المستخدم تحكّم: يدوس فيسأل السيرفر **حالًا**، وبيعرض آخر تحديث
     عشان يعرف هو شايف بيانات قديمة قد إيه.

     مهم: تصرفات المستخدم نفسه بتنادي pokeAll بعدها فورًا، فنتيجة شغله
     بتبان على طول — التأخير بيخص اللي بيحصل من ناس تانية بس. */
  var _btnEl = null, _lblEl = null, _tickTimer = null;

  /* عمر أقدم بيانات على الشاشة. لازم يتحسب لكل Poller على حدة: بقيمة عامة
     واحدة أي Poller بينجح كان بيصفّر اللافتة للكل — يعني في customers.html
     (5 بولرات) و callcenter.html (أكتر من 20) لو بولر الأوردرات التقيل مات
     وبولر /api/zones الخفيف لسه بيرد، اللافتة تقول «محدّث الآن» والجدول
     واقف من ساعة. ده نفس الكذب اللي اللافتة اتعملت عشان تمنعه.
     بنقارن كل واحد بدورته هو + مهلة، عشان بولر الدقيقة مايتحسبش «متأخر»
     وهو ماشي في ميعاده. */
  function _staleMs() {
    var now = Date.now(), worst = 0;
    _pollers.forEach(function (p) {
      if (p._dead) return; // رفض صلاحية دايم — مش «بيانات قديمة»، شوف _markDead
      var age = now - (p._lastOk || 0);
      if (age > p.interval + 10000 && age > worst) worst = age;
    });
    return worst; // صفر = كل البولرات في ميعادها
  }

  // كل البولرات مرفوضة (حساب موقوف مثلًا) → مفيش أي بيانات بتتحدّث.
  // من غير الحالة دي كان _staleMs هيرجّع صفر واللافتة تقول «محدّث الآن».
  function _allDead() {
    return _pollers.length > 0 && _pollers.every(function (p) { return p._dead; });
  }

  function _agoText() {
    if (_allDead()) return "تعذّر التحديث — مفيش صلاحية";
    var s = Math.round(_staleMs() / 1000);
    if (s < 10) return "محدّث الآن";
    if (s < 60) return "من " + s + " ثانية";
    return "من " + Math.round(s / 60) + " دقيقة";
  }

  function _paintRefreshBtn() {
    if (_lblEl) _lblEl.textContent = _agoText();
  }

  /** بيركّب زرار تحديث عائم — بينده مرة واحدة من كل لوحة */
  API.mountRefreshButton = function (opts) {
    opts = opts || {};
    if (_btnEl) return _btnEl;
    var wrap = document.createElement("div");
    wrap.id = "api-refresh-fab";
    wrap.setAttribute("role", "status");
    wrap.style.cssText =
      /* اللوحة تقدر ترفع اللافتة بـ window.API_REFRESH_BOTTOM — تطبيق العميل
      عنده بار سفلي ثابت ٩٦px فاللافتة كانت مغطّية تبويبين. الافتراضي زي
      ما كان بالظبط، فباقي اللوحات مااتأثرتش. */
    "position:fixed;z-index:99998;bottom:" +
      (opts.bottom || global.API_REFRESH_BOTTOM || "18px") + ";" +
      (opts.side === "left" ? "left:16px;" : "right:16px;") +
      "display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;" +
      "background:rgba(20,20,24,.92);color:#f0f0f0;border:1px solid rgba(255,255,255,.16);" +
      "box-shadow:0 6px 20px rgba(0,0,0,.3);font-family:inherit;font-size:.78rem;" +
      "cursor:pointer;user-select:none;transition:opacity .2s";
    wrap.innerHTML =
      '<span id="api-refresh-ico" style="font-size:1rem;line-height:1">\u{1F504}</span>' +
      "<span>تحديث</span>" +
      '<span id="api-refresh-ago" style="opacity:.6;font-size:.72rem">محدّث الآن</span>';
    wrap.title = "اسأل السيرفر حالًا من غير انتظار الدورة";

    wrap.onclick = function () {
      var ico = document.getElementById("api-refresh-ico");
      if (ico) { ico.style.transition = "transform .6s"; ico.style.transform = "rotate(360deg)"; }
      wrap.style.opacity = ".55";
      pokeAll();
      setTimeout(function () {
        wrap.style.opacity = "1";
        if (ico) { ico.style.transition = "none"; ico.style.transform = "none"; }
      }, 600);
    };

    document.body.appendChild(wrap);
    _btnEl = wrap;
    _lblEl = document.getElementById("api-refresh-ago");
    if (_tickTimer) clearInterval(_tickTimer);
    _tickTimer = setInterval(_paintRefreshBtn, 5000);
    return wrap;
  };

  /* الزرار بيتركّب لوحده أول ما أول Poller يشتغل — كده كل لوحة بتاخده
     من غير ما نعدّل 7 ملفات. اللوحة تقدر تعطّله بـ
     window.API_NO_REFRESH_BTN = true قبل تحميل السكربت. */
  function _autoMount() {
    if (global.API_NO_REFRESH_BTN || _btnEl || !_pollers.length) return;
    if (document.body) API.mountRefreshButton();
    else document.addEventListener("DOMContentLoaded", function () { API.mountRefreshButton(); });
  }

  // رجوع التاب للواجهة → تحديث فوري بدل انتظار الدورة الجاية
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) pokeAll();
  });

  global.API = API;
})(window);
