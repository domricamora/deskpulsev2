<?php
$logged_in = current_user();

// Standard subscription prices, configured by the super admin on Platform settings.
$prices = plan_base_prices();
// Big, clean price label: drop the ".00" on whole amounts.
$priceLabel = function (float $p): string {
    return '$' . (fmod($p, 1.0) === 0.0 ? number_format($p, 0) : number_format($p, 2));
};

// Inline SVG icon set (Lucide-style, stroke = currentColor). No emoji, no CDN.
$I = [
  'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  'activity'=>'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
  'window'=>'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M6.5 6.5h.01M9.5 6.5h.01"/>',
  'image'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
  'tasks'=>'<path d="m9 11 3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/>',
  'approve'=>'<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
  'billing'=>'<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
  'briefcase'=>'<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
  'live'=>'<circle cx="12" cy="12" r="2"/><path d="M7.8 7.8a6 6 0 0 0 0 8.4M16.2 16.2a6 6 0 0 0 0-8.4M4.9 4.9a10 10 0 0 0 0 14.2M19.1 19.1a10 10 0 0 0 0-14.2"/>',
  'shield'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
  'shield-check'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
  'sliders'=>'<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M2 14h4M10 8h4M18 16h4"/>',
  'share'=>'<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
  'chart'=>'<path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6" rx="1"/><rect x="12" y="7" width="3" height="10" rx="1"/><rect x="17" y="13" width="3" height="4" rx="1"/>',
  'eye'=>'<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
  'eye-off'=>'<path d="M9.9 4.2A10 10 0 0 1 12 4c6.5 0 10 7 10 7a18 18 0 0 1-2.3 3.2M6.1 6.1A18 18 0 0 0 2 11s3.5 7 10 7a10 10 0 0 0 4-.8"/><path d="m4 4 16 16"/><path d="M9.5 9.5a3 3 0 0 0 4.2 4.2"/>',
  'server'=>'<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
  'check'=>'<path d="M20 6 9 17l-5-5"/>',
  'star'=>'<path d="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.2l5.9-.9Z"/>',
  'arrow'=>'<path d="M5 12h14M13 6l6 6-6 6"/>',
  'fingerprint'=>'<rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><path d="m10.3 15.4 1.4 1.4 2.6-2.6"/>',
  'agents'=>'<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M5.5 16.5a3.5 3.5 0 0 1 7 0"/><path d="M15 9.5h4M15 13h3"/>',
  'schedule'=>'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M12 14v3l2 1"/>',
  'payslip'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
];
$svg = function (string $name) use ($I): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($I[$name] ?? '') . '</svg>';
};
$meta = marketing_meta([
    'path'        => '/',
    'title'       => 'DeskPulse — Remote Work Monitoring & Time Tracking for Teams & Individuals',
    'description' => 'DeskPulse is remote-work monitoring and time-tracking software for teams and '
        . 'individuals. Automatic time tracking, activity & idle detection, app and window insights, '
        . 'screenshots, per-task time, client billing, salary cost reports, and shareable summaries.',
    'keywords'    => 'remote work monitoring, time tracking, employee monitoring, productivity '
        . 'tracking, screenshots, activity tracking, staffing, client billing, timesheets, BPO',
    'og_title'    => 'DeskPulse — Remote Work Monitoring for Teams & Individuals',
    'og_desc'     => 'Automatic time tracking, activity, screenshots, per-task time, client billing '
        . 'and shareable reports — for any remote team or individual.',
    'jsonld'      => [
        schema_software($prices),
        schema_faq(marketing_home_faqs()),
        schema_organization(),
    ],
]);
?><!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/_head.php'; ?>
</head>
<body class="public landing">

<?php include __DIR__ . '/_nav.php'; ?>

