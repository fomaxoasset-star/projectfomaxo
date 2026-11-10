package com.fomaxo.pos;

import android.content.Context;

/** The app's version name, e.g. "1.0.7". */
final class BuildConfigInfo {
    private BuildConfigInfo() {}

    static String version(Context c) {
        try {
            return c.getPackageManager().getPackageInfo(c.getPackageName(), 0).versionName;
        } catch (Exception e) {
            return "1";
        }
    }
}
