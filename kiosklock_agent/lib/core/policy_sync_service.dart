import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'app_config.dart';
import 'kiosk_channel.dart';
import 'networking.dart';

class PolicySyncService {
  static final PolicySyncService instance = PolicySyncService();

  /// Secure-storage key holding the operator-configured standalone policy (set via the
  /// PIN-protected config panel). When present it overrides [AppConfig.bundledPolicy].
  static const String kStandalonePolicyKey = 'standalone_policy';

  final Dio _dio;
  final FlutterSecureStorage _storage;
  Timer? _timer;
  bool _isSyncing = false;

  final ValueNotifier<Map<String, dynamic>?> activePolicyNotifier = ValueNotifier(null);

  PolicySyncService({Dio? dio, FlutterSecureStorage? storage})
      : _dio = dio ?? Dio(BaseOptions(baseUrl: Networking.apiBaseUrl)),
        _storage = storage ?? const FlutterSecureStorage();

  void startSync() {
    // In standalone mode there is no server to poll — apply the bundled policy once instead.
    if (AppConfig.standaloneMode) {
      applyStandalonePolicy();
      return;
    }

    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 15), (_) => syncPolicy());
    // Initial sync
    syncPolicy();
  }

  /// Returns the standalone policy the operator saved locally, or `null` if none is set yet.
  Future<Map<String, dynamic>?> loadSavedStandalonePolicy() async {
    final raw = await _storage.read(key: kStandalonePolicyKey);
    if (raw == null || raw.isEmpty) return null;
    try {
      return Map<String, dynamic>.from(jsonDecode(raw) as Map);
    } catch (_) {
      return null;
    }
  }

  /// Persist a new standalone policy (from the config panel) and immediately enforce it.
  Future<void> saveAndApplyStandalonePolicy(Map<String, dynamic> policy) async {
    await _storage.write(key: kStandalonePolicyKey, value: jsonEncode(policy));
    await applyStandalonePolicy();
  }

  /// Apply the active standalone policy with no network access. Uses the operator-configured
  /// policy from secure storage when present, otherwise the baked-in [AppConfig.bundledPolicy].
  /// Mirrors the apply path used for server-delivered policies so the same native lock + UI
  /// rendering kicks in.
  Future<void> applyStandalonePolicy() async {
    final saved = await loadSavedStandalonePolicy();
    final policy = saved ?? AppConfig.bundledPolicy;
    activePolicyNotifier.value = Map<String, dynamic>.from(policy);

    final String policyType = policy['policy_type'];
    final String? target = policy['target'] as String?;
    final Map<String, dynamic> restrictions =
        Map<String, dynamic>.from(policy['restrictions'] ?? {});

    try {
      if (policyType == 'multi_app') {
        final apps = List<Map<String, dynamic>>.from(
            (restrictions['apps'] as List?)?.map((e) => Map<String, dynamic>.from(e as Map)) ?? []);
        final packages = apps
            .map((a) => (a['package'] as String?)?.trim() ?? '')
            .where((p) => p.isNotEmpty)
            .toList();
        await KioskChannel.setKioskApps(packages, restrictions);
      } else if (policyType == 'single_app' && target != null && target.isNotEmpty) {
        await KioskChannel.lockToApp(target, restrictions);
      } else if (policyType == 'url_whitelist') {
        await KioskChannel.lockToApp('com.kiosklock.kiosklock_agent', restrictions);
      }
    } catch (e) {
      debugPrint('Standalone policy apply error: $e');
    }
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
          activePolicyNotifier.value = null;
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

        // Expose active policy to listeners
        activePolicyNotifier.value = Map<String, dynamic>.from(policy);

        final lastAppliedIdStr = await _storage.read(key: 'last_applied_policy_id');
        final lastAppliedVerStr = await _storage.read(key: 'last_applied_policy_version');

        if (lastAppliedIdStr != '$policyId' || lastAppliedVerStr != '$version') {
          bool success = false;
          String? errorMessage;

          try {
            if (policyType == 'single_app' && target != null && target.isNotEmpty) {
              success = await KioskChannel.lockToApp(target, restrictions);
            } else if (policyType == 'url_whitelist') {
              // Lock our own app package to run the Whitelisted WebView in kiosk mode
              success = await KioskChannel.lockToApp('com.kiosklock.kiosklock_agent', restrictions);
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
            await _ackPolicy(deviceId, policyId, 'applied', options, null, version);
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

  Future<void> _ackPolicy(String deviceId, int policyId, String status, Options options, [String? errorMessage, int? version]) async {
    try {
      await _dio.post(
        '/devices/$deviceId/policy-ack',
        data: {
          'policy_id': policyId,
          'status': status,
          'error_message': errorMessage,
          'version': version,
        },
        options: options,
      );
    } catch (e) {
      print('Failed to send policy-ack: $e');
    }
  }
}
