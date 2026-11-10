package com.fomaxo.pos;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;

/**
 * What the home-screen widget shows, kept on the phone so it shows at once (also without internet),
 * and refreshed from pos.fomaxo.com with the login the POS app handed over.
 */
final class WidgetData {
    private WidgetData() {}

    static SharedPreferences prefs(Context c) {
        return c.getApplicationContext().getSharedPreferences("fx_widget", Context.MODE_PRIVATE);
    }

    static void saveLogin(Context c, String token, String name, String role) {
        SharedPreferences.Editor e = prefs(c).edit()
            .putString("token", token == null ? "" : token)
            .putString("name", name == null ? "" : name)
            .putString("role", role == null ? "" : role);
        if (token == null || token.isEmpty()) {
            e.remove("total").remove("orders").remove("waiting").remove("label").remove("time");
        }
        e.apply();
    }

    /** Fetches today's numbers in the background, then redraws every widget. */
    static void refreshInBackground(Context c) {
        refreshInBackground(c, null);
    }

    static void refreshInBackground(Context c, Runnable done) {
        final Context app = c.getApplicationContext();
        new Thread(() -> {
            try {
                fetch(app);
            } finally {
                SalesWidget.redrawAll(app);
                if (done != null) done.run();
            }
        }, "fx-widget").start();
    }

    /** Asks the server. Keeps the last numbers when there is no internet. */
    static void fetch(Context c) {
        SharedPreferences p = prefs(c);
        String token = p.getString("token", "");
        if (token.isEmpty()) {
            p.edit().putString("state", "login").apply();
            return;
        }
        HttpURLConnection con = null;
        try {
            con = (HttpURLConnection) new URL(MainActivity.HOME + "api.php?a=widget").openConnection();
            con.setConnectTimeout(10000);
            con.setReadTimeout(10000);
            con.setUseCaches(false);
            con.setRequestProperty("X-Auth-Token", token);
            con.setRequestProperty("Accept", "application/json");
            int code = con.getResponseCode();
            if (code == 401) {
                p.edit().putString("state", "login").apply();
                return;
            }
            if (code != 200) {
                p.edit().putString("state", "offline").apply();
                return;
            }
            JSONObject j = new JSONObject(read(con.getInputStream()));
            p.edit()
                .putString("total", j.optString("total", "AED 0.00"))
                .putInt("orders", j.optInt("orders", 0))
                .putInt("waiting", j.optInt("waiting", 0))
                .putString("label", j.optString("label", ""))
                .putString("time", j.optString("time", ""))
                .putString("state", "ok")
                .apply();
        } catch (Exception e) {
            p.edit().putString("state", "offline").apply();
        } finally {
            if (con != null) con.disconnect();
        }
    }

    private static String read(InputStream in) throws Exception {
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        byte[] buf = new byte[4096];
        int n;
        while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
        in.close();
        return out.toString("UTF-8");
    }
}
