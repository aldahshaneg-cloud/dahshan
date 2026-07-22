import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:audioplayers/audioplayers.dart';
import 'package:vibration/vibration.dart';
import 'package:url_launcher/url_launcher.dart';
import 'dart:convert';
import 'dart:async';
import 'dart:typed_data';
import 'dart:ui' show FontFeature;
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:flutter_callkit_incoming/flutter_callkit_incoming.dart';
import 'package:flutter_callkit_incoming/entities/entities.dart';

// ════════════════════════════════════════════════════════════════
//  CONSTANTS — الألوان والثوابت
// ════════════════════════════════════════════════════════════════
const _fbUrl = 'https://aldahshaneg-92f66-default-rtdb.firebaseio.com';

/// مفتاح تنقل عام — يسمح بالانتقال لشاشة الطلب فور الضغط على "قبول"
/// في شاشة المكالمة الواردة، حتى لو التطبيق كان مقفول تمامًا وقتها
final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();

class K {
  static const bg     = Color(0xFF080808);
  static const card   = Color(0xFF121212);
  static const card2  = Color(0xFF1C1C1C);
  static const red    = Color(0xFFE8192C);
  static const white  = Color(0xFFFFFFFF);
  static const grey   = Color(0xFF6E6E80);
  static const grey2  = Color(0xFF2A2A36);
  static const border = Color(0xFF222230);
  static const green  = Color(0xFF22C55E);
  static const blue   = Color(0xFF3B82F6);
  static const orange = Color(0xFFF97316);
  static const db     = _fbUrl;
}

// ════════════════════════════════════════════════════════════════
//  SOUND SETTINGS — نغمات الإشعار
// ════════════════════════════════════════════════════════════════

/// كل نغمة متاحة في التطبيق
class SoundOption {
  final String id;       // اسم الملف بدون مسار (مثال: "bell.mp3")
  final String label;    // اسم يظهر للمستخدم بالعربي
  final String emoji;    // أيقونة تعبيرية
  const SoundOption({required this.id, required this.label, required this.emoji});
}

/// ─── قائمة النغمات — أضف ملفاتك هنا ───
const List<SoundOption> kSoundOptions = [
  SoundOption(id: 'mixkit-access-allowed-tone-2869.wav',           label: 'وصول مسموح',    emoji: '✅'),
  SoundOption(id: 'mixkit-arabian-mystery-harp-notification-2489.wav', label: 'هارب عربي', emoji: '🎵'),
  SoundOption(id: 'mixkit-bell-notification-933.wav',              label: 'جرس',           emoji: '🔔'),
  SoundOption(id: 'mixkit-bubble-pop-up-alert-notification-2357.wav', label: 'فقاعة',      emoji: '🫧'),
  SoundOption(id: 'mixkit-clear-announce-tones-2861.wav',          label: 'إعلان',         emoji: '📢'),
  SoundOption(id: 'mixkit-confirmation-tone-2867.wav',             label: 'تأكيد',         emoji: '☑️'),
  SoundOption(id: 'mixkit-correct-answer-reward-952.wav',          label: 'إجابة صحيحة',   emoji: '🏆'),
  SoundOption(id: 'mixkit-correct-answer-tone-2870.wav',           label: 'إجابة صحيحة 2', emoji: '✨'),
  SoundOption(id: 'mixkit-digital-quick-tone-2866.wav',            label: 'نغمة رقمية',    emoji: '💻'),
  SoundOption(id: 'mixkit-doorbell-single-press-333.wav',          label: 'جرس باب',       emoji: '🚪'),
  SoundOption(id: 'mixkit-doorbell-tone-2864.wav',                 label: 'جرس باب 2',     emoji: '🏠'),
  SoundOption(id: 'mixkit-double-beep-tone-alert-2868.wav',        label: 'تنبيه مزدوج',   emoji: '🔊'),
  SoundOption(id: 'mixkit-game-notification-wave-alarm-987.wav',   label: 'تنبيه لعبة',    emoji: '🎮'),
  SoundOption(id: 'mixkit-gaming-lock-2848.wav',                   label: 'قفل',           emoji: '🔒'),
  SoundOption(id: 'mixkit-happy-bells-notification-937.wav',       label: 'أجراس سعيدة',   emoji: '🎶'),
  SoundOption(id: 'mixkit-interface-option-select-2573.wav',       label: 'تحديد خيار',    emoji: '👆'),
  SoundOption(id: 'mixkit-long-pop-2358.wav',                      label: 'فرقعة',         emoji: '💥'),
  SoundOption(id: 'mixkit-musical-alert-notification-2309.wav',    label: 'تنبيه موسيقي',  emoji: '🎼'),
  SoundOption(id: 'mixkit-musical-reveal-961.wav',                 label: 'كشف موسيقي',    emoji: '🎹'),
  SoundOption(id: 'mixkit-orchestral-emergency-alarm-2974.wav',    label: 'إنذار طارئ',    emoji: '🚨'),
  SoundOption(id: 'mixkit-positive-notification-951.wav',          label: 'إشعار إيجابي',  emoji: '😊'),
  SoundOption(id: 'mixkit-sci-fi-click-900.wav',                   label: 'خيال علمي',     emoji: '🚀'),
  SoundOption(id: 'mixkit-software-interface-back-2575.wav',       label: 'رجوع',          emoji: '⬅️'),
  SoundOption(id: 'mixkit-software-interface-remove-2576.wav',     label: 'حذف',           emoji: '🗑️'),
  SoundOption(id: 'mixkit-software-interface-start-2574.wav',      label: 'بداية',         emoji: '▶️'),
  SoundOption(id: 'mixkit-success-software-tone-2865.wav',         label: 'نجاح',          emoji: '🎯'),
  SoundOption(id: 'mixkit-urgent-simple-tone-loop-2976.wav',       label: 'تنبيه عاجل',    emoji: '⚡'),
  SoundOption(id: 'mixkit-weird-alarm-loop-2977.wav',              label: 'إنذار غريب',    emoji: '🔴'),
  SoundOption(id: 'mixkit-wrong-answer-fail-notification-946.wav', label: 'خطأ',           emoji: '❌'),
];

// ════════════════════════════════════════════════════════════════
//  LOCAL NOTIFICATION SERVICE — إشعارات الستارة الحقيقية
// ════════════════════════════════════════════════════════════════
class LocalNotif {
  static final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();

  static const _channelId   = 'tiar_orders';
  static const _channelName = 'طلبات التوصيل';
  static const _channelDesc = 'إشعارات الأوردرات الجديدة';

  static Future<void> init() async {
    const android = AndroidInitializationSettings('@mipmap/ic_launcher');
    const ios     = DarwinInitializationSettings(
      requestAlertPermission: true,
      requestBadgePermission: true,
      requestSoundPermission: true,
    );
    await _plugin.initialize(
      const InitializationSettings(android: android, iOS: ios),
    );
    final androidImpl = _plugin
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
    await androidImpl?.requestNotificationsPermission();
  }

  static Future<void> showNewOrder({int count = 1}) async {
    final title = 'طيّار — ${count == 1 ? 'طلب جديد' : '$count طلبات جديدة'} 🚀';
    const body  = 'افتح التطبيق لعرض تفاصيل الطلب';

    // نجيب نفس النغمة اللي الطيار اختارها جوه التطبيق ونخليها هي صوت
    // الإشعار الحقيقي (تشتغل حتى لو التطبيق مقفول تمامًا) — بدل نغمة
    // النظام الافتراضية، عشان تبقى نفس الرنة في كل الحالات
    final soundId = await NotificationService.getSelectedSound();
    final rawSoundName = _toRawSoundName(soundId);
    // ملحوظة: أندرويد يثبّت صوت القناة (Channel) لحظة إنشائها ولا يقبل
    // تغييره بعدين، فلازم قناة منفصلة لكل نغمة عشان تبديل النغمة من الإعدادات يشتغل فعليًا
    final channelId = '${_channelId}_$rawSoundName';

    final androidDetails = AndroidNotificationDetails(
      channelId, _channelName,
      channelDescription: _channelDesc,
      importance: Importance.max,
      priority:   Priority.high,
      playSound:  true,
      sound: RawResourceAndroidNotificationSound(rawSoundName),
      enableVibration: true,
      vibrationPattern: Int64List.fromList([0, 400, 150, 400, 150, 600]),
      icon: '@mipmap/ic_launcher',
      fullScreenIntent: true,
      category: AndroidNotificationCategory.alarm,
    );
    const iosDetails = DarwinNotificationDetails(
      presentAlert: true, presentBadge: true, presentSound: true,
      interruptionLevel: InterruptionLevel.timeSensitive,
    );
    final details = NotificationDetails(android: androidDetails, iOS: iosDetails);

    // نطلق الإشعار مرتين بفارق بسيط — نفس نغمة التطبيق تتكرر وتبقى واضحة
    // ومحسوسة أكتر، وده يشتغل حتى لو التطبيق مقفول تمامًا (مش بس في الخلفية)
    await _plugin.show(1001, title, body, details);
    await Future.delayed(const Duration(milliseconds: 900));
    await _plugin.show(1002, title, body, details);
  }

  /// يحوّل اسم ملف الصوت (اللي فيه شرطات ونقطة الامتداد) لاسم صالح كموردٍ
  /// خام (raw resource) في أندرويد: حروف إنجليزية صغيرة + أرقام + شرطة سفلية فقط
  static String _toRawSoundName(String assetFileName) {
    final noExt = assetFileName.contains('.')
        ? assetFileName.substring(0, assetFileName.lastIndexOf('.'))
        : assetFileName;
    return noExt.toLowerCase().replaceAll(RegExp(r'[^a-z0-9_]'), '_');
  }

  static Future<void> clearAll() async => _plugin.cancelAll();

  // ────────────────────────────────────────────────────────────────
  //  إشعار الرنين الثابت (Ongoing) — إشعار واحد للأوردر الجديد
  // ────────────────────────────────────────────────────────────────
  // إشعار واحد بس (مش بيتكرر ولا بيتمسح بالسحب)، بترافقه نغمة loop مستمرة
  // بتتشغّل بشكل منفصل من OrderRinger — عشان كده الإشعار نفسه من غير صوت
  // ولا اهتزاز (onlyAlertOnce) حتى لو اتحدّث، فيفضل إشعار واحد ثابت بس

  static const _ringNotifId   = 2001;
  static const _ringChannelId = 'tiar_ring';

  /// يعرض/يحدّث إشعار الرنين الثابت (نفس الـ id دايمًا = إشعار واحد فقط)
  static Future<void> showRingingNotification({required int count}) async {
    final title = count <= 1 ? 'طلب توصيل جديد 🚀' : '$count طلبات توصيل جديدة 🚀';
    const body  = 'اضغط لفتح التطبيق وإيقاف التنبيه';

    final androidDetails = AndroidNotificationDetails(
      _ringChannelId, 'تنبيه الطلبات',
      channelDescription: 'رنين مستمر عند وصول طلب توصيل جديد',
      importance:      Importance.max,
      priority:        Priority.high,
      playSound:       false, // الصوت loop بيتشغّل بشكل منفصل من OrderRinger
      enableVibration: false, // الاهتزاز loop بيتشغّل بشكل منفصل من OrderRinger
      onlyAlertOnce:   true,  // ميعملش heads-up تاني لو اتحدّث → إشعار واحد ثابت
      ongoing:         true,  // ثابت — ميتمسحش بالسحب، يجبر الطيار يفتح التطبيق
      autoCancel:      false,
      // category=alarm عشان الرنين يخترق وضع "عدم الإزعاج" زي المنبّه.
      // ملحوظة: مش بنستخدم fullScreenIntent عن قصد — عايزين الإشعار يفضل
      // في الستارة ويرن، مش يفتح التطبيق تلقائيًا؛ الطيار هو اللي بينزل
      // الستارة ويفتح التطبيق بنفسه فيقف الرنين (ده اللي بيوقف النغمة)
      category:        AndroidNotificationCategory.alarm,
      icon:            '@mipmap/ic_launcher',
    );
    const iosDetails = DarwinNotificationDetails(
      presentAlert: true, presentBadge: true, presentSound: false,
      interruptionLevel: InterruptionLevel.timeSensitive,
    );
    await _plugin.show(
      _ringNotifId, title, body,
      NotificationDetails(android: androidDetails, iOS: iosDetails),
    );
  }

  /// يشيل إشعار الرنين الثابت (يشتغل من أي isolate — الإلغاء بالـ id على مستوى النظام)
  static Future<void> cancelRingingNotification() async =>
      _plugin.cancel(_ringNotifId);
}

// ════════════════════════════════════════════════════════════════
//  ORDER RINGER — تنبيه الأوردر: إشعار ثابت + رنين نغمة loop مستمر
// ════════════════════════════════════════════════════════════════
//
// بديل شاشة "المكالمة الواردة" الفل-سكرين: بيعرض إشعار ستارة واحد ثابت،
// وبيشغّل نفس نغمة الطيار المختارة بشكل loop مستمر (+ اهتزاز متكرر) لحد
// ما الطيار يفتح التطبيق فيقف الرنين. بيشتغل من الـ isolate الخلفي حتى
// والتطبيق مقفول تمامًا، وبيستخدم usage=alarm عشان يرن حتى لو الموبايل
// صامت/على اهتزاز — بالظبط زي نغمة المنبّه.
class OrderRinger {
  static final AudioPlayer _player = AudioPlayer();
  static bool _ringing        = false; // نغمة/اهتزاز شغّالين حاليًا؟
  static bool _audioConfigured = false;
  static Timer? _watchdog;             // مراقب يضمن استمرار النغمة

  /// تهيئة سياق الصوت مرة واحدة: loop + usage=alarm (يرن فوق الصامت)
  static Future<void> _ensureAudioConfig() async {
    if (_audioConfigured) return;
    _audioConfigured = true;
    await _player.setReleaseMode(ReleaseMode.loop);
    // شبكة أمان: لو النغمة خلصت لأي سبب (المفروض متخلصش مع loop) نرجّعها
    // فورًا بدل ما نستنى الدورة الجاية بعد 15 ثانية
    _player.onPlayerComplete.listen((_) async {
      if (!_ringing) return;
      try {
        final soundId = await NotificationService.getSelectedSound();
        await _player.play(AssetSource('sounds/$soundId'), volume: 1.0);
      } catch (_) {}
    });
    await _player.setAudioContext(AudioContext(
      android: const AudioContextAndroid(
        isSpeakerphoneOn: false,
        stayAwake:        true,
        contentType:      AndroidContentType.sonification,
        usageType:        AndroidUsageType.alarm,
        // none = مبنطلبش تركيز الصوت من النظام. لو طلبناه (gain) وتطبيق
        // تاني خده — مشغّل فيديو، مكالمة، واتساب — النظام بيوقف نغمتنا
        // وما تفضلش شغّالة. إحنا عايزين رنة إنذار ما تسكتش لأي سبب.
        audioFocus:       AndroidAudioFocus.none,
      ),
    ));
  }

  /// يبدأ/يكمّل الرنين المستمر. بتتنادى كل دورة طالما فيه طلب معلّق.
  ///
  /// مهم: مبنعتمدش على فلاج في الذاكرة يقول "أنا بأرن"، لأن الصوت ممكن
  /// يقف من ورانا لأسباب كتير — فشل التشغيل أول مرة، مكالمة واردة، تطبيق
  /// تاني خد تركيز الصوت، أو النظام أوقف المشغّل. الفلاج ساعتها بيفضل
  /// true والرنين ما بيرجعش أبدًا (ده كان بيخلي الرنة تشتغل مرة وتسكت مرة).
  /// فبنتحقق من حالة المشغّل الفعلية كل دورة ونرجّعه لو وقف.
  static Future<void> startRinging({required int count}) async {
    _ringing = true;
    await LocalNotif.showRingingNotification(count: count);
    await _ensurePlaying();
    _startWatchdog();
  }

  /// يشغّل النغمة لو مش شغّالة فعلاً + يضمن استمرار الاهتزاز
  static Future<void> _ensurePlaying() async {
    try {
      if (_player.state != PlayerState.playing) {
        await _ensureAudioConfig();
        final soundId = await NotificationService.getSelectedSound();
        try { await _player.stop(); } catch (_) {}
        await _player.play(AssetSource('sounds/$soundId'), volume: 1.0);
      }
    } catch (_) {
      // فشل التشغيل دلوقتي — المراقب هيحاول تاني بعد ثواني بدل ما نستسلم
    }
    try {
      if (await Vibration.hasVibrator() ?? false) {
        // repeat: 0 → يكرّر النمط من أوله باستمرار لحد ما نستدعي cancel()
        Vibration.vibrate(pattern: [0, 600, 400, 600, 400, 800], repeat: 0);
      }
    } catch (_) {}
  }

  /// مراقب الرنين: بيتأكد كل 3 ثواني إن النغمة فعلاً شغّالة ويرجّعها لو
  /// وقفت لأي سبب. من غيره كنا بنستنى دورة الخدمة (15 ثانية) عشان نكتشف
  /// إن الصوت سكت — وده اللي كان بيخلي الرنة متقطعة أو تسكت خالص.
  static void _startWatchdog() {
    if (_watchdog != null) return;
    _watchdog = Timer.periodic(const Duration(seconds: 3), (t) async {
      if (!_ringing) { t.cancel(); _watchdog = null; return; }
      await _ensurePlaying();
    });
  }

  /// يوقف الرنين بالكامل ويشيل الإشعار الثابت
  static Future<void> stopRinging() async {
    _ringing = false;
    _watchdog?.cancel();
    _watchdog = null;
    try { await _player.stop(); } catch (_) {}
    try { await Vibration.cancel(); } catch (_) {}
    await LocalNotif.cancelRingingNotification();
  }
}

// ════════════════════════════════════════════════════════════════
//  FOREGROUND TASK HANDLER — يشتغل في الخلفية بشكل مستمر
// ════════════════════════════════════════════════════════════════

/// لازم Top-Level function
@pragma('vm:entry-point')
void _startForegroundCallback() {
  FlutterForegroundTask.setTaskHandler(_OrderTaskHandler());
}

class _OrderTaskHandler extends TaskHandler {
  Set<String> _knownIds = {};
  bool _firstRun = true;

  @override
  Future<void> onStart(DateTime timestamp, TaskStarter starter) async {
    // مهم جداً: الـ isolate الخاص بخدمة الخلفية منفصل تماماً عن الـ isolate
    // الرئيسي للتطبيق، فمكتبة الإشعارات المحلية لازم تتهيأ من جديد هنا،
    // وإلا الإشعار ممكن يفشل بصمت وهو التطبيق في الخلفية أو مقفول
    await LocalNotif.init();
    // نحمّل الـ IDs المحفوظة عند البدء
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getStringList('known_order_ids') ?? [];
    _knownIds = saved.toSet();
    _firstRun = true;
  }

  @override
  Future<void> onRepeatEvent(DateTime timestamp) async {
    final prefs = await SharedPreferences.getInstance();
    // مهم: نعيد قراءة القيم من القرص عشان نشوف اللي كتبه الـ isolate الرئيسي
    // (فلاج فتح التطبيق / مسح قائمة الرنين) — SharedPreferences بتكاش نسخة
    // منفصلة لكل isolate، فمن غير reload مش هنحس بتغييرات التطبيق الرئيسي
    try { await prefs.reload(); } catch (_) {}

    final pilotId = prefs.getString('pilotId') ?? '';
    if (pilotId.isEmpty) return;

    // "التطبيق مفتوح؟" — مش كفاية فلاج، لأن الفلاج بيتحط false وقت الإغلاق
    // السليم بس. لو الطيار عمل force-close أو أندرويد قفل التطبيق، الفلاج
    // بيفضل true للأبد والخدمة تفضل ساكتة ومترنّش خالص.
    // فبنشترط كمان إن التطبيق بعت إشارة حياة حديثة (كل 8 ثواني وهو مفتوح).
    final fgFlag = prefs.getBool('app_in_foreground') ?? false;
    final fgTs   = prefs.getInt('app_fg_ts') ?? 0;
    final fresh  = DateTime.now().millisecondsSinceEpoch - fgTs < 45000;
    final appOpen = fgFlag && fresh;

    // ── إرسال الموقع في الخلفية (كل 60ث كحد أقصى، ولو الطيار اتحرّك فعلاً) ──
    // لما التطبيق مفتوح، HomeScreen هو اللي بيبعت الموقع من stream الـ GPS،
    // فمبنبعتش من هنا عشان ما نكرّرش الشغل ونستنزف البطارية
    if (!appOpen) {
      await LocationSender.maybeSend(pilotId, background: true);
    }

    // ── التطبيق مفتوح قدام الطيار؟ ──
    // لو الطيار فاتح التطبيق، مفيش داعي لأي رنين خلفي، ولا حتى جلب شجرة
    // الأوردرات كاملة من هنا (توفير بيانات) — HomeScreen بيتولّى ده بنفسه
    // ويحدّث known_order_ids باستمرار. بس نعيد ضبط الـ baseline المحلي منها
    if (appOpen) {
      await OrderRinger.stopRinging();
      _knownIds = (prefs.getStringList('known_order_ids') ?? []).toSet();
      _firstRun = false;
      return;
    }

    try {
      final res = await http
          .get(Uri.parse('$_fbUrl/orders.json${await FbAuth.queryParam()}'))
          .timeout(const Duration(seconds: 10));
      if (res.statusCode != 200) return;

      final raw = json.decode(res.body);
      if (raw == null) return;

      final currentIds = (raw as Map<String, dynamic>)
          .entries
          .where((e) =>
              e.value is Map &&
              e.value['pilotId'] == pilotId &&
              e.value['status'] == 'جاري التوصيل')
          .map((e) => e.key)
          .toSet();

      // ── قائمة الطلبات التي لسه الطيار ما فتحش التطبيق يشوفها ──
      // نفضل نرن عليها لحد ما يفتح التطبيق فعليًا (اللي بيمسح القائمة دي)
      final pendingSaved = prefs.getStringList('pending_ring_ids') ?? [];
      var pending = pendingSaved.toSet();

      // نحسب الطلبات الجديدة طالما عندنا سجل سابق محفوظ — حتى لو دي أول
      // دورة بعد ما الخدمة رجعت. من غير الشرط ده، لو أندرويد قفل الخدمة أو
      // الموبايل اترستارت ووصل طلب في الوقت ده، كانت أول دورة بتسجّله
      // كـ"معروف" وما بترنش عليه أبدًا رغم إن الطيار عمره ما شافه.
      // بنتخطّاها بس في أول تشغيل على جهاز جديد (مفيش سجل أصلاً).
      if (!_firstRun || _knownIds.isNotEmpty) {
        final added = currentIds.difference(_knownIds);
        if (added.isNotEmpty) pending.addAll(added);
      }

      // نشيل من قائمة الرنين أي طلب مبقاش مُسند لهذا الطيار بنفس الحالة
      // (اتسلّم / اترجع / اتحول لطيار تاني) عشان الرنين يوقف تلقائياً
      pending = pending.intersection(currentIds);

      if (pending.isNotEmpty) {
        // إشعار ستارة واحد ثابت + نغمة loop مستمرة لحد ما الطيار يفتح
        // التطبيق — يشتغل حتى لو التطبيق مقفول تمامًا (بديل شاشة المكالمة)
        await OrderRinger.startRinging(count: pending.length);
      } else {
        // مفيش طلب معلّق — نوقف أي رنين شغّال ونشيل الإشعار
        await OrderRinger.stopRinging();
      }

      await prefs.setStringList('known_order_ids', currentIds.toList());
      await prefs.setStringList('pending_ring_ids', pending.toList());

      _firstRun = false;
      _knownIds = currentIds;
    } catch (_) {}
  }

