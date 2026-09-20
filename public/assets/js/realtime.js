/**
 * realtime.js — طبقة البثّ الفوري المشتركة (Laravel Reverb عبر pusher-js)
 *
 * ═══ إيه ده وإيه مش ده ═══
 * ده **جرس** مش قناة بيانات. الملف ده مسؤوليته حاجة واحدة: يقول للصفحة
 * «الأوردر الفلاني اتغيّر». الصفحة هي اللي بتقرر تعمل إيه — والمفروض
 * تعمل حاجة واحدة بس: تنادي **نفس** مسار التحديث بتاع الاستطلاع
 * (`API.Poller.tick()` بـ`?since=`). أي كود هنا بيلمس صف في جدول أو
 * بيكتب في كاش الصفحة = مصدر تاني للحقيقة، ودي بالظبط الحاجة اللي
 * docs/REALTIME.md §6 بيحذّر منها.
 *
 * ═══ الاستطلاع مايتقفلش ═══
 * الملف ده **إضافة فوق** الاستطلاع مش بديل عنه. لو Reverb وقع، أو
 * pusher.min.js ما اتحمّلش، أو التفويض اترفض — كل دالة هنا بترجّع بهدوء
 * والصفحة تفضل شغّالة بالاستطلاع بالظبط زي ما كانت. مفيش استثناء بيطلع
 * من هنا لبرّه، ومفيش رسالة خطأ للمستخدم.
 *
 * ═══ المكتبة محليًا مش من CDN ═══
 * `assets/js/vendor/pusher.min.js` (نسخة npm الرسمية v8.6.0 بالبايت).
 * تحميلها من CDN كان معناه إن الصفحة تقع لو النت مقفول على الشبكة —
 * نفس اللسعة بتاعة SheetJS من cdnjs.
 *
 * ═══ الإعدادات ═══
 * الافتراضي بيتبنى من عنوان الصفحة نفسها: نفس الدومين، ومسار Pusher
 * الافتراضي `/app/{key}` (وده بالظبط اللي بروكسي nginx شايله).
 * للتطوير المحلي (Reverb على 127.0.0.1:8080 بمفتاح تاني) حطّ الإعدادات
 * **قبل** ما السكربت ده يتحمّل:
 *
 *     window.REALTIME_CONFIG = { key: "…", wsPort: 8080, forceTLS: false };
 *
 * ═══ الواجهة ═══
 *   REALTIME.available()                 — المكتبة موجودة؟
 *   REALTIME.connect()                   — اتصال واحد مشترك (idempotent)
 *   REALTIME.subscribeBranch(id, cb, cbReq, cbUrge) — قناة فرع؛ بترجّع دالة إلغاء
 *   REALTIME.unsubscribeBranch(id)
 *   REALTIME.syncBranches([ids], cb)     — يخلّي الاشتراكات = القايمة دي بالظبط
 *   REALTIME.unsubscribeAll()
 *   REALTIME.disconnect()
 *   REALTIME.state()                     — 'unavailable' | 'connecting' | 'connected' | 'disconnected'
 *   REALTIME.everConnected()             — نجح الاتصال مرة في الجلسة دي؟
 *   REALTIME.onStateChange(cb)           — cb({state, previous, reconnected})؛ بترجّع دالة فكّ
 */
