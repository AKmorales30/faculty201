<?php
/**
 * Page links under a paged list (Activity Logs, Search Documents).
 *
 * Expects: $page (current, 1-based), $pages (total), $page_url
 * (fn(int $page): string, keeping the list's filters in the URL).
 * Optional: $pagination_labels = ['« Newer', 'Older »'] for the prev / next links.
 * Prints nothing when everything fits on one page.
 */
if ($pages > 1):
  [$prev_label, $next_label] = $pagination_labels ?? ['&laquo; Previous', 'Next &raquo;'];
  $first = max(1, $page - 2);
  $last = min($pages, $page + 2); ?>
<nav class="mt-3" aria-label="Pages">
  <ul class="pagination pagination-sm flex-wrap justify-content-center">
    <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($page_url(max(1, $page - 1))) ?>"><?= $prev_label ?></a></li>
    <?php if ($first > 1): ?>
      <li class="page-item"><a class="page-link" href="<?= h($page_url(1)) ?>">1</a></li>
      <?php if ($first > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
    <?php endif; ?>
    <?php for ($p = $first; $p <= $last; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= h($page_url($p)) ?>"><?= $p ?></a></li>
    <?php endfor; ?>
    <?php if ($last < $pages): ?>
      <?php if ($last < $pages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
      <li class="page-item"><a class="page-link" href="<?= h($page_url($pages)) ?>"><?= $pages ?></a></li>
    <?php endif; ?>
    <li class="page-item <?= $page === $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($page_url(min($pages, $page + 1))) ?>"><?= $next_label ?></a></li>
  </ul>
</nav>
<?php endif; ?>
