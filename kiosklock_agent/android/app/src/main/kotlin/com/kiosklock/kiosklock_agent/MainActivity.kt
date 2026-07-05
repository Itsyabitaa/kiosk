package com.kiosklock.kiosklock_agent

import android.content.Intent
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
                // If MainActivity is in the foreground (resumed), the target app was closed/stopped!
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
