// Admin → Territory War: preset buttons fill the country list.
document.querySelectorAll('[data-teams]').forEach(function (b) {
  b.addEventListener('click', function () { document.getElementById('teams').value = b.dataset.teams; });
});