  /// استقبال إشارة فورية من التطبيق الرئيسي (مثلاً "الطيار فتح التطبيق")
  /// عشان نوقف الرنين في نفس اللحظة من غير ما نستنى دورة الـ 15 ثانية الجاية
  @override
  void onReceiveData(Object data) {
    if (data == 'stop_ring') {
      OrderRinger.stopRinging();
    }
  }

  @override
  Future<void> onDestroy(DateTime timestamp) async {
    await OrderRinger.stopRinging();
  }
}

// ════════════════════════════════════════════════════════════════
//  FOREGROUND SERVICE MANAGER
// ════════════════════════════════════════════════════════════════
class FgService {
  /// تهيئة الإعدادات — مرة واحدة عند بدء التطبيق
  static void init() {
    FlutterForegroundTask.init(
      androidNotificationOptions: AndroidNotificationOptions(
        channelId: 'tiar_fg_service',
        channelName: 'خدمة الطيار',
        channelDescription: 'تعمل في الخلفية لاستقبال الطلبات',
        channelImportance: NotificationChannelImportance.LOW,
        priority: NotificationPriority.LOW,
        // الإشعار الثابت في الستارة (اللي بيعرف المستخدم إن التطبيق شغّال)
      ),
      iosNotificationOptions: const IOSNotificationOptions(
        showNotification: false,
        playSound: false,
      ),
      foregroundTaskOptions: ForegroundTaskOptions(
        // في الخلفية المستخدم مش بيبص على الشاشة، فمفيش داعي لسرعة
        // 3 ثواني — 15 ثانية كافية جداً لإشعار بطلب جديد بدون استنزاف
        // ملحوظ للبطارية والبيانات وهو التطبيق مقفول
        eventAction: ForegroundTaskEventAction.repeat(15000),
        autoRunOnBoot: true,   // يبدأ تلقائي بعد إعادة تشغيل الهاتف
        autoRunOnMyPackageReplaced: true,
        allowWakeLock: true,
        allowWifiLock: true,
      ),
    );
  }

  /// تشغيل الخدمة
  static Future<void> start() async {
    if (await FlutterForegroundTask.isRunningService) return;
    await FlutterForegroundTask.startService(
      serviceId: 501,
      notificationTitle: 'طيّار — في انتظار الطلبات',
      notificationText: 'يتحقق من الطلبات الجديدة...',
      callback: _startForegroundCallback,
    );
  }

  /// إيقاف الخدمة (عند تسجيل الخروج)
  static Future<void> stop() async {
    await FlutterForegroundTask.stopService();
  }
}

// ════════════════════════════════════════════════════════════════
//  NOTIFICATION SERVICE — صوت + اهتزاز عند أوردر جديد
// ════════════════════════════════════════════════════════════════
class NotificationService {
  static final AudioPlayer _player = AudioPlayer();

  /// مفتاح حفظ اختيار الصوت في SharedPreferences
  static const _prefKey = 'selected_sound';

  /// يجيب اسم ملف الصوت المحفوظ (أو الافتراضي)
  static Future<String> getSelectedSound() async {
    final p = await SharedPreferences.getInstance();
    return p.getString(_prefKey) ?? kSoundOptions.first.id;
  }

  /// يحفظ اختيار الصوت
  static Future<void> setSelectedSound(String soundId) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_prefKey, soundId);
  }

  /// يشغّل الصوت المحدد + اهتزاز
  static Future<void> playNewOrder() async {
    final hasVibrator = await Vibration.hasVibrator() ?? false;
    if (hasVibrator) {
      Vibration.vibrate(pattern: [0, 400, 150, 400, 150, 600]);
    }
    try {
      final soundId = await getSelectedSound();
      await _player.play(AssetSource('sounds/$soundId'));
    } catch (_) {
      await SystemSound.play(SystemSoundType.alert);
    }
  }

  /// معاينة صوت معين بدون حفظ
  static Future<void> preview(String soundId) async {
    try {
      await _player.stop();
      await _player.play(AssetSource('sounds/$soundId'));
    } catch (_) {
      await SystemSound.play(SystemSoundType.alert);
    }
  }

  static void dispose() => _player.dispose();
}

// ════════════════════════════════════════════════════════════════
//  INCOMING CALL SERVICE — شاشة "مكالمة واردة" حقيقية للأوردر الجديد
// ════════════════════════════════════════════════════════════════
//
// بدل ما نكتفي بإشعار عادي بيرن مرة أو اتنين ويسكت، بنستخدم نفس
// آلية "المكالمات الواردة" الحقيقية في النظام (ConnectionService في
// أندرويد / CallKit في iOS) — بالظبط زي أوبر وكريم وطلبات:
//  • بترن باستمرار (loop) لحد ما الطيار يرد، حتى لو التطبيق مقفول تمامًا
//  • بتفتح فوق شاشة القفل (Lock Screen)
//  • مش بتتأثر بتوفير البطارية (Battery Optimization / Doze) لأنها
//    بتستخدم نفس مسار المكالمات الحقيقية في النظام
class IncomingCallService {
  /// نفس الملف اللي الطيار اختاره من إعدادات النغمة، لكن بصيغة
  /// "Raw Resource" (بدون امتداد) — لازم يكون موجود في
  /// android/app/src/main/res/raw/ حتى تقدر أندرويد تلقاه كنغمة رنين
  static String _toRawSoundName(String assetFileName) {
    final noExt = assetFileName.contains('.')
        ? assetFileName.substring(0, assetFileName.lastIndexOf('.'))
        : assetFileName;
    return noExt.toLowerCase().replaceAll(RegExp(r'[^a-z0-9_]'), '_');
  }

  /// يعرض شاشة مكالمة واردة لأوردر واحد أو أكتر لسه معلّق
  static Future<void> showIncomingOrder({
    required String orderId,
    required int count,
  }) async {
    final soundId = await NotificationService.getSelectedSound();
    final rawSoundName = _toRawSoundName(soundId);
    final title = count == 1 ? 'طلب توصيل جديد' : '$count طلبات توصيل جديدة';

    final params = CallKitParams(
      id: orderId,
      nameCaller: 'طيّار — $title',
      appName: 'طيّار',
      handle: 'اضغط قبول لعرض تفاصيل الطلب',
      type: 0, // 0 = صوت فقط (مفيش فيديو)
      duration: 45000, // 45 ثانية رنين قبل ما يعتبرها "فائتة"
      missedCallNotification: const NotificationParams(
        showNotification: true,
        isShowCallback: false,
        subtitle: 'فاتك طلب توصيل',
      ),
      extra: <String, dynamic>{'orderId': orderId},
      android: AndroidParams(
        isCustomNotification: true,
        isShowLogo: false,
        ringtonePath: rawSoundName,
        backgroundColor: '#080808',
        actionColor: '#22C55E',
        textColor: '#ffffff',
        incomingCallNotificationChannelName: 'مكالمات الطلبات',
        missedCallNotificationChannelName: 'طلبات فائتة',
        isShowCallID: false,
        textAccept: 'قبول',
        textDecline: 'رفض',
      ),
      ios: IOSParams(
        handleType: 'generic',
        supportsVideo: false,
        ringtonePath: '$rawSoundName.wav',
      ),
    );
    await FlutterCallkitIncoming.showCallkitIncoming(params);
  }

  /// يقفل شاشة المكالمة الواردة (مثلاً لو الأوردر اتلغى أو راح لطيار تاني)
  static Future<void> endCall(String orderId) async {
    await FlutterCallkitIncoming.endCall(orderId);
  }

  static Future<void> endAllCalls() async {
    await FlutterCallkitIncoming.endAllCalls();
  }

  /// يبدأ الاستماع لأحداث "قبول / رفض / انتهاء المهلة" —
  /// ينادى مرة واحدة بس عند بدء التطبيق (من main)
  static void listenToEvents({
    required void Function(String orderId) onAccept,
  }) {
    FlutterCallkitIncoming.onEvent.listen((event) async {
      if (event == null) return;
      switch (event) {
        case CallEventActionCallAccept():
          final orderId =
              event.callKitParams.extra?['orderId']?.toString() ?? '';
          if (orderId.isNotEmpty) {
            // مهم جداً: لازم نبلّغ النظام إن المكالمة "اتوصلت" فور القبول،
            // وإلا أندرويد أحيانًا بيقفل نشاط المكالمة الواردة بطريقة بتسحب
            // معاها نشاط التطبيق الرئيسي كمان (يبان كإنه "قفل التطبيق")
            try {
              await FlutterCallkitIncoming.setCallConnected(orderId);
            } catch (_) {}
            onAccept(orderId);
          }
          break;
        case CallEventActionCallDecline():
        case CallEventActionCallTimeout():
        case CallEventActionCallEnded():
          // الطيار رفض أو خلصت مهلة الرنين — منسيبش حاجة معلّقة
          break;
        default:
          break;
      }
    });
  }

  /// يتحقق —عند بدء التطبيق فقط— من وجود مكالمة "متصلة بالفعل" لسه معلّقة.
  /// ده بيغطي حالة إن التطبيق كان مقفول تمامًا (Terminated) وابتدأ من
  /// جديد بسبب ضغط "قبول" على شاشة المكالمة، لأن مستمع الأحداث العادي
  /// (listenToEvents) بيتسجل بعد ما الـ isolate يبدأ، فممكن يفوّت
  /// اللحظة اللي اتطلق فيها حدث القبول الأصلي قبل ما التطبيق يخلص تحميله
  static Future<String?> getAlreadyAcceptedOrderId() async {
    try {
      final calls = await FlutterCallkitIncoming.activeCalls();
      if (calls is List<CallKitParams> && calls.isNotEmpty) {
        final first = calls.first;
        final extra = first.extra;
        final orderId = extra?['orderId']?.toString() ?? first.id;
        if (orderId != null && orderId.isNotEmpty) {
          try {
            await FlutterCallkitIncoming.setCallConnected(orderId);
          } catch (_) {}
          return orderId;
        }
      }
    } catch (_) {}
    return null;
  }
}

// ════════════════════════════════════════════════════════════════
//  MODELS — نماذج البيانات
// ════════════════════════════════════════════════════════════════
class DeliveryItem {
  final String receiverName;
  final String receiverPhone;
  final String receiverPhone2;
  final String address;
  final String zoneName;
  final double zonePrice;
  final String note;
  /// صور الإيصال/العنوان اللي المحل رفعها مع الطلب (روابط Cloudinary)
  final List<String> images;

  DeliveryItem({
    required this.receiverName,
    required this.receiverPhone,
    required this.receiverPhone2,
    required this.address,
    required this.zoneName,
    required this.zonePrice,
    required this.note,
    this.images = const [],
  });

  factory DeliveryItem.fromMap(Map m) {
    return DeliveryItem(
        receiverName:   m['receiverName']   ?? '',
        receiverPhone:  m['receiverPhone']  ?? '',
        receiverPhone2: m['receiverPhone2'] ?? '',
        address:        m['address']        ?? '',
        zoneName:       m['zoneName']       ?? '',
        zonePrice:      (m['zonePrice'] as num?)?.toDouble() ?? 0,
        note:           m['note']           ?? '',
        images:         (m['images'] is List)
            ? List<String>.from((m['images'] as List).whereType<String>())
            : const [],
      );
  }
}

class OrderModel {
  final String id;
  final String orderNum;
  final String senderName;
  final String senderPhone;
  final String senderAddress;
  final String branchName;
  final String notes;
  final List<DeliveryItem> deliveries;
  final double totalDeliveryPrice;
  final double storePrepaid;
  final String storePrepaidNote;
  final String status;
  final String statusSince;
  final String pilotId;
  final String pilotName;
  final String receivedAt;      // الطيار استلم الطرد فعلياً من مكان الاستلام
  final String tripStartedAt;   // الطيار بدأ رحلة توصيل هذا الطلب بعينه
  final String deliveredAt;
  final String createdAt;
  // حالة طلب إرجاع الطلب بإذن: '' (لا يوجد) | 'pending' (بانتظار موافقة
  // الفرع/الإدارة) | 'rejected' (اترفض) — عند الموافقة يتحوّل الأوردر نفسه
  // لحالة "لم يتم التوصيل" فمش محتاجين قيمة 'approved' هنا
  final String returnStatus;
  final String returnReason;

  OrderModel({
    required this.id,
    required this.orderNum,
    required this.senderName,
    required this.senderPhone,
    required this.senderAddress,
    required this.branchName,
    required this.notes,
    required this.deliveries,
    required this.totalDeliveryPrice,
    required this.storePrepaid,
    required this.storePrepaidNote,
    required this.status,
    required this.statusSince,
    required this.pilotId,
    required this.pilotName,
    required this.receivedAt,
    required this.tripStartedAt,
    required this.deliveredAt,
    required this.createdAt,
    this.returnStatus = '',
    this.returnReason = '',
  });

  factory OrderModel.fromMap(String id, Map m) {
    final rawDeliveries = m['deliveries'];
    final delivs = rawDeliveries is List
        ? rawDeliveries.map((d) => DeliveryItem.fromMap(Map<String, dynamic>.from(d))).toList()
        : <DeliveryItem>[];
    return OrderModel(
      id:                 id,
      orderNum:           m['orderNum']           ?? id.substring(0, id.length < 6 ? id.length : 6),
      senderName:         m['senderName']         ?? '',
      senderPhone:        m['senderPhone']        ?? '',
      senderAddress:      m['senderAddress']      ?? '',
      branchName:         m['branchName']         ?? '',
      notes:              m['notes']              ?? '',
      deliveries:         delivs,
      totalDeliveryPrice: (m['totalDeliveryPrice'] as num?)?.toDouble() ?? 0,
      storePrepaid:       (m['storePrepaid'] as num?)?.toDouble() ?? 0,
      storePrepaidNote:   m['storePrepaidNote']   ?? '',
      status:             m['status']             ?? '',
      statusSince:        m['statusSince']        ?? '',
      pilotId:            m['pilotId']            ?? '',
      pilotName:          m['pilotName']          ?? '',
      receivedAt:         m['receivedAt']         ?? '',
      tripStartedAt:      m['tripStartedAt']      ?? '',
      deliveredAt:        m['deliveredAt']        ?? '',
      createdAt:          m['createdAt']          ?? '',
      returnStatus:       m['returnStatus']       ?? '',
      returnReason:       m['returnReason']       ?? '',
    );
  }

  /// الطيار استلم الطرد فعلياً من مكان الاستلام (مرحلة منفصلة عن بدء الرحلة)
  bool   get hasReceived => receivedAt.isNotEmpty;
  /// الطيار بدأ رحلة توصيل هذا الطلب بعينه (بعد استلامه فعلياً)
  bool   get hasStarted  => tripStartedAt.isNotEmpty;
  int    get parcelCount => deliveries.isEmpty ? 1 : deliveries.length;
  /// طلب إرجاع بانتظار موافقة الفرع/الإدارة
  bool   get returnPending  => returnStatus == 'pending';
  /// طلب إرجاع اترفض من الفرع/الإدارة
  bool   get returnRejected => returnStatus == 'rejected';

  DeliveryItem? get firstDelivery => deliveries.isNotEmpty ? deliveries.first : null;

  String get displayZone {
    final d = firstDelivery;
    if (d == null) return '';
    return d.zoneName.isNotEmpty ? d.zoneName : d.address;
  }

  DateTime? get timerStart {
    // استخدم tripStartedAt لو موجود، وإلا receivedAt، وإلا statusSince
    if (tripStartedAt.isNotEmpty) return DateTime.tryParse(tripStartedAt);
    if (receivedAt.isNotEmpty) return DateTime.tryParse(receivedAt);
    return DateTime.tryParse(statusSince);
  }
}

class PilotModel {
  final String id;
  final String name;
  final String phone1;
  final String vehicleNo;
  final String cardNum;
  final String address;
  final String pilotStatus;
  final String assignedBranchId;
  final double? lat;
  final double? lng;

  PilotModel({
    required this.id,
    required this.name,
    required this.phone1,
    required this.vehicleNo,
    required this.cardNum,
    required this.address,
    required this.pilotStatus,
    required this.assignedBranchId,
    this.lat,
    this.lng,
  });

  factory PilotModel.fromMap(String id, Map m) {
    final loc = m['location'];
    return PilotModel(
      id:          id,
      name:        m['name']        ?? '',
      phone1:      m['phone1']      ?? '',
      vehicleNo:   m['vehicleNo']   ?? '',
      cardNum:     m['cardNum']     ?? '',
      address:     m['address']     ?? '',
      pilotStatus: m['pilotStatus'] ?? '',
      assignedBranchId: m['assignedBranchId'] ?? '',
      lat: loc != null ? (loc['lat'] as num?)?.toDouble() : null,
      lng: loc != null ? (loc['lng'] as num?)?.toDouble() : null,
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  SESSION SERVICE — حفظ الجلسة
// ════════════════════════════════════════════════════════════════
class Session {
  static Future<void> save({
    required String username,
    required String name,
    required String pilotId,
  }) async {
    final p = await SharedPreferences.getInstance();
    await p.setString('username', username);
    await p.setString('name',     name);
    await p.setString('pilotId',  pilotId);
  }

  static Future<void> clear() async {
    final p = await SharedPreferences.getInstance();
    await p.remove('username');
    await p.remove('name');
    await p.remove('pilotId');
  }

  static Future<Map<String, String>?> load() async {
    final p = await SharedPreferences.getInstance();
    final u = p.getString('username') ?? '';
    if (u.isEmpty) return null;
    return {
      'username': u,
      'name':     p.getString('name')    ?? u,
      'pilotId':  p.getString('pilotId') ?? '',
    };
  }
}

// ════════════════════════════════════════════════════════════════
//  SHIFT SERVICE — إدارة الوردية
// ════════════════════════════════════════════════════════════════
class ShiftService {
  static const _keyActive    = 'shift_active';
  static const _keyStartedAt = 'shift_started_at';
  static const _keyOrderIds  = 'shift_order_ids';
  static const _keyShiftId   = 'shift_id';
  static const _keyPausedSeconds  = 'shift_paused_seconds';
  static const _keyBreakStartedAt = 'shift_break_started_at';

  /// هل في وردية نشطة الآن؟
  static Future<bool> isActive() async {
    final p = await SharedPreferences.getInstance();
    return p.getBool(_keyActive) ?? false;
  }

  /// وقت بدء الوردية
  static Future<DateTime?> startedAt() async {
    final p   = await SharedPreferences.getInstance();
    final iso = p.getString(_keyStartedAt) ?? '';
    return iso.isEmpty ? null : DateTime.tryParse(iso);
  }

  /// مُعرّف الوردية الحالية في Firebase (فاضي لو مفيش وردية)
  static Future<String> currentShiftId() async {
    final p = await SharedPreferences.getInstance();
    return p.getString(_keyShiftId) ?? '';
  }

  /// بدء وردية جديدة — تُسجَّل في Firebase عشان تظهر فورًا في لوحتَي الإدارة والفرع
  static Future<void> startShift(String pilotId, String pilotName) async {
    final now = DateTime.now().toIso8601String();
    final shiftId = await FB.createShift(pilotId, pilotName, now);
    final p = await SharedPreferences.getInstance();
    await p.setBool  (_keyActive,    true);
    await p.setString(_keyStartedAt, now);
    await p.setString(_keyShiftId,   shiftId);
    await p.setStringList(_keyOrderIds, []);
    await p.setInt(_keyPausedSeconds, 0);
    await p.setString(_keyBreakStartedAt, '');
  }

  /// إنهاء الوردية الحالية — تُقفَل في Firebase، ويتحرر الطيار بالكامل من
  /// فرعه الحالي (نفس سلوك زر "إنهاء الوردية" في لوحتَي الإدارة والفرع
  /// بالضبط)، عشان يخرج فورًا من طابور/قوائم فرعه القديم ويبقى متاحًا لطلب
  /// فتح وردية في أي فرع تاني، بدل ما يفضل مربوط بنفس الفرع القديم للأبد
  static Future<void> endShift(String pilotId) async {
    final p = await SharedPreferences.getInstance();
    final shiftId = p.getString(_keyShiftId) ?? '';
    if (shiftId.isNotEmpty) {
      await FB.closeShift(shiftId);
    }
    await FB.releasePilotFromBranch(pilotId);
    await p.setBool  (_keyActive,    false);
    await p.setString(_keyStartedAt, '');
    await p.setString(_keyShiftId,   '');
    await p.setStringList(_keyOrderIds, []);
    await p.setInt(_keyPausedSeconds, 0);
    await p.setString(_keyBreakStartedAt, '');
  }

  /// إنهاء الوردية محليًا فقط (بدون إرسال طلب إقفال لِـ Firebase) —
  /// تُستخدم عندما يُقفل المشرف الوردية من لوحة الإدارة/الفرع، فيكتشف
  /// تطبيق الطيار ذلك ويُنظّف حالته المحلية فقط دون تكرار الإقفال
  static Future<void> endShiftLocalOnly() async {
    final p = await SharedPreferences.getInstance();
    await p.setBool  (_keyActive,    false);
    await p.setString(_keyStartedAt, '');
    await p.setString(_keyShiftId,   '');
    await p.setStringList(_keyOrderIds, []);
    await p.setInt(_keyPausedSeconds, 0);
    await p.setString(_keyBreakStartedAt, '');
  }

  /// تبنّي وردية مفتوحة بالفعل على السيرفر — تُستخدم لما المشرف (المدير
  /// العام أو مدير الفرع) يفتح الوردية مباشرة من لوحة الإدارة من غير ما
  /// التطبيق نفسه يبعت طلب فتح وردية أصلاً (مثلاً الطيار مقفول تطبيقه
  /// وقتها أو ملوش هاتف ذكي وقتها). بنسجّل بيانات الوردية دي محليًا زي
  /// ما لو كان التطبيق هو اللي بدأها، من غير ما ننشئ سجل وردية جديد في
  /// Firebase (لأنه أصلاً موجود ومُنشأ من لوحة الإدارة).
  static Future<void> adoptShift(String shiftId, DateTime startedAt) async {
    final p = await SharedPreferences.getInstance();
    await p.setBool  (_keyActive,    true);
    await p.setString(_keyStartedAt, startedAt.toIso8601String());
    await p.setString(_keyShiftId,   shiftId);
    if (p.getStringList(_keyOrderIds) == null) await p.setStringList(_keyOrderIds, []);
    if (p.getInt(_keyPausedSeconds) == null) await p.setInt(_keyPausedSeconds, 0);
    await p.setString(_keyBreakStartedAt, '');
  }

  /// إجمالي وقت الاستراحات المتراكم خلال هذه الوردية (بالثواني)
  static Future<int> pausedSeconds() async {
    final p = await SharedPreferences.getInstance();
    return p.getInt(_keyPausedSeconds) ?? 0;
  }

  /// وقت بداية الاستراحة الحالية النشطة (null لو مفيش استراحة نشطة)
  static Future<DateTime?> breakStartedAt() async {
    final p = await SharedPreferences.getInstance();
    final iso = p.getString(_keyBreakStartedAt) ?? '';
    return iso.isEmpty ? null : DateTime.tryParse(iso);
  }

  /// تسجيل بداية استراحة معتمدة — يوقف عداد الوردية من هذه اللحظة
  static Future<void> startBreak(DateTime startedAt) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_keyBreakStartedAt, startedAt.toIso8601String());
  }

  /// إنهاء الاستراحة — يضيف مدتها للوقت المتراكم ويستأنف عداد الوردية
  /// من حيث توقف بالضبط (تكملة على الوقت الذي قبل البريك)
  static Future<void> endBreak() async {
    final p = await SharedPreferences.getInstance();
    final startIso = p.getString(_keyBreakStartedAt) ?? '';
    if (startIso.isNotEmpty) {
      final start = DateTime.tryParse(startIso);
      if (start != null) {
        final extra = DateTime.now().difference(start).inSeconds;
        final total = (p.getInt(_keyPausedSeconds) ?? 0) + (extra > 0 ? extra : 0);
        await p.setInt(_keyPausedSeconds, total);
      }
    }
    await p.setString(_keyBreakStartedAt, '');
  }

  /// الوقت الفعلي المنقضي من الوردية بعد خصم كل الاستراحات — يتجمّد
  /// عند بداية أي استراحة نشطة ولا يستأنف إلا بعد إنهائها
  static Future<Duration> elapsed() async {
    final start = await startedAt();
    if (start == null) return Duration.zero;
    final pausedSecs = await pausedSeconds();
    final breakStart = await breakStartedAt();
    if (breakStart != null) {
      final raw = breakStart.difference(start) - Duration(seconds: pausedSecs);
      return raw.isNegative ? Duration.zero : raw;
    }
    final raw = DateTime.now().difference(start) - Duration(seconds: pausedSecs);
    return raw.isNegative ? Duration.zero : raw;
  }

  /// إضافة أوردر للوردية الحالية — يُبصَم عليه برقم الوردية في Firebase
  /// حتى تقدر لوحات الإدارة/الفرع تجيب أوردرات أي وردية بسهولة
  static Future<void> addOrder(String orderId) async {
    final p    = await SharedPreferences.getInstance();
    final list = p.getStringList(_keyOrderIds) ?? [];
    if (!list.contains(orderId)) {
      list.add(orderId);
      await p.setStringList(_keyOrderIds, list);
      final shiftId = p.getString(_keyShiftId) ?? '';
      if (shiftId.isNotEmpty) {
        await FB.stampOrderShift(orderId, shiftId);
      }
    }
  }

  /// أوردرات الوردية الحالية
  static Future<Set<String>> shiftOrderIds() async {
    final p = await SharedPreferences.getInstance();
    return (p.getStringList(_keyOrderIds) ?? []).toSet();
  }
}

// ════════════════════════════════════════════════════════════════
//  FIREBASE SERVICE — استدعاءات Firebase
// ════════════════════════════════════════════════════════════════
// ════════════════════════════════════════════════════════════════
//  FIREBASE AUTH (Anonymous) — توكن للوصول لقاعدة البيانات
// ════════════════════════════════════════════════════════════════
//
// قواعد قاعدة البيانات بتتطلب auth != null، فالتطبيق بيسجّل دخول مجهول
// (Anonymous) مرة واحدة وبيحفظ refreshToken، وبيضيف ?auth=<idToken> على
// كل نداء REST. لو فشل التوكن لأي سبب بنرجّع null والنداءات تشتغل من غير
// توكن (متوافق مع القواعد المفتوحة) — عشان التحديث يبقى آمن ومتدرّج.
class FbAuth {
  static const _apiKey = 'AIzaSyAqDGiMVtZ6PQH522ndXEYCBM37WKcI-ws';
  static String? _idToken;
  static DateTime? _expiresAt;
  static bool _busy = false;

