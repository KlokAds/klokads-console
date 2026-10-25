package com.klokads.app

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.TimeUnit

/**
 * The server's notifications (money in/out, reminders, invoices) as phone notifications.
 * Asked every 15 minutes (the shortest Android allows in the background), right after a
 * message was forwarded, and whenever the app opens. Each one is shown once.
 */
object Feed {
    private const val FLUTTER = "FlutterSharedPreferences"
    private const val PERIODIC = "klok-feed"
    private const val NOW = "klok-feed-now"

    const val MONEY = "klok_money"
    const val REMINDERS = "klok_reminders"

    private fun flutter(context: Context) = context.getSharedPreferences(FLUTTER, Context.MODE_PRIVATE)

    private val online = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    fun schedule(context: Context) {
        val request = PeriodicWorkRequestBuilder<FeedWorker>(15, TimeUnit.MINUTES).setConstraints(online).build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(PERIODIC, ExistingPeriodicWorkPolicy.UPDATE, request)
    }

    /** A few seconds from now (the server has just booked something). */
    fun pollSoon(context: Context) {
        val request = OneTimeWorkRequestBuilder<FeedWorker>().setConstraints(online).setInitialDelay(3, TimeUnit.SECONDS).build()
        WorkManager.getInstance(context).enqueueUniqueWork(NOW, ExistingWorkPolicy.REPLACE, request)
    }

