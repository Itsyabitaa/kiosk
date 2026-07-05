import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    let controller : FlutterViewController = window?.rootViewController as! FlutterViewController
    let kioskChannel = FlutterMethodChannel(name: "com.kiosklock/kiosk", binaryMessenger: controller.binaryMessenger)
    
    kioskChannel.setMethodCallHandler { (call: FlutterMethodCall, result: @escaping FlutterResult) in
        switch call.method {
        case "lockToApp":
            result(false)
        case "unlock":
            result(false)
        case "applyRestrictions":
            result(false)
        case "getDeviceState":
            result([String: Any]())
        default:
            result(FlutterMethodNotImplemented)
        }
    }
    
    GeneratedPluginRegistrant.register(with: self)
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }
}
