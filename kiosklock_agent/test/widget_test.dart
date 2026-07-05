import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kiosklock_agent/main.dart';

void main() {
  testWidgets('EnrollmentScreen rendering test', (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(
      home: EnrollmentScreen(),
    ));

    expect(find.text('Enroll Device'), findsOneWidget);
    expect(find.byType(TextField), findsOneWidget);
    expect(find.widgetWithText(ElevatedButton, 'Enroll'), findsOneWidget);
  });
}
