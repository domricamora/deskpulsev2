/* DeskPulse hero — 3D global network. Remote workstations distributed on a rotating
   globe stream data to one central hub. Dependency-free canvas 2D, retina-aware, and
   fully static under prefers-reduced-motion. */
(function () {
  var canvas = document.getElementById('hero-globe');
  if (!canvas || !canvas.getContext) return;
  var ctx = canvas.getContext('2d');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var BLUE = '#3b82f6', TEAL = '#2dd4bf', LIGHT = '#7dd3fc';
  var W = 0, H = 0, DPR = 1, cx = 0, cy = 0, R = 0;
  var tiltX = -0.42;              // fixed viewing tilt
  var rot = 0.6;                  // current Y rotation (radians)
  var NODE_COUNT = 64;
  var nodes = [];
  var links = [];                 // subset of nodes wired to the hub, each with a traveling packet

  // Fibonacci-sphere distribution → evenly spread "workstations".
  function buildNodes() {
    nodes = [];
    var golden = Math.PI * (3 - Math.sqrt(5));
    for (var i = 0; i < NODE_COUNT; i++) {
      var y = 1 - (i / (NODE_COUNT - 1)) * 2;      // 1 .. -1
      var r = Math.sqrt(1 - y * y);
      var theta = golden * i;
      nodes.push({ x: Math.cos(theta) * r, y: y, z: Math.sin(theta) * r });
    }
    links = [];
    for (var k = 4; k < NODE_COUNT; k += 5) {       // ~12 active connections
      links.push({ node: k, p: Math.random(), speed: 0.006 + Math.random() * 0.012 });
    }
  }

  function resize() {
    var rect = canvas.getBoundingClientRect();
    W = Math.max(1, rect.width); H = Math.max(1, rect.height);
    DPR = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(W * DPR); canvas.height = Math.round(H * DPR);
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    // Globe is centered within its own (upper-right) canvas box, scaled large.
    cx = W * 0.5;
    cy = H * 0.46;
    R = Math.min(W, H) * 0.46;
  }

  // Rotate a unit-sphere point by the current Y rotation + fixed X tilt, then project.
  function project(p) {
    var cosR = Math.cos(rot), sinR = Math.sin(rot);
    var x1 = p.x * cosR - p.z * sinR;
    var z1 = p.x * sinR + p.z * cosR;
    var cosT = Math.cos(tiltX), sinT = Math.sin(tiltX);
    var y2 = p.y * cosT - z1 * sinT;
    var z2 = p.y * sinT + z1 * cosT;
    var persp = 1 / (1.9 - z2 * 0.55);              // subtle perspective
    return { x: cx + x1 * R * persp, y: cy + y2 * R * persp, z: z2, s: persp };
  }

  function meridians() {
    ctx.lineWidth = 1;
    var i, j, a, b, pt, first;
    // Parallels (latitude rings)
    for (i = -2; i <= 2; i++) {
      var lat = i * 0.62;
      ctx.beginPath(); first = true;
      for (j = 0; j <= 48; j++) {
        a = (j / 48) * Math.PI * 2;
        pt = project({ x: Math.cos(a) * Math.cos(lat), y: Math.sin(lat), z: Math.sin(a) * Math.cos(lat) });
        if (first) { ctx.moveTo(pt.x, pt.y); first = false; } else ctx.lineTo(pt.x, pt.y);
      }
      ctx.strokeStyle = 'rgba(96,165,250,0.10)'; ctx.stroke();
    }
    // Meridians (longitude arcs)
    for (i = 0; i < 6; i++) {
      var lon = (i / 6) * Math.PI;
      ctx.beginPath(); first = true;
      for (j = 0; j <= 48; j++) {
        b = (j / 48) * Math.PI * 2;
        pt = project({ x: Math.cos(lon) * Math.sin(b), y: Math.cos(b), z: Math.sin(lon) * Math.sin(b) });
        if (first) { ctx.moveTo(pt.x, pt.y); first = false; } else ctx.lineTo(pt.x, pt.y);
      }
      ctx.strokeStyle = 'rgba(45,212,191,0.08)'; ctx.stroke();
    }
  }

  function drawHub() {
    var pulse = reduce ? 0.5 : (0.5 + 0.5 * Math.sin(rot * 2.2));
    var glow = 22 + pulse * 16;
    var g = ctx.createRadialGradient(cx, cy, 0, cx, cy, glow);
    g.addColorStop(0, 'rgba(45,212,191,0.9)');
    g.addColorStop(0.4, 'rgba(59,130,246,0.5)');
    g.addColorStop(1, 'rgba(59,130,246,0)');
    ctx.fillStyle = g;
    ctx.beginPath(); ctx.arc(cx, cy, glow, 0, Math.PI * 2); ctx.fill();
    ctx.save();
    ctx.shadowColor = TEAL; ctx.shadowBlur = 18;
    ctx.fillStyle = '#eafffb';
    ctx.beginPath(); ctx.arc(cx, cy, 4.5, 0, Math.PI * 2); ctx.fill();
    ctx.restore();
  }

  function frame() {
    ctx.clearRect(0, 0, W, H);
    meridians();

    // Projected node cache
    var proj = nodes.map(project);

    // Connection lines + traveling data packets (node → hub)
    ctx.lineCap = 'round';
    for (var i = 0; i < links.length; i++) {
      var L = links[i], pt = proj[L.node];
      var frontFade = 0.25 + 0.55 * ((pt.z + 1) / 2);
      ctx.strokeStyle = 'rgba(59,130,246,' + (0.10 + frontFade * 0.12).toFixed(3) + ')';
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(pt.x, pt.y); ctx.lineTo(cx, cy); ctx.stroke();
      if (!reduce) { L.p += L.speed; if (L.p > 1) L.p -= 1; }
      var px = pt.x + (cx - pt.x) * L.p, py = pt.y + (cy - pt.y) * L.p;
      ctx.save();
      ctx.shadowColor = TEAL; ctx.shadowBlur = 10;
      ctx.fillStyle = TEAL;
      ctx.beginPath(); ctx.arc(px, py, 2, 0, Math.PI * 2); ctx.fill();
      ctx.restore();
    }

    drawHub();

    // Workstation nodes — brighter/larger at the front, dim at the back.
    for (var n = 0; n < proj.length; n++) {
      var p = proj[n], depth = (p.z + 1) / 2;             // 0 back … 1 front
      var rad = 1.1 + depth * 2.2;
      var alpha = 0.22 + depth * 0.6;
      ctx.save();
      if (depth > 0.55) { ctx.shadowColor = n % 2 ? TEAL : LIGHT; ctx.shadowBlur = 8 * depth; }
      ctx.fillStyle = (n % 3 === 0 ? 'rgba(45,212,191,' : 'rgba(125,211,252,') + alpha.toFixed(3) + ')';
      ctx.beginPath(); ctx.arc(p.x, p.y, rad, 0, Math.PI * 2); ctx.fill();
      ctx.restore();
    }
  }

  var raf = null;
  function loop() { rot += 0.0022; frame(); raf = requestAnimationFrame(loop); }

  function start() {
    resize(); buildNodes();
    if (raf) cancelAnimationFrame(raf);
    if (reduce) { frame(); return; }               // one static frame, no motion
    loop();
  }

  var rt;
  window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { resize(); buildNodes(); if (reduce) frame(); }, 150); });
  // Pause when the hero scrolls out of view to save cycles.
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        if (en.isIntersecting) { if (!raf) loop(); }
        else if (raf) { cancelAnimationFrame(raf); raf = null; }
      });
    }, { threshold: 0.01 });
    io.observe(canvas);
  }
  start();
})();
