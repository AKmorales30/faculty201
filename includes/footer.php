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
</script>
</body>
</html>
