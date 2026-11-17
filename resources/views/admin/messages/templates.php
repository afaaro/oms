<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $templates */
$templates = $templates ?? [];
?>
<div class="sf-page-head">
  <h1>Reply templates</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/messages')) ?>">&lt;&lt; Messages</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="sf-card">
  <h2>New template</h2>
  <form method="post" action="<?= $e($url('admin/messages/templates')) ?>">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <div class="sf-field"><label>Name</label><input name="name" placeholder="e.g. Tracking sent"></div>
    <div class="sf-field"><label>Channel type (optional)</label><input name="channel_type" placeholder="ebay / shopify / blank for all"></div>
    <div class="sf-field"><label>Body</label><textarea name="body" rows="5" required></textarea></div>
    <button class="btn btn-primary" type="submit">Save template</button>
  </form>
</div>

<div class="sf-card">
  <h2>Templates</h2>
  <?php if ($templates === []): ?>
    <p class="text-muted mb-0">No templates yet.</p>
  <?php else: foreach ($templates as $tpl): ?>
    <div style="border-top:1px solid var(--sf-border,#ddd);padding:1rem 0">
      <form method="post" action="<?= $e($url('admin/messages/templates')) ?>">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>">
        <div class="sf-field"><label>Name</label><input name="name" value="<?= $e((string) $tpl['name']) ?>"></div>
        <div class="sf-field"><label>Channel type</label><input name="channel_type" value="<?= $e((string) ($tpl['channel_type'] ?? '')) ?>"></div>
        <div class="sf-field"><label>Body</label><textarea name="body" rows="4" required><?= $e((string) $tpl['body']) ?></textarea></div>
        <button class="btn btn-sm btn-outline-secondary" type="submit">Update</button>
      </form>
      <form method="post" action="<?= $e($url('admin/messages/templates/delete')) ?>" style="display:inline;margin-top:.5rem" onsubmit="return confirm('Delete template?');">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>">
        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
      </form>
    </div>
  <?php endforeach; endif; ?>
</div>
