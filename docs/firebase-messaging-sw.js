/* ══════════════════════════════════════════════════════════════
   Firebase Cloud Messaging — استقبال إشعارات الطلبات والتطبيق مقفول
   لازم يفضل في جذر الاستضافة باسمه ده بالظبط (بيدوّر عليه FCM تلقائيًا).
══════════════════════════════════════════════════════════════ */
importScripts("https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js");
importScripts("https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js");

firebase.initializeApp({
  apiKey: "AIzaSyAqDGiMVtZ6PQH522ndXEYCBM37WKcI-ws",
  authDomain: "aldahshaneg-92f66.firebaseapp.com",
  databaseURL: "https://aldahshaneg-92f66-default-rtdb.firebaseio.com",
  projectId: "aldahshaneg-92f66",
  storageBucket: "aldahshaneg-92f66.firebasestorage.app",
  messagingSenderId: "164254513529",
  appId: "1:164254513529:web:8ec9bf526f2b2669d397a8"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(payload => {
  const d = payload.data || {};
  self.registration.showNotification(d.title || "الدهشان", {
    body: d.body || "",
    icon: "/assets/icon-192.png",
    badge: "/assets/icon-192.png",
    dir: "rtl", lang: "ar",
    tag: d.orderId || "dahshan",
    data: { orderId: d.orderId || "" }
  });
});

self.addEventListener("notificationclick", e => {
  e.notification.close();
  const target = "/tiar_customer.html" + (e.notification.data?.orderId ? "#order-" + e.notification.data.orderId : "");
  e.waitUntil(clients.matchAll({ type: "window", includeUncontrolled: true }).then(list => {
    for (const c of list) if (c.url.includes("tiar_customer.html") && "focus" in c) return c.focus();
    return clients.openWindow(target);
  }));
});
