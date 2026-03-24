// ── Shared auth, sidebar, user menu, and theme logic ──

let currentUser = null;

async function initApp() {
  // Build sidebar immediately (before auth) so layout doesn't jump
  buildSidebar();
  restoreSidebarState();

  try {
    const res = await fetch('/api/auth/me.php');
    if (res.status === 401) {
      if (!window.location.pathname.startsWith('/login')
          && !window.location.pathname.startsWith('/customer')
          && window.location.pathname !== '/') {
        window.location.href = '/login.html';
      }
      return;
    }
    currentUser = await res.json();
    applyTheme(currentUser.theme);
    renderSidebarUser();
    updateSidebarAdmin();
  } catch (err) {
    console.error('Auth check failed:', err);
  }
}

// ── Theme ──
function applyTheme(pref) {
  const html = document.documentElement;
  if (pref === 'dark') {
    html.setAttribute('data-theme', 'dark');
  } else if (pref === 'light') {
    html.setAttribute('data-theme', 'light');
  } else {
    const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    html.setAttribute('data-theme', dark ? 'dark' : 'light');
  }
}

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
  if (currentUser && currentUser.theme === 'system') {
    applyTheme('system');
  }
});

// ── Sidebar ──
function buildSidebar() {
  // Don't build sidebar on login or customer pages
  if (window.location.pathname.startsWith('/login')) return;
  if (window.location.pathname.startsWith('/customer') || window.location.pathname === '/') return;

  const path = window.location.pathname;

  const sidebar = document.createElement('div');
  sidebar.className = 'sidebar';
  sidebar.id = 'app-sidebar';
  sidebar.innerHTML = `
    <div class="sidebar-header">
      <button class="sidebar-toggle" id="sidebar-toggle" title="Menü ein-/ausklappen">&#9776;</button>
      <a href="/it/" class="sidebar-brand" style="text-decoration:none;color:inherit;">IT Portal</a>
    </div>
    <nav class="sidebar-nav">
      <div class="sidebar-section">
        <a href="/it/" class="sidebar-link ${path === '/it/' || path === '/it' || path === '/index.html' ? 'active' : ''}">
          <span class="icon">&#x1F4CA;</span><span class="label">Dashboard</span>
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-title">Rohdaten</div>
        <a href="/vms.html" class="sidebar-link ${path === '/vms.html' ? 'active' : ''}">
          <span class="icon">&#x1F5A5;</span><span class="label">Virtuelle Maschinen</span>
        </a>
        <a href="/licenses.html" class="sidebar-link ${path === '/licenses.html' ? 'active' : ''}">
          <span class="icon">&#x1F4BB;</span><span class="label">Microsoft 365 Lizenzen</span>
        </a>
        <a href="/devices.html" class="sidebar-link ${path === '/devices.html' ? 'active' : ''}">
          <span class="icon">&#x1F4F1;</span><span class="label">Notebooks / Clients</span>
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-title">Abrechnung</div>
        <a href="/billing/" class="sidebar-link ${path === '/billing/' || path === '/billing/index.html' ? 'active' : ''}">
          <span class="icon">&#x1F4B0;</span><span class="label">Gesamtabrechnung</span>
        </a>
        <a href="/billing/iaas.html" class="sidebar-link ${path === '/billing/iaas.html' ? 'active' : ''}">
          <span class="icon">&#x1F5A5;</span><span class="label">IaaS Server Hosting</span>
        </a>
        <a href="/billing/licenses.html" class="sidebar-link ${path === '/billing/licenses.html' ? 'active' : ''}">
          <span class="icon">&#x1F4BB;</span><span class="label">Microsoft 365 Lizenzen</span>
        </a>
        <a href="/billing/devices.html" class="sidebar-link ${path === '/billing/devices.html' ? 'active' : ''}">
          <span class="icon">&#x1F4F1;</span><span class="label">Client Services</span>
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-title">Einstellungen</div>
        <a href="/stammdaten/" class="sidebar-link ${path.startsWith('/stammdaten') ? 'active' : ''}">
          <span class="icon">&#x1F4C1;</span><span class="label">Stammdaten</span>
        </a>
        <a href="/admin/vault.html" class="sidebar-link ${path === '/admin/vault.html' ? 'active' : ''}">
          <span class="icon">&#x1F512;</span><span class="label">Vault</span>
        </a>
        <a href="/admin/" class="sidebar-link ${path.startsWith('/admin/') && path !== '/admin/vault.html' ? 'active' : ''}" id="sidebar-admin-link" style="display:none;">
          <span class="icon">&#x2699;</span><span class="label">Administration</span>
        </a>
      </div>
    </nav>
    <div class="sidebar-footer" id="sidebar-user-area"></div>
  `;

  document.body.prepend(sidebar);

  // Wrap existing content in main-content div
  const mainContent = document.createElement('div');
  mainContent.className = 'main-content';
  while (sidebar.nextSibling) {
    mainContent.appendChild(sidebar.nextSibling);
  }
  document.body.appendChild(mainContent);

  // Remove old navbar if present
  const oldNavbar = mainContent.querySelector('.navbar');
  if (oldNavbar) oldNavbar.remove();

  // Toggle handler
  document.getElementById('sidebar-toggle').addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebar-collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
    // Update main-content margin
    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '56px' : '240px';
  });
}

