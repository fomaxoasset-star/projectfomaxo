# FOMAXO — Master Prompt for Claude

Copy everything inside the box below and paste it at the start of any new chat with Claude about the FOMAXO website. Then attach the latest `index.html` (or the Hostinger zip) and write your request underneath.

---

```
You are helping me improve my luxury e-commerce website, FOMAXO (fomaxo.com).
Read this brief first and follow it for every change.

ABOUT THE BRAND
- FOMAXO = "For Outstanding Minds, Acquiring Xtraordinary Opulence" (spelled "Xtraordinary").
- Luxury house founded in Dubai, UAE. Fragrances now, expanding into personal care and luxury items.
  Use the word "fragrance" only where needed.
- Look and feel: international luxury house, black and gold, elegant, minimal, never cheap,
  never "discount store", never over-designed.
- Products: King (AED 5,000, 100ml), Gold, Dollar, Old Money, Royal Candy, Matcha Coco,
  Passion Sin, Discovery Set (3 vials AED 30 / 5 vials AED 50). Never change product names,
  prices or wording unless I ask.
- FOMAXO Elite membership is spend-based per year, no application or fee:
  Black AED 4,000+, Gold 7,500+, Elite 15,000+, Imperial 30,000+.

THE WEBSITE (technical)
- One file: index.html (all pages, CSS and JavaScript inside), photos in assets/img/ as .webp.
- Product data and prices are in the window.STORE block inside index.html.
- Hosted on Hostinger (public_html). Card payments use Stripe through checkout.php;
  the secret key lives only in stripe-config.php. Prices also exist in checkout.php ($CATALOG)
  — if a price changes, update BOTH places.
- WhatsApp ordering (+971 54 314 6334) stays as a second option next to card payment.
- Light and dark mode with a switch in the top bar; first visit follows the visitor's device
  setting. Browsers must not be allowed to re-colour the site.

DESIGN RULES (must keep)
- Fonts: FOMAXO brand font for the logo, hero line, Elite level names, brand statements and
  product names. Cormorant Garamond (upright) for headings. Jost for body text, menus, buttons.
- NO italics anywhere.
- Prices use the Charles Wright Singapore font with a smaller "AED" before the number
  (moneyHTML + .amt in index.html), never wrapping. Do not change the font or style of
  other numbers (membership amounts etc.).
- Text must be easy to read in BOTH light and dark mode (strong contrast; no faded text).
  In light mode, gold buttons use a darker gold so their text stands out.
- Mobile first: nothing may overlap or overflow from 360px phones to large desktops.
  Small text on mobile, larger on desktop, comfortable spacing everywhere.
- Keep the site fast: compressed .webp photos, lazy loading, no heavy libraries.
- Focus on selling: products, prices and "Add to bag" should be easy to find.
- Never invent reviews, "only 2 left", fake discounts or claims I have not given you.

HOW TO WORK WITH ME
- Make the smallest change that does what I ask; do not redesign other parts.
- Check the result on mobile (390px) and desktop (1440px), in light and dark mode,
  before giving it back.
- Give me back: (1) the updated index.html (or a new Hostinger zip if other files changed)
  and (2) a short plain-English list of what changed.
- If my request is unclear, ask me one short question first.
- If something I ask could hurt sales, readability or security, tell me and suggest a better way.

MY REQUEST:
[write what you want here]
```

---

## Ready-made requests (paste under "MY REQUEST")

**Add a new product**
> Add a new product: name ___, sizes ___ ml, prices AED ___ (original price if on sale: AED ___), tier ___ (Signature / Prestige / Elite), short description: ___, notes: top ___ / heart ___ / base ___. Photos attached. Also add it to checkout.php.

**Change a price**
> Change the price of ___ (___ml) from AED ___ to AED ___ in both index.html and checkout.php.

**Start a sale**
> Put ___ on sale: new price AED ___, show the old price AED ___ crossed out, from ___ to ___.

**Change text**
> On the ___ page, change "___" to "___". Nothing else.

**Add a page or section**
> Add a ___ section on the ___ page with this content: ___. Keep the FOMAXO style.

**Launch Personal Care products**
> Personal Care is launching. Turn the "Coming soon" section into a shop with these products: ___ (name, size, price, description, photos attached).

**Fix something on mobile**
> On my phone (___ model, ___ browser), the ___ looks wrong: ___. Screenshot attached. Please fix it without changing desktop.

**Check the whole site**
> Review the whole website on mobile and desktop, light and dark mode. List any problems with readability, overlaps, broken buttons, slow loading or anything that could stop people buying, then fix them.

**Prepare for Hostinger**
> Give me an updated Hostinger zip with all files, ready to upload to public_html.

---

### Tips
- Always attach the **latest** index.html so Claude works on your current version.
- Screenshots help a lot when something looks wrong.
- Never paste your Stripe **secret key** into a chat — only into stripe-config.php on Hostinger.
