import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

import 'kiosk_channel.dart';
import 'networking.dart';
import 'policy_sync_service.dart';
import 'storage.dart';

/// Persistent real-time command channel.
///
/// Connects to Laravel Reverb over the Pusher wire protocol using `web_socket_channel`,
/// subscribes to the device's private channel (`private-device.{id}`) via the JWT-authorized
/// `/broadcasting/auth` endpoint, and executes pushed commands instantly. A slow HTTP poll of
/// the durable `mdm_commands` queue runs as a safety net for flaky/persistent-connection loss.
class CommandChannelService {
  static final CommandChannelService instance = CommandChannelService();

  final Dio _dio;
  final AppStorage _storage;

  CommandChannelService({Dio? dio, AppStorage? storage})
      : _dio = dio ?? Networking.authClient(),
        _storage = storage ?? AppStorage.instance;

  WebSocketChannel? _channel;
  StreamSubscription? _subscription;
  Timer? _fallbackTimer;
  Timer? _reconnectTimer;
  int _reconnectAttempts = 0;
  bool _started = false;
  String? _deviceId;

  /// Poll fallback interval — deliberately slow (the WS is the primary path).
  static const Duration fallbackPollInterval = Duration(seconds: 60);
  static const Duration maxReconnectBackoff = Duration(seconds: 30);

  Future<void> start() async {
    if (_started) return;
    _started = true;

    _deviceId = await _storage.deviceId();
    if (_deviceId == null) {
      _started = false;
      return;
    }

    await _connect();

    // Safety-net poll of the durable command queue.
    _fallbackTimer?.cancel();
    _fallbackTimer = Timer.periodic(fallbackPollInterval, (_) => _pollFallback());
  }

  void stop() {
    _started = false;
    _reconnectTimer?.cancel();
    _fallbackTimer?.cancel();
    _subscription?.cancel();
    _channel?.sink.close();
    _channel = null;
  }

  Future<void> _connect() async {
    try {
      final channel = WebSocketChannel.connect(Uri.parse(Networking.wsUrl()));
      _channel = channel;

      _subscription = channel.stream.listen(
        _onMessage,
        onDone: _scheduleReconnect,
        onError: (_) => _scheduleReconnect(),
        cancelOnError: true,
      );
    } catch (e) {
      _scheduleReconnect();
    }
  }

  void _scheduleReconnect() {
    if (!_started) return;
    _subscription?.cancel();
    _channel = null;

    // Exponential backoff capped at maxReconnectBackoff.
    final seconds = (1 << _reconnectAttempts).clamp(1, maxReconnectBackoff.inSeconds);
    _reconnectAttempts = (_reconnectAttempts + 1).clamp(0, 5);

    _reconnectTimer?.cancel();
    _reconnectTimer = Timer(Duration(seconds: seconds), () {
      if (_started) _connect();
    });
  }

  Future<void> _onMessage(dynamic raw) async {
    try {
      final Map<String, dynamic> message = jsonDecode(raw as String);
      final String? event = message['event'];

      switch (event) {
        case 'pusher:connection_established':
          _reconnectAttempts = 0;
          final data = _decodeData(message['data']);
          final socketId = data['socket_id'] as String?;
          if (socketId != null) {
            await _subscribeToDeviceChannel(socketId);
          }
          break;
        case 'command':
          final data = _decodeData(message['data']);
          await _handleCommand(data);
          break;
        default:
          // pusher_internal:subscription_succeeded, pusher:pong, pusher:error, etc.
          break;
      }
    } catch (e) {
      if (kDebugMode) print('CommandChannel message error: $e');
    }
  }

  Map<String, dynamic> _decodeData(dynamic data) {
    if (data is String) {
      return Map<String, dynamic>.from(jsonDecode(data));
    }
    if (data is Map) {
      return Map<String, dynamic>.from(data);
    }
    return {};
  }

  Future<void> _subscribeToDeviceChannel(String socketId) async {
    final channelName = 'private-device.$_deviceId';

    try {
      final response = await _dio.post('/broadcasting/auth', data: {
        'socket_id': socketId,
        'channel_name': channelName,
      });

      final auth = response.data['auth'];
      _channel?.sink.add(jsonEncode({
        'event': 'pusher:subscribe',
        'data': {'auth': auth, 'channel': channelName},
      }));
    } catch (e) {
      if (kDebugMode) print('CommandChannel subscribe failed: $e');
    }
  }

  /// Execute a command and acknowledge it so the backend can confirm delivery.
  Future<void> _handleCommand(Map<String, dynamic> command) async {
    final String? type = command['command_type'];
    final commandId = command['id'];

    bool handled = true;
    switch (type) {
      case 'policy_update':
        await PolicySyncService.instance.syncPolicy();
        break;
      case 'lock_command':
        // Re-sync to (re)apply the assigned policy's lock configuration.
        await PolicySyncService.instance.syncPolicy();
        break;
      case 'unlock_command':
        await KioskChannel.unlock();
        break;
      case 'reboot_command':
        await KioskChannel.reboot();
        break;
      case 'wipe_command':
        await KioskChannel.wipe();
        break;
      default:
        handled = false;
    }

    if (handled && commandId != null) {
      await _ackCommand(commandId);
    }
  }

  Future<void> _ackCommand(dynamic commandId) async {
    if (_deviceId == null) return;
    try {
      await _dio.post('/devices/$_deviceId/mdm/commands/$commandId/ack');
    } catch (e) {
      if (kDebugMode) print('CommandChannel ack failed: $e');
    }
  }

  /// Fallback: pull pending commands from the durable queue and execute any the WS missed.
  Future<void> _pollFallback() async {
    if (_deviceId == null) return;
    try {
      final response = await _dio.get('/devices/$_deviceId/mdm/commands');
      final commands = (response.data['commands'] as List?) ?? [];
      for (final c in commands) {
        await _handleCommand(Map<String, dynamic>.from(c));
      }
    } catch (e) {
      if (kDebugMode) print('CommandChannel fallback poll failed: $e');
    }
  }
}
