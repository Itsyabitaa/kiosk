import 'package:flutter/material.dart';
import 'package:kiosklock_agent/features/enrollment/enrollment_repository.dart';

import 'package:kiosklock_agent/core/kiosk_channel.dart';
import 'package:kiosklock_agent/core/policy_sync_service.dart';
import 'package:kiosklock_agent/core/secure_exit_manager.dart';
import 'package:kiosklock_agent/features/browser/kiosk_browser_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  PolicySyncService.instance.startSync();
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'KioskLock Agent',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.deepPurple),
        useMaterial3: true,
      ),
      home: const StartupScreen(),
    );
  }
}

class StartupScreen extends StatefulWidget {
  const StartupScreen({super.key});

  @override
  State<StartupScreen> createState() => _StartupScreenState();
}

class _StartupScreenState extends State<StartupScreen> {
  final EnrollmentRepository _repository = EnrollmentRepository();

  @override
  void initState() {
    super.initState();
    _checkEnrollment();
  }

  Future<void> _checkEnrollment() async {
    bool enrolled = await _repository.isEnrolled();
    if (!mounted) return;
    
    if (enrolled) {
      Navigator.of(context).pushReplacement(MaterialPageRoute(
        builder: (_) => const PolicySyncScreen(),
      ));
    } else {
      Navigator.of(context).pushReplacement(MaterialPageRoute(
        builder: (_) => const EnrollmentScreen(),
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: CircularProgressIndicator(),
      ),
    );
  }
}

class EnrollmentScreen extends StatefulWidget {
  const EnrollmentScreen({super.key});

  @override
  State<EnrollmentScreen> createState() => _EnrollmentScreenState();
}

class _EnrollmentScreenState extends State<EnrollmentScreen> {
  final _tokenController = TextEditingController();
  final EnrollmentRepository _repository = EnrollmentRepository();
  bool _isLoading = false;

  void _enroll() async {
    setState(() => _isLoading = true);
    try {
      await _repository.enroll(_tokenController.text);
      // Trigger an immediate policy sync check now that we have a device token
      PolicySyncService.instance.syncPolicy();
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(
        builder: (_) => const PolicySyncScreen(),
      ));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Enrollment failed: $e')),
      );
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Enroll Device')),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            TextField(
              controller: _tokenController,
              decoration: const InputDecoration(labelText: 'Enrollment Code'),
            ),
            const SizedBox(height: 20),
            if (_isLoading)
              const CircularProgressIndicator()
            else
              ElevatedButton(
                onPressed: _enroll,
                child: const Text('Enroll'),
              ),
          ],
        ),
      ),
    );
  }
}

class PolicySyncScreen extends StatefulWidget {
  const PolicySyncScreen({super.key});

  @override
  State<PolicySyncScreen> createState() => _PolicySyncScreenState();
}

class _PolicySyncScreenState extends State<PolicySyncScreen> {
  int _tapCount = 0;
  DateTime? _firstTapTime;

  void _handleTap() {
    final now = DateTime.now();
    if (_firstTapTime == null || now.difference(_firstTapTime!) > const Duration(seconds: 3)) {
      _firstTapTime = now;
      _tapCount = 1;
    } else {
      _tapCount++;
      if (_tapCount >= 5) {
        _tapCount = 0;
        _firstTapTime = null;
        _showPinDialog();
      }
    }
  }

  void _showPinDialog() {
    final pinController = TextEditingController();
    String? errorMessage;

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setState) {
            return AlertDialog(
              title: const Text('Exit Kiosk Mode'),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (errorMessage != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12.0),
                      child: Text(
                        errorMessage!,
                        style: const TextStyle(color: Colors.red, fontSize: 13),
                      ),
                    ),
                  TextField(
                    controller: pinController,
                    decoration: const InputDecoration(labelText: 'Enter PIN'),
                    obscureText: true,
                    keyboardType: TextInputType.number,
                  ),
                ],
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
                ElevatedButton(
                  onPressed: () async {
                    try {
                      final success = await SecureExitManager.instance.verifyAndExit(pinController.text);
                      if (success && mounted) {
                        Navigator.of(context).pop();
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(content: Text('Kiosk Mode exited successfully.')),
                        );
                      }
                    } catch (e) {
                      setState(() {
                        errorMessage = e.toString().replaceAll('Exception: ', '');
                      });
                    }
                  },
                  child: const Text('Submit'),
                ),
              ],
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<Map<String, dynamic>?>(
      valueListenable: PolicySyncService.instance.activePolicyNotifier,
      builder: (context, policy, _) {
        if (policy != null && policy['policy_type'] == 'url_whitelist') {
          final restrictions = Map<String, dynamic>.from(policy['restrictions'] ?? {});
          final String homeUrl = policy['target'] ?? '';
          final List<String> allowedDomains = List<String>.from(restrictions['allowed_domains'] ?? []);
          final int idleTimeout = restrictions['idle_timeout_minutes'] ?? 5;
          final int refreshInterval = restrictions['refresh_interval_minutes'] ?? 0;

          return KioskBrowserScreen(
            homeUrl: homeUrl,
            allowedDomains: allowedDomains,
            idleTimeoutMinutes: idleTimeout,
            refreshIntervalMinutes: refreshInterval,
          );
        }

        return Scaffold(
          appBar: AppBar(title: const Text('Syncing Policies...')),
          body: GestureDetector(
            onTap: _handleTap,
            behavior: HitTestBehavior.opaque,
            child: const Center(
              child: Padding(
                padding: EdgeInsets.all(24.0),
                child: Text(
                  'Device is enrolled. Waiting for policies...\n\n(Tap screen 5 times in 3 seconds to exit)',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 16),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
