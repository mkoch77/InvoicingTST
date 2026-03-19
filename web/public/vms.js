const monthSelect = document.getElementById('month-select');
const osSelect = document.getElementById('os-select');
const customerSelect = document.getElementById('customer-select');
const searchInput = document.getElementById('search-input');
const pageSizeSelect = document.getElementById('page-size');
const vmRows = document.getElementById('vm-rows');
const vmCount = document.getElementById('vm-count');
const pagination = document.getElementById('pagination');

let allVMs = [];
let allCustomers = [];
let sortKey = 'hostname';
let sortDir = 1;
let currentPage = 1;
let activeFilter = null;

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

function stripCidr(ip) {
  return ip.replace(/\/\d+$/, '');
}

// ── Load customers for dropdown + assignment ──
async function loadCustomers() {
  try {
    const res = await fetch('/api/customers.php');
    allCustomers = await res.json();
    populateCustomerFilter();
  } catch (e) { console.error(e); }
}

function populateCustomerFilter() {
  const prev = customerSelect.value;
  // Keep first two options ("Alle" + "Nicht zugeordnet")
  customerSelect.length = 2;
  allCustomers.forEach(c => {
    const opt = document.createElement('option');
    opt.value = String(c.id);
    opt.textContent = `${c.code} – ${c.name}`;
    customerSelect.appendChild(opt);
  });
  if (prev) customerSelect.value = prev;
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
    if (urlMonth && months.includes(urlMonth)) {
      monthSelect.value = urlMonth;
    } else if (months.length > 0) {
      monthSelect.value = months[0];
    }
  } catch (err) {
    console.error('Failed to load months:', err);
  }
}

function populateOsFilter() {
  const prev = osSelect.value;
  const osSet = new Set();
  allVMs.forEach(vm => { if (vm.operating_system) osSet.add(vm.operating_system); });
  const sorted = [...osSet].sort((a, b) => a.localeCompare(b, 'de'));
  osSelect.length = 1;
  sorted.forEach(os => {
    const opt = document.createElement('option');
    opt.value = os;
    opt.textContent = os;
    osSelect.appendChild(opt);
  });
  if (prev && sorted.includes(prev)) osSelect.value = prev;
}

// ── Fetch VM data ──
async function loadVMs() {
  const month = monthSelect.value;
  const url = month ? `/api/vms.php?month=${month}` : '/api/vms.php';
  vmRows.innerHTML = '<tr><td colspan="11" class="empty">Laden…</td></tr>';

  try {
    const res = await fetch(url);
    allVMs = await res.json();

    // Auto-assign customers if any VMs are unassigned
    const hasUnassigned = allVMs.some(vm => !vm.customer_id);
    if (hasUnassigned && month) {
      await fetch('/api/vm-customer.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ month }),
      });
      // Reload to get updated assignments
      const res2 = await fetch(url);
      allVMs = await res2.json();
    }

    currentPage = 1;
    populateOsFilter();
    renderFilterBadge();
    renderTable();
  } catch (err) {
    vmRows.innerHTML = `<tr><td colspan="11" class="empty">Fehler: ${esc(err.message)}</td></tr>`;
  }
}

