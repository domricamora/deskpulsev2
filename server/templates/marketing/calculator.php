<?php
/**
 * /tools/cost-calculator — a genuinely useful free tool, and the campaign's highest-value
 * linkable asset. Works entirely client-side from server-rendered price data, so it needs
 * no API, no build step and no third-party script.
 *
 * The prices come from plan_base_prices() and competitor_prices(), so the tool can never
 * disagree with /pricing, the JSON-LD or /llms.txt.
 */
$m = fn($v) => money_short((float) $v);
$capSeats = plan_cap_seats($prices);
$payload = [
    'perSeat'  => (float) $prices['per_seat'],
    'cap'      => (float) $prices['seat_cap'],
    'minSeats' => max(1, (int) $prices['seats_bill_min']),
    'capSeats' => $capSeats,
    'vendors'  => array_map(fn($v) => ['name' => $v['name'], 'tier' => $v['tier'],
                                       'perSeat' => $v['per_seat'], 'note' => $v['note']], $vendors),
];
?><!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/_head.php'; ?>
</head>
<body class="public landing">
<?php include __DIR__ . '/_nav.php'; ?>

<section class="hero compact">
  <span class="aurora" aria-hidden="true"></span>
  <div class="hero-in" data-reveal>
    <span class="kicker">Free tool</span>
    <h1>Monitoring cost calculator</h1>
    <p class="lead">How much your team&rsquo;s time-tracking software actually costs, per seat and
      per year, against every major vendor. No signup, no email.</p>
  </div>
</section>

<section class="features" id="calc">
  <div class="calc-wrap" data-reveal>
    <div class="calc-controls">
      <label for="calc-seats"><b>How many people do you track?</b></label>
      <input type="range" id="calc-seats" min="1" max="150" value="20" step="1" class="calc-range">
      <div class="calc-seatrow">
        <input type="number" id="calc-seats-num" min="1" max="2000" value="20" class="calc-num"
               aria-label="Seat count">
        <span class="muted">seats</span>
      </div>
      <p class="muted small" id="calc-note"></p>
    </div>

    <div class="calc-headline">
      <div class="calc-big">
        <span class="lbl">DeskPulse</span>
        <b id="calc-dp">—</b>
        <span class="sub" id="calc-dp-seat"></span>
      </div>
      <div class="calc-big alt">
        <span class="lbl">You&rsquo;d save per year</span>
        <b id="calc-save">—</b>
        <span class="sub" id="calc-save-vs"></span>
      </div>
    </div>

    <table class="data" data-nofilter id="calc-table">
      <thead>
        <tr><th>Product</th><th>Tier</th><th>Per seat</th><th>Per month</th><th>Per year</th><th>vs DeskPulse</th></tr>
      </thead>
      <tbody></tbody>
    </table>
    <p class="muted small">Competitor figures are published list prices for the named tier,
      checked <?= e($vendors[0]['checked']) ?>. Add-on modules (analytics, extra storage) are not
      included, so a real invoice is usually higher than shown here. DeskPulse has a
      <?= (int) $payload['minSeats'] ?>-seat minimum on the Team plan
      <?php if ($capSeats > 0): ?>and stops at <?= e($m($prices['seat_cap'])) ?>, which is reached at
      <?= (int) $capSeats ?> seats<?php endif; ?>.</p>
  </div>
</section>

<section class="cta-band">
  <div class="cta-in" data-reveal>
    <h2 id="calc-cta-head">Stop paying per head</h2>
    <p>Everything is included at every price — screenshots, roles, client billing, payroll and
      the audit log. No card to start.</p>
    <div class="cta-row">
      <a class="btn lg" href="<?= e(url('/register?plan=per_seat')) ?>">Start a free trial</a>
      <a class="btn lg ghost" href="<?= e(url('/pricing')) ?>">See full pricing</a>
    </div>
  </div>
</section>

