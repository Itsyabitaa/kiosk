import 'package:dio/dio.dart';

import 'storage.dart';

/// Single source of truth for server endpoints and the authenticated HTTP client.
///
/// Base URL / WS host were previously hardcoded (`http://localhost/api`) in three separate
/// services. Centralizing here means the QR-provisioned `server_url` (or a build flavor) can
/// later drive both HTTP and WebSocket endpoints from one place.
class Networking {
  // Dev machine's LAN IP (reachable from a physical phone on the same Wi-Fi).
  // Emulator alternatives: 10.0.2.2 (Android AVD) / 10.0.3.2 (Genymotion) with the same port.
  static const String host = '192.168.100.60';
  static const String httpScheme = 'http';
  static const int httpPort = 8000; // `php artisan serve --host=0.0.0.0 --port=8000`
  static const String apiBaseUrl = '$httpScheme://$host:$httpPort/api';

  // Reverb websocket endpoint. Pusher protocol path is /app/{appKey}.
  static const String wsHost = host;
  static const int wsPort = 8080;
  static const bool wsUseTls = false;
  static const String reverbAppKey = 'kiosklock-key';

  static String wsUrl() {
    final scheme = wsUseTls ? 'wss' : 'ws';
    return '$scheme://$wsHost:$wsPort/app/$reverbAppKey?protocol=7&client=flutter&version=1.0.0';
  }

  /// Dio client that automatically attaches the device bearer token.
  static Dio authClient({AppStorage? storage}) {
    final store = storage ?? AppStorage.instance;
    final dio = Dio(BaseOptions(baseUrl: apiBaseUrl));

    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await store.deviceToken();
        if (token != null && token.isNotEmpty) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(options);
      },
    ));

    return dio;
  }
}
