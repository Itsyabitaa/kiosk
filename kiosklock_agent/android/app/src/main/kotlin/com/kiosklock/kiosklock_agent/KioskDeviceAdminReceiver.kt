package com.kiosklock.kiosklock_agent

import android.app.admin.DeviceAdminReceiver
import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.Intent
import android.os.PersistableBundle
import android.widget.Toast

class KioskDeviceAdminReceiver : DeviceAdminReceiver() {
    companion object {
        const val PREFS_NAME = "kiosk_provisioning"
        const val KEY_ENROLLMENT_TOKEN = "enrollment_token"
        const val KEY_POLICY_ID = "policy_id"
        const val KEY_ORG_ID = "org_id"
        const val KEY_SERVER_URL = "server_url"
    }

    override fun onEnabled(context: Context, intent: Intent) {
        super.onEnabled(context, intent)
        Toast.makeText(context, "Kiosk Device Admin Enabled", Toast.LENGTH_SHORT).show()
    }

    override fun onDisabled(context: Context, intent: Intent) {
        super.onDisabled(context, intent)
        Toast.makeText(context, "Kiosk Device Admin Disabled", Toast.LENGTH_SHORT).show()
    }

    /**
     * Fired once QR/NFC device-owner provisioning finishes. The setup wizard forwards the
     * PROVISIONING_ADMIN_EXTRAS_BUNDLE we bundled into the QR payload here. We persist the
     * enrollment token / policy id so the Flutter agent can auto-enroll on first launch.
     */
    override fun onProfileProvisioningComplete(context: Context, intent: Intent) {
        super.onProfileProvisioningComplete(context, intent)

        val extras: PersistableBundle? =
            intent.getParcelableExtra(DevicePolicyManager.EXTRA_PROVISIONING_ADMIN_EXTRAS_BUNDLE)

        if (extras != null) {
            val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            prefs.edit()
                .putString(KEY_ENROLLMENT_TOKEN, extras.getString(KEY_ENROLLMENT_TOKEN))
                .putString(KEY_POLICY_ID, extras.getString(KEY_POLICY_ID))
                .putString(KEY_ORG_ID, extras.getString(KEY_ORG_ID))
                .putString(KEY_SERVER_URL, extras.getString(KEY_SERVER_URL))
                .apply()
        }
    }
}
