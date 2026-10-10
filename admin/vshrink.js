/* Upload from your phone: a big or iPhone (.mov) video is made smaller in the browser first, then uploaded as usual.
   Fast way: the video is re-encoded straight from the file (admin/mediabunny.js, WebCodecs), several times faster than playing it,
   1080p, about 20 MB for 30 seconds. Browsers without it re-record the video while it plays (720p). Neither works = upload as it is. */
(function () {
  var f = document.querySelector('form[data-vshrink]'); if (!f) return;
  var inp = f.querySelector('input[type=file][name=video]'), max = 500 * 1048576, MB = 1048576, note = f.querySelector('.vsh'), btn = f.querySelector('.btn');
  var mime = ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm'].filter(function (m) { return window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m); })[0];
  var pct = -1, say = function (t) { note.hidden = false; note.querySelector('span').textContent = t; var m = /smaller… (\d+)%/.exec(t); if (m) pct = +m[1]; };
  var tooBig = function (file) { alert('This video is ' + Math.round(file.size / MB) + ' MB. Please use one under ' + Math.round(max / MB) + ' MB.'); };
  /* fast: re-encode from the file itself, no playing; null = this browser can't, use the slow way */
  function fast(file) {
    if (!window.VideoEncoder || !window.AudioEncoder) return Promise.resolve(null);
    var M = null, conv = null, src = null;
    return import(new URL('mediabunny.js?v=1.59.1', location.href).href).then(function (m) {
      M = m; src = new M.Input({source: new M.BlobSource(file), formats: M.ALL_FORMATS});
      return src.getPrimaryVideoTrack();
    }).then(function (t) {
      if (!t) return null;
      var w = t.displayWidth, h = t.displayHeight, k = Math.min(1, 1920 / Math.max(w, h));
      w = Math.round(w * k / 2) * 2; h = Math.round(h * k / 2) * 2;
      return Promise.all([M.getFirstEncodableVideoCodec(['avc', 'hevc', 'vp9'], {width: w, height: h}), M.getFirstEncodableAudioCodec(['aac', 'opus'])]).then(function (cs) {
        if (!cs[0] || !cs[1]) return null;
        var out = new M.Output({format: new M.Mp4OutputFormat({fastStart: 'in-memory'}), target: new M.BufferTarget()});
        return M.Conversion.init({input: src, output: out, showWarnings: false,
          video: {width: w, height: h, fit: 'contain', codec: cs[0], bitrate: 5000000, forceTranscode: true},
          audio: {codec: cs[1], bitrate: 128000}}).then(function (c) {
          conv = stopNow = c; if (!c.isValid) return null;
          c.onProgress = function (p) { say('Making the video smaller… ' + Math.min(99, Math.round(p * 100)) + '%. Keep this page open.'); };
          say('Making the video smaller… 0%. Keep this page open.');
          return c.execute().then(function () { var b = out.target.buffer; return b && b.byteLength ? new Blob([b], {type: 'video/mp4'}) : null; });
        });
      });
    }).catch(function () { try { conv && conv.cancel(); } catch (e) {} return null; });
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
        try { rec = new MediaRecorder(st, {mimeType: mime, videoBitsPerSecond: 2500000, audioBitsPerSecond: 128000}); } catch (e) { return fail(); }
        rec.ondataavailable = function (e) { if (e.data && e.data.size) parts.push(e.data); };
        rec.onstop = function () { cancelAnimationFrame(raf); if (ctx) ctx.close(); URL.revokeObjectURL(v.src); v.remove(); var b = new Blob(parts, {type: mime.split(';')[0]}); b.size ? ok(b) : no(); };
        rec.start(1000);
        (function draw() { g.drawImage(v, 0, 0, c.width, c.height); say('Making the video smaller… ' + (v.duration ? Math.min(99, Math.round(v.currentTime / v.duration * 100)) : 0) + '%. Keep this page open.'); raf = requestAnimationFrame(draw); })();
      };
      v.onended = function () { rec && rec.state !== 'inactive' ? rec.stop() : fail(); };
      v.onerror = fail;
      if (ctx && ctx.resume) ctx.resume();
      var p = v.play(); if (p && p.catch) p.catch(fail);
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
    var big = file.size > 100 * MB || /quicktime/i.test(file.type) || /\.mov$/i.test(file.name);   // under 100 MB goes straight up in pieces; iPhone .mov is made playable everywhere
    var can = mime && HTMLCanvasElement.prototype.captureStream;
    if (!big || (!can && !window.VideoEncoder)) { if (max && file.size > max) { tooBig(file); return; } send(null); return; }
    btn.disabled = true; setTimeout(function () { btn.textContent = 'Please wait…'; });
    /* making it smaller must be quick: no progress for 10 seconds, under 10% after 20 seconds, or a tap on Skip = upload the original instead (in pieces) */
    var t0 = Date.now(), last = -1, moved = t0, bail, gaveUp = new Promise(function (ok) { bail = ok; });
    var sk = document.createElement('button'); sk.type = 'button'; sk.className = 'btn line sm vskip'; sk.textContent = 'Skip, upload original'; sk.onclick = function () { bail(); }; note.appendChild(sk);
    var dog = setInterval(function () { var now = Date.now(); if (pct !== last) { last = pct; moved = now; } if (now - moved > 10000 || (now - t0 > 20000 && pct < 10)) bail(); }, 1000);
    var quit = false; gaveUp.then(function () { quit = true; clearInterval(dog); try { stopNow && stopNow.cancel(); } catch (e) {} });
    var shrunk = fast(file).then(function (b) { return b && b.size < file.size ? playable(b).then(function (y) { if (y) how = 'fast ' + b.type; return y ? b : null; }) : null; })
      .then(function (b) { if (quit) return Promise.reject(); return b || (can ? shrink(file).then(function (r) { return playable(r).then(function (y) { if (y) how = 'recorded ' + r.type; return y ? r : Promise.reject(); }); }) : Promise.reject()); });
    shrunk.catch(function () {});
    Promise.race([shrunk, gaveUp.then(function () { how = 'as is (skipped)'; return Promise.reject(); })]).then(function (b) {
      clearInterval(dog); sk.remove();
      send(new File([b], file.name.replace(/\.\w+$/, '') + '.' + (/mp4/.test(b.type) ? 'mp4' : 'webm'), {type: b.type}));
    }, function () {
      clearInterval(dog); sk.remove(); bail();
      if (max && file.size > max) { btn.disabled = false; btn.textContent = 'Upload'; note.hidden = true; tooBig(file); return; }
      send(null);
    });
  });
})();
