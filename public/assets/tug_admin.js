// Admin → Tug of War: preset buttons fill the pair list.
document.querySelectorAll('[data-pairs]').forEach(function (b) {
  b.addEventListener('click', function () { document.getElementById('pairs').value = b.dataset.pairs; });
});
