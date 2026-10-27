// Fingerprint / face login for the admin (passkeys). The fingerprint stays on the device.
(() => {
  const b2u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const u2b = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0));
  const ls = { get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } } };
  const supported = () => !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
  const api = async (q, body, csrf) => {
    const r = await fetch('passkey.php?do=' + q, body ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf || '' }, body: JSON.stringify(body) }
      : { headers: { 'X-CSRF': csrf || '' }, cache: 'no-store' });
    const j = await r.json().catch(() => ({ error: 'Server error' }));
    if (!r.ok || j.error) throw new Error(j.error || 'Server error');
    return j;
  };
  const ua = navigator.userAgent;
  const guessName = async () => {
    let n = '';
    try {
      const h = await navigator.userAgentData?.getHighEntropyValues?.(['model']);
      const p = navigator.userAgentData?.platform || '';
      if (p === 'Android') n = h?.model ? (/^SM-/i.test(h.model) ? 'Samsung ' : '') + h.model : 'Android phone';
      else if (p === 'Windows') n = 'Windows PC (Windows Hello)';
      else if (p === 'macOS') n = 'Mac (Touch ID)';
    } catch (e) { /* ignore */ }
    if (!n) n = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) ? 'iPad' : /Android/.test(ua) ? 'Android phone' : /Windows/.test(ua) ? 'Windows PC (Windows Hello)' : /Mac/.test(ua) ? 'Mac (Touch ID)' : 'My device';
    return n;
  };

  /* ---------- add this device ---------- */
  const register = async (csrf) => {
    const o = await api('reg_opts', null, csrf);
    const cred = await navigator.credentials.create({ publicKey: {
      challenge: u2b(o.challenge), rp: { id: o.rpId, name: o.rpName },
      user: { id: u2b(o.userId), name: o.userName, displayName: o.userName + ' (GameStream Admin)' },
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
      authenticatorSelection: { authenticatorAttachment: 'platform', residentKey: 'required', requireResidentKey: true, userVerification: 'required' },
      excludeCredentials: (o.exclude || []).map((id) => ({ type: 'public-key', id: u2b(id) })),
      timeout: 120000, attestation: 'none',
    } });
    const j = await api('reg', { id: cred.id, clientDataJSON: b2u(cred.response.clientDataJSON), attestationObject: b2u(cred.response.attestationObject), name: await guessName() }, csrf);
    ls.set('fft_pk', '1');
    return j.name;
  };
  // show the browser's own reason too, so a problem on one phone can be found
  const why = (e) => (e && e.name && e.name !== 'Error' ? ' [' + e.name + (e.message ? ': ' + String(e.message).slice(0, 120) : '') + ']' : '');
  const errText = (e) => e && e.name === 'InvalidStateError' ? 'Fingerprint login is already on for this device.'
    : e && e.name === 'NotAllowedError' ? 'Not done — it was cancelled or the phone did not allow it. Close other floating windows, use Chrome and try again.' + why(e)
    : ((e && e.message) || 'Something went wrong.') + why(e);

  /* ---------- login page ---------- */
  const btn = document.getElementById('pk-login');
  if (btn) {
    const msg = document.getElementById('pk-msg');
    const say = (t, bad) => { msg.textContent = t; msg.hidden = !t; msg.className = 'flash ' + (bad ? 'flash-error' : 'flash-ok'); };
    if (!supported()) { document.getElementById('pk-box').hidden = true; return; }
    let busy = false;
    const login = async (auto) => {
      if (busy) return; busy = true; btn.disabled = true; say('');
      try {
        const o = await api('login_opts');
        const a = await navigator.credentials.get({ publicKey: { challenge: u2b(o.challenge), rpId: o.rpId, userVerification: 'required', timeout: 120000, allowCredentials: [] } });
        const j = await api('login', { id: a.id, clientDataJSON: b2u(a.response.clientDataJSON), authenticatorData: b2u(a.response.authenticatorData), signature: b2u(a.response.signature) });
        ls.set('fft_pk', '1');
        location.href = j.go || 'dashboard.php';
        return;
      } catch (e) {
        if (!(auto && e && e.name === 'NotAllowedError')) say(e && e.name === 'NotAllowedError' ? 'Cancelled. Tap the button to try again, or use the password.' : errText(e), true);
        if (e && /not known/.test(e.message || '')) ls.set('fft_pk', '0');
      }
      busy = false; btn.disabled = false;
    };
    btn.addEventListener('click', () => login(false));
    if (ls.get('fft_pk') === '1') setTimeout(() => login(true), 350);
  }

  /* ---------- "Use fingerprint next time?" after a password login ---------- */
  const offer = document.getElementById('pk-offer');
  if (offer && supported() && ls.get('fft_pk') !== '1' && !(Number(ls.get('fft_pk_no')) > Date.now() - 30 * 86400000)) {
    PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable?.().then((ok) => {
      if (!ok) return;
      offer.hidden = false;
      const m = offer.querySelector('.pk-m');
      offer.querySelector('[data-no]').onclick = () => { ls.set('fft_pk_no', String(Date.now())); offer.hidden = true; };
      offer.querySelector('[data-yes]').onclick = async (ev) => {
        if (!document.hasFocus()) { m.textContent = 'Close the floating window (the small window on top) first, then tap again.'; return; }
        ev.target.disabled = true;
        m.textContent = 'Wait — the phone opens its fingerprint box…';
        const hint = setTimeout(() => { m.textContent = 'Nothing showed up? Close every floating / small window, then tap again.'; ev.target.disabled = false; }, 12000);
        try {
          const n = await register(offer.dataset.csrf);
          m.textContent = '✓ Done! Next time just touch the sensor (' + n + ').';
          setTimeout(() => { offer.hidden = true; }, 2200);
        } catch (e) { m.textContent = errText(e); ev.target.disabled = false; }
        clearTimeout(hint);
      };
    }).catch(() => {});
  }

  /* ---------- Settings → Add this device ---------- */
  const add = document.getElementById('pk-add');
  if (add) {
    const m = document.getElementById('pk-add-msg');
    if (!supported()) { add.disabled = true; m.textContent = 'This browser cannot use fingerprint login.'; }
    add.addEventListener('click', async () => {
      if (!document.hasFocus()) { m.textContent = 'Close the floating window (the small window on top) first, then tap again.'; return; }
      add.disabled = true; m.textContent = 'Wait — the phone opens its fingerprint box…';
      setTimeout(() => { if (add.disabled) { m.textContent = 'Nothing showed up? Close every floating / small window, then tap again.'; add.disabled = false; } }, 12000);
      try { const n = await register(add.dataset.csrf); m.textContent = '✓ Added: ' + n; setTimeout(() => location.reload(), 900); }
      catch (e) { m.textContent = errText(e); add.disabled = false; }
    });
  }
})();