// ── Filter badge ──
function renderFilterBadge() {
  const existing = document.getElementById('filter-badge');
  if (existing) existing.remove();
  if (!activeFilter) return;

  const labels = {
    citrix: 'Citrix Worker', server: 'Server - Neue Infrastruktur',
    templates: 'Templates', off_suspended: 'Ausgeschaltet / Suspended', other: 'Sonstige'
  };

  const badge = document.createElement('span');
  badge.id = 'filter-badge';
  badge.className = 'filter-badge';
  badge.innerHTML = `${esc(labels[activeFilter] || activeFilter)} <button class="filter-badge-remove" title="Filter entfernen">&times;</button>`;
  badge.querySelector('button').addEventListener('click', () => {
    activeFilter = null;
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

  if (activeFilter && filterFns[activeFilter]) {
    filtered = filtered.filter(filterFns[activeFilter]);
  }

  const selectedOs = osSelect.value;
  if (selectedOs) {
    filtered = filtered.filter(vm => vm.operating_system === selectedOs);
  }

  const selectedCustomer = customerSelect.value;
  if (selectedCustomer === '__none__') {
    filtered = filtered.filter(vm => !vm.customer_id);
  } else if (selectedCustomer) {
    filtered = filtered.filter(vm => String(vm.customer_id) === selectedCustomer);
  }

  if (query) {
    filtered = filtered.filter(vm => {
      const searchable = [
        vm.hostname, vm.dns_name, vm.operating_system, vm.power_state,
        vm.customer_name, vm.customer_code,
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
    if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * sortDir;
    return String(va).localeCompare(String(vb), 'de') * sortDir;
  });

  return sorted;
}

// ── Check if hostname has an auto-detectable customer code ──
function hasAutoCode(hostname) {
  const h = (hostname || '').toUpperCase();
  if (/^F[023]/.test(h) && h.length >= 5) {
    const code = h.substring(2, 5);
    return allCustomers.some(c => c.code.toUpperCase() === code);
  }
  return false;
}

// ── Build customer select HTML for manual assignment (only for VMs without auto-code) ──
function customerSelectHtml(vmHostname, currentCustomerId) {
  let html = `<select class="customer-assign" data-hostname="${esc(vmHostname)}" title="Kunde manuell zuordnen">`;
  html += '<option value="">– Kein Kunde –</option>';
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
  const toNum = start + pageData.length;
  vmCount.textContent = `${fromNum}\u2013${toNum} von ${sorted.length} VMs`;

  if (sorted.length === 0) {
    vmRows.innerHTML = '<tr><td colspan="11" class="empty">Keine VMs gefunden.</td></tr>';
    pagination.innerHTML = '';
    return;
  }

  vmRows.innerHTML = '';
  const canAssign = currentUser && (currentUser.role === 'admin' || currentUser.role === 'operator');

  for (const vm of pageData) {
    const tr = document.createElement('tr');
    const ips = (vm.ip_addresses || []).map(stripCidr).join(', ');
    const stateClass =
      vm.power_state === 'On' ? 'state-on' :
      vm.power_state === 'Off' ? 'state-off' :
      vm.power_state === 'Suspended' ? 'state-suspended' : '';

    let customerCell;
    if (canAssign) {
      customerCell = customerSelectHtml(vm.hostname, vm.customer_id);
    } else {
      customerCell = vm.customer_name ? esc(`${vm.customer_code} – ${vm.customer_name}`) : '';
    }

    tr.innerHTML = `
      <td>${esc(vm.hostname)}</td>
      <td>${esc(vm.dns_name || '')}</td>
      <td class="customer-cell">${customerCell}</td>
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

  // Attach change handlers for customer assignment selects
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
        // Update local data
        allVMs.forEach(vm => {
          if (vm.hostname === hostname) {
            vm.customer_id = customerId || null;
            const c = allCustomers.find(x => x.id === customerId);
            vm.customer_code = c ? c.code : null;
            vm.customer_name = c ? c.name : null;
          }
        });
      } catch (e) {
        console.error('Assignment failed:', e);
      }
      this.disabled = false;
    });
  });

  renderPagination(totalPages);
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
    if (sortKey === key) { sortDir *= -1; } else { sortKey = key; sortDir = 1; }
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

pageSizeSelect.addEventListener('change', () => { currentPage = 1; renderTable(); });
osSelect.addEventListener('change', () => { currentPage = 1; renderTable(); });
customerSelect.addEventListener('change', () => { currentPage = 1; renderTable(); });

let searchTimeout;
searchInput.addEventListener('input', () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => { currentPage = 1; renderTable(); }, 150);
});

// ── Auto-assign button ──
document.getElementById('assign-btn').addEventListener('click', async () => {
  const btn = document.getElementById('assign-btn');
  const month = monthSelect.value;
  btn.disabled = true;
  btn.textContent = 'Läuft…';
  try {
    const res = await fetch('/api/vm-customer.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ month }),
    });
    const data = await res.json();
    btn.textContent = `${data.updated} aktualisiert`;
    await loadVMs();
    setTimeout(() => { btn.textContent = 'Zuordnung'; }, 2000);
  } catch (e) {
    btn.textContent = 'Fehler';
    setTimeout(() => { btn.textContent = 'Zuordnung'; }, 2000);
  }
  btn.disabled = false;
});

// ── Excel export ──
document.getElementById('export-btn').addEventListener('click', () => {
  const month = monthSelect.value;
  window.location.href = month ? `/api/export.php?month=${month}` : '/api/export.php';
});

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

// Init
loadCustomers();
loadMonths().then(loadVMs);