function restoreSidebarState() {
  const sidebar = document.getElementById('app-sidebar');
  if (!sidebar) return;
  if (localStorage.getItem('sidebar-collapsed') === '1') {
    sidebar.classList.add('collapsed');
    const main = document.querySelector('.main-content');
    if (main) main.style.marginLeft = '56px';
  }
}

function updateSidebarAdmin() {
  if (!currentUser) return;
  if (currentUser.role === 'admin') {
    const link = document.getElementById('sidebar-admin-link');
    if (link) link.style.display = '';
  }
}

function renderSidebarUser() {
  if (!currentUser) return;
  const area = document.getElementById('sidebar-user-area');
  if (!area) return;

  const initial = (currentUser.display_name || currentUser.username || '?')[0].toUpperCase();

  area.innerHTML = `
    <div class="sidebar-user">
      <button class="sidebar-user-toggle" id="sidebar-user-toggle" type="button">
        <span class="user-avatar">${esc(initial)}</span>
        <span class="sidebar-user-name">${esc(currentUser.display_name || currentUser.username)}</span>
      </button>
      <div class="user-dropdown-menu" id="user-dropdown">
        <div class="user-dropdown-header">
          <strong>${esc(currentUser.display_name || currentUser.username)}</strong>
          <span class="user-role-badge">${esc(currentUser.role)}</span>
        </div>
        <a href="/settings.html">Einstellungen</a>
        <div class="user-dropdown-divider"></div>
        <a href="#" id="logout-btn">Abmelden</a>
      </div>
    </div>
  `;

  const toggleBtn = document.getElementById('sidebar-user-toggle');
  const dropdown = document.getElementById('user-dropdown');

  toggleBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    dropdown.classList.toggle('open');
  });

  document.addEventListener('click', (e) => {
    if (!area.contains(e.target)) {
      dropdown.classList.remove('open');
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') dropdown.classList.remove('open');
  });

  document.getElementById('logout-btn').addEventListener('click', async (e) => {
    e.preventDefault();
    await fetch('/api/auth/logout.php', { method: 'POST' });
    window.location.href = '/login.html';
  });
}

// Legacy compat: renderUserMenu does nothing now (sidebar handles it)
function renderUserMenu() {}

function esc(str) {
  if (!str) return '';
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

// ── Toast notifications ──
function showToast(message, type = 'info', duration = 4000) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container';
    document.body.appendChild(container);
  }

  const icons = { success: '\u2713', error: '\u2717', warning: '\u26A0', info: '\u2139' };

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `
    <span class="toast-icon">${icons[type] || icons.info}</span>
    <span class="toast-message">${esc(message)}</span>
    <button class="toast-close">&times;</button>
  `;

  toast.querySelector('.toast-close').addEventListener('click', () => removeToast(toast));
  container.appendChild(toast);

  if (duration > 0) {
    setTimeout(() => removeToast(toast), duration);
  }

  return toast;
}

function removeToast(toast) {
  if (!toast || toast.classList.contains('toast-out')) return;
  toast.classList.add('toast-out');
  toast.addEventListener('animationend', () => toast.remove());
}

// Apply dark theme immediately to prevent flash
document.documentElement.setAttribute('data-theme', 'dark');

initApp();
