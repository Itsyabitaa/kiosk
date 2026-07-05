import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:dio/dio.dart';
import 'kiosk_channel.dart';
import 'networking.dart';

class SecureExitManager {
  static final SecureExitManager instance = SecureExitManager();

  final FlutterSecureStorage _storage = const FlutterSecureStorage();
  final Dio _dio;

  SecureExitManager({Dio? dio})
      : _dio = dio ?? Dio(BaseOptions(baseUrl: Networking.apiBaseUrl));

  /// Factory-default admin password for a fresh install. Matches the SureLock-style `0000`.
  /// Operators should change this from the config panel after first setup.
  static const String defaultPin = '0000';

  Future<void> seedDefaultPinIfNeeded() async {
    final currentPin = await _storage.read(key: 'exit_pin_hash');
    if (currentPin == null) {
      final defaultPinHash = sha256.convert(utf8.encode(defaultPin)).toString();
      await _storage.write(key: 'exit_pin_hash', value: defaultPinHash);
    }
  }

  /// Verify the admin PIN WITHOUT exiting kiosk mode. Used to gate the local configuration
  /// panel. Applies the same lockout/backoff rules as [verifyAndExit].
  Future<bool> verifyPinOnly(String inputPin) async {
    await seedDefaultPinIfNeeded();

    final lockoutExpiryStr = await _storage.read(key: 'pin_lockout_expiry');
    if (lockoutExpiryStr != null) {
      final expiry = DateTime.parse(lockoutExpiryStr);
      if (DateTime.now().isBefore(expiry)) {
        final remaining = expiry.difference(DateTime.now()).inSeconds;
        throw Exception('PIN lockout active. Try again in $remaining seconds.');
      }
    }

    final storedHash = await _storage.read(key: 'exit_pin_hash');
    final inputHash = sha256.convert(utf8.encode(inputPin)).toString();

    if (storedHash == inputHash) {
      await _storage.delete(key: 'failed_pin_attempts');
      await _storage.delete(key: 'pin_lockout_expiry');
      return true;
    }

    final attemptsStr = await _storage.read(key: 'failed_pin_attempts') ?? '0';
    final attempts = int.parse(attemptsStr) + 1;
    await _storage.write(key: 'failed_pin_attempts', value: '$attempts');

    if (attempts >= 5) {
      final expiry = DateTime.now().add(const Duration(minutes: 1));
      await _storage.write(key: 'pin_lockout_expiry', value: expiry.toIso8601String());
      throw Exception('Too many failed attempts. Lockout for 60 seconds.');
    }

    throw Exception('Incorrect PIN. Attempt $attempts of 5.');
  }

  /// Change the admin PIN (requires the current PIN).
  Future<void> changePin(String currentPin, String newPin) async {
    final ok = await verifyPinOnly(currentPin);
    if (!ok) {
      throw Exception('Current PIN is incorrect.');
    }
    if (newPin.length < 4) {
      throw Exception('New PIN must be at least 4 digits.');
    }
    final newHash = sha256.convert(utf8.encode(newPin)).toString();
    await _storage.write(key: 'exit_pin_hash', value: newHash);
  }

  Future<bool> verifyAndExit(String inputPin) async {
    await seedDefaultPinIfNeeded();

    final deviceId = await _storage.read(key: 'device_id');
    final deviceToken = await _storage.read(key: 'device_token');

    // 1. Check lockout status
    final lockoutExpiryStr = await _storage.read(key: 'pin_lockout_expiry');
    if (lockoutExpiryStr != null) {
      final expiry = DateTime.parse(lockoutExpiryStr);
      if (DateTime.now().isBefore(expiry)) {
        final remaining = expiry.difference(DateTime.now()).inSeconds;
        throw Exception('PIN lockout active. Try again in $remaining seconds.');
      }
    }

    final storedHash = await _storage.read(key: 'exit_pin_hash');
    final inputHash = sha256.convert(utf8.encode(inputPin)).toString();

    if (storedHash == inputHash) {
      // Success
      await _storage.delete(key: 'failed_pin_attempts');
      await _storage.delete(key: 'pin_lockout_expiry');

      // Call native unlock
      await KioskChannel.unlock();

      // Log success event
      if (deviceId != null && deviceToken != null) {
        _logEvent(deviceId, deviceToken, 'exit_attempt', 'success', {'reason': 'local PIN exit'});
      }
      return true;
    } else {
      // Failed
      final attemptsStr = await _storage.read(key: 'failed_pin_attempts') ?? '0';
      final attempts = int.parse(attemptsStr) + 1;
      await _storage.write(key: 'failed_pin_attempts', value: '$attempts');

      // Log failed event
      if (deviceId != null && deviceToken != null) {
        _logEvent(deviceId, deviceToken, 'exit_attempt', 'failed', {
          'reason': 'incorrect PIN',
          'attempt_count': attempts,
        });
      }

      if (attempts >= 5) {
        final expiry = DateTime.now().add(const Duration(minutes: 1));
        await _storage.write(key: 'pin_lockout_expiry', value: expiry.toIso8601String());

        // Send tamper alert event to backend
        if (deviceId != null && deviceToken != null) {
          _logEvent(deviceId, deviceToken, 'tamper_alert', 'failed', {
            'reason': 'PIN lockout triggered after 5 failed attempts',
          });
        }
        throw Exception('Too many failed attempts. Lockout for 60 seconds.');
      }

      throw Exception('Incorrect PIN. Attempt $attempts of 5.');
    }
  }

  Future<void> logKioskEvent(String eventType, String status, Map<String, dynamic> details) async {
    final deviceId = await _storage.read(key: 'device_id');
    final deviceToken = await _storage.read(key: 'device_token');
    if (deviceId != null && deviceToken != null) {
      await _logEvent(deviceId, deviceToken, eventType, status, details);
    }
  }

  Future<void> _logEvent(String deviceId, String deviceToken, String eventType, String status, Map<String, dynamic> details) async {
    try {
      await _dio.post(
        '/devices/$deviceId/events',
        data: {
          'event_type': eventType,
          'status': status,
          'details': details,
        },
        options: Options(headers: {
          'Authorization': 'Bearer $deviceToken',
        }),
      );
    } catch (e) {
      print('Failed to log event: $e');
    }
  }
}