  /// يرجّع idToken صالح (أو null لو تعذّر) — بيتعامل مع التجديد تلقائيًا
  static Future<String?> token() async {
    if (_idToken != null && _expiresAt != null &&
        DateTime.now().isBefore(_expiresAt!.subtract(const Duration(minutes: 5)))) {
      return _idToken;
    }
    if (_busy) return _idToken; // منع التزاحم بين نداءات متوازية
    _busy = true;
    try {
      final prefs = await SharedPreferences.getInstance();
      try { await prefs.reload(); } catch (_) {}
      final refresh = prefs.getString('fb_refresh_token');

      if (refresh != null && refresh.isNotEmpty) {
        final ok = await _refresh(refresh);
        if (ok) return _idToken;
      }
      await _signInAnonymously(prefs);
      return _idToken;
    } catch (_) {
      return null;
    } finally {
      _busy = false;
    }
  }

  static Future<bool> _refresh(String refreshToken) async {
    try {
      final res = await http.post(
        Uri.parse('https://securetoken.googleapis.com/v1/token?key=$_apiKey'),
        body: {'grant_type': 'refresh_token', 'refresh_token': refreshToken},
      ).timeout(const Duration(seconds: 12));
      if (res.statusCode != 200) return false;
      final d = json.decode(res.body);
      _idToken   = d['id_token'];
      _expiresAt = DateTime.now().add(Duration(seconds: int.tryParse('${d['expires_in']}') ?? 3600));
      return _idToken != null;
    } catch (_) { return false; }
  }

  static Future<void> _signInAnonymously(SharedPreferences prefs) async {
    final res = await http.post(
      Uri.parse('https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=$_apiKey'),
      headers: {'Content-Type': 'application/json'},
      body: json.encode({'returnSecureToken': true}),
    ).timeout(const Duration(seconds: 12));
    if (res.statusCode != 200) return;
    final d = json.decode(res.body);
    _idToken   = d['idToken'];
    _expiresAt = DateTime.now().add(Duration(seconds: int.tryParse('${d['expiresIn']}') ?? 3600));
    // نحفظ refreshToken عشان ما ننشئش حساب مجهول جديد كل مرة
    if (d['refreshToken'] != null) {
      await prefs.setString('fb_refresh_token', d['refreshToken']);
    }
  }

  /// لاحقة الاستعلام المطلوبة على روابط REST (فاضية لو مفيش توكن)
  static Future<String> queryParam() async {
    final t = await token();
    return (t == null || t.isEmpty) ? '' : '?auth=$t';
  }
}

class FB {
  static Future<dynamic> get(String path) async {
    final res = await http.get(Uri.parse('${K.db}/$path.json${await FbAuth.queryParam()}'))
        .timeout(const Duration(seconds: 10));
    if (res.statusCode == 200) return json.decode(res.body);
    throw Exception('HTTP ${res.statusCode}');
  }

  static Future<void> patch(String path, Map<String, dynamic> data) async {
    await http.patch(
      Uri.parse('${K.db}/$path.json${await FbAuth.queryParam()}'),
      body: json.encode(data),
    ).timeout(const Duration(seconds: 10));
  }

  // أوردرات الطيار النشطة
  static Future<List<OrderModel>> myActiveOrders(String pilotId) async {
    final raw = await get('orders');
    if (raw == null) return [];
    return (raw as Map<String, dynamic>)
        .entries
        .where((e) =>
            e.value is Map &&
            e.value['pilotId'] == pilotId &&
            e.value['status'] == 'جاري التوصيل')
        .map((e) => OrderModel.fromMap(e.key, Map<String, dynamic>.from(e.value)))
        .toList()
      ..sort((a, b) => a.statusSince.compareTo(b.statusSince));
  }

  // أوردرات الطيار المنتهية
  static Future<List<OrderModel>> myCompletedOrders(String pilotId) async {
    final raw = await get('orders');
    if (raw == null) return [];
    return (raw as Map<String, dynamic>)
        .entries
        .where((e) =>
            e.value is Map &&
            e.value['pilotId'] == pilotId &&
            (e.value['status'] == 'تم التسليم' ||
             e.value['status'] == 'لم يتم التوصيل'))
        .map((e) => OrderModel.fromMap(e.key, Map<String, dynamic>.from(e.value)))
        .toList()
      ..sort((a, b) => b.statusSince.compareTo(a.statusSince));
  }

  static Future<PilotModel?> getPilot(String pilotId) async {
    if (pilotId.isEmpty) return null;
    final raw = await get('pilots/$pilotId');
    if (raw == null) return null;
    return PilotModel.fromMap(pilotId, Map<String, dynamic>.from(raw));
  }

  // ── الورديات — إنشاء/إقفال/ربط الأوردرات بها (لعرضها في لوحتَي الإدارة والفرع) ──

  /// إنشاء وردية جديدة في Firebase، وإرجاع مُعرّفها (push id)
  static Future<String> createShift(String pilotId, String pilotName, String startedAt) async {
    // نجيب فرع الطيار وقت بداية الوردية ونثبته على السجل، عشان لوحة الفرع
    // تقدر تفلتر الورديات باستخدام الفرع المحفوظ بدل الاعتماد على قائمة
    // طيارين الفرع الحالية (اللي بتتغيّر لو الطيار اتنقل أو اتحذف بعدين)
    final pilot = await getPilot(pilotId);
    final res = await http.post(
      Uri.parse('${K.db}/shifts.json${await FbAuth.queryParam()}'),
      body: json.encode({
        'pilotId':    pilotId,
        'pilotName':  pilotName,
        'branchId':   pilot?.assignedBranchId ?? '',
        'startedAt':  startedAt,
        'status':     'active',
      }),
    ).timeout(const Duration(seconds: 10));
    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      return (data is Map ? data['name'] : null) ?? '';
    }
    return '';
  }

  /// إقفال الوردية عند إنهائها
  static Future<void> closeShift(String shiftId) async {
    await patch('shifts/$shiftId', {
      'endedAt': DateTime.now().toIso8601String(),
      'status':  'ended',
    });
  }

  /// تحرير الطيار بالكامل من فرعه الحالي بعد إنهاء ورديته من التطبيق نفسه —
  /// نفس سلوك زر "إنهاء الوردية" في لوحتَي الإدارة والفرع بالضبط
  /// (assignedBranchId: null)، عشان يخرج فورًا من طابور انتظار فرعه القديم
  /// ويبقى متاحًا لأي فرع تاني يقبل طلب فتح ورديته الجاية، بدل ما يفضل
  /// حبيس نفس الفرع القديم للأبد
  static Future<void> releasePilotFromBranch(String pilotId) async {
    if (pilotId.isEmpty) return;
    try {
      final pilotRaw = await getPilotRaw(pilotId);
      final branchId = pilotRaw?['assignedBranchId']?.toString() ?? '';
      final queueNo  = (pilotRaw?['queueNo'] as num?)?.toInt() ?? 0;

      await patch('pilots/$pilotId', {
        'pilotStatus':      null,
        'assignedBranchId': null,
        'queueNo':          null,
        'statusSince':      null,
        'breakStartedAt':   null,
        'leaveType':        null,
        'leaveReason':      null,
      });

      // نقرّب أرقام الطيارين اللي كانوا خلفه في طابور فرعه القديم خطوة واحدة
      // عشان الطابور يفضل متراص بدون فجوات (نفس منطق لوحتَي الإدارة والفرع)
      if (branchId.isNotEmpty && queueNo > 0) {
        await _shiftQueueAfterRemoval(branchId, queueNo);
      }
    } catch (_) {
      // لو فشل التحرير من الفرع لأي سبب (مشكلة شبكة مثلاً)، نتجاهل الخطأ
      // هنا ولا نمنع الطيار من إتمام إنهاء ورديته محليًا — يقدر يحاول تاني
    }
  }

  /// بعد خروج طيار من طابور انتظار فرعه، بيقرّب أرقام كل الطيارين اللي كانوا
  /// خلفه في نفس الفرع خطوة واحدة، عشان الطابور يفضل متراص بدون فجوات
  /// (نفس منطق shiftQueueAfterRemoval في لوحتَي الإدارة والفرع)
  static Future<void> _shiftQueueAfterRemoval(String branchId, int removedQueueNo) async {
    final raw = await get('pilots');
    if (raw == null) return;
    final Map<String, dynamic> updates = {};
    for (final entry in (raw as Map<String, dynamic>).entries) {
      final v = entry.value;
      if (v is Map &&
          (v['assignedBranchId'] ?? '') == branchId &&
          (v['pilotStatus'] ?? '') == 'waiting') {
        final q = (v['queueNo'] as num?)?.toInt() ?? 0;
        if (q > removedQueueNo) {
          updates['pilots/${entry.key}/queueNo'] = q - 1;
        }
      }
    }
    if (updates.isNotEmpty) await patch('', updates);
  }

  /// بصم رقم الوردية على الأوردر عشان يظهر ضمن أوردرات هذه الوردية في لوحات الإدارة/الفرع
  static Future<void> stampOrderShift(String orderId, String shiftId) async {
    await patch('orders/$orderId', {'shiftId': shiftId});
  }

  static Future<String> getBranchName(String branchId) async {
    if (branchId.isEmpty) return '';
    try {
      final raw = await get('branches/$branchId/name');
      return raw?.toString() ?? '';
    } catch (_) { return ''; }
  }

  static Future<Map<String, dynamic>?> getShiftRecord(String shiftId) async {
    if (shiftId.isEmpty) return null;
    final raw = await get('shifts/$shiftId');
    if (raw == null) return null;
    return Map<String, dynamic>.from(raw);
  }

  static Future<void> updateLocation(String pilotId, double lat, double lng) async {
    await patch('pilots/$pilotId', {
      'location': {
        'lat': lat,
        'lng': lng,
        'updatedAt': DateTime.now().toIso8601String(),
      },
    });
  }

  // ── طلب فتح وردية جديدة (يحتاج موافقة الفرع/الإدارة) ──────────────────

  /// إرسال طلب فتح وردية جديدة، وإرجاع مُعرّف الطلب (لمتابعة حالته)
  static Future<String> requestShiftStart({
    required String pilotId,
    required String pilotName,
    required String branchId,
    required String branchName,
  }) async {
    final res = await http.post(
      Uri.parse('${K.db}/pilotShiftRequests.json${await FbAuth.queryParam()}'),
      body: json.encode({
        'pilotId':     pilotId,
        'pilotName':   pilotName,
        'branchId':    branchId,
        'branchName':  branchName,
        'status':      'pending',
        'requestedAt': DateTime.now().toIso8601String(),
      }),
    ).timeout(const Duration(seconds: 10));
    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      return (data is Map ? data['name'] : null) ?? '';
    }
    return '';
  }

  /// جلب حالة طلب فتح الوردية (pending / approved / rejected)
  static Future<Map<String, dynamic>?> getShiftRequest(String reqId) async {
    if (reqId.isEmpty) return null;
    final raw = await get('pilotShiftRequests/$reqId');
    if (raw == null) return null;
    return Map<String, dynamic>.from(raw);
  }

  // ── طلب إذن (استراحة / عطلة‑انصراف / حادث) — يحتاج موافقة الفرع/الإدارة ──

  /// إرسال طلب إذن، وإرجاع مُعرّف الطلب (لمتابعة حالته)
  static Future<String> requestLeave({
    required String pilotId,
    required String pilotName,
    required String branchId,
    required String branchName,
    required String type, // rest | dayoff | incident
    required String reason,
  }) async {
    final res = await http.post(
      Uri.parse('${K.db}/pilotLeaveRequests.json${await FbAuth.queryParam()}'),
      body: json.encode({
        'pilotId':     pilotId,
        'pilotName':   pilotName,
        'branchId':    branchId,
        'branchName':  branchName,
        'type':        type,
        'reason':      reason,
        'status':      'pending',
        'requestedAt': DateTime.now().toIso8601String(),
      }),
    ).timeout(const Duration(seconds: 10));
    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      return (data is Map ? data['name'] : null) ?? '';
    }
    return '';
  }

  /// جلب حالة طلب الإذن (pending / approved / rejected)
  static Future<Map<String, dynamic>?> getLeaveRequest(String reqId) async {
    if (reqId.isEmpty) return null;
    final raw = await get('pilotLeaveRequests/$reqId');
    if (raw == null) return null;
    return Map<String, dynamic>.from(raw);
  }

  /// إنهاء الإذن ورجوع الطيار للعمل — يستدعيها الطيار نفسه من التطبيق
  static Future<void> endLeave(String pilotId) async {
    final pilot = await getPilot(pilotId);
    final nextQ = await getNextQueueNo(pilot?.assignedBranchId ?? '');
    final now = DateTime.now().toIso8601String();
    await patch('pilots/$pilotId', {
      'pilotStatus':    'waiting',
      'queueNo':        nextQ,
      'statusSince':    now,
      'breakStartedAt': null,
      'leaveType':      null,
      'leaveReason':    null,
    });

    // نقفل طلب الإذن نفسه (حالة "approved" → "ended") وإلا يفضل ظاهر في
    // لوحات الإدارة/الفرع إنه لسه في إذن حتى بعد ما يرجع للعمل فعليًا
    try {
      final raw = await get('pilotLeaveRequests');
      if (raw is Map) {
        for (final entry in raw.entries) {
          final v = entry.value;
          if (v is Map && v['pilotId'] == pilotId && v['status'] == 'approved') {
            await patch('pilotLeaveRequests/${entry.key}', {
              'status':  'ended',
              'endedAt': now,
              'endedBy': 'pilot',
            });
          }
        }
      }
    } catch (_) {
      // لو فشل تحديث سجل الطلب لأي سبب، ده مش حرج — حالة الطيار
      // نفسها اتحدثت بالفعل، وده الأهم لاستئناف شغله
    }
  }

  /// جلب حالة الطيار الحالية من Firebase (لمزامنة حالة الاستراحة/إنهاء الوردية
  /// عن بُعد من لوحة الإدارة أو الفرع مع الحالة المحلية في التطبيق)
  static Future<Map<String, dynamic>?> getPilotRaw(String pilotId) async {
    if (pilotId.isEmpty) return null;
    final raw = await get('pilots/$pilotId');
    if (raw == null) return null;
    return Map<String, dynamic>.from(raw);
  }

  /// جلب حالة وردية معيّنة (لاكتشاف إغلاقها عن بُعد من لوحة الإدارة/الفرع)
  static Future<Map<String, dynamic>?> getShiftRaw(String shiftId) async {
    if (shiftId.isEmpty) return null;
    final raw = await get('shifts/$shiftId');
    if (raw == null) return null;
    return Map<String, dynamic>.from(raw);
  }

  /// البحث عن وردية نشطة لهذا الطيار على السيرفر — تُستخدم لاكتشاف وردية
  /// اتفتحت له عن بُعد من لوحة الإدارة أو الفرع (زر "فتح وردية مباشرة")
  /// بدون ما التطبيق نفسه يطلبها، عشان "يتبنّاها" محليًا بدل ما يفضل
  /// عارض شاشة "طلب فتح وردية" وهو أصلاً في وردية شغّالة على السيرفر
  static Future<Map<String, dynamic>?> findActiveShiftForPilot(String pilotId) async {
    if (pilotId.isEmpty) return null;
    try {
      final raw = await get('shifts');
      if (raw is! Map) return null;
      String? bestId;
      Map<String, dynamic>? best;
      for (final entry in raw.entries) {
        final v = entry.value;
        if (v is Map &&
            v['pilotId'] == pilotId &&
            (v['status'] ?? 'active') == 'active') {
          final startedAt = v['startedAt']?.toString() ?? '';
          final bestStartedAt = best?['startedAt']?.toString() ?? '';
          if (best == null || startedAt.compareTo(bestStartedAt) > 0) {
            bestId = entry.key;
            best = Map<String, dynamic>.from(v);
          }
        }
      }
      if (bestId == null || best == null) return null;
      best['id'] = bestId;
      return best;
    } catch (_) {
      return null;
    }
  }

  /// المرحلة 1: تسجيل استلام الطيار للطرد فعلياً من مكان الاستلام (مستقلة عن بدء الرحلة)
  static Future<void> receiveOrder(String orderId) async {
    await patch('orders/$orderId', {
      'receivedAt': DateTime.now().toIso8601String(),
    });
  }

  /// نفس المرحلة لكن لعدة طلبات مرة واحدة — لحالة استلام أكثر من طرد من نفس المحل في زيارة واحدة
  static Future<void> receiveOrders(List<String> orderIds) async {
    if (orderIds.isEmpty) return;
    final now = DateTime.now().toIso8601String();
    final Map<String, dynamic> updates = {};
    for (final id in orderIds) {
      updates['orders/$id/receivedAt'] = now;
    }
    await patch('', updates);
  }

  /// المرحلة 2: بدء رحلة توصيل طلب بعينه (بعد استلامه). لو حصل بدء الرحلة قبل تسجيل
  /// الاستلام لأي سبب (توافقاً مع بيانات قديمة)، نسجّل الاستلام في نفس اللحظة كشبكة أمان
  /// حتى لا يظهر الطلب في لوحات الإدارة/الفرع وكأنه لم يُستلم أبداً.
  static Future<void> startTrip(String orderId, {bool alreadyReceived = false}) async {
    final now = DateTime.now().toIso8601String();
    final Map<String, dynamic> data = {'tripStartedAt': now};
    if (!alreadyReceived) data['receivedAt'] = now;
    await patch('orders/$orderId', data);
  }

  static Future<void> markDelivered(String orderId) async {
    final now = DateTime.now().toIso8601String();

    // 1) نجيب بيانات الطلب الحالية عشان نعرف الطيار المرتبط بيه
    final orderRaw = await get('orders/$orderId');
    final String pilotId = orderRaw is Map ? (orderRaw['pilotId'] ?? '').toString() : '';

    // 2) تسجيل تسليم الطلب
    await patch('orders/$orderId', {
      'status':      'تم التسليم',
      'deliveredAt': now,
      // لم تتم تسوية فلوس هذا الأوردر مع الفرع بعد — يظهر للمدير عند عودة الطيار
      // ليؤكد استلام المبلغ منه أو تركه كعهدة معه
      'moneySettled': false,
    });

    if (pilotId.isEmpty) return;

    // 3) لو ده كان آخر طلب معه (مفيش طلبات تانية "جاري التوصيل")، يبقى متاحًا
    // فورًا على الخريطة (أخضر) لتلقّي طلب جديد وهو في الطريق — دون الحاجة
    // للرجوع للفرع أولًا. الفرع يسوّي فلوس الطلبات المسلَّمة لاحقًا عند عودته.
    final pilot = await getPilot(pilotId);
    if (pilot != null && pilot.pilotStatus == 'onLeave') return; // في إذن — سيبه زي ما هو

    final ordersRaw = await get('orders');
    final stillDelivering = ordersRaw is Map
        ? (ordersRaw as Map<String, dynamic>).entries.any((e) =>
            e.key != orderId &&
            e.value is Map &&
            e.value['pilotId'] == pilotId &&
            e.value['status'] == 'جاري التوصيل')
        : false;

    if (!stillDelivering) {
      final nextQ = await getNextQueueNo(pilot?.assignedBranchId ?? '');
      await patch('pilots/$pilotId', {
        'pilotStatus': 'waiting',
        'queueNo':     nextQ,
        'statusSince': now,
      });
    }
  }

  // رقم الدور التالي للطيار في طابور الانتظار (نفس منطق لوحات الإدارة/الفرع)
  static Future<int> getNextQueueNo(String branchId) async {
    final raw = await get('pilots');
    if (raw == null) return 1;
    int maxNo = 0;
    for (final entry in (raw as Map<String, dynamic>).entries) {
      final v = entry.value;
      if (v is Map &&
          (v['assignedBranchId'] ?? '') == branchId &&
          (v['pilotStatus'] ?? '') == 'waiting') {
        final q = (v['queueNo'] as num?)?.toInt() ?? 0;
        if (q > maxNo) maxNo = q;
      }
    }
    return maxNo + 1;
  }

  /// تسجيل "لم يتم التوصيل" — إرجاع الطلب من الطيار.
  /// نفس الحالة (status) وأسماء الحقول المستخدمة في لوحات الإدارة/الفرع/المحل
  /// حتى يظهر الطلب فورًا في تبويب "لم يتم التوصيل" وتُحدَّث حالة الطيار.
  static Future<void> markNotDelivered(String orderId, String reason) async {
    final now = DateTime.now().toIso8601String();

    // 1) نجيب بيانات الطلب الحالية عشان نعرف الطيار المرتبط بيه
    final orderRaw = await get('orders/$orderId');
    final String pilotId = orderRaw is Map ? (orderRaw['pilotId'] ?? '').toString() : '';

    // 2) نحدّث حالة الطلب — بنفس المفاتيح اللي بتقرأها كل التطبيقات الأخرى
    await patch('orders/$orderId', {
      'status':             'لم يتم التوصيل',
      'undeliveredAt':      now,
      'undeliveredReason':  reason,
    });

    if (pilotId.isEmpty) return;

    // 3) لو الطيار مالوش طلبات تانية "جاري التوصيل"، يرجع لطابور الانتظار
    final pilot = await getPilot(pilotId);
    final ordersRaw = await get('orders');
    final stillDelivering = ordersRaw is Map
        ? (ordersRaw as Map<String, dynamic>).entries.any((e) =>
            e.key != orderId &&
            e.value is Map &&
            e.value['pilotId'] == pilotId &&
            e.value['status'] == 'جاري التوصيل')
        : false;

    if (!stillDelivering) {
      final nextQ = await getNextQueueNo(pilot?.assignedBranchId ?? '');
      await patch('pilots/$pilotId', {
        'pilotStatus': 'waiting',
        'queueNo':     nextQ,
        'statusSince': now,
      });
    }
  }

  // ── طلب إرجاع الطلب (يحتاج موافقة الفرع/الإدارة) ──────────────────────
  //
  // بدل ما الطيار يرجّع الطلب فورًا، بيبعت طلب إرجاع بسبب مكتوب، والطلب ده
  // بيظهر في لوحتَي الفرع والإدارة (نود pilotReturnRequests زي طلبات الإذن)،
  // وأي واحد فيهم يوافق أو يرفض. عند الموافقة يتحوّل الأوردر لـ"لم يتم التوصيل".

  /// إرسال طلب إرجاع طلب، وإرجاع مُعرّف الطلب. بنبصم كمان على الأوردر نفسه
  /// حالة returnStatus='pending' عشان تطبيق الطيار يعرض "بانتظار الموافقة"
  /// من غير ما يجيب نود الطلبات كامل (توفير بيانات)
  static Future<String> requestOrderReturn({
    required String orderId,
    required String orderNum,
    required String pilotId,
    required String pilotName,
    required String branchId,
    required String branchName,
    required String reason,
  }) async {
    final now = DateTime.now().toIso8601String();
    final res = await http.post(
      Uri.parse('${K.db}/pilotReturnRequests.json${await FbAuth.queryParam()}'),
      body: json.encode({
        'orderId':     orderId,
        'orderNum':    orderNum,
        'pilotId':     pilotId,
        'pilotName':   pilotName,
        'branchId':    branchId,
        'branchName':  branchName,
        'reason':      reason,
        'status':      'pending',
        'requestedAt': now,
      }),
    ).timeout(const Duration(seconds: 10));

    await patch('orders/$orderId', {
      'returnStatus': 'pending',
      'returnReason': reason,
    });

    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      return (data is Map ? data['name'] : null) ?? '';
    }
    return '';
  }

  /// إلغاء علامة الرفض عن الأوردر بعد ما الطيار يشوفها (يرجع الأزرار طبيعية)
  static Future<void> clearOrderReturnFlag(String orderId) async {
    await patch('orders/$orderId', {
      'returnStatus': null,
      'returnReason': null,
    });
  }
}

