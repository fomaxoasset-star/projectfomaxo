/* Upload from your phone: a big or iPhone (.mov) video is made smaller in the browser first, then uploaded as usual.
   Fast way: the video is re-encoded straight from the file (admin/mediabunny.js, WebCodecs), several times faster than playing it,
   Every video over 10 MB comes out under 5 MB: the quality is set from the video's length (1080p, 720p for long videos).
   Browsers without it re-record the video while it plays (720p). Neither works = upload as it is. */
(function () {
  var f = document.querySelector('form[data-vshrink]'); if (!f) return;
  var inp = f.querySelector('input[type=file][name=video]'), MB = 1048576, note = f.querySelector('.vsh'), btn = f.querySelector('.btn');
  var mime = ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm'].filter(function (m) { return window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m); })[0];
  var why = '', pct = -1, say = function (t) { note.hidden = false; note.querySelector('span').textContent = t; var m = /smaller… (\d+)%/.exec(t); if (m) pct = +m[1]; };
  var GOAL = 4.6 * MB * 8;   // bits: every made-smaller video lands under 5 MB
  var rate = function (sec) { return Math.max(400000, Math.min(5000000, Math.floor(GOAL / Math.max(1, sec || 30)) - 96000)); };   // video bits per second for that length (96 kbit/s is the sound)
  /* fast: re-encode from the file itself, no playing; null = this browser can't, use the slow way */
  function fast(file, scale) {
    if (!window.VideoEncoder) { why = 'no VideoEncoder'; return Promise.resolve(null); }
    var M = null, conv = null, src = null;
    return import(new URL('mediabunny.js?v=1.59.1', location.href).href).then(function (m) {
      M = m; src = new M.Input({source: new M.BlobSource(file), formats: M.ALL_FORMATS});
      return Promise.all([src.getPrimaryVideoTrack(), src.computeDuration().catch(function () { return 0; }), src.getPrimaryAudioTrack().catch(function () { return null; })]);
    }).then(function (r) {
      var t = r[0], at = r[2], br = Math.max(300000, Math.round(rate(r[1]) * (scale || 1))); if (!t) { why = 'no video track'; return null; }
      var w = t.displayWidth, h = t.displayHeight, k = Math.min(1, (br < 2500000 ? 1280 : 1920) / Math.max(w, h));
      w = Math.round(w * k / 2) * 2; h = Math.round(h * k / 2) * 2;
      return Promise.all([M.getFirstEncodableVideoCodec(['avc', 'hevc', 'vp9'], {width: w, height: h}), window.AudioEncoder ? M.getFirstEncodableAudioCodec(['aac', 'opus']).catch(function () { return null; }) : null]).then(function (cs) {
        if (!cs[0]) { why = 'no video encoder ' + w + 'x' + h; return null; }
        var au = cs[1] ? {codec: cs[1], bitrate: 96000} : at ? {codec: at.codec} : undefined;   // no sound encoder (some Safari): the sound is copied as it is
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
  function shrink(file) {
    return new Promise(function (ok, no) {
      var v = document.createElement('video'), c = document.createElement('canvas'), g = c.getContext('2d'), rec = null, parts = [], raf = 0, ctx = null, dest = null;
      v.playsInline = true; v.setAttribute('playsinline', ''); v.preload = 'auto'; v.src = URL.createObjectURL(file); note.insertBefore(v, note.firstChild);
      stopNow = {cancel: function () { v.onended = v.onerror = null; fail(); }};
      try { var AC = window.AudioContext || window.webkitAudioContext; if (AC) { ctx = new AC(); dest = ctx.createMediaStreamDestination(); ctx.createMediaElementSource(v).connect(dest); } } catch (e) { dest = null; }
      var fail = function () { cancelAnimationFrame(raf); try { rec && rec.state !== 'inactive' && rec.stop(); } catch (e) {} v.remove(); no(); };
      v.onloadedmetadata = function () { var k = Math.min(1, 1280 / Math.max(v.videoWidth, v.videoHeight)); c.width = Math.round(v.videoWidth * k / 2) * 2; c.height = Math.round(v.videoHeight * k / 2) * 2; };
      v.onplaying = function () {
        if (rec) return;
        var st = c.captureStream(30); if (dest) dest.stream.getAudioTracks().forEach(function (t) { st.addTrack(t); });
        try { rec = new MediaRecorder(st, {mimeType: mime, videoBitsPerSecond: Math.min(2500000, rate(v.duration)), audioBitsPerSecond: 96000}); } catch (e) { return fail(); }
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
  function send(file) {
    var src = file || inp.files[0];
    btn.disabled = true; btn.textContent = 'Uploading…'; f.dataset.go = 1;
    if (src) return parts(src).then(function (p) {
      var fd = new FormData(f); fd.delete('video'); fd.set('parts', p.up); fd.set('parts_n', p.n); fd.set('parts_size', src.size); fd.set('parts_type', src.type); fd.set('how', how + ', ' + p.n + ' pieces');
      say('Saving the video…'); post(fd);
    }, function () { stop('The upload stopped. Check your internet, then tap Upload again.'); });
    var fd = new FormData(f); fd.set('how', how); post(fd);
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
    /* over 10 MB (any type) is made smaller, under 5 MB, so the site stays fast; any type other than MP4/WebM (iPhone .mov, .mkv, .avi…) is turned into MP4.
       Only a video under 10 MB in a type the site keeps (MP4, WebM, MOV) may go up as it is when that can't be done here */
    var plain = /^video\/(mp4|webm|x-m4v)$/i.test(file.type) || /\.(mp4|m4v|webm)$/i.test(file.name), mov = /quicktime/i.test(file.type) || /\.mov$/i.test(file.name);
    var big = file.size > 10 * MB || !plain, asIs = file.size <= 10 * MB && (plain || mov);
    var can = mime && HTMLCanvasElement.prototype.captureStream;
    var cant = function () { btn.disabled = false; btn.textContent = 'Upload'; say('This video could not be made smaller here, so it was not uploaded. Please save it as MP4 and upload again. (' + (why || 'no way to convert') + ')'); skipBtn(file); };
    if (!big) { send(null); return; }
    if (!can && !window.VideoEncoder) { if (asIs) send(null); else cant(); return; }
    btn.disabled = true; setTimeout(function () { btn.textContent = 'Please wait…'; });
    /* making it smaller: no progress for 45 seconds, or a tap on Skip = upload the original instead (in pieces) */
    var t0 = Date.now(), last = -1, moved = t0, bail, gaveUp = new Promise(function (ok) { bail = ok; });
    var sk = document.createElement('button'); sk.type = 'button'; sk.className = 'btn line sm vskip'; sk.textContent = 'Skip, upload original'; var skipped = false; sk.onclick = function () { skipped = true; bail(); }; if (keeps(file)) note.appendChild(sk);   // any size: the original goes up as it is when Skip is tapped
    var dog = setInterval(function () { var now = Date.now(); if (pct !== last) { last = pct; moved = now; } if (now - moved > 45000) bail(); }, 1000);
    var quit = false; gaveUp.then(function () { quit = true; clearInterval(dog); try { stopNow && stopNow.cancel(); } catch (e) {} });
    var shrunk = fast(file).then(function (b) {   // still over 5 MB (some phones' encoders overshoot): once more, with the quality lowered by just that much
      if (!b || b.size <= 5 * MB || quit) return b; t0 = Date.now(); return fast(file, 4.6 * MB / b.size).then(function (c) { return c && c.size < b.size ? c : b; });
    }).then(function (b) { return b && b.size < file.size ? playable(b).then(function (y) { if (y) how = 'fast ' + b.type; return y ? b : null; }) : null; })
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
      send(null);
    });
  });
  /* 9:16 or 16:9: the ring is picked from the chosen video's real shape (it can still be changed by hand) */
  inp.addEventListener('change', function () {
    var file = inp.files && inp.files[0], r = f.querySelectorAll('input[name=shape]'); if (!file || r.length < 2) return;
    var v = document.createElement('video'); v.preload = 'metadata'; v.muted = true; v.src = URL.createObjectURL(file);
    v.onloadedmetadata = function () { if (v.videoWidth && v.videoHeight) (v.videoWidth > v.videoHeight ? r[1] : r[0]).checked = true; URL.revokeObjectURL(v.src); };
    v.onerror = function () { URL.revokeObjectURL(v.src); };
  });
})();
