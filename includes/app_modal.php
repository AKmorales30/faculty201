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
 *
 * Same layout for every pop-up: title at the top, the content in the middle
 * (scrolls when long), and a Close button at the bottom right -- plus the X,
 * the Esc key and a click outside. A fragment element with data-modal-footer
 * (e.g. "Open as a full page") is shown at the bottom left. There is only
 * this one pop-up: opening another item replaces its content.
 */
?>
<div class="modal fade" id="appModal" tabindex="-1" aria-labelledby="appModalTitle" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-truncate" id="appModalTitle">Loading…</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="appModalBody" aria-live="polite"></div>
      <div class="modal-footer justify-content-between">
        <div class="small me-auto" id="appModalFooterExtra"></div>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
