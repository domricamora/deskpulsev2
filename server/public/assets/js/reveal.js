(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var revealEls = document.querySelectorAll('[data-reveal]');

  function showAll() { revealEls.forEach(function (el) { el.classList.add('in'); }); }

  if (reduce || !('IntersectionObserver' in window)) { showAll(); return; }

  // Sticky header: compact + glow once the page is scrolled.
  var top = document.querySelector('.landing .pub-top');
  if (top) {
    var onScroll = function () { top.classList.toggle('scrolled', window.scrollY > 12); };
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  }

  var ro = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); ro.unobserve(en.target); } });
  }, { threshold: 0.12 });
  revealEls.forEach(function (el) { ro.observe(el); });

  // Scroll-to-top button.
  var toTop = document.createElement('button');
  toTop.type = 'button'; toTop.className = 'to-top'; toTop.setAttribute('aria-label', 'Back to top');
  toTop.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
  document.body.appendChild(toTop);
  window.addEventListener('scroll', function () { toTop.classList.toggle('show', window.scrollY > 400); }, { passive: true });
  toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); });
})();
