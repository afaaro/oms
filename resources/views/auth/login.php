<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $error */
/** @var string $login */
?>
<div class="sf-login-wrap">
  <div class="sf-login card border-0 shadow-sm">
    <div class="card-body p-4">
      <h1 class="h3 mb-1">Store<span>Feeder</span></h1>
      <p class="text-muted mb-4">Sign in to your account</p>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2"><?= $e((string) $error) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= $e($url('login')) ?>" autocomplete="on">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <div aria-hidden="true" style="position:absolute;left:-10000px;height:1px;overflow:hidden">
          <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div class="mb-3">
          <label class="form-label" for="username">Username or Email</label>
          <input class="form-control" id="username" type="text" name="username" value="<?= $e($login ?? '') ?>" autocomplete="username" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input class="form-control" id="password" type="password" name="password" autocomplete="current-password" required>
        </div>
        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
          <label class="form-check-label" for="remember">Remember me</label>
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign In</button>
      </form>
    </div>
  </div>
</div>
