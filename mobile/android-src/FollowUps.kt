package com.klokads.app

import android.app.Notification
import android.app.NotificationManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.provider.CallLog
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.TimeUnit

/**
 * Work follow-ups: a WhatsApp / Messenger / IMO message I haven't opened, or a call I missed and
 * haven't returned. The phone tells the server "new", "seen" (the chat's notification went away
 * because I opened it) and "called back" (from the call log); the server reminds me after an hour.
 * Events wait in a queue on the phone and go when there is internet.
 */
object FollowUps {
    private const val FLUTTER = "FlutterSharedPreferences"
    private const val QUEUE = "klok_followup_queue"
    private const val WORK = "klok-followups"

    /** Chat app packages → the server's source names. */
    val CHAT_APPS = mapOf(
        "com.whatsapp" to "whatsapp",
        "com.whatsapp.w4b" to "whatsapp_business",
        "com.facebook.orca" to "messenger",
        "com.facebook.mlite" to "messenger",
        "com.imo.android.imoim" to "imo",
        "com.imo.android.imoimlite" to "imo",
        "com.imo.android.imoimbeta" to "imo",
    )

    /** Not a message from a person: app status, backups, calls in progress. */
    private val NOISE = Regex(
        "checking for new messages|backup|web is currently active|incoming (voice|video) call|ongoing (voice|video) call|calling…|calling\\.\\.\\.|ringing|is typing|new messages from|messages from \\d+ chats|connecting",
        RegexOption.IGNORE_CASE,
    )
    private val MISSED = Regex("missed (voice |video )?call", RegexOption.IGNORE_CASE)

    private fun flutter(context: Context) = context.getSharedPreferences(FLUTTER, Context.MODE_PRIVATE)

    fun url(context: Context): String? = flutter(context).getString("flutter.followups.url", null)

    /** Which sources I switched on (all, until I choose). */
    fun watching(context: Context, source: String): Boolean {
        val chosen = flutter(context).getString("flutter.followup.apps", null) ?: return true
        return chosen.split(',').map { it.trim() }.contains(source)
    }

    // ------------------------------------------------------------------ chat notifications

    fun onPosted(context: Context, sbn: StatusBarNotification) {
        val source = CHAT_APPS[sbn.packageName] ?: return
        if (url(context) == null || !watching(context, source)) return
        val n = sbn.notification
        if (n.flags and Notification.FLAG_GROUP_SUMMARY != 0 || n.flags and Notification.FLAG_ONGOING_EVENT != 0) return
        val extras = n.extras
        if (extras.getBoolean(Notification.EXTRA_IS_GROUP_CONVERSATION, false)) return // groups: too noisy

        val person = person(extras) ?: return
        val text = latestText(extras)
        if (NOISE.containsMatchIn(person) || NOISE.containsMatchIn(text) || person.equals(sbn.packageName, true)) return
        val missed = MISSED.containsMatchIn(text) || MISSED.containsMatchIn(person)

        // Each message once (apps re-post the same notification as it updates).
        val seen = context.getSharedPreferences("klok_chat_seen", Context.MODE_PRIVATE)
        val hash = (source + person + text).hashCode().toString()
        if (seen.contains(hash)) return
        val editor = seen.edit().putLong(hash, System.currentTimeMillis())
        if (seen.all.size > 300) {
            seen.all.entries.sortedBy { it.value as? Long ?: 0L }.take(150).forEach { editor.remove(it.key) }
        }
        editor.apply()

        queue(context, JSONObject().put("action", "new").put("source", source).put("kind", if (missed) "missed_call" else "message")
            .put("person", person).put("text", if (missed) null else text).put("at", sbn.postTime))
    }

    /** The chat's notification went away because I opened (or cleared) it: seen. */
    fun onRemoved(context: Context, sbn: StatusBarNotification, reason: Int) {
        val source = CHAT_APPS[sbn.packageName] ?: return
        if (url(context) == null || !watching(context, source)) return
        if (reason !in listOf(
                NotificationListenerService.REASON_CLICK, NotificationListenerService.REASON_CANCEL,
                NotificationListenerService.REASON_APP_CANCEL, NotificationListenerService.REASON_APP_CANCEL_ALL,
                NotificationListenerService.REASON_CANCEL_ALL,
            )
        ) return
        val extras = sbn.notification.extras
        if (sbn.notification.flags and Notification.FLAG_GROUP_SUMMARY != 0) return
        val person = person(extras) ?: return
        // A missed call stays open until I call back; only messages are "seen".
        if (MISSED.containsMatchIn(latestText(extras))) return
        queue(context, JSONObject().put("action", "seen").put("source", source).put("person", person).put("at", System.currentTimeMillis()))
    }

    private fun person(extras: Bundle): String? {
        val title = extras.getCharSequence(Notification.EXTRA_CONVERSATION_TITLE)?.toString()
            ?: extras.getCharSequence(Notification.EXTRA_TITLE)?.toString()
            ?: return null
        // "Rahim (3 messages)" → "Rahim"
        return title.replace(Regex("\\s*\\(\\d+ (new )?messages?\\)$", RegexOption.IGNORE_CASE), "").trim().ifEmpty { null }
    }