<!-- ░░ HERO — asymmetric split, deep→light blue, glossy app mockup ░░ -->
<section class="hero">
  <span class="aurora" aria-hidden="true"></span>
  <canvas id="hero-globe" aria-hidden="true"></canvas>
  <div class="hero-grid">
    <div class="hero-copy" data-reveal>
      <span class="kicker"><?= $svg('star') ?> Remote-work intelligence</span>
      <h1>Maximize efficiency, <span class="grad">cut your losses</span>,<br>monitor your agents.</h1>
      <p class="lead">DeskPulse tracks time, activity, apps, tasks and screenshots from a lightweight
        desktop agent and turns it into clear dashboards, client billing and accountability —
        so less time leaks and more work ships. Built for <strong>BPOs, outsourcing teams</strong>
        and <strong>individuals</strong>.</p>
    </div>

    <div class="hero-mock" data-reveal aria-hidden="true">
      <div class="mock-bar"><i></i><i></i><i></i><span class="mock-url">deskpulse · overview</span></div>
      <div class="mock-screen">
        <div class="mock-stat"><span class="k">Tracked today</span><span class="v">7h 42m</span></div>
        <div class="mock-stat"><span class="k">Activity</span><span class="v">86%</span></div>
        <div class="mock-stat"><span class="k">Billable</span><span class="v">$1,240</span></div>
        <div class="mock-stat"><span class="k">Live now</span><span class="v">12</span></div>
        <div class="mock-chart">
          <b style="height:42%"></b><b style="height:66%"></b><b style="height:54%"></b><b style="height:80%"></b>
          <b style="height:60%"></b><b style="height:92%"></b><b style="height:74%"></b><b style="height:50%"></b>
          <b style="height:70%"></b><b style="height:88%"></b><b style="height:64%"></b><b style="height:78%"></b>
        </div>
      </div>
    </div>
  </div>
  <div class="hero-actions" data-reveal>
    <div class="cta-row">
      <a class="btn lg" href="<?= e(url('/register')) ?>">Create your workspace <?= $svg('arrow') ?></a>
      <a class="btn lg ghost" href="<?= e(url('/download')) ?>">Download the app</a>
    </div>
    <ul class="hero-trust">
      <li><?= $svg('check') ?> Free to start</li>
      <li><?= $svg('check') ?> Windows, macOS &amp; Linux</li>
      <li><?= $svg('check') ?> Your data, your workspace</li>
    </ul>
  </div>
</section>

<!-- ░░ METRICS — glassy band overlapping hero ░░ -->
<section class="metrics" data-reveal>
  <div class="metrics-in">
    <div class="metric"><span class="n" data-count="7">0</span><span class="l">Capability-based roles</span></div>
    <div class="metric"><span class="n" data-count="15" data-suffix="+">0</span><span class="l">Signals captured live</span></div>
    <div class="metric"><span class="n" data-count="3">0</span><span class="l">Platforms · Win · macOS · portable</span></div>
    <div class="metric"><span class="n" data-count="100" data-suffix="%">0</span><span class="l">Your data, isolated per org</span></div>
  </div>
</section>

<!-- ░░ FEATURES — icon-badge bento grid ░░ -->
<section id="features" class="features">
  <div class="section-head" data-reveal>
    <span class="eyebrow">Platform</span>
    <h2>Everything you need to understand remote work</h2>
    <p class="sub">One agent, one server, every signal — captured transparently and turned into decisions.</p>
  </div>
  <!-- gradient (teal→blue) applied to the feature icons' strokes via CSS url(#dpico) -->
  <svg width="0" height="0" class="dp-defs" aria-hidden="true" focusable="false"><defs>
    <linearGradient id="dpico" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#2dd4bf"/><stop offset="1" stop-color="#3b82f6"/>
    </linearGradient>
  </defs></svg>
  <div class="feat-cats">
    <?php
    // One card per capability group. Centered: a large gradient icon, the CATEGORY as
    // the header, the feature title beneath it, and a short blurb that slides down on
    // hover (shown by default on touch). [icon, category, title, blurb]
    $cats = [
      ['chart','Analytics','Unified dashboards','Every signal on role-based dashboards — per member, team &amp; client.'],
      ['activity','Tracking','Time tracking','Automatic sessions where only genuine human input counts.'],
      ['image','Insights','Apps &amp; windows','A zoomable activity timeline with screenshot previews.'],
      ['schedule','Payroll','Overtime &amp; pay','Overtime approval and pay from real tracked time.'],
      ['billing','Billing','Client billing','Per-agent hourly or flat monthly, rolled up per customer.'],
      ['live','Oversight','Live &amp; remote','See who&rsquo;s working now &mdash; and step in when needed.'],
      ['shield','Security','Roles &amp; audit','Fine-grained roles (RBAC) with a full audit trail.'],
      ['share','Sharing','Share &amp; export','Public read-only links, CSV export and your own branding.'],
    ];
    foreach ($cats as [$ic, $cat, $t, $d]): ?>
      <article class="fcat" data-reveal>
        <div class="fcat-main">
          <span class="cat-ico"><?= $svg($ic) ?></span>
          <span class="cat-eyebrow"><?= $cat ?></span>
          <h3><?= $t ?></h3>
        </div>
        <p class="cat-desc"><?= $d ?></p>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<!-- ░░ PRODUCT TOUR — screenshot carousel ░░ -->
