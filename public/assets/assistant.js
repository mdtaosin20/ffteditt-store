// Admin → Ask Claude: the chat page.
(() => {
  const root = document.getElementById('cl');
  if (!root) return;
  const chat = document.getElementById('cl-chat'), form = document.getElementById('cl-form'), text = document.getElementById('cl-text');
  const send = document.getElementById('cl-send'), usd = document.getElementById('cl-usd');
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  // a little Markdown: **bold**, `code`, lists, line breaks
  const md = (s) => esc(s)
    .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/^#{1,4}\s*(.+)$/gm, '<b>$1</b>')
    .replace(/^\s*[-*•]\s+(.+)$/gm, '<span class="li">• $1</span>')
    .replace(/\n/g, '<br>').replace(/<\/span><br>/g, '</span>');
  let busy = false;
  const api = async (body) => {
    try {
      const r = await fetch('assistant_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': root.dataset.csrf }, body: JSON.stringify(body) });
      return await r.json();
    } catch (e) {   // the internet dropped, or the answer took too long: never leave the box locked
      return { error: 'উত্তর আসেনি (ইন্টারনেট বা সময় শেষ)। একটু পরে আবার পাঠান।' };
    }
  };
  const render = (j, thinking) => {
    const items = j.items || [];
    let h = '';
    if (!items.length && !thinking) h = '<div class="cl-empty muted">Write a problem below, e.g. “Trollzen লাইভে চ্যাট কাউন্ট হচ্ছে না”.</div>';
    for (const it of items) {
      if (it.k === 'me') h += `<div class="m u">${esc(it.t).replace(/\n/g, '<br>')}</div>`;
      else if (it.k === 'ai') h += `<div class="m a"><div class="who"><i></i>Claude</div>${md(it.t)}</div>`;
      else if (it.k === 'look') h += `<div class="look">${esc(it.t)}</div>`;
      else if (it.k === 'act') {
        h += `<div class="act ${it.res || (it.ask ? 'ask' : '')}"><b>${it.ask ? '⚡ আমি এটা করতে চাই:' : it.res === 'done' ? '✅ করা হয়েছে:' : it.res === 'no' ? '✕ আপনি না বলেছেন:' : '⚠️ হয়নি:'}</b>${esc(it.t)}`
          + (it.why ? `<div class="why">${esc(it.why)}</div>` : '')
          + (it.ask ? '<div class="r"><button type="button" class="btn btn-green btn-sm" data-yes="1">✅ হ্যাঁ, করো</button><button type="button" class="btn btn-ghost btn-sm" data-yes="0">✕ না</button></div>' : '')
          + (it.res === 'fail' && it.msg ? `<div class="why">${esc(it.msg)}</div>` : '') + '</div>';
      }
    }
    if (thinking) h += '<div class="m a thinking"><div class="who"><i></i>Claude</div><span class="dots"><i></i><i></i><i></i></span> দেখছি…</div>';
    if (j.error) h += `<div class="flash flash-error">${esc(j.error)}</div>`;
    chat.innerHTML = h;
    if (j.usd !== undefined && usd) usd.textContent = '$' + Number(j.usd).toFixed(2);
    const waiting = !!j.waiting;
    // the box is never locked: you can always type; only Send waits while Claude answers or a card waits for Yes / No
    text.disabled = false;
    send.disabled = busy || waiting;
    text.placeholder = waiting ? 'আগে উপরের কার্ডে হ্যাঁ বা না চাপুন' : 'সমস্যাটা লিখুন…';
    chat.scrollTop = chat.scrollHeight;
    window.scrollTo(0, document.body.scrollHeight);
  };
  let last = { items: [] };
  const go = async (body, optimistic) => {
    if (busy) return;
    busy = true;
    if (optimistic) last = { ...last, items: [...(last.items || []), optimistic], error: '' };
    render(last, true);
    const j = await api(body);
    busy = false;
    last = j.items ? j : { ...last, error: j.error || 'Something went wrong.' };
    if (!j.items) setTimeout(() => api({ do: 'view' }).then((v) => { if (v.items && !busy) { last = v; render(last, false); } }), 4000);   // fetch what really happened
    render(last, false);
  };
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const t = text.value.trim(); if (!t || busy || last.waiting) return;
    text.value = '';
    go({ do: 'send', text: t }, { k: 'me', t });
  });
  text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !matchMedia('(pointer: coarse)').matches) { e.preventDefault(); form.requestSubmit(); } });
  chat.addEventListener('click', (e) => { const b = e.target.closest('[data-yes]'); if (b) go({ do: 'answer', yes: b.dataset.yes === '1' }); });
  document.getElementById('cl-q').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b && !busy) { text.value = b.textContent; form.requestSubmit(); } });
  document.getElementById('cl-new').addEventListener('click', () => { if (confirm('Start a new chat? (the old one is cleared)')) go({ do: 'reset' }); });
  go({ do: 'view' });
})();