// ════════════════════════════════════════════════════════════════
//  LOCATION SENDER — إرسال موقع الطيار بكفاءة (بطارية/بيانات)
// ════════════════════════════════════════════════════════════════
//
// يرسل الموقع مرة كل 60 ثانية كحد أقصى، وميبعتش لو الطيار واقف مكانه
// (تحرّك أقل من مسافة معقولة) عشان نوفّر البطارية والباقة. بيشتغل من الـ
// isolate الرئيسي (والتطبيق مفتوح) ومن الخدمة الخلفية (والتطبيق مقفول) —
// كل واحد بياخد وقته من نفس المفاتيح المحفوظة عشان ما يتزاحموش.
class LocationSender {
  static const _minInterval = Duration(seconds: 60); // مرة كل دقيقة كحد أقصى
  static const _minMoveMeters = 20.0;                // تجاهل الحركة الطفيفة

  /// يجلب الموقع الحالي ويرسله لو مرّ 60ث على آخر إرسال والطيار اتحرّك فعلاً.
  /// [background] لتقليل دقة الـ GPS في الخلفية (توفير أكبر للبطارية).
  static Future<void> maybeSend(String pilotId, {bool background = false}) async {
    if (pilotId.isEmpty) return;
    try {
      final prefs = await SharedPreferences.getInstance();
      final nowMs = DateTime.now().millisecondsSinceEpoch;
      final lastMs = prefs.getInt('loc_last_ms') ?? 0;
      if (nowMs - lastMs < _minInterval.inMilliseconds) return; // لسه بدري

      if (!await Geolocator.isLocationServiceEnabled()) return;
      final perm = await Geolocator.checkPermission();
      if (perm == LocationPermission.denied ||
          perm == LocationPermission.deniedForever) return;

      final pos = await Geolocator.getCurrentPosition(
        locationSettings: LocationSettings(
          accuracy: background ? LocationAccuracy.medium : LocationAccuracy.high,
          timeLimit: const Duration(seconds: 15),
        ),
      );

      // تجاهل الإرسال لو الطيار مش متحرّك مسافة معقولة (توفير بيانات)
      final lastLat = prefs.getDouble('loc_last_lat');
      final lastLng = prefs.getDouble('loc_last_lng');
      if (lastLat != null && lastLng != null) {
        final moved = Geolocator.distanceBetween(lastLat, lastLng, pos.latitude, pos.longitude);
        if (moved < _minMoveMeters) {
          // واقف مكانه — نحدّث وقت المحاولة بس عشان ما نلحّش على الـ GPS تاني قبل دقيقة
          await prefs.setInt('loc_last_ms', nowMs);
          return;
        }
      }

      await FB.updateLocation(pilotId, pos.latitude, pos.longitude);
      await prefs.setInt('loc_last_ms', nowMs);
      await prefs.setDouble('loc_last_lat', pos.latitude);
      await prefs.setDouble('loc_last_lng', pos.longitude);
    } catch (_) {
      // نتجاهل أخطاء الموقع العابرة (GPS/شبكة) — هيتحاول تاني الدورة الجاية
    }
  }
}

// ════════════════════════════════════════════════════════════════
//  MAIN
// ════════════════════════════════════════════════════════════════
void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor:          Colors.transparent,
    statusBarIconBrightness: Brightness.light,
    systemNavigationBarColor: K.bg,
  ));

  // ── تهيئة إشعارات الستارة ──
  await LocalNotif.init();

  // ── تهيئة Foreground Service ──
  FgService.init();

  // ── الاستماع لحدث "قبول" من شاشة المكالمة الواردة ──
  // بيشتغل سواء التطبيق كان فاتح أو لسه بيفتح لأول مرة بسبب الضغط على قبول
  final session = await Session.load();
  IncomingCallService.listenToEvents(onAccept: (orderId) {
    final pilotId = session?['pilotId'];
    if (pilotId == null || pilotId.isEmpty) return;
    navigatorKey.currentState?.push(
      MaterialPageRoute(
        builder: (_) => OrderDetailScreen(orderId: orderId, pilotId: pilotId),
      ),
    );
  });

  runApp(TiarApp(session: session));

  // ── تغطية حالة "التطبيق كان مقفول تمامًا" وابتدأ بسبب ضغط "قبول" ──
  // بنستنى أول فريم يترسم عشان navigatorKey يبقى جاهز فعليًا قبل أي Push
  final pilotId = session?['pilotId'];
  if (pilotId != null && pilotId.isNotEmpty) {
    final alreadyAcceptedId = await IncomingCallService.getAlreadyAcceptedOrderId();
    if (alreadyAcceptedId != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        navigatorKey.currentState?.push(
          MaterialPageRoute(
            builder: (_) => OrderDetailScreen(orderId: alreadyAcceptedId, pilotId: pilotId),
          ),
        );
      });
    }
  }
}

class TiarApp extends StatelessWidget {
  final Map<String, String>? session;
  const TiarApp({super.key, this.session});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      navigatorKey: navigatorKey,
      title: 'الدهشان',
      debugShowCheckedModeBanner: false,
      builder: (ctx, child) => Directionality(textDirection: TextDirection.rtl, child: child!),
      theme: ThemeData(
        brightness:            Brightness.dark,
        scaffoldBackgroundColor: K.bg,
        colorScheme:           const ColorScheme.dark(primary: K.red, surface: K.card),
        useMaterial3:          true,
      ),
      home: SplashScreen(session: session),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  SPLASH SCREEN
// ════════════════════════════════════════════════════════════════
class SplashScreen extends StatefulWidget {
  final Map<String, String>? session;
  const SplashScreen({super.key, this.session});
  @override State<SplashScreen> createState() => _SplashState();
}

class _SplashState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _fade, _scale;

  @override
  void initState() {
    super.initState();
    _ctrl  = AnimationController(vsync: this, duration: const Duration(milliseconds: 1000));
    _fade  = Tween<double>(begin: 0, end: 1).animate(CurvedAnimation(parent: _ctrl, curve: Curves.easeIn));
    _scale = Tween<double>(begin: .8, end: 1).animate(CurvedAnimation(parent: _ctrl, curve: Curves.easeOutBack));
    _ctrl.forward();

    Future.delayed(const Duration(milliseconds: 2000), () async {
      if (!mounted) return;
      if (widget.session != null) {
        var shiftActive = await ShiftService.isActive();
        // لو مفيش وردية نشطة محليًا، لازم نتأكد من السيرفر قبل ما نحكم
        // نهائيًا — ممكن يكون المشرف (المدير العام أو مدير الفرع) فتح
        // له الوردية مباشرة من لوحة الإدارة وهو مقفول تطبيقه وقتها، فمينفعش
        // نعتمد على العلم المحلي بس ونورّيه شاشة "فتح وردية" غلط
        if (!shiftActive) {
          shiftActive = await _adoptRemoteShiftIfAny(widget.session!['pilotId']!);
        }
        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => shiftActive
            ? HomeScreen(
                username: widget.session!['username']!,
                name:     widget.session!['name']!,
                pilotId:  widget.session!['pilotId']!,
              )
            : ShiftScreen(
                username: widget.session!['username']!,
                name:     widget.session!['name']!,
                pilotId:  widget.session!['pilotId']!,
              )),
        );
      } else {
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => const LoginScreen()),
        );
      }
    });
  }

  /// يتأكد هل فيه وردية اتفتحت لهذا الطيار عن بُعد من لوحة الإدارة/الفرع
  /// بينما التطبيق كان مقفول، ولو لقى، يتبنّاها محليًا عشان الطيار يدخل
  /// على شاشة الطلبات مباشرة بدل ما يفضل شايف شاشة "فتح وردية" وهو أصلاً شغّال
  Future<bool> _adoptRemoteShiftIfAny(String pilotId) async {
    if (pilotId.isEmpty) return false;
    try {
      final pilot = await FB.getPilotRaw(pilotId);
      final status = pilot?['pilotStatus']?.toString() ?? '';
      if (status != 'waiting' && status != 'delivering' && status != 'onLeave') return false;
      final shift = await FB.findActiveShiftForPilot(pilotId);
      if (shift == null) return false;
      final startedAt = DateTime.tryParse(shift['startedAt']?.toString() ?? '') ?? DateTime.now();
      await ShiftService.adoptShift(shift['id'].toString(), startedAt);
      return true;
    } catch (_) {
      return false;
    }
  }

  @override void dispose() { _ctrl.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: K.bg,
      body: Center(
        child: FadeTransition(
          opacity: _fade,
          child: ScaleTransition(
            scale: _scale,
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              Image.asset('assets/logo.png', width: 200, height: 200),
              const SizedBox(height: 10),
              const Text('نظام إدارة التوصيل',
                  style: TextStyle(fontSize: 14, color: K.grey, letterSpacing: .5)),
              const SizedBox(height: 52),
              SizedBox(
                width: 28, height: 28,
                child: CircularProgressIndicator(
                    color: K.red.withValues(alpha: .5), strokeWidth: 2),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  SHIFT SCREEN — شاشة بداية الوردية
// ════════════════════════════════════════════════════════════════
class ShiftScreen extends StatefulWidget {
  final String username, name, pilotId;
  const ShiftScreen({super.key, required this.username, required this.name, required this.pilotId});
  @override State<ShiftScreen> createState() => _ShiftScreenState();
}

class _ShiftScreenState extends State<ShiftScreen> with SingleTickerProviderStateMixin {
  bool _loading = false;
  bool _pending = false;
  bool _rejected = false;
  String? _reqId;
  Timer? _pollTimer;
  Timer? _remoteCheckTimer;
  late final AnimationController _pulseCtrl;
  late final Animation<double> _pulseAnim;

  @override
  void initState() {
    super.initState();
    _pulseCtrl = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 2),
    )..repeat(reverse: true);
    _pulseAnim = Tween<double>(begin: 1.0, end: 1.07).animate(
      CurvedAnimation(parent: _pulseCtrl, curve: Curves.easeInOut),
    );
    // تحقق دوري: لو المشرف (المدير العام أو مدير الفرع) فتح الوردية
    // مباشرة من لوحة الإدارة وهو واقف على الشاشة دي، لازم ننقله تلقائيًا
    // لشاشة الطلبات من غير ما يحتاج يبعت طلب فتح وردية بنفسه أصلاً
    _remoteCheckTimer = Timer.periodic(const Duration(seconds: 4), (_) => _checkRemoteShiftOpen());
  }

  Future<void> _checkRemoteShiftOpen() async {
    if (!mounted || _loading) return;
    try {
      final pilot = await FB.getPilotRaw(widget.pilotId);
      final status = pilot?['pilotStatus']?.toString() ?? '';
      if (status != 'waiting' && status != 'delivering' && status != 'onLeave') return;
      final shift = await FB.findActiveShiftForPilot(widget.pilotId);
      if (shift == null) return;
      final startedAt = DateTime.tryParse(shift['startedAt']?.toString() ?? '') ?? DateTime.now();
      await ShiftService.adoptShift(shift['id'].toString(), startedAt);
      _pollTimer?.cancel();
      _remoteCheckTimer?.cancel();
      if (!mounted) return;
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(
          builder: (_) => HomeScreen(
            username: widget.username,
            name:     widget.name,
            pilotId:  widget.pilotId,
          ),
        ),
      );
    } catch (_) {
      // نتجاهل أخطاء الشبكة العابرة ونحاول مرة أخرى في الدورة القادمة
    }
  }

  @override
  void dispose() {
    _pulseCtrl.dispose();
    _pollTimer?.cancel();
    _remoteCheckTimer?.cancel();
    super.dispose();
  }

  /// إرسال طلب فتح وردية جديدة — لا تبدأ الوردية فعلياً إلا بعد موافقة الفرع/الإدارة
  Future<void> _startShift() async {
    setState(() { _loading = true; _rejected = false; });
    try {
      final pilot = await FB.getPilot(widget.pilotId);
      final reqId = await FB.requestShiftStart(
        pilotId: widget.pilotId,
        pilotName: widget.name,
        branchId: pilot?.assignedBranchId ?? '',
        branchName: '',
      );
      if (reqId.isEmpty) {
        if (mounted) setState(() => _loading = false);
        return;
      }
      _reqId = reqId;
      if (!mounted) return;
      setState(() { _loading = false; _pending = true; });
      _pollTimer = Timer.periodic(const Duration(seconds: 3), (_) => _checkShiftRequest());
      _checkShiftRequest();
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _checkShiftRequest() async {
    final reqId = _reqId;
    if (reqId == null) return;
    try {
      final data = await FB.getShiftRequest(reqId);
      final status = (data?['status'] ?? 'pending').toString();
      if (status == 'approved') {
        _pollTimer?.cancel();
        _remoteCheckTimer?.cancel();
        await ShiftService.startShift(widget.pilotId, widget.name);
        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(
            builder: (_) => HomeScreen(
              username: widget.username,
              name:     widget.name,
              pilotId:  widget.pilotId,
            ),
          ),
        );
      } else if (status == 'rejected') {
        _pollTimer?.cancel();
        if (mounted) setState(() { _pending = false; _reqId = null; _rejected = true; });
      }
    } catch (_) {
      // نتجاهل أخطاء الشبكة العابرة ونحاول مرة أخرى في الدورة القادمة
    }
  }

  Future<void> _logout() async {
    await Session.clear();
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: K.bg,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 28),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              const SizedBox(height: 52),

              // ── الشعار ──
              Center(
                child: Image.asset('assets/logo.png', width: 130, height: 130),
              ),
              const SizedBox(height: 28),

              // ── ترحيب ──
              Text('مرحباً، ${widget.name}',
                style: const TextStyle(
                  color: K.white, fontSize: 22, fontWeight: FontWeight.w800),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(
                _pending
                    ? 'تم إرسال طلب فتح الوردية للفرع…\nبانتظار الموافقة'
                    : _rejected
                        ? 'تم رفض طلب فتح الوردية من الفرع\nيمكنك إعادة المحاولة'
                        : 'اضغط على الزر أدناه لإرسال طلب فتح وردية\nلن تبدأ الوردية إلا بعد موافقة الفرع',
                style: TextStyle(
                  color: _rejected ? K.red : K.grey, fontSize: 14, height: 1.6),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 60),

              // ── زر بدء الوردية (دائري نابض) ──
              Center(
                child: ScaleTransition(
                  scale: _pulseAnim,
                  child: GestureDetector(
                    onTap: (_loading || _pending) ? null : _startShift,
                    child: Container(
                      width: 180, height: 180,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: RadialGradient(
                          colors: [
                            K.red,
                            K.red.withValues(alpha: .75),
                          ],
                        ),
                        boxShadow: [
                          BoxShadow(
                            color: K.red.withValues(alpha: .45),
                            blurRadius: 40,
                            spreadRadius: 8,
                          ),
                        ],
                      ),
                      child: (_loading || _pending)
                        ? Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              const CircularProgressIndicator(
                                  color: Colors.white, strokeWidth: 3),
                              if (_pending) ...[
                                const SizedBox(height: 10),
                                const Text('بانتظار الموافقة',
                                    style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700)),
                              ],
                            ],
                          )
                        : const Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.play_arrow_rounded,
                                  color: Colors.white, size: 60),
                              SizedBox(height: 6),
                              Text('طلب فتح الوردية',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 16,
                                  fontWeight: FontWeight.w800,
                                  letterSpacing: .5,
                                )),
                            ],
                          ),
                    ),
                  ),
                ),
              ),

              const Spacer(),

              // ── تسجيل الخروج ──
              TextButton.icon(
                onPressed: _logout,
                icon: const Icon(Icons.logout_rounded, color: K.grey, size: 18),
                label: const Text('تسجيل الخروج',
                    style: TextStyle(color: K.grey, fontSize: 13)),
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  LOGIN SCREEN
// ════════════════════════════════════════════════════════════════
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override State<LoginScreen> createState() => _LoginState();
}

class _LoginState extends State<LoginScreen> {
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  bool _loading = false, _hidePass = true;
  String _error = '';

  @override void dispose() { _userCtrl.dispose(); _passCtrl.dispose(); super.dispose(); }

  Future<void> _login() async {
    final user = _userCtrl.text.trim();
    final pass = _passCtrl.text.trim();
    if (user.isEmpty || pass.isEmpty) {
      setState(() => _error = 'يرجى إدخال اسم المستخدم وكلمة المرور');
      return;
    }
    setState(() { _loading = true; _error = ''; });
    try {
      final data = await FB.get('users/$user');
      if (data == null) { _setErr('اسم المستخدم غير موجود'); return; }
      if (data['password'] != pass) { _setErr('كلمة المرور غير صحيحة'); return; }
      final role = data['role']?.toString() ?? '';
      if (!['طيار','سائق'].contains(role)) { _setErr('هذا الحساب ليس لطيار'); return; }

      final name    = data['name']?.toString()    ?? user;
      final pilotId = data['pilotId']?.toString() ?? '';
      await Session.save(username: user, name: name, pilotId: pilotId);

      if (!mounted) return;
      Navigator.pushReplacement(context, MaterialPageRoute(
          builder: (_) => ShiftScreen(username: user, name: name, pilotId: pilotId)));
    } on TimeoutException { _setErr('انتهت مهلة الاتصال'); }
    catch (_) { _setErr('خطأ في الاتصال بالإنترنت'); }
  }

  void _setErr(String m) { if (mounted) setState(() { _error = m; _loading = false; }); }

