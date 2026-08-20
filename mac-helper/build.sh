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
mkdir -p "$APP/Contents/MacOS"

swiftc -O -parse-as-library \
    -o "$APP/Contents/MacOS/TaskStickReminders" \
    Sources/App.swift \
    -framework Cocoa \
    -framework EventKit

cp Info.plist "$APP/Contents/Info.plist"

codesign --force --deep --sign - "$APP"

echo "Built $APP"
echo "Run: open '$APP'"
