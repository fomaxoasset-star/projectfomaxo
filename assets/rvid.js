/* FOMAXO — a customer's review video, made ready in their own browser before it is posted (this file loads only when they pick a video).
   1. a cover photo from the video, 2. the video made about 5 MB or less (720p, same way as admin uploads) when it is bigger or not an MP4,
   3. a small silent preview (640px, first 8 seconds) for the product page's video row, so the row never loads the full video.
   Uses admin/mediabunny.js (WebCodecs). A browser that can't keeps the video as it is when it is 20 MB or less.
   Nothing is asked of the customer: they only pick a file. */
(function () {
  var MB = 1048576, MAX_SEC = 60, RAW_MAX = 20 * MB, GOAL = 4.6 * MB * 8, AB = 96000, PSEC = 8, PBR = 600000;
  var M = null;
  var lib = function () { return M ? Promise.resolve(M) : import(new URL('admin/mediabunny.js?v=1.59.1', document.baseURI).href).then(function (m) { return (M = m); }); };
  var limit = function (p, ms, stop) { var t; return Promise.race([p, new Promise(function (ok) { t = setTimeout(function () { try { stop && stop(); } catch (e) {} ok(null); }, ms); })]).then(function (r) { clearTimeout(t); return r; }); };
  var TOO_LONG = 'Please choose a video of 60 seconds or less.', TOO_BIG = 'This video is too big. Please choose a shorter one, up to 60 seconds.';

  /* the length, and a cover photo (a frame a quarter of the way in), from the browser's own player */
  function look(file) {
    return new Promise(function (ok) {
      var v = document.createElement('video'), u = URL.createObjectURL(file), done = false, d = 0;
      var end = function (cover, bad) { if (done) return; done = true; clearTimeout(t); v.onloadedmetadata = v.onseeked = v.onerror = null; v.removeAttribute('src'); v.load(); URL.revokeObjectURL(u); ok({dur: d, cover: cover || null, bad: !!bad}); };
      var t = setTimeout(function () { end(null); }, 15000);
      v.muted = true; v.playsInline = true; v.setAttribute('playsinline', ''); v.preload = 'auto';
      v.onloadedmetadata = function () { d = isFinite(v.duration) ? v.duration : 0; v.currentTime = Math.max(.1, Math.min(2, d / 4)); };
      v.onseeked = function () {
        var w = v.videoWidth, h = v.videoHeight; if (!w || !h) return end(null);
        var z = Math.min(1, 1280 / Math.max(w, h)), c = document.createElement('canvas'); c.width = Math.round(w * z); c.height = Math.round(h * z);
        try { c.getContext('2d').drawImage(v, 0, 0, c.width, c.height); } catch (e) { return end(null); }
        c.toBlob(function (b) { end(b && b.size ? b : null); }, 'image/jpeg', .86);
      };
      v.onerror = function () { end(null, true); };
      v.src = u;
    });
  }
  /* one conversion with mediabunny; null when this browser can't */
  function convert(blob, make, say) {
    var conv = null;
    return limit(lib().then(function (M) {
      var src = new M.Input({source: new M.BlobSource(blob), formats: M.ALL_FORMATS});
      return Promise.all([src.getPrimaryVideoTrack(), src.computeDuration().catch(function () { return 0; }), src.getPrimaryAudioTrack().catch(function () { return null; })]).then(function (r) {
        if (!r[0]) return null;
        return make(M, src, r[0], r[1], r[2]).then(function (o) {
          if (!o) return null;
          var out = new M.Output({format: new M.Mp4OutputFormat({fastStart: 'in-memory'}), target: new M.BufferTarget()});
          return M.Conversion.init(Object.assign({input: src, output: out, showWarnings: false}, o)).then(function (c) {
            conv = c; if (!c.isValid) return null;
            if (r[2] && !(o.audio && o.audio.discard) && c.discardedTracks.some(function (x) { return x.track.type === 'audio'; })) return null;   // the sound would be lost
            if (say) c.onProgress = function (p) { say(Math.min(99, Math.round(p * 100))); };
            return c.execute().then(function () { var b = out.target.buffer; return b && b.byteLength ? new Blob([b], {type: 'video/mp4'}) : null; });
          });
        });
      });
    }).catch(function () { return null; }), 180000, function () { conv && conv.cancel(); });
  }
  function shrink(file, sec, say) {
    return convert(file, function (M, src, t, d, at) {
      d = d || sec || 30;
      var br = Math.max(300000, Math.min(2500000, Math.floor(GOAL / Math.max(1, d)) - AB)), L = br < 350000 ? 720 : br < 700000 ? 960 : 1280;
      var k = Math.min(1, L / Math.max(t.displayWidth, t.displayHeight)), w = Math.round(t.displayWidth * k / 2) * 2, h = Math.round(t.displayHeight * k / 2) * 2;
      return Promise.all([M.getFirstEncodableVideoCodec(['avc', 'hevc', 'vp9'], {width: w, height: h}), window.AudioEncoder ? M.getFirstEncodableAudioCodec(['aac', 'opus']).catch(function () { return null; }) : null]).then(function (cs) {
        if (!cs[0]) return null;
        return {video: {width: w, height: h, fit: 'contain', codec: cs[0], bitrate: br, forceTranscode: true}, audio: cs[1] ? {codec: cs[1], bitrate: AB} : at ? {codec: at.codec} : undefined};
      });
    }, say);
  }
  function preview(blob) {
    return convert(blob, function (M, src, t, d) {
      var k = Math.min(1, 640 / Math.max(t.displayWidth, t.displayHeight)), w = Math.round(t.displayWidth * k / 2) * 2, h = Math.round(t.displayHeight * k / 2) * 2;
      return M.canEncodeVideo('avc', {width: w, height: h}).then(function (ok) {   // the row needs H.264 so every phone plays it
        return ok ? {video: {width: w, height: h, fit: 'contain', codec: 'avc', bitrate: PBR, forceTranscode: true}, audio: {discard: true}, trim: d > PSEC ? {start: 0, end: PSEC} : undefined} : null;
      });
    });
  }
  /* say(text) shows how far it is. Gives {video, name, prev, cover} or fails with a message for the customer */
  window.fxRvid = function (file, say) {
    say = say || function () {};
    say('Preparing your video…');
    return look(file).then(function (m) {
      if (m.dur > MAX_SEC + 1) throw new Error(TOO_LONG);
      var mp4 = /^video\/mp4$/i.test(file.type) || /\.mp4$/i.test(file.name);
      var need = file.size > 5 * MB || !mp4;
      var made = need && window.VideoEncoder ? shrink(file, m.dur, function (p) { say('Preparing your video… ' + p + '%'); }) : Promise.resolve(null);
      return made.then(function (small) {
        var v = small && (small.size < file.size || !mp4) ? small : null;
        if (!v && file.size > RAW_MAX) throw new Error(TOO_BIG);
        if (!v && m.bad) throw new Error('This video could not be opened. Please choose another one.');
        var video = v || file;
        if (!window.VideoEncoder) return {video: video, name: v ? 'video.mp4' : file.name || 'video.mp4', prev: null, cover: m.cover};
        say('Preparing your video… 99%');
        return preview(video).then(function (p) { return {video: video, name: v ? 'video.mp4' : file.name || 'video.mp4', prev: p && p.size <= 3 * MB ? p : null, cover: m.cover}; });
      });
    });
  };
})();