  @override
  Widget build(BuildContext context) {
    final screenH = MediaQuery.of(context).size.height;
    final logoH   = screenH * 0.52;

    return Scaffold(
      backgroundColor: K.bg,
      resizeToAvoidBottomInset: true,
      body: Stack(
        children: [

          // ════ طبقة 1 (سفلى) — خلفية سوداء + الصورة محافظة على ملامحها ════
          Positioned(
            top: 0, left: 0, right: 0,
            height: logoH,
            child: Stack(fit: StackFit.expand, children: [
              // خلفية سوداء خلف الصورة
              const ColoredBox(color: Colors.black),
              // الصورة كاملة بدون قطع — fitWidth تملأ العرض وتحافظ على النسبة
              Image.asset(
                'assets/logo.png',
                fit: BoxFit.contain,
                alignment: Alignment.topCenter,
              ),
              // تدرج ناعم أسفل الصورة للدمج مع الفورم
              Positioned(
                bottom: 0, left: 0, right: 0, height: 120,
                child: Container(
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [Colors.transparent, Color(0xE5080808)],
                    ),
                  ),
                ),
              ),
            ]),
          ),

          // ════ طبقة 2 (عليا) — الفورم يتسكرول فوق الصورة ════
          SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // مساحة شفافة بحجم الصورة — الفورم يبدأ من تحتها
                SizedBox(height: logoH - 36),

                // كونتينر الفورم — خلفية شفافة عشان الصورة تبان من تحت
                Container(
                  width: double.infinity,
                  decoration: const BoxDecoration(
                    color: Colors.transparent,
                    borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
                  ),
                  padding: const EdgeInsets.fromLTRB(24, 28, 24, 40),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Text('تسجيل الدخول',
                        style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: K.white)),
                    const SizedBox(height: 4),
                    const Text('أدخل بيانات حسابك للمتابعة',
                        style: TextStyle(fontSize: 13, color: K.grey)),
                    const SizedBox(height: 28),

                    TField(ctrl: _userCtrl, hint: 'اسم المستخدم',
                        icon: Icons.person_outline_rounded, ltr: true,
                        action: TextInputAction.next),
                    const SizedBox(height: 14),
                    TField(
                      ctrl: _passCtrl, hint: 'كلمة المرور',
                      icon: Icons.lock_outline_rounded, ltr: true,
                      obscure: _hidePass, action: TextInputAction.done,
                      onSubmit: (_) => _login(),
                      suffix: IconButton(
                        icon: Icon(_hidePass ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                            color: K.grey, size: 20),
                        onPressed: () => setState(() => _hidePass = !_hidePass),
                      ),
                    ),

                    if (_error.isNotEmpty) ...[
                      const SizedBox(height: 14),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        decoration: BoxDecoration(
                          color: K.red.withValues(alpha: .08),
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: K.red.withValues(alpha: .25)),
                        ),
                        child: Row(children: [
                          const Icon(Icons.error_outline, color: K.red, size: 16),
                          const SizedBox(width: 8),
                          Expanded(child: Text(_error,
                              style: const TextStyle(color: K.red, fontSize: 13))),
                        ]),
                      ),
                    ],
                    const SizedBox(height: 28),

                    SizedBox(
                      width: double.infinity, height: 52,
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: K.red, foregroundColor: K.white,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                          elevation: 0,
                        ),
                        onPressed: _loading ? null : _login,
                        child: _loading
                            ? const SizedBox(width: 22, height: 22,
                                child: CircularProgressIndicator(color: K.white, strokeWidth: 2))
                            : const Text('تسجيل الدخول',
                                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                      ),
                    ),
                    const SizedBox(height: 14),
                    Center(
                      child: TextButton(
                        onPressed: () {},
                        child: const Text('نسيت كلمة المرور؟',
                            style: TextStyle(color: K.grey, fontSize: 13)),
                      ),
                    ),
                  ]),
                ),
              ],
            ),
          ),

        ],
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  IDLE OVERLAY — شاشة السكون بعد عدم التفاعل
// ════════════════════════════════════════════════════════════════
class IdleOverlay extends StatefulWidget {
  final Widget child;
  /// المدة قبل ظهور شاشة السكون (افتراضي: 60 ثانية)
  final Duration idleTimeout;
  const IdleOverlay({super.key, required this.child, this.idleTimeout = const Duration(seconds: 60)});
  @override State<IdleOverlay> createState() => _IdleOverlayState();
}

class _IdleOverlayState extends State<IdleOverlay> with SingleTickerProviderStateMixin {
  Timer? _idleTimer;
  bool _isIdle = false;
  late final AnimationController _fadeCtrl;
  late final Animation<double> _fadeAnim;

  @override
  void initState() {
    super.initState();
    _fadeCtrl = AnimationController(vsync: this, duration: const Duration(milliseconds: 600));
    _fadeAnim = CurvedAnimation(parent: _fadeCtrl, curve: Curves.easeInOut);
    _resetTimer();
  }

  void _resetTimer() {
    _idleTimer?.cancel();
    _idleTimer = Timer(widget.idleTimeout, _goIdle);
  }

  void _goIdle() {
    if (!mounted) return;
    setState(() => _isIdle = true);
    _fadeCtrl.forward();
  }

  void _wakeUp() {
    if (!_isIdle) return;
    _fadeCtrl.reverse().then((_) {
      if (mounted) setState(() => _isIdle = false);
    });
    _resetTimer();
  }

  void _onUserInteraction([_]) {
    if (_isIdle) {
      _wakeUp();
    } else {
      _resetTimer();
    }
  }

  @override
  void dispose() {
    _idleTimer?.cancel();
    _fadeCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.translucent,
      onTap: _onUserInteraction,
      onPanDown: _onUserInteraction,
      child: Stack(
        children: [
          widget.child,
          if (_isIdle)
            FadeTransition(
              opacity: _fadeAnim,
              child: GestureDetector(
                behavior: HitTestBehavior.opaque,
                onTap: _wakeUp,
                onPanDown: (_) => _wakeUp(),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    // خلفية سوداء خلف الصورة
                    const ColoredBox(color: Colors.black),
                    // الصورة تملأ الشاشة كاملة بدون أي نص
                    Image.asset('assets/logo.png', fit: BoxFit.contain),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  HOME SCREEN — Sidebar Navigation
// ════════════════════════════════════════════════════════════════
class HomeScreen extends StatefulWidget {
  final String username, name, pilotId;
  const HomeScreen({super.key, required this.username, required this.name, required this.pilotId});
  @override State<HomeScreen> createState() => _HomeState();
}

class _HomeState extends State<HomeScreen> with WidgetsBindingObserver {
  int _tab = 0;
  Timer? _refreshTimer;
  int _refreshTick = 0;    // عدّاد دورات التحديث لتوزيع النداءات
  Set<String> _knownOrderIds = {};
  bool _firstLoad = true;
  bool _gpsBusy = false;   // منع التزاحم على إرسال الموقع

  // ── حالة الإذن/الاستراحة المُزامَنة من Firebase ──
  bool _onBreak = false;
  String? _leaveType;
  String? _leaveReason;
  bool _leaveForced = false; // إيقاف مفروض من الإدارة/الفرع — الطيار ميقدرش ينهيه

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // إذن الموقع بيتطلب داخل _initFgService (قبل تشغيل خدمة نوع location)،
    // وأول إرسال فعلي للموقع بيحصل من مؤقّت التحديث بعد منح الإذن
    _loadKnownIdsFromPrefs();
    _loadOrders();
    // توفير بطارية/بيانات: بنوزّع النداءات بدل ما نعملها كلها كل دورة —
    // الأوردرات كل 8ث (استجابة سريعة كفاية)، مزامنة حالة الطيار كل 16ث،
    // والتأكد إن الخدمة الخلفية شغّالة كل 32ث
    _refreshTimer = Timer.periodic(const Duration(seconds: 8), (_) {
      if (!mounted) return;
      _refreshTick++;
      _loadOrders();
      _touchForegroundHeartbeat(); // نقول للخدمة الخلفية إن التطبيق لسه مفتوح
      _sendLocation(); // مُقيّد داخليًا بمرة كل 60ث
      if (_refreshTick % 2 == 0) _syncPilotStatus();
      if (_refreshTick % 4 == 0) _ensureFgServiceAlive();
    });
    // الطيار فتح التطبيق فعلياً — نوقف رنين أي طلب كان مستني فورًا
    _onAppForeground();
    // تشغيل Foreground Service للخلفية
    _initFgService();
  }

  /// مراقبة دورة حياة التطبيق — أي رجوع للواجهة يوقف الرنين الخلفي فورًا،
  /// وأي خروج للخلفية يسمح للخدمة الخلفية ترن من جديد على أي طلب جديد
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    super.didChangeAppLifecycleState(state);
    if (state == AppLifecycleState.resumed) {
      _onAppForeground();
    } else if (state == AppLifecycleState.paused ||
               state == AppLifecycleState.detached) {
      _markAppBackground();
    }
  }

  /// التطبيق بقى قدام الطيار: نرفع فلاج "مفتوح"، نمسح قائمة الرنين، نلغي
  /// إشعار الرنين الثابت، ونبعت إشارة فورية للخدمة الخلفية توقف النغمة
  /// في نفس اللحظة (من غير ما نستنى دورتها الجاية)
  Future<void> _onAppForeground() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('app_in_foreground', true);
    await prefs.setInt('app_fg_ts', DateTime.now().millisecondsSinceEpoch);
    await prefs.remove('pending_ring_ids');
    await LocalNotif.cancelRingingNotification();
    LocalNotif.clearAll();
    try { FlutterForegroundTask.sendDataToTask('stop_ring'); } catch (_) {}
  }

  /// التطبيق راح للخلفية: نخفض الفلاج عشان الخدمة الخلفية ترجع ترن على أي
  /// طلب جديد يوصل والطيار مش شايف
  Future<void> _markAppBackground() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('app_in_foreground', false);
    await prefs.setInt('app_fg_ts', 0);
  }

  /// إشارة حياة: بتتبعت كل دورة تحديث وإحنا مفتوحين، عشان الخدمة الخلفية
  /// تعرف إن التطبيق **فعلاً** شغّال. لو التطبيق اتقفل بالعافية (force-close
  /// أو أندرويد قفله) الإشارة بتتوقف وخلال أقل من دقيقة الخدمة ترجع ترن.
  Future<void> _touchForegroundHeartbeat() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setInt('app_fg_ts', DateTime.now().millisecondsSinceEpoch);
    } catch (_) {}
  }

  /// مزامنة حالة الطيار مع Firebase — لاكتشاف: (1) موافقة/رفض/إنهاء
  /// الإذن من الفرع أو الإدارة، (2) إنهاء الوردية عن بُعد من المشرف
  Future<void> _syncPilotStatus() async {
    if (widget.pilotId.isEmpty) return;
    try {
      // 1) هل أُنهيت الوردية عن بُعد من لوحة الإدارة/الفرع؟
      final shiftId = await ShiftService.currentShiftId();
      if (shiftId.isNotEmpty) {
        final shiftData = await FB.getShiftRaw(shiftId);
        final shiftStatus = shiftData?['status']?.toString() ?? 'active';
        if (shiftStatus == 'ended') {
          await ShiftService.endShiftLocalOnly();
          if (!mounted) return;
          Navigator.pushReplacement(context, MaterialPageRoute(
            builder: (_) => ShiftScreen(username: widget.username, name: widget.name, pilotId: widget.pilotId),
          ));
          return;
        }
      }
      // 2) حالة الإذن/الاستراحة
      final pilot = await FB.getPilotRaw(widget.pilotId);
      if (pilot == null) return;
      final status = pilot['pilotStatus']?.toString() ?? '';
      final serverBreakIso = pilot['breakStartedAt']?.toString();
      final localBreakStart = await ShiftService.breakStartedAt();
      if (status == 'onLeave' && serverBreakIso != null && serverBreakIso.isNotEmpty) {
        if (localBreakStart == null) {
          final parsed = DateTime.tryParse(serverBreakIso) ?? DateTime.now();
          await ShiftService.startBreak(parsed);
        }
        if (mounted) setState(() {
          _onBreak = true;
          _leaveType = pilot['leaveType']?.toString();
          _leaveReason = pilot['leaveReason']?.toString();
          _leaveForced = pilot['leaveForced'] == true; // إيقاف إجباري من الإدارة/الفرع
        });
      } else {
        if (localBreakStart != null) await ShiftService.endBreak();
        if (mounted && _onBreak) setState(() { _onBreak = false; _leaveType = null; _leaveReason = null; _leaveForced = false; });
      }
    } catch (_) {
      // نتجاهل أخطاء الشبكة العابرة
    }
  }

  /// الطيار يضغط "عدت للعمل" — ينهي الإذن ويستأنف عداد الوردية
  Future<void> _endBreak() async {
    try {
      await FB.endLeave(widget.pilotId);
      await ShiftService.endBreak();
      if (mounted) setState(() { _onBreak = false; _leaveType = null; _leaveReason = null; });
    } catch (_) {}
  }

  /// فتح نافذة طلب إذن (استراحة / عطلة‑انصراف / حادث)
  Future<void> _openLeaveRequestDialog() async {
    String selectedType = 'rest';
    final reasonCtrl = TextEditingController();
    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          backgroundColor: K.card,
          title: const Text('طلب إذن', style: TextStyle(color: K.white)),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('نوع الإذن', style: TextStyle(color: K.grey, fontSize: 13)),
              const SizedBox(height: 8),
              Wrap(spacing: 8, runSpacing: 8, children: [
                _leaveTypeChip('rest', 'استراحة', selectedType, (v) => setDialogState(() => selectedType = v)),
                _leaveTypeChip('dayoff', 'عطلة / انصراف', selectedType, (v) => setDialogState(() => selectedType = v)),
                _leaveTypeChip('incident', 'حادث', selectedType, (v) => setDialogState(() => selectedType = v)),
              ]),
              const SizedBox(height: 16),
              const Text('السبب', style: TextStyle(color: K.grey, fontSize: 13)),
              const SizedBox(height: 8),
              TextField(
                controller: reasonCtrl,
                maxLines: 3,
                style: const TextStyle(color: K.white),
                decoration: InputDecoration(
                  hintText: 'اكتب سبب الإذن…',
                  hintStyle: const TextStyle(color: K.grey),
                  filled: true, fillColor: K.card2,
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide.none),
                ),
              ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء', style: TextStyle(color: K.grey))),
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx, true),
              style: ElevatedButton.styleFrom(backgroundColor: K.orange),
              child: const Text('إرسال الطلب', style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );
    if (result != true) return;
    try {
      final pilot = await FB.getPilot(widget.pilotId);
      await FB.requestLeave(
        pilotId: widget.pilotId,
        pilotName: widget.name,
        branchId: pilot?.assignedBranchId ?? '',
        branchName: '',
        type: selectedType,
        reason: reasonCtrl.text.trim(),
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تم إرسال طلب الإذن — بانتظار موافقة الفرع')));
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تعذّر إرسال الطلب — تحقق من الاتصال بالإنترنت')));
      }
    }
  }

  Widget _leaveTypeChip(String value, String label, String selected, void Function(String) onSelect) {
    final isSelected = value == selected;
    return GestureDetector(
      onTap: () => onSelect(value),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: isSelected ? K.orange.withValues(alpha: .18) : K.card2,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: isSelected ? K.orange : K.border),
        ),
        child: Text(label, style: TextStyle(color: isSelected ? K.orange : K.grey, fontSize: 13, fontWeight: FontWeight.w600)),
      ),
    );
  }

  /// فحص دوري (كل 5 ثواني بينما التطبيق مفتوح): لو أندرويد قفل خدمة الخلفية
  /// من غير علم المستخدم (بيحصل في أجهزة معينة بسبب توفير البطارية)، نعيد تشغيلها فورًا
  Future<void> _ensureFgServiceAlive() async {
    try {
      final running = await FlutterForegroundTask.isRunningService;
      if (!running) await FgService.start();
    } catch (_) {}
  }

  Future<void> _initFgService() async {
    // مهم: نطلب إذن الموقع الأول ونستناه قبل تشغيل الخدمة، لأن الخدمة من
    // نوع "location"، وأندرويد 14+ بيرمي استثناء لو اتشغّلت قبل منح الإذن
    await _ensureLocationPermission();
    // طلب إذن الإشعارات (Android 13+)
    await FlutterForegroundTask.requestNotificationPermission();
    // طلب استثناء التطبيق من تحسين البطارية — أهم خطوة عشان أندرويد
    // (خصوصاً شاومي/هواوي/أوبو/سامسونج) ميقفلش خدمة الخلفية ويمنع التنبيهات
    try {
      final isIgnoring = await FlutterForegroundTask.isIgnoringBatteryOptimizations;
      if (!isIgnoring) {
        await FlutterForegroundTask.requestIgnoreBatteryOptimization();
      }
    } catch (_) {}
    // حفظ pilotId للـ TaskHandler
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('pilotId', widget.pilotId);
    // تشغيل الخدمة
    await FgService.start();
  }

  /// نحمّل الـ IDs المحفوظة من Workmanager عشان ما نعيد إشعار لأوردر قديم
  Future<void> _loadKnownIdsFromPrefs() async {
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getStringList('known_order_ids') ?? [];
    if (mounted && saved.isNotEmpty) {
      _knownOrderIds = saved.toSet();
    }
  }

  /// يجيب الأوردرات ويقارن بالمعروفة — لو في جديد يطلع إشعار
  Future<void> _loadOrders() async {
    if (widget.pilotId.isEmpty) return;
    try {
      final orders = await FB.myActiveOrders(widget.pilotId);
      final newIds = orders.map((o) => o.id).toSet();

      if (!_firstLoad) {
        final added = newIds.difference(_knownOrderIds);
        if (added.isNotEmpty && mounted) {
          // ─── أوردر جديد! — أضفه للوردية ───
          for (final id in added) {
            await ShiftService.addOrder(id);
          }
          // التطبيق مفتوح قدام الطيار فعلاً — مفيش داعي لرنين مستمر (ده
          // بيحصل بس والتطبيق مقفول). هنا يكفي تنبيه صوتي لمرة واحدة + بانر،
          // والطيار شايف الطلب على طول. الرنين الخلفي المستمر بيتولّاه
          // OrderRinger من الخدمة الخلفية لما التطبيق يكون مقفول/في الخلفية
          NotificationService.playNewOrder();
          _showNewOrderBanner(added.length);
        }
      } else {
        // أول تحميل — أضف الأوردرات النشطة الحالية للوردية
        for (final o in orders) {
          await ShiftService.addOrder(o.id);
        }
      }

      _firstLoad = false;
      _knownOrderIds = newIds;

      // حفظ الـ IDs لمزامنة Workmanager
      final prefs = await SharedPreferences.getInstance();
      await prefs.setStringList('known_order_ids', newIds.toList());
      await prefs.setString('pilotId', widget.pilotId);

      if (mounted) setState(() {});
    } catch (_) {}
  }

  /// بانر أعلى الشاشة عند وصول أوردر جديد
  void _showNewOrderBanner(int count) {
    final msg = count == 1 ? '🚀 لديك طلب توصيل جديد!' : '🚀 لديك $count طلبات جديدة!';
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Row(children: [
          const Icon(Icons.notifications_active_rounded, color: Colors.white, size: 20),
          const SizedBox(width: 10),
          Text(msg, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
        ]),
        backgroundColor: K.red,
        duration: const Duration(seconds: 5),
        behavior: SnackBarBehavior.floating,
        margin: const EdgeInsets.all(12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        action: SnackBarAction(
          label: 'عرض',
          textColor: Colors.white,
          onPressed: () => setState(() => _tab = 0),
        ),
      ),
    );
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    // التطبيق بيتقفل — نخفض الفلاج عشان الخدمة الخلفية ترجع ترن على الطلبات
    _markAppBackground();
    _refreshTimer?.cancel();
    NotificationService.dispose();
    super.dispose();
  }

  /// طلب إذن الموقع مرة واحدة عند فتح الشاشة (لا نشغّل stream مستمر عشان
  /// البطارية — الإرسال بقى دوري كل 60ث عبر LocationSender من مؤقّت التحديث)
  Future<void> _ensureLocationPermission() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return;
      var perm = await Geolocator.checkPermission();
      if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
    } catch (_) {}
  }

  /// إرسال موقع الطيار (foreground) — مُقيّد داخليًا بمرة كل 60ث ولو اتحرّك
  /// فعلاً، فمفيش استنزاف حتى لو اتنادى كل دورة تحديث
  Future<void> _sendLocation() async {
    if (_gpsBusy) return;
    _gpsBusy = true;
    try { await LocationSender.maybeSend(widget.pilotId); } catch (_) {}
    _gpsBusy = false;
  }

  /// إنهاء الوردية فقط (بدون خروج من الحساب)
  Future<void> _endShift() async {
    final ok = await _confirm(
      context,
      'إنهاء الوردية',
      'هل تريد إنهاء الوردية الحالية؟\nستخرج من قائمة فرعك الحالي وتصبح متاحًا لفتح وردية في أي فرع آخر.\nلن تُحذف الطلبات المنجزة.',
      K.orange,
      'إنهاء الوردية',
    );
    if (!ok) return;
    await ShiftService.endShift(widget.pilotId);
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(
        builder: (_) => ShiftScreen(
          username: widget.username,
          name:     widget.name,
          pilotId:  widget.pilotId,
        ),
      ),
    );
  }

  Future<void> _logout() async {
    final ok = await _confirm(context, 'تسجيل الخروج', 'هل تريد الخروج من حسابك؟', K.red, 'خروج');
    if (!ok) return;
    _refreshTimer?.cancel();
    await FgService.stop();
    await ShiftService.endShift(widget.pilotId);
    await Session.clear();
    await IncomingCallService.endAllCalls();
    if (!mounted) return;
    Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => const LoginScreen()));
  }

  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();

  @override
  Widget build(BuildContext context) {
    final tabs = [
      OrdersTab(
        pilotId: widget.pilotId, name: widget.name, refreshKey: _knownOrderIds.length, scaffoldKey: _scaffoldKey,
        onBreak: _onBreak, leaveType: _leaveType, leaveReason: _leaveReason, leaveForced: _leaveForced,
        onEndBreak: _endBreak, onRequestLeave: _openLeaveRequestDialog,
      ),
      CompletedTab(pilotId: widget.pilotId),
      AccountTab(name: widget.name, pilotId: widget.pilotId, onLogout: _logout),
    ];

    const tabLabels = ['طلباتي', 'المنتهية', 'الحساب'];
    const tabIcons  = [
      Icons.receipt_long_rounded,
      Icons.check_circle_rounded,
      Icons.person_rounded,
    ];

    return PopScope(
      // منع الخروج التلقائي من التطبيق بزر الرجوع
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_tab != 0) {
          // لو في تاب ثاني → ارجع للتاب الأول
          setState(() => _tab = 0);
        } else {
          // لو في التاب الأول → اخرج من التطبيق
          SystemNavigator.pop();
        }
      },
      child: IdleOverlay(
      idleTimeout: const Duration(seconds: 60),
      child: Scaffold(
      key: _scaffoldKey,
      backgroundColor: K.bg,
      drawer: Drawer(
        backgroundColor: K.card,
        width: 260,
        child: SafeArea(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.fromLTRB(20, 24, 20, 20),
              decoration: const BoxDecoration(
                border: Border(bottom: BorderSide(color: K.border)),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Container(
                  width: 56, height: 56,
                  decoration: BoxDecoration(
                    color: K.red.withValues(alpha: .12),
                    shape: BoxShape.circle,
                    border: Border.all(color: K.red, width: 1.5),
                  ),
                  child: const Icon(Icons.person_rounded, color: K.red, size: 30),
                ),
                const SizedBox(height: 12),
                Text(widget.name,
                    style: const TextStyle(color: K.white,
                        fontSize: 16, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4),
                const Text('طيار توصيل',
                    style: TextStyle(color: K.grey, fontSize: 12)),
              ]),
            ),
            const SizedBox(height: 8),
            for (int i = 0; i < 3; i++)
              _DrawerItem(
                icon: tabIcons[i],
                label: tabLabels[i],
                selected: _tab == i,
                onTap: () {
                  setState(() => _tab = i);
                  Navigator.pop(context);
                },
              ),
            const Divider(color: K.border, height: 24),
            _DrawerItem(
              icon: Icons.music_note_outlined,
              label: 'نغمة الإشعارات',
              selected: false,
              onTap: () {
                Navigator.pop(context);
                Navigator.push(context,
                    MaterialPageRoute(builder: (_) => const SoundSettingsScreen()));
              },
            ),
            const Spacer(),
            // ── طلب إذن (استراحة / عطلة / حادث) ──
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
              child: ListTile(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                tileColor: K.red.withValues(alpha: .08),
                leading: const Icon(Icons.pan_tool_outlined, color: K.red, size: 20),
                title: const Text('طلب إذن',
                    style: TextStyle(color: K.red, fontSize: 14, fontWeight: FontWeight.w600)),
                onTap: () { Navigator.pop(context); _openLeaveRequestDialog(); },
              ),
            ),
            // ── إنهاء الوردية ──
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
              child: ListTile(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                tileColor: K.orange.withValues(alpha: .10),
                leading: const Icon(Icons.stop_circle_outlined, color: K.orange, size: 20),
                title: const Text('إنهاء الوردية',
                    style: TextStyle(color: K.orange, fontSize: 14, fontWeight: FontWeight.w600)),
                onTap: () { Navigator.pop(context); _endShift(); },
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 16),
              child: ListTile(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                tileColor: K.red.withValues(alpha: .08),
                leading: const Icon(Icons.logout_rounded, color: K.red, size: 20),
                title: const Text('تسجيل الخروج',
                    style: TextStyle(color: K.red, fontSize: 14, fontWeight: FontWeight.w600)),
                onTap: () { Navigator.pop(context); _logout(); },
              ),
            ),
            const Padding(
              padding: EdgeInsets.only(bottom: 12),
              child: Center(
                child: Text('الإصدار 1.0.0',
                    style: TextStyle(color: K.grey, fontSize: 11)),
              ),
            ),
          ]),
        ),
      ),
      body: IndexedStack(index: _tab, children: tabs),
      bottomNavigationBar: Container(
        decoration: const BoxDecoration(
          color: K.card,
          border: Border(top: BorderSide(color: K.border)),
        ),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: List.generate(3, (i) {
                final selected = _tab == i;
                return GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTap: () => setState(() => _tab = i),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
                    decoration: BoxDecoration(
                      color: selected ? K.red.withValues(alpha: .12) : Colors.transparent,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Icon(tabIcons[i],
                          color: selected ? K.red : K.grey,
                          size: 22),
                      const SizedBox(height: 3),
                      Text(tabLabels[i],
                          style: TextStyle(
                            color: selected ? K.red : K.grey,
                            fontSize: 10,
                            fontWeight: selected ? FontWeight.w700 : FontWeight.normal,
                          )),
                    ]),
                  ),
                );
              }),
            ),
          ),
        ),
      ),
    ),  // Scaffold
    ),  // IdleOverlay
    );  // PopScope
  }
}

