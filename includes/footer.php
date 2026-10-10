    </main>
  </div>
</div>
<?php if (current_user()) { include __DIR__ . '/chatbot_widget.php'; } ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script>
// Close the mobile slide-in menu once a link is tapped (navigation still proceeds).
document.querySelectorAll('#appSidebar .nav-link').forEach(function (link) {
  link.addEventListener('click', function () {
    var sb = bootstrap.Offcanvas.getInstance(document.getElementById('appSidebar'));
    if (sb) sb.hide();
  });
});

// Hover / focus tooltips for icon-only buttons: mark the button with data-tooltip and put the text in its title.
// Delegated from <body>, so rows added or re-rendered later get them too. Shown in <body> above the icon,
// so a scrolling table can't cut them off (they flip below near the top of the window).
new bootstrap.Tooltip(document.body, { selector: '[data-tooltip]', placement: 'top', container: 'body', trigger: 'hover focus' });
// Hide it once the button is clicked, so it doesn't stay up over a modal or confirm box the button opens
document.addEventListener('click', function (e) {
  var el = e.target.closest && e.target.closest('[data-tooltip]');
  var tip = el && bootstrap.Tooltip.getInstance(el);
  if (tip) tip.hide();
});
</script>
</body>
</html>
