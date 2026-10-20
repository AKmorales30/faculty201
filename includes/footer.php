<?php
/**
 * Bottom of every page after login: closes the layout opened by
 * includes/header.php, then the one pop-up (includes/app_modal.php), the
 * confirmation pop-up and the scripts (Bootstrap, assets/js/app.js,
 * assets/js/admin.js).
 */
?>
      </div>
    </main>
  </div>
</div>
<?php include __DIR__ . '/app_modal.php'; ?>
<!-- Confirmation for forms marked data-confirm (assets/js/admin.js); without JavaScript the form just submits -->
<div class="modal fade" id="adConfirm" tabindex="-1" aria-labelledby="adConfirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
    <div class="modal-content">
      <div class="modal-header"><h2 class="modal-title" id="adConfirmTitle">Are you sure?</h2></div>
      <div class="modal-body"><p class="mb-0" id="adConfirmText" style="font-size:15px;line-height:1.6;color:var(--ad-ink-2)"></p></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-brand" id="adConfirmOk">Confirm</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="<?= h(admin_asset('assets/js/app.js')) ?>"></script>
<script src="<?= h(admin_asset('assets/js/admin.js')) ?>"></script>
</body>
</html>
