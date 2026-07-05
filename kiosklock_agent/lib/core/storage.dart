import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Central, typed accessor for the small set of secure-storage keys the agent uses.
/// Previously these string keys were duplicated across services; keeping them here avoids
/// drift and typos.
class AppStorage {
  static final AppStorage instance = AppStorage();

  final FlutterSecureStorage _storage;

  AppStorage({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const kDeviceToken = 'device_token';
  static const kDeviceId = 'device_id';

  Future<String?> deviceToken() => _storage.read(key: kDeviceToken);
  Future<String?> deviceId() => _storage.read(key: kDeviceId);

  Future<String?> read(String key) => _storage.read(key: key);
  Future<void> write(String key, String value) => _storage.write(key: key, value: value);
  Future<void> delete(String key) => _storage.delete(key: key);
}
