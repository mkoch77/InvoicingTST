const monthSelect = document.getElementById('month-select');
const searchInput = document.getElementById('search-input');
const vmRows = document.getElementById('vm-rows');
const vmCount = document.getElementById('vm-count');

let allVMs = [];
let sortKey = 'hostname';
let sortDir = 1; // 1 = asc, -1 = desc

// ── Months dropdown ──
async function loadMonths() {
  try {
    const res = await fetch('/api/months');
    const months = await res.json();
    months.forEach(m => {
      const opt = document.createElement('option');
      opt.value = m;
      const [y, mo] = m.split('-');
      const date = new Date(y, parseInt(mo) - 1);
      opt.textContent = date.toLocaleDateString('de-DE', { year: 'numeric', month: 'long' });
      monthSelect.appendChild(opt);
    });
    if (months.length > 0) monthSelect.value = months[0];
  } catch (err) {
    console.error('Failed to load months:', err);
  }
}

// ── Fetch VM data ──
async function loadVMs() {
  const month = monthSelect.value;
  const url = month ? `/api/vms?month=${month}` : '/api/vms';
  vmRows.innerHTML = '<tr><td colspan="9" class="empty">Laden…</td></tr>';

  try {
    const res = await fetch(url);
    allVMs = await res.json();
    renderTable();
  } catch (err) {
    vmRows.innerHTML = `<tr><td colspan="9" class="empty">Fehler: ${esc(err.message)}</td></tr>`;
  }
}

// ── Sort + Filter + Render ──
function renderTable() {
  const query = searchInput.value.toLowerCase().trim();

  // Filter
  let filtered = allVMs;
  if (query) {
    filtered = allVMs.filter(vm => {
      const searchable = [
        vm.hostname, vm.dns_name, vm.operating_system, vm.power_state,
        (vm.ip_addresses || []).join(', ')
      ].join(' ').toLowerCase();
      return searchable.includes(query);
    });
  }

  // Sort
  const sorted = [...filtered].sort((a, b) => {
    let va = a[sortKey];
    let vb = b[sortKey];

    // Handle arrays (ip_addresses)
    if (Array.isArray(va)) va = va.join(', ');
    if (Array.isArray(vb)) vb = vb.join(', ');

    // Nulls last
    if (va == null && vb == null) return 0;
    if (va == null) return 1;
    if (vb == null) return -1;

    // Numeric
    if (typeof va === 'number' && typeof vb === 'number') {
      return (va - vb) * sortDir;
    }

    // String
    return String(va).localeCompare(String(vb), 'de') * sortDir;
  });

  vmCount.textContent = `${sorted.length} von ${allVMs.length} VMs`;

  if (sorted.length === 0) {
    vmRows.innerHTML = '<tr><td colspan="9" class="empty">Keine VMs gefunden.</td></tr>';
    return;
  }

  vmRows.innerHTML = '';
  for (const vm of sorted) {
    const tr = document.createElement('tr');
    const ips = (vm.ip_addresses || []).join(', ');
    const stateClass =
      vm.power_state === 'On' ? 'state-on' :
      vm.power_state === 'Off' ? 'state-off' :
      vm.power_state === 'Suspended' ? 'state-suspended' : '';

    tr.innerHTML = `
      <td>${esc(vm.hostname)}</td>
      <td>${esc(vm.dns_name || '')}</td>
      <td class="ip-cell">${esc(ips)}</td>
      <td>${esc(vm.operating_system || '')}</td>
      <td>${vm.vcpu ?? ''}</td>
      <td>${vm.vram_mb ?? ''}</td>
      <td>${vm.used_storage_gb ?? ''}</td>
      <td>${vm.provisioned_storage_gb ?? ''}</td>
      <td class="${stateClass}">${esc(vm.power_state || '')}</td>
    `;
    vmRows.appendChild(tr);
  }
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

    // Update arrow indicators
    document.querySelectorAll('thead th').forEach(h => h.classList.remove('sorted'));
    th.classList.add('sorted');
    th.querySelector('.sort-arrow').textContent = sortDir === 1 ? '\u25B2' : '\u25BC';

    renderTable();
  });
});

// ── Events ──
monthSelect.addEventListener('change', loadVMs);

let searchTimeout;
searchInput.addEventListener('input', () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(renderTable, 150);
});

// ── Excel export ──
document.getElementById('export-btn').addEventListener('click', () => {
  const month = monthSelect.value;
  const url = month ? `/api/vms/export?month=${month}` : '/api/vms/export';
  window.location.href = url;
});

function esc(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

loadMonths().then(loadVMs);
