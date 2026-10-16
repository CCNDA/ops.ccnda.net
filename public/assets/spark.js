// 網站流量卡的 30 天趨勢線：滑鼠滑過、或聚焦後用左右鍵，顯示那一天的使用者人數（戰情室 v1.18.0）
(function () {
  function fmt(d) { return (+d.slice(4, 6)) + '/' + (+d.slice(6, 8)); }
  document.querySelectorAll('svg.spark').forEach(function (svg) {
    var days = JSON.parse(svg.dataset.days), w = +svg.dataset.w, h = +svg.dataset.h, pad = +svg.dataset.pad, max = +svg.dataset.max;
    var dot = svg.querySelector('.spark-dot'), xl = svg.querySelector('.spark-x'), tip = svg.parentNode.querySelector('.spark-tip');
    var n = days.length, cur = n - 1;
    function show(i) {
      cur = Math.max(0, Math.min(n - 1, i));
      var x = pad + cur * (w - 2 * pad) / (n - 1), y = h - pad - days[cur][1] / max * (h - 2 * pad);
      dot.setAttribute('cx', x); dot.setAttribute('cy', y); xl.setAttribute('x1', x); xl.setAttribute('x2', x);
      dot.setAttribute('visibility', 'visible'); xl.setAttribute('visibility', 'visible');
      tip.textContent = fmt(days[cur][0]) + '：' + days[cur][1].toLocaleString('zh-TW') + ' 人';
      tip.style.left = Math.min(100, Math.max(0, x / w * 100)) + '%';
      tip.classList.add('on');
    }
    function hide() { dot.setAttribute('visibility', 'hidden'); xl.setAttribute('visibility', 'hidden'); tip.classList.remove('on'); }
    svg.addEventListener('pointermove', function (e) {
      var r = svg.getBoundingClientRect();
      show(Math.round((e.clientX - r.left) / r.width * (n - 1)));
    });
    svg.addEventListener('pointerleave', function () { if (document.activeElement !== svg) hide(); });
    svg.addEventListener('focus', function () { show(cur); });
    svg.addEventListener('blur', hide);
    svg.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); show(cur + (e.key === 'ArrowRight' ? 1 : -1)); }
      else if (e.key === 'Home') { e.preventDefault(); show(0); }
      else if (e.key === 'End') { e.preventDefault(); show(n - 1); }
      else if (e.key === 'Escape') { hide(); }
    });
  });
})();
