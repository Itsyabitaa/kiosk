import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'dart:io' show Platform;
import 'package:flutter/foundation.dart';
import 'package:kiosklock_agent/core/networking.dart';

class EnrollmentRepository {
  final Dio _dio;
  final FlutterSecureStorage _storage;

  EnrollmentRepository({Dio? dio, FlutterSecureStorage? storage})
      : _dio = dio ?? Dio(BaseOptions(baseUrl: Networking.apiBaseUrl)),
        _storage = storage ?? const FlutterSecureStorage();

  Future<void> enroll(String token) async {
    const int maxRetries = 5;
    int retryCount = 0;
    int delay = 2; // seconds

    while (true) {
      try {
        String platform = kIsWeb ? 'web' : (Platform.isAndroid ? 'android' : (Platform.isIOS ? 'ios' : 'unknown'));
        String hardwareFingerprint = 'device_${platform}_fingerprint_stub';

        final response = await _dio.post('/enroll', data: {
          'hardware_fingerprint': hardwareFingerprint,
          'platform': platform,
          'enrollment_token': token,
        });

        if (response.statusCode == 200) {
          final data = response.data;
          await _storage.write(key: 'device_token', value: data['device_token']);
          await _storage.write(key: 'device_id', value: data['device_id'].toString());
          return;
        } else {
          throw Exception('Failed to enroll: ${response.statusCode}');
        }
      } on DioException catch (e) {
        bool shouldRetry = false;
        
        // Retry only on connection errors, timeout errors, or 5xx server errors.
        // Do not retry on client-side errors (4xx) like invalid/expired token.
        if (e.response == null) {
          shouldRetry = true;
        } else if (e.response!.statusCode != null && e.response!.statusCode! >= 500) {
          shouldRetry = true;
        }

        if (shouldRetry && retryCount < maxRetries) {
          retryCount++;
          await Future.delayed(Duration(seconds: delay));
          delay *= 2; // Exponential backoff
        } else {
          // If we have a response, try to extract the specific error message
          if (e.response != null && e.response!.data != null && e.response!.data is Map) {
            final data = e.response!.data as Map;
            if (data.containsKey('error')) {
              throw Exception(data['error']);
            }
            if (data.containsKey('message')) {
              throw Exception(data['message']);
            }
          }
          throw Exception(e.message ?? 'Network error during enrollment');
        }
      } catch (e) {
        throw Exception('An unexpected error occurred: $e');
      }
    }
  }

  Future<bool> isEnrolled() async {
    final token = await _storage.read(key: 'device_token');
    return token != null && token.isNotEmpty;
  }

  /// Re-provision the currently enrolled device to a new policy using a scanned QR token.
  /// Used by the in-app scanner sheet to move an already enrolled device onto a new policy.
  Future<void> reassignPolicy(String scannedToken) async {
    final deviceId = await _storage.read(key: 'device_id');
    final deviceToken = await _storage.read(key: 'device_token');

    if (deviceId == null || deviceToken == null) {
      throw Exception('Device is not enrolled');
    }

    try {
      final response = await _dio.post(
        '/devices/$deviceId/reassign',
        data: {'token': scannedToken},
        options: Options(headers: {'Authorization': 'Bearer $deviceToken'}),
      );

      if (response.statusCode != 200) {
        throw Exception('Reassignment failed: ${response.statusCode}');
      }
    } on DioException catch (e) {
      if (e.response?.data is Map) {
        final data = e.response!.data as Map;
        if (data.containsKey('error')) {
          throw Exception(data['error']);
        }
      }
      throw Exception(e.message ?? 'Network error during reassignment');
    }
  }
}
