import 'package:flutter/services.dart';

class KioskChannel {
  static const _channel = MethodChannel('com.kiosklock/kiosk');

  static Future<bool> lockToApp(String packageName, [Map<String, dynamic>? restrictions]) async {
    final result = await _channel.invokeMethod<bool>('lockToApp', {
      'package': packageName,
      'restrictions': restrictions ?? {},
    });
    return result ?? false;
  }

  static Future<bool> unlock() async {
    final result = await _channel.invokeMethod<bool>('unlock');
    return result ?? false;
  }

  /// Remotely reboot the device (device-owner only).
  static Future<bool> reboot() async {
    try {
      final result = await _channel.invokeMethod<bool>('reboot');
      return result ?? false;
    } on PlatformException {
      return false;
    } on MissingPluginException {
      return false;
    }
  }

  /// Remotely factory-wipe the device (device-owner only). Destructive.
  static Future<bool> wipe() async {
    try {
      final result = await _channel.invokeMethod<bool>('wipe');
      return result ?? false;
    } on PlatformException {
      return false;
    } on MissingPluginException {
      return false;
    }
  }

  static Future<bool> applyRestrictions(Map<String, dynamic> restrictions) async {
    final result = await _channel.invokeMethod<bool>('applyRestrictions', restrictions);
    return result ?? false;
  }

  static Future<Map<String, dynamic>> getDeviceState() async {
    final result = await _channel.invokeMapMethod<String, dynamic>('getDeviceState');
    return result ?? {};
  }

  /// Returns the admin extras bundle (enrollment_token, policy_id, ...) captured during
  /// QR/NFC device-owner provisioning, or null when the device was not provisioned via QR.
  static Future<Map<String, dynamic>?> getAdminExtras() async {
    try {
      final result = await _channel.invokeMapMethod<String, dynamic>('getAdminExtras');
      if (result == null || result['enrollment_token'] == null) {
        return null;
      }
      return result;
    } on PlatformException {
      return null;
    } on MissingPluginException {
      return null;
    }
  }
}