<section class="showcase-carousel" id="tour">
  <div class="section-head" data-reveal>
    <span class="eyebrow">Product tour</span>
    <h2>See DeskPulse in action</h2>
    <p class="sub">The real dashboards your team works in every day.</p>
  </div>
  <div class="carousel" data-reveal>
    <div class="carousel-track">
      <div class="tour-chrome" aria-hidden="true"><i></i><i></i><i></i><span class="tour-url"><?= $svg('live') ?> app.deskpulse.click</span></div>
      <?php
      // Fourth element is the alt text. These were CSS background-images, which meant
      // six real product screenshots were invisible to crawlers, link unfurlers, image
      // search and directory reviewers — the audit reported "zero images" on a page that
      // visibly has six. They are <img> now, with descriptive alt.
      $slides = [
        ['screens/overview.webp', 'Agent dashboard', 'Time, activity, apps, tasks, screenshots and pay — for one person, a team or the whole organization.',
         'DeskPulse agent dashboard showing tracked hours, activity percentage, application breakdown and recent screenshots for a single worker'],
        ['screens/team.webp', 'Team performance at a glance', 'Every member\'s activity/inactivity split as an instant donut chart, right alongside pay, bill rate and labor cost.',
         'DeskPulse team performance dashboard listing each member with an activity donut chart, tracked hours, pay rate, bill rate and labour cost'],
        ['screens/agent_detail.webp', 'Deep-dive any agent', 'A full per-agent profile: rates, devices, assigned clients, tasks, an activity timeline and a performance donut.',
         'DeskPulse per-agent profile page showing pay and bill rates, registered devices, assigned clients, task list and an activity timeline'],
        ['screens/efficiency.webp', 'Efficiency & effectiveness report', 'Rank your team by activity and task-completion — a blended effectiveness score built for performance reviews.',
         'DeskPulse efficiency report ranking team members by activity percentage and task completion into a blended effectiveness score'],
        ['screens/payroll.webp', 'Payroll & payslips', 'Per-day pay computed from genuine tracked time, plus an organization-wide salary breakdown for admins & HR.',
         'DeskPulse payroll page showing per-day pay computed from tracked time with an organization-wide salary breakdown'],
        ['screens/clients.webp', 'Clients & contracts', 'Quote a standard rate per client or contract, while every employee keeps their own individual bill rate.',
         'DeskPulse clients and contracts page showing per-client contract rates alongside individual employee bill rates'],
      ];
      foreach ($slides as $i => [$src, $t, $d, $alt]): ?>
        <figure class="slide<?= $i === 0 ? ' on' : '' ?>">
          <img class="slide-img" src="<?= e(url('/assets/img/' . $src)) ?>" alt="<?= e($alt) ?>"
               width="1280" height="1000" <?= $i === 0 ? '' : 'loading="lazy"' ?> decoding="async">
          <figcaption><h3><?= e($t) ?></h3><p><?= e($d) ?></p></figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
    <button type="button" class="carousel-nav prev" aria-label="Previous">&lsaquo;</button>
    <button type="button" class="carousel-nav next" aria-label="Next">&rsaquo;</button>
    <div class="carousel-dots">
      <?php foreach ($slides as $i => $s): ?>
        <button type="button" class="dot<?= $i === 0 ? ' on' : '' ?>" data-i="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>"></button>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ░░ HOW IT WORKS — horizontal stepper over faint office image ░░ -->
