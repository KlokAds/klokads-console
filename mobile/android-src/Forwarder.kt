package com.klokads.app

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
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
import java.util.UUID
import java.util.concurrent.TimeUnit

/**
 * Bank / wallet messages to the server, done natively so it works with the app closed.
 *
 * Only what I allowed leaves the phone: SMS from the senders switched on in the app (or, with
 * that check off, any bank-like sender — never a person's number), notifications from the
 * wallet apps switched on, and never a message with an ignore word (codes, PINs). Every one
 * is kept in a history on the phone with its state — waiting, sent (and what the server made
 * of it), failed — and is sent by WorkManager until the server has it.
 */
object Forwarder {
    /** Flutter's shared_preferences file: server URLs after pairing, senders and ignore words. */
    private const val FLUTTER = "FlutterSharedPreferences"
    private const val HISTORY = "klok_history"
    private const val WORK = "klok-forward"
    private const val KEEP = 300

    private val MONEY = Regex("(tk\\.?|bdt|৳|taka|usd|us\\$|sgd|s\\$|\\$)\\s*\\d|\\d[\\d,.]*\\s*(tk|bdt|taka|usd|sgd)\\b", RegexOption.IGNORE_CASE)
    private val TRX = Regex("trx ?id|txn ?id|transaction id", RegexOption.IGNORE_CASE)

    private fun flutter(context: Context) = context.getSharedPreferences(FLUTTER, Context.MODE_PRIVATE)

    fun url(context: Context): String? = flutter(context).getString("flutter.inbox.url", null)

    fun normalise(name: String) = name.lowercase().replace(Regex("[^a-z0-9]"), "")

    /** A person's number (+8801…, 01711…) is never forwarded; names and short codes (16216) can be. */
    fun fromBankOrWallet(sender: String): Boolean {
        val digits = sender.filter { it.isDigit() }
        val isPhone = digits.length >= 10 && sender.replace(Regex("[\\s+()-]"), "").all { it.isDigit() }
        return !isPhone
    }

    private fun list(context: Context, key: String): List<String> =
        (flutter(context).getString(key, null) ?: "").split('\n').map { it.trim() }.filter { it.isNotEmpty() }

    /** Is this SMS sender switched on? */
    fun senderAllowed(context: Context, sender: String): Boolean {
        if (!fromBankOrWallet(sender)) {
            return false
        }
        if (!flutter(context).getBoolean("flutter.inbox.whitelist", true)) {
            return true
        }
        val from = normalise(sender)
        return list(context, "flutter.inbox.senders").map { normalise(it) }.any { it.isNotEmpty() && (from == it || from.contains(it)) }
    }

    fun ignored(context: Context, text: String): Boolean {
        if (TRX.containsMatchIn(text)) {
            return false
        }
        val lower = text.lowercase()
        return list(context, "flutter.inbox.ignore").any { Regex("\\b" + Regex.escape(it.lowercase()) + "\\b").containsMatchIn(lower) }
    }

    fun looksLikeMoney(text: String) = MONEY.containsMatchIn(text)

    // ------------------------------------------------------------------ history

    @Synchronized
    fun history(context: Context): JSONArray =
        try {
            JSONArray(context.getSharedPreferences(HISTORY, Context.MODE_PRIVATE).getString("items", "[]"))
        } catch (e: Exception) {
            JSONArray()
        }

    @Synchronized
    private fun save(context: Context, items: JSONArray) {
        // Newest first; the oldest finished ones go when the list is full.
        val kept = JSONArray()
        for (i in 0 until minOf(items.length(), KEEP)) {
            kept.put(items.getJSONObject(i))
        }
        context.getSharedPreferences(HISTORY, Context.MODE_PRIVATE).edit().putString("items", kept.toString()).commit()
    }

    @Synchronized
    private fun update(context: Context, id: String, change: (JSONObject) -> Unit) {
        val items = history(context)
        for (i in 0 until items.length()) {
            if (items.getJSONObject(i).getString("id") == id) {
                change(items.getJSONObject(i))
            }
        }
        save(context, items)
    }

    fun pending(context: Context): Int {
        val items = history(context)
        return (0 until items.length()).count { items.getJSONObject(it).optString("state") in listOf("pending", "failed") }
    }

    /** Keeps a message (if it is allowed at all). @return true when it waits to be sent. */
    @Synchronized
    fun enqueue(context: Context, source: String, from: String, text: String, at: Long): Boolean {
        if (url(context) == null || ignored(context, text)) {
            return false
        }
        val money = looksLikeMoney(text)
        val item = JSONObject().put("id", UUID.randomUUID().toString()).put("source", source).put("from", from)
            .put("text", text).put("at", at).put("state", if (money) "pending" else "skipped")
        if (!money) {
            item.put("note", "No amount — not a transaction")
        }
        val items = history(context)
        val next = JSONArray().put(item)
        for (i in 0 until items.length()) {
            next.put(items.getJSONObject(i))
        }
        save(context, next)
        return money
    }

