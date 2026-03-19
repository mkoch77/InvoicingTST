// ── Shared auth, sidebar, user menu, and theme logic ──

let currentUser = null;

async function initApp() {
  // Build sidebar immediately (before auth) so layout doesn't jump
  buildSidebar();
  restoreSidebarState();

  try {
    const res = await fetch('/api/auth/me.php');
    if (res.status === 401) {
      if (!window.location.pathname.startsWith('/login')) {
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
  // Don't build sidebar on login page
  if (window.location.pathname.startsWith('/login')) return;

  const path = window.location.pathname;

  const sidebar = document.createElement('div');
  sidebar.className = 'sidebar';
  sidebar.id = 'app-sidebar';
  sidebar.innerHTML = `
    <div class="sidebar-header">
      <button class="sidebar-toggle" id="sidebar-toggle" title="Menü ein-/ausklappen">&#9776;</button>
      <span class="sidebar-brand">Accounting</span>
    </div>
    <nav class="sidebar-nav">
      <div class="sidebar-section">
        <a href="/" class="sidebar-link ${path === '/' || path === '/index.html' ? 'active' : ''}">
          <span class="icon">&#x1F4CA;</span><span class="label">Dashboard</span>
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-title">Rohdaten</div>
        <a href="/vms.html" class="sidebar-link ${path === '/vms.html' ? 'active' : ''}">
          <span class="icon">&#x1F5A5;</span><span class="label">Virtuelle Maschinen</span>
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-title">Einstellungen</div>
        <a href="/stammdaten/customers.html" class="sidebar-link ${path === '/stammdaten/customers.html' ? 'active' : ''}">
          <span class="icon">&#x1F465;</span><span class="label">Kundenkürzel</span>
        </a>
        <a href="/stammdaten/pricing.html" class="sidebar-link ${path === '/stammdaten/pricing.html' ? 'active' : ''}">
          <span class="icon">&#x1F4B0;</span><span class="label">Preisklassen</span>
        </a>
        <a href="/admin/vault.html" class="sidebar-link ${path === '/admin/vault.html' ? 'active' : ''}">
          <span class="icon">&#x1F512;</span><span class="label">Vault</span>
        </a>
      </div>
      <div class="sidebar-section" id="sidebar-admin-section" style="display:none;">
        <div class="sidebar-section-title">Administration</div>
        <a href="/admin/users.html" class="sidebar-link ${path === '/admin/users.html' ? 'active' : ''}">
          <span class="icon">&#x1F6E1;</span><span class="label">Benutzerverwaltung</span>
        </a>
        <a href="/cmdb.html" class="sidebar-link ${path === '/cmdb.html' ? 'active' : ''}">
          <span class="icon">&#x1F4E6;</span><span class="label">CMDB Browser</span>
        </a>
        <a href="/admin/logs.html" class="sidebar-link ${path === '/admin/logs.html' ? 'active' : ''}">
          <span class="icon">&#x1F4CB;</span><span class="label">Logs</span>
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
    const section = document.getElementById('sidebar-admin-section');
    if (section) section.style.display = '';
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
