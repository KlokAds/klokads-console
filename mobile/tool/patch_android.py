"""Turns the android/ folder that `flutter create` makes into the MH-Bot app: the package name,
signing (the same key every time, so a new APK installs over the old one), permissions, the
SMS receiver, the wallet-notification listener and our Kotlin. Run from mobile/."""

import re
import shutil
from pathlib import Path

PACKAGE = 'com.klokads.app'


def patch(path, old, new, count=1):
    text = Path(path).read_text(encoding='utf8')
    if not re.search(old, text):
        raise SystemExit(f'{path}: pattern not found: {old}')
    Path(path).write_text(re.sub(old, new, text, count=count), encoding='utf8')


manifest = 'android/app/src/main/AndroidManifest.xml'
app = 'android/app/build.gradle.kts'

patch(app, r'minSdk = [^\n]+', 'minSdk = 23')
patch(app, r'compileOptions \{', 'compileOptions {\n        isCoreLibraryDesugaringEnabled = true')
patch(app, r'(\n(\s*)buildTypes \{)', r'''
    signingConfigs {
        create("release") {
            storeFile = file(System.getenv("KEYSTORE_FILE"))
            storePassword = System.getenv("KEYSTORE_PASSWORD")
            keyAlias = "khamar"
            keyPassword = System.getenv("KEYSTORE_PASSWORD")
        }
    }
\1''')
patch(app, r'signingConfig = signingConfigs\.getByName\("debug"\)', 'signingConfig = signingConfigs.getByName("release")')
# No code shrinking: R8 strips classes some plugins find by reflection.
patch(app, r'(signingConfig = signingConfigs\.getByName\("release"\))', r'''\1
            isMinifyEnabled = false
            isShrinkResources = false''')
Path(app).write_text(Path(app).read_text(encoding='utf8') + '''
dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
    implementation("androidx.core:core-ktx:1.13.1")
}
''', encoding='utf8')
patch(app, r'namespace = "[^"]+"', f'namespace = "{PACKAGE}"')
patch(app, r'applicationId = "[^"]+"', f'applicationId = "{PACKAGE}"')

patch(manifest, r'(<manifest[^>]*>)', r'''\1
    <uses-permission android:name="android.permission.INTERNET" />
    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
    <uses-permission android:name="android.permission.VIBRATE" />
    <uses-permission android:name="android.permission.WAKE_LOCK" />
    <uses-permission android:name="android.permission.USE_BIOMETRIC" />
    <uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />
    <uses-permission android:name="android.permission.RECEIVE_SMS" />
    <uses-permission android:name="android.permission.READ_SMS" />
    <uses-permission android:name="android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS" />
    <uses-permission android:name="android.permission.REQUEST_INSTALL_PACKAGES" />
    <uses-permission android:name="android.permission.UPDATE_PACKAGES_WITHOUT_USER_ACTION" />''')
patch(manifest, r'(\n(\s*)<activity)', r'''
        <receiver android:name=".SmsReceiver" android:exported="true" android:permission="android.permission.BROADCAST_SMS">
            <intent-filter android:priority="999">
                <action android:name="android.provider.Telephony.SMS_RECEIVED" />
            </intent-filter>
        </receiver>
        <receiver android:name=".BootReceiver" android:exported="false">
            <intent-filter>
                <action android:name="android.intent.action.BOOT_COMPLETED" />
            </intent-filter>
        </receiver>
        <receiver android:exported="false" android:name=".UpdateReceiver" />
        <service android:name=".WalletListener" android:exported="false" android:label="MH-Bot wallet alerts"
            android:permission="android.permission.BIND_NOTIFICATION_LISTENER_SERVICE">
            <intent-filter>
                <action android:name="android.service.notification.NotificationListenerService" />
            </intent-filter>
        </service>\1''')
patch(manifest, r'android:label="[^"]*"', 'android:label="MH-Bot"')
# Tapping a notification brings the running app forward with its link (no second copy).
patch(manifest, r'android:launchMode="[^"]*"', 'android:launchMode="singleTask"')
patch(manifest, r'(<queries>)', r'''\1
        <intent>
            <action android:name="android.intent.action.VIEW" />
            <data android:scheme="https" />
        </intent>
        <intent>
            <action android:name="android.intent.action.DIAL" />
        </intent>''')

for made in Path('android/app/src/main/kotlin').rglob('MainActivity.kt'):
    made.unlink()
target = Path('android/app/src/main/kotlin/' + PACKAGE.replace('.', '/'))
target.mkdir(parents=True, exist_ok=True)
for source in Path('android-src').glob('*.kt'):
    shutil.copy(source, target / source.name)

res = Path('android/app/src/main/res')
(res / 'drawable').mkdir(parents=True, exist_ok=True)
# The MH mark in white on nothing: Android tints small notification icons itself.
(res / 'drawable' / 'ic_notification.xml').write_text('''<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="24dp" android:height="24dp" android:viewportWidth="64" android:viewportHeight="64">
    <path android:strokeColor="#FFFFFFFF" android:strokeWidth="6" android:strokeLineCap="round" android:strokeLineJoin="round"
        android:pathData="M12,44L12,21L20,33L28,21L28,44M37,21L37,44M51,21L51,44M37,32.5L51,32.5" />
</vector>
''', encoding='utf8')
print('android patched')
