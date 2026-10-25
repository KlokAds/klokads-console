package com.klokads.app

import android.app.Notification
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification

/**
 * Reads notifications from wallet apps that send no SMS (nSave, Rizon, Elevate Pay,
 * WorldFirst, Airwallex, Payoneer…) and forwards the ones about money. Android only runs
 * this after I turn on "Notification access" for Klok in the phone's settings.
 */
class WalletListener : NotificationListenerService() {
    override fun onNotificationRemoved(sbn: StatusBarNotification, rankingMap: RankingMap, reason: Int) {
        FollowUps.onRemoved(this, sbn, reason)
    }

    companion object {
        /** Words in a wallet app's package or name; set from the app (Settings), these by default. */
        val DEFAULT_APPS = listOf("nsave", "rizon", "elevatepay", "worldfirst", "airwallex", "payoneer", "redotpay", "binance")
        private const val SEEN = "klok_wallet_seen"
    }

    private fun watched(): List<String> =
        (getSharedPreferences("FlutterSharedPreferences", MODE_PRIVATE).getString("flutter.wallet.apps", null)
            ?.split(',')?.map { Forwarder.normalise(it) }?.filter { it.isNotEmpty() })
            ?: DEFAULT_APPS

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        if (sbn.packageName == packageName) {
            return
        }
        // WhatsApp / Messenger / IMO: work follow-ups, not money.
        if (FollowUps.CHAT_APPS.containsKey(sbn.packageName)) {
            FollowUps.onPosted(this, sbn)
            return
        }
        if (Forwarder.url(this) == null) {
            return
        }
        val label = try {
            packageManager.getApplicationLabel(packageManager.getApplicationInfo(sbn.packageName, 0)).toString()
        } catch (e: Exception) {
            sbn.packageName
        }
        val key = Forwarder.normalise(sbn.packageName + label)
        if (watched().none { key.contains(it) }) {
            return
        }

        val extras = sbn.notification.extras
        val title = extras.getCharSequence(Notification.EXTRA_TITLE)?.toString() ?: ""
        val text = (extras.getCharSequence(Notification.EXTRA_BIG_TEXT) ?: extras.getCharSequence(Notification.EXTRA_TEXT))?.toString() ?: ""
        val message = listOf(title, text).filter { it.isNotBlank() }.joinToString(". ")
        if (message.isBlank()) {
            return
        }

        // The phone's own App Clone runs the second copy in another profile: "clone" vs "main"
        // tells which of two nSave / Rizon accounts this is.
        val profile = if (sbn.user == android.os.Process.myUserHandle()) "main" else "clone"

        // Apps re-post the same notification as it updates: each message goes once.
        val seen = getSharedPreferences(SEEN, MODE_PRIVATE)
        val hash = (sbn.packageName + profile + message).hashCode().toString()
        if (seen.contains(hash)) {
            return
        }
        val editor = seen.edit().putLong(hash, System.currentTimeMillis())
        if (seen.all.size > 200) {
            seen.all.entries.sortedBy { it.value as? Long ?: 0L }.take(100).forEach { editor.remove(it.key) }
        }
        editor.apply()

        if (Forwarder.enqueue(this, "app", label, message, sbn.postTime, profile)) {
            Forwarder.sendNow(this) {}
        }
    }
}
