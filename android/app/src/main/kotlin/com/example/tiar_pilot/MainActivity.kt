// ═══════════════════════════════════════════════════════════════════════
// MainActivity.kt — نسخة كاملة بافتراض إن ملفكم الحالي لسه بالشكل
// الافتراضي (class فاضية زي ما بيعملها Flutter تلقائيًا).
//
// ⚠️ مهم: لو ملفكم فيه كود موجود بالفعل (خصوصًا لو عندكم أي إعداد خاص
// بـ flutter_callkit_incoming زي registerEventCallback جوه onCreate)،
// متستبدلوش الملف بالكامل — دمّجوا الأجزاء الثلاثة دي بس مع اللي عندكم:
//   1) الـ imports في الأول
//   2) الخصائص (properties) الثلاثة جوه الـ class
//   3) دالة configureFlutterEngine (لو عندكم واحدة بالفعل، ضيفوا جواها
//      الجزء بتاع MethodChannel بس، وسيبوا باقي اللي موجود زي ما هو)
//   4) دالة onActivityResult (لو عندكم واحدة بالفعل، ضيفوا جواها الـ if
//      بتاع PICK_RINGTONE_REQUEST بس)
// الـ package name لازم يفضل زي اللي عندكم بالظبط (أول سطر في ملفكم
// الحالي) — متغيّروهوش، ده بيتحدد وقت إنشاء المشروع ومربوط بإعدادات تانية.
// ═══════════════════════════════════════════════════════════════════════

package com.example.tiar // ⚠️ غيّروه ليطابق أول سطر في ملفكم الحالي بالظبط

import android.app.Activity
import android.content.Intent
import android.media.Ringtone
import android.media.RingtoneManager
import android.net.Uri
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    // اسم القناة لازم يطابق بالظبط MethodChannel('tiar/ringtone') في main.dart
    private val RINGTONE_CHANNEL = "tiar/ringtone"
    private val PICK_RINGTONE_REQUEST = 4201

    private var pendingResult: MethodChannel.Result? = null
    private var previewRingtone: Ringtone? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, RINGTONE_CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    // بيفتح شاشة اختيار النغمة الرسمية بتاعة أندرويد (نفس اللي
                    // بتفتح من إعدادات الهاتف) — النتيجة بترجع في onActivityResult
                    "pickRingtone" -> {
                        pendingResult = result
                        val intent = Intent(RingtoneManager.ACTION_RINGTONE_PICKER).apply {
                            putExtra(RingtoneManager.EXTRA_RINGTONE_TYPE, RingtoneManager.TYPE_ALL)
                            putExtra(RingtoneManager.EXTRA_RINGTONE_SHOW_SILENT, false)
                            putExtra(RingtoneManager.EXTRA_RINGTONE_SHOW_DEFAULT, false)
                            putExtra(RingtoneManager.EXTRA_RINGTONE_TITLE, "اختر نغمة")
                            // آخر نغمة كانت متعاينة، لو موجودة، تتحدد تلقائيًا في القائمة
                            val current = call.argument<String>("currentUri")
                            if (!current.isNullOrEmpty()) {
                                putExtra(RingtoneManager.EXTRA_RINGTONE_EXISTING_URI, Uri.parse(current))
                            }
                        }
                        try {
                            startActivityForResult(intent, PICK_RINGTONE_REQUEST)
                        } catch (e: Exception) {
                            pendingResult = null
                            result.error("PICKER_ERROR", e.message, null)
                        }
                    }

                    // معاينة نغمة (بالـ URI) — بتوقف أي معاينة سابقة لسه شغالة الأول
                    "playRingtoneUri" -> {
                        try {
                            previewRingtone?.stop()
                            val uriStr = call.argument<String>("uri")
                            val uri = Uri.parse(uriStr)
                            val ringtone = RingtoneManager.getRingtone(applicationContext, uri)
                            previewRingtone = ringtone
                            ringtone?.play()
                            result.success(null)
                        } catch (e: Exception) {
                            result.error("PLAY_ERROR", e.message, null)
                        }
                    }

                    "stopRingtonePreview" -> {
                        previewRingtone?.stop()
                        result.success(null)
                    }

                    else -> result.notImplemented()
                }
            }
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == PICK_RINGTONE_REQUEST) {
            val result = pendingResult
            pendingResult = null
            if (resultCode == Activity.RESULT_OK) {
                @Suppress("DEPRECATION")
                val uri: Uri? = data?.getParcelableExtra(RingtoneManager.EXTRA_RINGTONE_PICKED_URI)
                result?.success(uri?.toString())
            } else {
                // المستخدم ألغى الاختيار
                result?.success(null)
            }
        }
    }

    override fun onDestroy() {
        previewRingtone?.stop()
        super.onDestroy()
    }
}