<section id="how" class="how">
  <div class="how-in">
    <div class="section-head light" data-reveal>
      <span class="eyebrow">Onboarding</span>
      <h2>Live in four steps</h2>
      <p class="sub">From sign-up to dashboards in an afternoon — not a quarter.</p>
    </div>
    <div class="step-track">
      <div class="step" data-reveal><span class="num">1</span><h3>Create your workspace</h3>
        <p>Sign up in seconds — it spins up your private, multi-tenant organization.</p></div>
      <div class="step" data-reveal><span class="num">2</span><h3>Add your team &amp; roles</h3>
        <p>Invite people, assign roles and teams, set pay/bill rates and your monitoring policy.</p></div>
      <div class="step" data-reveal><span class="num">3</span><h3>Install the desktop app</h3>
        <p>Workers install the Windows or macOS agent and sign in. Tracking runs only on Start.</p></div>
      <div class="step" data-reveal><span class="num">4</span><h3>Watch the dashboards</h3>
        <p>Time, activity, tasks, screenshots, billing, live status and audit logs flow in.</p></div>
    </div>
  </div>
</section>

<!-- ░░ ROLES — accent cards ░░ -->
<section id="roles" class="features alt">
  <div class="section-head" data-reveal>
    <span class="eyebrow">Access control</span>
    <h2>A role for everyone</h2>
    <p class="sub">Capability-based access — people only see and do what their role allows.</p>
  </div>
  <div class="role-grid">
    <?php
    $roles = [
      ['shield','Company admin','Full control of one organization — team, clients, billing, settings and reports.'],
      ['live','Team manager','Their assigned team only: live view, reports, screenshots and timesheet approvals.'],
      ['approve','HR manager','Attendance, productivity, approvals and profiles — no screenshots, billing or rates.'],
      ['sliders','IT admin','Devices, audit log and the monitoring policy — no payroll or screenshots.'],
      ['clock','Employee','Their own dashboard, timesheets, tasks and profile.'],
      ['eye','Client / viewer','Read-only, team-scoped reports for outsourcing clients — no management.'],
    ];
    foreach ($roles as [$ic, $t, $d]): ?>
      <article class="card role" data-reveal><span class="role-ico"><?= $svg($ic) ?></span>
        <div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div></article>
    <?php endforeach; ?>
  </div>
</section>

<!-- ░░ PRICING — two standard plans, org featured (in the showcase slot) ░░ -->
<section id="pricing" class="pricing">
  <div class="section-head" data-reveal>
    <span class="eyebrow">Pricing</span>
    <h2>Per-seat pricing that stops</h2>
    <p class="sub"><?= e(money_short((float) $prices['per_seat'])) ?> a seat &mdash; and your bill
      never goes past <?= e(money_short((float) $prices['seat_cap'])) ?>.</p>
  </div>
  <?php include __DIR__ . '/_pricing.php'; ?>
  <p class="price-foot muted" data-reveal>
    <a href="<?= e(url('/pricing')) ?>">Full pricing details, FAQ and the seat-by-seat comparison &rarr;</a>
  </p>
</section>

<!-- ░░ PRIVACY — over faint tech image ░░ -->
<section id="privacy" class="privacy">
  <div class="privacy-in">
    <div class="section-head light" data-reveal>
      <span class="eyebrow">Trust</span>
      <h2>Built for transparency</h2>
      <p class="sub">Monitoring people demands the highest bar. DeskPulse sets it.</p>
    </div>
    <div class="role-grid three">
      <article class="card glass" data-reveal><span class="feat-ico"><?= $svg('eye') ?></span>
        <h3>Consent-first</h3><p>Tracking runs only while the worker presses Start, with a persistent
          “● Monitoring” indicator. No silent background spying.</p></article>
      <article class="card glass" data-reveal><span class="feat-ico"><?= $svg('eye-off') ?></span>
        <h3>Privacy controls</h3><p>Screenshots can be blurred, are never shown on public links, and
          rates are hidden from employees. Access is capability-gated per role.</p></article>
      <article class="card glass" data-reveal><span class="feat-ico"><?= $svg('server') ?></span>
        <h3>Your data, isolated</h3><p>Multi-tenant and isolated per organization — every workspace&rsquo;s
          data is kept separate and private.</p></article>
    </div>
  </div>
