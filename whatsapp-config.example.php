<?php
/* Copy this file to domains/fomaxo.com/whatsapp-config.php (ONE LEVEL ABOVE public_html, never on GitHub)
   and fill in the details from Meta (developers.facebook.com → your app → WhatsApp → API Setup). */
return [
  'access_token'    => 'PASTE_PERMANENT_ACCESS_TOKEN_HERE',   // System user token with whatsapp_business_messaging permission
  'phone_number_id' => 'PASTE_PHONE_NUMBER_ID_HERE',          // the ID of FOMAXO's WhatsApp business number (not the number itself)
  'template'        => 'review_request',                      // approved template name; body uses {{1}} first name, {{2}} perfumes, {{3}} review link
  'language'        => 'en',                                  // template language code exactly as approved (en, en_US …)
  'send_after_days' => 3,                                     // days after the order
  'from_hour'       => 11, 'to_hour' => 20,                   // only send between these hours, Dubai time
];
