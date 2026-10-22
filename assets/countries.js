/* Countries for the customer phone box: ISO code, dialling code, name. UAE first. */
window.FX_COUNTRIES = (function () {
  var raw = 'AF93Afghanistan|AL355Albania|DZ213Algeria|AS1684American Samoa|AD376Andorra|AO244Angola|AI1264Anguilla|AG1268Antigua and Barbuda|AR54Argentina|AM374Armenia|AW297Aruba|AU61Australia|AT43Austria|AZ994Azerbaijan|' +
    'BS1242Bahamas|BH973Bahrain|BD880Bangladesh|BB1246Barbados|BY375Belarus|BE32Belgium|BZ501Belize|BJ229Benin|BM1441Bermuda|BT975Bhutan|BO591Bolivia|BA387Bosnia and Herzegovina|BW267Botswana|BR55Brazil|BN673Brunei|BG359Bulgaria|BF226Burkina Faso|BI257Burundi|' +
    'KH855Cambodia|CM237Cameroon|CA1Canada|CV238Cape Verde|KY1345Cayman Islands|CF236Central African Republic|TD235Chad|CL56Chile|CN86China|CO57Colombia|KM269Comoros|CG242Congo|CD243Congo (DR)|CK682Cook Islands|CR506Costa Rica|CI225Côte d’Ivoire|HR385Croatia|CU53Cuba|CW599Curaçao|CY357Cyprus|CZ420Czechia|' +
    'DK45Denmark|DJ253Djibouti|DM1767Dominica|DO1809Dominican Republic|EC593Ecuador|EG20Egypt|SV503El Salvador|GQ240Equatorial Guinea|ER291Eritrea|EE372Estonia|SZ268Eswatini|ET251Ethiopia|' +
    'FJ679Fiji|FI358Finland|FR33France|GF594French Guiana|PF689French Polynesia|GA241Gabon|GM220Gambia|GE995Georgia|DE49Germany|GH233Ghana|GI350Gibraltar|GR30Greece|GL299Greenland|GD1473Grenada|GP590Guadeloupe|GU1671Guam|GT502Guatemala|GN224Guinea|GW245Guinea-Bissau|GY592Guyana|' +
    'HT509Haiti|HN504Honduras|HK852Hong Kong|HU36Hungary|IS354Iceland|IN91India|ID62Indonesia|IR98Iran|IQ964Iraq|IE353Ireland|IL972Israel|IT39Italy|JM1876Jamaica|JP81Japan|JO962Jordan|' +
    'KZ7Kazakhstan|KE254Kenya|KI686Kiribati|XK383Kosovo|KW965Kuwait|KG996Kyrgyzstan|LA856Laos|LV371Latvia|LB961Lebanon|LS266Lesotho|LR231Liberia|LY218Libya|LI423Liechtenstein|LT370Lithuania|LU352Luxembourg|' +
    'MO853Macau|MG261Madagascar|MW265Malawi|MY60Malaysia|MV960Maldives|ML223Mali|MT356Malta|MH692Marshall Islands|MQ596Martinique|MR222Mauritania|MU230Mauritius|YT262Mayotte|MX52Mexico|FM691Micronesia|MD373Moldova|MC377Monaco|MN976Mongolia|ME382Montenegro|MS1664Montserrat|MA212Morocco|MZ258Mozambique|MM95Myanmar|' +
    'NA264Namibia|NR674Nauru|NP977Nepal|NL31Netherlands|NC687New Caledonia|NZ64New Zealand|NI505Nicaragua|NE227Niger|NG234Nigeria|KP850North Korea|MK389North Macedonia|NO47Norway|OM968Oman|' +
    'PK92Pakistan|PW680Palau|PS970Palestine|PA507Panama|PG675Papua New Guinea|PY595Paraguay|PE51Peru|PH63Philippines|PL48Poland|PT351Portugal|PR1787Puerto Rico|QA974Qatar|RE262Réunion|RO40Romania|RU7Russia|RW250Rwanda|' +
    'KN1869Saint Kitts and Nevis|LC1758Saint Lucia|VC1784Saint Vincent|WS685Samoa|SM378San Marino|ST239São Tomé and Príncipe|SA966Saudi Arabia|SN221Senegal|RS381Serbia|SC248Seychelles|SL232Sierra Leone|SG65Singapore|SK421Slovakia|SI386Slovenia|SB677Solomon Islands|SO252Somalia|ZA27South Africa|KR82South Korea|SS211South Sudan|ES34Spain|LK94Sri Lanka|SD249Sudan|SR597Suriname|SE46Sweden|CH41Switzerland|SY963Syria|' +
    'TW886Taiwan|TJ992Tajikistan|TZ255Tanzania|TH66Thailand|TL670Timor-Leste|TG228Togo|TO676Tonga|TT1868Trinidad and Tobago|TN216Tunisia|TR90Türkiye|TM993Turkmenistan|TC1649Turks and Caicos|TV688Tuvalu|' +
    'UG256Uganda|UA380Ukraine|AE971United Arab Emirates|GB44United Kingdom|US1United States|UY598Uruguay|UZ998Uzbekistan|VU678Vanuatu|VA379Vatican City|VE58Venezuela|VN84Vietnam|VG1284Virgin Islands (UK)|VI1340Virgin Islands (US)|YE967Yemen|ZM260Zambia|ZW263Zimbabwe';
  var all = raw.split('|').map(function (s) {
    var m = /^([A-Z]{2})(\d+)(.+)$/.exec(s);
    return { iso: m[1], dial: m[2], name: m[3] };
  });
  var by = {};
  all.forEach(function (c) { by[c.iso] = c; });
  // Shown first: UAE, the Gulf, then the countries most store customers come from.
  var top = ['AE', 'SA', 'OM', 'QA', 'KW', 'BH', 'IN', 'PK', 'BD', 'PH', 'EG', 'LK', 'NP', 'JO', 'LB', 'SY', 'IR', 'GB', 'US', 'RU'].map(function (k) { return by[k]; });
  function flag(iso) { return iso.replace(/./g, function (ch) { return String.fromCodePoint(127397 + ch.charCodeAt(0)); }); }
  // Longest dialling code first, so +1876 (Jamaica) wins over +1, and +971 over +97x.
  var byDial = all.slice().sort(function (a, b) { return b.dial.length - a.dial.length; });
  var prefer = { '1': 'US', '7': 'RU', '44': 'GB', '262': 'RE', '590': 'GP' };
  return {
    all: all, top: top, by: by, flag: flag,
    /** "+971501234567" -> { iso: 'AE', national: '501234567' } */
    split: function (full) {
      var d = String(full || '').replace(/\D/g, '');
      if (!/^\+|^00/.test(String(full || '').trim()) && !/^971/.test(d)) return null;
      if (d.indexOf('00') === 0) d = d.slice(2);
      for (var i = 0; i < byDial.length; i++) {
        var c = byDial[i];
        if (d.indexOf(c.dial) === 0) {
          return { iso: prefer[c.dial] || c.iso, national: d.slice(c.dial.length) };
        }
      }
      return null;
    }
  };
})();
