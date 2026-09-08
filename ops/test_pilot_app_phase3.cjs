/**
 * 📱 حارس: المرحلة 3 من علاج المراجعة (2026-09-08) — تطبيق الطيار (Flutter، ../aldahshan).
 *
 * مفيش Flutter SDK على جهاز التطوير، فالحارس نصّي: بيثبّت إن التعديلات موجودة
 * ومفيش رجوع للسلوك القديم. البناء والنشر على التليفونات خطوة صاحب النظام.
 * لو مجلد التطبيق مش موجود (السيرفر مثلًا) بيتخطّى بهدوء.
 *
 * التشغيل: node ops/test_pilot_app_phase3.cjs
 */
const fs = require('fs');
const path = require('path');
const APP = path.join(__dirname, '..', '..', 'aldahshan');
if (!fs.existsSync(path.join(APP, 'lib', 'main.dart'))) { console.log('مافيش مجلد تطبيق الطيار هنا — تخطّي'); process.exit(0); }
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };
const M = fs.readFileSync(path.join(APP, 'lib', 'main.dart'), 'utf8');
const P = fs.readFileSync(path.join(APP, 'pubspec.yaml'), 'utf8');
const X = fs.readFileSync(path.join(APP, 'android', 'app', 'src', 'main', 'AndroidManifest.xml'), 'utf8');
const count = (s, n) => s.split(n).length - 1;

console.log('══ الإصدار ══');
ok('pubspec 2.5.8+32 وkAppVersion 2.5.8', P.includes('version: 2.5.8+32') && M.includes("const String kAppVersion = '2.5.8';"));

console.log('\n══ الصوت والتنبيهات ══');
ok('🔴 المشغّل الثابت مابيتقفلش مع الشاشة الرئيسية', count(M, 'NotificationService.dispose();') === 0);
ok('🔴 تحديث معلّق بدل التخطي الصامت', M.includes('if (_loadingOrders) { _reloadPending = true; return; }') && M.includes('if (_reloadPending) {\n        _reloadPending = false;\n        if (mounted) unawaited(_loadOrders());'));
ok('🔴 إذن استثناء البطارية في المانيفست', X.includes('android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS'));
ok('نوع الخدمة location بس', X.includes('android:foregroundServiceType="location"') && !X.includes('location|dataSync'));
ok('الأذونات بتتطلب مرة كل 24 ساعة', M.includes("prefs.getInt('perm_prompt_at')") && M.includes('if (nowMs - lastAsk > 24 * 3600 * 1000) {'));
ok('فتح التطبيق مابيمسحش استعجالات المحل', !/cancelRingingNotification\(\);\s*\n\s*LocalNotif\.clearAll\(\);/.test(M));

console.log('\n══ المؤقتات والاستهلاك ══');
ok('🔴 من غير وردية: مفيش تتبّع حي والاستطلاع كل 3 دورات', M.includes("final inShift = prefs.getBool('shift_active') ?? false;") && M.includes('unawaited(LiveTrack.stop(flush: true));\n      if (_cycles % 3 != 0) return;'));
ok('🔴 ويبسوكت واحد: الخدمة بتقفل بثّها والتطبيق مفتوح', M.includes('if (appOpen) {\n      if (Realtime.instance.status.value != RealtimeStatus.off) Realtime.instance.stop();\n    } else if (_cycles >= 2) {\n      _ensureRealtime(pilotId);'));
ok('pilot/state من غير fresh في مزامنة الوردية', /getShiftRaw\(String shiftId\) async \{[\s\S]{0,400}?final st = await state\(\);/.test(M) && !/getShiftRaw\(String shiftId\) async \{[\s\S]{0,400}?state\(fresh: true\)/.test(M));
ok('طلب واحد في الرحلة للأوردرات النشطة', M.includes('return _ordersInflight ??= _fetchActiveOrders().whenComplete(() => _ordersInflight = null);'));
ok('تاب الحساب بإحصائيات السيرفر مش 1000 أوردر', M.includes("final fin = await Api.finishedOrders(period: 'year');") && !M.includes('final all = await Api.myOrders();'));
ok('السبلاش: الشبكة بالتوازي (900 مللي بدل 2000)', M.includes('final updF = checkAppUpdate();\n    Future.delayed(const Duration(milliseconds: 900)') && M.includes('final upd = await updF;'));

console.log('\n══ التوقيتات ══');
ok('ساعة السيرفر: clockOffset من serverNow وبتتحدّث من state وactive-orders', M.includes('static int clockOffsetMs = 0;') && count(M, 'syncClock(d);') === 2 && M.includes('_elapsed.value = start != null ? Svc.now().difference(start) : Duration.zero;'));
ok('عدّاد الوردية بيعيد القراءة مع تغيّر الإذن', M.includes('if (old.onBreak != widget.onBreak) _loadShiftInfo();'));

console.log('\n══ شكوى «الأزرار مابتشتغلش» (2.5.7) ══');
ok('🔴 بانر الموقع الكاذب: sinceLastOk بتعمل reload', /sinceLastOk\(\) async \{[\s\S]{0,400}?prefs\.reload\(\)/.test(M));
ok('🔴 شاشة السكون اتشالت من الشاشة الرئيسية', !M.includes('child: IdleOverlay(') && !M.includes('),  // IdleOverlay'));
ok('🔴 تنبيه علوي مابيمنعش الضغط بدل SnackBar', M.includes('void showTopToast(BuildContext context, String msg, {Color? color, int seconds = 3})') && M.includes('child: IgnorePointer(') && count(M, 'SnackBarBehavior.floating') === 0);
ok('زرار «بدء الرحلة» بيوري سبينر', /onPressed: _processing \? null : _startTripToCustomer,\s*\n\s*icon: _processing/.test(M));
ok('🔴 الجلب بعد الفعل مابيتخطّاش (تفاصيل الأوردر + تاب الطلبات)', count(M, 'if (running != null) return running.then((_) => _load(showSpinner: showSpinner));') === 2 && !M.includes('if (_fetching) return;\n    _fetching = true;\n    if (showSpinner && mounted) setState(() => _loading = true);\n    try {\n      final raw = await Api.getOrder'));
ok('«عدت للعمل» بيقول لما يفشل', M.includes("showErrorSnack(context, e, fallback: 'ما قدرناش نسجّل رجوعك للعمل — جرّب تاني')"));
ok('عدّاد الوردية والإذن بساعة السيرفر', M.includes('final end = _breakStart ?? Svc.now();') && M.includes('final extra = Svc.now().difference(start).inSeconds;') && count(M, "?? '') ?? Svc.now();") === 2);

console.log('\n══ سلامة نصية بسيطة ══');
const braces = count(M, '{') - count(M, '}');
ok('الأقواس متوازنة في main.dart', braces === 0, 'diff=' + braces);
ok('مفيش TODO-P3 متبقّي', !M.includes('TODO-P3'));

console.log('\n════════════════════════════════════════');
console.log('PILOT APP PHASE 3: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail ? 1 : 0);
