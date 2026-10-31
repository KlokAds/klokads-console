package com.klokads.app

import android.app.Notification
import android.os.Handler
import android.os.Looper
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification

/**
 * Reads notifications from wallet apps that send no SMS (nSave, Rizon, Elevate Pay,
 * WorldFirst, Airwallex, Payoneer…) and forwards the ones about money. Android only runs
 * this after I turn on "Notification access" for Klok in the phone's settings.
 */
class WalletListener : NotificationListenerService() {
    private val ticker = Handler(Looper.getMainLooper())
    private val tick = object : Runnable {
        override fun run() {
            pollNow()
            ticker.postDelayed(this, POLL_EVERY_MS)
        }
    }
    private var lastPoll = 0L

    /** Android keeps this listener running, so it fetches the server's notifications every minute (not every 15). */
    override fun onListenerConnected() {
        ticker.removeCallbacks(tick)
        ticker.post(tick)
    }

    override fun onListenerDisconnected() {
        ticker.removeCallbacks(tick)
    }

    private fun pollNow() {
        val now = System.currentTimeMillis()
        if (now - lastPoll < 20_000) {
            return
        }
        lastPoll = now
        Thread {
            try {
                Feed.poll(applicationContext)
            } catch (_: Exception) {
            }
        }.start()
    }

    override fun onNotificationRemoved(sbn: StatusBarNotification, rankingMap: RankingMap, reason: Int) {
        FollowUps.onRemoved(this, sbn, reason)
    }

    companion object {
        /** Words in a wallet app's package or name; set from the app (Settings), these by default. */
        val DEFAULT_APPS = listOf("nsave", "rizon", "elevatepay", "worldfirst", "airwallex", "payoneer", "redotpay", "binance")
        private const val SEEN = "klok_wallet_seen"
        private const val POLL_EVERY_MS = 60_000L
    }

    private fun watched(): List<String> =
        (getSharedPreferences("FlutterSharedPreferences", MODE_PRIVATE).getString("flutter.wallet.apps", null)
            ?.split(',')?.map { Forwarder.normalise(it) }?.filter { it.isNotEmpty() })
            ?: DEFAULT_APPS

    private fun fromGmail(sbn: StatusBarNotification) {
        val n = sbn.notification
        if (n.flags and Notification.FLAG_GROUP_SUMMARY != 0 || Forwarder.url(this) == null) {
            return
        }
        val extras = n.extras
        val sender = extras.getCharSequence(Notification.EXTRA_TITLE)?.toString() ?: return
        // Only from a bank / wallet switched on in Senders.
        if (!Forwarder.senderAllowed(this, sender)) {
            return
        }
        val text = listOfNotNull(
            extras.getCharSequence(Notification.EXTRA_TEXT)?.toString(),
            extras.getCharSequence(Notification.EXTRA_BIG_TEXT)?.toString(),
        ).distinct().joinToString(". ")
        if (text.isBlank()) {
            return
        }
        val seen = getSharedPreferences(SEEN, MODE_PRIVATE)
        val hash = ("gmail" + sender + text).hashCode().toString()
        if (seen.contains(hash)) {
            return
        }
        seen.edit().putLong(hash, System.currentTimeMillis()).apply()
        if (Forwarder.enqueue(this, "email", sender, text, sbn.postTime)) {
            Forwarder.sendNow(this) {}
        }
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        if (sbn.packageName == packageName) {
            return
        }
        // The phone is awake (something just came in): a good moment to fetch ours too.
        pollNow()
        // A bank's email in Gmail (BRAC, Midland…): sent like its SMS — the server books it once.
        if (sbn.packageName == "com.google.android.gm") {
            fromGmail(sbn)
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
        val isWatched = watched().any { key.contains(it) }
        // Android's own and ongoing ones (music, downloads) are never about a payment.
        if (!isWatched && (sbn.isOngoing || sbn.packageName == "android" || sbn.packageName.startsWith("com.android.systemui"))) {
            return
        }

        val extras = sbn.notification.extras
        val title = extras.getCharSequence(Notification.EXTRA_TITLE)?.toString() ?: ""
        val text = (extras.getCharSequence(Notification.EXTRA_BIG_TEXT) ?: extras.getCharSequence(Notification.EXTRA_TEXT))?.toString() ?: ""
        val message = listOf(title, text).filter { it.isNotBlank() }.joinToString(". ")
        // Any other app only when it shows an amount: the server books it once I trust that app.
        if (message.isBlank() || (!isWatched && !Forwarder.looksLikeMoney(message))) {
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
