package com.kiosklock.kiosklock_agent

import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private val CHANNEL = "com.kiosklock/kiosk"

    companion object {
        var lockedPackageName: String? = null
    }

    private val relaunchHandler = Handler(Looper.getMainLooper())
    private val relaunchRunnable = object : Runnable {
        override fun run() {
            checkAndRelaunch()
            relaunchHandler.postDelayed(this, 2000) // check every 2 seconds
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        relaunchHandler.post(relaunchRunnable)

        // Native boot persistence recovery
        val prefs = getSharedPreferences("kiosk_prefs", Context.MODE_PRIVATE)
        val savedPackage = prefs.getString("locked_package", null)
        if (savedPackage != null) {
            lockedPackageName = savedPackage
            val dpm = getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
            if (dpm.isDeviceOwnerApp(packageName)) {
                try {
                    val adminComponent = ComponentName(this, KioskDeviceAdminReceiver::class.java)
                    dpm.setLockTaskPackages(adminComponent, arrayOf(savedPackage, packageName))
                    
                    // Re-apply Lock Task features
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                        var flags = 0
                        val blockNotifications = prefs.getBoolean("block_notifications", true)
                        val blockRecents = prefs.getBoolean("block_recents", true)
                        val blockHome = prefs.getBoolean("block_home", false)

                        if (!blockNotifications) {
                            flags = flags or DevicePolicyManager.LOCK_TASK_FEATURE_NOTIFICATIONS
                        }
                        if (!blockRecents) {
                            flags = flags or DevicePolicyManager.LOCK_TASK_FEATURE_RECENTS
                        }
                        if (!blockHome) {
                            flags = flags or DevicePolicyManager.LOCK_TASK_FEATURE_HOME
                        }
                        flags = flags or DevicePolicyManager.LOCK_TASK_FEATURE_SYSTEM_INFO
                        dpm.setLockTaskFeatures(adminComponent, flags)
                    }

                    // Launch target app if different
                    if (savedPackage != packageName) {
                        val launchIntent = packageManager.getLaunchIntentForPackage(savedPackage)
                        if (launchIntent != null) {
                            launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                            startActivity(launchIntent)
                        }
                    }
                    startLockTask()
                } catch (e: Exception) {
                    // Ignore
                }
            }
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        relaunchHandler.removeCallbacks(relaunchRunnable)
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL)
            .setMethodCallHandler(KioskMethodChannelHandler(this))
    }

    override fun onResume() {
        super.onResume()
        checkAndRelaunch()
    }

    private fun checkAndRelaunch() {
        val target = lockedPackageName ?: return
        if (target != packageName) {
            try {
                val launchIntent = packageManager.getLaunchIntentForPackage(target)
                if (launchIntent != null) {
                    launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    startActivity(launchIntent)
                }
            } catch (e: Exception) {
                // Ignore
            }
        }
    }
}
