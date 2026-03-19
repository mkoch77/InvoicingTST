const monthSelect = document.getElementById('month-select');
const searchInput = document.getElementById('search-input');
const pageSizeSelect = document.getElementById('page-size');
const vmRows = document.getElementById('vm-rows');
const vmCount = document.getElementById('vm-count');
const pagination = document.getElementById('pagination');

let allVMs = [];
let sortKey = 'hostname';
let sortDir = 1;
let currentPage = 1;
let activeFilter = null; // group filter from URL param

// ── URL parameter handling ──
const urlParams = new URLSearchParams(window.location.search);
const urlMonth = urlParams.get('month');
const urlFilter = urlParams.get('filter');
if (urlFilter) activeFilter = urlFilter;

// ── Filter definitions matching dashboard groups ──
const filterFns = {
  citrix:        vm => (vm.hostname || '').toUpperCase().startsWith('CLT'),
  server:        vm => (vm.hostname || '').toUpperCase().startsWith('F0'),
  templates:     vm => (vm.hostname || '').toUpperCase().includes('TEMP'),
  off_suspended: vm => vm.power_state === 'Off' || vm.power_state === 'Suspended',
  other:         vm => {
    const h = (vm.hostname || '').toUpperCase();
    return !h.startsWith('CLT') && !h.startsWith('F0') && !h.includes('TEMP');
  }
};

function getPageSize() {
  const val = pageSizeSelect.value;
  return val === 'all' ? Infinity : parseInt(val, 10);
}

function stripCidr(ip) {
  return ip.replace(/\/\d+$/, '');
}

// ── Months dropdown ──
async function loadMonths() {
  try {
    const res = await fetch('/api/months.php');
    const months = await res.json();
    months.forEach(m => {
      const opt = document.createElement('option');
      opt.value = m;
      const [y, mo] = m.split('-');
      const date = new Date(y, parseInt(mo) - 1);
      opt.textContent = date.toLocaleDateString('de-DE', { year: 'numeric', month: 'long' });
      monthSelect.appendChild(opt);
    });
    // URL month param takes priority, then default to latest
    if (urlMonth && months.includes(urlMonth)) {
      monthSelect.value = urlMonth;
    } else if (months.length > 0) {
      monthSelect.value = months[0];
    }
  } catch (err) {
    console.error('Failed to load months:', err);
  }
}

// ── Fetch VM data ──
async function loadVMs() {
  const month = monthSelect.value;
  const url = month ? `/api/vms.php?month=${month}` : '/api/vms.php';
  vmRows.innerHTML = '<tr><td colspan="10" class="empty">Laden…</td></tr>';

  try {
    const res = await fetch(url);
    allVMs = await res.json();
    currentPage = 1;
    renderFilterBadge();
    renderTable();
  } catch (err) {
    vmRows.innerHTML = `<tr><td colspan="10" class="empty">Fehler: ${esc(err.message)}</td></tr>`;
  }
}

// ── Show active filter badge ──
function renderFilterBadge() {
  const existing = document.getElementById('filter-badge');
  if (existing) existing.remove();

  if (!activeFilter) return;

  const labels = {
    citrix: 'Citrix Worker',
    server: 'Server - Neue Infrastruktur',
    templates: 'Templates',
    off_suspended: 'Ausgeschaltet / Suspended',
    other: 'Sonstige'
  };

  const badge = document.createElement('span');
  badge.id = 'filter-badge';
  badge.className = 'filter-badge';
  badge.innerHTML = `${esc(labels[activeFilter] || activeFilter)} <button class="filter-badge-remove" title="Filter entfernen">&times;</button>`;
  badge.querySelector('button').addEventListener('click', () => {
    activeFilter = null;
    // Clean URL
    const u = new URL(window.location);
    u.searchParams.delete('filter');
    window.history.replaceState({}, '', u);
    renderFilterBadge();
    currentPage = 1;
    renderTable();
  });

  const controls = document.querySelector('.controls');
  controls.insertBefore(badge, document.getElementById('vm-count'));
}

// ── Get filtered + sorted data ──
function getFilteredSorted() {
  const query = searchInput.value.toLowerCase().trim();

  let filtered = allVMs;

  // Apply group filter first
  if (activeFilter && filterFns[activeFilter]) {
    filtered = filtered.filter(filterFns[activeFilter]);
  }

  // Then text search
  if (query) {
    filtered = filtered.filter(vm => {
      const searchable = [
        vm.hostname, vm.dns_name, vm.operating_system, vm.power_state,
        (vm.ip_addresses || []).map(stripCidr).join(', ')
      ].join(' ').toLowerCase();
      return searchable.includes(query);
    });
  }

  const sorted = [...filtered].sort((a, b) => {
    let va = a[sortKey];
    let vb = b[sortKey];

    if (Array.isArray(va)) va = va.join(', ');
    if (Array.isArray(vb)) vb = vb.join(', ');

    if (va == null && vb == null) return 0;
    if (va == null) return 1;
    if (vb == null) return -1;

    if (typeof va === 'number' && typeof vb === 'number') {
      return (va - vb) * sortDir;
    }

    return String(va).localeCompare(String(vb), 'de') * sortDir;
  });

  return sorted;
}

