import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:kiosklock_agent/core/secure_exit_manager.dart';
import 'package:kiosklock_agent/features/config/kiosk_config_screen.dart';

class KioskBrowserScreen extends StatefulWidget {
  final String homeUrl;
  final List<String> allowedDomains;
  final int idleTimeoutMinutes;
  final int refreshIntervalMinutes;

  const KioskBrowserScreen({
    super.key,
    required this.homeUrl,
    required this.allowedDomains,
    this.idleTimeoutMinutes = 5,
    this.refreshIntervalMinutes = 0,
  });

  static bool isDomainAllowed(String url, List<String> allowedDomains) {
    if (allowedDomains.isEmpty) return false;
    final uri = Uri.tryParse(url);
    if (uri == null || uri.host.isEmpty) return false;
    final host = uri.host.toLowerCase();
    
    for (var pattern in allowedDomains) {
      final cleanPattern = pattern.trim().toLowerCase();
      if (cleanPattern == host) return true;
      if (cleanPattern.startsWith('*.')) {
        final suffix = cleanPattern.substring(2);
        if (host == suffix || host.endsWith('.$suffix')) {
          return true;
        }
      }
    }
    return false;
  }

  @override
  State<KioskBrowserScreen> createState() => _KioskBrowserScreenState();
}

class _KioskBrowserScreenState extends State<KioskBrowserScreen> {
  late final WebViewController _controller;
  bool _isOffline = false;
  bool _isConfigError = false;
  Timer? _idleTimer;
  Timer? _refreshTimer;

  int _exitTapCount = 0;
  DateTime? _exitFirstTapTime;

  @override
  void initState() {
    super.initState();
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);

    _validateConfig();
    if (!_isConfigError) {
      _initWebView();
      _startIdleTimer();
      _startRefreshTimer();
    }
  }

  void _validateConfig() {
    if (widget.homeUrl.isEmpty || !Uri.parse(widget.homeUrl).isAbsolute) {
      _isConfigError = true;
      SecureExitManager.instance.logKioskEvent(
        'browser_error',
        'failed',
        {'reason': 'Invalid home URL: ${widget.homeUrl}'},
      );
    }
  }

  void _initWebView() {
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onNavigationRequest: (NavigationRequest request) {
            if (_isDomainAllowed(request.url)) {
              return NavigationDecision.navigate;
            } else {
              _handleBlockedNavigation(request.url);
              return NavigationDecision.prevent;
            }
          },
          onPageFinished: (String url) {
            _injectCSS();
          },
          onWebResourceError: (WebResourceError error) {
            if (error.errorType == WebResourceErrorType.hostLookup ||
                error.errorType == WebResourceErrorType.connect ||
                error.errorType == WebResourceErrorType.timeout) {
              setState(() {
                _isOffline = true;
              });
            }
          },
        ),
      );

    _loadHome();
  }

  void _loadHome() {
    setState(() {
      _isOffline = false;
    });
    _controller.loadRequest(Uri.parse(widget.homeUrl));
  }

  bool _isDomainAllowed(String url) {
    return KioskBrowserScreen.isDomainAllowed(url, widget.allowedDomains);
  }

  void _handleBlockedNavigation(String url) {
    SecureExitManager.instance.logKioskEvent(
      'navigation_blocked',
      'prevented',
      {
        'url': url,
        'allowed_domains': widget.allowedDomains,
      },
    );

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Access to this website is restricted.'),
        duration: Duration(seconds: 2),
      ),
    );

    _loadHome();
  }

  void _injectCSS() {
    const css = '* { -webkit-touch-callout: none !important; -webkit-user-select: none !important; }';
    _controller.runJavaScript(
      "const style = document.createElement('style'); style.innerHTML = '$css'; document.head.appendChild(style);"
    );
  }

  void _handleExitTap(PointerDownEvent event) {
    final size = MediaQuery.of(context).size;
    // Top-right corner exit gesture box (100x100 pixels)
    if (event.position.dx > size.width - 100 && event.position.dy < 100) {
      final now = DateTime.now();
      if (_exitFirstTapTime == null || now.difference(_exitFirstTapTime!) > const Duration(seconds: 3)) {
        _exitFirstTapTime = now;
        _exitTapCount = 1;
      } else {
        _exitTapCount++;
        if (_exitTapCount >= 5) {
          _exitTapCount = 0;
          _exitFirstTapTime = null;
          _showExitPinDialog();
        }
      }
    }
  }

  void _showExitPinDialog() {
    final pinController = TextEditingController();
    String? errorMessage;

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setState) {
            return AlertDialog(
              title: const Text('Admin Access'),
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
                  const Text(
                    'Enter the password to configure or exit the kiosk.',
                    style: TextStyle(fontSize: 13, color: Colors.black54),
                  ),
                  const SizedBox(height: 12),
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
                      final ok = await SecureExitManager.instance.verifyPinOnly(pinController.text);
                      if (ok && mounted) {
                        Navigator.of(context).pop();
                        Navigator.of(context).push(MaterialPageRoute(
                          builder: (_) => const KioskConfigScreen(),
                        ));
                      }
                    } catch (e) {
                      setState(() {
                        errorMessage = e.toString().replaceAll('Exception: ', '');
                      });
                    }
                  },
                  child: const Text('Unlock'),
                ),
              ],
            );
          },
        );
      },
    );
  }

  void _resetIdleTimer() {
    if (_isConfigError) return;
    _idleTimer?.cancel();
    _startIdleTimer();
  }

  void _startIdleTimer() {
    if (widget.idleTimeoutMinutes <= 0) return;
    _idleTimer = Timer(Duration(minutes: widget.idleTimeoutMinutes), () {
      SecureExitManager.instance.logKioskEvent(
        'idle_timeout',
        'success',
        {'timeout_minutes': widget.idleTimeoutMinutes},
      );
      _loadHome();
    });
  }

  void _startRefreshTimer() {
    if (widget.refreshIntervalMinutes <= 0) return;
    _refreshTimer = Timer.periodic(Duration(minutes: widget.refreshIntervalMinutes), (timer) {
      SecureExitManager.instance.logKioskEvent(
        'signage_refresh',
        'success',
        {'refresh_interval_minutes': widget.refreshIntervalMinutes},
      );
      _loadHome();
    });
  }

  @override
  void dispose() {
    _idleTimer?.cancel();
    _refreshTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_isConfigError) {
      return const Scaffold(
        body: Center(
          child: Padding(
            padding: EdgeInsets.all(24.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.error_outline, size: 64, color: Colors.red),
                SizedBox(height: 16),
                Text(
                  'Kiosk Browser Error',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                ),
                SizedBox(height: 8),
                Text(
                  'Policy config is missing or invalid.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey),
                ),
              ],
            ),
          ),
        ),
      );
    }

    if (_isOffline) {
      return Scaffold(
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.wifi_off, size: 64, color: Colors.grey),
                const SizedBox(height: 16),
                const Text(
                  'Offline',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 8),
                const Text(
                  'Please check device connectivity and try again.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey),
                ),
                const SizedBox(height: 24),
                ElevatedButton(
                  onPressed: _loadHome,
                  child: const Text('Retry'),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return Scaffold(
      body: Listener(
        onPointerDown: (event) {
          _resetIdleTimer();
          _handleExitTap(event);
        },
        onPointerMove: (_) => _resetIdleTimer(),
        behavior: HitTestBehavior.translucent,
        child: SafeArea(
          child: WebViewWidget(controller: _controller),
        ),
      ),
    );
  }
}
