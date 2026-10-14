    </main>
  </div>
</div>
<?php if (current_user()) { include __DIR__ . '/app_modal.php'; include __DIR__ . '/chatbot_widget.php'; } ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= (int)@filemtime(ROOT_PATH . '/assets/js/app.js') ?>"></script>
</body>
</html>
