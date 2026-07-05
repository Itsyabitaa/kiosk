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

  static Future<bool> applyRestrictions(Map<String, dynamic> restrictions) async {
    final result = await _channel.invokeMethod<bool>('applyRestrictions', restrictions);
    return result ?? false;
  }

  static Future<Map<String, dynamic>> getDeviceState() async {
    final result = await _channel.invokeMapMethod<String, dynamic>('getDeviceState');
    return result ?? {};
  }
}