<?php include __DIR__ . '/_foot.php'; ?>
<script id="dp-prices" type="application/json"><?= json_encode($payload, JSON_UNESCAPED_SLASHES) ?></script>
<script>
(function () {
  var P = JSON.parse(document.getElementById('dp-prices').textContent);
  var range = document.getElementById('calc-seats');
  var num = document.getElementById('calc-seats-num');
  var tbody = document.querySelector('#calc-table tbody');
  var fmt = function (v) {
    return '$' + v.toLocaleString('en-US', { minimumFractionDigits: v % 1 ? 2 : 0,
                                             maximumFractionDigits: 2 });
  };
  // Mirrors plan_seat_price() in dashboard.php: floor, multiply, cap.
  function dpPrice(seats) {
    var billed = Math.max(P.minSeats, Math.max(1, seats));
    var total = P.perSeat * billed;
    return P.cap > 0 ? Math.min(total, P.cap) : total;
  }
  function render(seats) {
    var dp = dpPrice(seats);
    document.getElementById('calc-dp').textContent = fmt(dp) + '/mo';
    document.getElementById('calc-dp-seat').textContent =
      fmt(Math.round((dp / seats) * 100) / 100) + ' per seat · ' + fmt(dp * 12) + '/year';

    var rows = P.vendors.map(function (v) {
      var mo = v.perSeat * seats;
      return { name: v.name, tier: v.tier, perSeat: v.perSeat, mo: mo, yr: mo * 12,
               diff: mo - dp };
    }).sort(function (a, b) { return b.mo - a.mo; });

    var worst = rows[0];
    document.getElementById('calc-save').textContent = fmt(Math.max(0, (worst.mo - dp) * 12));
    document.getElementById('calc-save-vs').textContent = 'against ' + worst.name +
      ' (' + worst.tier + ')';

    tbody.innerHTML = '<tr class="row-self"><td><b>DeskPulse</b></td><td>' +
      (P.cap > 0 && seats >= P.capSeats ? 'Organization (cap)' : 'Team') +
      '</td><td>' + fmt(Math.round((dp / seats) * 100) / 100) + '</td><td><b>' + fmt(dp) +
      '</b></td><td><b>' + fmt(dp * 12) + '</b></td><td>—</td></tr>' +
      rows.map(function (r) {
        return '<tr><td>' + r.name + '</td><td>' + r.tier + '</td><td>' + fmt(r.perSeat) +
          '</td><td>' + fmt(r.mo) + '</td><td>' + fmt(r.yr) + '</td><td><b>+' +
          fmt(r.diff * 12) + '</b>/yr</td></tr>';
      }).join('');

    var note = document.getElementById('calc-note');
    if (seats < P.minSeats) {
      note.textContent = 'The Team plan starts at ' + P.minSeats + ' seats (' +
        fmt(dpPrice(P.minSeats)) + '/mo). Below that, look at Solo or Individual.';
    } else if (P.cap > 0 && seats >= P.capSeats) {
      note.textContent = 'Your bill has hit the cap. Every additional seat is free.';
    } else if (P.cap > 0) {
      note.textContent = 'At ' + P.capSeats + ' seats your bill reaches ' + fmt(P.cap) +
        ' and stops — that is ' + (P.capSeats - seats) + ' more people from here.';
    } else {
      note.textContent = '';
    }
    document.getElementById('calc-cta-head').textContent =
      'Save ' + fmt(Math.max(0, (worst.mo - dp) * 12)) + ' a year on ' + seats + ' seats';
  }
  function sync(v, from) {
    var seats = Math.max(1, Math.min(2000, parseInt(v, 10) || 1));
    if (from !== 'range') { range.value = Math.min(seats, range.max); }
    if (from !== 'num') { num.value = seats; }
    render(seats);
    // One event per settled interaction, not per pixel of drag.
    clearTimeout(window.__dpCalcT);
    window.__dpCalcT = setTimeout(function () {
      if (window.gtag) { gtag('event', 'calculator_used', { seats: seats }); }
    }, 800);
  }
  range.addEventListener('input', function () { sync(range.value, 'range'); });
  num.addEventListener('input', function () { sync(num.value, 'num'); });
  render(parseInt(num.value, 10));
})();
</script>
<script src="<?= e(url('/assets/js/reveal.js')) ?>" defer></script>
<?php /* tables.js wraps every table.data in its scroll container — without it the
         calculator's wide result table pushes the whole page sideways. */ ?>
<script src="<?= e(url('/assets/js/tables.js')) ?>" defer></script>
</body>
</html>
