import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kiosklock_agent/features/browser/kiosk_browser_screen.dart';

void main() {
  group('KioskBrowserScreen Domain Whitelisting Unit Tests', () {
    final whitelist = ['google.com', '*.youtube.com', 'wikipedia.org'];

    test('Allow exact host match', () {
      expect(KioskBrowserScreen.isDomainAllowed('https://google.com', whitelist), isTrue);
      expect(KioskBrowserScreen.isDomainAllowed('http://google.com/search?q=test', whitelist), isTrue);
      expect(KioskBrowserScreen.isDomainAllowed('https://wikipedia.org', whitelist), isTrue);
    });

    test('Allow wildcard subdomains', () {
      expect(KioskBrowserScreen.isDomainAllowed('https://youtube.com', whitelist), isTrue);
      expect(KioskBrowserScreen.isDomainAllowed('https://music.youtube.com', whitelist), isTrue);
      expect(KioskBrowserScreen.isDomainAllowed('https://embed.youtube.com/video', whitelist), isTrue);
    });

    test('Prevent off-list domains', () {
      expect(KioskBrowserScreen.isDomainAllowed('https://evilgoogle.com', whitelist), isFalse);
      expect(KioskBrowserScreen.isDomainAllowed('https://example.com', whitelist), isFalse);
      expect(KioskBrowserScreen.isDomainAllowed('https://other.org', whitelist), isFalse);
    });

    test('Prevent subdomain if only exact domain whitelisted', () {
      expect(KioskBrowserScreen.isDomainAllowed('https://sub.google.com', whitelist), isFalse);
    });

    test('Prevent empty or invalid domains', () {
      expect(KioskBrowserScreen.isDomainAllowed('', whitelist), isFalse);
      expect(KioskBrowserScreen.isDomainAllowed('invalid-url', whitelist), isFalse);
    });
  });

  group('KioskBrowserScreen Widget Tests', () {
    testWidgets('Renders Policy Misconfigured Screen when URL is empty', (WidgetTester tester) async {
      await tester.pumpWidget(const MaterialApp(
        home: KioskBrowserScreen(
          homeUrl: '',
          allowedDomains: [],
        ),
      ));

      expect(find.text('Kiosk Browser Error'), findsOneWidget);
      expect(find.text('Policy config is missing or invalid.'), findsOneWidget);
    });

    testWidgets('Renders Policy Misconfigured Screen when URL is invalid', (WidgetTester tester) async {
      await tester.pumpWidget(const MaterialApp(
        home: KioskBrowserScreen(
          homeUrl: 'not_a_valid_url',
          allowedDomains: [],
        ),
      ));

      expect(find.text('Kiosk Browser Error'), findsOneWidget);
    });
  });
}
