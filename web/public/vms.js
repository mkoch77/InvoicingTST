const monthSelect = document.getElementById('month-select');
const searchInput = document.getElementById('search-input');
const pageSizeSelect = document.getElementById('page-size');
const vmRows = document.getElementById('vm-rows');
const vmCount = document.getElementById('vm-count');
const pagination = document.getElementById('pagination');
const activeFiltersBar = document.getElementById('active-filters');
const COLS = 12;

let allVMs = [];
let allCustomers = [];
let sortKey = 'hostname';
let sortDir = 1;
let currentPage = 1;
let activeFilter = null; // dashboard group filter
let columnFilters = {};  // { key: value } per-column filters

const urlParams = new URLSearchParams(window.location.search);
const urlMonth = urlParams.get('month');
const urlFilter = urlParams.get('filter');
if (urlFilter) activeFilter = urlFilter;

const filterFns = {
  templates:     vm => (vm.hostname || '').toUpperCase().includes('TEMP'),
  citrix:        vm => { const h = (vm.hostname || '').toUpperCase(); return !h.includes('TEMP') && h.startsWith('CLT'); },
  server:        vm => { const h = (vm.hostname || '').toUpperCase(); return !h.includes('TEMP') && h.startsWith('F0'); },
  off_suspended: vm => vm.power_state === 'Off' || vm.power_state === 'Suspended',
  other:         vm => {
    const h = (vm.hostname || '').toUpperCase();
    return !h.includes('TEMP') && !h.startsWith('CLT') && !h.startsWith('F0');
  }
};

function getPageSize() {
  const val = pageSizeSelect.value;
  return val === 'all' ? Infinity : parseInt(val, 10);
}

function stripCidr(ip) { return ip.replace(/\/\d+$/, ''); }

// ── Load customers ──
async function loadCustomers() {
  try {
    const res = await fetch('/api/customers.php');
    allCustomers = await res.json();
  } catch (e) { console.error(e); }
}

// ── Months ──
async function loadMonths() {
  try {
    const res = await fetch('/api/months.php');
    const months = await res.json();
    months.forEach(m => {
      const opt = document.createElement('option');
      opt.value = m;
      const [y, mo] = m.split('-');
      opt.textContent = new Date(y, parseInt(mo) - 1).toLocaleDateString('de-DE', { year: 'numeric', month: 'long' });
      monthSelect.appendChild(opt);
    });
    if (urlMonth && months.includes(urlMonth)) monthSelect.value = urlMonth;
    else if (months.length > 0) monthSelect.value = months[0];
  } catch (err) { console.error(err); }
}

// ── Load VMs ──
async function loadVMs() {
  const month = monthSelect.value;
  const url = month ? `/api/vms.php?month=${month}` : '/api/vms.php';
  vmRows.innerHTML = `<tr><td colspan="${COLS}" class="empty">Laden…</td></tr>`;

  try {
    const res = await fetch(url);
    allVMs = await res.json();

    // Auto-assign unassigned VMs
    if (allVMs.some(vm => !vm.customer_id) && month) {
      await fetch('/api/vm-customer.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ month }),
      });
      const res2 = await fetch(url);
      allVMs = await res2.json();
    }

    currentPage = 1;
    renderActiveFilters();
    renderTable();
  } catch (err) {
    vmRows.innerHTML = `<tr><td colspan="${COLS}" class="empty">Fehler: ${esc(err.message)}</td></tr>`;
  }
}

// ── Column filter popup ──
let openFilterPopup = null;

function closeFilterPopup() {
  if (openFilterPopup) {
    openFilterPopup.remove();
    openFilterPopup = null;
  }
}

function getUniqueValues(key) {
  const vals = new Set();
  allVMs.forEach(vm => {
    let v = vm[key];
    if (Array.isArray(v)) v = v.map(stripCidr).join(', ');
    if (v != null && v !== '') vals.add(String(v));
  });
  return [...vals].sort((a, b) => a.localeCompare(b, 'de'));
}