// ─── Drawer Item ─────────────────────────────────────────────────
class _DrawerItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;
  const _DrawerItem({required this.icon, required this.label,
      required this.selected, required this.onTap});
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
    child: ListTile(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      tileColor: selected ? K.red.withValues(alpha: .12) : Colors.transparent,
      leading: Icon(icon, color: selected ? K.red : K.grey, size: 20),
      title: Text(label,
          style: TextStyle(
              color: selected ? K.red : K.white,
              fontSize: 14,
              fontWeight: selected ? FontWeight.w700 : FontWeight.normal)),
      onTap: onTap,
    ),
  );
}


// ════════════════════════════════════════════════════════════════
//  ORDERS TAB — طلباتي الحالية
// ════════════════════════════════════════════════════════════════
class OrdersTab extends StatefulWidget {
  final String pilotId, name;
  final int refreshKey;
  final GlobalKey<ScaffoldState> scaffoldKey;
  final bool onBreak;
  final String? leaveType;
  final String? leaveReason;
  final bool leaveForced; // إيقاف مفروض من الإدارة/الفرع
  final VoidCallback? onEndBreak;
  final VoidCallback? onRequestLeave;
  const OrdersTab({
    super.key, required this.pilotId, required this.name, required this.refreshKey, required this.scaffoldKey,
    this.onBreak = false, this.leaveType, this.leaveReason, this.leaveForced = false, this.onEndBreak, this.onRequestLeave,
  });
  @override State<OrdersTab> createState() => _OrdersTabState();
}

class _OrdersTabState extends State<OrdersTab> {
  List<OrderModel> _orders = [];
  bool _loading = true;
  bool _fetching = false;
  Timer? _autoRefresh;
  Timer? _clockTimer;
  DateTime? _shiftStart;
  Duration _shiftElapsed = Duration.zero;
  Set<String> _shiftIds = {};

  @override
  void initState() {
    super.initState();
    _loadShiftInfo();
    _load(showSpinner: true);
    _autoRefresh = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) {
        _load();
        _tickClock();
      }
    });
    _clockTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  Future<void> _loadShiftInfo() async {
    final start = await ShiftService.startedAt();
    final ids   = await ShiftService.shiftOrderIds();
    if (mounted) setState(() { _shiftStart = start; _shiftIds = ids; });
  }

  void _tickClock() {
    ShiftService.elapsed().then((e) {
      if (mounted) setState(() => _shiftElapsed = e);
    });
  }

  String _fmtDuration(Duration d) {
    final h = d.inHours.toString().padLeft(2, '0');
    final m = (d.inMinutes % 60).toString().padLeft(2, '0');
    final s = (d.inSeconds % 60).toString().padLeft(2, '0');
    return '$h:$m:$s';
  }

  String _leaveLabel(String? type) {
    switch (type) {
      case 'rest':     return 'في استراحة';
      case 'dayoff':   return 'في عطلة / انصراف';
      case 'incident': return 'بلاغ حادث';
      default:         return 'في إذن';
    }
  }

  @override
  void didUpdateWidget(OrdersTab old) {
    super.didUpdateWidget(old);
    if (old.refreshKey != widget.refreshKey) _load();
  }

  @override
  void dispose() {
    _autoRefresh?.cancel();
    _clockTimer?.cancel();
    super.dispose();
  }

  Future<void> _load({bool showSpinner = false}) async {
    if (_fetching) return;
    _fetching = true;
    if (showSpinner && mounted) setState(() => _loading = true);
    try {
      // جلب أوردرات الوردية من ShiftService
      final ids  = await ShiftService.shiftOrderIds();
      final list = await FB.myActiveOrders(widget.pilotId);
      // نضيف الأوردرات الحالية للوردية تلقائياً
      for (final o in list) { await ShiftService.addOrder(o.id); }
      final updatedIds = await ShiftService.shiftOrderIds();
      if (mounted) setState(() {
        _orders  = list;
        _shiftIds = updatedIds;
        _loading = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    } finally {
      _fetching = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    // بمجرد ما الطلب يوصل للطيار يبقى بالفعل بدأ في توصيله — كل الطلبات
    // النشطة تُحسب "جاري التوصيل" من غير مراحل وسيطة
    final started = _orders.length;
    _tickClock();

    return Scaffold(
      backgroundColor: K.bg,
      appBar: _AppBar(
        title: 'طلباتي الحالية',
        leading: const Icon(Icons.menu_rounded, color: K.white),
        onLeadingTap: () => widget.scaffoldKey.currentState?.openDrawer(),
        trailing: const Icon(Icons.notifications_outlined, color: K.white),
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(showSpinner: true), color: K.red, backgroundColor: K.card2,
        child: CustomScrollView(slivers: [

          // ── شريط الوردية ──
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: Container(
                decoration: BoxDecoration(
                  color: (widget.onBreak ? K.red : K.green).withValues(alpha: .08),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: (widget.onBreak ? K.red : K.green).withValues(alpha: .3)),
                ),
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                child: Column(children: [
                  Row(children: [
                    Container(
                      width: 8, height: 8,
                      decoration: BoxDecoration(color: widget.onBreak ? K.red : K.green, shape: BoxShape.circle),
                    ),
                    const SizedBox(width: 8),
                    Text(widget.onBreak ? _leaveLabel(widget.leaveType) : 'الوردية نشطة',
                        style: TextStyle(color: widget.onBreak ? K.red : K.green, fontSize: 12, fontWeight: FontWeight.w700)),
                    const Spacer(),
                    Icon(Icons.timer_outlined, color: widget.onBreak ? K.red : K.green, size: 14),
                    const SizedBox(width: 4),
                    Text(_fmtDuration(_shiftElapsed),
                        style: TextStyle(color: widget.onBreak ? K.red : K.green, fontSize: 13,
                            fontWeight: FontWeight.w800, fontFeatures: const [FontFeature.tabularFigures()])),
                  ]),
                  if (widget.onBreak) ...[
                    if ((widget.leaveReason ?? '').isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: Align(
                          alignment: Alignment.centerRight,
                          child: Text('السبب: ${widget.leaveReason}',
                              style: const TextStyle(color: K.grey, fontSize: 12)),
                        ),
                      ),
                    // لو الإيقاف مفروض من الإدارة/الفرع: الطيار ميقدرش يرجّع
                    // نفسه — بيظهر تنبيه بس، والعودة بتكون من لوحة الإدارة/الفرع
                    if (widget.leaveForced)
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Container(
                          width: double.infinity,
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                          decoration: BoxDecoration(
                            color: K.red.withValues(alpha: .12),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: K.red.withValues(alpha: .3)),
                          ),
                          child: Row(children: const [
                            Icon(Icons.lock_outline_rounded, color: K.red, size: 16),
                            SizedBox(width: 8),
                            Expanded(child: Text(
                              'تم إيقافك من الإدارة/الفرع — العودة للعمل بتكون من عندهم',
                              style: TextStyle(color: K.red, fontSize: 12, fontWeight: FontWeight.w600),
                            )),
                          ]),
                        ),
                      )
                    else
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: SizedBox(
                          width: double.infinity, height: 38,
                          child: ElevatedButton.icon(
                            onPressed: widget.onEndBreak,
                            icon: const Icon(Icons.play_arrow_rounded, size: 18),
                            label: const Text('عدت للعمل'),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: K.green, foregroundColor: Colors.white,
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                            ),
                          ),
                        ),
                      ),
                  ] else
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: SizedBox(
                        width: double.infinity, height: 34,
                        child: OutlinedButton.icon(
                          onPressed: widget.onRequestLeave,
                          icon: const Icon(Icons.pan_tool_outlined, size: 16),
                          label: const Text('طلب إذن (استراحة / عطلة / حادث)', style: TextStyle(fontSize: 12)),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: K.orange, side: BorderSide(color: K.orange.withValues(alpha: .5)),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                          ),
                        ),
                      ),
                    ),
                ]),
              ),
            ),
          ),

          // ── Stats ──
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
              child: Container(
                decoration: BoxDecoration(
                    color: K.card, borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: K.border)),
                padding: const EdgeInsets.symmetric(vertical: 14),
                child: Row(children: [
                  Expanded(
                    child: StatCell(value: '$started', label: 'جاري التوصيل', color: K.red),
                  ),
                ]),
              ),
            ),
          ),

          // ── Reorder button (لو أكثر من طلب) ──
          if (_orders.length > 1)
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: K.grey,
                    side: const BorderSide(color: K.border),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    padding: const EdgeInsets.symmetric(vertical: 10),
                  ),
                  onPressed: () async {
                    await Navigator.push(context, MaterialPageRoute(
                        builder: (_) => ReorderScreen(orders: _orders)));
                    _load();
                  },
                  icon: const Icon(Icons.swap_vert_rounded, size: 18),
                  label: const Text('ترتيب التوصيل', style: TextStyle(fontSize: 13)),
                ),
              ),
            ),

          // ── Orders list ──
          if (_loading)
            const SliverFillRemaining(
                child: Center(child: CircularProgressIndicator(color: K.red)))
          else if (_orders.isEmpty)
            SliverFillRemaining(
              child: Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                Icon(Icons.inbox_outlined, size: 64, color: K.grey.withValues(alpha: .4)),
                const SizedBox(height: 14),
                const Text('لا يوجد طلبات حالياً',
                    style: TextStyle(color: K.grey, fontSize: 16)),
                const SizedBox(height: 6),
                const Text('يتحدث تلقائياً كل ثانية',
                    style: TextStyle(color: K.grey, fontSize: 12)),
                const SizedBox(height: 20),
                ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(backgroundColor: K.red, foregroundColor: K.white),
                  onPressed: _load,
                  icon: const Icon(Icons.refresh, size: 16),
                  label: const Text('تحديث الآن'),
                ),
              ])),
            )
          else
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
              sliver: SliverList(
                delegate: SliverChildBuilderDelegate(
                  (_, idx) {
                    final o = _orders[idx];
                    return OrderCard(
                      order: o,
                      index: idx + 1,
                      onTap: () async {
                        await Navigator.push(context, MaterialPageRoute(
                            builder: (_) => OrderDetailScreen(
                                orderId: o.id, pilotId: widget.pilotId)));
                        _load();
                      },
                    );
                  },
                  childCount: _orders.length,
                ),
              ),
            ),
        ]),
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  ORDER CARD — كارت الطلب مع العداد
// ════════════════════════════════════════════════════════════════
class OrderCard extends StatefulWidget {
  final OrderModel order;
  final int index;
  final VoidCallback onTap;
  const OrderCard({super.key, required this.order, required this.index, required this.onTap});
  @override State<OrderCard> createState() => _OrderCardState();
}

class _OrderCardState extends State<OrderCard> {
  Timer? _timer;
  Duration _elapsed = Duration.zero;

  @override
  void initState() {
    super.initState();
    _tick();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) { if (mounted) { _tick(); setState(() {}); } });
  }

  void _tick() {
    final start = widget.order.timerStart;
    _elapsed = start != null ? DateTime.now().difference(start) : Duration.zero;
  }

  @override void dispose() { _timer?.cancel(); super.dispose(); }

  String _fmt(Duration d) {
    final h = d.inHours.toString().padLeft(2, '0');
    final m = (d.inMinutes % 60).toString().padLeft(2, '0');
    final s = (d.inSeconds % 60).toString().padLeft(2, '0');
    return '$h:$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    final o       = widget.order;
    // بمجرد ما الطلب يوصل للطيار يبقى بالفعل بدأ في توصيله
    const started = true;
    const statusLabel = 'جاري التوصيل';
    const statusColor = K.red;

    return GestureDetector(
      onTap: widget.onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        decoration: BoxDecoration(
          color: K.card,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
              color: started ? K.red.withValues(alpha: .25) : K.border),
        ),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

            // ── Row 1: order# + status ──
            Row(children: [
              Text('#${o.orderNum}',
                  style: const TextStyle(color: K.white, fontSize: 15, fontWeight: FontWeight.w800)),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Container(width: 6, height: 6,
                      decoration: BoxDecoration(
                          color: statusColor, shape: BoxShape.circle)),
                  const SizedBox(width: 5),
                  Text(statusLabel,
                      style: TextStyle(color: statusColor, fontSize: 11,
                          fontWeight: FontWeight.w700)),
                ]),
              ),
            ]),
            const SizedBox(height: 12),

            // ── Row 2: from→to + box ──
            Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    const Icon(Icons.circle, size: 7, color: K.orange),
                    const SizedBox(width: 7),
                    const Text('من: ', style: TextStyle(color: K.grey, fontSize: 12)),
                    Expanded(
                      child: Text(o.senderName,
                          style: const TextStyle(color: K.white, fontSize: 13,
                              fontWeight: FontWeight.w600),
                          overflow: TextOverflow.ellipsis),
                    ),
                  ]),
                  Padding(
                    padding: const EdgeInsets.only(right: 3),
                    child: Container(
                        height: 14, width: 1,
                        margin: const EdgeInsets.symmetric(vertical: 2),
                        color: K.border),
                  ),
                  Row(children: [
                    const Icon(Icons.location_on_rounded, size: 12, color: K.red),
                    const SizedBox(width: 5),
                    const Text('إلى: ', style: TextStyle(color: K.grey, fontSize: 12)),
                    Expanded(
                      child: Text(o.displayZone,
                          style: const TextStyle(color: K.white, fontSize: 13,
                              fontWeight: FontWeight.w600),
                          overflow: TextOverflow.ellipsis),
                    ),
                  ]),
                ]),
              ),
              const SizedBox(width: 12),
              Column(children: [
                const Icon(Icons.inventory_2_outlined, color: K.orange, size: 30),
                const SizedBox(height: 4),
                Text('${o.parcelCount} قطعة',
                    style: const TextStyle(color: K.grey, fontSize: 10)),
              ]),
            ]),

            const SizedBox(height: 12),
            Divider(color: K.border, height: 1),
            const SizedBox(height: 10),

            // ── Row 3: collection + timer ──
            Row(children: [
              const Icon(Icons.payments_outlined, size: 14, color: K.green),
              const SizedBox(width: 5),
              Text('${o.totalDeliveryPrice.toStringAsFixed(0)} ج.م',
                  style: const TextStyle(color: K.green, fontSize: 13,
                      fontWeight: FontWeight.w700)),
              if (o.storePrepaid > 0) ...[
                const SizedBox(width: 12),
                const Icon(Icons.store_outlined, size: 14, color: K.orange),
                const SizedBox(width: 4),
                Text('${o.storePrepaid.toStringAsFixed(0)} ج.م',
                    style: const TextStyle(color: K.orange, fontSize: 12,
                        fontWeight: FontWeight.w600)),
              ],
              const Spacer(),
              Row(children: [
                const Icon(Icons.timer_outlined, size: 13, color: K.red),
                const SizedBox(width: 4),
                Text(
                  _fmt(_elapsed),
                  style: const TextStyle(color: K.red, fontSize: 13,
                      fontWeight: FontWeight.w700, letterSpacing: .5),
                  textDirection: TextDirection.ltr,
                ),
              ]),
            ]),
          ]),
        ),
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  COMPLETED TAB — الطلبات المنتهية
// ════════════════════════════════════════════════════════════════
class CompletedTab extends StatefulWidget {
  final String pilotId;
  const CompletedTab({super.key, required this.pilotId});
  @override State<CompletedTab> createState() => _CompletedState();
}

class _CompletedState extends State<CompletedTab> {
  List<OrderModel> _orders = [];
  bool _loading = true;
  bool _fetching = false;
  Timer? _autoRefresh;

  @override
  void initState() {
    super.initState();
    _load(showSpinner: true);
    _autoRefresh = Timer.periodic(const Duration(seconds: 5), (_) {
      if (mounted) _load();
    });
  }

  @override
  void dispose() {
    _autoRefresh?.cancel();
    super.dispose();
  }

  Future<void> _load({bool showSpinner = false}) async {
    if (_fetching) return;
    _fetching = true;
    if (showSpinner && mounted) setState(() => _loading = true);
    try {
      // وقت بداية الوردية الحالية
      final shiftStart = await ShiftService.startedAt();
      final allCompleted = await FB.myCompletedOrders(widget.pilotId);

      List<OrderModel> shiftCompleted;
      if (shiftStart == null) {
        // لا توجد وردية نشطة → لا تُظهر أي أوردرات
        shiftCompleted = [];
      } else {
        // فلتر مزدوج: الوقت + قائمة IDs
        final shiftIds = await ShiftService.shiftOrderIds();
        shiftCompleted = allCompleted.where((o) {
          // أولاً: لو الـ ID موجود في قائمة الوردية → أظهره دائماً
          if (shiftIds.contains(o.id)) return true;
          // ثانياً: اعتمد على وقت إنهاء الأوردر
          final timeStr = o.deliveredAt.isNotEmpty ? o.deliveredAt : o.statusSince;
          if (timeStr.isEmpty) return false;
          final orderTime = DateTime.tryParse(timeStr);
          if (orderTime == null) return false;
          // أظهر الأوردر فقط إذا أُنجز بعد بداية الوردية الحالية
          return orderTime.isAfter(shiftStart);
        }).toList();
      }

      if (mounted) setState(() { _orders = shiftCompleted; _loading = false; });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    } finally {
      _fetching = false;
    }
  }

  String _formatTime(String iso) {
    if (iso.isEmpty) return '—';
    final dt = DateTime.tryParse(iso);
    if (dt == null) return '—';
    final h = dt.hour > 12 ? dt.hour - 12 : (dt.hour == 0 ? 12 : dt.hour);
    final m = dt.minute.toString().padLeft(2, '0');
    final period = dt.hour < 12 ? 'ص' : 'م';
    return '$h:$m $period';
  }

  @override
  Widget build(BuildContext context) {
    final delivered = _orders.where((o) => o.status == 'تم التسليم').toList();
    final total     = delivered.fold<double>(0, (s, o) => s + o.totalDeliveryPrice);

    return Scaffold(
      backgroundColor: K.bg,
      appBar: _AppBar(
        title: 'الطلبات المنتهية',
        trailing: IconButton(icon: const Icon(Icons.refresh, color: K.white), onPressed: () => _load(showSpinner: true)),
      ),
      body: _loading
        ? const Center(child: CircularProgressIndicator(color: K.red))
        : CustomScrollView(slivers: [

          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
              child: Container(
                decoration: BoxDecoration(color: K.card,
                    borderRadius: BorderRadius.circular(14), border: Border.all(color: K.border)),
                padding: const EdgeInsets.symmetric(vertical: 14),
                child: Row(children: [
                  StatCell(value: '${_orders.length}', label: 'إجمالي الطلبات', color: K.white),
                  VSep(),
                  StatCell(value: '${total.toStringAsFixed(0)} ج', label: 'إجمالي التحصيل', color: K.green),
                  VSep(),
                  StatCell(value: '${delivered.length}', label: 'تم التسليم', color: K.green),
                ]),
              ),
            ),
          ),

          if (_orders.isEmpty)
            const SliverFillRemaining(
              child: Center(child: Text('لا يوجد طلبات منتهية',
                  style: TextStyle(color: K.grey, fontSize: 15))))
          else
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
              sliver: SliverList(
                delegate: SliverChildBuilderDelegate((_, i) {
                  final o        = _orders[i];
                  final ok       = o.status == 'تم التسليم';
                  final clr      = ok ? K.green : K.red;
                  return Container(
                    margin: const EdgeInsets.only(bottom: 10),
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: K.card,
                      borderRadius: BorderRadius.circular(13),
                      border: Border.all(color: K.border),
                    ),
                    child: Row(children: [
                      Container(
                        width: 42, height: 42,
                        decoration: BoxDecoration(
                            color: clr.withValues(alpha: .1),
                            borderRadius: BorderRadius.circular(11)),
                        child: Icon(ok ? Icons.check_circle_outline : Icons.cancel_outlined,
                            color: clr, size: 22),
                      ),
                      const SizedBox(width: 12),
                      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Text('#${o.orderNum}',
                              style: const TextStyle(color: K.white,
                                  fontWeight: FontWeight.w700, fontSize: 14)),
                          const Spacer(),
                          _Badge(label: o.status, color: clr),
                        ]),
                        const SizedBox(height: 4),
                        Text(o.displayZone.isNotEmpty ? o.displayZone : o.senderName,
                            style: const TextStyle(color: K.grey, fontSize: 12),
                            overflow: TextOverflow.ellipsis),
                        const SizedBox(height: 4),
                        Row(children: [
                          Text('${o.totalDeliveryPrice.toStringAsFixed(0)} ج.م',
                              style: const TextStyle(color: K.green,
                                  fontWeight: FontWeight.w700, fontSize: 13)),
                          const Spacer(),
                          Text(_formatTime(o.deliveredAt),
                              style: const TextStyle(color: K.grey, fontSize: 11)),
                        ]),
                      ])),
                    ]),
                  );
                }, childCount: _orders.length),
              ),
            ),
        ]),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  ACCOUNT TAB — الحساب
