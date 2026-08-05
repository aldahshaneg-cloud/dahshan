// اختبار دخان بسيط للودجتس المشتركة في تطبيق الطيار.
//
// الاختبار الافتراضي القديم كان بيبني MyApp — كلاس مش موجود أصلًا — فكان
// flutter analyze بيطلع منه error. وما ينفعش نبني TiarApp نفسه هنا لأن
// SplashScreen بيفتح AnimationController و Future.delayed وبيوصل لفايربيز،
// وده بيوقّع الاختبار بـ pending timer. فبنختبر ودجتس عرض خالصة بدل كده.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:tiar_pilot/main.dart';

void main() {
  Widget wrap(Widget child) => MaterialApp(
        home: Directionality(
          textDirection: TextDirection.rtl,
          child: Scaffold(body: child),
        ),
      );

  testWidgets('StatCell يعرض القيمة والعنوان', (WidgetTester tester) async {
    await tester.pumpWidget(wrap(
      const Row(children: [StatCell(value: '12', label: 'أوردرات', color: Colors.red)]),
    ));

    expect(find.text('12'), findsOneWidget);
    expect(find.text('أوردرات'), findsOneWidget);
  });

  testWidgets('TField بياخد النص ويفضل RTL', (WidgetTester tester) async {
    final ctrl = TextEditingController();
    await tester.pumpWidget(wrap(
      TField(ctrl: ctrl, hint: 'اسم المستخدم', icon: Icons.person),
    ));

    expect(find.text('اسم المستخدم'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'ahmed');
    expect(ctrl.text, 'ahmed');
    expect(tester.widget<TextField>(find.byType(TextField)).textDirection, TextDirection.rtl);
  });
}
