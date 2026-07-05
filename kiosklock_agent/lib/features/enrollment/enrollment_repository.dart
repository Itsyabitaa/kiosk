import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'dart:io' show Platform;
import 'package:flutter/foundation.dart';

class EnrollmentRepository {
  final Dio _dio;
  final FlutterSecureStorage _storage;

  EnrollmentRepository({Dio? dio, FlutterSecureStorage? storage})
      : _dio = dio ?? Dio(BaseOptions(baseUrl: 'http://localhost/api')),
        _storage = storage ?? const FlutterSecureStorage();

  Future<void> enroll(String token) async {
    int maxRetries = 3;
    int retryCount = 0;
    int delay = 2; // seconds

    while (retryCount <= maxRetries) {
      try {
        String platform = kIsWeb ? 'web' : (Platform.isAndroid ? 'android' : (Platform.isIOS ? 'ios' : 'unknown'));
        
        // Use a dummy hardware fingerprint for now, in a real app use device_info_plus
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
        if (e.type == DioExceptionType.connectionTimeout || 
            e.type == DioExceptionType.receiveTimeout || 
            e.type == DioExceptionType.unknown) {
          if (retryCount == maxRetries) {
            throw Exception('Network error during enrollment after $maxRetries retries.');
          }
          retryCount++;
          await Future.delayed(Duration(seconds: delay));
          delay *= 2; // Exponential backoff
        } else {
          throw Exception('Enrollment failed: ${e.message}');
        }
      }
    }
  }

  Future<bool> isEnrolled() async {
    final token = await _storage.read(key: 'device_token');
    return token != null && token.isNotEmpty;
  }
}
