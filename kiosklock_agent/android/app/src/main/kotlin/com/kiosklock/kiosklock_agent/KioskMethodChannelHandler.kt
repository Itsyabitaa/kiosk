package com.kiosklock.kiosklock_agent

import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import io.flutter.plugin.common.MethodChannel.MethodCallHandler
import io.flutter.plugin.common.MethodChannel.Result

class KioskMethodChannelHandler : MethodCallHandler {
    override fun onMethodCall(call: MethodCall, result: Result) {
        when (call.method) {
            "lockToApp" -> {
                // TODO: Implement actual lock logic
                result.success(false)
            }
            "unlock" -> {
                // TODO: Implement unlock logic
                result.success(false)
            }
            "applyRestrictions" -> {
                // TODO: Implement restriction logic
                result.success(false)
            }
            "getDeviceState" -> {
                // TODO: Implement state fetching logic
                result.success(emptyMap<String, Any>())
            }
            else -> {
                result.notImplemented()
            }
        }
    }
}
