/// Static, build-time configuration for the agent.
///
/// [standaloneMode] turns the device into a fully self-contained kiosk: no enrollment, no
/// server, no internet. On launch the agent applies [bundledPolicy] directly through the
/// native device-owner lock. Set it to `false` to go back to the normal server-managed flow
/// (enrollment code + live policy sync + remote commands).
class AppConfig {
  /// Fully offline / standalone kiosk. No backend required.
  static const bool standaloneMode = true;

  /// The policy baked into the app and applied on-device in standalone mode.
  ///
  /// Two supported shapes (same format the backend would send):
  ///
  /// 1. Lock to a single installed app (fully offline — recommended):
  ///    {
  ///      'policy_type': 'single_app',
  ///      'target': 'com.android.chrome',   // package name of the app to pin
  ///      'restrictions': {},
  ///    }
  ///
  /// 2. Whitelisted web kiosk using the built-in browser (needs the page to be reachable,
  ///    e.g. a local/LAN page if there's no internet):
  ///    {
  ///      'policy_type': 'url_whitelist',
  ///      'target': 'http://192.168.100.60:8000/kiosk',
  ///      'restrictions': {
  ///        'allowed_domains': ['192.168.100.60'],
  ///        'idle_timeout_minutes': 5,
  ///        'refresh_interval_minutes': 0,
  ///      },
  ///    }
  ///
  /// This is only the *initial* default. Once an operator sets a target from the on-device
  /// PIN-protected config panel (Lock to App / Lock to Website), that saved choice is persisted
  /// in secure storage and overrides this value on every launch/reboot.
  ///
  /// Default: pin the device to this agent app itself, which needs nothing else installed and
  /// no network. Change `target` to lock to whichever app you actually want.
  static const Map<String, dynamic> bundledPolicy = {
    'id': 0,
    'version': 1,
    'policy_type': 'single_app',
    'target': 'com.kiosklock.kiosklock_agent',
    'restrictions': <String, dynamic>{},
  };
}
