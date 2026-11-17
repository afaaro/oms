<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $editUser */
/** @var array<string,mixed> $userAcl */
/** @var string $tab */
/** @var array $actionTree */
/** @var array $pageTree */
/** @var list<array{key:string,label:string}> $mobileTree */
/** @var list<array<string,mixed>> $warehouses */
/** @var list<array<string,mixed>> $channels */
/** @var string|null $flash */

$checked = static function (array $haystack, string|int $needle): string {
    return in_array($needle, $haystack, false) || in_array((string) $needle, array_map('strval', $haystack), true) ? ' checked' : '';
};
$grantAll = !empty($userAcl['flags']['grant_all']) || !empty($userAcl['flags']['is_admin']);
?>
<div class="sf-page-head">
  <h1>Account <?= $e((string) $editUser['email']) ?></h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/settings/users')) ?>">&lt;&lt; Back To Users</a>
</div>

<?php if (!empty($flash)): ?>
  <div class="alert alert-success py-2"><?= $e((string) $flash) ?></div>
<?php endif; ?>

<div class="sf-tabs" style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
  <?php foreach (['basic' => 'Basic Settings', 'permissions' => 'User Permissions', 'warehouses' => 'Warehouse Permissions', 'channels' => 'Channel Permissions'] as $k => $label): ?>
    <a class="btn <?= $tab === $k ? 'btn-primary' : '' ?>"
       style="<?= $tab !== $k ? 'background:#e8eef6;color:#1c2430' : '' ?>"
       href="<?= $e($url('admin/settings/users/edit', ['id' => $editUser['id'], 'tab' => $k])) ?>"><?= $e($label) ?></a>
  <?php endforeach; ?>
  <a class="btn" style="background:#e8eef6;color:#1c2430" href="<?= $e($url('admin/settings/users/clone', ['id' => $editUser['id']])) ?>">Clone User</a>
</div>

