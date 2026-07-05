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

  /// Enter multi-app "home launcher" kiosk mode: whitelist [packages] for lock task and make
  /// this app the device HOME. The user picks an app from our launcher grid.
  static Future<bool> setKioskApps(
    List<String> packages, [
    Map<String, dynamic>? restrictions,
  ]) async {
    final result = await _channel.invokeMethod<bool>('setKioskApps', {
      'packages': packages,
      'restrictions': restrictions ?? {},
    });
    return result ?? false;
  }

  /// Launch an approved app by package name (used by the kiosk launcher tiles).
  static Future<bool> launchApp(String packageName) async {
    try {
      final result = await _channel.invokeMethod<bool>('launchApp', {
        'package': packageName,
      });
      return result ?? false;
    } on PlatformException {
      return false;
    } on MissingPluginException {
      return false;
    }
  }

  /// Returns launchable apps installed on the device as maps of
  /// `{package, label, icon}` where `icon` is a base64-encoded PNG. Used by the admin picker
  /// and the launcher to render app tiles.
  static Future<List<Map<String, dynamic>>> getInstalledApps() async {
    try {
      final result = await _channel.invokeListMethod<dynamic>('getInstalledApps');
      if (result == null) return [];
      return result
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList(growable: false);
    } on PlatformException {
      return [];
    } on MissingPluginException {
      return [];
    }
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