function showFilterPopup(th) {
  closeFilterPopup();

  const key = th.dataset.key;
  const filterType = th.dataset.filterType || 'text';
  const rect = th.getBoundingClientRect();

  const popup = document.createElement('div');
  popup.className = 'col-filter-popup';
  popup.style.top = (rect.bottom + window.scrollY + 4) + 'px';
  popup.style.left = Math.max(0, rect.left + window.scrollX) + 'px';

  if (filterType === 'select') {
    const values = getUniqueValues(key);
    let html = `<div class="col-filter-header">${esc(th.textContent.replace(/[▲▼]/g, '').trim())} filtern</div>`;
    html += `<div class="col-filter-option" data-value="">Alle anzeigen</div>`;
    if (key === 'customer_name') {
      html += `<div class="col-filter-option" data-value="__none__">Nicht zugeordnet</div>`;
    }
    values.forEach(v => {
      const active = columnFilters[key] === v ? ' active' : '';
      html += `<div class="col-filter-option${active}" data-value="${esc(v)}">${esc(v)}</div>`;
    });
    popup.innerHTML = html;

    popup.querySelectorAll('.col-filter-option').forEach(opt => {
      opt.addEventListener('click', () => {
        const val = opt.dataset.value;
        if (val === '') {
          delete columnFilters[key];
        } else {
          columnFilters[key] = val;
        }
        closeFilterPopup();
        currentPage = 1;
        renderActiveFilters();
        renderTable();
      });
    });
  } else {
    popup.innerHTML = `
      <div class="col-filter-header">${esc(th.textContent.replace(/[▲▼]/g, '').trim())} filtern</div>
      <input type="text" class="col-filter-input" placeholder="Filter…" value="${esc(columnFilters[key] || '')}" />
    `;
    const inp = popup.querySelector('input');
    inp.focus();
    let debounce;
    inp.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(() => {
        if (inp.value.trim()) {
          columnFilters[key] = inp.value.trim();
        } else {
          delete columnFilters[key];
        }
        currentPage = 1;
        renderActiveFilters();
        renderTable();
      }, 200);
    });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeFilterPopup();
      if (e.key === 'Enter') closeFilterPopup();
    });
  }

  document.body.appendChild(popup);
  openFilterPopup = popup;

  // Close on outside click (delayed to avoid immediate close)
  setTimeout(() => {
    const handler = (e) => {
      if (!popup.contains(e.target) && !th.contains(e.target)) {
        closeFilterPopup();
        document.removeEventListener('click', handler);
      }
    };
    document.addEventListener('click', handler);
  }, 0);
}

// ── Active filters display ──
function renderActiveFilters() {
  activeFiltersBar.innerHTML = '';

  const labels = {
    citrix: 'Citrix Worker', server: 'Server - Neue Infrastruktur',
    templates: 'Templates', off_suspended: 'Ausgeschaltet / Suspended', other: 'Sonstige'
  };

  if (activeFilter) {
    const badge = mkBadge(labels[activeFilter] || activeFilter, () => {
      activeFilter = null;
      const u = new URL(window.location);
      u.searchParams.delete('filter');
      window.history.replaceState({}, '', u);
      renderActiveFilters();
      currentPage = 1;
      renderTable();
    });
    activeFiltersBar.appendChild(badge);
  }

  for (const [key, val] of Object.entries(columnFilters)) {
    const th = document.querySelector(`th[data-key="${key}"]`);
    const label = th ? th.textContent.replace(/[▲▼]/g, '').trim() : key;
    const display = val === '__none__' ? 'Nicht zugeordnet' : val;
    const badge = mkBadge(`${label}: ${display}`, () => {
      delete columnFilters[key];
      renderActiveFilters();
      currentPage = 1;
      renderTable();
    });
    activeFiltersBar.appendChild(badge);
  }
}

function mkBadge(text, onRemove) {
  const span = document.createElement('span');
  span.className = 'filter-badge';
  span.innerHTML = `${esc(text)} <button class="filter-badge-remove" title="Filter entfernen">&times;</button>`;
  span.querySelector('button').addEventListener('click', onRemove);
  return span;
}

// ── Filtering ──
function getFilteredSorted() {
  const query = searchInput.value.toLowerCase().trim();
  let filtered = allVMs;

  // Dashboard group filter
  if (activeFilter && filterFns[activeFilter]) {
    filtered = filtered.filter(filterFns[activeFilter]);
  }

  // Column filters
  for (const [key, val] of Object.entries(columnFilters)) {
    if (key === 'customer_name' && val === '__none__') {
      filtered = filtered.filter(vm => !vm.customer_id);
    } else {
      const filterType = document.querySelector(`th[data-key="${key}"]`)?.dataset.filterType;
      if (filterType === 'select') {
        filtered = filtered.filter(vm => {
          let v = vm[key];
          if (Array.isArray(v)) v = v.map(stripCidr).join(', ');
          return String(v ?? '') === val;
        });
      } else {
        const lower = val.toLowerCase();
        filtered = filtered.filter(vm => {
          let v = vm[key];
          if (Array.isArray(v)) v = v.map(stripCidr).join(', ');
          return String(v ?? '').toLowerCase().includes(lower);
        });
      }
    }
  }

  // Text search
  if (query) {
    filtered = filtered.filter(vm => {
      const searchable = [
        vm.hostname, vm.dns_name, vm.operating_system, vm.power_state,
        vm.customer_name, vm.customer_code, vm.cmdb_customer, vm.pricing_class,
        (vm.ip_addresses || []).map(stripCidr).join(', ')
      ].join(' ').toLowerCase();
      return searchable.includes(query);
    });
  }

  // Sort
  const sorted = [...filtered].sort((a, b) => {
    let va = a[sortKey], vb = b[sortKey];
    if (Array.isArray(va)) va = va.join(', ');
    if (Array.isArray(vb)) vb = vb.join(', ');
    if (va == null && vb == null) return 0;
    if (va == null) return 1;
    if (vb == null) return -1;
    if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * sortDir;
    return String(va).localeCompare(String(vb), 'de') * sortDir;
  });

  return sorted;
}

