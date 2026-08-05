/* ══════════════════════════════════════════════════════════════
   Service Worker مشترك — تطبيق العملاء + بوابة المحلات
   ──────────────────────────────────────────────────────────────
   مهم: التطبيقين على نفس المجلد، والمتصفح بيسمح بـ service worker
   واحد بس لكل نطاق (scope). أول ما كان كل تطبيق يسجّل ملفه الخاص،
   الجديد كان بيمسح القديم — فالتطبيق التاني يفضل من غير SW ومايتثبتش.
   عشان كده بقى ملف واحد بيخدم الاتنين.

   وبرضه: نفس المجلد فيه لوحات الإدارة والفرع والكول سنتر، فالـ SW ده
   بيتعامل *فقط* مع ملفات التطبيقين ومكتباتهم. أي طلب تاني بيعدّي
   للشبكة زي ما هو من غير ما نلمسه (مفيش respondWith).
══════════════════════════════════════════════════════════════ */
const CACHE = "dahshan-apps-v2";

/* الملفات اللي التطبيقين مش هيشتغلوا من غيرها */
const SHELL = [
  "./tiar_customer.html",
  "./tiar_store.html",
  "./customer-manifest.json",
  "./store-manifest.json",
  "./assets/logo.png",
  "./assets/icon-192.png",
  "./assets/icon-512.png"
];

/* مكتبات خارجية بنكاشها عشان الفتح يبقى سريع ويشتغل offline */
const CDN = [
  "fonts.googleapis.com", "fonts.gstatic.com",
  "unpkg.com/leaflet", "cdnjs.cloudflare.com/ajax/libs/qrcodejs"
];

const OURS = ["/tiar_customer.html", "/tiar_store.html",
              "/customer-manifest.json", "/store-manifest.json"];

const isOurs = url =>
  OURS.some(p => url.pathname.endsWith(p)) ||
  url.pathname.includes("/assets/") ||
  CDN.some(c => (url.host + url.pathname).includes(c));

self.addEventListener("install", e => {
  // addAll بتفشل كلها لو ملف واحد وقع — بنكاش كل ملف لوحده عشان
  // ملف ناقص مايمنعش الـ SW من التنصيب ويخلّي التثبيت مايشتغلش
  e.waitUntil(
    caches.open(CACHE)
      .then(c => Promise.all(SHELL.map(u => c.add(u).catch(() => {}))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", e => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET") return;
  // Firebase و Cloudinary لازم يروحوا للشبكة دايمًا — مفيش كاش لبيانات حيّة
  if (/firebaseio|googleapis\.com\/identitytoolkit|cloudinary|gstatic\.com\/firebasejs/.test(url.href)) return;
  if (!isOurs(url)) return;

  // Network-first: أحدث نسخة لو فيه نت، والكاش وقت انقطاعه
  e.respondWith(
    fetch(e.request)
      .then(res => {
        if (res && res.status === 200)
          caches.open(CACHE).then(c => c.put(e.request, res.clone()));
        return res;
      })
      .catch(() => caches.match(e.request).then(r => r || caches.match("./tiar_customer.html")))
  );
});
