<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $error */
/** @var array<string,string> $old */
$old = $old ?? [];
?>
<div class="sf-page-head">
  <h1>New Tenant</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('platform/tenants')) ?>">&lt;&lt; Back</a>
</div>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2"><?= $e((string) $error) ?></div>
<?php endif; ?>
<form method="post" action="<?= $e($url('platform/tenants/new')) ?>" class="card sf-panel" style="border-radius:4px;padding:1rem;max-width:560px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">

  <h3 style="margin:0 0 .75rem">Company</h3>
  <div class="sf-field"><label>Company name</label><input name="name" required value="<?= $e($old['name'] ?? '') ?>" style="width:100%;padding:.5rem" placeholder="Duzky Ltd"></div>
  <div class="sf-field"><label>Slug</label><input name="slug" value="<?= $e($old['slug'] ?? '') ?>" style="width:100%;padding:.5rem" placeholder="duzky"></div>
  <div class="sf-field"><label>Contact email</label><input name="contact_email" type="email" value="<?= $e($old['contact_email'] ?? '') ?>" style="width:100%;padding:.5rem"></div>
  <div class="sf-field">
    <label>Plan</label>
    <select name="plan" style="padding:.45rem">
      <?php foreach (['standard','professional','enterprise'] as $p): ?>
        <option value="<?= $e($p) ?>" <?= ($old['plan'] ?? 'standard') === $p ? 'selected' : '' ?>><?= $e($p) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <h3 style="margin:1.25rem 0 .35rem">Owner login</h3>
  <p class="text-muted" style="margin:0 0 .75rem">Creates the first admin user for this tenant (optional — leave blank to add users later).</p>
  <div class="sf-field"><label>Display name</label><input name="user_name" value="<?= $e($old['user_name'] ?? '') ?>" style="width:100%;padding:.5rem" placeholder="Jane Smith"></div>
  <div class="sf-field"><label>Login email</label><input name="user_email" type="email" value="<?= $e($old['user_email'] ?? '') ?>" style="width:100%;padding:.5rem" placeholder="jane@duzky.com"></div>
  <div class="sf-field"><label>Username</label><input name="user_username" value="<?= $e($old['user_username'] ?? '') ?>" style="width:100%;padding:.5rem" placeholder="jane (optional)"></div>
  <div class="sf-field"><label>Password</label><input name="user_password" type="password" autocomplete="new-password" style="width:100%;padding:.5rem" placeholder="Min 8 characters"></div>

  <button class="btn btn-primary" type="submit" style="margin-top:.5rem">Create tenant</button>
</form>
