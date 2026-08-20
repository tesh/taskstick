#!/bin/bash
# Builds TaskStickReminders.app from Sources/main.swift.
# No Xcode project needed — just the Swift compiler + Cocoa/EventKit,
# packaged into a minimal .app bundle by hand and ad-hoc code-signed
# (required for macOS to grant it a stable, persistent Reminders
# permission across relaunches).
set -euo pipefail
cd "$(dirname "$0")"

APP="build/TaskStickReminders.app"
rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"

swiftc -O -parse-as-library \
    -o "$APP/Contents/MacOS/TaskStickReminders" \
    Sources/App.swift \
    -framework Cocoa \
    -framework EventKit

cp Info.plist "$APP/Contents/Info.plist"
cp Resources/MenuBarIcon.png "$APP/Contents/Resources/MenuBarIcon.png"

# Finder/Dock/Login-Items icon, built from the same TaskStick mark used
# for the menu bar — .icns needs a full size set, generated here rather
# than checked in so there's one source image to keep in sync.
ICONSET="build/AppIcon.iconset"
rm -rf "$ICONSET"
mkdir -p "$ICONSET"
for size in 16 32 128 256 512; do
    sips -z "$size" "$size" Resources/MenuBarIcon.png --out "$ICONSET/icon_${size}x${size}.png" >/dev/null
    double=$((size * 2))
    sips -z "$double" "$double" Resources/MenuBarIcon.png --out "$ICONSET/icon_${size}x${size}@2x.png" >/dev/null
done
iconutil -c icns "$ICONSET" -o "$APP/Contents/Resources/AppIcon.icns"
rm -rf "$ICONSET"

codesign --force --deep --sign - "$APP"

echo "Built $APP"
echo "Run: open '$APP'"
