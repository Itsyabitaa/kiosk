import 'dart:convert';

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

enum _LockMode { app, website, home }

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

  /// Approved apps for the multi-app home launcher: each is `{package, label}`.
  final List<Map<String, dynamic>> _homeApps = [];

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
      } else if (type == 'multi_app') {
        _mode = _LockMode.home;
        final apps = (restrictions['apps'] as List?) ?? [];
        _homeApps
            .addAll(apps.map((e) => Map<String, dynamic>.from(e as Map)));
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

      if (_mode == _LockMode.home) {
        if (_homeApps.isEmpty) {
          throw Exception('Add at least one app to the home screen.');
        }
        policy = {
          'id': 0,
          'version': DateTime.now().millisecondsSinceEpoch,
          'policy_type': 'multi_app',
          'target': null,
          'restrictions': <String, dynamic>{
            'apps': _homeApps,
          },
        };
      } else if (_mode == _LockMode.app) {
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

        // "Allow sub-domains" → store each host as a wildcard entry (*.host). The kiosk browser's
        // matcher treats *.example.com as matching example.com and any sub-domain of it.
        if (_allowSubdomains) {
          domains = domains
              .map((d) => d.startsWith('*.') ? d : '*.${d.replaceFirst(RegExp(r'^\*\.'), '')}')
              .toList();
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
          content: Text(_mode == _LockMode.home
              ? 'Kiosk home set with ${_homeApps.length} app(s).'
              : _mode == _LockMode.app
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
                icon: Icon(Icons.smartphone),
                label: Text('App'),
              ),
              ButtonSegment(
                value: _LockMode.website,
                icon: Icon(Icons.public),
                label: Text('Website'),
              ),
              ButtonSegment(
                value: _LockMode.home,
                icon: Icon(Icons.grid_view),
                label: Text('Home'),
              ),
            ],
            selected: {_mode},
            onSelectionChanged: (s) => setState(() => _mode = s.first),
          ),
          const SizedBox(height: 20),
          if (_mode == _LockMode.app)
            ..._buildAppFields()
          else if (_mode == _LockMode.website)
            ..._buildWebsiteFields()
          else
            ..._buildHomeFields(),
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
      const SizedBox(height: 4),
      CheckboxListTile(
        contentPadding: EdgeInsets.zero,
        controlAffinity: ListTileControlAffinity.leading,
        value: _allowSubdomains,
        onChanged: (v) => setState(() => _allowSubdomains = v ?? false),
        title: const Text('Allow sub-domains'),
        subtitle: const Text('Permit any sub-domain of the allowed domains (e.g. app.example.com)'),
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

  List<Widget> _buildHomeFields() {
    return [
      const Text(
        'These apps appear on the kiosk home screen. The user can only open apps from this list; '
        'everything else on the device stays hidden.',
        style: TextStyle(fontSize: 13, color: Colors.black54),
      ),
      const SizedBox(height: 12),
      OutlinedButton.icon(
        onPressed: _pickApps,
        icon: const Icon(Icons.add),
        label: const Text('Add apps'),
      ),
      const SizedBox(height: 8),
      if (_homeApps.isEmpty)
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 12),
          child: Text('No apps added yet.', style: TextStyle(color: Colors.black45)),
        )
      else
        ..._homeApps.map((app) => ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.android),
              title: Text((app['label'] as String?)?.isNotEmpty == true
                  ? app['label'] as String
                  : app['package'] as String),
              subtitle: Text(app['package'] as String),
              trailing: IconButton(
                icon: const Icon(Icons.delete_outline),
                onPressed: () => setState(() => _homeApps.remove(app)),
              ),
            )),
    ];
  }

  Future<void> _pickApps() async {
    final selectedPackages = _homeApps.map((a) => a['package'] as String).toSet();
    final result = await showModalBottomSheet<List<Map<String, dynamic>>>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _AppPickerSheet(initiallySelected: selectedPackages),
    );
    if (result != null) {
      setState(() {
        _homeApps
          ..clear()
          ..addAll(result);
      });
    }
  }
}

/// Bottom-sheet multi-select of installed launchable apps (icon + label + package).
class _AppPickerSheet extends StatefulWidget {
  final Set<String> initiallySelected;
  const _AppPickerSheet({required this.initiallySelected});

  @override
  State<_AppPickerSheet> createState() => _AppPickerSheetState();
}

class _AppPickerSheetState extends State<_AppPickerSheet> {
  List<Map<String, dynamic>> _apps = [];
  final Map<String, Uint8List> _icons = {};
  final Set<String> _selected = {};
  bool _loading = true;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _selected.addAll(widget.initiallySelected);
    _load();
  }

  Future<void> _load() async {
    final installed = await KioskChannel.getInstalledApps();
    for (final app in installed) {
      final pkg = app['package'] as String?;
      final iconB64 = app['icon'] as String?;
      if (pkg != null && iconB64 != null && iconB64.isNotEmpty) {
        try {
          _icons[pkg] = base64Decode(iconB64);
        } catch (_) {}
      }
    }
    if (mounted) {
      setState(() {
        _apps = installed;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final filtered = _apps.where((a) {
      if (_query.isEmpty) return true;
      final label = ((a['label'] as String?) ?? '').toLowerCase();
      final pkg = ((a['package'] as String?) ?? '').toLowerCase();
      final q = _query.toLowerCase();
      return label.contains(q) || pkg.contains(q);
    }).toList();

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.85,
      maxChildSize: 0.95,
      builder: (context, scrollController) {
        return Column(
          children: [
            const SizedBox(height: 12),
            Container(width: 40, height: 4, color: Colors.black26),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  const Text('Select apps',
                      style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  const Spacer(),
                  FilledButton(
                    onPressed: () {
                      final chosen = _apps
                          .where((a) => _selected.contains(a['package']))
                          .map((a) => {
                                'package': a['package'],
                                'label': a['label'],
                              })
                          .toList();
                      Navigator.of(context).pop(chosen);
                    },
                    child: Text('Done (${_selected.length})'),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: TextField(
                onChanged: (v) => setState(() => _query = v),
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'Search apps',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
              ),
            ),
            const SizedBox(height: 8),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : ListView.builder(
                      controller: scrollController,
                      itemCount: filtered.length,
                      itemBuilder: (context, i) {
                        final app = filtered[i];
                        final pkg = app['package'] as String? ?? '';
                        final icon = _icons[pkg];
                        final checked = _selected.contains(pkg);
                        return CheckboxListTile(
                          value: checked,
                          onChanged: (v) => setState(() {
                            if (v == true) {
                              _selected.add(pkg);
                            } else {
                              _selected.remove(pkg);
                            }
                          }),
                          secondary: icon != null
                              ? Image.memory(icon, width: 40, height: 40)
                              : const Icon(Icons.android, size: 40),
                          title: Text((app['label'] as String?) ?? pkg),
                          subtitle: Text(pkg, maxLines: 1, overflow: TextOverflow.ellipsis),
                        );
                      },
                    ),
            ),
          ],
        );
      },
    );
  }
}