    fun channels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }
        val manager = context.getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel(MONEY, "Money in & out", NotificationManager.IMPORTANCE_HIGH).apply {
            description = "Every automatic credit and debit"
        })
        manager.createNotificationChannel(NotificationChannel(REMINDERS, "Reminders", NotificationManager.IMPORTANCE_HIGH).apply {
            description = "Dena-paona, bills, envelopes, invoices to send"
        })
    }

    /** @return false when the server could not be reached (try again later). */
    fun poll(context: Context): Boolean {
        val prefs = flutter(context)
        val url = prefs.getString("flutter.feed.url", null) ?: return true
        val after = prefs.getLong("flutter.feed.last", 0L)
        val body = try {
            get("$url?after=$after")
        } catch (e: Exception) {
            return false
        } ?: return false

        val json = JSONObject(body)
        val items = json.optJSONArray("items") ?: return true
        var last = after
        channels(context)
        for (i in 0 until items.length()) {
            val item = items.getJSONObject(i)
            val id = item.getLong("id")
            // A missing link comes as JSON null, which optString turns into the word "null".
            val link = if (item.isNull("url")) "" else item.optString("url")
            show(context, id, item.optString("kind"), item.optString("title"), item.optString("body"), link)
            last = maxOf(last, id)
        }
        prefs.edit().putLong("flutter.feed.last", maxOf(last, if (items.length() == 0) json.optLong("last_id", last) else last)).apply()
        return true
    }

    private fun show(context: Context, id: Long, kind: String, title: String, body: String, url: String) {
        val open = Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtra("url", url)
        // One notification per chat (c=…): a newer message replaces it quietly instead of stacking up.
        val chat = Regex("[?&]c=([a-f0-9]+)").find(url)?.groupValues?.get(1)
        val shown = chat?.let { "chat-$it".hashCode() } ?: id.toInt()
        val tap = PendingIntent.getActivity(context, id.toInt(), open, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val money = kind == "credit" || kind == "debit"
        val builder = NotificationCompat.Builder(context, if (money) MONEY else REMINDERS)
            .setSmallIcon(R.drawable.ic_notification)
            .setColor(if (kind == "credit") 0xFF059669.toInt() else if (kind == "debit") 0xFFE11D48.toInt() else 0xFF0D9488.toInt())
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(if (money) NotificationCompat.CATEGORY_STATUS else NotificationCompat.CATEGORY_REMINDER)
            .setAutoCancel(true)
            .setOnlyAlertOnce(chat != null)
            .setContentIntent(tap)
        // A follow-up reminder: done or later, right from the notification. Everything else: "Seen".
        val followUpId = Regex("[?&]f=(\\d+)").find(url)?.groupValues?.get(1)
        val taskId = Regex("[?&]t=(\\d+)").find(url)?.groupValues?.get(1)
        val touchId = Regex("[?&]k=(\\d+)").find(url)?.groupValues?.get(1)
        // A task (Done ✓) or a family call (Talked ✓), each with "1 hour later".
        listOfNotNull(taskId?.let { it to "tasks" }, touchId?.let { it to "touch" }).firstOrNull()?.let { (target, path) ->
            for ((action, label) in listOf("done" to (if (path == "touch") "Talked ✓" else "Done ✓"), "later" to "1 hour later")) {
                val intent = Intent(context, TaskActionReceiver::class.java)
                    .putExtra("id", target).putExtra("path", path).putExtra("action", action).putExtra("notification", shown)
                builder.addAction(0, label, PendingIntent.getBroadcast(context, (id * 10 + if (action == "done") 4 else 5).toInt(), intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE))
            }
        }
        if (followUpId == null && taskId == null && touchId == null) {
            val seen = Intent(context, SeenReceiver::class.java).putExtra("id", id).putExtra("notification", shown)
            builder.addAction(0, "✓ Seen", PendingIntent.getBroadcast(context, (id * 10 + 3).toInt(), seen, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE))
        }
        followUpId?.let { followUp ->
            for ((action, label) in listOf("done" to "Done", "later" to "1 hour later")) {
                val intent = Intent(context, FollowUpActionReceiver::class.java)
                    .putExtra("id", followUp).putExtra("action", action).putExtra("notification", shown)
                val pending = PendingIntent.getBroadcast(context, (id * 10 + if (action == "done") 1 else 2).toInt(), intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
                builder.addAction(0, label, pending)
            }
        }
        val notification = builder.build()
        try {
            NotificationManagerCompat.from(context).notify(shown, notification)
        } catch (_: SecurityException) {
            // Notifications not allowed on this phone (yet).
        }
    }

    private fun get(target: String): String? {
        val connection = URL(target).openConnection() as HttpURLConnection
        try {
            connection.connectTimeout = 15000
            connection.readTimeout = 20000
            connection.setRequestProperty("Accept", "application/json")
            if (connection.responseCode !in 200..299) {
                return null
            }
            return connection.inputStream.bufferedReader().use { it.readText() }
        } finally {
            connection.disconnect()
        }
    }
}

/** Done / 1 hour later on a task's notification. */
class TaskActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getStringExtra("id") ?: return
        val action = intent.getStringExtra("action") ?: return
        context.getSystemService(NotificationManager::class.java).cancel(intent.getIntExtra("notification", 0))
        val feed = context.getSharedPreferences("FlutterSharedPreferences", Context.MODE_PRIVATE).getString("flutter.feed.url", null) ?: return
        val pending = goAsync()
        Thread {
            try {
                val path = intent.getStringExtra("path") ?: "tasks"
                val connection = URL(feed.replace("/app/feed/", "/app/$path/") + "/$id/$action").openConnection() as HttpURLConnection
                connection.requestMethod = "POST"
                connection.connectTimeout = 8000
                connection.readTimeout = 8000
                connection.setRequestProperty("Accept", "application/json")
                connection.responseCode
                connection.disconnect()
            } catch (_: Exception) {
            } finally {
                pending.finish()
            }
        }.start()
    }
}

/** "✓ Seen" on a notification: it closes, and the bell on the site marks it read too. */
class SeenReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getLongExtra("id", 0L)
        context.getSystemService(NotificationManager::class.java).cancel(intent.getIntExtra("notification", 0))
        val feed = context.getSharedPreferences("FlutterSharedPreferences", Context.MODE_PRIVATE).getString("flutter.feed.url", null) ?: return
        val pending = goAsync()
        Thread {
            try {
                val connection = URL("$feed/read?id=$id").openConnection() as HttpURLConnection
                connection.requestMethod = "POST"
                connection.connectTimeout = 8000
                connection.readTimeout = 8000
                connection.setRequestProperty("Accept", "application/json")
                connection.responseCode
                connection.disconnect()
            } catch (_: Exception) {
            } finally {
                pending.finish()
            }
        }.start()
    }
}

class FeedWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result {
        // Missed calls are found in the call log on the same rhythm.
        try {
            FollowUps.checkCalls(applicationContext)
        } catch (_: Exception) {
        }
        return if (Feed.poll(applicationContext)) Result.success() else Result.retry()
    }
}
