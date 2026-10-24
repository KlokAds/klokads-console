package com.klokads.app

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.net.Uri
import android.os.Build
import android.provider.Settings
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

/**
 * The app's own updates, without the Play Store.
 *
 * The new APK is downloaded inside the app and handed to Android's installer. The first time,
 * Android asks once ("allow from this source" and "install?"). After that the app is the
 * installer of its own updates, and on Android 12+ later versions go in without asking: the
 * app closes and the new one is there on the next open. Login and settings stay.
 */
object AppUpdater {
    private const val ACTION = "com.klokads.app.UPDATE_STATUS"

    /** Whether Android lets this app install APKs at all (asked once in Settings). */
    fun allowed(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.O || context.packageManager.canRequestPackageInstalls()

    /** Opens Android's "install unknown apps" page for this app, so the person can allow it once. */
    fun askToAllow(context: Context) {
        val intent = Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        context.startActivity(intent)
    }

    /** Downloads the APK and starts installing it. Runs on a background thread; returns at once. */
    fun install(context: Context, url: String, onDone: (String) -> Unit) {
        Thread {
            try {
                val apk = File(context.cacheDir, "update.apk")
                download(url, apk)
                commit(context, apk)
                onDone("started")
            } catch (e: Throwable) {
                onDone("failed: ${e.message}")
            }
        }.start()
    }

    private fun download(url: String, to: File) {
        val connection = URL(url).openConnection() as HttpURLConnection
        try {
            connection.connectTimeout = 20000
            connection.readTimeout = 60000
            connection.instanceFollowRedirects = true
            if (connection.responseCode !in 200..299) {
                throw IllegalStateException("download ${connection.responseCode}")
            }
            connection.inputStream.use { input -> to.outputStream().use { input.copyTo(it) } }
        } finally {
            connection.disconnect()
        }
    }

    private fun commit(context: Context, apk: File) {
        val installer = context.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
            setAppPackageName(context.packageName)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                // Once this app installed itself, Android 12+ lets its updates in without a prompt.
                setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
            }
        }
        val id = installer.createSession(params)
        installer.openSession(id).use { session ->
            session.openWrite("klok.apk", 0, apk.length()).use { out ->
                apk.inputStream().use { it.copyTo(out) }
                session.fsync(out)
            }
            val flags = PendingIntent.FLAG_UPDATE_CURRENT or
                (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) PendingIntent.FLAG_MUTABLE else 0)
            val status = PendingIntent.getBroadcast(
                context, id, Intent(context, UpdateReceiver::class.java).setAction(ACTION), flags,
            )
            session.commit(status.intentSender)
        }
    }
}

/** Android tells the result here; when it needs the person's OK, its own install screen is shown. */
class UpdateReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        when (intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)) {
            PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                @Suppress("DEPRECATION")
                val confirm = intent.getParcelableExtra<Intent>(Intent.EXTRA_INTENT) ?: return
                context.startActivity(confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            }
            else -> {}
        }
    }
}
