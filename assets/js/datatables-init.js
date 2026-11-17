(function () {
  var keyMaps = {
    'orders-table': ['flags', 'order', 'channel', 'skus', 'date', 'postcode', 'email', 'total', 'packaging', 'packages', 'shipping', 'status', 'weight', 'pickwave', 'notes'],
    'disputes-table': ['flags', 'order', 'channel', 'skus', 'date', 'postcode', 'email', 'total', 'packaging', 'packages', 'shipping', 'status', 'weight', 'pickwave', 'notes'],
    'warehouse-orders-table': ['flags', 'order', 'channel', 'skus', 'date', 'postcode', 'email', 'total', 'packaging', 'packages', 'shipping', 'status', 'weight', 'pickwave', 'notes'],
    'products-table': ['type', 'image', 'sku', 'name', 'ean', 'supplier_sku', 'inventory', 'allocated', 'available', 'supplier', 'brand', 'price', 'warehouse', 'active'],
    'customers-table': ['name', 'email', 'phone', 'postcode', 'created'],
    'users-table': ['name', 'email', 'login', 'role', 'status', 'actions'],
    'channels-table': ['name', 'code', 'type', 'active', 'actions'],
    'warehouses-table': ['name', 'code', 'default', 'active'],
    'tenants-table': ['company', 'plan', 'status', 'users', 'tickets', 'contact', 'actions'],
    'support-table': ['id', 'tenant', 'subject', 'category', 'priority', 'status', 'updated'],
    'suppliers-table': ['name', 'code', 'email', 'phone', 'active'],
    'po-table': ['reference', 'supplier', 'status', 'total', 'expected'],
    'categories-table': ['name', 'products', 'active', 'created'],
    'attributes-table': ['name', 'code', 'values', 'products', 'variants', 'active', 'actions'],
    'stock-locations-table': ['code', 'name', 'qty', 'active'],
    'returns-table': ['order', 'sku', 'qty', 'reason', 'status', 'date', 'actions'],
    'shipments-table': ['order', 'tracking', 'carrier', 'status', 'channel', 'manifest', 'despatched', 'actions'],
    'dropships-table': ['reference', 'order', 'supplier', 'status', 'created', 'actions'],
    'report-jobs-table': ['name', 'type', 'schedule', 'status', 'file', 'created'],
    'shipping-methods-table': ['name', 'carrier', 'service', 'packaging', 'active'],
    'shipping-regions-table': ['name', 'countries', 'prefixes', 'active'],
    'shipping-rules-table': ['name', 'priority', 'channel', 'region', 'method', 'match', 'weight', 'active'],
    'listings-table': ['listing', 'channel', 'sku', 'price', 'qty', 'product', 'status', 'actions']
  };

  var selectedOrders = Object.create(null);

  function selectedCount() {
    return Object.keys(selectedOrders).length;
  }

  function syncSelectionUi() {
    var count = selectedCount();
    var countEl = document.getElementById('pick-selected-count');
    if (countEl) {
      countEl.textContent = String(count);
    }
    ['btn-create-pickwave', 'btn-mark-printed', 'btn-despatch-selected', 'btn-retry-fulfillment'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (btn) {
        btn.disabled = count === 0;
      }
    });
    document.querySelectorAll('.sf-bulk-btn').forEach(function (btn) {
      btn.disabled = count === 0;
    });

    var pickIds = document.getElementById('pickwave-ids');
    var despatchIds = document.getElementById('despatch-ids');
    var ordersIds = document.getElementById('orders-bulk-ids');
    [pickIds, despatchIds, ordersIds].forEach(function (box) {
      if (!box) {
        return;
      }
      box.innerHTML = '';
      Object.keys(selectedOrders).forEach(function (id) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'order_ids[]';
        input.value = id;
        box.appendChild(input);
      });
    });
  }

  function setSelected(id, on) {
    id = String(id);
    if (on) {
      selectedOrders[id] = true;
    } else {
      delete selectedOrders[id];
    }
  }

  function bindOrderSelection(table, dt) {
    if (table.getAttribute('data-select') !== '1') {
      return;
    }

    var $table = jQuery(table);
    var pageToggle = document.getElementById('sf-select-page');

    function refreshPageChecks() {
      var boxes = table.querySelectorAll('tbody .sf-order-check');
      var allOn = boxes.length > 0;
      boxes.forEach(function (cb) {
        var id = cb.getAttribute('data-order-id') || cb.value;
        cb.checked = !!selectedOrders[String(id)];
        if (!cb.checked) {
          allOn = false;
        }
      });
      if (pageToggle) {
        pageToggle.checked = allOn;
        pageToggle.indeterminate = !allOn && boxes.length > 0 && Array.prototype.some.call(boxes, function (cb) {
          return cb.checked;
        });
      }
      syncSelectionUi();
    }

    $table.on('change', '.sf-order-check', function () {
      setSelected(this.getAttribute('data-order-id') || this.value, this.checked);
      refreshPageChecks();
    });

    if (pageToggle) {
      pageToggle.addEventListener('change', function () {
        var on = pageToggle.checked;
        table.querySelectorAll('tbody .sf-order-check').forEach(function (cb) {
          cb.checked = on;
          setSelected(cb.getAttribute('data-order-id') || cb.value, on);
        });
        syncSelectionUi();
      });
    }

    var clearBtn = document.getElementById('btn-clear-selection');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        selectedOrders = Object.create(null);
        refreshPageChecks();
      });
    }

    dt.on('draw', function () {
      refreshPageChecks();
    });

    ['pickwave-form', 'despatch-form', 'orders-bulk-form'].forEach(function (fid) {
      var form = document.getElementById(fid);
      if (!form) {
        return;
      }
      form.addEventListener('submit', function (ev) {
        syncSelectionUi();
        if (selectedCount() === 0) {
          ev.preventDefault();
          alert('Select at least one order.');
          return;
        }
        if (fid === 'orders-bulk-form') {
          var actionInput = document.getElementById('orders-bulk-action');
          var submitter = ev.submitter;
          if (actionInput && submitter && submitter.getAttribute('data-action')) {
            actionInput.value = submitter.getAttribute('data-action');
          }
          if (actionInput && !actionInput.value) {
            ev.preventDefault();
            alert('Choose a bulk action.');
          }
        }
      });
    });

    syncSelectionUi();
  }

  function initTable(table) {
    if (!window.jQuery || !jQuery.fn.DataTable) {
      return;
    }
    if (jQuery.fn.DataTable.isDataTable(table)) {
      return;
    }

    var $table = jQuery(table);
    var ajaxUrl = table.getAttribute('data-ajax');
    var pageLength = parseInt(table.getAttribute('data-page-length') || '25', 10);
    var orderAttr = table.getAttribute('data-order');
    var order = [[0, 'desc']];
    try {
      if (orderAttr) {
        order = JSON.parse(orderAttr);
      }
    } catch (e) {}

    var selectable = table.getAttribute('data-select') === '1';
    var keys = null;
    // Use data-sf-keys (NOT data-columns — that is a DataTables built-in and breaks init)
    var colsAttr = table.getAttribute('data-sf-keys') || table.getAttribute('data-col-keys');
    if (colsAttr) {
      try {
        keys = JSON.parse(colsAttr);
      } catch (e) {}
    }
    if (!keys) {
      keys = keyMaps[table.id] ? keyMaps[table.id].slice() : null;
    }
    if (!keys) {
      keys = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th, i) {
        return 'col_' + i;
      });
    }
    // Keep column keys aligned with <thead>. Extra headers get an empty cell
    // rather than a field name the JSON row does not contain.
    var thCount = table.querySelectorAll('thead th').length;
    if (keys && thCount > 0 && keys.length !== thCount) {
      if (keys.length > thCount) {
        keys = keys.slice(0, thCount);
      } else {
        while (keys.length < thCount) {
          keys.push('');
        }
      }
    }
    if (selectable && keys[0] !== 'select') {
      keys = ['select'].concat(keys);
      thCount = table.querySelectorAll('thead th').length;
      if (keys.length > thCount) {
        keys = keys.slice(0, thCount);
      }
    }

    var columns = keys.map(function (k) {
      if (!k) {
        return { data: null, defaultContent: '—', orderable: false, searchable: false };
      }
      return {
        data: k,
        defaultContent: '—',
        orderable: k !== 'actions' && k !== 'select' && k !== 'type' && k !== 'image' && k !== 'flags' && k !== 'notes' && k !== 'skus',
        searchable: k !== 'select' && k !== 'actions' && k !== 'type' && k !== 'image' && k !== 'flags' && k !== 'notes',
        className: k === 'select' ? 'sf-col-check' : (k === 'type' ? 'sf-col-type' : (k === 'skus' ? 'sf-col-skus' : undefined))
      };
    });

    var scrollX = table.getAttribute('data-scroll-x') === '1'
      || window.matchMedia('(max-width: 900px)').matches;

    var dt = $table.DataTable({
      processing: true,
      serverSide: true,
      searching: true,
      pageLength: pageLength,
      lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
      order: order,
      scrollX: scrollX,
      autoWidth: false,
      ajax: {
        url: ajaxUrl,
        dataSrc: 'data',
        error: function (xhr) {
          console.error('DataTables ajax failed', xhr.status, (xhr.responseText || '').slice(0, 300));
        }
      },
      columns: columns,
      language: {
        search: '',
        searchPlaceholder: 'Search…',
        lengthMenu: 'Show _MENU_',
        info: '_START_ – _END_ of _TOTAL_',
        infoEmpty: '0 items',
        infoFiltered: '(filtered from _MAX_)',
        processing: 'Loading…',
        paginate: {
          previous: '‹',
          next: '›'
        }
      },
      dom: '<"sf-dt-toolbar"lf>rt<"sf-dt-footer"ip>'
    });

    bindOrderSelection(table, dt);
    bindColumnMenu(table, dt);
    bindProductVariantExpand(table, dt, keys);

    // Keep expanded variant columns lined up when the table redraws/resizes
    dt.on('draw.dt', function () {
      if (typeof table._sfSyncVariantWidths === 'function') {
        table._sfSyncVariantWidths();
      }
    });
    window.addEventListener('resize', function () {
      if (typeof table._sfSyncVariantWidths === 'function') {
        table._sfSyncVariantWidths();
      }
    });
  }

  function bindColumnMenu(table, dt) {
    if (table.getAttribute('data-col-menu') !== '1') {
      return;
    }
    var wrapper = table.closest('.dataTables_wrapper');
    var toolbar = wrapper ? wrapper.querySelector('.sf-dt-toolbar') : null;
    if (!toolbar) {
      return;
    }

    var storageKey = 'sf-colvis:' + (table.id || 'table');
    var hidden = [];
    try {
      hidden = JSON.parse(localStorage.getItem(storageKey) || '[]');
    } catch (e) {
      hidden = [];
    }
    if (!Array.isArray(hidden)) {
      hidden = [];
    }

    var columns = [];
    dt.columns().every(function (idx) {
      var key = dt.settings()[0].aoColumns[idx].data;
      if (key === 'select') {
        return;
      }
      columns.push({
        idx: idx,
        key: String(key),
        title: (this.header().textContent || '').trim() || String(key)
      });
    });

    function persist() {
      var off = columns.filter(function (col) {
        return !dt.column(col.idx).visible();
      }).map(function (col) {
        return col.key;
      });
      localStorage.setItem(storageKey, JSON.stringify(off));
    }

    function applyHidden() {
      columns.forEach(function (col) {
        if (hidden.indexOf(col.key) !== -1) {
          dt.column(col.idx).visible(false, false);
        }
      });
      dt.columns.adjust();
    }

    applyHidden();

    var box = document.createElement('div');
    box.className = 'sf-col-menu';
    box.innerHTML = ''
      + '<button type="button" class="sf-col-menu-btn" aria-expanded="false" aria-controls="sf-col-menu-panel">Columns</button>'
      + '<div class="sf-col-menu-panel" id="sf-col-menu-panel" hidden>'
      + '  <div class="sf-col-menu-head">'
      + '    <label><input type="checkbox" class="sf-col-menu-all"> Select All</label>'
      + '    <button type="button" class="sf-col-menu-close" aria-label="Close">×</button>'
      + '  </div>'
      + '  <div class="sf-col-menu-list"></div>'
      + '</div>';
    toolbar.appendChild(box);

    var panel = box.querySelector('.sf-col-menu-panel');
    var list = box.querySelector('.sf-col-menu-list');
    var toggle = box.querySelector('.sf-col-menu-btn');
    var all = box.querySelector('.sf-col-menu-all');
    var closeBtn = box.querySelector('.sf-col-menu-close');

    columns.forEach(function (col) {
      var label = document.createElement('label');
      var input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = dt.column(col.idx).visible();
      input.addEventListener('change', function () {
        dt.column(col.idx).visible(input.checked, false);
        dt.columns.adjust();
        persist();
        syncAll();
      });
      label.appendChild(input);
      label.appendChild(document.createTextNode(' ' + col.title));
      list.appendChild(label);
    });

    function syncAll() {
      var boxes = list.querySelectorAll('input');
      var on = 0;
      boxes.forEach(function (input) {
        if (input.checked) {
          on++;
        }
      });
      all.checked = boxes.length > 0 && on === boxes.length;
      all.indeterminate = on > 0 && on < boxes.length;
    }

    all.addEventListener('change', function () {
      var on = all.checked;
      list.querySelectorAll('input').forEach(function (input, i) {
        input.checked = on;
        dt.column(columns[i].idx).visible(on, false);
      });
      dt.columns.adjust();
      persist();
      all.indeterminate = false;
    });

    document.body.appendChild(panel);

    function placePanel() {
      var rect = toggle.getBoundingClientRect();
      panel.style.position = 'fixed';
      if (window.innerWidth <= 900) {
        panel.style.top = 'auto';
        panel.style.bottom = '0';
        panel.style.left = '0';
        panel.style.right = '0';
        panel.style.width = '100%';
        panel.style.maxHeight = '70vh';
        return;
      }
      var width = 230;
      var left = Math.min(rect.right - width, window.innerWidth - width - 8);
      panel.style.bottom = 'auto';
      panel.style.width = width + 'px';
      panel.style.top = Math.round(rect.bottom + 6) + 'px';
      panel.style.left = Math.max(8, Math.round(left)) + 'px';
      panel.style.right = 'auto';
      panel.style.maxHeight = Math.max(180, window.innerHeight - rect.bottom - 16) + 'px';
    }

    function setOpen(open) {
      if (open) {
        placePanel();
      }
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    toggle.addEventListener('click', function (ev) {
      ev.stopPropagation();
      setOpen(panel.hidden);
    });
    window.addEventListener('resize', function () {
      if (!panel.hidden) {
        placePanel();
      }
    });
    closeBtn.addEventListener('click', function () {
      setOpen(false);
    });
    document.addEventListener('click', function (ev) {
      if (!box.contains(ev.target) && !panel.contains(ev.target)) {
        setOpen(false);
      }
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') {
        setOpen(false);
      }
    });
    syncAll();
  }

  function bindProductVariantExpand(table, dt, keys) {
    if (table.id !== 'products-table') {
      return;
    }
    var variantsUrl = table.getAttribute('data-variants-ajax');
    if (!variantsUrl) {
      return;
    }
    var $table = jQuery(table);
    var cache = Object.create(null);

    function cellsHtml(row) {
      return keys.map(function (k) {
        return '<td class="' + (k === 'type' ? 'sf-col-type' : '') + '">' + (row[k] != null ? row[k] : '') + '</td>';
      }).join('');
    }

    function parentColumnWidths() {
      var widths = [];
      // Prefer visible header cells from the scroll head if present
      var $heads = $table.closest('.dataTables_wrapper').find('.dataTables_scrollHead th');
      if (!$heads.length) {
        $heads = $table.children('thead').find('th');
      }
      $heads.each(function () {
        widths.push(Math.max(24, Math.round(this.getBoundingClientRect().width)));
      });
      return widths;
    }

    function renderChild(rows) {
      if (!rows || !rows.length) {
        return '<div class="muted" style="padding:.5rem .75rem">No variants</div>';
      }
      var widths = parentColumnWidths();
      var html = '<table class="sf-variant-children sf-grid"><colgroup>';
      keys.forEach(function (_k, i) {
        var w = widths[i] || 0;
        html += w > 0 ? '<col style="width:' + w + 'px">' : '<col>';
      });
      html += '</colgroup><tbody>';
      rows.forEach(function (row) {
        html += '<tr class="sf-variant-row">' + cellsHtml(row) + '</tr>';
      });
      html += '</tbody></table>';
      return html;
    }

    function syncOpenChildWidths() {
      $table.find('tr.sf-parent-expanded').each(function () {
        var parentRow = dt.row(this);
        if (!parentRow.child.isShown()) {
          return;
        }
        var child = parentRow.child();
        if (!child || !child.length) {
          return;
        }
        var nested = child.find('table.sf-variant-children')[0];
        if (!nested) {
          return;
        }
        var widths = parentColumnWidths();
        var cols = nested.querySelectorAll('colgroup col');
        widths.forEach(function (w, i) {
          if (cols[i] && w > 0) {
            cols[i].style.width = w + 'px';
          }
        });
      });
    }

    $table.on('click', '.sf-variant-toggle', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      var btn = this;
      var tr = btn.closest('tr');
      var row = dt.row(tr);
      var parentId = btn.getAttribute('data-parent-id');
      if (!parentId) {
        return;
      }

      if (row.child.isShown()) {
        row.child.hide();
        tr.classList.remove('sf-parent-expanded');
        btn.textContent = '+';
        btn.setAttribute('aria-expanded', 'false');
        return;
      }

      function show(rows) {
        row.child(renderChild(rows), 'sf-variant-child').show();
        tr.classList.add('sf-parent-expanded');
        btn.textContent = '\u2212';
        btn.setAttribute('aria-expanded', 'true');
        // Re-measure after paint (scroll/layout settle)
        window.requestAnimationFrame(syncOpenChildWidths);
      }

      if (cache[parentId]) {
        show(cache[parentId]);
        return;
      }

      btn.disabled = true;
      fetch(variantsUrl + (variantsUrl.indexOf('?') >= 0 ? '&' : '?') + 'parent_id=' + encodeURIComponent(parentId), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (json) {
        cache[parentId] = (json && json.data) ? json.data : [];
        show(cache[parentId]);
      }).catch(function () {
        show([]);
      }).finally(function () {
        btn.disabled = false;
      });
    });

    table._sfSyncVariantWidths = syncOpenChildWidths;
  }

  function boot() {
    document.querySelectorAll('table[data-datatable]').forEach(initTable);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
