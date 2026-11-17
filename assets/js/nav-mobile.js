(function () {
  'use strict';

  var body = document.body;
  var toggle = document.querySelector('[data-sf-nav-toggle]');
  var backdrop = document.querySelector('[data-sf-nav-backdrop]');
  var side = document.querySelector('.sf-menubar') || document.querySelector('.sf-side');
  if (!toggle || !side) {
    return;
  }

  function isMobile() {
    return window.matchMedia('(max-width: 900px)').matches;
  }

  function setOpen(open) {
    body.classList.toggle('sf-nav-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      body.style.overflow = 'hidden';
    } else {
      body.style.overflow = '';
    }
  }

  toggle.addEventListener('click', function (e) {
    e.preventDefault();
    setOpen(!body.classList.contains('sf-nav-open'));
  });

  if (backdrop) {
    backdrop.addEventListener('click', function () {
      setOpen(false);
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      setOpen(false);
    }
  });

  // Tap primary items with children to expand submenu on mobile
  side.querySelectorAll('.sf-nav-item').forEach(function (item) {
    var link = item.querySelector(':scope > .sf-nav-link');
    var flyout = item.querySelector(':scope > .sf-flyout');
    if (!link || !flyout) {
      return;
    }

    link.addEventListener('click', function (e) {
      if (!isMobile()) {
        return;
      }
      // First tap expands; second tap (already open) follows the link
      if (!item.classList.contains('is-open')) {
        e.preventDefault();
        side.querySelectorAll('.sf-nav-item.is-open').forEach(function (other) {
          if (other !== item) {
            other.classList.remove('is-open');
          }
        });
        item.classList.add('is-open');
      }
    });
  });

  // Keep current section's submenu open
  side.querySelectorAll('.sf-nav-item').forEach(function (item) {
    if (item.querySelector(':scope > .sf-nav-link.is-active') && item.querySelector(':scope > .sf-flyout')) {
      item.classList.add('is-active-section', 'is-open');
    }
  });

  window.addEventListener('resize', function () {
    if (!isMobile()) {
      setOpen(false);
    }
  });
})();