(function (global) {
  "use strict";

  /* المفتاح العام لـPusher/Reverb **مش سر** — المتصفح بيبعته في عنوان
     الويبسوكت نفسه، وأي واحد فاتح الصفحة شايفه. السر (`REVERB_APP_SECRET`)
     عمره ما بيخرج من السيرفر. */
  var DEFAULT_KEY = "c0a55681a8d1be270370107c587a7a96";

  var CHANNEL_PREFIX = "private-branch.";
  var EVENT_ORDER_CHANGED = "order.changed";          // OrderChanged::broadcastAs()
  var EVENT_PILOT_REQUEST = "pilot.request.changed";  // PilotRequestChanged::broadcastAs()
  /* 🔴 الاستثناء الوحيد لقاعدة «جرس مش بيانات» فوق: الاستعجال **مالوش**
     صف في جدول الأوردرات يتقرا بالاستطلاع — هو حدث لحظي بيحصل وينتهي.
     فحمولته (المحل · رقم الأوردر · نص جاهز) بتعدّي للصفحة زي ما هي،
     ومفيش خطر مصدر تاني للحقيقة لأنها مابتكتبش في أي كاش أوردرات. */
  var EVENT_ORDER_URGED   = "order.urged";            // OrderUrged::broadcastAs()

  function _cfg() {
    var https = global.location.protocol === "https:";
    var base = {
      key: DEFAULT_KEY,
      /* نفس دومين الصفحة ونفس منفذها. مسار Pusher الافتراضي `/app/{key}`
         هو بالظبط اللي بروكسي nginx شايله (docs/REALTIME.md §7)، فمفيش
         `wsPath` ولا أي إعداد زيادة لازم. */
      wsHost: global.location.hostname,
      /* المنفذ من الصفحة نفسها لو متحدّد. `wssPort` بيفضل 443 دايمًا —
         الافتراضي القديم (80 على صفحة http) كان بيخلّي pusher-js يضرب
         محاولة `wss://…:80` فاشلة قبل ما يقع على ws، وبتظهر كخطأ أحمر في
         الكونسول من غير أي سبب حقيقي. */
      wsPort: https ? 443 : (global.location.port ? Number(global.location.port) : 80),
      wssPort: 443,
      forceTLS: https,
      /* ناقل واحد بيطابق بروتوكول الصفحة (بيتحسب تحت بعد دمج التخصيص).
         الاتنين مع بعض معناهم محاولة فاشلة مضمونة على واحد منهم كل مرة. */
      enabledTransports: null,
      authEndpoint: "/broadcasting/auth",
      /* 🔴 لازم يتبعت حتى لو فاضي. pusher-js بيرمي «Options object must
         provide a cluster» من الـconstructor لو المفتاح ده مش موجود
         خالص — حتى لو `wsHost` متحدّد صراحةً. والرمية دي **نص** مش
         Error، فبتعدّي من غير اسم ولا رسالة في أي catch عادي.
         (اتكشفت وقت التجربة على Reverb محلي: من غيره الاتصال كان
         بيفشل بصمت والصفحة تفضل على الاستطلاع للأبد.)
         النص الفاضي بيعدّي الفحص (`null == t.cluster`)، وقيمته
         مابتتقريش أصلًا لأن العناوين متحدّدة فوق. */
      cluster: "",
    };
    var over = global.REALTIME_CONFIG || {};
    for (var k in over) if (Object.prototype.hasOwnProperty.call(over, k)) base[k] = over[k];
    /* بعد الدمج عشان يمشي على `forceTLS` النهائي مش الافتراضي. */
    /* 🔴 (2026-09-21) الناقل في pusher-js اسمه "ws" **حتى مع TLS** — استراتيجية forceTLS بتبني
       `ws_loop` من ناقل "ws" وتوصّله على wss://. القيمة القديمة ["wss"] لوحدها كانت بتشيل الناقل
       الوحيد المستعمل، فالمكتبة تروح `initialized → failed` فورًا من غير أي محاولة اتصال: لوحات الويب
       **عمرها ما اتصلت بالبثّ على الإنتاج** (كل `/broadcasting/auth` في سجل أباتشي كانت من تطبيق الطيار)
       وكانت عايشة على الاستطلاع — «الأوردر بيوصل الفرع بعد نص دقيقة/دقيقة». اتأكدت في متصفح حقيقي:
       ["wss"] → failed · ["ws","wss"] → connected. */
    if (!base.enabledTransports) base.enabledTransports = base.forceTLS ? ["ws", "wss"] : ["ws"];
    return base;
  }

  var _pusher = null;
  var _channels = {};          // { branchId: { ch, handler } }
  var _state = "unavailable";
  var _everConnected = false;
  var _watchers = [];

  function _setState(next) {
    if (next === _state) return;
    var prev = _state;
    _state = next;
    var reconnected = false;
    if (next === "connected") {
      /* «رجوع الاتصال» = اتصال نجح **بعد** ما كان اشتغل وقع قبل كده.
         أول اتصال في الجلسة مش رجوع — الاستطلاع لسه محمّل كل حاجة من
         شوية، فمافيش فجوة أحداث نعوّضها. */
      reconnected = _everConnected;
      _everConnected = true;
    }
    _watchers.slice().forEach(function (cb) {
      try { cb({ state: next, previous: prev, reconnected: reconnected }); } catch (_) {}
    });
  }

  /* حالات pusher-js السبعة بتتلمّ على تلاتة — الصفحة مش محتاجة تعرف
     الفرق بين 'unavailable' و'failed'، هي عايزة تعرف: البثّ واصل ولا لأ. */
  function _mapState(s) {
    if (s === "connected") return "connected";
    if (s === "connecting" || s === "initialized") return "connecting";
    return "disconnected";   // unavailable · failed · disconnected
  }

  var RT = {
    available: function () { return typeof global.Pusher === "function"; },
    state: function () { return _state; },
    everConnected: function () { return _everConnected; },

    onStateChange: function (cb) {
      if (typeof cb !== "function") return function () {};
      _watchers.push(cb);
      return function () {
        var i = _watchers.indexOf(cb);
        if (i !== -1) _watchers.splice(i, 1);
      };
    },

    /**
     * اتصال واحد لكل صفحة. النداء التاني بيرجّع نفس الكائن.
     * بيرجّع null لو المكتبة مش موجودة أو الاتصال ما اتبناش — والصفحة
     * تفضل ماشية بالاستطلاع.
     */
    connect: function () {
      if (_pusher) return _pusher;
      if (!RT.available()) {
        /* الملف ما اتحمّلش (٤٠٤ · شبكة · حاجب إعلانات). مش خطأ للمستخدم:
           الاستطلاع هو المصدر الرسمي أصلًا. */
        _setState("unavailable");
        return null;
      }
      var c = _cfg();
      try {
        global.Pusher.logToConsole = false;
        _pusher = new global.Pusher(c.key, {
          cluster: c.cluster || "",    // إلزامي حتى وهو فاضي — الشرح في _cfg فوق
          wsHost: c.wsHost,
          wsPort: c.wsPort,
          wssPort: c.wssPort,
          forceTLS: c.forceTLS,
          /* ويبسوكت وبس. من غير التقييد ده pusher-js بيرجع لـsockjs على
             دومين Pusher السحابي لما الويبسوكت يفشل — يعني نداء **لبرّه**
             من صفحة المفروض إنها كلها same-origin.

             وإحصائيات `stats.pusher.com` مقفولة من غير ما نعمل حاجة:
             الافتراضي في الإصدار ٨ بقى `enableStats:false`، و`disableStats`
             القديمة بتطلّع تحذير إهمال في الكونسول — فمابنبعتهاش. */
          enabledTransports: c.enabledTransports,
          /* القناة خاصة → التفويض من السيرفر بكوكي الجلسة. XHR same-origin
             بيبعت الكوكي لوحده، ومجموعة `api` مافيهاش CSRF (bootstrap/app.php)
             فمفيش توكن لازم يتحط هنا. */
          authEndpoint: c.authEndpoint,
        });
      } catch (e) {
        _pusher = null;
        _setState("unavailable");
        return null;
      }

      try {
        _setState(_mapState(_pusher.connection.state));
        _pusher.connection.bind("state_change", function (st) {
          _setState(_mapState(st.current));
        });
        /* أخطاء الاتصال بتتبلع هنا عن قصد. من غير الرابط ده pusher-js
           بيرمي في الكونسول، ومفيش حاجة للمستخدم يعملها — الاستطلاع شغّال. */
        _pusher.connection.bind("error", function () {});
      } catch (_) {}

      return _pusher;
    },

    /**
     * اشتراك في قناة فرع. `cb(payload)` بيتنده على كل `order.changed`.
     * بترجّع دالة إلغاء (بترجّع دالة فاضية لو الاشتراك ما حصلش).
     */
    subscribeBranch: function (branchId, cb, cbRequests, cbUrge) {
      var id = String(branchId == null ? "" : branchId);
      if (!id) return function () {};
      if (_channels[id]) return function () { RT.unsubscribeBranch(id); };
      var p = RT.connect();
      if (!p) return function () {};
      try {
        var ch = p.subscribe(CHANNEL_PREFIX + id);
        var handler = function (payload) {
          try { if (typeof cb === "function") cb(payload || {}); } catch (_) {}
        };
        /* الاسم على السلك من `broadcastAs()` من غير نقطة بادئة. النقطة
           في `Echo.listen('.order.changed')` دي اصطلاح Echo («ماتحطّش
           namespace»)، ومالهاش لازمة مع pusher-js الخام. */
        ch.bind(EVENT_ORDER_CHANGED, handler);
        /* حدث طلبات الطيار (إذن/وردية) — كولباك منفصل عن الأوردرات عشان
           كل لوحة تركل مستطلعات الطلبات بس من غير ما تلمس مسار الأوردرات.
           المعامل اختياري: النداءات القديمة بكولباكين شغّالة زي ما هي. */
        var reqHandler = null;
        if (typeof cbRequests === "function") {
          reqHandler = function (payload) {
            try { cbRequests(payload || {}); } catch (_) {}
          };
          ch.bind(EVENT_PILOT_REQUEST, reqHandler);
        }
        /* رفض التفويض (٤٠٣ من /broadcasting/auth) — بيتسجّل بهدوء وبس.
           pusher-js مابيعيدش المحاولة في نفس الاتصال، والصفحة شغّالة
           بالاستطلاع فمفيش حاجة تتقال للمستخدم. */
        ch.bind("pusher:subscription_error", function (st) {
          console.warn("realtime: الاشتراك في " + CHANNEL_PREFIX + id + " اترفض", st && st.status);
        });
        /* استعجال من المحل — نفس نمط الكولباك الاختياري فوق: النداءات
           القديمة بكولباك أو اتنين شغّالة زي ما هي من غير أي تغيير. */
        var urgeHandler = null;
        if (typeof cbUrge === "function") {
          urgeHandler = function (payload) {
            try { cbUrge(payload || {}); } catch (_) {}
          };
          ch.bind(EVENT_ORDER_URGED, urgeHandler);
        }
        _channels[id] = { ch: ch, handler: handler, reqHandler: reqHandler, urgeHandler: urgeHandler };
        return function () { RT.unsubscribeBranch(id); };
      } catch (_) {
        return function () {};
      }
    },

    unsubscribeBranch: function (branchId) {
      var id = String(branchId == null ? "" : branchId);
      var entry = _channels[id];
      if (!entry) return;
      delete _channels[id];
      try {
        entry.ch.unbind(EVENT_ORDER_CHANGED, entry.handler);
        if (entry.reqHandler) entry.ch.unbind(EVENT_PILOT_REQUEST, entry.reqHandler);
        if (entry.urgeHandler) entry.ch.unbind(EVENT_ORDER_URGED, entry.urgeHandler);
        if (_pusher) _pusher.unsubscribe(CHANNEL_PREFIX + id);
      } catch (_) {}
    },

    /**
     * يخلّي الاشتراكات مطابقة للقايمة دي بالظبط — بيضيف الجديد ويشيل
     * اللي اتشال. بتتنده كل ما قايمة الفروع تتحدّث؛ الفروع اللي مشتركين
     * فيها أصلًا مابتتلمسش (مفيش إعادة تفويض من غير داعي).
     */
    syncBranches: function (ids, cb, cbRequests, cbUrge) {
      var want = {};
      (ids || []).forEach(function (b) {
        var id = String(b == null ? "" : b);
        if (id) want[id] = true;
      });
      Object.keys(_channels).forEach(function (id) {
        if (!want[id]) RT.unsubscribeBranch(id);
      });
      Object.keys(want).forEach(function (id) {
        if (!_channels[id]) RT.subscribeBranch(id, cb, cbRequests, cbUrge);
      });
      return Object.keys(_channels).length;
    },

    subscribedBranches: function () { return Object.keys(_channels); },

    unsubscribeAll: function () {
      Object.keys(_channels).forEach(RT.unsubscribeBranch);
    },

    disconnect: function () {
      RT.unsubscribeAll();
      if (_pusher) {
        try { _pusher.disconnect(); } catch (_) {}
        _pusher = null;
      }
      _setState("unavailable");
      /* `_everConnected` **مابيتصفّرش** — ده وصف للجلسة كلها، وعليه
         بيتبنى قرار «نوري مؤشر الانقطاع ولا لأ». */
    },

    /**
     * تجميع النداءات: بترجّع نسخة من `fn` مهما اتندهت كتير في نافذة
     * `ms` مابتتنفّذش غير **مرة واحدة** في آخرها.
     *
     * ليه لازم: الحدث إشعار مش بيانات، والرد الصح عليه نداء استطلاع
     * واحد. طيار بيقفل ٨ أوردرات ورا بعض بيولّد ٨ أحداث في ثانية — من
     * غير التجميع دي ٨ نداءات لـ/api/orders، يعني البثّ بيعمل حِمل أكتر
     * من الاستطلاع اللي المفروض يخفّفه.
     */
    /* 🔴 حافة أمامية (2026-09-21): أول حدث بينفّذ **فورًا من غير مؤقت**، واللي ييجي
       جوه نافذة التجميع بيتلمّ في نداء واحد بعدها.
       ليه: بلاغ صاحب النظام «الكول سنتر بيعمل الأوردر والفرع بنص دقيقة أو دقيقة على
       ما يوصل». الحدث كان بيوصل المتصفح في نص ثانية، بس ردّ الفعل كان
       `setTimeout(300)` — والمتصفح بيخنق مؤقتات التبويب اللي في الخلفية (المشرف واقف
       على واتساب) لمرة كل دقيقة، فالأوردر والرنّة بيتأخروا لحد 60 ثانية. رسايل
       الويبسوكت نفسها مابتتخنقش — فالتنفيذ من جوه الحدث مباشرة هو الحل. */
    coalesce: function (fn, ms) {
      var t = null, last = 0, pending = false;
      var wait = ms || 300;
      function run() {
        last = Date.now(); pending = false;
        try { fn(); } catch (e) { console.error("realtime: coalesced handler", e); }
      }
      return function () {
        if (Date.now() - last >= wait) {       // هادي بقاله فترة → نفّذ حالًا
          if (t) { clearTimeout(t); t = null; }
          run();
          return;
        }
        pending = true;                        // جوه النافذة → اتلمّ مع اللي بعده
        if (t) return;
        t = setTimeout(function () { t = null; if (pending) run(); }, wait);
      };
    },

    /**
     * إعادة المزامنة بعد انقطاع.
     *
     * الأحداث اللي حصلت والاتصال مقطوع **ضاعت** — الويبسوكت مالوش ذاكرة
     * ولا إعادة تشغيل. اللي بيرجّع الاتساق هو الاستطلاع: `API.pokeAll()`
     * بتنده كل بولر شغّال بـ`?since=` بتاعه هو (آخر `serverNow` وصله)،
     * فالسيرفر بيرجّع اللي اتغيّر من ساعة آخر رد بس مش الصفحة من الأول.
     *
     * مابتشتغلش على **أول** اتصال — الاستطلاع لسه حمّل كل حاجة من ثانية
     * عند فتح الصفحة، فمفيش فجوة نعوّضها (`reconnected` بيفرّق بينهم).
     *
     * بترجّع دالة فكّ الارتباط.
     */
    autoResync: function (onReconnect) {
      var poke = function () {
        if (typeof onReconnect === "function") { try { onReconnect(); } catch (_) {} return; }
        try {
          if (global.API && typeof global.API.pokeAll === "function") global.API.pokeAll();
        } catch (_) {}
      };

      var un = RT.onStateChange(function (e) { if (e.reconnected) poke(); });

      /* رجوع التاب من الخلفية: `api.js` بتنده `pokeAll` لوحدها على
         visibilitychange، فمابنكررش النداء هنا (نداءين متوازيين لنفس
         المسار من غير فايدة). اللي ناقص هو السوكت نفسه — التاب لو قعد
         ساعة في الخلفية على موبايل، النظام بيقفل الاتصال والـ backoff
         بتاع pusher-js ممكن يكون وصل لدقايق. بنستعجله بس. */
      var onVis = function () {
        if (document.hidden || !_pusher) return;
        try {
          var s = _pusher.connection.state;
          if (s === "unavailable" || s === "failed" || s === "disconnected") _pusher.connect();
        } catch (_) {}
      };
      document.addEventListener("visibilitychange", onVis);

      return function () {
        un();
        document.removeEventListener("visibilitychange", onVis);
      };
    },

    /**
     * مؤشر «البثّ مقطوع» — نقطة صغيرة + سطر، بتبان **بس** لما البثّ يكون
     * كان شغّال ووقع.
     *
     * مش إنذار: مفيش صوت ولا أحمر ولا نافذة. البيانات لسه بتتحدّث
     * بالاستطلاع زي الأول بالظبط؛ اللي اتغيّر إن التحديث بقى أبطأ،
     * والمستخدم من حقه يعرف ده من غير ما يتخض.
     *
     * ليه مقيّد بـ`everConnected()`: لو Reverb مش متاح من أساسه (نسخة
     * قديمة، بروكسي مش مظبوط، المكتبة ما اتحمّلتش) فالصفحة شغّالة بالظبط
     * زي ما كانت **قبل** الجولة دي — مفيش حاجة اتكسرت عشان نقول عليها.
     */
    mountIndicator: function (opts) {
      opts = opts || {};
      if (!document.body) {
        document.addEventListener("DOMContentLoaded", function () { RT.mountIndicator(opts); });
        return null;
      }
      var el = document.getElementById("realtime-indicator");
      if (el) return el;

      el = document.createElement("div");
      el.id = "realtime-indicator";
      el.setAttribute("role", "status");
      /* فوق زرار «تحديث» بتاع api.js (اللي عند bottom:18px) مش فوقه */
      el.style.cssText =
        "position:fixed;z-index:99997;bottom:" + (opts.bottom || "62px") + ";" +
        (opts.side === "left" ? "left:16px;" : "right:16px;") +
        "display:none;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;" +
        "background:rgba(20,20,24,.82);color:#e8e8e8;border:1px solid rgba(255,255,255,.14);" +
        "font-family:inherit;font-size:.7rem;line-height:1;pointer-events:none;opacity:.85;" +
        /* على شاشة موبايل ضيقة الشريط كان بياخد عرض الشاشة كله وياكل
           زرار «تحديث». سطر واحد قصير بسقف عرض — والشرح الكامل في
           `title` لمين يحب يعرف أكتر. */
        "white-space:nowrap;max-width:calc(100vw - 32px);overflow:hidden";
      el.title = "الاتصال الفوري بالسيرفر مقطوع — البيانات لسه بتتحدّث، بس كل شوية بدل فورًا";
      el.innerHTML =
        '<span style="width:7px;height:7px;border-radius:50%;background:#c9a227;flex:0 0 auto"></span>' +
        "<span>التحديث الفوري مقطوع</span>";
      document.body.appendChild(el);

      RT.onStateChange(function () {
        el.style.display = (RT.state() !== "connected" && RT.everConnected()) ? "flex" : "none";
      });
      return el;
    },
  };

  global.REALTIME = RT;
})(window);
