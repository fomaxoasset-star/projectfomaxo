/* Upload from your phone: a big or iPhone (.mov) video is made smaller in the browser first (720p, about 10 MB for 30 seconds,
   a format every phone and computer plays), then uploaded as usual. Browsers that can't do it upload the video as it is. */
(function () {
  var f = document.querySelector('form[data-vshrink]'); if (!f) return;
  var inp = f.querySelector('input[type=file][name=video]'), max = +f.dataset.max || 0, MB = 1048576, note = f.querySelector('.vsh'), btn = f.querySelector('.btn');
  var mime = ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm'].filter(function (m) { return window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m); })[0];
  var say = function (t) { note.hidden = false; note.querySelector('span').textContent = t; };
  var tooBig = function (file) { alert('This video is ' + Math.round(file.size / MB) + ' MB. Please use one under ' + Math.round(max / MB) + ' MB.'); };
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
    if (!big || !can) { if (max && file.size > max) { e.preventDefault(); tooBig(file); return; } btn.textContent = 'Uploading…'; return; }
    e.preventDefault(); btn.disabled = true; setTimeout(function () { btn.textContent = 'Please wait…'; });
    shrink(file).then(function (b) {
      var dt = new DataTransfer(); dt.items.add(new File([b], file.name.replace(/\.\w+$/, '') + '.' + (/mp4/.test(b.type) ? 'mp4' : 'webm'), {type: b.type})); inp.files = dt.files;
      say('Uploading… ' + Math.max(1, Math.round(b.size / MB)) + ' MB'); btn.textContent = 'Uploading…'; f.dataset.go = 1; f.submit();
    }, function () {
      if (max && file.size > max) { btn.disabled = false; btn.textContent = 'Upload'; note.hidden = true; tooBig(file); return; }
      say('Uploading…'); btn.textContent = 'Uploading…'; f.dataset.go = 1; f.submit();
    });
  });
})();
