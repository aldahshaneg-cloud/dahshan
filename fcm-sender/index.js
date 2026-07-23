'use strict';

// ════════════════════════════════════════════════════════════════
//  الدهشان — سكريبت إرسال إشعارات FCM للطيارين
// ════════════════════════════════════════════════════════════════
//
// بيراقب نود "orders" في قاعدة البيانات. أول ما أوردر يتسند لطيار
// (status = "جاري التوصيل" و عنده pilotId)، بيبعت إشعار FCM لتوكن الطيار
// اللي التطبيق خزّنه في pilotFcmTokens/{pilotId}. الإشعار "data-only" +
// أولوية عالية، فبيوقظ تطبيق الطيار ويشغّل الرنين حتى لو كان مقفول.
//
// التشغيل:
//   1) نزّل مفتاح الخدمة من Firebase (اقرأ README.md) وحطه هنا باسم
//      serviceAccountKey.json
//   2) npm install
//   3) npm start   (سيبه شغّال — على كمبيوتر المكتب أو سيرفر)

const admin = require('firebase-admin');
const serviceAccount = require('./serviceAccountKey.json');

admin.initializeApp({
  credential: admin.credential.cert(serviceAccount),
  databaseURL: 'https://aldahshaneg-92f66-default-rtdb.firebaseio.com',
});

const db = admin.database();
const ASSIGNED_STATUS = 'جاري التوصيل'; // نفس الحالة اللي بيستخدمها التطبيق

/**
 * يبعت إشعار للطيار المُسند له الأوردر — لو مبعتش قبل كده لنفس الطيار.
 * idempotent: بنسجّل في fcmState/{orderId} آخر طيار اتبعتله، فما نكررش.
 */
async function notify(orderId, order) {
  if (!order) return;
  const pilotId = order.pilotId;
  const status = order.status;
  if (!pilotId || status !== ASSIGNED_STATUS) return;

  const stateRef = db.ref('fcmState/' + orderId);
  const last = (await stateRef.get()).val();
  if (last === pilotId) return; // اتبعت خلاص لنفس الطيار

  const token = (await db.ref('pilotFcmTokens/' + pilotId).get()).val();
  if (!token) {
    console.log(ts(), 'لا يوجد توكن للطيار', pilotId, '(أوردر', orderId + ')');
    return;
  }

  try {
    await admin.messaging().send({
      token: token,
      android: {
        priority: 'high', // يوقظ التطبيق حتى في وضع توفير الطاقة (doze)
      },
      data: {
        type: 'new_order',
        orderId: String(orderId),
      },
    });
    await stateRef.set(pilotId);
    console.log(ts(), '✅ اتبعت إشعار للطيار', pilotId, '— أوردر', orderId);
  } catch (e) {
    console.error(ts(), '❌ فشل الإرسال للطيار', pilotId, '—', e.message);
    // التوكن باظ/اتلغى → نشيله عشان ما نفضلش نحاول عليه
    if (e.code === 'messaging/registration-token-not-registered') {
      await db.ref('pilotFcmTokens/' + pilotId).remove();
      console.log(ts(), '🧹 اتشال توكن قديم للطيار', pilotId);
    }
  }
}

function ts() {
  return new Date().toLocaleString('en-GB');
}

// عند بدء التشغيل: بنعلّم كل الأوردرات المُسندة حاليًا كـ"اتبعتلها" من غير
// ما نبعت — عشان ما نغرقش الطيارين بإشعارات لأوردرات قديمة أول ما نشغّل.
async function seedThenListen() {
  const snap = await db.ref('orders').get();
  const all = snap.val() || {};
  const updates = {};
  let seeded = 0;
  for (const id of Object.keys(all)) {
    const o = all[id];
    if (o && o.pilotId && o.status === ASSIGNED_STATUS) {
      updates['fcmState/' + id] = o.pilotId;
      seeded++;
    }
  }
  if (seeded) await db.ref().update(updates);
  console.log(ts(), 'تم تعليم', seeded, 'أوردر مُسند حاليًا (بدون إرسال)');

  const ordersRef = db.ref('orders');
  // أوردر جديد اتضاف وهو مُسند من الأول
  ordersRef.on('child_added', (s) => notify(s.key, s.val()));
  // أوردر موجود اتغيّر (غالبًا: اتسند لطيار دلوقتي)
  ordersRef.on('child_changed', (s) => notify(s.key, s.val()));

  console.log(ts(), '🚀 السكريبت شغّال وبيراقب الأوردرات... (سيبه مفتوح)');
}

seedThenListen().catch((e) => {
  console.error('فشل بدء التشغيل:', e);
  process.exit(1);
});
