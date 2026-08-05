/* ══════════════════════════════════════════════════════════════
   تثبيت التطبيق على الموبايل — مشترك بين تطبيق العملاء وبوابة المحلات
   ──────────────────────────────────────────────────────────────
   المتصفح مابيسمحش لأي موقع إنه «ينزّل» تطبيق بالغصب. كروم بيدّي
   نافذة التثبيت لما يقرّر هو (بيستنى المستخدم يتفاعل مع الصفحة)،
   وسفاري على الآيفون مابيدّيهاش خالص.

   عشان كده بدل ما نستنى ونسيب المستخدم مش فاهم إيه اللي حصل، بنفتح
   شاشة فيها الخطوات بالظبط لجهازه، وزرار «ثبّت الآن» بيشتغل لحظة ما
   المتصفح يسمح — من غير ما المستخدم يعمل حاجة تانية.
══════════════════════════════════════════════════════════════ */
(function () {
  var deferred = null;
  var sheetOpen = false;

  function installed() {
    return matchMedia("(display-mode: standalone)").matches || navigator.standalone === true;
  }

  window.addEventListener("beforeinstallprompt", function (e) {
    e.preventDefault();
    deferred = e;
    // لو الشاشة مفتوحة، نفعّل الزرار في نفس اللحظة
    var b = document.getElementById("_instGo");
    if (b) {
      b.style.display = "";
      b.textContent = "📲 ثبّت الآن";
    }
    var w = document.getElementById("_instWait");
    if (w) w.style.display = "none";
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

  function platform() {
    var ua = navigator.userAgent || "";
    if (/iPad|iPhone|iPod/.test(ua) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1))
      return "ios";
    if (/Android/.test(ua)) return "android";
    return "desktop";
  }

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

  window.dahshanInstall = async function (auto) {
    if (installed()) {
      if (!auto) alertOk("التطبيق متثبّت عندك بالفعل ✅");
      return;
    }
    // لو المتصفح سامح بالتثبيت المباشر، نفتح نافذته على طول
    if (deferred) {
      deferred.prompt();
      var res = await deferred.userChoice;
      deferred = null;
      if (res && res.outcome === "accepted") return;   // appinstalled هيتكفّل بالباقي
      // رفض — نوريه الخطوات لو حب يعملها بنفسه
    }
    if (sheetOpen) return;
    openSheet();
  };

  function openSheet() {
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

        '<div id="_instWait" style="margin-top:16px;background:rgba(255,255,255,.05);' +
          'border:1px dashed #3a3b45;border-radius:13px;padding:12px;font-size:.8rem;color:#8b93a7;text-align:center">' +
          'المتصفح مابيسمحش بالتثبيت بضغطة واحدة هنا — اعمل الخطوات دي وهي بسيطة 👇</div>' +

        '<div style="margin-top:18px;font-weight:800;font-size:.86rem;color:#e8192c">' + TITLE[p] + '</div>' +
        '<ol style="margin:10px 0 0;padding-inline-start:20px;line-height:2.1;font-size:.87rem">' +
          STEPS[p].map(function (s) { return "<li>" + s + "</li>"; }).join("") +
        '</ol>' +

        '<button onclick="dahshanCloseInstall()" style="width:100%;margin-top:20px;background:transparent;' +
          'color:#8b93a7;border:1px solid #2a2b33;padding:12px;border-radius:13px;cursor:pointer;' +
          'font-family:inherit;font-size:.87rem">تمام، فهمت</button>' +
      '</div>';
    document.body.appendChild(box);
    box.addEventListener("click", function (e) { if (e.target === box) closeSheet(); });

    // لو المتصفح سمح بالتثبيت وإحنا فاتحين الشاشة، نظهر الزرار
    var go = document.getElementById("_instGo");
    go.onclick = function () { window.dahshanInstall(false); };
    if (deferred) { go.style.display = ""; document.getElementById("_instWait").style.display = "none"; }
  }

  /* ?install=1 جاي من زرار «تحميل» في الموقع العام.
     بنستنى شوية للمتصفح يقرّر، وبعدين نفتح الشاشة على أي حال. */
  document.addEventListener("DOMContentLoaded", function () {
    var wants = new URLSearchParams(location.search).get("install") === "1";
    if (!wants || installed()) return;
    setTimeout(function () { window.dahshanInstall(true); }, 1200);
  });
})();