// ── Sort + Filter + Paginate + Render ──
function renderTable() {
  const sorted = getFilteredSorted();
  const pageSize = getPageSize();
  const totalPages = pageSize === Infinity ? 1 : Math.max(1, Math.ceil(sorted.length / pageSize));

  if (currentPage > totalPages) currentPage = totalPages;

  const start = pageSize === Infinity ? 0 : (currentPage - 1) * pageSize;
  const pageData = pageSize === Infinity ? sorted : sorted.slice(start, start + pageSize);

  const fromNum = sorted.length === 0 ? 0 : start + 1;
  const toNum = start + pageData.length;
  vmCount.textContent = `${fromNum}\u2013${toNum} von ${sorted.length} VMs`;

  if (sorted.length === 0) {
    vmRows.innerHTML = '<tr><td colspan="10" class="empty">Keine VMs gefunden.</td></tr>';
    pagination.innerHTML = '';
    return;
  }

  vmRows.innerHTML = '';
  for (const vm of pageData) {
    const tr = document.createElement('tr');
    const ips = (vm.ip_addresses || []).map(stripCidr).join(', ');
    const stateClass =
      vm.power_state === 'On' ? 'state-on' :
      vm.power_state === 'Off' ? 'state-off' :
      vm.power_state === 'Suspended' ? 'state-suspended' : '';

    tr.innerHTML = `
      <td>${esc(vm.hostname)}</td>
      <td>${esc(vm.dns_name || '')}</td>
      <td class="ip-cell">${esc(ips)}</td>
      <td class="os-cell">${esc(vm.operating_system || '')}</td>
      <td>${vm.vcpu ?? ''}</td>
      <td>${vm.vram_mb ?? ''}</td>
      <td>${vm.used_storage_gb ?? ''}</td>
      <td>${vm.provisioned_storage_gb ?? ''}</td>
      <td class="${stateClass}">${esc(vm.power_state || '')}</td>
      <td>${esc(formatDate(vm.exported_at))}</td>
    `;
    vmRows.appendChild(tr);
  }

  renderPagination(totalPages);
}

// ── Pagination controls ──
function renderPagination(totalPages) {
  pagination.innerHTML = '';
  if (totalPages <= 1) return;

  const addBtn = (label, page, disabled, active) => {
    const btn = document.createElement('button');
    btn.textContent = label;
    btn.className = 'page-btn' + (active ? ' active' : '');
    btn.disabled = disabled;
    if (!disabled && !active) {
      btn.addEventListener('click', () => { currentPage = page; renderTable(); });
    }
    pagination.appendChild(btn);
  };

  addBtn('\u00AB', 1, currentPage === 1, false);
  addBtn('\u2039', currentPage - 1, currentPage === 1, false);

  const maxVisible = 7;
  let pages = [];
  if (totalPages <= maxVisible) {
    for (let i = 1; i <= totalPages; i++) pages.push(i);
  } else {
    pages.push(1);
    let rangeStart = Math.max(2, currentPage - 2);
    let rangeEnd = Math.min(totalPages - 1, currentPage + 2);
    if (currentPage <= 3) rangeEnd = Math.min(totalPages - 1, 5);
    if (currentPage >= totalPages - 2) rangeStart = Math.max(2, totalPages - 4);
    if (rangeStart > 2) pages.push('...');
    for (let i = rangeStart; i <= rangeEnd; i++) pages.push(i);
    if (rangeEnd < totalPages - 1) pages.push('...');
    pages.push(totalPages);
  }

  for (const p of pages) {
    if (p === '...') {
      const span = document.createElement('span');
      span.className = 'page-ellipsis';
      span.textContent = '...';
      pagination.appendChild(span);
    } else {
      addBtn(String(p), p, false, p === currentPage);
    }
  }

  addBtn('\u203A', currentPage + 1, currentPage === totalPages, false);
  addBtn('\u00BB', totalPages, currentPage === totalPages, false);
}

// ── Sort click handler ──
document.querySelectorAll('thead th[data-key]').forEach(th => {
  th.addEventListener('click', () => {
    const key = th.dataset.key;
    if (sortKey === key) {
      sortDir *= -1;
    } else {
      sortKey = key;
      sortDir = 1;
    }

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
  const u = new URL(window.location);
  u.searchParams.delete('filter');
  window.history.replaceState({}, '', u);
  renderFilterBadge();
  loadVMs();
});

pageSizeSelect.addEventListener('change', () => {
  currentPage = 1;
  renderTable();
});

let searchTimeout;
searchInput.addEventListener('input', () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => { currentPage = 1; renderTable(); }, 150);
});

// ── Excel export ──
document.getElementById('export-btn').addEventListener('click', () => {
  const month = monthSelect.value;
  const url = month ? `/api/export.php?month=${month}` : '/api/export.php';
  window.location.href = url;
});

function formatDate(val) {
  if (!val) return '';
  const iso = val.replace(' ', 'T').replace(/\+(\d{2})$/, '+$1:00');
  const d = new Date(iso);
  if (isNaN(d)) return val;
  return d.toLocaleString('de-DE');
}

function esc(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

loadMonths().then(loadVMs);
