/* نسخة مرجعية — اتشالت من public/callcenter.html بتاريخ 2026-08-30.
   كانت الكاتب الوحيد لـPOST /api/zone-requests، وكان بينده عليها
   `saveOrder` بتاعة شاشة «أوردر جديد» المستقلة اللي اتشالت.
   لإحيائها: المودال محتاج طريقة الموظف يكتب بيها منطقة مش في القايمة
   (دلوقتي الحقلين `orderSenderZone` و`dZone-N` قوايم مقفولة)، وبعدين
   ينده عليها من `window.addOrder` بعد نجاح الحفظ.
   الـAPI والراوت والجدول `cc_zone_requests` كلهم لسه موجودين. */

  async function logZoneRequest(parcel, orderNum, orderId) {
    /* POST /api/zone-requests — السيرفر نفسه بيدير عدّاد التكرار:
       نفس المنطقة لنفس الفرع وهي pending → زيادة count بدل صف جديد.
       فشل التسجيل مش بيفشّل حفظ الأوردر (الأوردر اتحفظ فعلًا). */
    try {
      const res = await api2().post("/api/zone-requests", {
        areaName: parcel.zoneName,
        branchId: parcel.branchId || null,
        lastPrice: (parcel.price === 0 || parcel.price) ? parcel.price : null,
        orderNum: orderNum || null
      }, { noPoke: true });
      const r = nrmZoneReq(res.request || {});
      const i = ZONEREQS.findIndex(x => x.id === r.id);
      if (i >= 0) ZONEREQS[i] = r; else ZONEREQS.push(r);
      if (document.querySelector("#page-cczones.active")) renderCCZones();
    } catch(e) { console.warn("zoneRequest:", e); }
  }
