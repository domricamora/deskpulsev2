/* Dashboard behaviour: timezone reporting, local-time rendering, and the
 * multi-row manual-entry form. Charts live in charts.js, live view in live.js. */
(function () {
  // 1. Tell the server the viewer's timezone (so it can format times locally).
  try {
    var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (tz) {
      document.cookie = "dp_tz=" + encodeURIComponent(tz) +
        ";path=/;max-age=31536000;samesite=Lax";
    }
  } catch (e) { /* ignore */ }

  // 2. Convert <time class="dp-time" data-utc="..."> to the viewer's local time.
  function fmtLocal(d, mode) {
    var o;
    if (mode === "date") o = { year: "numeric", month: "short", day: "numeric" };
    else if (mode === "time") o = { hour: "2-digit", minute: "2-digit" };
    else if (mode === "sec") o = { hour: "2-digit", minute: "2-digit", second: "2-digit" };
    else if (mode === "full") o = { year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" };
    else o = { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" };
    return d.toLocaleString([], o);
  }
  document.querySelectorAll("time.dp-time").forEach(function (el) {
    var iso = el.getAttribute("data-utc");
    if (!iso) return;
    var d = new Date(iso);
    if (isNaN(d.getTime())) return;
    el.textContent = fmtLocal(d, el.getAttribute("data-fmt") || "datetime");
    el.title = d.toString();
  });

  // Mobile sidebar drawer: hamburger toggles an off-canvas nav with backdrop.
  (function () {
    var toggle = document.getElementById("nav-toggle");
    var backdrop = document.getElementById("nav-backdrop");
    function setNav(open) {
      document.body.classList.toggle("nav-open", open);
      if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");
    }
    if (toggle) {
      toggle.addEventListener("click", function () {
        setNav(!document.body.classList.contains("nav-open"));
      });
    }
    if (backdrop) backdrop.addEventListener("click", function () { setNav(false); });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") setNav(false);
    });
    // Dismiss the drawer after picking a destination on mobile.
    document.querySelectorAll(".sidebar nav a").forEach(function (a) {
      a.addEventListener("click", function () { setNav(false); });
    });
  })();

  // Copy-to-clipboard buttons (e.g. public share links).
  document.querySelectorAll(".copy-link").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var link = btn.getAttribute("data-link");
      if (navigator.clipboard) {
        navigator.clipboard.writeText(link).then(function () {
          var t = btn.textContent; btn.textContent = "copied!";
          setTimeout(function () { btn.textContent = t; }, 1200);
        });
      } else {
        window.prompt("Copy this link:", link);
      }
    });
  });

  // 3. Multi-row manual entry (Add more) + local→UTC conversion on submit.
  var rows = document.getElementById("adj-rows");
  if (rows) {
    var firstStart = rows.querySelector('input[name="started_at[]"]');
    if (firstStart && !firstStart.value) {
      var n = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
      firstStart.value = n.toISOString().slice(0, 16);
    }
    var addBtn = document.getElementById("add-row");
    if (addBtn) {
      addBtn.addEventListener("click", function () {
        var clone = rows.querySelector(".adj-row").cloneNode(true);
        clone.querySelectorAll("input").forEach(function (i) { i.value = ""; });
        clone.querySelectorAll("select").forEach(function (s) { s.selectedIndex = 0; });
        rows.appendChild(clone);
      });
    }
    rows.addEventListener("click", function (e) {
      if (e.target.classList.contains("remove-row")) {
        if (rows.querySelectorAll(".adj-row").length > 1) {
          e.target.closest(".adj-row").remove();
        }
      }
    });
    var form = document.getElementById("manual-entry");
    form.addEventListener("submit", function () {
      // Convert each row's local datetime-local values to UTC ISO.
      rows.querySelectorAll(".adj-row").forEach(function (row) {
        var s = row.querySelector('input[name="started_at[]"]').value;
        var en = row.querySelector('input[name="ended_at[]"]').value;
        row.querySelector('input[name="started_at_utc[]"]').value =
          s ? new Date(s).toISOString() : "";
        row.querySelector('input[name="ended_at_utc[]"]').value =
          en ? new Date(en).toISOString() : "";
      });
    });
  }

  // 5. Scroll-to-top button (created dynamically so every page gets it).
  (function () {
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "to-top";
    btn.setAttribute("aria-label", "Back to top");
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
    document.body.appendChild(btn);
    var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    function toggle() { btn.classList.toggle("show", window.scrollY > 400); }
    window.addEventListener("scroll", toggle, { passive: true });
    btn.addEventListener("click", function () {
      window.scrollTo({ top: 0, behavior: reduce ? "auto" : "smooth" });
    });
    toggle();
  })();
})();
