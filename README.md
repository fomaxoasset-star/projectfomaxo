# FOMAXO — fomaxo.com

Luxury house founded in Dubai. Website source for fomaxo.com.

- `index.html` – the whole website (pages, styles, scripts)
- `assets/img/` – product photos (.webp)
- `checkout.php` – Stripe card checkout (prices checked server-side)
- `stripe-config.example.php` – template; the real `stripe-config.php` with your key lives on Hostinger **one level above public_html**, never in GitHub
- `.htaccess` – forces https, protects the key file, caches photos
- `SETUP-STEPS.txt` – Hostinger + Stripe setup guide
- `FOMAXO-Claude-Prompt.md` – master prompt for future changes with Claude
