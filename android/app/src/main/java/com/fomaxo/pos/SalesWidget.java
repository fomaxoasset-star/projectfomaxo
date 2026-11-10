package com.fomaxo.pos;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.widget.RemoteViews;

/** Home-screen widget: today's sales, number of orders, and Sell / Sales / refresh buttons. */
public class SalesWidget extends AppWidgetProvider {

    static final String ACTION_REFRESH = "com.fomaxo.pos.WIDGET_REFRESH";

    @Override
    public void onUpdate(Context c, AppWidgetManager m, int[] ids) {
        redrawAll(c); // last known numbers straight away
        final PendingResult pr = goAsync();
        WidgetData.refreshInBackground(c, pr::finish);
    }

    @Override
    public void onReceive(Context c, Intent i) {
        super.onReceive(c, i);
        if (ACTION_REFRESH.equals(i.getAction())) {
            WidgetData.prefs(c).edit().putString("state", "loading").apply();
            redrawAll(c);
            final PendingResult pr = goAsync();
            WidgetData.refreshInBackground(c, pr::finish);
        }
    }

    static void redrawAll(Context c) {
        AppWidgetManager m = AppWidgetManager.getInstance(c);
        int[] ids = m.getAppWidgetIds(new ComponentName(c, SalesWidget.class));
        if (ids == null || ids.length == 0) return;
        RemoteViews v = build(c);
        for (int id : ids) m.updateAppWidget(id, v);
    }

    static RemoteViews build(Context c) {
        SharedPreferences p = WidgetData.prefs(c);
        RemoteViews v = new RemoteViews(c.getPackageName(), R.layout.widget_sales);
        String state = p.getString("state", "");
        boolean loggedIn = !p.getString("token", "").isEmpty() && !"login".equals(state);

        if (!loggedIn) {
            v.setTextViewText(R.id.w_total, "Log in");
            v.setTextViewText(R.id.w_sub, "Open FOMAXO Store and log in");
            v.setTextViewText(R.id.w_time, "");
        } else {
            v.setTextViewText(R.id.w_total, p.getString("total", "AED 0.00"));
            int orders = p.getInt("orders", 0);
            int waiting = p.getInt("waiting", 0);
            String label = p.getString("label", "");
            String sub = orders + (orders == 1 ? " order" : " orders")
                + (waiting > 0 ? " · " + waiting + " waiting" : "")
                + (label.isEmpty() ? "" : " · " + label);
            v.setTextViewText(R.id.w_sub, sub);
            String time = p.getString("time", "");
            String when = "loading".equals(state) ? "Updating…"
                : "offline".equals(state) ? "Offline" + (time.isEmpty() ? "" : " · " + time)
                : time.isEmpty() ? "Today" : "Today · " + time;
            v.setTextViewText(R.id.w_time, when);
        }

        v.setOnClickPendingIntent(R.id.w_sell, open(c, "sell", 1));
        v.setOnClickPendingIntent(R.id.w_sales, open(c, "sales", 2));
        v.setOnClickPendingIntent(R.id.w_head, open(c, "sell", 3));
        Intent r = new Intent(c, SalesWidget.class).setAction(ACTION_REFRESH);
        v.setOnClickPendingIntent(R.id.w_refresh,
            PendingIntent.getBroadcast(c, 4, r, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE));
        return v;
    }

    private static PendingIntent open(Context c, String view, int code) {
        Intent i = new Intent(c, MainActivity.class)
            .setAction("com.fomaxo.pos.OPEN_" + view.toUpperCase())
            .putExtra("view", view)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        return PendingIntent.getActivity(c, code, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }
}
