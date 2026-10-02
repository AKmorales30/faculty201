    </main>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
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
