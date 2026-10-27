// FFT Admin as an app on the phone / PC: Chrome installs it (a real app with its own icon), no APK file needed.
(() => {
  if ('serviceWorker' in navigator && location.pathname.startsWith('/admin/')) {
    navigator.serviceWorker.register('/admin/sw.js', { scope: '/admin/' }).catch(() => {});
  }
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  if (standalone) return;
  let ask = null;
  const no = () => { try { return Number(localStorage.getItem('fft_app_no')) > Date.now() - 7 * 86400000; } catch (e) { return false; } };
  const chip = document.createElement('div');
  chip.className = 'app-chip';
  chip.hidden = true;
  chip.innerHTML = '<img src="/admin/app/icon-192.png" alt=""><div><b>Install FFT Admin app</b><span>Opens like an app · fingerprint login</span></div>'
    + '<button type="button" class="btn btn-blue btn-sm" data-i>Install</button><button type="button" class="app-x" data-x aria-label="Close">✕</button>';
  document.body.appendChild(chip);
  chip.querySelector('[data-x]').onclick = () => { chip.hidden = true; try { localStorage.setItem('fft_app_no', String(Date.now())); } catch (e) { /* ignore */ } };
  chip.querySelector('[data-i]').onclick = async () => {
    if (!ask) return;
    ask.prompt();
    const r = await ask.userChoice.catch(() => null);
    ask = null; chip.hidden = true;
    if (r && r.outcome === 'dismissed') try { localStorage.setItem('fft_app_no', String(Date.now())); } catch (e) { /* ignore */ }
  };
  addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    ask = e;
    if (!no() && !document.getElementById('pk-offer')?.offsetParent) chip.hidden = false;
  });
  addEventListener('appinstalled', () => { chip.hidden = true; });
})();
