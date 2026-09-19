/* Live view: poll the JSON endpoint and re-render live agents.
 *  - mode "platform" (super admin): Organization → Team → Client → agents
 *  - mode "team" (managers/admins):  Client → Team → agents */
(function () {
  const grid = document.getElementById('live-grid');
  if (!grid) return;
  const endpoint = grid.getAttribute('data-endpoint');
  const updated = document.getElementById('live-updated');
  const EMPTY = '<p class="muted">No employees are tracking right now.</p>';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, c => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }

  function card(m) {
    const body = '<div class="cur"><b>' + esc(m.app || 'Unknown') + '</b><br>' +
      esc((m.title || '').slice(0, 60)) + '</div>' +
      '<div class="bar"><i style="width:' + (m.activity || 0) + '%"></i></div>' +
      '<small class="muted">' + (m.activity || 0) + '% active now</small>';
    const shot = m.screenshot
      ? '<img src="' + esc(m.screenshot) + '" alt="latest screenshot">' : '';
    return '<div class="live-card">' +
      '<h4>' + esc(m.name) + ' <span class="pill on">● live</span></h4>' +
      body + shot +
      '<div class="muted" style="margin-top:.5rem">Today: ' + esc(m.today_active) +
      ' · ' + (m.today_pct || 0) + '% active</div>' +
      '</div>';
  }

  const grid_ = members => '<div class="live-grid">' + members.map(card).join('') + '</div>';
  const head = (label, count) =>
    '<span class="muted">· ' + count + '</span>';

  // ── team mode: client → team → agents ──
  function teamSub(t) {
    return '<div class="live-team"><div class="live-team-head">' +
      esc(t.team) + ' ' + head(t.team, t.members.length) + '</div>' +
      grid_(t.members) + '</div>';
  }
  function clientGroup(g) {
    return '<section class="live-client"><div class="live-client-head"><h3>' +
      esc(g.client) + '</h3><span class="status approved">' + g.count + ' live</span></div>' +
      g.teams.map(teamSub).join('') + '</section>';
  }

  // ── platform mode: organization → team → client → agents ──
  function clientSub(c) {
    return '<div class="live-client-sub"><div class="live-client-subhead">' +
      esc(c.client) + ' ' + head(c.client, c.members.length) + '</div>' +
      grid_(c.members) + '</div>';
  }
  function teamPlatform(t) {
    return '<div class="live-team"><div class="live-team-head">' +
      esc(t.team) + ' ' + head(t.team, t.count) + '</div>' +
      t.clients.map(clientSub).join('') + '</div>';
  }
  function orgGroup(o) {
    return '<section class="live-client"><div class="live-client-head"><h3>' +
      esc(o.org) + '</h3><span class="status approved">' + o.count + ' live</span></div>' +
      o.teams.map(teamPlatform).join('') + '</section>';
  }

  function refresh() {
    fetch(endpoint, { headers: { 'X-Requested-With': 'fetch' } })
      .then(r => r.json())
      .then(data => {
        let html;
        if (data.mode === 'platform') {
          html = (data.orgs || []).map(orgGroup).join('');
        } else {
          html = (data.groups || []).map(clientGroup).join('');
        }
        grid.innerHTML = html || EMPTY;
        if (updated) {
          updated.textContent = (data.total || 0) + ' live · updated ' +
            new Date().toLocaleTimeString();
        }
      })
      .catch(() => { if (updated) updated.textContent = 'connection lost — retrying'; });
  }

  refresh();
  setInterval(refresh, 15000);
})();
