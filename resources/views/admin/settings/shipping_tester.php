<?php
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $channels */
/** @var array<string,mixed> $sample */
/** @var array{rule:?array,method:?array,reason:string}|null $result */
?>
<div class="sf-page-head">
  <h1>Shipping Rules Tester</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/settings/shipping/rules')) ?>">Shipping Rules</a>
  <a href="<?= $e($url('admin/settings/shipping')) ?>">&lt;&lt; Shipping Configuration</a>
</div>

<form method="get" action="<?= $e($url('admin/settings/shipping/tester')) ?>" class="card sf-panel" style="padding:1.1rem;margin-bottom:1rem;max-width:820px">
  <input type="hidden" name="run" value="1">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <div class="sf-field">
      <label>Channel</label>
      <select name="channel_id">
        <option value="0">Any / none</option>
        <?php foreach ($channels as $c): ?>
          <option value="<?= $e((string) $c['id']) ?>" <?= (int) $sample['channel_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= $e((string) $c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sf-field"><label>Channel shipping method text</label><input name="shipping_method" value="<?= $e((string) $sample['shipping_method']) ?>"></div>
    <div class="sf-field"><label>Weight (g)</label><input name="weight_grams" type="number" value="<?= $e((string) $sample['weight_grams']) ?>"></div>
    <div class="sf-field"><label>Postcode</label><input name="postcode" value="<?= $e((string) $sample['postcode']) ?>"></div>
    <div class="sf-field"><label>Country</label><input name="country" value="<?= $e((string) $sample['country']) ?>"></div>
    <div class="sf-field"><label>Or load order ID</label><input name="order_id" type="number" placeholder="e.g. 12"></div>
  </div>
  <button class="btn btn-primary" type="submit" style="margin-top:1rem">Test rules</button>
</form>

<?php if ($result !== null): ?>
  <div class="sf-card" style="max-width:820px">
    <h2>Result</h2>
    <p><?= $e($result['reason']) ?></p>
    <?php if ($result['rule'] !== null): ?>
      <p><strong>Rule:</strong> <?= $e((string) $result['rule']['name']) ?> (priority <?= $e((string) $result['rule']['priority']) ?>)</p>
      <p><strong>Region:</strong> <?= $e((string) ($result['rule']['region_name'] ?? 'Any')) ?></p>
    <?php endif; ?>
    <?php if ($result['method'] !== null): ?>
      <p><strong>Mapped method:</strong> <?= $e((string) $result['method']['name']) ?></p>
      <p><strong>Carrier:</strong> <?= $e((string) $result['method']['carrier']) ?> · <?= $e((string) $result['method']['service_code']) ?></p>
    <?php else: ?>
      <p class="text-muted">No method mapped.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
