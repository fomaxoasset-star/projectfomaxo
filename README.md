# FOMAXO — fomaxo.com

Luxury house founded in Dubai. Website source for fomaxo.com. Every merge to `main` goes live on Hostinger.

- `index.html` – the whole shop (pages, styles, scripts); `assets/i18n-ar.js` – the Arabic text
- `assets/img/` – photos (.webp; `t/` and `s/` hold smaller copies)
- `admin/index.php` – the back office at fomaxo.com/admin (products, stock, orders, reviews, coupons, offers, settings)
- `checkout.php` – cash on delivery orders · `ziina.php` – card / Apple Pay / Google Pay through Ziina (prices checked on the server)
- `store-lib.php`, `orders-lib.php` and the other `-lib.php` files – shared server code (never opened from the browser)
- `.htaccess` – forces https, product addresses, keeps private files private, caches photos
- Keys and passwords live in config files **one level above public_html**, never in GitHub
- `FOMAXO-Claude-Prompt.md` – brand and design rules for future changes with Claude
