import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:kiosklock_agent/core/kiosk_channel.dart';
import 'package:kiosklock_agent/core/policy_sync_service.dart';
import 'package:kiosklock_agent/core/secure_exit_manager.dart';

/// PIN-protected local admin panel. From here an operator who knows the password can choose
/// what the device locks to — a single app or a single website — and start it immediately.
/// Works fully offline (standalone mode); the choice is persisted on-device and re-applied on
/// every reboot.
class KioskConfigScreen extends StatefulWidget {
  const KioskConfigScreen({super.key});

  @override
  State<KioskConfigScreen> createState() => _KioskConfigScreenState();
}

enum _LockMode { app, website }

class _KioskConfigScreenState extends State<KioskConfigScreen> {
  _LockMode _mode = _LockMode.app;

  final _packageController = TextEditingController();
  final _urlController = TextEditingController();
  final _domainsController = TextEditingController();
  final _idleController = TextEditingController(text: '5');

  bool _loading = true;
  bool _saving = false;
  bool _allowSubdomains = false;
  String? _error;

  static const _appPresets = <String, String>{
    'This app (self)': 'com.kiosklock.kiosklock_agent',
    'Chrome': 'com.android.chrome',
    'YouTube': 'com.google.android.youtube',
    'Maps': 'com.google.android.apps.maps',
  };

  @override
  void initState() {
    super.initState();
    _prefill();
  }

  Future<void> _prefill() async {
    final policy = await PolicySyncService.instance.loadSavedStandalonePolicy();
    if (policy != null) {
      final type = policy['policy_type'] as String?;
      final target = policy['target'] as String? ?? '';
      final restrictions = Map<String, dynamic>.from(policy['restrictions'] ?? {});
      if (type == 'url_whitelist') {
        _mode = _LockMode.website;
        _urlController.text = target;
        final domains = List<String>.from(restrictions['allowed_domains'] ?? []);
        _allowSubdomains = domains.any((d) => d.startsWith('*.'));
        _domainsController.text =
            domains.map((d) => d.startsWith('*.') ? d.substring(2) : d).join(', ');
        _idleController.text = '${restrictions['idle_timeout_minutes'] ?? 5}';
      } else {
        _mode = _LockMode.app;
        _packageController.text = target;
      }
    }
    if (mounted) setState(() => _loading = false);
  }

  @override
  void dispose() {
    _packageController.dispose();
    _urlController.dispose();
    _domainsController.dispose();
    _idleController.dispose();
    super.dispose();
  }

  String _hostOf(String url) {
    try {
      final uri = Uri.parse(url.trim());
      return uri.host;
    } catch (_) {
      return '';
    }
  }

  Future<void> _saveAndLock() async {
    setState(() {
      _saving = true;
      _error = null;
    });

    try {
      Map<String, dynamic> policy;

      if (_mode == _LockMode.app) {
        final pkg = _packageController.text.trim();
        if (pkg.isEmpty) {
          throw Exception('Enter the app package name to lock to.');
        }
        policy = {
          'id': 0,
          'version': DateTime.now().millisecondsSinceEpoch,
          'policy_type': 'single_app',
          'target': pkg,
          'restrictions': <String, dynamic>{},
        };
      } else {
        var url = _urlController.text.trim();
        if (url.isEmpty) {
          throw Exception('Enter the website URL to lock to.');
        }
        if (!url.startsWith('http://') && !url.startsWith('https://')) {
          url = 'https://$url';
        }

        // Allowed domains: use the operator list if provided, otherwise derive from the URL host.
        var domains = _domainsController.text
            .split(',')
            .map((d) => d.trim())
            .where((d) => d.isNotEmpty)
            .toList();
        if (domains.isEmpty) {
          final host = _hostOf(url);
          if (host.isNotEmpty) domains = [host];
        }

        final idle = int.tryParse(_idleController.text.trim()) ?? 5;

        policy = {
          'id': 0,
          'version': DateTime.now().millisecondsSinceEpoch,
          'policy_type': 'url_whitelist',
          'target': url,
          'restrictions': <String, dynamic>{
            'allowed_domains': domains,
            'idle_timeout_minutes': idle,
            'refresh_interval_minutes': 0,
          },
        };
      }

      await PolicySyncService.instance.saveAndApplyStandalonePolicy(policy);
      await SecureExitManager.instance
          .logKioskEvent('config_change', 'success', {'policy_type': policy['policy_type']});

      if (!mounted) return;
      final messenger = ScaffoldMessenger.of(context);
      Navigator.of(context).pop();
      messenger.showSnackBar(
        SnackBar(
          content: Text(_mode == _LockMode.app
              ? 'Locked to app: ${_packageController.text.trim()}'
              : 'Locked to website: ${_urlController.text.trim()}'),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _error = e.toString().replaceAll('Exception: ', ''));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _exitKiosk() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Exit kiosk mode?'),
        content: const Text(
            'This unlocks the device and stops enforcing the kiosk policy until it is started again.'),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          ElevatedButton(onPressed: () => Navigator.of(ctx).pop(true), child: const Text('Exit')),
        ],
      ),
    );
    if (confirmed != true) return;

