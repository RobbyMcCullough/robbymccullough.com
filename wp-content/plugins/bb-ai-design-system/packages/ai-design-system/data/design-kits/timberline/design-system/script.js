/* @base */
function onReady(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn);
  } else {
    fn();
  }
}

onReady(function () {
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      io.unobserve(entry.target);
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
  var observe = function (el) { io.observe(el); };
  document.querySelectorAll('.bb-reveal').forEach(observe);
  new MutationObserver(function (muts) {
    muts.forEach(function (m) {
      m.addedNodes.forEach(function (n) {
        if (n.nodeType !== 1) return;
        if (n.matches && n.matches('.bb-reveal')) observe(n);
        if (n.querySelectorAll) n.querySelectorAll('.bb-reveal').forEach(observe);
      });
    });
  }).observe(document.body, { childList: true, subtree: true });
});