</section>

<!-- ░░ CTA BAND ░░ -->
<section class="cta-band">
  <div class="cta-in" data-reveal>
    <h2>Set the standard for your operation.</h2>
    <p>Join the teams running remote work on DeskPulse. Start free in minutes.</p>
    <div class="cta-row">
      <a class="btn lg" href="<?= e(url('/register')) ?>">Get started free <?= $svg('arrow') ?></a>
      <a class="btn lg ghost" href="<?= e(url('/login')) ?>">Sign in to your dashboard</a>
    </div>
  </div>
</section>

<?php include __DIR__ . '/_foot.php'; ?>

<script>
(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var revealEls = document.querySelectorAll('[data-reveal]');
  var nums = document.querySelectorAll('[data-count]');

  function showAll() { revealEls.forEach(function (el) { el.classList.add('in'); }); }
  function fillNums() { nums.forEach(function (n) { n.textContent = n.getAttribute('data-count') + (n.getAttribute('data-suffix') || ''); }); }

  if (reduce || !('IntersectionObserver' in window)) { showAll(); fillNums(); return; }

  // Sticky header: compact + glow once the page is scrolled.
  var top = document.querySelector('.landing .pub-top');
  if (top) {
    var onScroll = function () { top.classList.toggle('scrolled', window.scrollY > 12); };
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  }

  // Stagger siblings within the same grid so reveals cascade.
  ['.feat-cats', '.step-track', '.role-grid', '.metrics-in'].forEach(function (sel) {
    document.querySelectorAll(sel).forEach(function (grid) {
      [].slice.call(grid.querySelectorAll('[data-reveal]')).forEach(function (el, k) {
        el.style.setProperty('--rd', (k * 60) + 'ms');
      });
    });
  });

  var ro = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); ro.unobserve(en.target); } });
  }, { threshold: 0.12 });
  revealEls.forEach(function (el) { ro.observe(el); });

  function countUp(el) {
    var target = parseFloat(el.getAttribute('data-count'));
    var suffix = el.getAttribute('data-suffix') || '';
    var dur = 1100, t0 = null;
    function step(ts) {
      if (!t0) t0 = ts;
      var p = Math.min((ts - t0) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * eased) + suffix;
      if (p < 1) requestAnimationFrame(step); else el.textContent = target + suffix;
    }
    requestAnimationFrame(step);
  }
  var no = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { if (en.isIntersecting) { countUp(en.target); no.unobserve(en.target); } });
  }, { threshold: 0.6 });
  nums.forEach(function (n) { no.observe(n); });

  // Product-tour carousel.
  var car = document.querySelector('.carousel');
  if (car) {
    var slides = [].slice.call(car.querySelectorAll('.slide'));
    var dots = [].slice.call(car.querySelectorAll('.dot'));
    var i = 0, timer = null;
    function show(n) {
      i = (n + slides.length) % slides.length;
      slides.forEach(function (s, k) { s.classList.toggle('on', k === i); });
      dots.forEach(function (d, k) { d.classList.toggle('on', k === i); });
    }
    function restart() { if (reduce) return; clearInterval(timer); timer = setInterval(function () { show(i + 1); }, 5500); }
    car.querySelector('.next').addEventListener('click', function () { show(i + 1); restart(); });
    car.querySelector('.prev').addEventListener('click', function () { show(i - 1); restart(); });
    dots.forEach(function (d) { d.addEventListener('click', function () { show(+d.getAttribute('data-i')); restart(); }); });
    restart();
  }

  // Scroll-to-top button.
  var toTop = document.createElement('button');
  toTop.type = 'button'; toTop.className = 'to-top'; toTop.setAttribute('aria-label', 'Back to top');
  toTop.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
  document.body.appendChild(toTop);
  window.addEventListener('scroll', function () { toTop.classList.toggle('show', window.scrollY > 400); }, { passive: true });
  toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); });
})();
</script>
<script src="<?= e(url('/assets/js/hero-globe.js')) ?>" defer></script>
</body>
</html>