// ════════════════════════════════════════════════════════════════
class AccountTab extends StatefulWidget {
  final String name, pilotId;
  final VoidCallback onLogout;
  const AccountTab({super.key, required this.name, required this.pilotId, required this.onLogout});
  @override State<AccountTab> createState() => _AccountState();
}

class _AccountState extends State<AccountTab> {
  int _total = 0, _delivered = 0, _active = 0;
  bool _fetching = false;   // منع التزاحم
  Timer? _autoRefresh;

  @override
  void initState() {
    super.initState();
    _load();
    // تحديث تلقائي كل ثانية
    _autoRefresh = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) _load();
    });
  }

  @override
  void dispose() {
    _autoRefresh?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    if (_fetching) return;
    _fetching = true;
    try {
      final raw = await FB.get('orders');
      if (raw == null || !mounted) return;
      final all = (raw as Map<String, dynamic>)
          .entries.where((e) => e.value is Map && e.value['pilotId'] == widget.pilotId).toList();
      setState(() {
        _total     = all.length;
        _delivered = all.where((e) => e.value['status'] == 'تم التسليم').length;
        _active    = all.where((e) => e.value['status'] == 'جاري التوصيل').length;
      });
    } catch (_) {} finally {
      _fetching = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: K.bg,
      appBar: _AppBar(
        title: 'الحساب',
        trailing: IconButton(icon: const Icon(Icons.settings_outlined, color: K.white), onPressed: () {}),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(children: [
          // Profile
          Container(
            width: 88, height: 88,
            decoration: BoxDecoration(
              color: K.card2,
              shape: BoxShape.circle,
              border: Border.all(color: K.red, width: 2),
            ),
            child: const Icon(Icons.person_rounded, size: 46, color: K.grey),
          ),
          const SizedBox(height: 12),
          Text(widget.name,
              style: const TextStyle(color: K.white, fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          _Badge(label: 'طيار توصيل', color: K.red),
          const SizedBox(height: 22),

          // Stats
          Container(
            decoration: BoxDecoration(color: K.card,
                borderRadius: BorderRadius.circular(14), border: Border.all(color: K.border)),
            padding: const EdgeInsets.symmetric(vertical: 14),
            child: Row(children: [
              StatCell(value: '$_total',     label: 'إجمالي الطلبات', color: K.white),
              VSep(),
              StatCell(value: '$_delivered', label: 'تم التسليم',     color: K.green),
              VSep(),
              StatCell(value: '$_active',    label: 'جاري التوصيل',   color: K.red),
            ]),
          ),
          const SizedBox(height: 16),

          // Menu
          _CardSection(children: [
            _MenuTile(Icons.person_outline_rounded,    'المعلومات الشخصية', () {
              Navigator.push(context, MaterialPageRoute(
                builder: (_) => PersonalInfoScreen(
                  pilotId: widget.pilotId,
                  name:    widget.name,
                )));
            }),
            const Divider(color: K.border, height: 1),
            _MenuTile(Icons.notifications_outlined,    'نغمة الإشعارات', () {
              Navigator.push(context, MaterialPageRoute(
                builder: (_) => const SoundSettingsScreen()));
            }),
          ]),
          const SizedBox(height: 12),

          _CardSection(children: [
            _MenuTile(Icons.logout_rounded, 'تسجيل الخروج', widget.onLogout, isRed: true),
          ]),
          const SizedBox(height: 24),
          const Text('الإصدار 1.0.0', style: TextStyle(color: K.grey, fontSize: 12)),
          const SizedBox(height: 40),
        ]),
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  PERSONAL INFO SCREEN — المعلومات الشخصية
// ════════════════════════════════════════════════════════════════
class PersonalInfoScreen extends StatefulWidget {
  final String pilotId, name;
  const PersonalInfoScreen({super.key, required this.pilotId, required this.name});
  @override State<PersonalInfoScreen> createState() => _PersonalInfoState();
}

class _PersonalInfoState extends State<PersonalInfoScreen> {
  PilotModel? _pilot;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final p = await FB.getPilot(widget.pilotId);
      if (mounted) setState(() { _pilot = p; _loading = false; });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: K.bg,
      appBar: _AppBar(title: 'المعلومات الشخصية'),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: K.red))
          : SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

                // ── بيانات الحساب ──
                _labelText('بيانات الحساب'),
                const SizedBox(height: 10),
                _CardSection(children: [
                  _InfoTile(Icons.person_outline_rounded,    'الاسم',        widget.name),
                  if (_pilot != null) ...[
                    const Divider(color: K.border, height: 1),
                    _InfoTile(Icons.phone_outlined,          'الهاتف',        _pilot!.phone1),
                    const Divider(color: K.border, height: 1),
                    _InfoTile(Icons.directions_car_outlined, 'رقم المركبة',  _pilot!.vehicleNo),
                    const Divider(color: K.border, height: 1),
                    _InfoTile(Icons.credit_card_outlined,    'رقم البطاقة',  _pilot!.cardNum),
                    const Divider(color: K.border, height: 1),
                    _InfoTile(Icons.location_on_outlined,    'العنوان',       _pilot!.address),
                  ],
                ]),
                const SizedBox(height: 28),

                // ── الدعم والمساعدة ──
                _labelText('الدعم والمساعدة'),
                const SizedBox(height: 10),
                _CardSection(children: [
                  _MenuTile(Icons.help_outline_rounded,        'الأسئلة الشائعة',  () {}),
                  const Divider(color: K.border, height: 1),
                  _MenuTile(Icons.chat_bubble_outline_rounded, 'تواصل مع الدعم',   () {}),
                  const Divider(color: K.border, height: 1),
                  _MenuTile(Icons.info_outline_rounded,        'عن التطبيق', () {
                    showAboutDialog(
                      context: context,
                      applicationName: 'طيّار',
                      applicationVersion: '1.0.0',
                      applicationLegalese: '© 2024 نظام إدارة التوصيل',
                    );
                  }),
                ]),
                const SizedBox(height: 40),
              ]),
            ),
    );
  }

  Widget _labelText(String text) => Text(text,
      style: const TextStyle(color: K.grey, fontSize: 12,
          fontWeight: FontWeight.w600, letterSpacing: .5));
}

// ════════════════════════════════════════════════════════════════
//  SOUND SETTINGS SCREEN — اختيار نغمة الإشعارات
// ════════════════════════════════════════════════════════════════
class SoundSettingsScreen extends StatefulWidget {
  const SoundSettingsScreen({super.key});
  @override State<SoundSettingsScreen> createState() => _SoundSettingsState();
}

class _SoundSettingsState extends State<SoundSettingsScreen> {
  String _selected = '';
  String? _previewing;   // الصوت اللي بيتشغّل دلوقتي

  @override
  void initState() {
    super.initState();
    NotificationService.getSelectedSound().then((v) {
      if (mounted) setState(() => _selected = v);
    });
  }

  Future<void> _pick(SoundOption opt) async {
    // معاينة فورية
    setState(() => _previewing = opt.id);
    await NotificationService.preview(opt.id);
    if (!mounted) return;
    setState(() => _previewing = null);
  }

  Future<void> _save(String soundId) async {
    await NotificationService.setSelectedSound(soundId);
    if (!mounted) return;
    setState(() => _selected = soundId);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: const Row(children: [
          Icon(Icons.check_circle_outline, color: Colors.white, size: 18),
          SizedBox(width: 8),
          Text('تم حفظ نغمة الإشعار', style: TextStyle(fontWeight: FontWeight.w600)),
        ]),
        backgroundColor: K.green,
        behavior: SnackBarBehavior.floating,
        margin: const EdgeInsets.all(12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        duration: const Duration(seconds: 2),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: K.bg,
      appBar: _AppBar(title: 'نغمة الإشعارات'),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // ── شرح ──
          Container(
            margin: const EdgeInsets.only(bottom: 20),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: K.red.withValues(alpha: .08),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: K.red.withValues(alpha: .2)),
            ),
            child: const Row(children: [
              Icon(Icons.info_outline_rounded, color: K.red, size: 18),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'اضغط على النغمة للمعاينة، ثم اضغط "اختيار" لحفظها',
                  style: TextStyle(color: K.grey, fontSize: 13),
                ),
              ),
            ]),
          ),

          // ── قائمة النغمات ──
          ...kSoundOptions.map((opt) {
            final isSelected  = _selected == opt.id;
            final isPreviewing = _previewing == opt.id;

            return GestureDetector(
              onTap: () => _pick(opt),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 200),
                margin: const EdgeInsets.only(bottom: 10),
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                decoration: BoxDecoration(
                  color: isSelected ? K.red.withValues(alpha: .1) : K.card,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(
                    color: isSelected ? K.red.withValues(alpha: .5) : K.border,
                    width: isSelected ? 1.5 : 1,
                  ),
                ),
                child: Row(children: [
                  // أيقونة الصوت
                  Container(
                    width: 44, height: 44,
                    decoration: BoxDecoration(
                      color: isSelected
                          ? K.red.withValues(alpha: .15)
                          : K.card2,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Center(
                      child: isPreviewing
                          ? SizedBox(
                              width: 20, height: 20,
                              child: CircularProgressIndicator(
                                color: K.red, strokeWidth: 2),
                            )
                          : Text(opt.emoji, style: const TextStyle(fontSize: 22)),
                    ),
                  ),
                  const SizedBox(width: 14),

                  // اسم النغمة
                  Expanded(child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(opt.label,
                          style: TextStyle(
                            color: isSelected ? K.red : K.white,
                            fontSize: 15,
                            fontWeight: isSelected ? FontWeight.w700 : FontWeight.w500,
                          )),
                      const SizedBox(height: 2),
                      Text(isPreviewing ? 'جاري التشغيل...' : 'اضغط للمعاينة',
                          style: const TextStyle(color: K.grey, fontSize: 11)),
                    ],
                  )),

                  // زر الاختيار / علامة الاختيار
                  if (isSelected)
                    const Icon(Icons.check_circle_rounded, color: K.red, size: 22)
                  else
                    GestureDetector(
                      onTap: () => _save(opt.id),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
                        decoration: BoxDecoration(
                          color: K.card2,
                          borderRadius: BorderRadius.circular(20),
                          border: Border.all(color: K.border),
                        ),
                        child: const Text('اختيار',
                            style: TextStyle(
                              color: K.white, fontSize: 12, fontWeight: FontWeight.w600)),
                      ),
                    ),
                ]),
              ),
            );
          }),

          const SizedBox(height: 20),

          // ── زر حفظ النغمة الحالية المعاينة ──
          if (_selected.isNotEmpty) ...[
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: K.card,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: K.border),
              ),
              child: Row(children: [
                const Icon(Icons.music_note_rounded, color: K.green, size: 18),
                const SizedBox(width: 10),
                Expanded(child: Text(
                  'النغمة الحالية: ${kSoundOptions.firstWhere((o) => o.id == _selected, orElse: () => kSoundOptions.first).label}',
                  style: const TextStyle(color: K.white, fontSize: 13),
                )),
                GestureDetector(
                  onTap: () => NotificationService.preview(_selected),
                  child: const Icon(Icons.play_circle_outline_rounded,
                      color: K.green, size: 28),
                ),
              ]),
            ),
          ],
          const SizedBox(height: 40),
        ],
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  ORDER DETAIL SCREEN — تفاصيل الطلب
// ════════════════════════════════════════════════════════════════
class OrderDetailScreen extends StatefulWidget {
  final String orderId, pilotId;
  const OrderDetailScreen({super.key, required this.orderId, required this.pilotId});
  @override State<OrderDetailScreen> createState() => _OrderDetailState();
}

class _OrderDetailState extends State<OrderDetailScreen> {
  OrderModel? _order;
  bool _loading = true, _processing = false;
  bool _fetching = false;   // منع التزاحم
  Timer? _autoRefresh;

  @override
  void initState() {
    super.initState();
    _load(showSpinner: true);
    // تحديث تلقائي كل 5 ثواني (بدل كل ثانية) — توفير بطارية/بيانات
    // مع بقاء الحالة محدّثة بشكل شبه فوري للمستخدم
    _autoRefresh = Timer.periodic(const Duration(seconds: 5), (_) {
      if (mounted) _load();
    });
  }

  @override
  void dispose() {
    _autoRefresh?.cancel();
    super.dispose();
  }

  Future<void> _load({bool showSpinner = false}) async {
    if (_fetching) return;
    _fetching = true;
    if (showSpinner && mounted) setState(() => _loading = true);
    try {
      final raw = await FB.get('orders/${widget.orderId}');
      if (raw != null && mounted) {
        setState(() {
          _order   = OrderModel.fromMap(widget.orderId, Map<String, dynamic>.from(raw));
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    } finally {
      _fetching = false;
    }
  }

  /// بمجرد ما الطلب يوصل للطيار يبقى بالفعل بدأ في توصيله، فمفيش داعي
  /// لخطوتي "استلام الطرد" و"بدء الرحلة" المنفصلتين — زر واحد بس لتأكيد
  /// التسليم يقوم بكل الخطوات دفعة واحدة
  Future<void> _markDelivered() async {
    if (_order == null) return;
    final ok = await _confirm(context, 'تأكيد التسليم',
        'هل تم تسليم الطلب للعميل بنجاح؟', K.green, 'تأكيد التسليم');
    if (!ok) return;
    setState(() => _processing = true);
    try {
      final o = _order!;
      if (!o.hasReceived) await FB.receiveOrder(o.id);
      if (!o.hasStarted) await FB.startTrip(o.id, alreadyReceived: true);
      await FB.markDelivered(o.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: const Text('✅ تم تسليم الطلب بنجاح'),
        backgroundColor: K.green,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ));
      Navigator.pop(context);
    } catch (_) {
      if (mounted) setState(() => _processing = false);
    }
  }

  /// طلب إرجاع الطلب — يفتح نافذة يكتب فيها الطيار السبب، ثم يرسل طلب إذن
  /// إرجاع للفرع والإدارة (بدل الإرجاع الفوري). عند الموافقة يتحوّل الأوردر
  /// تلقائيًا لـ"لم يتم التوصيل" من لوحة الفرع/الإدارة.
  Future<void> _requestReturnOrder() async {
    if (_order == null) return;
    final reasonCtrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: K.card,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('طلب إرجاع الطلب', style: TextStyle(color: K.white, fontSize: 17, fontWeight: FontWeight.w800)),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('اكتب سبب إرجاع الطلب، وهيتبعت للفرع والإدارة للموافقة عليه.',
              style: TextStyle(color: K.grey, fontSize: 13, height: 1.5)),
          const SizedBox(height: 14),
          TextField(
            controller: reasonCtrl,
            maxLines: 3,
            autofocus: true,
            style: const TextStyle(color: K.white),
            decoration: InputDecoration(
              hintText: 'مثال: العميل مش بيرد / العنوان غلط / العميل رفض الاستلام',
              hintStyle: const TextStyle(color: K.grey, fontSize: 12),
              filled: true, fillColor: K.card2,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide.none),
            ),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء', style: TextStyle(color: K.grey))),
          ElevatedButton(
            onPressed: () {
              if (reasonCtrl.text.trim().isEmpty) return;
              Navigator.pop(ctx, true);
            },
            style: ElevatedButton.styleFrom(backgroundColor: K.orange, foregroundColor: Colors.white),
            child: const Text('إرسال الطلب'),
          ),
        ],
      ),
    );
    if (ok != true) return;

