<?php
/** @var callable $e */
/** @var string $content */
/** @var string $title */
/** @var bool $autoPrint */
$autoPrint = !empty($autoPrint);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($title ?? 'Print') ?> - StoreFeeder</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Mulish, Helvetica, Arial, sans-serif;
      color: #111;
      background: #e8e8e8;
      font-size: 13px;
      line-height: 1.4;
    }
    .sf-print-toolbar {
      position: sticky; top: 0; z-index: 10;
      display: flex; gap: .75rem; align-items: center; flex-wrap: wrap;
      padding: .65rem 1rem;
      background: #111827; color: #fff;
    }
    .sf-print-toolbar a, .sf-print-toolbar button {
      color: #fff; background: #2563eb; border: 0; border-radius: 4px;
      padding: .45rem .9rem; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none;
    }
    .sf-print-toolbar .ghost { background: transparent; border: 1px solid rgba(255,255,255,.35); }
    .sf-print-sheet {
      width: 210mm;
      max-width: 100%;
      margin: 1rem auto;
      background: #fff;
      padding: 12mm;
      box-shadow: 0 2px 12px rgba(0,0,0,.15);
    }
    .sf-label-sheet {
      width: 100mm;
      min-height: 150mm;
      margin: 1rem auto;
      background: #fff;
      padding: 6mm;
      box-shadow: 0 2px 12px rgba(0,0,0,.15);
    }
    .sf-page-break { page-break-after: always; break-after: page; }
    h1,h2,h3 { margin: 0 0 .5rem; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #ddd; padding: .4rem .35rem; text-align: left; vertical-align: top; }
    th { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #555; }
    .muted { color: #666; }
    .barcode {
      font-family: "Libre Barcode 128", "Courier New", monospace;
      font-size: 42px; letter-spacing: .04em; line-height: 1;
      display: block; text-align: center; margin: .5rem 0 .15rem;
    }
    .tracking {
      text-align: center; font-weight: 700; font-size: 14px; letter-spacing: .08em;
    }
    .addr { font-size: 14px; line-height: 1.35; }
    .meta { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: .75rem; }
    @media print {
      body { background: #fff; }
      .sf-print-toolbar { display: none !important; }
      .sf-print-sheet, .sf-label-sheet {
        margin: 0; box-shadow: none; width: auto; max-width: none;
      }
    }
  </style>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Libre+Barcode+128&display=swap">
</head>
<body>
  <div class="sf-print-toolbar">
    <button type="button" onclick="window.print()">Print</button>
    <button type="button" class="ghost" onclick="window.close()">Close</button>
    <span class="muted" style="color:#cbd5e1"><?= $e($title ?? '') ?></span>
  </div>
  <?= $content ?>
  <?php if ($autoPrint): ?>
  <script>window.addEventListener('load', function () { window.setTimeout(function () { window.print(); }, 250); });</script>
  <?php endif; ?>
</body>
</html>
