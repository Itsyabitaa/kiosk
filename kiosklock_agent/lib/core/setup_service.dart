import 'package:flutter/services.dart';

import 'storage.dart';

/// Tracks first-run setup (SureLock-style onboarding) and bridges to native permission checks.
class SetupService {
  SetupService._();
  static final SetupService instance = SetupService._();

  static const _channel = MethodChannel('com.kiosklock/kiosk');

  static const kSetupComplete = 'setup_complete';
  static const kSetupIntent = 'setup_intent';
  static const kEulaAccepted = 'eula_accepted_v1';

  final _storage = AppStorage.instance;

  Future<bool> isComplete() async {
    final v = await _storage.read(kSetupComplete);
    return v == 'true';
  }

  Future<void> markComplete() async {
    await _storage.write(kSetupComplete, 'true');
  }

  Future<String?> setupIntent() => _storage.read(kSetupIntent);

  Future<void> saveSetupIntent(String intent) =>
      _storage.write(kSetupIntent, intent);

  Future<bool> isEulaAccepted() async {
    final v = await _storage.read(kEulaAccepted);
    return v == 'true';
  }

  Future<void> acceptEula() => _storage.write(kEulaAccepted, 'true');

  /// Returns permission rows from native Android checks.
  Future<List<SetupPermission>> fetchPermissions() async {
    try {
      final result = await _channel.invokeListMethod<dynamic>('getSetupPermissionsStatus');
      if (result == null) return [];
      return result
          .map((e) => SetupPermission.fromMap(Map<String, dynamic>.from(e as Map)))
          .toList();
    } on PlatformException {
      return [];
    } on MissingPluginException {
      return [];
    }
  }

  Future<bool> openPermission(String permissionId) async {
    try {
      final result = await _channel.invokeMethod<bool>('openSetupPermission', {
        'permissionId': permissionId,
      });
      return result ?? false;
    } on PlatformException {
      return false;
    } on MissingPluginException {
      return false;
    }
  }
}

class SetupPermission {
  final String id;
  final String title;
  final String description;
  final bool granted;
  final bool required;

  const SetupPermission({
    required this.id,
    required this.title,
    required this.description,
    required this.granted,
    required this.required,
  });

  factory SetupPermission.fromMap(Map<String, dynamic> map) {
    return SetupPermission(
      id: map['id'] as String? ?? '',
      title: map['title'] as String? ?? '',
      description: map['description'] as String? ?? '',
      granted: map['granted'] as bool? ?? false,
      required: map['required'] as bool? ?? false,
    );
  }
}
