import 'package:flutter/material.dart';
import 'package:kiosklock_agent/features/enrollment/enrollment_repository.dart';

import 'package:kiosklock_agent/core/policy_sync_service.dart';

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

class PolicySyncScreen extends StatelessWidget {
  const PolicySyncScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Syncing Policies...')),
      body: const Center(
        child: Text('Device is enrolled. Waiting for policies...'),
      ),
    );
  }
}
