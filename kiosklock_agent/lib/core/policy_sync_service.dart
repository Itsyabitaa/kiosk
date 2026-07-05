import 'dart:async';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'kiosk_channel.dart';

class PolicySyncService {
  static final PolicySyncService instance = PolicySyncService();

  final Dio _dio;
  final FlutterSecureStorage _storage;
  Timer? _timer;
  bool _isSyncing = false;

  PolicySyncService({Dio? dio, FlutterSecureStorage? storage})
      : _dio = dio ?? Dio(BaseOptions(baseUrl: 'http://localhost/api')),
        _storage = storage ?? const FlutterSecureStorage();

  void startSync() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 15), (_) => syncPolicy());
    // Initial sync
    syncPolicy();
  }

  void stopSync() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> syncPolicy() async {
    if (_isSyncing) return;
    _isSyncing = true;

    try {
      final deviceToken = await _storage.read(key: 'device_token');
      final deviceId = await _storage.read(key: 'device_id');

      if (deviceToken == null || deviceId == null) {
        _isSyncing = false;
        return;
      }

      final options = Options(headers: {
        'Authorization': 'Bearer $deviceToken',
      });

      final response = await _dio.get('/devices/$deviceId/policy', options: options);

      if (response.statusCode == 200) {
        final data = response.data;
        final policy = data['policy'];

        if (policy == null) {
          final lastAppliedId = await _storage.read(key: 'last_applied_policy_id');
          if (lastAppliedId != null) {
            await KioskChannel.unlock();
            await _storage.delete(key: 'last_applied_policy_id');
            await _storage.delete(key: 'last_applied_policy_version');
          }
          _isSyncing = false;
          return;
        }

        final int policyId = policy['id'];
        final int version = policy['version'];
        final String policyType = policy['policy_type'];
        final String? target = policy['target'];
        final Map<String, dynamic> restrictions = Map<String, dynamic>.from(policy['restrictions'] ?? {});

        final lastAppliedIdStr = await _storage.read(key: 'last_applied_policy_id');
        final lastAppliedVerStr = await _storage.read(key: 'last_applied_policy_version');

        if (lastAppliedIdStr != '$policyId' || lastAppliedVerStr != '$version') {
          bool success = false;
          String? errorMessage;

          try {
            if (policyType == 'single_app' && target != null && target.isNotEmpty) {
              success = await KioskChannel.lockToApp(target, restrictions);
            } else if (policyType == 'url_whitelist') {
              success = await KioskChannel.applyRestrictions(restrictions);
            } else {
              success = true;
            }
          } catch (e) {
            success = false;
            errorMessage = e.toString();
          }

          if (success) {
            await _storage.write(key: 'last_applied_policy_id', value: '$policyId');
            await _storage.write(key: 'last_applied_policy_version', value: '$version');
            await _ackPolicy(deviceId, policyId, 'applied', options);
          } else {
            await _ackPolicy(deviceId, policyId, 'error', options, errorMessage);
          }
        }
      }
    } catch (e) {
      // Log/ignore network errors gracefully so app doesn't crash if offline on launch
      print('PolicySyncService offline/error: $e');
    } finally {
      _isSyncing = false;
    }
  }

  Future<void> _ackPolicy(String deviceId, int policyId, String status, Options options, [String? errorMessage]) async {
    try {
      await _dio.post(
        '/devices/$deviceId/policy-ack',
        data: {
          'policy_id': policyId,
          'status': status,
          'error_message': errorMessage,
        },
        options: options,
      );
    } catch (e) {
      print('Failed to send policy-ack: $e');
    }
  }
}