    private fun latestText(extras: Bundle): String {
        // Chat apps put each message in EXTRA_MESSAGES; the last is the newest.
        @Suppress("DEPRECATION")
        val messages = extras.getParcelableArray(Notification.EXTRA_MESSAGES)
        val last = (messages?.lastOrNull() as? Bundle)?.getCharSequence("text")?.toString()
        return last ?: (extras.getCharSequence(Notification.EXTRA_BIG_TEXT) ?: extras.getCharSequence(Notification.EXTRA_TEXT))?.toString() ?: ""
    }

    // ------------------------------------------------------------------ phone calls

    /** New entries in the call log since the last look: missed → open, called back / answered → closed. */
    fun checkCalls(context: Context) {
        if (url(context) == null || !watching(context, "call")) return
        if (context.checkSelfPermission(android.Manifest.permission.READ_CALL_LOG) != PackageManager.PERMISSION_GRANTED) return
        val prefs = context.getSharedPreferences(QUEUE, Context.MODE_PRIVATE)
        val since = prefs.getLong("calls_since", System.currentTimeMillis() - 60 * 60 * 1000L)
        var newest = since
        try {
            context.contentResolver.query(
                CallLog.Calls.CONTENT_URI, arrayOf(CallLog.Calls.NUMBER, CallLog.Calls.CACHED_NAME, CallLog.Calls.TYPE, CallLog.Calls.DATE, CallLog.Calls.DURATION),
                "${CallLog.Calls.DATE} > ?", arrayOf(since.toString()), "${CallLog.Calls.DATE} ASC",
            )?.use { c ->
                while (c.moveToNext()) {
                    val number = c.getString(0) ?: continue
                    val name = c.getString(1)
                    val type = c.getInt(2)
                    val at = c.getLong(3)
                    newest = maxOf(newest, at)
                    val event = when (type) {
                        CallLog.Calls.MISSED_TYPE, CallLog.Calls.REJECTED_TYPE ->
                            JSONObject().put("action", "new").put("kind", "missed_call")
                        CallLog.Calls.OUTGOING_TYPE, CallLog.Calls.INCOMING_TYPE ->
                            JSONObject().put("action", "called_back")
                        else -> continue
                    }
                    queue(context, event.put("source", "call").put("person", name ?: number).put("phone", number).put("at", at).put("duration", c.getLong(4)), send = false)
                }
            }
        } catch (_: SecurityException) {
            return
        }
        prefs.edit().putLong("calls_since", newest).apply()
        schedule(context)
    }

    // ------------------------------------------------------------------ queue

    @Synchronized
    private fun read(context: Context): JSONArray =
        try {
            JSONArray(context.getSharedPreferences(QUEUE, Context.MODE_PRIVATE).getString("items", "[]"))
        } catch (e: Exception) {
            JSONArray()
        }

    @Synchronized
    private fun write(context: Context, items: JSONArray) {
        context.getSharedPreferences(QUEUE, Context.MODE_PRIVATE).edit().putString("items", items.toString()).commit()
    }

    @Synchronized
    fun queue(context: Context, event: JSONObject, send: Boolean = true) {
        val items = read(context)
        items.put(event)
        // Never more than the last 500 waiting (the phone was offline for days).
        val kept = JSONArray()
        for (i in maxOf(0, items.length() - 500) until items.length()) kept.put(items.get(i))
        write(context, kept)
        if (send) schedule(context)
    }

    fun schedule(context: Context) {
        val request = OneTimeWorkRequestBuilder<FollowUpWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(WORK, ExistingWorkPolicy.APPEND_OR_REPLACE, request)
    }

    /** @return true when nothing is left waiting. */
    fun flush(context: Context): Boolean {
        val target = url(context) ?: return true
        val items = synchronized(this) { read(context) }
        if (items.length() == 0) return true
        val batch = JSONArray()
        for (i in 0 until minOf(items.length(), 100)) batch.put(items.get(i))
        val status = try {
            post(target, JSONObject().put("events", batch))
        } catch (e: Exception) {
            return false
        }
        if (status !in 200..299 && status != 422) return false
        synchronized(this) {
            // Drop what was sent (new events may have arrived meanwhile).
            val now = read(context)
            val kept = JSONArray()
            for (i in batch.length() until now.length()) kept.put(now.get(i))
            write(context, kept)
        }
        return read(context).length() == 0 || flush(context)
    }

    fun post(target: String, body: JSONObject): Int {
        val connection = URL(target).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = 15000
            connection.readTimeout = 20000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            return connection.responseCode
        } finally {
            connection.disconnect()
        }
    }
}

class FollowUpWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result = if (FollowUps.flush(applicationContext)) Result.success() else Result.retry()
}

/** "Done" / "1 hour later" on a follow-up reminder, without opening the app. */
class FollowUpActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getStringExtra("id") ?: return
        val action = intent.getStringExtra("action") ?: return
        val notification = intent.getIntExtra("notification", 0)
        context.getSystemService(NotificationManager::class.java).cancel(notification)
        val base = FollowUps.url(context) ?: return
        val pending = goAsync()
        Thread {
            try {
                FollowUps.post("$base/$id/$action", JSONObject().put("minutes", 60))
            } catch (_: Exception) {
            } finally {
                pending.finish()
            }
        }.start()
    }
}
