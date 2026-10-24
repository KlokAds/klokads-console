#!/usr/bin/env bash
# Makes and builds the Android app on the GitHub runner (run from mobile/). On a failure the
# last lines of the log become an annotation, readable without opening the whole log.
set -o pipefail

log="$RUNNER_TEMP/build.log"

fail() {
    echo "::error title=APK build failed at: $1::$(tail -n 60 "$log" | grep -v '^\s*at ' | tail -n 35 | sed 's/%/%25/g' | awk '{printf "%s%%0A", $0}')"
    exit 1
}

run() {
    local step="$1"
    shift
    echo "== $step"
    "$@" 2>&1 | tee -a "$log" || fail "$step"
}

: > "$log"
run "flutter create" flutter create --org com.klokads --project-name klokapp --platforms android .
rm -f test/widget_test.dart
run "patch android" python3 tool/patch_android.py
mkdir -p assets
run "icons (svg)" rsvg-convert -w 1024 -h 1024 assets-src/icon.svg -o assets/icon.png
run "icons (svg fg)" rsvg-convert -w 1024 -h 1024 assets-src/icon-foreground.svg -o assets/icon-foreground.png
echo "$KEYSTORE_BASE64" | tr -d '[:space:]' | base64 -d > "$KEYSTORE_FILE" 2>>"$log" || fail "keystore (not valid base64)"
run "pub get" flutter pub get
# Older plugins still name the ProGuard file that new Android Gradle refuses.
grep -rl "proguard-android.txt" "$HOME/.pub-cache/hosted" --include=build.gradle 2>/dev/null \
    | xargs -r sed -i "s/proguard-android.txt/proguard-android-optimize.txt/g"
run "icons" dart run flutter_launcher_icons
# Only for 64-bit ARM phones (every Android phone of recent years): half the size.
run "build apk" flutter build apk --release --target-platform android-arm64
cp build/app/outputs/flutter-apk/app-release.apk klok.apk
