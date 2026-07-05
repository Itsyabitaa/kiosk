package com.kiosklock.kiosklock_agent

import android.app.Activity
import android.app.ActivityManager
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.drawable.Drawable
import android.os.Build
import android.os.UserManager
import android.util.Base64
import java.io.ByteArrayOutputStream
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
                        val blockNotifications = restrictions?.get("block_notifications") as? Boolean ?: true
                        val blockRecents = restrictions?.get("block_recents") as? Boolean ?: true
                        val blockHome = restrictions?.get("block_home") as? Boolean ?: false

                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                            var flags = 0

                            if (!blockNotifications) {
                                flags = flags or 8 // LOCK_TASK_FEATURE_NOTIFICATIONS
                            }
                            if (!blockRecents) {
                                flags = flags or 4 // LOCK_TASK_FEATURE_RECENTS
                            }
                            if (!blockHome) {
                                flags = flags or 2 // LOCK_TASK_FEATURE_HOME
                            }

                            // Always allow system info
                            flags = flags or 1 // LOCK_TASK_FEATURE_SYSTEM_INFO

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
            "setKioskApps" -> {
                // Multi-app kiosk "home launcher" mode: our app becomes the device HOME and a
                // fixed set of approved apps is whitelisted for lock task. The user picks an app
                // from our launcher; pressing HOME returns to the launcher.
                val packages = call.argument<List<String>>("packages") ?: emptyList()
                val restrictions = call.argument<Map<String, Any>>("restrictions")

                try {
                    val dpm = activity.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
                    val adminComponent = ComponentName(activity, KioskDeviceAdminReceiver::class.java)

                    if (!dpm.isDeviceOwnerApp(activity.packageName)) {
                        MainActivity.lockedPackageName = activity.packageName
                        activity.startLockTask()
                        result.success(false)
                        return
                    }

                    val allowed = (packages + activity.packageName).distinct().toTypedArray()
                    dpm.setLockTaskPackages(adminComponent, allowed)

                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                        // Allow HOME so the home button returns to our launcher, plus system info.
                        var flags = 1 // LOCK_TASK_FEATURE_SYSTEM_INFO
                        flags = flags or 2 // LOCK_TASK_FEATURE_HOME
                        dpm.setLockTaskFeatures(adminComponent, flags)
                    }

                    applyKioskUserRestrictions(dpm, adminComponent, restrictions)

                    // Make our launcher the persistent HOME so exiting an app returns here.
                    try {
                        val homeFilter = IntentFilter(Intent.ACTION_MAIN)
                        homeFilter.addCategory(Intent.CATEGORY_HOME)
                        homeFilter.addCategory(Intent.CATEGORY_DEFAULT)
                        val ourComponent = ComponentName(activity, MainActivity::class.java)
                        dpm.addPersistentPreferredActivity(adminComponent, homeFilter, ourComponent)
                    } catch (e: Exception) {
                        // Non-fatal: home preference may already be set.
                    }

                    val prefs = activity.getSharedPreferences("kiosk_prefs", Context.MODE_PRIVATE)
                    prefs.edit()
                        .putString("locked_package", activity.packageName)
                        .putBoolean("launcher_mode", true)
                        .putString("kiosk_apps", packages.joinToString(","))
                        .apply()

                    // In launcher mode the watchdog should keep OUR app as home, not force a
                    // specific target app to the foreground.
                    MainActivity.lockedPackageName = activity.packageName

                    val serviceIntent = Intent(activity, KioskWatchdogService::class.java)
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        activity.startForegroundService(serviceIntent)
                    } else {
                        activity.startService(serviceIntent)
                    }

                    activity.startLockTask()
                    result.success(true)
                } catch (e: Exception) {
                    result.error("SET_APPS_FAILED", e.message, null)
                }
            }
            "launchApp" -> {
                val packageName = call.argument<String>("package")
                if (packageName == null) {
                    result.error("BAD_ARGS", "Package name is required", null)
                    return
                }
                try {
                    val launchIntent = activity.packageManager.getLaunchIntentForPackage(packageName)
                    if (launchIntent != null) {
                        launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        activity.startActivity(launchIntent)
                        result.success(true)
                    } else {
                        result.error("NOT_INSTALLED", "Package $packageName is not installed", null)
                    }
                } catch (e: Exception) {
                    result.error("LAUNCH_FAILED", e.message, null)
                }
            }
            "getInstalledApps" -> {
                // Returns launchable apps (package, human label, base64 PNG icon) for the admin
                // app picker.
                try {
                    val pm = activity.packageManager
                    val intent = Intent(Intent.ACTION_MAIN, null).addCategory(Intent.CATEGORY_LAUNCHER)
                    val resolveInfos = pm.queryIntentActivities(intent, 0)
                    val apps = resolveInfos
                        .map { ri ->
                            mapOf(
                                "package" to ri.activityInfo.packageName,
                                "label" to ri.loadLabel(pm).toString(),
                                "icon" to drawableToBase64(ri.loadIcon(pm))
                            )
                        }
                        .distinctBy { it["package"] }
                        .sortedBy { (it["label"] as String).lowercase() }
                    result.success(apps)
                } catch (e: Exception) {
                    result.error("LIST_APPS_FAILED", e.message, null)
                }
            }
            "unlock" -> {
                try {
                    MainActivity.lockedPackageName = null
                    activity.stopLockTask()

                    // Drop the persistent HOME preference used by launcher mode.
                    try {
                        val dpmHome = activity.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
                        val adminHome = ComponentName(activity, KioskDeviceAdminReceiver::class.java)
                        if (dpmHome.isDeviceOwnerApp(activity.packageName)) {
                            dpmHome.clearPackagePersistentPreferredActivities(adminHome, activity.packageName)
                        }
                    } catch (e: Exception) {}

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
            "reboot" -> {
                // Remote reboot (device-owner only, API 24+).
                try {
                    val dpm = activity.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
                    val adminComponent = ComponentName(activity, KioskDeviceAdminReceiver::class.java)
                    if (dpm.isDeviceOwnerApp(activity.packageName) && Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
                        dpm.reboot(adminComponent)
                        result.success(true)
                    } else {
                        result.success(false)
                    }
                } catch (e: Exception) {
                    result.error("REBOOT_FAILED", e.message, null)
                }
            }
            "wipe" -> {
                // Remote factory reset (device-owner only). Destructive.
                try {
                    val dpm = activity.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
                    val adminComponent = ComponentName(activity, KioskDeviceAdminReceiver::class.java)
                    if (dpm.isDeviceOwnerApp(activity.packageName)) {
                        // Clear the factory-reset restriction we set during lock, otherwise wipe throws.
                        try {
                            dpm.clearUserRestriction(adminComponent, UserManager.DISALLOW_FACTORY_RESET)
                        } catch (e: Exception) {}
                        dpm.wipeData(0)
                        result.success(true)
                    } else {
                        result.success(false)
                    }
                } catch (e: Exception) {
                    result.error("WIPE_FAILED", e.message, null)
                }
            }
            "getAdminExtras" -> {
                // Surfaces the enrollment_token / policy_id passed via the QR provisioning
                // extras bundle. During device-owner provisioning the setup wizard delivers
                // EXTRA_PROVISIONING_ADMIN_EXTRAS_BUNDLE to KioskDeviceAdminReceiver, which we
                // persist to SharedPreferences and read back here for the Flutter agent.
                try {
                    val prefs = activity.getSharedPreferences(
                        KioskDeviceAdminReceiver.PREFS_NAME,
                        Context.MODE_PRIVATE
                    )

                    val token = prefs.getString(KioskDeviceAdminReceiver.KEY_ENROLLMENT_TOKEN, null)

                    if (token.isNullOrEmpty()) {
                        result.success(null)
                    } else {
                        result.success(mapOf(
                            "enrollment_token" to token,
                            "policy_id" to prefs.getString(KioskDeviceAdminReceiver.KEY_POLICY_ID, null),
                            "org_id" to prefs.getString(KioskDeviceAdminReceiver.KEY_ORG_ID, null),
                            "server_url" to prefs.getString(KioskDeviceAdminReceiver.KEY_SERVER_URL, null)
                        ))
                    }
                } catch (e: Exception) {
                    result.error("ADMIN_EXTRAS_FAILED", e.message, null)
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
            "getSetupPermissionsStatus" -> {
                try {
                    result.success(SetupPermissionsHelper.getStatus(activity))
                } catch (e: Exception) {
                    result.error("SETUP_STATUS_FAILED", e.message, null)
                }
            }
            "openSetupPermission" -> {
                val permissionId = call.argument<String>("permissionId")
                if (permissionId == null) {
                    result.error("BAD_ARGS", "permissionId is required", null)
                    return
                }
                try {
                    val opened = SetupPermissionsHelper.open(activity, permissionId)
                    result.success(opened)
                } catch (e: Exception) {
                    result.error("OPEN_SETUP_FAILED", e.message, null)
                }
            }
            else -> {
                result.notImplemented()
            }
        }
    }

    private fun applyKioskUserRestrictions(
        dpm: DevicePolicyManager,
        adminComponent: ComponentName,
        restrictions: Map<String, Any>?
    ) {
        val blockFactoryReset = restrictions?.get("block_factory_reset") as? Boolean ?: true
        val blockDebugging = restrictions?.get("block_debugging_features") as? Boolean ?: true
        val blockInstall = restrictions?.get("block_install_apps") as? Boolean ?: true
        val blockUninstall = restrictions?.get("block_uninstall_apps") as? Boolean ?: true
        val hidePlayStore = restrictions?.get("hide_play_store") as? Boolean ?: false

        fun toggle(key: String, enabled: Boolean) {
            if (enabled) dpm.addUserRestriction(adminComponent, key)
            else dpm.clearUserRestriction(adminComponent, key)
        }

        toggle(UserManager.DISALLOW_FACTORY_RESET, blockFactoryReset)
        toggle(UserManager.DISALLOW_DEBUGGING_FEATURES, blockDebugging)
        toggle(UserManager.DISALLOW_INSTALL_APPS, blockInstall)
        toggle(UserManager.DISALLOW_UNINSTALL_APPS, blockUninstall)

        try {
            dpm.setApplicationHidden(adminComponent, "com.android.vending", hidePlayStore)
        } catch (e: Exception) {
            // vending might not exist or be hideable
        }
    }

    private fun drawableToBase64(drawable: Drawable): String {
        val size = 96
        val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)
        drawable.setBounds(0, 0, canvas.width, canvas.height)
        drawable.draw(canvas)
        val stream = ByteArrayOutputStream()
        bitmap.compress(Bitmap.CompressFormat.PNG, 100, stream)
        bitmap.recycle()
        return Base64.encodeToString(stream.toByteArray(), Base64.NO_WRAP)
    }
}
