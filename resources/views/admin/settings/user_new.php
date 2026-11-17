<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $error */
/** @var int $cloneFrom */
?>
<div class="sf-page-head">
  <h1>New User</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/settings/users')) ?>">&lt;&lt; Back To Users</a>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2"><?= $e((string) $error) ?></div>
<?php endif; ?>
<?php if (!empty($cloneFrom)): ?>
  <div class="alert alert-danger py-2" style="background:#e7f0fb;color:#1557c0;border-color:#b7d0f0">
    Cloning permissions from user #<?= $e((string) $cloneFrom) ?> after create.
  </div>
<?php endif; ?>

<form method="post" action="<?= $e($url('admin/settings/users/new')) ?>" class="card sf-panel" style="border-radius:4px;padding:1rem;max-width:480px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div class="sf-field">
    <label>Email</label>
    <input type="email" name="email" required style="width:100%;padding:.5rem">
  </div>
  <div class="sf-field">
    <label>Display name</label>
    <input type="text" name="display_name" style="width:100%;padding:.5rem">
  </div>
  <div class="sf-field">
    <label>Password</label>
    <input type="password" name="password" required style="width:100%;padding:.5rem">
  </div>
  <button type="submit" class="btn btn-primary">Create User</button>
</form>
