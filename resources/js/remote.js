/* Remote desktop control console.
 * Starts a control session, polls its status, streams the latest JPEG frame, and
 * relays mouse/keyboard input to the agent. Dependency-free IIFE in the style of
 * live.js. All URLs are built from data-* attributes rendered with url() so the
 * app base path is honored.
 *
 * Ported from server/public/assets/js/remote.js. One change: the CSRF field is
 * named _token rather than _csrf, because that is what Laravel's VerifyCsrfToken
 * reads. The batch still travels as a JSON string in `payload` rather than as a
 * JSON body, so the token arrives as an ordinary form field.
 *
 * The capability is `remote` — company and IT admins hold it too. The legacy
 * header here said "super-admin only", which was already stale. */
(function () {
  const panel = document.getElementById('remote-panel');
  if (!panel) return;
  const startUrl = panel.getAttribute('data-start-url');
  const base = panel.getAttribute('data-base');        // .../app/remote/
  const csrf = panel.getAttribute('data-csrf');
  const startBtn = document.getElementById('remote-start');
  const stopBtn = document.getElementById('remote-stop');
  const pill = document.getElementById('remote-pill');
  const frame = document.getElementById('remote-frame');
  const wait = document.getElementById('remote-wait');

  let sessionId = null;
  let statusTimer = null;
  let inputTimer = null;
  let lastSeq = -1;
  let queue = [];          // discrete events (down/up/click/scroll/key)
  let pendingMove = null;  // coalesced latest mousemove
  let lastMoveAt = 0;

  function form(obj) {
    const p = new URLSearchParams();
    p.set('_token', csrf);
    for (const k in obj) p.set(k, obj[k]);
    return p;
  }
  function setPill(t) { pill.textContent = t; }

  // ── session lifecycle ──
  function start() {
    startBtn.disabled = true;
    setPill('Starting…');
    fetch(startUrl, { method: 'POST', body: form({}), headers: { 'X-Requested-With': 'fetch' } })
      .then(r => r.json())
      .then(d => {
        sessionId = d.session_id;
        lastSeq = -1;
        queue = [];
        pendingMove = null;
        stopBtn.disabled = false;
        wait.hidden = false;
        setPill('Connecting…');
        clearInterval(statusTimer);
        statusTimer = setInterval(poll, 1000);
        poll();
        clearInterval(inputTimer);
        inputTimer = setInterval(flushInput, 100);
      })
      .catch(() => { startBtn.disabled = false; setPill('Failed to start'); });
  }

  function poll() {
    if (!sessionId) return;
    fetch(base + sessionId + '/status', { headers: { 'X-Requested-With': 'fetch' } })
      .then(r => r.json())
      .then(s => {
        if (s.status === 'ended') { setPill('Ended'); teardown(); return; }
        if (s.status === 'pending') { setPill('Waiting for agent…'); return; }
        setPill('● Live control');
        if (s.seq && s.seq !== lastSeq) {
          lastSeq = s.seq;
          frame.src = base + sessionId + '/frame?seq=' + s.seq;
          frame.style.display = '';
          wait.hidden = true;
        }
      })
      .catch(() => setPill('connection lost — retrying'));
  }

  function stop() {
    if (!sessionId) { teardown(); return; }
    fetch(base + sessionId + '/stop', { method: 'POST', body: form({}),
      headers: { 'X-Requested-With': 'fetch' } }).catch(() => {}).then(teardown);
  }

  function teardown() {
    clearInterval(statusTimer); statusTimer = null;
    clearInterval(inputTimer); inputTimer = null;
    sessionId = null;
    stopBtn.disabled = true;
    startBtn.disabled = false;
  }

  // ── input capture (attached once; handlers no-op while no session) ──
  function norm(e) {
    const r = frame.getBoundingClientRect();
    const w = r.width || 1, h = r.height || 1;
    return {
      x: Math.min(1, Math.max(0, (e.clientX - r.left) / w)),
      y: Math.min(1, Math.max(0, (e.clientY - r.top) / h)),
    };
  }
  function button(e) {
    return e.button === 2 ? 'right' : (e.button === 1 ? 'middle' : 'left');
  }
  function active() { return sessionId !== null; }

  frame.addEventListener('mousemove', e => {
    if (!active()) return;
    const now = Date.now();
    if (now - lastMoveAt < 50) { const n = norm(e); pendingMove = { t: 'move', x: n.x, y: n.y }; return; }
    lastMoveAt = now;
    const n = norm(e);
    pendingMove = { t: 'move', x: n.x, y: n.y };
  });
  frame.addEventListener('mousedown', e => {
    if (!active()) return;
    const n = norm(e); queue.push({ t: 'down', button: button(e), x: n.x, y: n.y });
  });
  frame.addEventListener('mouseup', e => {
    if (!active()) return;
    const n = norm(e); queue.push({ t: 'up', button: button(e), x: n.x, y: n.y });
  });
  frame.addEventListener('click', e => {
    if (!active()) return;
    const n = norm(e); queue.push({ t: 'click', button: button(e), x: n.x, y: n.y });
  });
  frame.addEventListener('contextmenu', e => {
    e.preventDefault();
    if (!active()) return;
    const n = norm(e); queue.push({ t: 'click', button: 'right', x: n.x, y: n.y });
  });
  frame.addEventListener('wheel', e => {
    if (!active()) return;
    e.preventDefault();
    queue.push({ t: 'scroll', dy: e.deltaY > 0 ? -1 : 1 });
  }, { passive: false });

  document.addEventListener('keydown', e => {
    if (!active()) return;
    if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
      queue.push({ t: 'key', action: 'type', text: e.key });
    } else {
      queue.push({ t: 'key', action: 'press', key: e.key });
    }
    // Keep control keys from acting on the browser while controlling.
    if (e.key === 'Tab' || e.key === 'Backspace' || e.ctrlKey || e.metaKey) e.preventDefault();
  });

  function flushInput() {
    if (!sessionId) return;
    const events = [];
    if (pendingMove) { events.push(pendingMove); pendingMove = null; }
    if (queue.length) { for (const ev of queue) events.push(ev); queue = []; }
    if (!events.length) return;
    fetch(base + sessionId + '/input', {
      method: 'POST',
      body: form({ payload: JSON.stringify({ events: events }) }),
      headers: { 'X-Requested-With': 'fetch' },
    }).catch(() => {});
  }

  startBtn.addEventListener('click', start);
  stopBtn.addEventListener('click', stop);
})();
