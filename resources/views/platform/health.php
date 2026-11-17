<?php
/** @var callable $e */
/** @var callable $url */
/** @var array{
 *   ok:bool,
 *   status:string,
 *   checked_at:string,
 *   checks:list<array{key:string,label:string,ok:bool,level:string,detail:string}>,
 *   counts:array<string,int>,
 *   php:array<string,string>,
 *   database:array<string,mixed>
 * } $report
 */
$status = (string) ($report['status'] ?? 'unknown');
$statusClass = match ($status) {
    'healthy' => 'badge-ok',
    'degraded' => 'badge-warn',
    default => 'badge-err',
};
$levelClass = static function (string $level): string {
    return match ($level) {
        'ok' => 'badge-ok',
        'warn' => 'badge-warn',
        default => 'badge-err',
    };
};
?>
<div class="sf-page-head">
  <h1>Platform health</h1>
  <div class="spacer"></div>
  <a class="btn btn-outline-secondary" href="<?= $e($url('platform/health/json')) ?>" target="_blank" rel="noopener">JSON report</a>
  <a class="btn btn-outline-secondary" href="<?= $e($url('health')) ?>" target="_blank" rel="noopener">Public ping</a>
  <a href="<?= $e($url('platform')) ?>">&lt;&lt; Platform</a>
</div>

<div class="sf-stats">
  <div class="sf-stat">
    <div class="label">Status</div>
    <div class="value" style="font-size:1.15rem;margin-top:.55rem">
      <span class="badge <?= $e($statusClass) ?>"><?= $e(ucfirst($status)) ?></span>
    </div>
    <span class="text-muted"><?= $e((string) ($report['checked_at'] ?? '')) ?></span>
  </div>
  <div class="sf-stat">
    <div class="label">PHP</div>
    <div class="value" style="font-size:1.05rem;margin-top:.55rem"><?= $e((string) ($report['php']['version'] ?? '')) ?></div>
    <span class="text-muted"><?= $e((string) ($report['php']['sapi'] ?? '')) ?></span>
  </div>
  <div class="sf-stat">
    <div class="label">Database</div>
    <div class="value" style="font-size:1.05rem;margin-top:.55rem"><?= $e((string) ($report['database']['database'] ?? $report['database']['name'] ?? '—')) ?></div>
    <span class="text-muted"><?= $e((string) ($report['database']['host'] ?? '')) ?></span>
  </div>
  <div class="sf-stat">
    <div class="label">Orders (all tenants)</div>
    <div class="value"><?= $e((string) (int) ($report['counts']['orders'] ?? 0)) ?></div>
    <span class="text-muted"><?= $e((string) (int) ($report['counts']['tenants'] ?? 0)) ?> tenants · <?= $e((string) (int) ($report['counts']['channels'] ?? 0)) ?> channels</span>
  </div>
</div>

<div class="sf-home-grid" style="margin-top:1rem">
  <div class="sf-card">
    <h2>Checks</h2>
    <div class="table-responsive">
      <table class="table table-hover align-middle sf-grid mb-0">
        <thead>
          <tr>
            <th>Check</th>
            <th>Level</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (($report['checks'] ?? []) as $check): ?>
            <tr>
              <td class="text-nowrap"><?= $e((string) ($check['label'] ?? '')) ?></td>
              <td><span class="badge <?= $e($levelClass((string) ($check['level'] ?? 'error'))) ?>"><?= $e((string) ($check['level'] ?? '')) ?></span></td>
              <td style="white-space:normal"><?= $e((string) ($check['detail'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="sf-card">
    <h2>Runtime</h2>
    <p><strong>Memory limit:</strong> <?= $e((string) ($report['php']['memory_limit'] ?? '—')) ?></p>
    <p><strong>Timezone:</strong> <?= $e((string) ($report['php']['timezone'] ?? '—')) ?></p>
    <p><strong>Tables:</strong> <?= $e((string) (int) ($report['database']['tables'] ?? 0)) ?></p>
    <p><strong>Migrations:</strong>
      <?= $e((string) (int) ($report['database']['applied'] ?? 0)) ?> applied,
      <?= $e((string) (int) ($report['database']['pending'] ?? 0)) ?> pending
    </p>
    <p><strong>Users:</strong> <?= $e((string) (int) ($report['counts']['users'] ?? 0)) ?></p>
    <p class="mb-0">
      <a href="<?= $e($url('platform/database')) ?>">Database / migrations</a>
      ·
      <a href="<?= $e($url('platform/logs')) ?>">System logs</a>
    </p>
  </div>
</div>
