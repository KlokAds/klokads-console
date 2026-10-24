package com.klokads.app

import android.content.ComponentName
import android.content.Intent
import android.net.Uri
import android.os.PowerManager
import android.provider.Settings
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/** A FragmentActivity for the fingerprint prompt; one channel for everything native. */
class MainActivity : FlutterFragmentActivity() {
    private var channel: MethodChannel? = null
    private var launchUrl: String? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        launchUrl = intent?.getStringExtra("url")
        Feed.channels(this)
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
                        result.success(null)
                    }
                    "pending" -> result.success(Forwarder.pending(this@MainActivity))
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
                    else -> result.notImplemented()
                }
            }
        }
    }

    /** A notification tapped while the app was already open: go to its page. */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        intent.getStringExtra("url")?.let { channel?.invokeMethod("open", it) }
    }

    private fun walletAccess(): Boolean {
        val enabled = Settings.Secure.getString(contentResolver, "enabled_notification_listeners") ?: return false
        val me = ComponentName(this, WalletListener::class.java).flattenToString()
        return enabled.split(':').any { it == me }
    }
}
