(function () {
  var t = document.getElementById('title'), d = document.getElementById('desc'), c = document.getElementById('tc');
  var pt = document.getElementById('pv-title'), pd = document.getElementById('pv-desc'), img = document.getElementById('pv-img');
  var f = document.getElementById('image'), orig = img.src;
  function up() {
    var n = Array.from(t.value).length;
    c.textContent = n + ' / 100'; c.className = 'count' + (n > 100 ? ' bad' : '');
    pt.textContent = t.value; pd.textContent = d.value;
  }
  t.addEventListener('input', up); d.addEventListener('input', up); up();
  // Make the picture 1280 x 720 JPG on the phone before sending (big photos fail to upload on mobile data).
  var data = document.getElementById('image_data');
  f.addEventListener('change', function () {
    data.value = '';
    var file = f.files[0];
    if (!file) return;
    document.querySelector('input[name=thumb][value=own]').checked = true;
    var url = URL.createObjectURL(file);
    img.src = url; img.style.visibility = 'visible';
    var pic = new Image();
    pic.onload = function () {
      var cv = document.createElement('canvas'); cv.width = 1280; cv.height = 720;
      var w = pic.naturalWidth, h = pic.naturalHeight, cw = w, ch = Math.round(w * 9 / 16);
      if (ch > h) { ch = h; cw = Math.round(h * 16 / 9); }
      var g = cv.getContext('2d');
      g.fillStyle = '#000'; g.fillRect(0, 0, 1280, 720);
      g.drawImage(pic, (w - cw) / 2, (h - ch) / 2, cw, ch, 0, 0, 1280, 720);
      var q = 0.9, out = cv.toDataURL('image/jpeg', q);
      while (out.length > 2.4e6 && q > 0.5) { q -= 0.1; out = cv.toDataURL('image/jpeg', q); } // stay well under 2 MB
      data.value = out;
      img.src = out;
    };
    pic.src = url;
  });
  f.form.addEventListener('submit', function (e) {
    if (data.value) f.disabled = true;         // send the small copy, not the big original
    var b = e.submitter; if (b) { setTimeout(function () { b.disabled = true; b.textContent = 'Working… please wait'; }, 0); }
  });
  document.querySelectorAll('input[name=thumb]').forEach(function (r) {
    r.addEventListener('change', function () {
      img.style.visibility = r.value === 'none' ? 'hidden' : 'visible';
      if (r.value === 'default') img.src = 'yt_thumb.php?id=' + new URLSearchParams(location.search).get('id') + '&def=1&r=' + Date.now();
      if (r.value === 'own' && f.files[0]) img.src = URL.createObjectURL(f.files[0]);
      else if (r.value === 'own') img.src = orig;
    });
  });
})();