// ── Customer dropdown for inline assignment ──
function customerSelectHtml(vmHostname, currentCustomerId) {
  let html = `<select class="customer-assign" data-hostname="${esc(vmHostname)}">`;
  html += '<option value="">–</option>';
  allCustomers.forEach(c => {
    const sel = (currentCustomerId && String(c.id) === String(currentCustomerId)) ? ' selected' : '';
    html += `<option value="${c.id}"${sel}>${esc(c.code)} – ${esc(c.name)}</option>`;
  });
  html += '</select>';
  return html;
}

// ── Render table ──
function renderTable() {
  const sorted = getFilteredSorted();
  const pageSize = getPageSize();
  const totalPages = pageSize === Infinity ? 1 : Math.max(1, Math.ceil(sorted.length / pageSize));
  if (currentPage > totalPages) currentPage = totalPages;

  const start = pageSize === Infinity ? 0 : (currentPage - 1) * pageSize;
  const pageData = pageSize === Infinity ? sorted : sorted.slice(start, start + pageSize);

  const fromNum = sorted.length === 0 ? 0 : start + 1;
  vmCount.textContent = `${fromNum}\u2013${start + pageData.length} von ${sorted.length} VMs`;

  if (sorted.length === 0) {
    vmRows.innerHTML = `<tr><td colspan="${COLS}" class="empty">Keine VMs gefunden.</td></tr>`;
    pagination.innerHTML = '';
    return;
  }

  const canAssign = currentUser && (currentUser.role === 'admin' || currentUser.role === 'operator');

  vmRows.innerHTML = '';
  for (const vm of pageData) {
    const tr = document.createElement('tr');
    const ips = (vm.ip_addresses || []).map(stripCidr).join(', ');
    const stateClass =
      vm.power_state === 'On' ? 'state-on' :
      vm.power_state === 'Off' ? 'state-off' :
      vm.power_state === 'Suspended' ? 'state-suspended' : '';

    const customerCell = canAssign
      ? customerSelectHtml(vm.hostname, vm.customer_id)
      : esc(vm.customer_name ? `${vm.customer_code} – ${vm.customer_name}` : '');

    tr.innerHTML = `
      <td>${esc(vm.hostname)}</td>
      <td class="customer-cell">${customerCell}</td>
      <td>${esc(vm.cmdb_customer || '')}</td>
      <td class="ip-cell">${esc(ips)}</td>
      <td class="os-cell">${esc(vm.operating_system || '')}</td>
      <td>${vm.vcpu ?? ''}</td>
      <td>${vm.vram_mb ?? ''}</td>
      <td>${vm.used_storage_gb ?? ''}</td>
      <td>${vm.provisioned_storage_gb ?? ''}</td>
      <td class="${stateClass}">${esc(vm.power_state || '')}</td>
      <td class="num">${vm.points != null ? formatNum(vm.points) : ''}</td>
      <td>${esc(vm.pricing_class || '')}</td>
      <td class="num">${vm.price != null ? formatCurrency(vm.price) : ''}</td>
    `;
    vmRows.appendChild(tr);
  }

  // Customer assignment handlers
  document.querySelectorAll('.customer-assign').forEach(sel => {
    sel.addEventListener('change', async function () {
      const hostname = this.dataset.hostname;
      const customerId = this.value ? parseInt(this.value) : 0;
      this.disabled = true;
      try {
        await fetch('/api/vm-customer.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ hostname, customer_id: customerId }),
        });
        allVMs.forEach(vm => {
          if (vm.hostname === hostname) {
            vm.customer_id = customerId || null;
            const c = allCustomers.find(x => x.id === customerId);
            vm.customer_code = c ? c.code : null;
            vm.customer_name = c ? c.name : null;
          }
        });
      } catch (e) { console.error(e); }
      this.disabled = false;
    });
  });

  renderPagination(totalPages);

  // Mark headers that have active column filters
  document.querySelectorAll('thead th[data-filterable]').forEach(th => {
    th.classList.toggle('has-filter', !!columnFilters[th.dataset.key]);
  });
}

