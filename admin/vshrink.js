/* Upload from your phone: a big or iPhone (.mov) video is made smaller in the browser first, then uploaded as usual.
   Fast way: the video is re-encoded straight from the file (admin/mediabunny.js, WebCodecs), several times faster than playing it,
   Every video over 5 MB comes out at about 5 MB or less, sharp 720p (540p only for long videos). The home page row never loads it: it plays the small preview, the full video loads only in the player.
   Browsers without it re-record the video while it plays (720p). Neither works = upload as it is. */
/* a cover photo picked from the video itself: 5 moments are looked at (not the first second, often black or a fade) and the clearest,
   best-lit one wins (sharp edges, not too dark, not washed out). Gives a JPEG, or null when the browser can't read the video */
window.fxCover = function (src) {
  return new Promise(function (ok) {
    var v = document.createElement('video'), u = typeof src === 'string' ? src : URL.createObjectURL(src), done = false, best = null, bs = -1, ts = [], k = 0;
    var end = function () { if (done) return; done = true; clearTimeout(t); v.onseeked = v.onloadeddata = v.onerror = null; v.removeAttribute('src'); v.load(); if (u !== src) URL.revokeObjectURL(u);
      if (!best || bs < 2) return ok(null); best.toBlob(function (b) { ok(b && b.size ? b : null); }, 'image/jpeg', .88); };
    var t = setTimeout(end, 20000);
    var look = function () {
      var w = v.videoWidth, h = v.videoHeight; if (!w || !h) return end();
      var z = Math.min(1, 1280 / Math.max(w, h)), c = document.createElement('canvas'); c.width = Math.round(w * z); c.height = Math.round(h * z);
      var sm = document.createElement('canvas'), q = 64 / Math.max(w, h); sm.width = Math.max(8, Math.round(w * q)); sm.height = Math.max(8, Math.round(h * q));
      try { c.getContext('2d').drawImage(v, 0, 0, c.width, c.height); var g = sm.getContext('2d'); g.drawImage(c, 0, 0, sm.width, sm.height); var d = g.getImageData(0, 0, sm.width, sm.height).data; } catch (e) { return end(); }
      var n = sm.width * sm.height, Y = new Float32Array(n), m = 0, s = 0, e = 0;
      for (var i = 0; i < n; i++) { Y[i] = .299 * d[i * 4] + .587 * d[i * 4 + 1] + .114 * d[i * 4 + 2]; m += Y[i]; } m /= n;
      for (i = 0; i < n; i++) { s += (Y[i] - m) * (Y[i] - m); if ((i + 1) % sm.width) e += Math.abs(Y[i + 1] - Y[i]); if (i + sm.width < n) e += Math.abs(Y[i + sm.width] - Y[i]); }
      s = Math.sqrt(s / n); e /= n;
      var sc = e * (s < 12 ? .3 : 1) * (m < 40 || m > 220 ? .2 : 1);   // a dark, blank or washed-out frame loses
      if (sc > bs) { bs = sc; best = c; }
      if (++k < ts.length) v.currentTime = ts[k]; else end();
    };
    v.muted = true; v.playsInline = true; v.preload = 'auto';
    v.onloadeddata = function () { var d = isFinite(v.duration) && v.duration > 0 ? v.duration : 4; ts = [.2, .35, .5, .65, .8].map(function (x) { return Math.max(.1, d * x); }); v.onseeked = look; v.currentTime = ts[0]; };
    v.onerror = end; v.src = u;
  });
};
/* pick the cover yourself: play or slide the video to the moment you like, then "Use this frame" (a JPEG of that exact frame) */
window.fxPick = function (src, done) {
  if (!document.getElementById('vpick-css')) { var st = document.createElement('style'); st.id = 'vpick-css';
    st.textContent = '.vpick-bg{position:fixed;inset:0;z-index:90;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:16px}'
      + '.vpick-box{background:var(--panel,#fff);color:inherit;border:1px solid var(--gold,#b8913a);border-radius:12px;padding:14px;width:100%;max-width:420px;display:flex;flex-direction:column;gap:10px;max-height:calc(100vh - 32px)}'
      + '.vpick-box h3{margin:0;font-size:16px}.vpick-box video{width:100%;max-height:58vh;background:#000;border-radius:8px;object-fit:contain}'
      + '.vpick-box input[type=range]{width:100%;accent-color:var(--gold,#b8913a);margin:0}.vpick-box p{margin:0;font-size:13px;color:var(--muted,#777)}'
      + '.vpick-btns{display:flex;gap:8px;justify-content:flex-end}.vpick-btns .btn{margin:0}';
    document.head.appendChild(st); }
  var u = '', gone = false, bg = document.createElement('div'); bg.className = 'vpick-bg';
  bg.innerHTML = '<div class="vpick-box" role="dialog" aria-modal="true" aria-label="Pick the cover"><h3>Pick the cover</h3><video playsinline muted controls preload="auto"></video>'
    + '<input type="range" min="0" max="1000" value="0" step="1" aria-label="Move through the video"><p>Play or slide to the moment you like, then tap Use this frame.</p>'
    + '<div class="vpick-btns"><button type="button" class="btn line sm" data-x>Cancel</button><button type="button" class="btn sm" data-ok disabled>Use this frame</button></div></div>';
  document.body.appendChild(bg);
  var v = bg.querySelector('video'), sl = bg.querySelector('input'), ok = bg.querySelector('[data-ok]'), msg = bg.querySelector('p');
  var close = function () { gone = true; v.pause(); v.removeAttribute('src'); v.load(); if (u) URL.revokeObjectURL(u); bg.remove(); removeEventListener('keydown', key); };
  var key = function (e) { if (e.key === 'Escape') close(); }; addEventListener('keydown', key);
  bg.querySelector('[data-x]').onclick = close; bg.onclick = function (e) { if (e.target === bg) close(); };
  v.onloadeddata = function () { ok.disabled = false; if (v.currentTime < .05 && v.duration > 1) v.currentTime = Math.min(1, v.duration / 4); };
  v.ontimeupdate = function () { if (v.duration) sl.value = Math.round(v.currentTime / v.duration * 1000); };
  sl.oninput = function () { v.pause(); if (v.duration) v.currentTime = sl.value / 1000 * v.duration; };
  v.onerror = function () { msg.textContent = 'This browser can\'t open this video. Chrome works best.'; };
  ok.onclick = function () {
    v.pause(); var w = v.videoWidth, h = v.videoHeight; if (!w || !h) return;
    var z = Math.min(1, 1280 / Math.max(w, h)), c = document.createElement('canvas'); c.width = Math.round(w * z); c.height = Math.round(h * z);
    try { c.getContext('2d').drawImage(v, 0, 0, c.width, c.height); } catch (e) { msg.textContent = 'This frame could not be saved. Please try again.'; return; }
    ok.disabled = true; ok.textContent = 'Saving…';
    c.toBlob(function (b) { if (!b) { ok.disabled = false; ok.textContent = 'Use this frame'; return; } close(); done(b); }, 'image/jpeg', .9);
  };
  /* the video is loaded whole first (a few MB, admin only), so sliding to any moment works on every server and browser */
  var go = function (b) { if (gone) return; u = URL.createObjectURL(b); v.src = u; };
  if (typeof src === 'string') { msg.textContent = 'Loading the video…';
    fetch(src, {credentials: 'same-origin'}).then(function (r) { return r.ok ? r.blob() : Promise.reject(); }).then(function (b) { msg.textContent = 'Play or slide to the moment you like, then tap Use this frame.'; go(b); }, function () { msg.textContent = 'The video could not be loaded. Please try again.'; });
  } else go(src);
};
(function () {
  var f = document.querySelector('form[data-vshrink]'); if (!f) return;
  var inp = f.querySelector('input[type=file][name=video]'), MB = 1048576, note = f.querySelector('.vsh'), btn = f.querySelector('.btn');
  var mime = ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm'].filter(function (m) { return window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m); })[0];
  var why = '', pct = -1, say = function (t) { note.hidden = false; note.querySelector('span').textContent = t; var m = /smaller… (\d+)%/.exec(t); if (m) pct = +m[1]; };
  var GOAL = 4.6 * MB * 8, AB = 96000;   // bits: every made-smaller video lands at about 5 MB or less (96 kbit/s sound)
  var rate = function (sec) { return Math.max(300000, Math.min(2500000, Math.floor(GOAL / Math.max(1, sec || 30)) - AB)); };   // video bits per second for that length
  var side = function (br) { return br < 350000 ? 720 : br < 700000 ? 960 : 1280; };   // longest side: fewer pixels when there are fewer bits, so it stays sharp instead of blocky
  /* fast: re-encode from the file itself, no playing; null = this browser can't, use the slow way */
  function fast(file, scale) {
    if (!window.VideoEncoder) { why = 'no VideoEncoder'; return Promise.resolve(null); }
    var M = null, conv = null, src = null;
    return import(new URL('mediabunny.js?v=1.59.1', location.href).href).then(function (m) {
      M = m; src = new M.Input({source: new M.BlobSource(file), formats: M.ALL_FORMATS});
      return Promise.all([src.getPrimaryVideoTrack(), src.computeDuration().catch(function () { return 0; }), src.getPrimaryAudioTrack().catch(function () { return null; })]);
    }).then(function (r) {
      var t = r[0], at = r[2], br = Math.max(120000, Math.round(rate(r[1]) * (scale || 1))); if (!t) { why = 'no video track'; return null; }
      var w = t.displayWidth, h = t.displayHeight, k = Math.min(1, side(br) / Math.max(w, h));
      w = Math.round(w * k / 2) * 2; h = Math.round(h * k / 2) * 2;
      return Promise.all([M.getFirstEncodableVideoCodec(['avc', 'hevc', 'vp9'], {width: w, height: h}), window.AudioEncoder ? M.getFirstEncodableAudioCodec(['aac', 'opus']).catch(function () { return null; }) : null]).then(function (cs) {
        if (!cs[0]) { why = 'no video encoder ' + w + 'x' + h; return null; }
        var au = cs[1] ? {codec: cs[1], bitrate: AB} : at ? {codec: at.codec} : undefined;   // no sound encoder (some Safari): the sound is copied as it is
        var out = new M.Output({format: new M.Mp4OutputFormat({fastStart: 'in-memory'}), target: new M.BufferTarget()});
        return M.Conversion.init({input: src, output: out, showWarnings: false,
          video: {width: w, height: h, fit: 'contain', codec: cs[0], bitrate: br, forceTranscode: true},
          audio: au}).then(function (c) {
          conv = stopNow = c; if (!c.isValid) { why = 'not valid ' + c.discardedTracks.map(function (d) { return d.reason; }).join(' '); return null; }
          if (at && c.discardedTracks.some(function (d) { return d.track.type === 'audio'; })) { why = 'sound would be lost'; return null; }
          c.onProgress = function (p) { say('Making the video smaller… ' + Math.min(99, Math.round(p * 100)) + '%. Keep this page open.'); };
          say('Making the video smaller… 0%. Keep this page open.');
          return c.execute().then(function () { var b = out.target.buffer; return b && b.byteLength ? new Blob([b], {type: 'video/mp4'}) : null; });
        });
      });
    }).catch(function (e) { why = 'error ' + String(e && (e.message || e.name) || e).slice(0, 60); try { conv && conv.cancel(); } catch (x) {} return null; });
  }
  /* sure way: FFmpeg itself, running inside this browser (admin/ffmpeg, ffmpeg.wasm 0.12). Slower, but it opens every video type
     (iPhone HEVC .mov, .mkv, .avi, .m4v…) on every browser and needs no playing, so no tap. Fetched only when the fast way can't */
  var inWasm = false, MAC_SAFARI = /^((?!chrome|chromium|crios|fxios|edg|opr|android).)*safari/i.test(navigator.userAgent);   // Mac Safari: FFmpeg "Load failed" and froze the page (AJAY 21/10/2026), so it is never fetched there
  function wasm(file, scale) {
    if (MAC_SAFARI) { why += ' / ffmpeg: not in Safari'; return Promise.resolve(null); }
    var F = null, dur = 0, name = 'in' + ((/\.\w{1,5}$/.exec(file.name) || ['.mp4'])[0]).toLowerCase(), base = new URL('ffmpeg/', location.href).href;
    inWasm = true;
    return import(base + 'index.js?v=0.12.15').then(function (m) {
      F = new m.FFmpeg(); stopNow = {cancel: function () { try { F.terminate(); } catch (e) {} }};
      F.on('log', function (e) { var d = /Duration: (\d+):(\d+):([\d.]+)/.exec(e.message || ''); if (d && !dur) dur = +d[1] * 3600 + +d[2] * 60 + +d[3]; });
      F.on('progress', function (e) { if (e.progress >= 0 && e.progress <= 1) say('Making the video smaller… ' + Math.min(99, Math.round(e.progress * 100)) + '%. Keep this page open.'); });
      say('Getting the video maker ready (once)… Keep this page open.');
      return F.load({coreURL: base + 'ffmpeg-core.js?v=0.12.10', wasmURL: base + 'ffmpeg-core.wasm?v=0.12.10'});
    }).then(function () { return F.createDir('/in'); }).then(function () { return F.mount('WORKERFS', {files: [new File([file], name, {type: file.type})]}, '/in'); })
    .then(function () { return F.exec(['-hide_banner', '-i', '/in/' + name]).catch(function () {}); })   // reads the length (it says so in the log)
    .then(function () {
      var br = Math.max(120000, Math.round(rate(dur) * (scale || 1))), L = side(br);
      say('Making the video smaller… 0%. Keep this page open.');
      return F.exec(['-hide_banner', '-i', '/in/' + name, '-map', '0:v:0', '-map', '0:a:0?', '-vf', 'scale=w=' + L + ':h=' + L + ':force_original_aspect_ratio=decrease:force_divisible_by=2',
        '-c:v', 'libx264', '-preset', 'veryfast', '-b:v', String(br), '-maxrate', String(Math.round(br * 1.3)), '-bufsize', String(br * 2), '-pix_fmt', 'yuv420p',
        '-c:a', 'aac', '-b:a', '96k', '-ac', '2', '-movflags', '+faststart', '/out.mp4']);
    }).then(function (code) { if (code) { why += ' / ffmpeg: exit ' + code; return null; } return F.readFile('/out.mp4'); })
    .then(function (d) { return d && d.length ? new Blob([d.buffer], {type: 'video/mp4'}) : null; },
      function (e) { why += ' / ffmpeg: ' + String(e && (e.message || e.name) || e).slice(0, 60); return null; })
    .then(function (b) { inWasm = false; try { F && F.terminate(); } catch (e) {} return b; });
  }
  function shrink(file) {
    return new Promise(function (ok, no) {
      var v = document.createElement('video'), c = document.createElement('canvas'), g = c.getContext('2d'), rec = null, parts = [], raf = 0, ctx = null, dest = null;
      v.playsInline = true; v.setAttribute('playsinline', ''); v.preload = 'auto'; v.src = URL.createObjectURL(file); note.insertBefore(v, note.firstChild);
      stopNow = {cancel: function () { v.onended = v.onerror = null; fail(); }};
      try { var AC = window.AudioContext || window.webkitAudioContext; if (AC) { ctx = new AC(); dest = ctx.createMediaStreamDestination(); ctx.createMediaElementSource(v).connect(dest); } } catch (e) { dest = null; }
      var fail = function () { cancelAnimationFrame(raf); try { rec && rec.state !== 'inactive' && rec.stop(); } catch (e) {} v.remove(); no(); };
      v.onloadedmetadata = function () { var k = Math.min(1, side(rate(v.duration)) / Math.max(v.videoWidth, v.videoHeight)); c.width = Math.round(v.videoWidth * k / 2) * 2; c.height = Math.round(v.videoHeight * k / 2) * 2; };
      v.onplaying = function () {
        if (rec) return;
        var st = c.captureStream(30); if (dest) dest.stream.getAudioTracks().forEach(function (t) { st.addTrack(t); });
        try { rec = new MediaRecorder(st, {mimeType: mime, videoBitsPerSecond: rate(v.duration), audioBitsPerSecond: AB}); } catch (e) { return fail(); }
        rec.ondataavailable = function (e) { if (e.data && e.data.size) parts.push(e.data); };
        rec.onstop = function () { cancelAnimationFrame(raf); if (ctx) ctx.close(); URL.revokeObjectURL(v.src); v.remove(); var b = new Blob(parts, {type: mime.split(';')[0]}); b.size ? ok(b) : no(); };
        rec.start(1000);
        (function draw() { g.drawImage(v, 0, 0, c.width, c.height); say('Making the video smaller… ' + (v.duration ? Math.min(99, Math.round(v.currentTime / v.duration * 100)) : 0) + '%. Keep this page open.'); raf = requestAnimationFrame(draw); })();
      };
      v.onended = function () { rec && rec.state !== 'inactive' ? rec.stop() : fail(); };
      v.onerror = function () { why += ' / slow: cannot open'; fail(); };
      if (ctx && ctx.resume) ctx.resume();
      var p = v.play(); if (p && p.catch) p.catch(function (e) { why += ' / slow: ' + (e && e.name || 'no play'); fail(); });
    });
  }
  /* upload with a % bar (big videos in pieces, see parts()), then show the page the upload returns (the list with the new video, or what went wrong); a stopped upload says so */
  /* a made-smaller video is only sent if this browser can play it back; otherwise the next way is tried */
  function playable(b) {
    return new Promise(function (ok) {
      var v = document.createElement('video'), u = URL.createObjectURL(b), done = function (y) { clearTimeout(t); v.removeAttribute('src'); v.load(); URL.revokeObjectURL(u); ok(y); }, t = setTimeout(function () { done(true); }, 8000);
      v.muted = true; v.playsInline = true; v.preload = 'auto'; v.onloadeddata = function () { done(true); }; v.onerror = function () { done(false); }; v.src = u;
    });
  }
  var stopNow = null, how = 'as is', stop = function (t) { btn.disabled = false; btn.textContent = 'Upload'; delete f.dataset.go; say(t); };
  /* every video goes up in pieces, 4 at a time (several times faster on most connections);
     a piece that fails is sent again (4 tries), and the server checks every byte arrived before the video joins the list */
  function parts(file) {
    var size = Math.min(8 * MB, Math.max(2 * MB, Math.ceil(file.size / 16))), n = Math.ceil(file.size / size), up = '', done = [], next = 0, running = 0, failed = false, csrf = f.querySelector('[name=csrf]').value;
    var r = new Uint8Array(8); crypto.getRandomValues(r); r.forEach(function (x) { up += ('0' + x.toString(16)).slice(-2); });
    var show = function () { var s = 0; done.forEach(function (d) { s += d || 0; }); say('Uploading… ' + Math.min(99, Math.round(s / file.size * 100)) + '% of ' + Math.max(1, Math.round(file.size / MB)) + ' MB. Keep this page open.'); };
    return new Promise(function (ok, no) {
      function one(i, tries) {
        var x = new XMLHttpRequest(), fd = new FormData(), b = file.slice(i * size, Math.min(file.size, (i + 1) * size));
        var retry = function () { done[i] = 0; show(); if (failed) return; if (tries < 3) setTimeout(function () { one(i, tries + 1); }, 1500 * (tries + 1)); else { failed = true; no(); } };
        fd.append('csrf', csrf); fd.append('up', up); fd.append('i', i); fd.append('part', b, 'part');
        x.open('POST', './?vchunk=1'); x.timeout = 300000;
        x.upload.onprogress = function (e) { if (e.lengthComputable) { done[i] = Math.min(b.size, e.loaded * b.size / e.total); show(); } };
        x.onload = function () { var j = {}; try { j = JSON.parse(x.responseText); } catch (e) {} if (j.ok && j.size === b.size) { done[i] = b.size; show(); running--; pump(); } else retry(); };
        x.onerror = x.ontimeout = retry;
        x.send(fd);
      }
      function pump() { if (failed) return; if (next >= n && !running) return ok({up: up, n: n}); while (running < 4 && next < n) { running++; one(next++, 0); } }
      say('Uploading… 0%. Keep this page open.'); pump();
    });
  }
  var keeps = function (file) { return /^video\/(mp4|webm|x-m4v|quicktime)$/i.test(file.type) || /\.(mp4|m4v|webm|mov)$/i.test(file.name); };
  var skipBtn = function (file, also) { if (!keeps(file)) return null; var b = document.createElement('button'); b.type = 'button'; b.className = 'btn line sm vskip'; b.textContent = 'Skip, upload original';
    b.onclick = function () { b.remove(); also && also.remove(); how = 'as is (skipped by tap)'; send(null); }; note.appendChild(b); return b; };
  function tapOnce(file) {
    btn.disabled = true; say('One more step: tap "Make it smaller" (this browser needs a tap). It takes as long as the video. Keep this page open.');
    var go = document.createElement('button'); go.type = 'button'; go.className = 'btn sm vskip'; go.textContent = 'Make it smaller'; note.appendChild(go); var sb = skipBtn(file, go); try { go.scrollIntoView({block: 'center', behavior: 'smooth'}); } catch (e) {}
    go.onclick = function () {
      go.remove(); sb && sb.remove(); why = '';
      shrink(file).then(function (r) { return playable(r).then(function (y) { return y ? r : Promise.reject(); }); }).then(function (r) {
        how = 'recorded after tap ' + r.type; send(new File([r], file.name.replace(/\.\w+$/, '') + '.' + (/mp4/.test(r.type) ? 'mp4' : 'webm'), {type: r.type}));
      }, function () { if (file.size <= 10 * MB && (/^video\/(mp4|webm|x-m4v|quicktime)$/i.test(file.type) || /\.(mp4|m4v|webm|mov)$/i.test(file.name))) send(null); else { btn.disabled = false; btn.textContent = 'Upload'; say('This video could not be made smaller here, so it was not uploaded. Please save it as MP4 and upload again. (' + why + ')'); skipBtn(file); } });
    };
  }
  var cin = f.querySelector('input[type=file][name=cover]'), auto = null;
  var pk = document.createElement('button'); pk.type = 'button'; pk.className = 'lnk vpickup'; pk.hidden = true; pk.textContent = 'Pick cover from the video';   // the chosen frame becomes the cover (a cover photo chosen after it wins)
  pk.style.cssText = 'margin:4px 0 0;font-size:12px';
  pk.onclick = function () { var file = inp.files && inp.files[0]; if (!file) return; window.fxPick(file, function (b) { if (cin) cin.value = ''; auto = Promise.resolve(b); pk.textContent = 'Cover picked from the video ✓ (tap to change)'; }); };
  if (cin) { cin.parentNode.appendChild(pk); cin.addEventListener('change', function () { if (cin.files && cin.files.length && /✓/.test(pk.textContent)) pk.textContent = 'Pick cover from the video'; }); }   // no cover photo chosen: the best frame of the video becomes its cover
  function send(file) {
    if (auto) { var a = auto; auto = null; btn.disabled = true; a.then(function (b) { if (b && cin && !(cin.files && cin.files.length)) send.cover = b; send(file); }); return; }
    var src = file || inp.files[0];
    btn.disabled = true; btn.textContent = 'Uploading…'; f.dataset.go = 1;
    if (src) return parts(src).then(function (p) {
      var fd = new FormData(f); fd.delete('video'); fd.set('parts', p.up); fd.set('parts_n', p.n); fd.set('parts_size', src.size); fd.set('parts_type', src.type); fd.set('how', how + ', ' + p.n + ' pieces'); if (send.cover) fd.set('cover', send.cover, 'cover.jpg');
      say('Saving the video…'); post(fd);
    }, function () { stop('The upload stopped. Check your internet, then tap Upload again.'); });
    var fd = new FormData(f); fd.set('how', how); if (send.cover) fd.set('cover', send.cover, 'cover.jpg'); post(fd);
  }
  /* the video is already on the server in pieces: this only saves it. The page then loads afresh (not written over),
     so everything on it works again for the next upload, and it shows "Video added" or what went wrong */
  function post(fd) {
    fetch(f.action || location.href, {method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual'}).then(function (r) {
      if (r.type === 'opaqueredirect' || r.ok) location.replace(location.href);
      else stop('The upload did not finish (error ' + r.status + '). Please tap Upload again.');
    }, function () { stop('The upload stopped. Check your internet, then tap Upload again.'); });
  }
  f.addEventListener('submit', function (e) {
    var file = inp.files[0]; if (!file || f.dataset.go) return;
    e.preventDefault();
    /* over 5 MB (any type) is made smaller, to about 5 MB, in sharp 720p, so the site stays fast; any type other than MP4/WebM (iPhone .mov, .mkv, .avi…) is turned into MP4.
       Only a video under 10 MB in a type the site keeps (MP4, WebM, MOV) may go up as it is when that can't be done here */
    var plain = /^video\/(mp4|webm|x-m4v)$/i.test(file.type) || /\.(mp4|m4v|webm)$/i.test(file.name), mov = /quicktime/i.test(file.type) || /\.mov$/i.test(file.name);
    var big = file.size > 5 * MB || !plain, asIs = file.size <= 10 * MB && (plain || mov);
    var can = mime && HTMLCanvasElement.prototype.captureStream;
    var cant = function () { btn.disabled = false; btn.textContent = 'Upload'; say('This video could not be made smaller here, so it was not uploaded. Please save it as MP4 and upload again. (' + (why || 'no way to convert') + ')'); skipBtn(file); };
    if (!big) { send(null); return; }
    btn.disabled = true; setTimeout(function () { btn.textContent = 'Please wait…'; });
    /* making it smaller: no progress for 45 seconds, or a tap on Skip = upload the original instead (in pieces) */
    var t0 = Date.now(), last = -1, moved = t0, wasmAt = 0, bail, gaveUp = new Promise(function (ok) { bail = ok; });
    var sk = document.createElement('button'); sk.type = 'button'; sk.className = 'btn line sm vskip'; sk.textContent = 'Skip, upload original'; var skipped = false; sk.onclick = function () { skipped = true; bail(); }; if (keeps(file)) note.appendChild(sk);   // any size: the original goes up as it is when Skip is tapped
    var dog = setInterval(function () { var now = Date.now(); if (pct !== last) { last = pct; moved = now; } if (!inWasm) wasmAt = 0; else if (!wasmAt) wasmAt = now; if (inWasm && now - wasmAt < 180000) moved = now;   /* FFmpeg gets 3 minutes to get ready, then no progress for 45 s = Skip */ if (now - moved > 45000) bail(); }, 1000);
    var quit = false; gaveUp.then(function () { quit = true; clearInterval(dog); try { stopNow && stopNow.cancel(); } catch (e) {} });
    var shrunk = fast(file).then(function (b) {   // still over 5 MB (some phones' encoders overshoot): once more, with the quality lowered by just that much
      if (!b || b.size <= 5 * MB || quit) return b; t0 = Date.now(); return fast(file, 4.6 * MB / b.size).then(function (c) { return c && c.size < b.size ? c : b; });
    }).then(function (b) { return b && b.size < file.size ? playable(b).then(function (y) { if (y) how = 'fast ' + b.type; return y ? b : null; }) : null; })
      .then(function (b) { if (b || quit) return b; return wasm(file).then(function (w) {   // the fast way couldn't: the sure way (FFmpeg)
        if (!w || w.size <= 5 * MB || quit) return w; return wasm(file, 4.6 * MB / w.size).then(function (c) { return c && c.size < w.size ? c : w; });
      }).then(function (w) { if (w) how = 'ffmpeg ' + w.type; return w; }); })
      .then(function (b) { if (quit) return Promise.reject(); return b || (can ? shrink(file).then(function (r) { return playable(r).then(function (y) { if (y) how = 'recorded ' + r.type; return y ? r : Promise.reject(); }); }) : Promise.reject()); });
    shrunk.catch(function () {});
    Promise.race([shrunk, gaveUp.then(function () { how = 'as is (skipped at ' + pct + '%)'; return Promise.reject(); })]).then(function (b) {
      clearInterval(dog); sk.remove();
      send(new File([b], file.name.replace(/\.\w+$/, '') + '.' + (/mp4/.test(b.type) ? 'mp4' : 'webm'), {type: b.type}));
    }, function () {
      clearInterval(dog); sk.remove(); bail(); if (skipped) { send(null); return; } if (how === 'as is') how = 'as is (' + (why || 'slow way failed') + ')';
      try { navigator.sendBeacon && navigator.sendBeacon('../vlog.php', new Blob([JSON.stringify({v: ['shrink failed: ' + why + ' | ' + (file.type || '?') + ' ' + Math.round(file.size / MB) + ' MB']})], {type: 'application/json'})); } catch (x) {}
      /* some browsers (Safari with auto-play off) only play a video after a tap: then the slow way gets one more go, started by a tap */
      if (can && /NotAllowed/.test(why)) { tapOnce(file); return; }
      if (!asIs) { cant(); return; }
      if (file.size > 5 * MB) { say('This browser can\'t make it smaller, so it goes up as it is (' + (file.size / MB).toFixed(1) + ' MB). Chrome makes it smaller.'); setTimeout(function () { send(null); }, 3500); return; }   // Safari on a Mac
      send(null);
    });
  });
  /* 9:16 or 16:9: the ring is picked from the chosen video's real shape (it can still be changed by hand) */
  inp.addEventListener('change', function () {
    var file = inp.files && inp.files[0], r = f.querySelectorAll('input[name=shape]'); auto = file && window.fxCover ? window.fxCover(file) : null; pk.hidden = !file; pk.textContent = 'Pick cover from the video'; if (!file || r.length < 2) return;
    var v = document.createElement('video'); v.preload = 'metadata'; v.muted = true; v.src = URL.createObjectURL(file);
    v.onloadedmetadata = function () { if (v.videoWidth && v.videoHeight) (v.videoWidth > v.videoHeight ? r[1] : r[0]).checked = true; URL.revokeObjectURL(v.src); };
    v.onerror = function () { URL.revokeObjectURL(v.src); };
  });
})();
/* Small previews for the home page row: each video without one gets a 640px, silent copy of its first 8 seconds (about 0.5 MB),
   made here in the browser and kept next to it (vid/name-p.mp4). The row plays that; the full video loads only when the player opens.
   Runs by itself on this page for every video that has none yet (new uploads and older videos), one at a time. */
