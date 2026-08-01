/* ══════════════════════════════════════════════════════════════
   Cloud Functions — إشعارات تطبيق عملاء الدهشان
   بتراقب أي تغيير في orders/{id} وتبعت Push للعميل صاحب الطلب.

   ليه الدالة دي لازمة؟ لأن التطبيق مش بيفضل مفتوح، فمحتاجين حاجة
   على السيرفر تبعت الإشعار. الشرط الوحيد إن العميل يكون مفعّل
   الإشعارات (fcmToken متخزّن في customers/{uid}/fcmToken).

   النشر:
     cd functions && npm install
     firebase deploy --only functions
══════════════════════════════════════════════════════════════ */
const functions = require("firebase-functions/v1");
const admin = require("firebase-admin");

admin.initializeApp();

/* الأحداث اللي تستاهل إشعار — المفتاح هو الحقل اللي بيتكتب في الأوردر */
const EVENTS = [
  { field: "pilotId",       title: "تم إسناد طلبك لطيار", body: o => `طلبك #${o.orderNum} في طريقه للتحميل` },
  { field: "receivedAt",    title: "الطيار استلم شحنتك",  body: o => `شحنة طلب #${o.orderNum} مع الطيار دلوقتي` },
  { field: "tripStartedAt", title: "الطيار في الطريق",     body: o => `طلبك #${o.orderNum} خرج للتوصيل` },
  { field: "deliveredAt",   title: "تم التسليم بنجاح",     body: o => `تم تسليم طلب #${o.orderNum} ✅` },
  { field: "undeliveredAt", title: "لم يتم التوصيل",       body: o => `طلب #${o.orderNum}: ${o.undeliveredReason || "تعذّر التسليم"}` }
];

exports.notifyCustomerOnOrderChange = functions
  .database.ref("/orders/{orderId}")
  .onUpdate(async (change, context) => {
    const before = change.before.val() || {};
    const after  = change.after.val()  || {};

    // طلبات تطبيق العميل بس
    const uid = after.customerId;
    if (!uid) return null;

    // نبعت مرة واحدة بس لكل حدث — أول ما الحقل يتكتب
    const fired = EVENTS.filter(e => !before[e.field] && after[e.field]);
    if (after.status === "ملغي" && before.status !== "ملغي")
      fired.push({ title: "تم إلغاء الطلب", body: o => `تم إلغاء طلب #${o.orderNum}` });
    if (!fired.length) return null;

    const tokenSnap = await admin.database().ref(`customers/${uid}/fcmToken`).once("value");
    const token = tokenSnap.val();
    if (!token) return null;

    for (const ev of fired) {
      const message = {
        token,
        // data-only عشان الـ service worker هو اللي يتحكّم في شكل الإشعار
        data: {
          title: ev.title,
          body: ev.body(after),
          orderId: context.params.orderId,
          orderNum: String(after.orderNum || "")
        },
        android: { priority: "high" },
        webpush: { headers: { Urgency: "high" }, fcmOptions: { link: "/tiar_customer.html" } }
      };
      try {
        await admin.messaging().send(message);
      } catch (err) {
        // التوكن باظ أو اتلغى — نمسحه عشان ما نفضلش نحاول عليه
        const code = err && err.errorInfo && err.errorInfo.code;
        if (code === "messaging/registration-token-not-registered" ||
            code === "messaging/invalid-argument") {
          await admin.database().ref(`customers/${uid}/fcmToken`).remove();
        }
        console.error("FCM send failed:", code || err);
      }
    }
    return null;
  });
