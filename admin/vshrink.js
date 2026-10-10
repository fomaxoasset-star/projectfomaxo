/* Upload from your phone: a big or iPhone (.mov) video is made smaller in the browser first, then uploaded as usual.
   Fast way: the video is re-encoded straight from the file (admin/mediabunny.js, WebCodecs), several times faster than playing it,
   1080p, about 20 MB for 30 seconds. Browsers without it re-record the video while it plays (720p). Neither works = upload as it is. */
(function () {
  var f = document.querySelector('form[data-vshrink]'); if (!f) return;
  var inp = f.querySelector('input[type=file][name=video]'), max = +f.dataset.max || 0, MB = 1048576, note = f.querySelector('.vsh'), btn = f.querySelector('.btn');
  var mime = ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm'].filter(function (m) { return window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m); })[0];
  var say = function (t) { note.hidden = false; note.querySelector('span').textContent = t; };
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
          conv = c; if (!c.isValid) return null;
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
  f.addEventListener('submit', function (e) {
    var file = inp.files[0]; if (!file || f.dataset.go) return;
    var big = file.size > 30 * MB || /quicktime/i.test(file.type) || /\.mov$/i.test(file.name);
    var can = mime && window.DataTransfer && HTMLCanvasElement.prototype.captureStream;
    if (!big || !window.DataTransfer || (!can && !window.VideoEncoder)) { if (max && file.size > max) { e.preventDefault(); tooBig(file); return; } btn.textContent = 'Uploading…'; return; }
    e.preventDefault(); btn.disabled = true; setTimeout(function () { btn.textContent = 'Please wait…'; });
    fast(file).then(function (b) { return b && b.size < file.size ? b : (can ? shrink(file) : Promise.reject()); }).then(function (b) {
      var dt = new DataTransfer(); dt.items.add(new File([b], file.name.replace(/\.\w+$/, '') + '.' + (/mp4/.test(b.type) ? 'mp4' : 'webm'), {type: b.type})); inp.files = dt.files;
      say('Uploading… ' + Math.max(1, Math.round(b.size / MB)) + ' MB'); btn.textContent = 'Uploading…'; f.dataset.go = 1; f.submit();
    }, function () {
      if (max && file.size > max) { btn.disabled = false; btn.textContent = 'Upload'; note.hidden = true; tooBig(file); return; }
      say('Uploading…'); btn.textContent = 'Uploading…'; f.dataset.go = 1; f.submit();
    });
  });
})();
