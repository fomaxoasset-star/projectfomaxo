<?php
/* FOMAXO — where a visitor is, from their IP address, looked up in the files in geo/ (DB-IP Lite, https://db-ip.com, CC BY 4.0).
   Gives a country code (AE, IN …) and, for the UAE, the emirate. Approximate: phone networks often show Dubai or Abu Dhabi. */
const FOMAXO_EMIRATES = [1 => 'Dubai', 2 => 'Abu Dhabi', 3 => 'Sharjah', 4 => 'Ajman', 5 => 'Ras Al Khaimah', 6 => 'Fujairah', 7 => 'Umm Al Quwain'];

/* the visitor's address: the web server's own one, or the first public one a proxy passed on */
function fomaxo_geo_ip() {
  $pub = fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : null;
  if ($ip = $pub(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')))) return $ip;
  foreach (explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $ip) if ($ip = $pub(trim($ip))) return $ip;
  return null;
}

/* [country code or null, emirate or null] */
function fomaxo_geo($ip) {
  $bin = @inet_pton((string)$ip);
  if ($bin === false) return [null, null];
  if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $bin = substr($bin, 12);   // ::ffff:1.2.3.4
  $v4 = strlen($bin) === 4; $key = $v4 ? $bin : substr($bin, 0, 8); $w = strlen($key);
  $cc = fomaxo_geo_find(__DIR__ . '/geo/country' . ($v4 ? 4 : 6) . '.bin', $key, $w, $w + 2);
  $cc = $cc === null ? null : substr($cc, $w, 2);
  if ($cc === null || !preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'ZZ') return [null, null];
  $em = null;
  if ($cc === 'AE' && ($r = fomaxo_geo_find(__DIR__ . '/geo/ae' . ($v4 ? 4 : 6) . '.bin', $key, $w, 2 * $w + 1)) !== null
      && strcmp($key, substr($r, $w, $w)) <= 0) $em = FOMAXO_EMIRATES[ord($r[2 * $w])] ?? null;
  return [$cc, $em];
}

/* the last record in a sorted file whose start is <= key (records start with the big-endian start address) */
function fomaxo_geo_find($file, $key, $w, $size) {
  $f = @fopen($file, 'rb');
  if (!$f) return null;
  $lo = 0; $hi = intdiv((int)filesize($file), $size) - 1; $best = null;
  while ($lo <= $hi) {
    $mid = ($lo + $hi) >> 1; fseek($f, $mid * $size); $rec = fread($f, $size);
    if (strcmp(substr($rec, 0, $w), $key) <= 0) { $best = $rec; $lo = $mid + 1; } else $hi = $mid - 1;
  }
  fclose($f);
  return $best;
}

/* English country name from a two-letter code */
function fomaxo_country_name($cc) {
  static $n = ['AC' => 'Ascension Island', 'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan', 'AG' => 'Antigua & Barbuda', 'AI' => 'Anguilla', 'AL' => 'Albania', 'AM' => 'Armenia', 'AN' => 'Curaçao', 'AO' => 'Angola', 'AQ' => 'Antarctica', 'AR' => 'Argentina', 'AS' => 'American Samoa', 'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba', 'AX' => 'Åland Islands', 'AZ' => 'Azerbaijan', 'BA' => 'Bosnia & Herzegovina', 'BB' => 'Barbados', 'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BF' => 'Burkina Faso', 'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BI' => 'Burundi', 'BJ' => 'Benin', 'BL' => 'St. Barthélemy', 'BM' => 'Bermuda', 'BN' => 'Brunei', 'BO' => 'Bolivia', 'BQ' => 'Caribbean Netherlands', 'BR' => 'Brazil', 'BS' => 'Bahamas', 'BT' => 'Bhutan', 'BU' => 'Myanmar (Burma)', 'BV' => 'Bouvet Island', 'BW' => 'Botswana', 'BY' => 'Belarus', 'BZ' => 'Belize', 'CA' => 'Canada', 'CC' => 'Cocos (Keeling) Islands', 'CD' => 'Congo - Kinshasa', 'CF' => 'Central African Republic', 'CG' => 'Congo - Brazzaville', 'CH' => 'Switzerland', 'CI' => 'Côte d’Ivoire', 'CK' => 'Cook Islands', 'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China', 'CO' => 'Colombia', 'CP' => 'Clipperton Island', 'CQ' => 'Sark', 'CR' => 'Costa Rica', 'CS' => 'Serbia', 'CU' => 'Cuba', 'CV' => 'Cape Verde', 'CW' => 'Curaçao', 'CX' => 'Christmas Island', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DD' => 'Germany', 'DE' => 'Germany', 'DG' => 'Diego Garcia', 'DJ' => 'Djibouti', 'DK' => 'Denmark', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'DY' => 'Benin', 'DZ' => 'Algeria', 'EA' => 'Ceuta & Melilla', 'EC' => 'Ecuador', 'EE' => 'Estonia', 'EG' => 'Egypt', 'EH' => 'Western Sahara', 'ER' => 'Eritrea', 'ES' => 'Spain', 'ET' => 'Ethiopia', 'FI' => 'Finland', 'FJ' => 'Fiji', 'FK' => 'Falkland Islands', 'FM' => 'Micronesia', 'FO' => 'Faroe Islands', 'FR' => 'France', 'FX' => 'France', 'GA' => 'Gabon', 'GB' => 'United Kingdom', 'GD' => 'Grenada', 'GE' => 'Georgia', 'GF' => 'French Guiana', 'GG' => 'Guernsey', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GL' => 'Greenland', 'GM' => 'Gambia', 'GN' => 'Guinea', 'GP' => 'Guadeloupe', 'GQ' => 'Equatorial Guinea', 'GR' => 'Greece', 'GS' => 'South Georgia & South Sandwich Islands', 'GT' => 'Guatemala', 'GU' => 'Guam', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HK' => 'Hong Kong SAR China', 'HM' => 'Heard & McDonald Islands', 'HN' => 'Honduras', 'HR' => 'Croatia', 'HT' => 'Haiti', 'HU' => 'Hungary', 'HV' => 'Burkina Faso', 'IC' => 'Canary Islands', 'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man', 'IN' => 'India', 'IO' => 'British Indian Ocean Territory', 'IQ' => 'Iraq', 'IR' => 'Iran', 'IS' => 'Iceland', 'IT' => 'Italy', 'JE' => 'Jersey', 'JM' => 'Jamaica', 'JO' => 'Jordan', 'JP' => 'Japan', 'KE' => 'Kenya', 'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia', 'KI' => 'Kiribati', 'KM' => 'Comoros', 'KN' => 'St. Kitts & Nevis', 'KP' => 'North Korea', 'KR' => 'South Korea', 'KW' => 'Kuwait', 'KY' => 'Cayman Islands', 'KZ' => 'Kazakhstan', 'LA' => 'Laos', 'LB' => 'Lebanon', 'LC' => 'St. Lucia', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka', 'LR' => 'Liberia', 'LS' => 'Lesotho', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia', 'LY' => 'Libya', 'MA' => 'Morocco', 'MC' => 'Monaco', 'MD' => 'Moldova', 'ME' => 'Montenegro', 'MF' => 'St. Martin', 'MG' => 'Madagascar', 'MH' => 'Marshall Islands', 'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar (Burma)', 'MN' => 'Mongolia', 'MO' => 'Macao SAR China', 'MP' => 'Northern Mariana Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius', 'MV' => 'Maldives', 'MW' => 'Malawi', 'MX' => 'Mexico', 'MY' => 'Malaysia', 'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NC' => 'New Caledonia', 'NE' => 'Niger', 'NF' => 'Norfolk Island', 'NG' => 'Nigeria', 'NH' => 'Vanuatu', 'NI' => 'Nicaragua', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal', 'NR' => 'Nauru', 'NU' => 'Niue', 'NZ' => 'New Zealand', 'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'French Polynesia', 'PG' => 'Papua New Guinea', 'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'PM' => 'St. Pierre & Miquelon', 'PN' => 'Pitcairn Islands', 'PR' => 'Puerto Rico', 'PS' => 'Palestinian Territories', 'PT' => 'Portugal', 'PW' => 'Palau', 'PY' => 'Paraguay', 'QA' => 'Qatar', 'RE' => 'Réunion', 'RH' => 'Zimbabwe', 'RO' => 'Romania', 'RS' => 'Serbia', 'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia', 'SB' => 'Solomon Islands', 'SC' => 'Seychelles', 'SD' => 'Sudan', 'SE' => 'Sweden', 'SG' => 'Singapore', 'SH' => 'St. Helena', 'SI' => 'Slovenia', 'SJ' => 'Svalbard & Jan Mayen', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone', 'SM' => 'San Marino', 'SN' => 'Senegal', 'SO' => 'Somalia', 'SR' => 'Suriname', 'SS' => 'South Sudan', 'ST' => 'São Tomé & Príncipe', 'SU' => 'Russia', 'SV' => 'El Salvador', 'SX' => 'Sint Maarten', 'SY' => 'Syria', 'SZ' => 'Eswatini', 'TA' => 'Tristan da Cunha', 'TC' => 'Turks & Caicos Islands', 'TD' => 'Chad', 'TF' => 'French Southern Territories', 'TG' => 'Togo', 'TH' => 'Thailand', 'TJ' => 'Tajikistan', 'TK' => 'Tokelau', 'TL' => 'Timor-Leste', 'TM' => 'Turkmenistan', 'TN' => 'Tunisia', 'TO' => 'Tonga', 'TP' => 'Timor-Leste', 'TR' => 'Türkiye', 'TT' => 'Trinidad & Tobago', 'TV' => 'Tuvalu', 'TW' => 'Taiwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraine', 'UG' => 'Uganda', 'UK' => 'United Kingdom', 'UM' => 'U.S. Outlying Islands', 'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VA' => 'Vatican City', 'VC' => 'St. Vincent & Grenadines', 'VD' => 'Vietnam', 'VE' => 'Venezuela', 'VG' => 'British Virgin Islands', 'VI' => 'U.S. Virgin Islands', 'VN' => 'Vietnam', 'VU' => 'Vanuatu', 'WF' => 'Wallis & Futuna', 'WS' => 'Samoa', 'XA' => 'Pseudo-Accents', 'XB' => 'Pseudo-Bidi', 'XK' => 'Kosovo', 'YD' => 'Yemen', 'YE' => 'Yemen', 'YT' => 'Mayotte', 'YU' => 'Serbia', 'ZA' => 'South Africa', 'ZM' => 'Zambia', 'ZR' => 'Congo - Kinshasa', 'ZW' => 'Zimbabwe'];
  return $n[$cc] ?? $cc;
}
/* the flag emoji for a two-letter code */
function fomaxo_flag($cc) {
  return preg_match('/^[A-Z]{2}$/', $cc) ? mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65) : '';
}
