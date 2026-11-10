package com.fomaxo.pos;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.print.PrintAttributes;
import android.print.PrintManager;
import android.webkit.JavascriptInterface;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

/**
 * FOMAXO Store for Android: the POS (pos.fomaxo.com) in its own app.
 * The POS keeps working offline (its saved copy and offline sales live inside this app),
 * WhatsApp / Ziina / email open in their own apps, receipts print, and the home-screen widget gets the login.
 */
public class MainActivity extends Activity {

    static final String HOST = "pos.fomaxo.com";
    static final String HOME = "https://" + HOST + "/";

    private WebView web;
    private boolean pageReady = false;
    private String pendingView = null;
    private WebView printWeb; // kept while a receipt page is being printed

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle saved) {
        super.onCreate(saved);
        web = new WebView(this);
        web.setBackgroundColor(0xFF0A0A0B);
        setContentView(web);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setTextZoom(100); // the POS sizes its own text
        s.setSupportZoom(false);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setUserAgentString(s.getUserAgentString() + " FomaxoAndroid/" + BuildConfigInfo.version(this));

        web.addJavascriptInterface(new Bridge(), "FxAndroid");
        web.setWebChromeClient(new WebChromeClient());
        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest req) {
                Uri u = req.getUrl();
                String scheme = u.getScheme() == null ? "" : u.getScheme();
                if ((scheme.equals("https") || scheme.equals("http")) && HOST.equalsIgnoreCase(u.getHost())) {
                    return false; // the POS itself stays in the app
                }
                openExternal(u.toString());
                return true;
            }

            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                pageReady = false;
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                pageReady = true;
                runPendingView();
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest req, WebResourceError err) {
                // Only when the POS page itself can't load (first ever start without internet).
                if (req.isForMainFrame()) {
                    view.loadDataWithBaseURL(HOME, offlinePage(), "text/html", "utf-8", null);
                }
            }
        });

        pendingView = viewFrom(getIntent());
        if (saved != null) {
            web.restoreState(saved);
        } else {
            web.loadUrl(HOME);
        }
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        pendingView = viewFrom(intent);
        runPendingView();
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        web.saveState(out);
    }

    @Override
    protected void onPause() {
        super.onPause();
        WidgetData.refreshInBackground(this); // keep the widget in step when leaving the app
    }

    @SuppressWarnings("deprecation")
    @Override
    public void onBackPressed() {
        // The POS closes its own windows / goes back to Sell; when there is nothing left, leave the app.
        web.evaluateJavascript("(window.fxBack ? fxBack() : false)", value -> {
            if (!"true".equals(value)) moveTaskToBack(true);
        });
    }

    @Override
    protected void onDestroy() {
        if (web != null) web.destroy();
        super.onDestroy();
    }

    // ------------------------------------------------------------- widget buttons (Sell / Sales)

    private static String viewFrom(Intent i) {
        if (i == null) return null;
        String v = i.getStringExtra("view");
        return ("sell".equals(v) || "sales".equals(v)) ? v : null;
    }

    private void runPendingView() {
        if (!pageReady || pendingView == null) return;
        final String v = pendingView;
        pendingView = null;
        web.postDelayed(() -> web.evaluateJavascript("window.fxGo && fxGo('" + v + "')", null), 300);
    }

    // ------------------------------------------------------------- other apps

    void openExternal(String url) {
        try {
            Intent i;
            if (url.startsWith("intent:")) {
                i = Intent.parseUri(url, Intent.URI_INTENT_SCHEME);
                i.addCategory(Intent.CATEGORY_BROWSABLE);
                i.setComponent(null);
                i.setSelector(null);
                try {
                    startActivity(i);
                    return;
                } catch (ActivityNotFoundException e) {
                    String fallback = i.getStringExtra("browser_fallback_url");
                    if (fallback != null) {
                        startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(fallback)));
                    } else if (i.getPackage() != null) {
                        startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=" + i.getPackage())));
                    }
                    return;
                }
            }
            i = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(i);
        } catch (Exception e) {
            // nothing can open it: stay in the POS
        }
    }

    // ------------------------------------------------------------- printing

    void printPage(WebView w, String name) {
        PrintManager pm = (PrintManager) getSystemService(Context.PRINT_SERVICE);
        if (pm == null) return;
        pm.print(name, w.createPrintDocumentAdapter(name), new PrintAttributes.Builder().build());
    }

    @SuppressLint("SetJavaScriptEnabled")
    void printUrl(String url) {
        Uri u = Uri.parse(url);
        if (!HOST.equalsIgnoreCase(u.getHost())) return;
        printWeb = new WebView(this);
        printWeb.getSettings().setJavaScriptEnabled(true);
        printWeb.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageFinished(WebView view, String done) {
                printPage(view, "FOMAXO receipt");
            }
        });
        printWeb.loadUrl(url);
    }

    // ------------------------------------------------------------- what the POS page can ask the app

    class Bridge {
        @JavascriptInterface
        public void setLogin(String token, String name, String role) {
            WidgetData.saveLogin(MainActivity.this, token, name, role);
            WidgetData.refreshInBackground(MainActivity.this);
        }

        @JavascriptInterface
        public void refreshWidget() {
            WidgetData.refreshInBackground(MainActivity.this);
        }

        @JavascriptInterface
        public void openExternal(String url) {
            runOnUiThread(() -> MainActivity.this.openExternal(url));
        }

        @JavascriptInterface
        public void print() {
            runOnUiThread(() -> printPage(web, "FOMAXO receipt"));
        }

        @JavascriptInterface
        public void printUrl(String url) {
            runOnUiThread(() -> MainActivity.this.printUrl(url));
        }

        @JavascriptInterface
        public String version() {
            return BuildConfigInfo.version(MainActivity.this);
        }
    }

    private static String offlinePage() {
        return "<!doctype html><html><head><meta name=viewport content='width=device-width,initial-scale=1'>"
            + "<style>body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0a0b;"
            + "color:#f4f1ea;font-family:sans-serif;text-align:center}h1{color:#c9a24b;letter-spacing:.3em;font-size:22px}"
            + "p{color:#9a968c;margin:8px 24px 24px}a{display:inline-block;background:#c9a24b;color:#0a0a0b;padding:14px 28px;"
            + "border-radius:12px;text-decoration:none;font-weight:700}</style></head><body><div><h1>FOMAXO</h1>"
            + "<p>No internet. The first start needs internet once; after that the POS also works offline.</p>"
            + "<a href='" + HOME + "'>Try again</a></div></body></html>";
    }
}
