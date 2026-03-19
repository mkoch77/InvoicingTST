// ── Shared auth, user menu, and theme logic ──

let currentUser = null;

async function initApp() {
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
    renderUserMenu();
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
    // system preference
    const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    html.setAttribute('data-theme', dark ? 'dark' : 'light');
  }
}

// Listen for system theme changes
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
  if (currentUser && currentUser.theme === 'system') {
    applyTheme('system');
  }
});

// ── User menu in navbar ──
function renderUserMenu() {
  if (!currentUser) return;

  const navbar = document.querySelector('.navbar');
  if (!navbar || document.getElementById('user-menu')) return;

  const initial = (currentUser.display_name || currentUser.username || '?')[0].toUpperCase();

  const menu = document.createElement('div');
  menu.id = 'user-menu';
  menu.className = 'user-menu';
  menu.innerHTML = `
    <button class="user-toggle" id="user-toggle-btn" type="button">
      <span class="user-avatar">${esc(initial)}</span>
      <span class="user-name">${esc(currentUser.display_name || currentUser.username)}</span>
    </button>
    <div class="user-dropdown-menu" id="user-dropdown">
      <div class="user-dropdown-header">
        <strong>${esc(currentUser.display_name || currentUser.username)}</strong>
        <span class="user-role-badge">${esc(currentUser.role)}</span>
      </div>
      <a href="/settings.html">Einstellungen</a>
      ${currentUser.role === 'admin' ? '<a href="/admin/users.html">Benutzerverwaltung</a>' : ''}
      <div class="user-dropdown-divider"></div>
      <a href="#" id="logout-btn">Abmelden</a>
    </div>
  `;

  navbar.appendChild(menu);

  // Click-based toggle
  const toggleBtn = document.getElementById('user-toggle-btn');
  const dropdown = document.getElementById('user-dropdown');

  toggleBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    dropdown.classList.toggle('open');
  });

  // Close on click outside
  document.addEventListener('click', (e) => {
    if (!menu.contains(e.target)) {
      dropdown.classList.remove('open');
    }
  });

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') dropdown.classList.remove('open');
  });

  document.getElementById('logout-btn').addEventListener('click', async (e) => {
    e.preventDefault();
    await fetch('/api/auth/logout.php', { method: 'POST' });
    window.location.href = '/login.html';
  });
}

function esc(str) {
  if (!str) return '';
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

// Apply dark theme immediately to prevent flash
document.documentElement.setAttribute('data-theme', 'dark');

initApp();
