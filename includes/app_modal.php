<?php
/**
 * The one pop-up of the system (included once by includes/footer.php).
 * Any link or button with data-app-modal="<url>" opens it and loads that
 * URL -- always api/modal.php?view=... -- into the body (assets/js/app.js).
 * The server checks the role and ownership for every view and sends back
 * an HTML fragment; its first element may carry data-modal-title. Optional
 * on the trigger: data-app-modal-size="lg|xl" (default xl) and
 * data-app-modal-title (shown while loading).
 * A trigger that is a link keeps its href, so Ctrl/Cmd-click or a browser
 * without JavaScript still opens the page or file itself.
 */
?>
<div class="modal fade" id="appModal" tabindex="-1" aria-labelledby="appModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-truncate" id="appModalTitle">Loading…</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="appModalBody" aria-live="polite"></div>
    </div>
  </div>
</div>