    await KioskChannel.unlock();
    await SecureExitManager.instance
        .logKioskEvent('exit_attempt', 'success', {'reason': 'admin panel exit'});

    if (!mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    Navigator.of(context).pop();
    messenger.showSnackBar(
      const SnackBar(content: Text('Kiosk mode exited. Device unlocked.')),
    );
  }

  Future<void> _changePin() async {
    final currentController = TextEditingController();
    final newController = TextEditingController();
    String? dialogError;

    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('Change PIN'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (dialogError != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(dialogError!, style: const TextStyle(color: Colors.red, fontSize: 13)),
                ),
              TextField(
                controller: currentController,
                obscureText: true,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Current PIN'),
              ),
              TextField(
                controller: newController,
                obscureText: true,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'New PIN (min 4 digits)'),
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
            ElevatedButton(
              onPressed: () async {
                try {
                  await SecureExitManager.instance
                      .changePin(currentController.text, newController.text);
                  if (ctx.mounted) Navigator.of(ctx).pop();
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('PIN updated.')),
                    );
                  }
                } catch (e) {
                  setLocal(() => dialogError = e.toString().replaceAll('Exception: ', ''));
                }
              },
              child: const Text('Save'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Kiosk Configuration'),
        actions: [
          IconButton(
            tooltip: 'Change PIN',
            icon: const Icon(Icons.password),
            onPressed: _changePin,
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text(
            'Choose what this device locks to. The setting is saved on the device and re-applied '
            'automatically after every reboot — no internet required.',
            style: TextStyle(fontSize: 13, color: Colors.black54),
          ),
          const SizedBox(height: 16),
          SegmentedButton<_LockMode>(
            segments: const [
              ButtonSegment(
                value: _LockMode.app,
                icon: Icon(Icons.apps),
                label: Text('Lock to App'),
              ),
              ButtonSegment(
                value: _LockMode.website,
                icon: Icon(Icons.public),
                label: Text('Lock to Website'),
              ),
            ],
            selected: {_mode},
            onSelectionChanged: (s) => setState(() => _mode = s.first),
          ),
          const SizedBox(height: 20),
          if (_mode == _LockMode.app) ..._buildAppFields() else ..._buildWebsiteFields(),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 16),
              child: Text(_error!, style: const TextStyle(color: Colors.red)),
            ),
          const SizedBox(height: 24),
          FilledButton.icon(
            onPressed: _saving ? null : _saveAndLock,
            icon: _saving
                ? const SizedBox(
                    width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.lock),
            label: Text(_saving ? 'Applying...' : 'Save & Lock'),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _saving ? null : _exitKiosk,
            icon: const Icon(Icons.lock_open),
            label: const Text('Exit kiosk (unlock device)'),
          ),
        ],
      ),
    );
  }

  List<Widget> _buildAppFields() {
    return [
      TextField(
        controller: _packageController,
        decoration: const InputDecoration(
          labelText: 'App package name',
          hintText: 'e.g. com.android.chrome',
          border: OutlineInputBorder(),
        ),
      ),
      const SizedBox(height: 12),
      const Text('Quick pick:', style: TextStyle(fontSize: 13, color: Colors.black54)),
      const SizedBox(height: 8),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: _appPresets.entries
            .map((e) => ActionChip(
                  label: Text(e.key),
                  onPressed: () => setState(() => _packageController.text = e.value),
                ))
            .toList(),
      ),
    ];
  }

  List<Widget> _buildWebsiteFields() {
    return [
      TextField(
        controller: _urlController,
        keyboardType: TextInputType.url,
        decoration: const InputDecoration(
          labelText: 'Website URL',
          hintText: 'e.g. https://example.com',
          border: OutlineInputBorder(),
        ),
      ),
      const SizedBox(height: 12),
      TextField(
        controller: _domainsController,
        decoration: const InputDecoration(
          labelText: 'Allowed domains (optional, comma-separated)',
          hintText: 'left blank = only the URL\'s domain',
          border: OutlineInputBorder(),
        ),
      ),
      const SizedBox(height: 12),
      TextField(
        controller: _idleController,
        keyboardType: TextInputType.number,
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        decoration: const InputDecoration(
          labelText: 'Idle timeout (minutes, 0 = never)',
          border: OutlineInputBorder(),
        ),
      ),
    ];
  }
}
