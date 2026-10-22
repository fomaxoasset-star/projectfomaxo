# FOMAXO Store System

A store app for FOMAXO staff. It runs on your Hostinger plan and opens in any phone, tablet or laptop browser.

- **Sell:** tap products, add a customer and discount, take payment, give a receipt
- **Payments:** Cash, Tap to Pay (taken in the Ziina app), Ziina QR (confirms by itself), or another card machine
- **Stock:** in stock / running low / out of stock, stock goes down with every sale, restock and stock-count tools, full history per product
- **Receipts:** print (80mm receipt printer or A4/PDF), WhatsApp, email or link; shows TRN and VAT when set
- **FOMAXO Elite:** yearly spend per customer phone number, tier printed on the receipt
- **Staff:** separate logins; managers also get Reports, Staff and stock editing
- **Offline:** cash, Tap to Pay and card-machine sales keep working without internet and upload automatically later

---

## Set it up on Hostinger (about 15 minutes)

### 1. Make a subdomain
hPanel → **Websites** → fomaxo.com → **Domains → Subdomains** → create `pos` (gives **pos.fomaxo.com**).
Note its folder, usually `public_html/pos`.

### 2. Make a database
hPanel → **Databases → MySQL Databases** → create a new database and user.
Write down the **database name**, **username** and **password** (they look like `u123456789_fomaxopos`).

### 3. Upload the app
hPanel → **Files → File Manager** → open the subdomain folder → **Upload** the zip → right-click → **Extract**.
Make sure `index.html`, `api.php` and the `assets` folder sit directly in the subdomain folder (not inside another folder).

### 4. Fill in the settings
In File Manager, copy `config.sample.php` and name the copy `config.php`. Edit `config.php`:
- database name, user, password (host stays `localhost`)
- `base_url` → `https://pos.fomaxo.com`
- store address, phone, and your **TRN** if you are VAT-registered
- leave `ziina_mode` as `demo` for now

Make sure SSL is on for the subdomain (hPanel → **Security → SSL**). The app needs https.

### 5. Run setup
Open **https://pos.fomaxo.com/install.php**, create your manager login, then **delete `install.php`** in File Manager.

### 6. Add your products
Log in → **Stock**. The FOMAXO fragrance line was added switched off. For each product press **Edit**, set the size and price, tick **On sale**, then **Adjust → Received new stock** to enter how many you have.

### 7. Put it on the store devices
Open pos.fomaxo.com on each phone/tablet and log in:
- **iPhone/iPad:** Share → **Add to Home Screen**
- **Android:** menu (⋮) → **Install app** / **Add to Home screen**

It then opens full-screen like a normal app, and keeps working offline.

---

## Ziina

### Ziina QR (online, confirms automatically)
1. Get your API key from Ziina (business account → Developers / API). Ask Ziina support if you can't see it.
2. In `config.php`: put the key in `ziina_token` and set `ziina_mode` to `test`.
3. Make a test sale with **Ziina QR** and pay with a Ziina test card. Nothing is charged.
4. When it works, set `ziina_mode` to `live`.

Minimum Ziina payment is AED 2.

### Tap to Pay
Tap to Pay runs inside the Ziina app on the store phone. Staff press **Tap to Pay** in the store system, copy the amount, take the payment in Ziina, then press **Payment received**. Ziina does not currently offer a way for other apps to start Tap to Pay directly.

### Refunds
Voiding a sale (Sales → open the sale → **Void sale**, managers only) fixes the records and can put the items back into stock. The money itself is refunded from the Ziina app.

---

## Good to know
- **Light / dark mode:** tap the sun/moon button at the top, or choose **Auto / Light / Dark** in the name menu. Auto follows the device setting. Each device remembers its own choice.
- **Brand font:** upload your FOMAXO REAL font into `assets/` named `fomaxo-real.woff2` (or `fomaxo-real.ttf`). It is used for headings only. Until then a classic serif is used.
- **Offline:** logging in, Ziina QR, reports and stock editing need internet. Selling with Cash, Tap to Pay and Card machine does not. Offline sales show in **Sales → Waiting to upload** and upload by themselves.
- **Prices include VAT** by default (`prices_include_vat` in config.php). Receipts show the VAT part.
- **Staff discount limit** is 10% (change `staff_max_discount`). Managers have no limit.
- **Receipt printer:** any 80mm printer that works with the device's normal print dialog works (for example Bluetooth/Wi-Fi models from Epson or Star). Press **Print** on the receipt.
- **Backups:** Hostinger backs up databases automatically. You can also download one from hPanel → Databases.
- **Safety:** keep `config.php` private. Staff who leave: Staff → Edit → untick **Can log in** (signs them out everywhere).
