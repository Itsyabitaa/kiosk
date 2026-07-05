import 'dart:async';

import 'package:battery_plus/battery_plus.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'networking.dart';
import 'storage.dart';

/// Periodic, batched device telemetry.
///
/// Collects battery / connectivity / app+OS version snapshots on a slow cadence and flushes
/// them in batches (not per-metric, and not on every change) to avoid draining the battery
/// with constant network chatter. Snapshots buffer locally between flushes.
class TelemetryService {
  static final TelemetryService instance = TelemetryService();

  final Dio _dio;
  final AppStorage _storage;
  final Battery _battery;
  final Connectivity _connectivity;

  TelemetryService({
    Dio? dio,
    AppStorage? storage,
    Battery? battery,
    Connectivity? connectivity,
  })  : _dio = dio ?? Networking.authClient(),
        _storage = storage ?? AppStorage.instance,
        _battery = battery ?? Battery(),
        _connectivity = connectivity ?? Connectivity();

  Timer? _collectTimer;
  Timer? _flushTimer;
  bool _started = false;
  String? _deviceId;

  final List<Map<String, dynamic>> _buffer = [];

  static const Duration collectInterval = Duration(minutes: 5);
  static const Duration flushInterval = Duration(minutes: 15);
  static const int maxBufferBeforeFlush = 12;

  @visibleForTesting
  List<Map<String, dynamic>> get buffer => _buffer;

  Future<void> start() async {
    if (_started) return;
    _started = true;

    _deviceId = await _storage.deviceId();
    if (_deviceId == null) {
      _started = false;
      return;
    }

    await collectSnapshot();

    _collectTimer?.cancel();
    _collectTimer = Timer.periodic(collectInterval, (_) => collectSnapshot());

    _flushTimer?.cancel();
    _flushTimer = Timer.periodic(flushInterval, (_) => flush());
  }

  void stop() {
    _started = false;
    _collectTimer?.cancel();
    _flushTimer?.cancel();
  }

  /// Gather a single snapshot into the local buffer. Never throws.
  Future<void> collectSnapshot() async {
    try {
      final snapshot = <String, dynamic>{
        'recorded_at': DateTime.now().toUtc().toIso8601String(),
      };

      try {
        snapshot['battery_level'] = await _battery.batteryLevel;
      } catch (_) {}

      try {
        final conn = await _connectivity.checkConnectivity();
        snapshot['connectivity_type'] = _connectivityLabel(conn);
      } catch (_) {}

      try {
        final info = await PackageInfo.fromPlatform();
        snapshot['app_version'] = '${info.version}+${info.buildNumber}';
      } catch (_) {}

      try {
        snapshot['os_version'] = await _osVersion();
      } catch (_) {}

      _buffer.add(snapshot);

      if (_buffer.length >= maxBufferBeforeFlush) {
        await flush();
      }
    } catch (e) {
      if (kDebugMode) print('Telemetry collect error: $e');
    }
  }

  /// Send buffered snapshots as a single batched request. Retains the buffer on failure.
  Future<void> flush() async {
    if (_deviceId == null || _buffer.isEmpty) return;

    final batch = List<Map<String, dynamic>>.from(_buffer);
    try {
      final response = await _dio.post(
        '/devices/$_deviceId/telemetry',
        data: {'snapshots': batch},
      );
      if (response.statusCode == 200 || response.statusCode == 201) {
        _buffer.removeRange(0, batch.length);
      }
    } catch (e) {
      if (kDebugMode) print('Telemetry flush error (will retry): $e');
    }
  }

  String _connectivityLabel(dynamic result) {
    // connectivity_plus returns a List<ConnectivityResult> on recent versions.
    final ConnectivityResult r = result is List
        ? (result.isNotEmpty ? result.first : ConnectivityResult.none)
        : result as ConnectivityResult;
    switch (r) {
      case ConnectivityResult.wifi:
        return 'wifi';
      case ConnectivityResult.mobile:
        return 'mobile';
      case ConnectivityResult.ethernet:
        return 'ethernet';
      case ConnectivityResult.none:
        return 'none';
      default:
        return 'other';
    }
  }

  Future<String> _osVersion() async {
    final plugin = DeviceInfoPlugin();
    if (defaultTargetPlatform == TargetPlatform.android) {
      final a = await plugin.androidInfo;
      return 'Android ${a.version.release} (SDK ${a.version.sdkInt})';
    }
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      final i = await plugin.iosInfo;
      return '${i.systemName} ${i.systemVersion}';
    }
    return 'unknown';
  }
}
