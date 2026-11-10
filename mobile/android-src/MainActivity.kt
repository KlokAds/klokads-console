package com.klokads.app

import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Environment
import android.widget.Toast
import androidx.core.content.ContextCompat
import android.os.PowerManager
import android.provider.Settings
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/** A FragmentActivity for the fingerprint prompt; one channel for everything native. */
class MainActivity : FlutterFragmentActivity() {
    private var channel: MethodChannel? = null
    private var launchUrl: String? = null

    /** Downloads started from the app: their names, for the "saved" message when each is done. */
    private val downloads = mutableMapOf<Long, String>()
    private val downloaded = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1)
            val name = downloads.remove(id) ?: return
            Toast.makeText(context, "✅ $name — Downloads-এ রাখা হয়েছে", Toast.LENGTH_LONG).show()
            channel?.invokeMethod("downloaded", name)
        }
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        launchUrl = intent?.getStringExtra("url")?.takeUnless { it.isBlank() || it == "null" }
        Feed.channels(this)
        ContextCompat.registerReceiver(this, downloaded, IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE), ContextCompat.RECEIVER_EXPORTED)
        channel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "klok/native").apply {
            setMethodCallHandler { call, result ->
                when (call.method) {
                    // The link of the notification that opened the app (once).
                    "launchUrl" -> {
                        result.success(launchUrl)
                        launchUrl = null
                    }
                    "paired" -> {
                        Feed.schedule(this@MainActivity)
                        Forwarder.schedule(this@MainActivity)
                        result.success(null)
                    }
                    "poll" -> {
                        Feed.pollSoon(this@MainActivity)
                        Forwarder.schedule(this@MainActivity)
                        Thread { try { FollowUps.checkCalls(this@MainActivity) } catch (_: Exception) {} }.start()
                        result.success(null)
                    }
                    "pending" -> result.success(Forwarder.pending(this@MainActivity))
                    "history" -> result.success(Forwarder.history(this@MainActivity).toString())
                    "retry" -> {
                        Forwarder.retry(this@MainActivity, call.argument<String>("id"))
                        result.success(null)
                    }
                    "importSms" -> {
                        val days = call.argument<Int>("days") ?: 7
                        Thread {
                            val added = try { Forwarder.importInbox(this@MainActivity, days) } catch (e: Exception) { -1 }
                            runOnUiThread { result.success(added) }
                        }.start()
                    }
                    "walletAccess" -> result.success(walletAccess())
                    "openWalletAccess" -> {
                        startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                        result.success(null)
                    }
                    "batteryFree" -> result.success((getSystemService(POWER_SERVICE) as PowerManager).isIgnoringBatteryOptimizations(packageName))
                    "openBattery" -> {
                        startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                        result.success(null)
                    }
                    "updateAllowed" -> result.success(AppUpdater.allowed(this@MainActivity))
                    "askUpdateAllowed" -> {
                        AppUpdater.askToAllow(this@MainActivity)
                        result.success(null)
                    }
                    "install" -> {
                        val url = call.argument<String>("url")
                        if (url == null) {
                            result.error("url", "no url", null)
                        } else {
                            AppUpdater.install(this@MainActivity, url) { status -> runOnUiThread { result.success(status) } }
                        }
                    }
                    // A file from the site (an invoice PDF): straight into Downloads, signed in, with the phone's
                    // own "downloading… / download complete" notification — never through the browser.
                    "download" -> {
                        try {
                            val name = call.argument<String>("name") ?: "file.pdf"
                            val request = DownloadManager.Request(Uri.parse(call.argument<String>("url")))
                                .setTitle(name)
                                .setDescription("MH-Bot")
                                .setMimeType(call.argument<String>("mime") ?: "application/pdf")
                                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                                .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name)
                            call.argument<String>("cookie")?.takeIf { it.isNotBlank() }?.let { request.addRequestHeader("Cookie", it) }
                            call.argument<String>("agent")?.takeIf { it.isNotBlank() }?.let { request.addRequestHeader("User-Agent", it) }
                            val id = (getSystemService(DOWNLOAD_SERVICE) as DownloadManager).enqueue(request)
                            downloads[id] = name
                            result.success(true)
                        } catch (e: Exception) {
                            result.success(false)
                        }
                    }
                    else -> result.notImplemented()
                }
            }
        }
    }

    /** A notification tapped while the app was already open: go to its page. */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        val url = intent.getStringExtra("url")?.takeUnless { it.isBlank() || it == "null" } ?: return
        // Not ready to hear it yet (the app is still starting): hand it over when Flutter asks.
        channel?.invokeMethod("open", url) ?: run { launchUrl = url }
    }

    private fun walletAccess(): Boolean {
        val enabled = Settings.Secure.getString(contentResolver, "enabled_notification_listeners") ?: return false
        val me = ComponentName(this, WalletListener::class.java).flattenToString()
        return enabled.split(':').any { it == me }
    }
}
