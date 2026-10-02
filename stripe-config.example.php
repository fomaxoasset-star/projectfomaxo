<?php
/* FOMAXO — Stripe settings (TEMPLATE)
   Do NOT put your real key in GitHub.
   On Hostinger: File Manager → go ONE LEVEL ABOVE public_html
   (domains/fomaxo.com/) → create a file named  stripe-config.php
   → paste this content → put your secret key between the quotes → Save.
   Test key: sk_test_...   Live key: sk_live_...  (Stripe → Developers → API keys) */
return [
  'secret_key' => 'PASTE_YOUR_STRIPE_SECRET_KEY_HERE',
  'currency'   => 'aed',
];
