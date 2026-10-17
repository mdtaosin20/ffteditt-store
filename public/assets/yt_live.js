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
  f.addEventListener('change', function () {
    if (f.files[0]) { document.querySelector('input[name=thumb][value=own]').checked = true; img.src = URL.createObjectURL(f.files[0]); img.style.visibility = 'visible'; }
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