setTimeout(function () {   // after the page has loaded (the list comes after this script)
  var rows = [].slice.call(document.querySelectorAll('.vrow[data-pvsrc]')), f = document.querySelector('form[data-vshrink]');   // every uploaded video: data-mkprev = no preview yet (made by itself), data-pv = has one
  if (!rows.length || !f || !window.fetch || !window.Promise) return;
  var csrf = (f.querySelector('[name=csrf]') || {}).value, btn = f.querySelector('.btn'), SEC = 8, BR = 600000, pill = null, made = 0, conv = null, how = '', noFast = false, noWasm = false, Fok = false, fails = 0, gone = false, last = '';
  addEventListener('pagehide', function () { gone = true; });   // leaving or reloading the page: stop, don't log the cut-off ones as failed
  var KEY = 'fxPrevFail', bad = {}; try { bad = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
  var mark = function (nm) { bad[nm] = (bad[nm] || 0) + 1; try { localStorage.setItem(KEY, JSON.stringify(bad)); } catch (e) {} };
  var nmOf = function (r) { return r.dataset.pvsrc.split(/[\/=]/).pop(); };
  var CANT = 'fxPrevCant', cant = false; try { cant = Date.now() - (+localStorage.getItem(CANT) || 0) < 7 * 864e5; } catch (e) {}
  /* Mac Safari: making previews by itself froze the admin page (FFmpeg "Load failed", AJAY 21/10/2026), so it never starts there on its own;
     a tap on "Make preview" tries only the light way, never the 32 MB FFmpeg. Chrome makes them as before */
  var SAFARI = /^((?!chrome|chromium|crios|fxios|edg|opr|android).)*safari/i.test(navigator.userAgent); if (SAFARI) cant = true;
  /* every video still without a preview gets a "Make preview" button. By itself this page only tries the ones that never failed in this browser,
     and none at all for 7 days in a browser that couldn't make any (Mac Safari), so nothing is downloaded on every visit */
  var queue = cant ? [] : rows.filter(function (r) { return r.hasAttribute('data-mkprev') && !bad[nmOf(r)]; }), busy = false, total = queue.length;
  var save = function () { try { localStorage.setItem(KEY, JSON.stringify(bad)); } catch (e) {} };
  var label = function (r) { return r.hasAttribute('data-pv') ? 'Preview ✓ · Make again' : 'Make preview'; };
  var showBtn = function (r, failed) { var b = r._mk; if (!b) return; b.hidden = false; b.disabled = false; b.textContent = failed ? (SAFARI ? 'Preview: open admin in Chrome' : 'Preview failed · Try again') : label(r); if (failed) b.title = 'Could not be made in this browser. Chrome works best.'; };
  rows.forEach(function (r) {
    var inf = r.querySelector('.vinfo'); if (!inf) return;
    var b = r._mk = document.createElement('button'); b.type = 'button'; b.className = 'vmkp'; var q = queue.indexOf(r) >= 0; b.textContent = q ? 'Making preview…' : label(r); b.disabled = q;   // always shown: the preview's state, and a tap makes it (again)
    b.style.cssText = 'margin-left:8px;font:inherit;font-size:12px;line-height:1.6;color:#b8913a;background:none;border:1px solid currentColor;border-radius:999px;padding:0 9px;cursor:pointer';
    if (SAFARI) { b.disabled = true; b.style.cursor = 'default'; if (r.hasAttribute('data-pv')) b.textContent = 'Preview ✓'; else if (r.hasAttribute('data-mkprev')) { b.textContent = 'Preview: make it in Chrome'; b.title = 'Safari can\'t make previews. Open admin in Chrome once and they are made by themselves.'; } else b.hidden = true; }   // Safari makes none (nothing heavy runs there)
    b.onclick = function () { if (SAFARI) return; delete bad[nmOf(r)]; save(); try { localStorage.removeItem(CANT); } catch (e) {} noFast = false; noWasm = SAFARI; fails = 0;
      b.disabled = true; b.textContent = 'Making preview…'; if (queue.indexOf(r) < 0) { queue.push(r); total++; } if (!busy) next(); };
    inf.appendChild(b);
  });
  /* a way that hangs (some Mac Safari videos never finish in the fast way) is given up after a while, so the next way or the next video goes on */
  var limit = function (p, ms, stop) { var t; return Promise.race([p, new Promise(function (ok) { t = setTimeout(function () { try { stop && stop(); } catch (e) {} ok(null); }, ms); })]).then(function (r) { clearTimeout(t); return r; }); };
  var tell = function (t) { if (f.querySelector('[name=act]')) try { navigator.sendBeacon && navigator.sendBeacon('../vlog.php', new Blob([JSON.stringify({v: ['preview ' + t]})], {type: 'application/json'})); } catch (e) {} };   // fomaxo.com: written to vid-report.txt
  var say = function (t) {
    if (!pill) { pill = document.createElement('div'); pill.setAttribute('role', 'status'); pill.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:60;background:#111;color:#d4af37;border:1px solid #d4af37;border-radius:999px;padding:6px 14px;font-size:13px;max-width:calc(100vw - 24px)'; document.body.appendChild(pill); }
    pill.textContent = t; pill.hidden = !t;
  };
  function fastPrev(blob) {
    if (!window.VideoEncoder) return Promise.resolve(null);
    return import(new URL('mediabunny.js?v=1.59.1', location.href).href).then(function (M) {
      var src = new M.Input({source: new M.BlobSource(blob), formats: M.ALL_FORMATS});
      return Promise.all([src.getPrimaryVideoTrack(), src.computeDuration().catch(function () { return 0; })]).then(function (r) {
        var t = r[0]; if (!t) return null;
        var k = Math.min(1, 640 / Math.max(t.displayWidth, t.displayHeight)), w = Math.round(t.displayWidth * k / 2) * 2, h = Math.round(t.displayHeight * k / 2) * 2;
        return M.canEncodeVideo('avc', {width: w, height: h}).then(function (ok) {
          if (!ok) return null;   // the row needs H.264 so every phone plays it
          var out = new M.Output({format: new M.Mp4OutputFormat({fastStart: 'in-memory'}), target: new M.BufferTarget()});
          return M.Conversion.init({input: src, output: out, showWarnings: false, video: {width: w, height: h, fit: 'contain', codec: 'avc', bitrate: BR, forceTranscode: true}, audio: {discard: true}, trim: r[1] > SEC ? {start: 0, end: SEC} : undefined})
            .then(function (c) { conv = c; return c.isValid ? c.execute().then(function () { var b = out.target.buffer; return b && b.byteLength ? new Blob([b], {type: 'video/mp4'}) : null; }) : null; });
        });
      });
    }).catch(function (e) { how += ' fast: ' + String(e && (e.message || e.name) || e).slice(0, 50); return null; });
  }
  var F = null;   // FFmpeg (admin/ffmpeg), loaded once for all previews when the fast way can't
  function wasmPrev(blob) {
    var base = new URL('ffmpeg/', location.href).href;
    return (F ? Promise.resolve() : import(base + 'index.js?v=0.12.15').then(function (m) { F = new m.FFmpeg(); F.on('log', function (e) { last = String(e && e.message || '').slice(0, 80); }); var t = Date.now(); return F.load({coreURL: base + 'ffmpeg-core.js?v=0.12.10', wasmURL: base + 'ffmpeg-core.wasm?v=0.12.10'}).then(function () { Fok = true; tell('ffmpeg loaded in ' + Math.round((Date.now() - t) / 1000) + ' s'); }); }))
      .then(function () { return F.createDir('/p').catch(function () {}); })
      .then(function () { return F.mount('WORKERFS', {files: [new File([blob], 'in')]}, '/p'); })
      .then(function () { return F.exec(['-hide_banner', '-t', String(SEC), '-i', '/p/in', '-map', '0:v:0', '-an', '-vf', 'scale=w=640:h=640:force_original_aspect_ratio=decrease:force_divisible_by=2',
        '-c:v', 'libx264', '-preset', 'ultrafast', '-b:v', String(BR), '-maxrate', String(BR * 1.4), '-bufsize', String(BR * 2), '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '/p.mp4']); })
      .then(function (code) { return code ? null : F.readFile('/p.mp4'); })
      .then(function (d) { F.unmount('/p').catch(function () {}); F.deleteFile('/p.mp4').catch(function () {}); return d && d.length ? new Blob([d.buffer], {type: 'video/mp4'}) : null; })
      .catch(function (e) { how += ' ffmpeg: ' + String(e && (e.message || e.name) || e).slice(0, 50) + ' (last: ' + last + ')'; if (!Fok) noWasm = true; /* FFmpeg itself won't load in this browser: not tried again */ try { F && F.terminate(); } catch (x) {} F = null; return null; });
  }
  var idle = function () { return new Promise(function (ok) { (function w() { if (f.dataset.go || (btn && btn.disabled) || document.hidden) setTimeout(w, 3000); else ok(); })(); }); };   // an upload going on comes first; a tab in the background waits (the browser slows it right down)
  var i = 0, t0 = 0;
  function next() {
    if (gone) return;
    if (fails >= 2 && noFast && noWasm) {   // neither way works in this browser: stop quietly, the buttons stay for a try in another browser
      tell('stopped: this browser cannot make previews'); try { localStorage.setItem(CANT, String(Date.now())); } catch (e) {} queue.forEach(function (r) { showBtn(r, true); }); queue = []; }
    if (!queue.length) { busy = false; try { F && F.terminate(); } catch (e) {} F = null; if (made) { say('Quick previews ready for the home page (' + made + ').'); setTimeout(function () { say(''); }, 5000); } else say(''); return; }
    busy = true; var row = queue.shift(); i++;
    idle().then(function () {
      say('Making quick previews for the home page: ' + i + ' of ' + total + '…'); t0 = Date.now(); tell('start ' + i + ' of ' + total);
      var ac = window.AbortController ? new AbortController() : null;   // a download that hangs is given up after 90 seconds
      return limit(fetch(row.dataset.pvsrc, {credentials: 'same-origin', signal: ac && ac.signal}).then(function (r) { return r.ok ? r.blob() : null; }), 90000, function () { ac && ac.abort(); });
    }).then(function (b) {
      how = ''; if (!b) { how = 'not loaded'; return null; }
      tell('loaded ' + (b.size / 1048576).toFixed(1) + ' MB in ' + Math.round((Date.now() - t0) / 1000) + ' s');
      /* the fast way gets 25 seconds; once it fails here, the rest go straight to FFmpeg (fast, 640px, first 8 seconds only) */
      return (noFast ? Promise.resolve(null) : limit(fastPrev(b), 25000, function () { how += ' fast: too slow'; conv && conv.cancel(); })).then(function (p) { if (p) { how = 'fast'; return p; } noFast = true;
        if (noWasm) return null;
        return limit(wasmPrev(b), 180000, function () { how += ' ffmpeg: too slow (last: ' + last + ')'; try { F && F.terminate(); } catch (e) {} F = null; }).then(function (w) { if (w) how = 'ffmpeg'; return w; }); });
    }).then(function (p) {
        var nm = nmOf(row); if (gone) return;
        if (p) fails = 0; else { fails++; mark(nm); showBtn(row, true); }
        tell(nm + (p ? ' ' + Math.round(p.size / 1024) + ' KB ' + how : ' failed' + how) + ' in ' + Math.round((Date.now() - t0) / 1000) + ' s | ' + navigator.userAgent.slice(0, 120));
        if (!p || p.size > 3 * 1048576) { if (p) showBtn(row, true); return; }
        var fd = new FormData(); fd.append('csrf', csrf); if (f.querySelector('[name=action]')) fd.append('action', 'vid_prev'); else fd.append('act', 'prev'); fd.append('vid', row.dataset.vid); fd.append('prev', p, 'prev.mp4');
        return fetch(location.href, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) { made++; row.removeAttribute('data-mkprev'); row.setAttribute('data-pv', '1'); if (row._mk) { row._mk.disabled = false; row._mk.textContent = 'Preview ✓ · Make again'; } } else showBtn(row, true); });
      }).catch(function (e) { if (!gone) { tell('error ' + String(e && (e.message || e.name) || e).slice(0, 80)); showBtn(row, true); } }).then(next);
  }
  if (queue.length) next();
}, 1500);

/* videos uploaded without a cover photo get one picked from the video (window.fxCover), so the home page row shows the video's own picture,
   not the product photo, when the browser holds back autoplay. One video at a time; a video that fails twice in this browser is left */
setTimeout(function () {
  var rows = [].slice.call(document.querySelectorAll('.vrow[data-mkcover]')), f = document.querySelector('form[data-vshrink]');
  if (!rows.length || !f || !window.fetch || !window.fxCover) return;
  var csrf = (f.querySelector('[name=csrf]') || {}).value, KEY = 'fxCoverFail', bad = {}; try { bad = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
  rows = rows.filter(function (r) { return (bad[r.dataset.vid] || 0) < 2; });
  var gone = false; addEventListener('pagehide', function () { gone = true; });
  (function next() {
    var r = rows.shift(); if (!r || gone) return;
    if (document.hidden || f.dataset.go) { rows.unshift(r); return setTimeout(next, 3000); }   // an upload going on comes first
    window.fxCover(r.dataset.mkcover).then(function (c) {   // straight from its address: the browser reads only the 5 moments it looks at, not the whole video (a slow line stayed busy for minutes)
      if (gone) return;
      if (!c) { bad[r.dataset.vid] = (bad[r.dataset.vid] || 0) + 1; try { localStorage.setItem(KEY, JSON.stringify(bad)); } catch (e) {} return; }
      var fd = new FormData(); fd.append('csrf', csrf); fd.append('act', 'cover'); fd.append('vid', r.dataset.vid); fd.append('cover', c, 'cover.jpg');
      return fetch(location.href, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (x) { return x.json(); }).then(function (j) {
        if (j && j.ok) { r.removeAttribute('data-mkcover'); var im = r.querySelector('.vth'); if (im && j.src) { if (im.tagName === 'VIDEO') im.poster = j.src; else im.src = j.src; } } });
    }).catch(function () {}).then(function () { setTimeout(next, 500); });
  })();
}, 2500);

/* every uploaded video gets "Pick cover": choose the frame yourself (replaces the cover it has) */
setTimeout(function () {
  var f = document.querySelector('form[data-vshrink]'); if (!f || !window.fxPick) return;
  var csrf = (f.querySelector('[name=csrf]') || {}).value;
  [].slice.call(document.querySelectorAll('.vrow[data-vid]')).forEach(function (r) {
    var th = r.querySelector('video.vth'), inf = r.querySelector('.vinfo'); if (!th || !inf || !th.getAttribute('src')) return;
    var b = document.createElement('button'); b.type = 'button'; b.className = 'vpk'; b.textContent = 'Pick cover';
    b.style.cssText = 'margin-left:8px;font:inherit;font-size:12px;line-height:1.6;color:#b8913a;background:none;border:1px solid currentColor;border-radius:999px;padding:0 9px;cursor:pointer';
    b.onclick = function () { window.fxPick(th.getAttribute('src').split('#')[0], function (c) {
      b.disabled = true; b.textContent = 'Saving cover…';
      var fd = new FormData(); fd.append('csrf', csrf); fd.append('act', 'cover'); fd.append('vid', r.dataset.vid); fd.append('pick', '1'); fd.append('cover', c, 'cover.jpg');
      fetch(location.href, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (x) { return x.json(); }).then(function (j) {
        b.disabled = false; b.textContent = j && j.ok ? 'Cover saved ✓' : 'Not saved, try again';
        if (j && j.ok) { r.removeAttribute('data-mkcover'); if (j.src) th.poster = j.src; th.pause(); th.currentTime = 0; th.load(); }
      }, function () { b.disabled = false; b.textContent = 'Not saved, try again'; });
    }); };
    inf.appendChild(b);
  });
}, 1200);
