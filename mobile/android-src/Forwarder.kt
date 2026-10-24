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
 * Only messages that look like money leave the phone: an SMS from a bank or wallet name or
 * short code (never from a person's mobile number), or a notification from a wallet app,
 * that mentions an amount. Each is first written to a queue on the phone, then sent by
 * WorkManager whenever there is internet, again and again until the server has it — so
 * nothing is lost without internet or after a restart. The server books each TrxID once.
 */
object Forwarder {
    /** Flutter's shared_preferences file, where the app keeps the server URLs after pairing. */
    private const val FLUTTER = "FlutterSharedPreferences"
    private const val QUEUE = "klok_forward_queue"
    private const val WORK = "klok-forward"

    private val MONEY = Regex("(tk\\.?|bdt|৳|taka|usd|us\\$|sgd|s\\$|\\$)\\s*\\d|\\d[\\d,.]*\\s*(tk|bdt|taka|usd|sgd)\\b", RegexOption.IGNORE_CASE)

    private fun flutter(context: Context) = context.getSharedPreferences(FLUTTER, Context.MODE_PRIVATE)

    fun url(context: Context): String? = flutter(context).getString("flutter.inbox.url", null)

    /** A person's number (+8801…, 01711…) is never forwarded; names and short codes (16216) are. */
    fun fromBankOrWallet(sender: String): Boolean {
        val digits = sender.filter { it.isDigit() }
        val isPhone = digits.length >= 10 && sender.replace(Regex("[\\s+()-]"), "").all { it.isDigit() }
        return !isPhone
    }

    fun looksLikeMoney(text: String) = MONEY.containsMatchIn(text)

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

    fun pending(context: Context): Int = read(context).length()

    @Synchronized
    fun enqueue(context: Context, source: String, from: String, text: String, at: Long): Boolean {
        if (url(context) == null || !looksLikeMoney(text)) {
            return false
        }
        val items = read(context)
        items.put(JSONObject().put("id", UUID.randomUUID().toString()).put("source", source).put("from", from).put("text", text).put("at", at))
        write(context, items)
        return true
    }

    @Synchronized
    private fun remove(context: Context, id: String) {
        val items = read(context)
        val kept = JSONArray()
        for (i in 0 until items.length()) {
            if (items.getJSONObject(i).getString("id") != id) {
                kept.put(items.getJSONObject(i))
            }
        }
        write(context, kept)
    }

    /** Sends the queue as soon as there is internet; retried with growing gaps until done. */
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
     * @return true when the queue is empty afterwards.
     */
    fun flush(context: Context, quick: Boolean = false): Boolean {
        val target = url(context) ?: return true
        val items = read(context)
        for (i in 0 until items.length()) {
            val item = items.getJSONObject(i)
            val status = try {
                post(target, quick, JSONObject()
                    .put("source", item.optString("source", "sms"))
                    .put("from", item.getString("from"))
                    .put("text", item.getString("text"))
                    .put("receivedStamp", item.getLong("at")))
            } catch (e: Exception) {
                return false
            }
            when {
                status in 200..299 || status == 422 -> {
                    // 422: the server could not read it at all — retrying would never help.
                    remove(context, item.getString("id"))
                    flutter(context).edit().putLong("flutter.inbox.last_ok", System.currentTimeMillis()).apply()
                }
                status == 404 -> {
                    flutter(context).edit().putString("flutter.inbox.error", "revoked").apply()
                    return false
                }
                else -> return false
            }
        }
        // Whatever the server made of them shows up as phone notifications.
        Feed.pollSoon(context)
        return read(context).length() == 0
    }

    private fun post(target: String, quick: Boolean, body: JSONObject): Int {
        val connection = URL(target).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = if (quick) 4000 else 15000
            connection.readTimeout = if (quick) 5000 else 20000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            return connection.responseCode
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
            if (Forwarder.fromBankOrWallet(from)) {
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
