package com.kiosklock.kiosklock_agent

import android.app.AppOpsManager
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.os.PowerManager
import android.provider.Settings
import android.app.AlarmManager
import android.view.accessibility.AccessibilityManager

/**
 * Checks and opens Android system settings for the first-run setup wizard (SureLock-style).
 */
object SetupPermissionsHelper {

    fun getStatus(context: Context): List<Map<String, Any?>> {
        return listOf(
            deviceAdmin(context),
            deviceOwner(context),
            defaultLauncher(context),
            usageAccess(context),
            overlay(context),
            allFilesAccess(context),
            notificationAccess(context),
            exactAlarm(context),
            batteryOptimization(context),
            accessibility(context),
        )
    }

    fun open(context: Context, permissionId: String): Boolean {
        return when (permissionId) {
            "device_admin" -> requestDeviceAdmin(context)
            "device_owner" -> openDeviceOwnerInstructions(context)
            "default_launcher" -> openDefaultLauncherSettings(context)
            "usage_access" -> openAppOpsSettings(context, Settings.ACTION_USAGE_ACCESS_SETTINGS)
            "overlay" -> openOverlaySettings(context)
            "all_files_access" -> openAllFilesAccessSettings(context)
            "notification_access" -> openAppOpsSettings(context, Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS)
            "exact_alarm" -> openExactAlarmSettings(context)
            "battery_optimization" -> requestBatteryOptimization(context)
            "accessibility" -> openAppOpsSettings(context, Settings.ACTION_ACCESSIBILITY_SETTINGS)
            else -> false
        }
    }

    private fun deviceAdmin(context: Context): Map<String, Any?> {
        val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        val admin = ComponentName(context, KioskDeviceAdminReceiver::class.java)
        val granted = dpm.isAdminActive(admin)
        return mapOf(
            "id" to "device_admin",
            "title" to "Activate Device Admin",
            "description" to "KioskLock requires device admin to enforce kiosk lockdown, block settings changes, and protect against tampering.",
            "granted" to granted,
            "required" to true,
        )
    }

    private fun deviceOwner(context: Context): Map<String, Any?> {
        val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        val granted = dpm.isDeviceOwnerApp(context.packageName)
        return mapOf(
            "id" to "device_owner",
            "title" to "Enable Device Owner (Recommended)",
            "description" to "Full kiosk lockdown requires device-owner mode. Provision via QR code or: adb shell dpm set-device-owner com.kiosklock.kiosklock_agent/.KioskDeviceAdminReceiver",
            "granted" to granted,
            "required" to false,
        )
    }

    private fun defaultLauncher(context: Context): Map<String, Any?> {
        val home = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
        val resolve = context.packageManager.resolveActivity(home, 0)
        val granted = resolve?.activityInfo?.packageName == context.packageName
        return mapOf(
            "id" to "default_launcher",
            "title" to "Set KioskLock As Default Launcher",
            "description" to "Required for multi-app kiosk home mode. Choose KioskLock when prompted as the default home app.",
            "granted" to granted,
            "required" to false,
        )
    }

    private fun usageAccess(context: Context): Map<String, Any?> {
        val granted = hasAppOpsPermission(context, AppOpsManager.OPSTR_GET_USAGE_STATS)
        return mapOf(
            "id" to "usage_access",
            "title" to "Enable Usage Access",
            "description" to "Allows KioskLock to detect and block access to unapproved applications.",
            "granted" to granted,
            "required" to true,
        )
    }

    private fun overlay(context: Context): Map<String, Any?> {
        val granted = Settings.canDrawOverlays(context)
        return mapOf(
            "id" to "overlay",
            "title" to "Enable Display Over Other Apps",
            "description" to "Allows KioskLock to block the status bar and prevent access to unauthorized apps.",
            "granted" to granted,
            "required" to true,
        )
    }

