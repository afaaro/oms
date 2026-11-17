<?php
/** @var callable $e */
/** @var string $message */
?>
<div class="sf-page-head"><h1>Error</h1></div>
<div class="alert alert-danger py-2"><?= $e($message ?? 'Something went wrong.') ?></div>