// ── Pagination ──
function renderPagination(totalPages) {
  pagination.innerHTML = '';
  if (totalPages <= 1) return;

  const addBtn = (label, page, disabled, active) => {
    const btn = document.createElement('button');
    btn.textContent = label;
    btn.className = 'page-btn' + (active ? ' active' : '');
    btn.disabled = disabled;
    if (!disabled && !active) btn.addEventListener('click', () => { currentPage = page; renderTable(); });
    pagination.appendChild(btn);
  };

  addBtn('\u00AB', 1, currentPage === 1, false);
  addBtn('\u2039', currentPage - 1, currentPage === 1, false);

  const maxV = 7;
  let pages = [];
  if (totalPages <= maxV) {
    for (let i = 1; i <= totalPages; i++) pages.push(i);
  } else {
    pages.push(1);
    let rs = Math.max(2, currentPage - 2), re = Math.min(totalPages - 1, currentPage + 2);
    if (currentPage <= 3) re = Math.min(totalPages - 1, 5);
    if (currentPage >= totalPages - 2) rs = Math.max(2, totalPages - 4);
    if (rs > 2) pages.push('...');
    for (let i = rs; i <= re; i++) pages.push(i);
    if (re < totalPages - 1) pages.push('...');
    pages.push(totalPages);
  }

  for (const p of pages) {
    if (p === '...') {
      const s = document.createElement('span');
      s.className = 'page-ellipsis';
      s.textContent = '...';
      pagination.appendChild(s);
    } else {
      addBtn(String(p), p, false, p === currentPage);
    }
  }

  addBtn('\u203A', currentPage + 1, currentPage === totalPages, false);
  addBtn('\u00BB', totalPages, currentPage === totalPages, false);
}

// ── Header click: sort (left area) + filter (filter icon) ──
document.querySelectorAll('thead th[data-key]').forEach(th => {
  // Add filter icon for filterable columns
  if (th.hasAttribute('data-filterable')) {
    const filterIcon = document.createElement('span');
    filterIcon.className = 'filter-icon';
    filterIcon.textContent = '\u25BD';
    filterIcon.title = 'Filtern';
    filterIcon.addEventListener('click', (e) => {
      e.stopPropagation();
      showFilterPopup(th);
    });
    th.appendChild(filterIcon);
  }

  th.addEventListener('click', (e) => {
    // Don't sort when clicking the filter icon
    if (e.target.classList.contains('filter-icon')) return;

    const key = th.dataset.key;
    if (sortKey === key) sortDir *= -1;
    else { sortKey = key; sortDir = 1; }

    document.querySelectorAll('thead th').forEach(h => h.classList.remove('sorted'));
    th.classList.add('sorted');
    th.querySelector('.sort-arrow').textContent = sortDir === 1 ? '\u25B2' : '\u25BC';

    currentPage = 1;
    renderTable();
  });
});

// ── Events ──
monthSelect.addEventListener('change', () => {
  activeFilter = null;
  columnFilters = {};
  const u = new URL(window.location);
  u.searchParams.delete('filter');
  window.history.replaceState({}, '', u);
  renderActiveFilters();
  loadVMs();
});

pageSizeSelect.addEventListener('change', () => { currentPage = 1; renderTable(); });

let searchTimeout;
searchInput.addEventListener('input', () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => { currentPage = 1; renderTable(); }, 150);
});

document.getElementById('export-btn').addEventListener('click', () => {
  const month = monthSelect.value;
  window.location.href = month ? `/api/export.php?month=${month}` : '/api/export.php';
});

function formatNum(val) {
  if (val == null) return '';
  return Number(val).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatCurrency(val) {
  if (val == null) return '';
  return Number(val).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' \u20AC';
}

function formatDate(val) {
  if (!val) return '';
  const iso = val.replace(' ', 'T').replace(/\+(\d{2})$/, '+$1:00');
  const d = new Date(iso);
  if (isNaN(d)) return val;
  return d.toLocaleString('de-DE');
}

function esc(str) {
  if (!str) return '';
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

loadCustomers();
loadMonths().then(loadVMs);
