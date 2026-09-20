/* Live view: poll the JSON endpoint and re-render live agents.
 *  - mode "platform" (super admin): Organization → Team → Client → agents
 *  - mode "team" (managers/admins):  Client → Team → agents
 *
 * Ported from the legacy assets/js/live.js. One deviation: the activity bar's
 * width was an inline style="width:N%" attribute in the markup string, which
 * decision D13 rules out. The bar is built as an element and its width set
 * through the CSSOM instead — the same pixels, and not an inline style
 * attribute for a Content-Security-Policy to reject.
 */
(function () {
  const grid = document.getElementById('live-grid');
  if (!grid) return;

  const endpoint = grid.getAttribute('data-endpoint');
  const updated = document.getElementById('live-updated');
  const EMPTY = 'No employees are tracking right now.';

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function card(m) {
    const node = el('div', 'live-card');

    const heading = el('h4', null, m.name || '');
    heading.appendChild(el('span', 'pill on', '● live'));
    node.appendChild(heading);

    const current = el('div', 'cur');
    current.appendChild(el('b', null, m.app || 'Unknown'));
    current.appendChild(document.createElement('br'));
    current.appendChild(document.createTextNode((m.title || '').slice(0, 60)));
    node.appendChild(current);

    const bar = el('div', 'bar');
    const fill = document.createElement('i');
    fill.style.width = (m.activity || 0) + '%';
    bar.appendChild(fill);
    node.appendChild(bar);

    node.appendChild(el('small', 'muted', (m.activity || 0) + '% active now'));

    if (m.screenshot) {
      const img = document.createElement('img');
      img.src = m.screenshot;
      img.alt = 'latest screenshot';
      node.appendChild(img);
    }

    node.appendChild(el('div', 'muted live-today',
      'Today: ' + (m.today_active || '') + ' · ' + (m.today_pct || 0) + '% active'));

    return node;
  }

  function cardGrid(members) {
    const wrap = el('div', 'live-grid');
    (members || []).forEach(m => wrap.appendChild(card(m)));
    return wrap;
  }

  function section(title, count) {
    const node = el('section', 'live-client');
    const head = el('div', 'live-client-head');
    head.appendChild(el('h3', null, title));
    head.appendChild(el('span', 'status approved', count + ' live'));
    node.appendChild(head);
    return node;
  }

  function subHead(className, label, count) {
    return el('div', className, label + ' · ' + count);
  }

  // ── team mode: client → team → agents ──
  function clientGroup(g) {
    const node = section(g.client, g.count);

    (g.teams || []).forEach(t => {
      const team = el('div', 'live-team');
      team.appendChild(subHead('live-team-head', t.team, (t.members || []).length));
      team.appendChild(cardGrid(t.members));
      node.appendChild(team);
    });

    return node;
  }

  // ── platform mode: organization → team → client → agents ──
  function orgGroup(o) {
    const node = section(o.org, o.count);

    (o.teams || []).forEach(t => {
      const team = el('div', 'live-team');
      team.appendChild(subHead('live-team-head', t.team, t.count));

      (t.clients || []).forEach(c => {
        const client = el('div', 'live-client-sub');
        client.appendChild(subHead('live-client-subhead', c.client, (c.members || []).length));
        client.appendChild(cardGrid(c.members));
        team.appendChild(client);
      });

      node.appendChild(team);
    });

    return node;
  }

  function render(data) {
    const groups = data.mode === 'platform' ? (data.orgs || []) : (data.groups || []);
    const build = data.mode === 'platform' ? orgGroup : clientGroup;

    grid.replaceChildren();

    if (!groups.length) {
      grid.appendChild(el('p', 'muted', EMPTY));
      return;
    }

    groups.forEach(g => grid.appendChild(build(g)));
  }

  function refresh() {
    fetch(endpoint, { headers: { 'X-Requested-With': 'fetch' } })
      .then(r => r.json())
      .then(data => {
        render(data);
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