    private fun allFilesAccess(context: Context): Map<String, Any?> {
        val granted = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            Environment.isExternalStorageManager()
        } else {
            true
        }
        return mapOf(
            "id" to "all_files_access",
            "title" to "Enable All Files Access",
            "description" to "Allows KioskLock to manage local policy files and deployment assets on the device.",
            "granted" to granted,
            "required" to false,
        )
    }

    private fun notificationAccess(context: Context): Map<String, Any?> {
        val granted = isNotificationListenerEnabled(context)
        return mapOf(
            "id" to "notification_access",
            "title" to "Enable Notification Access",
            "description" to "Allows KioskLock to block notifications and control sound settings in kiosk mode.",
            "granted" to granted,
            "required" to false,
        )
    }

    private fun exactAlarm(context: Context): Map<String, Any?> {
        val granted = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val am = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            am.canScheduleExactAlarms()
        } else {
            true
        }
        return mapOf(
            "id" to "exact_alarm",
            "title" to "Schedule Exact Alarm",
            "description" to "Allows KioskLock to schedule reboots and timed kiosk maintenance windows.",
            "granted" to granted,
            "required" to false,
        )
    }

    private fun batteryOptimization(context: Context): Map<String, Any?> {
        val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        val granted = pm.isIgnoringBatteryOptimizations(context.packageName)
        return mapOf(
            "id" to "battery_optimization",
            "title" to "Disable Battery Optimization",
            "description" to "Keeps KioskLock running in the background so kiosk lockdown is never interrupted.",
            "granted" to granted,
            "required" to false,
        )
    }

    private fun accessibility(context: Context): Map<String, Any?> {
        // Optional: user may enable any accessibility aid; we only guide them to settings.
        val am = context.getSystemService(Context.ACCESSIBILITY_SERVICE) as AccessibilityManager
        val granted = am.isEnabled
        return mapOf(
            "id" to "accessibility",
            "title" to "Enable Accessibility Settings",
            "description" to "Optional. Helps suppress the notification panel and power menu on some devices.",
            "granted" to granted,
            "required" to false,
        )
    }

    private fun hasAppOpsPermission(context: Context, op: String): Boolean {
        val appOps = context.getSystemService(Context.APP_OPS_SERVICE) as AppOpsManager
        val mode = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            appOps.unsafeCheckOpNoThrow(op, android.os.Process.myUid(), context.packageName)
        } else {
            @Suppress("DEPRECATION")
            appOps.checkOpNoThrow(op, android.os.Process.myUid(), context.packageName)
        }
        return mode == AppOpsManager.MODE_ALLOWED
    }

    private fun isNotificationListenerEnabled(context: Context): Boolean {
        val flat = Settings.Secure.getString(
            context.contentResolver,
            "enabled_notification_listeners"
        ) ?: return false
        return flat.contains(context.packageName)
    }

    private fun requestDeviceAdmin(context: Context): Boolean {
        val admin = ComponentName(context, KioskDeviceAdminReceiver::class.java)
        val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        if (dpm.isAdminActive(admin)) return true

        val intent = Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN).apply {
            putExtra(DevicePolicyManager.EXTRA_DEVICE_ADMIN, admin)
            putExtra(
                DevicePolicyManager.EXTRA_ADD_EXPLANATION,
                "KioskLock needs device admin to enforce secure kiosk lockdown."
            )
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(intent)
        return true
    }

    private fun openDeviceOwnerInstructions(context: Context): Boolean {
        android.widget.Toast.makeText(
            context,
            "Device owner must be set via QR provisioning or adb dpm set-device-owner",
            android.widget.Toast.LENGTH_LONG
        ).show()
        return true
    }

    private fun openDefaultLauncherSettings(context: Context): Boolean {
        val home = Intent(Intent.ACTION_MAIN).apply {
            addCategory(Intent.CATEGORY_HOME)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(home)
        return true
    }

    private fun openAppOpsSettings(context: Context, action: String): Boolean {
        val intent = Intent(action).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            if (action == Settings.ACTION_USAGE_ACCESS_SETTINGS ||
                action == Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS
            ) {
                data = Uri.parse("package:${context.packageName}")
            }
        }
        try {
            context.startActivity(intent)
            return true
        } catch (e: Exception) {
            val fallback = Intent(Settings.ACTION_SETTINGS).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            context.startActivity(fallback)
            return true
        }
    }

    private fun openOverlaySettings(context: Context): Boolean {
        val intent = Intent(
            Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
            Uri.parse("package:${context.packageName}")
        ).apply { addFlags(Intent.FLAG_ACTIVITY_NEW_TASK) }
        context.startActivity(intent)
        return true
    }

    private fun openAllFilesAccessSettings(context: Context): Boolean {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            val intent = Intent(Settings.ACTION_MANAGE_APP_ALL_FILES_ACCESS_PERMISSION).apply {
                data = Uri.parse("package:${context.packageName}")
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            try {
                context.startActivity(intent)
                return true
            } catch (e: Exception) {
                val fallback = Intent(Settings.ACTION_MANAGE_ALL_FILES_ACCESS_PERMISSION).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                }
                context.startActivity(fallback)
                return true
            }
        }
        return true
    }

    private fun openExactAlarmSettings(context: Context): Boolean {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val intent = Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM).apply {
                data = Uri.parse("package:${context.packageName}")
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            context.startActivity(intent)
            return true
        }
        return true
    }

    private fun requestBatteryOptimization(context: Context): Boolean {
        val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        if (pm.isIgnoringBatteryOptimizations(context.packageName)) return true

        val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
            data = Uri.parse("package:${context.packageName}")
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(intent)
        return true
    }
}