    setState(() => _processing = true);
    try {
      final o = _order!;
      // نجيب فرع الطيار واسمه عشان الطلب يظهر في لوحة الفرع الصحيح
      final pilot = await FB.getPilot(widget.pilotId);
      final branchId = pilot?.assignedBranchId ?? '';
      final branchName = await FB.getBranchName(branchId);
      await FB.requestOrderReturn(
        orderId:    o.id,
        orderNum:   o.orderNum,
        pilotId:    widget.pilotId,
        pilotName:  pilot?.name ?? '',
        branchId:   branchId,
        branchName: branchName,
        reason:     reasonCtrl.text.trim(),
      );
      await _load(); // نحدّث الحالة فورًا لعرض "بانتظار الموافقة"
      if (!mounted) return;
      setState(() => _processing = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: const Text('📨 تم إرسال طلب الإرجاع — بانتظار موافقة الفرع/الإدارة'),
        backgroundColor: K.orange,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ));
    } catch (_) {
      if (mounted) setState(() => _processing = false);
    }
  }

  /// الطيار شاف إن طلب الإرجاع اترفض — نمسح العلامة عشان الأزرار ترجع طبيعية
  Future<void> _acknowledgeRejection() async {
    if (_order == null) return;
    setState(() => _processing = true);
    try {
      await FB.clearOrderReturnFlag(_order!.id);
      await _load();
    } catch (_) {}
    if (mounted) setState(() => _processing = false);
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(
          backgroundColor: K.bg,
          body: Center(child: CircularProgressIndicator(color: K.red)));
    }
    if (_order == null) {
      return Scaffold(
        backgroundColor: K.bg,
        appBar: _AppBar(title: 'تفاصيل الطلب'),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              const Icon(Icons.error_outline_rounded, color: K.grey, size: 48),
              const SizedBox(height: 16),
              const Text('تعذّر تحميل بيانات الطلب',
                  style: TextStyle(color: K.white, fontSize: 16, fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              const Text('تأكد من اتصالك بالإنترنت ثم حاول مرة أخرى',
                  style: TextStyle(color: K.grey, fontSize: 13), textAlign: TextAlign.center),
              const SizedBox(height: 24),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                    backgroundColor: K.red, foregroundColor: K.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
                onPressed: () => _load(showSpinner: true),
                icon: const Icon(Icons.refresh_rounded, size: 18),
                label: const Text('إعادة المحاولة'),
              ),
            ]),
          ),
        ),
      );
    }
    final o = _order!;

    return Scaffold(
      backgroundColor: K.bg,
      appBar: AppBar(
        backgroundColor: K.card,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_forward_ios, color: K.white, size: 18),
          onPressed: () => Navigator.pop(context),
        ),
        centerTitle: true,
        title: Text('#${o.orderNum}',
            style: const TextStyle(color: K.white, fontSize: 17, fontWeight: FontWeight.w800)),
        actions: [
          Padding(
            padding: const EdgeInsets.only(left: 12),
            child: _Badge(
              // بمجرد ما الطلب يوصل للطيار يبقى بالفعل بدأ في توصيله
              label: (o.status == 'تم التسليم' || o.status == 'لم يتم التوصيل')
                  ? o.status
                  : 'جاري التوصيل',
              color: (o.status == 'تم التسليم')
                  ? K.green
                  : (o.status == 'لم يتم التوصيل' ? K.orange : K.red),
            ),
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(children: [
          // ── Sender ──
          _Section(title: 'معلومات المرسل', icon: Icons.store_outlined, iconColor: K.orange,
            children: [
              _DRow(Icons.business_outlined,  o.senderName),
              if (o.senderPhone.isNotEmpty)
                _PhoneRow(o.senderPhone, label: 'رقم المرسل'),
              if (o.senderAddress.isNotEmpty)
                _DRow(Icons.location_on_outlined, o.senderAddress),
              if (o.branchName.isNotEmpty)
                _DRow(Icons.store_mall_directory_outlined, 'الفرع: ${o.branchName}'),
            ],
          ),
          const SizedBox(height: 12),

          // ── Deliveries ──
          for (int i = 0; i < o.deliveries.length; i++) ...[
            _Section(
              title: o.deliveries.length == 1 ? 'معلومات العميل' : 'العميل ${i + 1}',
              icon: Icons.person_outline_rounded, iconColor: K.blue,
              children: [
                if (o.deliveries[i].receiverName.isNotEmpty)
                  _DRow(Icons.person_outline_rounded, o.deliveries[i].receiverName),
                if (o.deliveries[i].receiverPhone.isNotEmpty)
                  _PhoneRow(o.deliveries[i].receiverPhone,
                      label: o.deliveries[i].receiverPhone2.isNotEmpty ? 'رقم 1' : null),
                if (o.deliveries[i].receiverPhone2.isNotEmpty)
                  _PhoneRow(o.deliveries[i].receiverPhone2, label: 'رقم 2'),
                if (o.deliveries[i].zoneName.isNotEmpty)
                  _DRow(Icons.map_outlined, 'المنطقة: ${o.deliveries[i].zoneName}'),
                if (o.deliveries[i].address.isNotEmpty)
                  _DRow(Icons.location_on_outlined, o.deliveries[i].address),
                if (o.deliveries[i].zonePrice > 0)
                  _DRow(Icons.payments_outlined,
                      'سعر المنطقة: ${o.deliveries[i].zonePrice.toStringAsFixed(0)} ج.م',
                      valueColor: K.green),
                if (o.deliveries[i].note.isNotEmpty)
                  _DRow(Icons.note_outlined, o.deliveries[i].note, valueColor: K.orange),
                // صور الإيصال/العنوان اللي المحل رفعها — اضغط للتكبير
                if (o.deliveries[i].images.isNotEmpty)
                  _ReceiptImages(urls: o.deliveries[i].images),
              ],
            ),
            const SizedBox(height: 12),
          ],

          // ── Order details ──
          _Section(title: 'تفاصيل الطلب', icon: Icons.receipt_long_outlined, iconColor: K.green,
            children: [
              _DRow(Icons.payments_outlined,
                  'قيمة التحصيل: ${o.totalDeliveryPrice.toStringAsFixed(0)} ج.م',
                  valueColor: K.green),
              _DRow(Icons.inventory_2_outlined, 'عدد القطع: ${o.parcelCount}'),
              if (o.storePrepaid > 0)
                _DRow(Icons.store_outlined,
                    'مدفوع مسبقاً: ${o.storePrepaid.toStringAsFixed(0)} ج.م',
                    valueColor: K.orange),
              if (o.notes.isNotEmpty)
                _DRow(Icons.note_alt_outlined, o.notes),
            ],
          ),
          const SizedBox(height: 28),

          // ── Actions ──
          // لا تظهر الأزرار إذا انتهت العملية
          if (o.status != 'تم التسليم' && o.status != 'لم يتم التوصيل') ...[
            // بمجرد ما الطلب يوصل للطيار يبقى بالفعل بدأ يوصّله —
            // زر واحد بس لتأكيد التسليم، من غير خطوات وسيطة
            SizedBox(
              width: double.infinity, height: 54,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                    backgroundColor: K.green, foregroundColor: K.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    elevation: 0),
                onPressed: _processing ? null : _markDelivered,
                icon: _processing
                    ? const SizedBox(width: 20, height: 20,
                        child: CircularProgressIndicator(color: K.white, strokeWidth: 2))
                    : const Icon(Icons.check_circle_outline, size: 24),
                label: const Text('تم توصيل الطلب',
                    style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
              ),
            ),
            const SizedBox(height: 10),

            // ── منطقة إرجاع الطلب (بإذن من الفرع/الإدارة) ──
            if (o.returnPending) ...[
              // طلب الإرجاع اتبعت ولسه بانتظار الموافقة
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 16),
                decoration: BoxDecoration(
                  color: K.orange.withValues(alpha: .08),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: K.orange.withValues(alpha: .35)),
                ),
                child: Row(children: [
                  const SizedBox(width: 20, height: 20,
                      child: CircularProgressIndicator(color: K.orange, strokeWidth: 2)),
                  const SizedBox(width: 12),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Text('طلب الإرجاع قيد المراجعة',
                        style: TextStyle(color: K.orange, fontSize: 14, fontWeight: FontWeight.w800)),
                    const SizedBox(height: 2),
                    const Text('بانتظار موافقة الفرع أو الإدارة',
                        style: TextStyle(color: K.grey, fontSize: 12)),
                    if (o.returnReason.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text('السبب: ${o.returnReason}',
                          style: const TextStyle(color: K.grey, fontSize: 12)),
                    ],
                  ])),
                ]),
              ),
            ] else if (o.returnRejected) ...[
              // الفرع/الإدارة رفض طلب الإرجاع
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 16),
                decoration: BoxDecoration(
                  color: K.red.withValues(alpha: .08),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: K.red.withValues(alpha: .35)),
                ),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: const [
                    Icon(Icons.cancel_rounded, color: K.red, size: 20),
                    SizedBox(width: 10),
                    Expanded(child: Text('تم رفض طلب الإرجاع',
                        style: TextStyle(color: K.red, fontSize: 14, fontWeight: FontWeight.w800))),
                  ]),
                  const SizedBox(height: 6),
                  const Text('برجاء متابعة توصيل الطلب، أو التواصل مع الفرع.',
                      style: TextStyle(color: K.grey, fontSize: 12)),
                ]),
              ),
              const SizedBox(height: 10),
              SizedBox(
                width: double.infinity, height: 46,
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                      foregroundColor: K.grey,
                      side: const BorderSide(color: K.border),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
                  onPressed: _processing ? null : _acknowledgeRejection,
                  icon: const Icon(Icons.check_rounded, size: 18),
                  label: const Text('حسنًا، متابعة التوصيل',
                      style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
                ),
              ),
            ] else ...[
              // الحالة العادية — زر طلب إرجاع الطلب
              SizedBox(
                width: double.infinity, height: 46,
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                      foregroundColor: K.orange,
                      side: const BorderSide(color: K.orange),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
                  onPressed: _processing ? null : _requestReturnOrder,
                  icon: const Icon(Icons.assignment_return_rounded, size: 18),
                  label: const Text('طلب إرجاع الطلب',
                      style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
                ),
              ),
            ],
            const SizedBox(height: 10),
          ] else ...[
          // بطاقة حالة الأوردر المنتهي
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(vertical: 18, horizontal: 16),
            decoration: BoxDecoration(
              color: (o.status == 'تم التسليم' ? K.green : K.orange).withValues(alpha: .08),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: (o.status == 'تم التسليم' ? K.green : K.orange).withValues(alpha: .3),
              ),
            ),
            child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              Icon(
                o.status == 'تم التسليم' ? Icons.check_circle_rounded : Icons.assignment_return_rounded,
                color: o.status == 'تم التسليم' ? K.green : K.orange,
                size: 22,
              ),
              const SizedBox(width: 10),
              Text(
                o.status == 'تم التسليم' ? 'تم تسليم هذا الطلب بنجاح' : 'تم إرجاع هذا الطلب',
                style: TextStyle(
                  color: o.status == 'تم التسليم' ? K.green : K.orange,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ]),
          ),
          const SizedBox(height: 10),
          ],

          SizedBox(
            width: double.infinity, height: 48,
            child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(
                  foregroundColor: K.grey,
                  side: const BorderSide(color: K.border),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
              onPressed: () async {
                // خذ أول عنوان توصيل، أو عنوان المرسل كـ fallback
                final addr = o.deliveries.isNotEmpty
                    ? (o.deliveries.first.address.isNotEmpty
                        ? o.deliveries.first.address
                        : o.deliveries.first.zoneName)
                    : o.senderAddress;
                if (addr.isEmpty) return;
                final encoded = Uri.encodeComponent(addr);
                // geo: scheme أولاً — يفتح تطبيق الخرائط مباشرةً
                final geoUri = Uri.parse('geo:0,0?q=$encoded');
                try {
                  final opened = await launchUrl(geoUri, mode: LaunchMode.externalApplication);
                  if (!opened) {
                    await launchUrl(Uri.parse('https://maps.google.com/?q=$encoded'),
                        mode: LaunchMode.externalApplication);
                  }
                } catch (_) {
                  try {
                    await launchUrl(Uri.parse('https://maps.google.com/?q=$encoded'),
                        mode: LaunchMode.externalApplication);
                  } catch (_) {}
                }
              },
              icon: const Icon(Icons.map_outlined, size: 18),
              label: const Text('فتح الموقع على الخريطة'),
            ),
          ),
          const SizedBox(height: 80),
        ]),
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  REORDER SCREEN — ترتيب التوصيل
// ════════════════════════════════════════════════════════════════
class ReorderScreen extends StatefulWidget {
  final List<OrderModel> orders;
  const ReorderScreen({super.key, required this.orders});
  @override State<ReorderScreen> createState() => _ReorderState();
}

class _ReorderState extends State<ReorderScreen> {
  late List<OrderModel> _list;
  bool _saving = false;

  @override void initState() { super.initState(); _list = List.from(widget.orders); }

  Future<void> _save() async {
    // لو محتاجين نحفظ الترتيب في Firebase نضيف هنا
    setState(() => _saving = true);
    await Future.delayed(const Duration(milliseconds: 400));
    if (mounted) { setState(() => _saving = false); Navigator.pop(context); }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: K.bg,
      appBar: AppBar(
        backgroundColor: K.card,
        surfaceTintColor: Colors.transparent, elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_forward_ios, color: K.white, size: 18),
          onPressed: () => Navigator.pop(context),
        ),
        centerTitle: true,
        title: const Text('ترتيب التوصيل',
            style: TextStyle(color: K.white, fontSize: 17, fontWeight: FontWeight.w700)),
      ),
      body: Column(children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: Text('اسحب لتغيير ترتيب التوصيل',
              style: TextStyle(color: K.grey, fontSize: 13)),
        ),
        Expanded(
          child: ReorderableListView.builder(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            itemCount: _list.length,
            onReorder: (a, b) {
              setState(() {
                if (b > a) b--;
                final item = _list.removeAt(a);
                _list.insert(b, item);
              });
            },
            itemBuilder: (_, i) {
              final o = _list[i];
              return Container(
                key: ValueKey(o.id),
                margin: const EdgeInsets.only(bottom: 10),
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                decoration: BoxDecoration(
                    color: K.card,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: K.border)),
                child: Row(children: [
                  Container(
                    width: 32, height: 32,
                    decoration: BoxDecoration(
                        color: K.red.withValues(alpha: .12),
                        borderRadius: BorderRadius.circular(8)),
                    child: Center(child: Text('${i + 1}',
                        style: const TextStyle(color: K.red,
                            fontWeight: FontWeight.w800, fontSize: 14))),
                  ),
                  const SizedBox(width: 12),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('#${o.orderNum}',
                        style: const TextStyle(color: K.white,
                            fontWeight: FontWeight.w700, fontSize: 14)),
                    const SizedBox(height: 3),
                    Text(o.displayZone,
                        style: const TextStyle(color: K.grey, fontSize: 12),
                        overflow: TextOverflow.ellipsis),
                  ])),
                  const Icon(Icons.drag_handle_rounded, color: K.grey, size: 22),
                ]),
              );
            },
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(16, 0, 16, MediaQuery.of(context).padding.bottom + 16),
          child: SizedBox(
            width: double.infinity, height: 52,
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                  backgroundColor: K.red, foregroundColor: K.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  elevation: 0),
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(width: 22, height: 22,
                      child: CircularProgressIndicator(color: K.white, strokeWidth: 2))
                  : const Text('حفظ الترتيب',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
            ),
          ),
        ),
      ]),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  SHARED WIDGETS
// ════════════════════════════════════════════════════════════════

// ─── AppBar ──────────────────────────────────────────────────────
class _AppBar extends StatelessWidget implements PreferredSizeWidget {
  final String title;
  final Widget? leading, trailing;
  final VoidCallback? onLeadingTap;
  const _AppBar({required this.title, this.leading, this.trailing, this.onLeadingTap});
  @override Size get preferredSize => const Size.fromHeight(kToolbarHeight);
  @override
  Widget build(BuildContext context) => AppBar(
    backgroundColor: K.card,
    surfaceTintColor: Colors.transparent,
    elevation: 0,
    centerTitle: true,
    leading: leading != null
        ? IconButton(
            icon: leading!,
            onPressed: onLeadingTap ?? () => Scaffold.of(context).openDrawer(),
          )
        : null,
    title: Text(title,
        style: const TextStyle(color: K.white, fontSize: 17, fontWeight: FontWeight.w700)),
    actions: trailing != null ? [trailing!] : null,
  );
}

// ─── Text Field ──────────────────────────────────────────────────
class TField extends StatelessWidget {
  final TextEditingController ctrl;
  final String hint;
  final IconData icon;
  final bool ltr, obscure;
  final TextInputAction? action;
  final void Function(String)? onSubmit;
  final Widget? suffix;
  const TField({super.key, required this.ctrl, required this.hint, required this.icon,
      this.ltr = false, this.obscure = false, this.action, this.onSubmit, this.suffix});

  @override
  Widget build(BuildContext context) => TextField(
    controller: ctrl,
    obscureText: obscure,
    textDirection: ltr ? TextDirection.ltr : TextDirection.rtl,
    textInputAction: action,
    onSubmitted: onSubmit,
    style: const TextStyle(color: K.white, fontSize: 15),
    decoration: InputDecoration(
      hintText: hint,
      hintStyle: const TextStyle(color: K.grey, fontSize: 14),
      prefixIcon: Icon(icon, color: K.grey, size: 20),
      suffixIcon: suffix,
      filled: true, fillColor: K.card2,
      border:         OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: K.border)),
      enabledBorder:  OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: K.border)),
      focusedBorder:  OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: K.red, width: 1.5)),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    ),
  );
}

// ─── Stat Cell ───────────────────────────────────────────────────
class StatCell extends StatelessWidget {
  final String value, label;
  final Color color;
  const StatCell({super.key, required this.value, required this.label, required this.color});
  @override
  Widget build(BuildContext context) => Expanded(child: Column(children: [
    Text(value, style: TextStyle(color: color, fontSize: 22, fontWeight: FontWeight.w800)),
    const SizedBox(height: 2),
    Text(label, style: const TextStyle(color: K.grey, fontSize: 10), textAlign: TextAlign.center),
  ]));
}

class VSep extends StatelessWidget {
  const VSep({super.key});
  @override Widget build(BuildContext context) =>
    Container(width: 1, height: 38, color: K.border);
}

// ─── Badge ───────────────────────────────────────────────────────
/// شريط صور الإيصال/العنوان اللي المحل بيرفعها مع الطلب.
/// الطيار يقدر يضغط على أي صورة يشوفها بالحجم الكامل ويكبّرها بأصابعه.
class _ReceiptImages extends StatelessWidget {
  final List<String> urls;
  const _ReceiptImages({required this.urls});

  void _openViewer(BuildContext context, int startIndex) {
    Navigator.push(context, MaterialPageRoute(
      builder: (_) => _ImageViewer(urls: urls, initialIndex: startIndex),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Icon(Icons.photo_camera_outlined, color: K.blue, size: 16),
          const SizedBox(width: 6),
          Text('صور الإيصال / العنوان (${urls.length})',
              style: const TextStyle(color: K.grey, fontSize: 12, fontWeight: FontWeight.w600)),
        ]),
        const SizedBox(height: 8),
        SizedBox(
          height: 88,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            itemCount: urls.length,
            separatorBuilder: (_, __) => const SizedBox(width: 8),
            itemBuilder: (_, i) => GestureDetector(
              onTap: () => _openViewer(context, i),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: Image.network(
                  urls[i],
                  width: 88, height: 88, fit: BoxFit.cover,
                  loadingBuilder: (c, child, p) => p == null ? child : Container(
                    width: 88, height: 88, color: K.card2,
                    child: const Center(child: SizedBox(width: 20, height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2, color: K.grey))),
                  ),
                  errorBuilder: (c, e, st) => Container(
                    width: 88, height: 88, color: K.card2,
                    child: const Icon(Icons.broken_image_outlined, color: K.grey, size: 22),
                  ),
                ),
              ),
            ),
          ),
        ),
      ]),
    );
  }
}

/// عارض الصور بالحجم الكامل مع إمكانية التكبير والتنقل بين الصور
class _ImageViewer extends StatefulWidget {
  final List<String> urls;
  final int initialIndex;
  const _ImageViewer({required this.urls, required this.initialIndex});
  @override State<_ImageViewer> createState() => _ImageViewerState();
}

class _ImageViewerState extends State<_ImageViewer> {
  late final PageController _ctrl = PageController(initialPage: widget.initialIndex);
  late int _current = widget.initialIndex;

  @override
  void dispose() { _ctrl.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.close_rounded, color: K.white),
          onPressed: () => Navigator.pop(context),
        ),
        centerTitle: true,
        title: Text('${_current + 1} / ${widget.urls.length}',
            style: const TextStyle(color: K.white, fontSize: 15, fontWeight: FontWeight.w700)),
      ),
      body: PageView.builder(
        controller: _ctrl,
        itemCount: widget.urls.length,
        onPageChanged: (i) => setState(() => _current = i),
        itemBuilder: (_, i) => InteractiveViewer(
          minScale: 1, maxScale: 5,
          child: Center(
            child: Image.network(
              widget.urls[i],
              fit: BoxFit.contain,
              loadingBuilder: (c, child, p) => p == null ? child
                  : const Center(child: CircularProgressIndicator(color: K.red)),
              errorBuilder: (c, e, st) => const Center(
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.broken_image_outlined, color: K.grey, size: 46),
                  SizedBox(height: 10),
                  Text('تعذّر تحميل الصورة — تحقق من الإنترنت',
                      style: TextStyle(color: K.grey, fontSize: 13)),
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  final String label;
  final Color color;
  const _Badge({required this.label, required this.color});
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
    decoration: BoxDecoration(
        color: color.withValues(alpha: .12),
        borderRadius: BorderRadius.circular(20)),
    child: Text(label, style: TextStyle(color: color,
        fontSize: 11, fontWeight: FontWeight.w700)),
  );
}

// ─── Section Card ────────────────────────────────────────────────
class _Section extends StatelessWidget {
  final String title;
  final IconData icon;
  final Color iconColor;
  final List<Widget> children;
  const _Section({required this.title, required this.icon,
      required this.iconColor, required this.children});
  @override
  Widget build(BuildContext context) => Container(
    decoration: BoxDecoration(color: K.card,
        borderRadius: BorderRadius.circular(14), border: Border.all(color: K.border)),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
        child: Row(children: [
          Icon(icon, color: iconColor, size: 15),
          const SizedBox(width: 6),
          Text(title, style: TextStyle(color: iconColor,
              fontSize: 12, fontWeight: FontWeight.w700)),
        ]),
      ),
      const Divider(color: K.border, height: 1),
      Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: children),
      ),
    ]),
  );
}

// ─── Detail Row ──────────────────────────────────────────────────
class _DRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color? valueColor;
  const _DRow(this.icon, this.label, {this.valueColor});
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Icon(icon, color: K.grey, size: 16),
      const SizedBox(width: 10),
      Expanded(
        child: Text(label,
            style: TextStyle(color: valueColor ?? K.white,
                fontSize: 14, fontWeight: FontWeight.w500)),
      ),
    ]),
  );
}

// ─── Phone Row — رقم + زر اتصال + زر واتساب ─────────────────────
class _PhoneRow extends StatelessWidget {
  final String phone;
  final String? label;
  const _PhoneRow(this.phone, {this.label});

  /// تنظيف الرقم — إزالة مسافات وشرطات
  String get _clean => phone.replaceAll(RegExp(r'[\s\-()]'), '');

  /// رقم واتساب — إضافة كود مصر لو الرقم يبدأ بـ 0
  String get _waNum {
    String n = _clean;
    if (n.startsWith('0')) n = '2$n';   // 01xxxxxxxx → 201xxxxxxxx
    return n;
  }

  Future<void> _call(BuildContext ctx) async {
    final uri = Uri(scheme: 'tel', path: _clean);
    await _open(ctx, uri, 'تعذّر فتح تطبيق الهاتف');
  }

  Future<void> _whatsapp(BuildContext ctx) async {
    // نجرب deep-link واتساب الرسمي
    final uri = Uri.parse('whatsapp://send?phone=$_waNum');
    final opened = await _open(ctx, uri, '');
    if (!opened) {
      // fallback: رابط الويب
      if (!ctx.mounted) return;
      await _open(ctx, Uri.parse('https://wa.me/$_waNum'), 'واتساب غير مثبّت');
    }
  }

  /// يفتح URI مباشرةً — يرجع true لو نجح
  Future<bool> _open(BuildContext ctx, Uri uri, String errorMsg) async {
    try {
      final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!ok && errorMsg.isNotEmpty && ctx.mounted) {
        _snack(ctx, errorMsg, isError: true);
      }
      return ok;
    } catch (e) {
      if (errorMsg.isNotEmpty && ctx.mounted) {
        _snack(ctx, errorMsg, isError: true);
      }
      return false;
    }
  }

  void _snack(BuildContext ctx, String msg, {bool isError = false}) {
    ScaffoldMessenger.of(ctx).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: isError ? K.red : K.green,
      duration: const Duration(seconds: 2),
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(children: [
        const Icon(Icons.phone_outlined, color: K.grey, size: 16),
        const SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (label != null)
              Text(label!, style: const TextStyle(color: K.grey, fontSize: 11)),
            Directionality(
              textDirection: TextDirection.ltr,
              child: Text(phone,
                  style: const TextStyle(
                      color: K.white, fontSize: 14, fontWeight: FontWeight.w600)),
            ),
          ]),
        ),
        const SizedBox(width: 8),
        // ── زر اتصال ──
        _ActionBtn(
          icon: Icons.call_rounded,
          color: K.green,
          label: 'اتصال',
          onTap: () => _call(context),
        ),
        const SizedBox(width: 8),
        // ── زر واتساب ──
        _ActionBtn(
          icon: Icons.wechat_rounded,          // أقرب أيقونة متاحة
          color: const Color(0xFF25D366),
          label: 'واتساب',
          onTap: () => _whatsapp(context),
        ),
      ]),
    );
  }
}

// ─── Action Button (call / whatsapp) ────────────────────────────
class _ActionBtn extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String label;
  final VoidCallback onTap;
  const _ActionBtn({required this.icon, required this.color,
      required this.label, required this.onTap});
  @override
  Widget build(BuildContext context) => InkWell(
    onTap: onTap,
    borderRadius: BorderRadius.circular(10),
    child: Container(
      width: 52, height: 46,
      decoration: BoxDecoration(
        color: color.withValues(alpha: .12),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withValues(alpha: .35)),
      ),
      child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
        Icon(icon, color: color, size: 18),
        const SizedBox(height: 2),
        Text(label, style: TextStyle(color: color, fontSize: 9, fontWeight: FontWeight.w700)),
      ]),
    ),
  );
}

// ─── Account helpers ─────────────────────────────────────────────
class _CardSection extends StatelessWidget {
  final List<Widget> children;
  const _CardSection({required this.children});
  @override
  Widget build(BuildContext context) => Container(
    decoration: BoxDecoration(color: K.card,
        borderRadius: BorderRadius.circular(14), border: Border.all(color: K.border)),
    child: Column(children: children),
  );
}

class _InfoTile extends StatelessWidget {
  final IconData icon;
  final String label, value;
  const _InfoTile(this.icon, this.label, this.value);
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
    child: Row(children: [
      Icon(icon, color: K.grey, size: 18),
      const SizedBox(width: 10),
      Text(label, style: const TextStyle(color: K.grey, fontSize: 13)),
      const Spacer(),
      Text(value.isEmpty ? '—' : value,
          style: const TextStyle(color: K.white, fontSize: 13, fontWeight: FontWeight.w500)),
    ]),
  );
}

class _MenuTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool isRed;
  const _MenuTile(this.icon, this.label, this.onTap, {this.isRed = false});
  @override
  Widget build(BuildContext context) => InkWell(
    onTap: onTap,
    borderRadius: BorderRadius.circular(14),
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      child: Row(children: [
        Icon(icon, color: isRed ? K.red : K.grey, size: 20),
        const SizedBox(width: 12),
        Text(label,
            style: TextStyle(color: isRed ? K.red : K.white, fontSize: 14)),
        const Spacer(),
        if (!isRed) const Icon(Icons.chevron_left_rounded, color: K.grey, size: 20),
      ]),
    ),
  );
}

// ─── Shared dialog/snack helpers ─────────────────────────────────
Future<bool> _confirm(BuildContext ctx, String title, String msg, Color color, String btn) async {
  return await showDialog<bool>(
    context: ctx,
    builder: (dialogCtx) => AlertDialog(
      backgroundColor: K.card2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Text(title, style: const TextStyle(color: K.white, fontWeight: FontWeight.w700)),
      content: Text(msg, style: const TextStyle(color: K.grey, fontSize: 14)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(dialogCtx, false),
            child: const Text('إلغاء', style: TextStyle(color: K.grey))),
        ElevatedButton(
          style: ElevatedButton.styleFrom(backgroundColor: color, foregroundColor: K.white),
          onPressed: () => Navigator.pop(dialogCtx, true),
          child: Text(btn),
        ),
      ],
    ),
  ) ?? false;
}
