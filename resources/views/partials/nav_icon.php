<?php
/** @var string $icon */
$icon = $icon ?? 'home';
?>
<span class="sf-nav-ico" aria-hidden="true">
<?php if ($icon === 'home'): ?>
  <svg viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5"/><path d="M6.5 10.5V20h11V10.5"/></svg>
<?php elseif ($icon === 'messages'): ?>
  <svg viewBox="0 0 24 24"><path d="M4 6.5h16v11H8.5L4 21z"/><path d="M8 10.5h8M8 13.5h5"/></svg>
<?php elseif ($icon === 'orders'): ?>
  <svg viewBox="0 0 24 24"><path d="M7 7h13l-1.5 9h-10z"/><path d="M7 7 5.5 3H2"/><circle cx="9" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/></svg>
<?php elseif ($icon === 'products'): ?>
  <svg viewBox="0 0 24 24"><path d="M4 8.5 12 4l8 4.5v9L12 22 4 17.5z"/><path d="M12 12v10M4 8.5 12 12l8-3.5"/></svg>
<?php elseif ($icon === 'customers'): ?>
  <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2"/><path d="M5 19.5c1.8-3.2 4-4.8 7-4.8s5.2 1.6 7 4.8"/></svg>
<?php elseif ($icon === 'warehouse'): ?>
  <svg viewBox="0 0 24 24"><path d="M3 10 12 4l9 6v10H3z"/><path d="M8 20v-7h8v7"/></svg>
<?php else: ?>
  <svg viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 17h14l-2.5-4 2.5-4H7"/></svg>
<?php endif; ?>
</span>
