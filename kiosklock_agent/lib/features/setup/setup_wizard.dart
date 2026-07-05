import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'package:kiosklock_agent/core/setup_service.dart';

/// SureLock-style first-run wizard: intent → EULA → grant permissions → kiosk.
class SetupWizard extends StatefulWidget {
  final VoidCallback onComplete;

  const SetupWizard({super.key, required this.onComplete});

  @override
  State<SetupWizard> createState() => _SetupWizardState();
}

class _SetupWizardState extends State<SetupWizard> with WidgetsBindingObserver {
  int _step = 0;
  String _intent = 'enterprise';
  String _appVersion = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _loadVersion();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Refresh permission list when returning from system settings.
    if (state == AppLifecycleState.resumed && _step == 2) {
      setState(() {});
    }
  }

  Future<void> _loadVersion() async {
    final info = await PackageInfo.fromPlatform();
    if (mounted) setState(() => _appVersion = info.version);
  }

  void _next() => setState(() => _step++);

  Future<void> _finish() async {
    await SetupService.instance.markComplete();
    widget.onComplete();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: switch (_step) {
        0 => _IntentStep(
            selected: _intent,
            onSelected: (v) => setState(() => _intent = v),
            onContinue: () async {
              await SetupService.instance.saveSetupIntent(_intent);
              _next();
            },
          ),
        1 => _EulaStep(
            onDeny: () => Navigator.of(context).pop(),
            onAccept: () async {
              await SetupService.instance.acceptEula();
              _next();
            },
          ),
        _ => _PermissionsStep(
            onComplete: _finish,
            appVersion: _appVersion,
          ),
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Step 1 — "I want to use KioskLock"
// ---------------------------------------------------------------------------

class _IntentStep extends StatelessWidget {
  final String selected;
  final ValueChanged<String> onSelected;
  final VoidCallback onContinue;

  const _IntentStep({
    required this.selected,
    required this.onSelected,
    required this.onContinue,
  });

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Column(
        children: [
          const Spacer(flex: 2),
          const Icon(Icons.lock, size: 72, color: Color(0xFF1B5E20)),
          const SizedBox(height: 8),
          const Text(
            'KioskLock',
            style: TextStyle(
              fontSize: 32,
              fontWeight: FontWeight.bold,
              color: Color(0xFF1B5E20),
            ),
          ),
          const Text(
            'Kiosk Lockdown',
            style: TextStyle(fontSize: 16, color: Color(0xFF2E7D32)),
          ),
          const Spacer(),
          const Text(
            'I want to use KioskLock',
            style: TextStyle(fontSize: 16, color: Colors.black54),
          ),
          const SizedBox(height: 16),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: Container(
              decoration: BoxDecoration(
                color: const Color(0xFFE8EEF5),
                borderRadius: BorderRadius.circular(8),
              ),
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Column(
                children: [
                  RadioListTile<String>(
                    value: 'launcher',
                    groupValue: selected,
                    onChanged: (v) => onSelected(v!),
                    title: const Text('As yet another home screen launcher.'),
                  ),
                  RadioListTile<String>(
                    value: 'enterprise',
                    groupValue: selected,
                    onChanged: (v) => onSelected(v!),
                    title: const Text('To prevent device misuse in my enterprise.'),
                  ),
                  RadioListTile<String>(
                    value: 'unsure',
                    groupValue: selected,
                    onChanged: (v) => onSelected(v!),
                    title: const Text('Not sure.'),
                  ),
                ],
              ),
            ),
          ),
          const Spacer(flex: 2),
          Padding(
            padding: const EdgeInsets.all(24),
            child: SizedBox(
              width: double.infinity,
              height: 48,
              child: FilledButton(
                style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFF1A237E),
                ),
                onPressed: onContinue,
                child: const Text('Continue'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Step 2 — End User License Agreement
// ---------------------------------------------------------------------------

class _EulaStep extends StatelessWidget {
  final VoidCallback onDeny;
  final VoidCallback onAccept;

  const _EulaStep({required this.onDeny, required this.onAccept});

  static const _eulaText = '''
END USER LICENSE AGREEMENT

This End User License Agreement ("Agreement") is a legal and binding agreement between you ("Licensee") and KioskLock Pro ("Licensor").

BY CLICKING "ACCEPT" BELOW, YOU AGREE TO BE LEGALLY BOUND BY THE TERMS OF THIS AGREEMENT. IF YOU DO NOT AGREE, DO NOT INSTALL OR USE THIS SOFTWARE.

1. LICENSE GRANT
Licensor grants Licensee a non-exclusive, non-transferable license to use KioskLock Pro on managed devices for kiosk lockdown and device management purposes.

2. RESTRICTIONS
Licensee shall not reverse engineer, decompile, or attempt to bypass kiosk security controls except through authorized administrator credentials.

3. DEVICE MANAGEMENT
Licensee acknowledges that KioskLock Pro requires elevated device permissions (device admin, usage access, overlay) to enforce lockdown policies.

4. DATA
Telemetry and configuration data may be stored locally on the device. When enrolled with a server, device status may be reported to the configured backend.

5. WARRANTY DISCLAIMER
THE SOFTWARE IS PROVIDED "AS IS" WITHOUT WARRANTY OF ANY KIND.

6. TERMINATION
This license terminates if Licensee violates any term of this Agreement or disables required security permissions without authorization.
''';

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: Colors.black54,
      child: Center(
        child: Card(
          margin: const EdgeInsets.all(24),
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  'End User License Agreement',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 12),
                SizedBox(
                  height: MediaQuery.of(context).size.height * 0.45,
                  child: SingleChildScrollView(
                    child: Text(
                      _eulaText,
                      style: const TextStyle(fontSize: 13, height: 1.4),
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    TextButton(onPressed: onDeny, child: const Text('DENY')),
                    const SizedBox(width: 8),
                    TextButton(onPressed: onAccept, child: const Text('ACCEPT')),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Step 3 — Grant App Permissions (SureLock-style checklist)
// ---------------------------------------------------------------------------

class _PermissionsStep extends StatefulWidget {
  final VoidCallback onComplete;
  final String appVersion;

  const _PermissionsStep({required this.onComplete, required this.appVersion});

  @override
  State<_PermissionsStep> createState() => _PermissionsStepState();
}

class _PermissionsStepState extends State<_PermissionsStep> {
  List<SetupPermission> _permissions = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    setState(() => _loading = true);
    final list = await SetupService.instance.fetchPermissions();
    if (mounted) {
      setState(() {
        _permissions = list;
        _loading = false;
      });
    }
  }

  bool get _allRequiredGranted =>
      _permissions.where((p) => p.required).every((p) => p.granted);

  int get _pendingCount => _permissions.where((p) => !p.granted).length;

  Future<void> _open(SetupPermission p) async {
    await SetupService.instance.openPermission(p.id);
  }

  IconData _iconFor(String id) {
    return switch (id) {
      'device_admin' => Icons.admin_panel_settings,
      'device_owner' => Icons.verified_user,
      'default_launcher' => Icons.home,
      'usage_access' => Icons.analytics_outlined,
      'overlay' => Icons.layers,
      'all_files_access' => Icons.folder,
      'notification_access' => Icons.notifications,
      'exact_alarm' => Icons.alarm,
      'battery_optimization' => Icons.battery_charging_full,
      'accessibility' => Icons.accessibility_new,
      _ => Icons.security,
    };
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          width: double.infinity,
          color: Colors.black,
          padding: EdgeInsets.only(
            top: MediaQuery.of(context).padding.top + 16,
            bottom: 16,
            left: 16,
            right: 16,
          ),
          child: const Text(
            'Grant App Permissions.',
            style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w500),
          ),
        ),
        if (_loading)
          const Expanded(child: Center(child: CircularProgressIndicator()))
        else
          Expanded(
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: ListView.separated(
                padding: const EdgeInsets.only(bottom: 8),
                itemCount: _permissions.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final p = _permissions[i];
                  return ListTile(
                    leading: Icon(_iconFor(p.id), size: 28),
                    title: Text(
                      p.title,
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        color: p.granted ? Colors.black38 : Colors.black,
                      ),
                    ),
                    subtitle: Text(
                      p.description,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12),
                    ),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          p.granted ? Icons.check_circle : Icons.error,
                          color: p.granted ? Colors.green : Colors.red,
                          size: 22,
                        ),
                        const Icon(Icons.chevron_right),
                      ],
                    ),
                    onTap: p.granted ? null : () => _open(p),
                  );
                },
              ),
            ),
          ),
        if (!_loading && _pendingCount > 0)
          Material(
            color: Colors.grey.shade800,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              child: Text(
                'Grant all pending permissions and tap Continue.${_pendingCount > 0 ? ' ($_pendingCount remaining)' : ''}',
                style: const TextStyle(color: Colors.white, fontSize: 13),
              ),
            ),
          ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: SizedBox(
              width: double.infinity,
              height: 48,
              child: FilledButton(
                style: FilledButton.styleFrom(
                  backgroundColor: _allRequiredGranted
                      ? const Color(0xFF1A237E)
                      : Colors.red.shade700,
                ),
                onPressed: widget.onComplete,
                child: Text(_allRequiredGranted ? 'Continue' : 'Continue Anyway'),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
