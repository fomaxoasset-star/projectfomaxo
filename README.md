# FOMAXO Store — Android app

The POS (pos.fomaxo.com) as an Android app, with a home-screen widget showing today's sales.

* `android/` — the app (WebView of pos.fomaxo.com, works offline through the POS's own saved copy) and the widget.
* `.github/workflows/android.yml` — GitHub builds an unsigned APK on every push to this branch and puts it on the `android-build` branch.
* The APK is signed with FOMAXO's private key outside this repo. Keep that key safe: every update must be signed with the same key.
