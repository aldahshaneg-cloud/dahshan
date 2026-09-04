/* ══════════════════════════════════════════════════════════════
   تثبيت التطبيق على الموبايل — مشترك بين تطبيق العملاء وبوابة المحلات
   ──────────────────────────────────────────────────────────────
   الهدف (قرار صاحب النظام 2026-09-01): الضغط على «تحميل التطبيق» في
   الموقع يوصّل **لمربّع التثبيت الحقيقي** بتاع المتصفح — مش صفحة تعليمات.

   ═══ حدود المنصّة، عشان التوقّعات تبقى صح ═══
   مفيش متصفح بيسمح لموقع إنه «ينزّل» تطبيق بالغصب. أقصى حاجة متاحة هي
   حدث `beforeinstallprompt`: كروم بيبعته لما يتأكد إن الصفحة قابلة
   للتثبيت (manifest + service worker + أيقونات)، وساعتها بنقدر نفتح
   مربّع التثبيت الأصلي بتاع أندرويد. سفاري على الآيفون **مابيبعتوش
   خالص** — هناك التعليمات هي الطريق الوحيد.

   ═══ الباج اللي كان بيخلي الكل يشوف تعليمات ═══
   النسخة القديمة كانت بتستنى **١٢٠٠ مللي ثابتة** وبعدين تفتح الشاشة.
   كروم بيحتاج وقت أطول من كده على نت الموبايل (بيجيب الـmanifest
   والأيقونات الأول)، فالشاشة كانت بتفتح على «المتصفح مابيسمحش بالتثبيت»
   قبل ما الحدث يوصل أصلًا. وكمان `prompt()` من مؤقّت — من غير ضغطة
   مستخدم — كروم بيرفضه، والاستثواء كان بيبلع باقي الدالة فمايحصلش حاجة.

   الحل: الانتظار **مربوط بالحدث** مش بمؤقّت، وأي فشل في `prompt()`
   بيتمسك ويتحوّل لزرار ضغطة واحدة. التعليمات آخر حاجة، ولمن يستحقها بس.
══════════════════════════════════════════════════════════════ */
(function () {
  var deferred = null;      // حدث beforeinstallprompt المحجوز
  var sheetOpen = false;
  var promptBusy = false;   // نداءين متزامنين على prompt() بيرموا استثناء
  var waiters = [];         // اللي مستنيين الحدث يوصل

  var swError = "";   // بيتعرض في سطر التشخيص — الأخطاء مكانت بتتبلع

  /* ── 🔴 تسجيل الـservice worker **بدري** ───────────────────────
     الصفحتين كانوا بيسجّلوه على `window.load` — يعني **بعد ما كل
     الصفحة وصورها وخطوطها تحمّل**. صفحة العميل ٢٧٨ كيلوبايت، وعلى 4G
     الـ`load` ممكن ياخد ١٠ ثواني. وكروم **مابيفكرش في التثبيت قبل ما
     الـservice worker يتسجّل**، فالحدث كان بييجي بعد ما مهلة الانتظار
     تكون خلصت والشاشة وقعت على التعليمات.

     الملف ده `defer` يعني بيشتغل قبل `DOMContentLoaded` — أبدر بكتير.
     والتسجيل المكرر لنفس السكربت والنطاق آمن (المتصفح بيتجاهله).

     و`.catch` القديم كان `() => {}` — بيبلع أي فشل في صمت. دلوقتي
     بنسجّل السبب عشان يبان في التشخيص. */
  if ("serviceWorker" in navigator && location.protocol !== "file:") {
    try {
      navigator.serviceWorker.register("app-sw.js").catch(function (e) {
        swError = String((e && e.message) || e).slice(0, 40);
      });
    } catch (e) { swError = "throw"; }
  }

  /* ── تحديث ذاتي ────────────────────────────────────────────────
     التطبيقات دي PWA، والـ service worker ممكن يفضل مخدّم نسخة قديمة
     محفوظة حتى بعد ما نرفع تعديل. هنا بنجبره يفحص التحديث كل مرة،
     ولو نسخة جديدة استلمت التحكم بنعمل reload مرة واحدة بس. */
  if ("serviceWorker" in navigator) {
    var hadController = !!navigator.serviceWorker.controller;
    var reloaded = false;
    navigator.serviceWorker.getRegistrations()
      .then(function (rs) { rs.forEach(function (r) { try { r.update(); } catch (e) {} }); })
      .catch(function () {});
    navigator.serviceWorker.addEventListener("controllerchange", function () {
      if (!hadController || reloaded) return;   // أول تسجيل مش تحديث
      reloaded = true;
      location.reload();
    });
  }

  function installed() {
    return matchMedia("(display-mode: standalone)").matches || navigator.standalone === true;
  }

  function platform() {
    var ua = navigator.userAgent || "";
    if (/iPad|iPhone|iPod/.test(ua) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1))
      return "ios";
    if (/Android/.test(ua)) return "android";
    return "desktop";
  }

  /* 🔴 المتصفح الداخلي بتاع التطبيقات (واتساب · فيسبوك · إنستجرام · تيك
     توك) **عمره ما بيسمح بالتثبيت** ومابيبعتش الحدث خالص. والطيار أو
     العميل غالبًا بيوصله لينك الموقع على واتساب فيفتحه من جوّه — وساعتها
     مهما عملنا مش هيتثبت. الحل الوحيد إننا نقوله يفتحها في كروم. */
  function inAppBrowser() {
    var ua = navigator.userAgent || "";
    if (/FBAN|FBAV|FB_IAB|Instagram|Line\/|Twitter|TikTok|Snapchat/i.test(ua)) return true;
    // WhatsApp وأغلب الـWebViews على أندرويد بتحط "; wv" في الـUA
    if (/Android/.test(ua) && /; wv\)/.test(ua)) return true;
    return false;
  }

  /* أسماء تطبيقات الدهشان حسب ملف الـmanifest — عشان لما نلاقي تطبيق
     تاني حاجز التثبيت نقول للمستخدم **بالاسم** إيه اللي يمسحه. */
  var APP_NAMES = {
    "customer-manifest.json": "تطبيق العملاء",
    "store-manifest.json": "بوابة المحلات",
    "customers-manifest.json": "إدارة العملاء (قديم)",
    "stores-manifest.json": "إدارة المحلات (قديم)",
    "tiar-manifest.json": "لوحة الإدارة",
    "pilots-manifest.json": "لوحة الطيارين",
    "branch-manifest.json": "تطبيق الفرع",
    "callcenter-manifest.json": "الكول سنتر",
  };

  var blockers = [];   // تطبيقات دهشان تانية متثبّتة وحاجزة الحدث — للرسالة والتشخيص

  /** التطبيق متثبّت على الجهاز؟ — بيحتاج related_applications في الـmanifest.
   *
   * 🔴 الاكتشاف الكبير (2026-09-01): كل تطبيقات الدهشان الستة نطاقهم `./`
   * يعني كل واحد بيعتبر نفسه صاحب الموقع كله. وكروم **بيمنع حدث التثبيت
   * عن أي صفحة جوه نطاق تطبيق متثبّت** — فلو «إدارة العملاء» القديم متثبّت
   * من زمان، تطبيق العميل عمره ما هيعرض يتثبّت والحدث مش هييجي خالص.
   * عشان كده الـmanifest بقى بيسمّي **كل** التطبيقات في related_applications
   * — فـgetInstalledRelatedApps بيكشف أي واحد فيهم مش بس التطبيق الحالي. */
  async function alreadyInstalled() {
    if (installed()) return true;
    try {
      if (navigator.getInstalledRelatedApps) {
        var apps = await navigator.getInstalledRelatedApps();
        if (apps && apps.length) {
          var mine = (document.querySelector('link[rel="manifest"]') || {}).href || "";
          blockers = [];
          for (var i = 0; i < apps.length; i++) {
            var u = apps[i].url || apps[i].id || "";
            if (mine && u && mine.indexOf(u.split("/").pop()) !== -1) continue; // ده التطبيق نفسه
            var file = u.split("/").pop();
            blockers.push(APP_NAMES[file] || file || "تطبيق دهشان");
          }
          return true;
        }
      }
    } catch (e) {}
    return false;
  }

  /* الحدث ممكن يوصل في أي لحظة — قبل الاستدعاء أو بعده بثواني.
     بنسجّله ونصحّي أي حد مستنيه، وبنحدّث الشاشة لو مفتوحة. */
  window.addEventListener("beforeinstallprompt", function (e) {
    e.preventDefault();
    deferred = e;
    showReady();
    var w = waiters; waiters = [];
    w.forEach(function (fn) { try { fn(); } catch (err) {} });
  });

  window.addEventListener("appinstalled", function () {
    deferred = null;
    closeSheet();
    try { document.getElementById("installRow").style.display = "none"; } catch (e) {}
    try { document.getElementById("installStoreBtn").style.display = "none"; } catch (e) {}
    alertOk("تم تثبيت التطبيق ✅ — هتلاقي أيقونته على شاشة موبايلك");
  });

  function alertOk(msg) {
    if (typeof window.toast === "function") { try { return window.toast(msg, "ok"); } catch (e) {} }
    alert(msg);
  }

  /** بيستنى الحدث لمدة أقصاها ms — بيرجّع true لو وصل */
  function waitForPrompt(ms) {
    if (deferred) return Promise.resolve(true);
    /* سفاري/الآيفون عمره ما هيبعت الحدث — مفيش داعي نخلّي المستخدم
       يبصّ في شاشة انتظار لثواني على الفاضي. */
    if (platform() === "ios") return Promise.resolve(false);

    return new Promise(function (resolve) {
      var done = false;
      var t = setTimeout(function () { if (!done) { done = true; resolve(false); } }, ms);
      waiters.push(function () {
        if (done) return;
        done = true; clearTimeout(t); resolve(true);
      });
    });
  }

  /** بيفتح مربّع التثبيت الأصلي. بيرجّع "accepted" / "dismissed" / "blocked" */
  async function firePrompt() {
    if (!deferred || promptBusy) return "blocked";
    promptBusy = true;
    try {
      /* 🔴 لازم try/catch: كروم بيرمي NotAllowedError لو النداء جه من
         غير ضغطة مستخدم. من غيره الاستثناء كان بيوقف الدالة كلها
         فمكانش بيحصل لا مربّع ولا حتى تعليمات. */
      var p = deferred.prompt();
      var res = await (p && p.then ? p : deferred.userChoice);
      if (!res || !res.outcome) { res = await deferred.userChoice; }
      var out = (res && res.outcome) === "accepted" ? "accepted" : "dismissed";
      deferred = null;              // الحدث بيتستهلك مرة واحدة بس
      return out;
    } catch (e) {
      return "blocked";             // المتصفح رفض — الزرار هو البديل
    } finally {
      promptBusy = false;
    }
  }

  /* ── الشاشة: تلات حالات ──────────────────────────────────────
     انتظار (بنجهّز) → جاهز (زرار ضغطة واحدة) → تعليمات (آخر حل) */
  var STEPS = {
    ios: [
      'اضغط زر <b>المشاركة</b> <span style="font-size:1.1em">⬆️</span> تحت في سفاري',
      'انزل لتحت واختار <b>«إضافة إلى الشاشة الرئيسية»</b>',
      'اضغط <b>«إضافة»</b> — هتلاقي أيقونة التطبيق على شاشتك'
    ],
    android: [
      'اضغط زر القائمة <b>⋮</b> فوق على اليمين',
      'اختار <b>«تثبيت التطبيق»</b> أو <b>«إضافة إلى الشاشة الرئيسية»</b>',
      'اضغط <b>«تثبيت»</b> — هتلاقي أيقونة التطبيق على شاشتك'
    ],
    desktop: [
      'اضغط أيقونة التثبيت <b>⊕</b> في شريط العنوان جنب النجمة',
      'أو من قائمة المتصفح <b>⋮</b> اختار <b>«تثبيت…»</b>',
      'التطبيق هيتفتح في نافذة مستقلة'
    ]
  };
  var TITLE = { ios: "على الآيفون", android: "على الأندرويد", desktop: "على الكمبيوتر" };

  function closeSheet() {
    var el = document.getElementById("_instSheet");
    if (el) el.remove();
    sheetOpen = false;
  }
  window.dahshanCloseInstall = closeSheet;

  /** الحدث وصل — نظهر زرار الضغطة الواحدة ونخفي الانتظار والتعليمات */
  function showReady() {
    var b = document.getElementById("_instGo");
    if (b) { b.style.display = ""; b.disabled = false; b.textContent = "📲 ثبّت الآن"; }
    var w = document.getElementById("_instWait"); if (w) w.style.display = "none";
    var s = document.getElementById("_instSteps"); if (s) s.style.display = "none";
  }

  /**
   * خلاص مفيش تثبيت مباشر — بس **بنقول السبب الحقيقي** مش رسالة عامة.
   *
   * 🔴 النسخة القديمة كانت بتقول «متصفحك مابيسمحش» لكل الحالات، فالمستخدم
   * اللي فاتح من واتساب — وده أكتر حالة — كان بيقرا تعليمات كروم وهو مش
   * في كروم أصلًا، فيعملها ومايحصلش حاجة ويرجع يقول «نفس الرسالة تاني».
   */
  function showSteps(reason) {
    var w = document.getElementById("_instWait");
    var s = document.getElementById("_instSteps");
    var o = document.getElementById("_instOpen");

    if (reason === "inapp") {
      if (w) {
        w.innerHTML = "الصفحة دي مفتوحة جوّه تطبيق تاني (زي واتساب) —" +
                      " والمتصفح ده مابيسمحش بتثبيت التطبيقات.<br />" +
                      "<b>افتحها في كروم</b> والتثبيت هيشتغل بضغطة.";
        w.style.display = "";
      }
      if (o) { o.style.display = ""; }
      if (s) s.style.display = "none";        // خطوات كروم مالهاش لازمة هنا
      return;
    }

    if (reason === "installed") {
      if (w) {
        /* لو اللي متثبّت **تطبيق دهشان تاني** (نطاقهم كلهم واحد فكروم
           بيحسبه هو) — نقول بالاسم إيه اللي يتمسح بدل رسالة مضلّلة. */
        w.innerHTML = blockers.length
          ? "فيه تطبيق دهشان تاني متثبّت على موبايلك وواخد مكان التثبيت:" +
            " <b>" + blockers.join("، ") + "</b><br />" +
            "امسحه من شاشة موبايلك (ضغطة مطوّلة ← إزالة/إلغاء تثبيت)" +
            " وبعدين ارجع هنا وجرّب تاني."
          : "التطبيق <b>متثبّت عندك بالفعل</b> ✅ —" +
            " هتلاقي أيقونته على شاشة موبايلك.";
        w.style.display = "";
      }
      if (s) s.style.display = "none";
      return;
    }

    if (w) {
      w.textContent = platform() === "ios"
        ? "سفاري مابيسمحش بالتثبيت بضغطة — اعمل الخطوات دي وهي بسيطة 👇"
        : "متصفحك مابيسمحش بالتثبيت بضغطة هنا — اعمل الخطوات دي وهي بسيطة 👇";
      w.style.display = "";
    }
    if (s) s.style.display = "";
    /* 🔬 الحالة دي هي «كل الشروط مستوفاة والحدث مش بييجي» — أصعب حالة
       نشخّصها من بعيد. الصفحة بتجمع الحقائق من الجهاز نفسه عشان صاحب
       النظام يصوّرها، بدل ما نخمّن دورة ورا دورة. */
    if (platform() !== "ios") showDiag();
  }

  /** سطر تشخيص مضغوط — الحقائق اللي بتحدّد ليه كروم ساكت */
  async function showDiag() {
    var box = document.getElementById("_instDiag");
    if (!box) return;
    var d = {};
    try { d.sec = window.isSecureContext ? "1" : "0"; } catch (e) { d.sec = "?"; }
    try {
      var regs = await navigator.serviceWorker.getRegistrations();
      d.sw = String(regs.length) + (navigator.serviceWorker.controller ? "c" : "-");
    } catch (e) { d.sw = "x"; }
    var link = document.querySelector('link[rel=manifest]');
    d.man = link ? "1" : "0";
    try {
      var mj = await fetch(link.getAttribute("href"), { cache: "no-store" }).then(function (r) {
        d.mst = String(r.status); return r.json();
      });
      d.rel = mj.related_applications ? "1" : "0";
      d.ico = String((mj.icons || []).length);
    } catch (e) { d.mst = "x"; d.rel = "?"; d.ico = "?"; }
    try {
      /* بنعرض أسماء ملفات الـmanifests المتثبّتة مش العدد بس — دي اللي
         بتفضح تطبيق قديم حاجز التثبيت (النطاقات كلها متداخلة). */
      if (navigator.getInstalledRelatedApps) {
        var ia = await navigator.getInstalledRelatedApps();
        d.ins = String(ia.length);
        if (ia.length) {
          d.ins += "[" + ia.map(function (a) {
            return String(a.url || a.id || "?").split("/").pop().replace("-manifest.json", "");
          }).join(",") + "]";
        }
      } else { d.ins = "n/a"; }
    } catch (e) { d.ins = "x"; }
    d.evt = deferred ? "1" : "0";
    d.dm  = matchMedia("(display-mode: standalone)").matches ? "1" : "0";
    var ua = navigator.userAgent || "";
    var cr = (ua.match(/Chrome\/(\d+)/) || [])[1] || "?";
    var av = (ua.match(/Android (\d+)/) || [])[1] || "?";

    var line = "sec" + d.sec + " sw" + d.sw + " man" + d.man + "/" + d.mst +
               " rel" + d.rel + " ico" + d.ico + " ins" + d.ins +
               " evt" + d.evt + " dm" + d.dm + " Cr" + cr + " A" + av +
               (swError ? " ERR:" + swError : "");
    box.textContent = "🔬 " + line;
    box.style.display = "";
    box.onclick = function () {
      try { navigator.clipboard.writeText(line); alertOk("اتنسخ سطر التشخيص"); } catch (e) {}
    };
  }

  function openSheet(state) {
    if (sheetOpen) { if (state === "ready") showReady(); return; }
    sheetOpen = true;
    var p = platform();
    var box = document.createElement("div");
    box.id = "_instSheet";
    box.setAttribute("dir", "rtl");
    box.style.cssText =
      "position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.72);" +
      "display:flex;align-items:flex-end;justify-content:center;" +
      "font-family:Tajawal,system-ui,sans-serif";
    box.innerHTML =
      '<div style="background:#15161c;color:#e8e8ef;width:100%;max-width:460px;' +
        'border-radius:20px 20px 0 0;padding:22px 20px 26px;max-height:90vh;overflow:auto;' +
        'box-shadow:0 -10px 40px rgba(0,0,0,.5)">' +
        '<div style="width:42px;height:4px;background:#3a3b45;border-radius:9px;margin:0 auto 16px"></div>' +
        '<div style="display:flex;align-items:center;gap:12px;margin-bottom:6px">' +
          '<img src="assets/icon-192.png" alt="" style="width:52px;height:52px;border-radius:13px" />' +
          '<div><div style="font-weight:900;font-size:1.05rem">تثبيت تطبيق الدهشان</div>' +
          '<div style="font-size:.78rem;color:#8b93a7">أيقونة على شاشتك، ويشتغل من غير نت</div></div>' +
        '</div>' +

        '<button id="_instGo" style="display:none;width:100%;margin-top:16px;background:#e8192c;color:#fff;' +
          'border:none;padding:14px;border-radius:13px;font-weight:800;font-size:.95rem;cursor:pointer;' +
          'font-family:inherit">📲 ثبّت الآن</button>' +

        '<div id="_instWait" style="display:none;margin-top:16px;background:rgba(255,255,255,.05);' +
          'border:1px dashed #3a3b45;border-radius:13px;padding:12px;font-size:.8rem;color:#8b93a7;' +
          'text-align:center;line-height:1.9">⏳ بنجهّز التثبيت…</div>' +

        /* زرار «افتح في كروم» — بيبان بس لما نكتشف متصفح داخلي.
           `intent://` هو الطريقة الوحيدة اللي بتفتح كروم فعليًا من جوّه
           WebView على أندرويد؛ ولو فشلت بننسخ الرابط عشان يلزقه بنفسه. */
        '<button id="_instOpen" style="display:none;width:100%;margin-top:14px;background:#1a73e8;' +
          'color:#fff;border:none;padding:14px;border-radius:13px;font-weight:800;font-size:.95rem;' +
          'cursor:pointer;font-family:inherit">🌐 افتح الصفحة في كروم</button>' +

        '<div id="_instSteps" style="display:none">' +
          '<div style="margin-top:18px;font-weight:800;font-size:.86rem;color:#e8192c">' + TITLE[p] + '</div>' +
          '<ol style="margin:10px 0 0;padding-inline-start:20px;line-height:2.1;font-size:.87rem">' +
            STEPS[p].map(function (s) { return "<li>" + s + "</li>"; }).join("") +
          '</ol>' +
        '</div>' +

        '<div id="_instDiag" style="display:none;margin-top:14px;padding:9px 10px;' +
          'background:rgba(255,255,255,.04);border:1px solid #2a2b33;border-radius:9px;' +
          'font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.66rem;color:#6b7280;' +
          'direction:ltr;text-align:left;word-break:break-all;cursor:pointer"></div>' +

        '<button onclick="dahshanCloseInstall()" style="width:100%;margin-top:20px;background:transparent;' +
          'color:#8b93a7;border:1px solid #2a2b33;padding:12px;border-radius:13px;cursor:pointer;' +
          'font-family:inherit;font-size:.87rem">تمام، فهمت</button>' +
      '</div>';
    document.body.appendChild(box);
    box.addEventListener("click", function (e) { if (e.target === box) closeSheet(); });

    /* الضغطة دي **ضغطة مستخدم حقيقية** — وده اللي كروم عايزه، فالمربّع
       الأصلي بيتفتح منها حتى لو المحاولة التلقائية اترفضت. */
    document.getElementById("_instGo").onclick = async function () {
      var out = await firePrompt();
      /* 🔴 بنقفل بنفسنا عند القبول كمان — حدث appinstalled مش مضمون يجي
         في كل المتصفحات، وسيبان الشاشة مفتوحة فوق التطبيق بعد ما المستخدم
         وافق بيبان كأن حاجة علّقت. */
      if (out === "accepted") { closeSheet(); return; }
      if (out === "blocked") { showSteps(); return; }
      closeSheet();                            // رفض المستخدم — نقفل بأدب
    };

    /* فتح كروم من جوّه WebView: `intent://` بيشتغل على أندرويد، ولو
       الجهاز رفضه بننسخ الرابط للحافظة عشان المستخدم يلزقه بنفسه. */
    document.getElementById("_instOpen").onclick = function () {
      var clean = location.origin + location.pathname + "?install=1";
      var host  = clean.replace(/^https?:\/\//, "");
      try {
        location.href = "intent://" + host +
          "#Intent;scheme=https;package=com.android.chrome;end";
      } catch (e) {}
      setTimeout(function () {
        try {
          navigator.clipboard.writeText(clean);
          alertOk("اتنسخ الرابط — افتح كروم والزقه في شريط العنوان");
        } catch (e) {}
      }, 1200);
    };

    if (state === "ready" || deferred) showReady();
    else if (state === "inapp" || state === "installed" || state === "steps") showSteps(state);
    else { var w = document.getElementById("_instWait"); if (w) w.style.display = ""; }
  }

  /**
   * التثبيت. `auto = true` معناها إن النداء جاي من الموقع (?install=1)
   * مش من ضغطة المستخدم على زرار جوه التطبيق.
   */
  window.dahshanInstall = async function (auto) {
    if (installed()) {
      if (!auto) alertOk("التطبيق متثبّت عندك بالفعل ✅");
      return;
    }

    /* ⓪ التشخيص الأول — الحالتين دول بيمنعوا الحدث خالص، والانتظار
       فيهم ٨ ثواني على الفاضي بعديها رسالة عامة مالهاش معنى:
         • متصفح داخلي (واتساب/فيسبوك) — مافيش تثبيت أصلًا، لازم كروم
         • متثبّت بالفعل — كروم مابيعرضش التثبيت تاني
       الاتنين دول أكتر سببين للشكوى «بيديني نفس رسالة التعليمات». */
    if (!deferred && inAppBrowser()) { openSheet("inapp"); return; }
    if (!deferred && await alreadyInstalled()) {
      /* لو اللي متثبّت تطبيق دهشان **تاني** (blockers) الرسالة القصيرة
         «متثبّت بالفعل» بتبقى كذبة — نفتح الشاشة اللي بتقول بالاسم
         إيه اللي حاجز التثبيت وإزاي يتشال. */
      if (auto || blockers.length) { openSheet("installed"); }
      else { alertOk("التطبيق متثبّت عندك بالفعل ✅"); }
      return;
    }

    /* ① الحدث جاهز؟ نفتح المربّع الأصلي على طول. */
    if (deferred) {
      var out = await firePrompt();
      if (out === "accepted" || out === "dismissed") { closeSheet(); return; }
      openSheet("ready");                      // اترفض برمجيًا — زرار ضغطة واحدة
      return;
    }

    /* ② لسه ماوصلش: نفتح الشاشة في وضع الانتظار ونستنى الحدث **بالحدث**
       مش بمؤقّت أعمى. أول ما يوصل بنجرّب المربّع تلقائيًا، ولو المتصفح
       رفض بيفضل زرار الضغطة الواحدة ظاهر. */
    /* المهلة ٢٠ ثانية للنداء التلقائي: كروم مابيقيّمش التثبيت قبل ما
       الـservice worker يتسجّل ويشتغل، وده بياخد وقت حقيقي على 4G.
       الانتظار مش مكلّف — الشاشة بتقول «بنجهّز» والحدث بيقطعه أول ما
       يوصل. الثمانية ثواني القديمة كانت بتخلص قبل ما كروم يقرّر. */
    openSheet("waiting");
    var came = await waitForPrompt(auto ? 20000 : 8000);
    /* المهلة خلصت والحدث ماجاش. قبل ما نرمي تعليمات عامة، نفحص تاني —
       `getInstalledRelatedApps` أحيانًا بتتأخر عن أول نداء، وده أكتر سبب
       حقيقي لسكوت كروم على جهاز مستوفي كل الشروط. */
    if (!came) {
      showSteps(await alreadyInstalled() ? "installed" : null);
      return;
    }

    var res = await firePrompt();
    if (res === "accepted" || res === "dismissed") { closeSheet(); return; }
    showReady();                               // اترفض برمجيًا — الزرار جاهز
  };

  /* ?install=1 جاي من زرار «تحميل التطبيق» في الموقع العام.
     بنبدأ فورًا — الانتظار جوه `dahshanInstall` وهو مربوط بالحدث. */
  document.addEventListener("DOMContentLoaded", function () {
    var wants = new URLSearchParams(location.search).get("install") === "1";
    if (!wants || installed()) return;
    window.dahshanInstall(true);
  });
})();