<form method="post" action="<?= $e($url('admin/settings/users/save')) ?>">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= $e((string) $editUser['id']) ?>">
  <input type="hidden" name="tab" value="<?= $e($tab) ?>">

  <?php if ($tab === 'basic'): ?>
    <div class="card sf-panel" style="border-radius:4px;padding:1rem">
      <h3 style="margin-top:0">General User Information</h3>
      <div class="sf-field">
        <label>Display name</label>
        <input type="text" name="display_name" value="<?= $e((string) $editUser['display_name']) ?>" style="width:100%;max-width:420px;padding:.5rem">
      </div>
      <div class="sf-field">
        <label>Email</label>
        <input type="text" value="<?= $e((string) $editUser['email']) ?>" disabled style="width:100%;max-width:420px;padding:.5rem;background:#f3f6fa">
      </div>
      <div class="sf-field">
        <label>Status</label>
        <select name="status" style="padding:.45rem">
          <?php foreach (['active','pending','suspended'] as $s): ?>
            <option value="<?= $e($s) ?>" <?= $editUser['status'] === $s ? 'selected' : '' ?>><?= $e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sf-field">
        <label>Role</label>
        <select name="role" style="padding:.45rem">
          <?php foreach (['member' => 'Member', 'admin' => 'Admin', 'owner' => 'Account Owner'] as $rk => $rl): ?>
            <option value="<?= $e($rk) ?>" <?= ($userAcl['role'] ?? '') === $rk ? 'selected' : '' ?>><?= $e($rl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="text-muted" style="font-size:.85rem">Last login: <?= $e((string) ($editUser['last_login_at'] ?? '—')) ?></p>
    </div>

  <?php elseif ($tab === 'permissions'): ?>
    <div class="card sf-panel" style="border-radius:4px;padding:1rem">
      <label class="sf-check" style="margin-bottom:1rem">
        <input type="checkbox" name="grant_all" value="1"<?= $grantAll ? ' checked' : '' ?>>
        <strong>Grant all permissions</strong> (Account Owner / Admin)
      </label>

      <h3>User Permissions</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem">
        <?php foreach ($actionTree as $group => $items): ?>
          <div>
            <strong><?= $e($group) ?></strong>
            <?php foreach ($items as $item): ?>
              <label class="sf-check" style="display:flex">
                <input type="checkbox" name="action_perms[]" value="<?= $e($item['key']) ?>"<?= $checked($userAcl['action_perms'], $item['key']) ?>>
                <?= $e($item['label']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <h3 style="margin-top:1.5rem">Page Permissions</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem">
        <?php foreach ($pageTree as $group => $items): ?>
          <div>
            <strong><?= $e($group) ?></strong>
            <?php foreach ($items as $item): ?>
              <label class="sf-check" style="display:flex">
                <input type="checkbox" name="page_perms[]" value="<?= $e($item['key']) ?>"<?= $checked($userAcl['page_perms'], $item['key']) ?>>
                <?= $e($item['label']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <h3 style="margin-top:1.5rem">Mobile App Permissions</h3>
      <?php foreach ($mobileTree as $item): ?>
        <label class="sf-check" style="display:flex">
          <input type="checkbox" name="mobile_perms[]" value="<?= $e($item['key']) ?>"<?= $checked($userAcl['action_perms'], $item['key']) ?>>
          <?= $e($item['label']) ?>
        </label>
      <?php endforeach; ?>
    </div>

  <?php elseif ($tab === 'warehouses'): ?>
    <div class="card sf-panel" style="border-radius:4px;padding:1rem">
      <p class="text-muted">Empty selection = all warehouses. Grant all overrides this.</p>
      <?php if ($warehouses === []): ?>
        <p>No warehouses yet. <a href="<?= $e($url('admin/settings/warehouses')) ?>">Add one</a>.</p>
      <?php endif; ?>
      <?php foreach ($warehouses as $w): ?>
        <label class="sf-check" style="display:flex">
          <input type="checkbox" name="warehouse_ids[]" value="<?= $e((string) $w['id']) ?>"<?= $checked($userAcl['warehouse_ids'], (int) $w['id']) ?>>
          <?= $e((string) $w['name']) ?>
        </label>
      <?php endforeach; ?>
      <input type="hidden" name="role" value="<?= $e((string) ($userAcl['role'] ?? 'member')) ?>">
      <?php foreach ($userAcl['page_perms'] as $p): ?><input type="hidden" name="page_perms[]" value="<?= $e((string) $p) ?>"><?php endforeach; ?>
      <?php foreach ($userAcl['action_perms'] as $p): ?><input type="hidden" name="action_perms[]" value="<?= $e((string) $p) ?>"><?php endforeach; ?>
      <?php foreach ($userAcl['channel_ids'] as $p): ?><input type="hidden" name="channel_ids[]" value="<?= $e((string) $p) ?>"><?php endforeach; ?>
    </div>

  <?php else: /* channels */ ?>
    <div class="card sf-panel" style="border-radius:4px;padding:1rem">
      <p class="text-muted">Empty selection = all channels.</p>
      <?php if ($channels === []): ?>
        <p>No channels yet. <a href="<?= $e($url('admin/settings/channels')) ?>">Add one</a>.</p>
      <?php endif; ?>
      <?php foreach ($channels as $c): ?>
        <label class="sf-check" style="display:flex">
          <input type="checkbox" name="channel_ids[]" value="<?= $e((string) $c['id']) ?>"<?= $checked($userAcl['channel_ids'], (int) $c['id']) ?>>
          <?= $e((string) $c['name']) ?> <span class="text-muted">(<?= $e((string) $c['code']) ?>)</span>
        </label>
      <?php endforeach; ?>
      <input type="hidden" name="role" value="<?= $e((string) ($userAcl['role'] ?? 'member')) ?>">
      <?php foreach ($userAcl['page_perms'] as $p): ?><input type="hidden" name="page_perms[]" value="<?= $e((string) $p) ?>"><?php endforeach; ?>
      <?php foreach ($userAcl['action_perms'] as $p): ?><input type="hidden" name="action_perms[]" value="<?= $e((string) $p) ?>"><?php endforeach; ?>
      <?php foreach ($userAcl['warehouse_ids'] as $p): ?><input type="hidden" name="warehouse_ids[]" value="<?= $e((string) $p) ?>"><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div style="margin-top:1rem">
    <button type="submit" class="btn btn-primary">Save</button>
    <a class="btn" style="background:#e8eef6;color:#1c2430;margin-left:.35rem" href="<?= $e($url('admin/settings/users')) ?>">Cancel</a>
  </div>
</form>
