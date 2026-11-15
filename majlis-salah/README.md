# Majlis Salah: live content

`content.json` is read by the Majlis Salah app (every 6 hours when online).

- `hijriOffset`: days to add to the calculated Hijri date, per time zone (e.g. `"Asia/Dubai": 1` if the UAE announces Ramadan one day later than calculated). `default` applies to every other country.
- `notice`: a message shown once to every user. Change `id` to show a new one.
- `hadith`, `duas`, `intentions`: extra content added to the app without an update.
