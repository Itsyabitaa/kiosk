package com.kiosklock.kiosklock_agent

import android.app.Activity
import android.app.ActivityManager
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.UserManager
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import io.flutter.plugin.common.MethodChannel.MethodCallHandler
import io.flutter.plugin.common.MethodChannel.Result

class KioskMethodChannelHandler(private val activity: Activity) : MethodCallHandler {
    override fun onMethodCall(call: MethodCall, result: Result) {
        when (call.method) {
            "lockToApp" -> {
                val packageName = call.argument<String>("package")
                val restrictions = call.argument<Map<String, Any>>("restrictions")
                
                if (packageName == null) {
                    result.error("BAD_ARGS", "Package name is required", null)
                    return
                }

                try {
                    val dpm = activity.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
                    val adminComponent = ComponentName(activity, KioskDeviceAdminReceiver::class.java)

                    if (dpm.isDeviceOwnerApp(activity.packageName)) {
                        // Configure allowed packages
                        dpm.setLockTaskPackages(adminComponent, arrayOf(packageName, activity.packageName))

                        // Configure allowed lock-task features driven by flags passed from Dart
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                            var flags = 0
                            
                            val blockNotifications = restrictions?.get("block_notifications") as? Boolean ?: true
                            val blockRecents = restrictions?.get("block_recents") as? Boolean ?: true
                            val blockHome = restrictions?.get("block_home") as? Boolean ?: false

                            if (!blockNotifications) {
                                flags = flags or DevicePolicyManager.LOCK_TASK_FEATURE_NOTIFICATIONS
                            }
                            if (!blockRecents) {
                                flags = flags or DevicePolicyManager.LOCK_TASK_FEATURE_RECENTS
                            }
                            if (!blockHome) {
                                flags = flags or DevicePolicyManager.LOCK_TASK_FEATURE_HOME
                            }

                            // Always allow system info
                            flags = flags or DevicePolicyManager.LOCK_TASK_FEATURE_SYSTEM_INFO

                            dpm.setLockTaskFeatures(adminComponent, flags)
                        }

                        // Apply User Restrictions
                        val blockFactoryReset = restrictions?.get("block_factory_reset") as? Boolean ?: true
                        val blockDebugging = restrictions?.get("block_debugging_features") as? Boolean ?: true
                        val blockInstall = restrictions?.get("block_install_apps") as? Boolean ?: true
                        val blockUninstall = restrictions?.get("block_uninstall_apps") as? Boolean ?: true
                        val hidePlayStore = restrictions?.get("hide_play_store") as? Boolean ?: false

                        if (blockFactoryReset) {
                            dpm.addUserRestriction(adminComponent, UserManager.DISALLOW_FACTORY_RESET)
                        } else {
                            dpm.clearUserRestriction(adminComponent, UserManager.DISALLOW_FACTORY_RESET)
                        }

                        if (blockDebugging) {
                            dpm.addUserRestriction(adminComponent, UserManager.DISALLOW_DEBUGGING_FEATURES)
                        } else {
                            dpm.clearUserRestriction(adminComponent, UserManager.DISALLOW_DEBUGGING_FEATURES)
                        }

                        if (blockInstall) {
                            dpm.addUserRestriction(adminComponent, UserManager.DISALLOW_INSTALL_APPS)
                        } else {
                            dpm.clearUserRestriction(adminComponent, UserManager.DISALLOW_INSTALL_APPS)
                        }

                        if (blockUninstall) {
                            dpm.addUserRestriction(adminComponent, UserManager.DISALLOW_UNINSTALL_APPS)
                        } else {
                            dpm.clearUserRestriction(adminComponent, UserManager.DISALLOW_UNINSTALL_APPS)
                        }

                        // Hide/Unhide Play Store
                        try {
                            dpm.setApplicationHidden(adminComponent, "com.android.vending", hidePlayStore)
                        } catch (e: Exception) {
                            // vending might not exist or be hideable
                        }

                        // Save target package and restrictions metadata to native SharedPreferences for boot recovery
                        val prefs = activity.getSharedPreferences("kiosk_prefs", Context.MODE_PRIVATE)
                        prefs.edit()
                            .putString("locked_package", packageName)
                            .putBoolean("block_notifications", blockNotifications)
                            .putBoolean("block_recents", blockRecents)
                            .putBoolean("block_home", blockHome)
                            .apply()

                        // Save target package to watchdog
                        MainActivity.lockedPackageName = packageName

                        // Launch target app if it's different from our app
                        if (packageName != activity.packageName) {
                            val launchIntent = activity.packageManager.getLaunchIntentForPackage(packageName)
                            if (launchIntent != null) {
                                launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                activity.startActivity(launchIntent)
                            } else {
                                result.error("NOT_INSTALLED", "Package $packageName is not installed on device", null)
                                return
                            }
                        }

                        // Start KioskWatchdogService
                        val serviceIntent = Intent(activity, KioskWatchdogService::class.java)
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                            activity.startForegroundService(serviceIntent)
                        } else {
                            activity.startService(serviceIntent)
                        }

                        // Start Lock Task Mode
                        activity.startLockTask()
                        result.success(true)
                    } else {
                        // Fallback to basic screen pinning if not Device Owner
                        MainActivity.lockedPackageName = packageName
                        if (packageName != activity.packageName) {
                            val launchIntent = activity.packageManager.getLaunchIntentForPackage(packageName)
                            if (launchIntent != null) {
                                launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                activity.startActivity(launchIntent)
                            } else {
                                result.error("NOT_INSTALLED", "Package $packageName is not installed on device", null)
                                return
                            }
                        }
                        activity.startLockTask()
                        result.success(false)
                    }
                } catch (e: Exception) {
                    result.error("LOCK_FAILED", e.message, null)
                }
            }
            "unlock" -> {
                try {
                    MainActivity.lockedPackageName = null
                    activity.stopLockTask()

                    // Clear restrictions and SharedPreferences
                    val dpm = activity.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
                    val adminComponent = ComponentName(activity, KioskDeviceAdminReceiver::class.java)
                    if (dpm.isDeviceOwnerApp(activity.packageName)) {
                        dpm.clearUserRestriction(adminComponent, UserManager.DISALLOW_FACTORY_RESET)
                        dpm.clearUserRestriction(adminComponent, UserManager.DISALLOW_DEBUGGING_FEATURES)
                        dpm.clearUserRestriction(adminComponent, UserManager.DISALLOW_INSTALL_APPS)
                        dpm.clearUserRestriction(adminComponent, UserManager.DISALLOW_UNINSTALL_APPS)
                        try {
                            dpm.setApplicationHidden(adminComponent, "com.android.vending", false)
                        } catch (e: Exception) {}
                    }

                    val prefs = activity.getSharedPreferences("kiosk_prefs", Context.MODE_PRIVATE)
                    prefs.edit().clear().apply()

                    // Stop KioskWatchdogService
                    val serviceIntent = Intent(activity, KioskWatchdogService::class.java)
                    activity.stopService(serviceIntent)

                    result.success(true)
                } catch (e: Exception) {
                    result.error("UNLOCK_FAILED", e.message, null)
                }
            }
            "getDeviceState" -> {
                try {
                    val dpm = activity.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
                    val isOwner = dpm.isDeviceOwnerApp(activity.packageName)
                    
                    val activityManager = activity.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
                    val lockTaskState = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                        activityManager.lockTaskModeState
                    } else {
                        if (activityManager.isInLockTaskMode) 1 else 0
                    }
                    val isLocked = lockTaskState != ActivityManager.LOCK_TASK_MODE_NONE

                    result.success(mapOf(
                        "isDeviceOwner" to isOwner,
                        "isLocked" to isLocked
                    ))
                } catch (e: Exception) {
                    result.error("STATE_FAILED", e.message, null)
                }
            }
            else -> {
                result.notImplemented()
            }
        }
    }
}