    /**
     * SMS already on the phone from the last [days] days, from allowed senders, that are not in
     * the history yet. The server books each transaction once, so nothing is counted twice.
     * @return how many were added.
     */
    fun importInbox(context: Context, days: Int): Int {
        val since = System.currentTimeMillis() - days * 86_400_000L
        val known = history(context).let { items -> (0 until items.length()).map { items.getJSONObject(it).let { o -> o.optString("from") + o.optLong("at") } }.toSet() }
        var added = 0
        context.contentResolver.query(
            Telephony.Sms.Inbox.CONTENT_URI, arrayOf(Telephony.Sms.ADDRESS, Telephony.Sms.BODY, Telephony.Sms.DATE),
            "${Telephony.Sms.DATE} > ?", arrayOf(since.toString()), "${Telephony.Sms.DATE} ASC",
        )?.use { cursor ->
            while (cursor.moveToNext()) {
                val from = cursor.getString(0) ?: continue
                val text = cursor.getString(1) ?: continue
                val at = cursor.getLong(2)
                if (from + at !in known && senderAllowed(context, from) && enqueue(context, "sms", from, text, at)) {
                    added++
                }
            }
        }
        if (added > 0) {
            schedule(context)
        }
        return added
    }

    fun retry(context: Context, id: String?) {
        val items = history(context)
        for (i in 0 until items.length()) {
            val item = items.getJSONObject(i)
            if ((id == null && item.optString("state") == "failed") || item.getString("id") == id) {
                item.put("state", "pending").remove("error")
            }
        }
        save(context, items)
        schedule(context)
    }

    /** Sends the waiting ones as soon as there is internet; retried with growing gaps until done. */
    fun schedule(context: Context) {
        val request = OneTimeWorkRequestBuilder<ForwardWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(WORK, ExistingWorkPolicy.APPEND_OR_REPLACE, request)
    }

    /**
     * @param quick the try made the moment a message arrives: short timeouts, to finish inside the
     *              few seconds Android gives a receiver.
     * @return true when nothing is left waiting.
     */
    fun flush(context: Context, quick: Boolean = false): Boolean {
        val target = url(context) ?: return true
        val items = history(context)
        var sent = false
        for (i in items.length() - 1 downTo 0) {
            val item = items.getJSONObject(i)
            if (item.optString("state") !in listOf("pending", "failed")) {
                continue
            }
            val id = item.getString("id")
            val (status, body) = try {
                post(target, quick, JSONObject()
                    .put("source", item.optString("source", "sms"))
                    .put("from", item.getString("from"))
                    .put("text", item.getString("text"))
                    .put("receivedStamp", item.getLong("at")))
            } catch (e: Exception) {
                return false // no internet: stays waiting
            }
            when {
                status in 200..299 -> {
                    val reply = try { JSONObject(body) } catch (e: Exception) { JSONObject() }
                    update(context, id) {
                        it.put("state", "sent").put("result", reply.optString("status")).put("note", reply.optString("note"))
                            .put("account", reply.optString("account")).put("sentAt", System.currentTimeMillis()).remove("error")
                    }
                    flutter(context).edit().putLong("flutter.inbox.last_ok", System.currentTimeMillis()).apply()
                    sent = true
                }
                status == 404 -> {
                    flutter(context).edit().putString("flutter.inbox.error", "revoked").apply()
                    update(context, id) { it.put("state", "failed").put("error", "Not paired any more — open Klok to pair again") }
                    return false
                }
                status == 422 -> update(context, id) { it.put("state", "skipped").put("note", "The server could not read it") }
                else -> {
                    update(context, id) { it.put("state", "failed").put("error", "Server error $status — will try again") }
                    return false
                }
            }
        }
        if (sent) {
            // Whatever the server made of them shows up as phone notifications.
            Feed.pollSoon(context)
        }
        return pending(context) == 0
    }

    private fun post(target: String, quick: Boolean, body: JSONObject): Pair<Int, String> {
        val connection = URL(target).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = if (quick) 4000 else 15000
            connection.readTimeout = if (quick) 5000 else 20000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            val code = connection.responseCode
            val text = try {
                (if (code in 200..299) connection.inputStream else connection.errorStream)?.bufferedReader()?.use { it.readText() } ?: ""
            } catch (e: Exception) {
                ""
            }
            return code to text
        } finally {
            connection.disconnect()
        }
    }

    /** Sent at once while Android lets the caller run; whatever can't go waits for WorkManager. */
    fun sendNow(context: Context, finish: () -> Unit) {
        schedule(context)
        Thread {
            try {
                flush(context, quick = true)
            } catch (_: Throwable) {
            } finally {
                finish()
            }
        }.start()
    }
}

class ForwardWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result =
        if (Forwarder.flush(applicationContext)) Result.success() else Result.retry()
}

/** Android hands every incoming SMS here, even with the app closed. */
class SmsReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) {
            return
        }
        val parts = Telephony.Sms.Intents.getMessagesFromIntent(intent) ?: return
        // A long SMS arrives in pieces: joined back per sender.
        val bySender = LinkedHashMap<String, StringBuilder>()
        var at = System.currentTimeMillis()
        for (part in parts) {
            val from = part.originatingAddress ?: continue
            bySender.getOrPut(from) { StringBuilder() }.append(part.messageBody ?: "")
            at = part.timestampMillis
        }
        var queued = false
        for ((from, text) in bySender) {
            if (Forwarder.senderAllowed(context, from)) {
                queued = Forwarder.enqueue(context, "sms", from, text.toString(), at) || queued
            }
        }
        if (queued) {
            val pending = goAsync()
            Forwarder.sendNow(context) { pending.finish() }
        }
    }
}

/** After a restart: the notification poll comes back (WorkManager keeps the rest). */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            Feed.schedule(context)
            Forwarder.schedule(context)
        }
    }
}
