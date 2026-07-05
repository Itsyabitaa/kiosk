import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:kiosklock_agent/core/kiosk_channel.dart';

/// The kiosk "home screen". Shows only the approved apps as tiles; tapping one launches it in
/// lock-task mode. A hidden 5-tap gesture on the header opens the PIN-protected admin panel.
class KioskLauncherScreen extends StatefulWidget {
  /// Each entry is `{package, label?}` as saved by the config panel.
  final List<Map<String, dynamic>> apps;

  /// Called after the admin password is verified (wired by the host to open the config panel).
  final VoidCallback onAdminRequested;

  const KioskLauncherScreen({
    super.key,
    required this.apps,
    required this.onAdminRequested,
  });

  @override
  State<KioskLauncherScreen> createState() => _KioskLauncherScreenState();
}

class _KioskLauncherScreenState extends State<KioskLauncherScreen> {
  final Map<String, Uint8List> _icons = {};
  final Map<String, String> _labels = {};
  bool _loading = true;

  int _tapCount = 0;
  DateTime? _firstTapTime;

  @override
  void initState() {
    super.initState();
    _loadIcons();
  }

  Future<void> _loadIcons() async {
    final installed = await KioskChannel.getInstalledApps();
    for (final app in installed) {
      final pkg = app['package'] as String?;
      if (pkg == null) continue;
      final iconB64 = app['icon'] as String?;
      if (iconB64 != null && iconB64.isNotEmpty) {
        try {
          _icons[pkg] = base64Decode(iconB64);
        } catch (_) {}
      }
      final label = app['label'] as String?;
      if (label != null) _labels[pkg] = label;
    }
    if (mounted) setState(() => _loading = false);
  }

  void _handleHeaderTap() {
    final now = DateTime.now();
    if (_firstTapTime == null || now.difference(_firstTapTime!) > const Duration(seconds: 3)) {
      _firstTapTime = now;
      _tapCount = 1;
    } else {
      _tapCount++;
      if (_tapCount >= 5) {
        _tapCount = 0;
        _firstTapTime = null;
        widget.onAdminRequested();
      }
    }
  }

  String _labelFor(Map<String, dynamic> app) {
    final pkg = app['package'] as String? ?? '';
    final configured = (app['label'] as String?)?.trim();
    if (configured != null && configured.isNotEmpty) return configured;
    return _labels[pkg] ?? pkg;
  }

  Future<void> _launch(String pkg) async {
    final ok = await KioskChannel.launchApp(pkg);
    if (!ok && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Could not launch $pkg (not installed?)')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final apps = widget.apps
        .where((a) => ((a['package'] as String?) ?? '').isNotEmpty)
        .toList(growable: false);

    return Scaffold(
      backgroundColor: const Color(0xFF101319),
      body: SafeArea(
        child: Column(
          children: [
            GestureDetector(
              onTap: _handleHeaderTap,
              behavior: HitTestBehavior.opaque,
              child: Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(vertical: 28, horizontal: 20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: const [
                    Text(
                      'KioskLock',
                      style: TextStyle(
                          color: Colors.white,
                          fontSize: 26,
                          fontWeight: FontWeight.bold,
                          letterSpacing: 0.5),
                    ),
                    SizedBox(height: 4),
                    Text(
                      'Tap an app to begin',
                      style: TextStyle(color: Colors.white54, fontSize: 14),
                    ),
                  ],
                ),
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : apps.isEmpty
                      ? _emptyState()
                      : GridView.builder(
                          padding: const EdgeInsets.all(20),
                          gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                            maxCrossAxisExtent: 130,
                            mainAxisSpacing: 20,
                            crossAxisSpacing: 20,
                            childAspectRatio: 0.85,
                          ),
                          itemCount: apps.length,
                          itemBuilder: (context, i) => _appTile(apps[i]),
                        ),
            ),
            GestureDetector(
              onTap: _handleHeaderTap,
              behavior: HitTestBehavior.opaque,
              child: const Padding(
                padding: EdgeInsets.only(bottom: 12, top: 4),
                child: Text(
                  'Secured by KioskLock Pro',
                  style: TextStyle(color: Colors.white24, fontSize: 11),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _emptyState() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: const [
            Icon(Icons.apps, color: Colors.white38, size: 64),
            SizedBox(height: 16),
            Text(
              'No apps configured.\nTap the title 5 times to open admin settings.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.white54, fontSize: 15),
            ),
          ],
        ),
      ),
    );
  }

  Widget _appTile(Map<String, dynamic> app) {
    final pkg = app['package'] as String? ?? '';
    final icon = _icons[pkg];

    return InkWell(
      onTap: () => _launch(pkg),
      borderRadius: BorderRadius.circular(18),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            width: 76,
            height: 76,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
              boxShadow: const [
                BoxShadow(color: Colors.black26, blurRadius: 8, offset: Offset(0, 3)),
              ],
            ),
            padding: const EdgeInsets.all(12),
            child: icon != null
                ? Image.memory(icon, fit: BoxFit.contain)
                : const Icon(Icons.android, size: 40, color: Colors.black45),
          ),
          const SizedBox(height: 8),
          Text(
            _labelFor(app),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: const TextStyle(color: Colors.white, fontSize: 13),
          ),
        ],
      ),
    );
  }
}
