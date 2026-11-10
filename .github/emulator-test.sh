#!/bin/bash
# Runs inside the emulator job: install, open, screenshot, widget broadcast, crash check.
mkdir -p test-out
adb install -r signed/FOMAXO-Store.apk > test-out/install.txt 2>&1
adb logcat -c
adb shell am start -n com.fomaxo.pos/.MainActivity >> test-out/install.txt 2>&1
sleep 25
adb exec-out screencap -p > test-out/1-open.png
# widget's Sales button path: open with view=sales
adb shell am start -n com.fomaxo.pos/.MainActivity --es view sales -a com.fomaxo.pos.OPEN_SALES >> test-out/install.txt 2>&1
sleep 6
adb exec-out screencap -p > test-out/2-sales-intent.png
# widget refresh broadcast must not crash (no widget placed yet, so it just fetches)
adb shell am broadcast -n com.fomaxo.pos/.SalesWidget -a com.fomaxo.pos.WIDGET_REFRESH >> test-out/install.txt 2>&1
sleep 5
# back button: leaves app (login screen has nothing to close)
adb shell input keyevent 4; sleep 2
adb shell dumpsys window | grep -E "mCurrentFocus" >> test-out/install.txt
adb shell dumpsys appwidget | grep -i -A2 fomaxo | head -20 > test-out/widget-provider.txt
adb logcat -d | grep -E "FATAL|AndroidRuntime|com.fomaxo.pos|chromium" | head -200 > test-out/logcat.txt
grep -q "FATAL EXCEPTION" test-out/logcat.txt && echo "CRASH" > test-out/result.txt || echo "NO CRASH" > test-out/result.txt
true
