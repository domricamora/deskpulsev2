/* Agent-detail modal for the platform Organizations tree. Clicking an
 * .agent-link fetches /app/platform/user/<id> and shows it in a modal. */
(function () {
  const modal = document.getElementById('user-modal');
  if (!modal) return;
  const base = modal.getAttribute('data-base');
  const remoteBase = modal.getAttribute('data-remote-base');
  const body = document.getElementById('um-body');
  const title = document.getElementById('um-title');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, c => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }
  const row = (k, v) => '<dt>' + esc(k) + '</dt><dd>' + v + '</dd>';

  function open(id) {
    title.textContent = 'Loading…';
    body.innerHTML = '<p class="muted">Loading…</p>';
    modal.hidden = false;
    fetch(base + id, { headers: { 'X-Requested-With': 'fetch' } })
      .then(r => r.json())
      .then(d => {
        title.textContent = d.name;
        const pill = d.live ? '<span class="pill on">● live</span>' : '';
        const list = a => (a && a.length ? a.map(esc).join(', ') : '—');
        const devices = (d.devices && d.devices.length)
          ? d.devices.map(x => esc(x.name) +
              (remoteBase ? ' — <a class="remote-link" href="' + remoteBase + x.id +
                '">Remote control</a>' : '')).join('<br>')
          : 'none';
        body.innerHTML =
          '<div class="um-sub">' + esc(d.role) + (d.org ? ' · ' + esc(d.org) : '') + ' ' + pill + '</div>' +
          '<dl class="kv">' +
          row('Email', esc(d.email)) +
          row('Phone', esc(d.phone)) +
          row('Job title', esc(d.job_title)) +
          row('Teams', esc(list(d.teams))) +
          row('Clients', esc(list(d.clients))) +
          (d.pay ? row('Pay rate', esc(d.pay)) : '') +
          (d.bill ? row('Bill rate', esc(d.bill)) : '') +
          row('Today', esc(d.today.active) + ' · ' + d.today.pct + '% active') +
          row('This week', esc(d.week.active) + ' · ' + d.week.pct + '% · ' + d.week.sessions + ' sessions') +
          row('Total tracked', esc(d.total_active)) +
          row('Open tasks', d.open_tasks) +
          row('Devices', devices) +
          row('Last seen', d.last_seen ? esc(d.last_seen) + ' UTC' : '—') +
          '</dl>';
      })
      .catch(() => { body.innerHTML = '<p class="muted">Could not load details.</p>'; });
  }
  function close() { modal.hidden = true; }

  document.addEventListener('click', e => {
    const btn = e.target.closest('.agent-link');
    if (btn) { open(btn.getAttribute('data-user-id')); return; }
    if (e.target === modal || e.target.id === 'um-close') { close(); }
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
})();
