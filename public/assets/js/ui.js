/**
 * ProofDesk UI System & Layout Manager
 */

const UI = {
  initTheme() {
    const savedTheme = localStorage.getItem('proofdesk_theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
  },

  toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('proofdesk_theme', next);
    if (window.lucide) {
      window.lucide.createIcons();
    }
  },

  toast(message, type = 'info', duration = 4000) {
    let container = document.getElementById('proofdesk-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'proofdesk-toast-container';
      container.className = 'toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    let iconName = 'info';
    if (type === 'success') iconName = 'check-circle';
    if (type === 'error') iconName = 'alert-triangle';

    toast.innerHTML = `
      <i data-lucide="${iconName}" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
      <span style="flex: 1;">${message}</span>
      <button style="background: none; border: none; cursor: pointer; color: inherit; padding: 2px;" onclick="this.parentElement.remove()">
        <i data-lucide="x" style="width: 14px; height: 14px;"></i>
      </button>
    `;

    container.appendChild(toast);
    if (window.lucide) {
      window.lucide.createIcons({ root: toast });
    }

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(10px)';
      toast.style.transition = 'all 0.25s ease';
      setTimeout(() => toast.remove(), 250);
    }, duration);
  },

  openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
      modal.classList.add('open');
      if (window.lucide) {
        window.lucide.createIcons({ root: modal });
      }
    }
  },

  closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
      modal.classList.remove('open');
    }
  },

  formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
      minimumFractionDigits: 2
    }).format(Number(amount) || 0);
  },

  formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return isNaN(d.getTime()) ? dateStr : d.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  },

  formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  },

  renderStatusBadge(status) {
    const statusLower = (status || '').toLowerCase();
    let badgeClass = 'badge-muted';
    let label = status;

    switch (statusLower) {
      case 'completed':
      case 'approved':
      case 'paid':
      case 'done':
        badgeClass = 'badge-success';
        break;
      case 'in_progress':
      case 'sent':
        badgeClass = 'badge-primary';
        break;
      case 'in_review':
      case 'pending':
      case 'planning':
        badgeClass = 'badge-warning';
        break;
      case 'revision_requested':
      case 'overdue':
      case 'rejected':
      case 'cancelled':
        badgeClass = 'badge-danger';
        break;
      default:
        badgeClass = 'badge-muted';
    }

    label = statusLower.replace(/_/g, ' ');

    return `
      <span class="badge ${badgeClass}">
        <span class="badge-dot"></span>
        ${label}
      </span>
    `;
  },

  async renderLayout(activePage = 'dashboard') {
    let user = Auth.getUser() || { full_name: 'User', role: 'member', email: '' };
    let ws = Auth.getActiveWorkspace();

    if (!ws || !localStorage.getItem('proofdesk_workspace_id')) {
      try {
        const prof = await API.get('/auth/profile');
        if (prof.data && prof.data.active_workspace) {
          Auth.setActiveWorkspace(prof.data.active_workspace);
          ws = prof.data.active_workspace;
        }
        if (prof.data) {
          user = prof.data;
          localStorage.setItem('proofdesk_user', JSON.stringify(prof.data));
        }
      } catch (e) {
        console.warn('Workspace sync warning:', e);
      }
    }

    ws = ws || { name: 'My Workspace' };
    const wsName = ws.workspaces ? ws.workspaces.name : (ws.name || 'My Workspace');
    const userRole = (ws && ws.role) ? ws.role : (user.role || 'workspace_owner');
    const isSuperAdmin = user.role === 'super_admin';
    const isOwner = userRole === 'workspace_owner' || isSuperAdmin;
    const isClient = userRole === 'client';

    const sidebarHtml = `
      <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-header">
          <a href="dashboard.html" class="brand-logo" title="ProofDesk Home">
            <img src="assets/images/logo.png" alt="ProofDesk" style="height: 32px; max-width: 155px; object-fit: contain;">
          </a>
          <button class="btn btn-icon btn-outline btn-sm mobile-close-btn" onclick="UI.toggleSidebar()" title="Close Navigation">
            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
          </button>
        </div>

        <div class="workspace-selector" onclick="UI.switchWorkspacePrompt()">
          <div class="workspace-info">
            <div class="workspace-badge">${wsName.charAt(0).toUpperCase()}</div>
            <div class="workspace-name">${wsName}</div>
          </div>
          <i data-lucide="chevrons-up-down" style="width: 14px; height: 14px; color: var(--text-muted);"></i>
        </div>

        <nav class="sidebar-nav">
          <div class="nav-section-title">Main</div>
          <a href="dashboard.html" class="nav-item ${activePage === 'dashboard' ? 'active' : ''}">
            <i data-lucide="layout-dashboard"></i>
            <span>Dashboard</span>
          </a>
          <a href="projects.html" class="nav-item ${activePage === 'projects' ? 'active' : ''}">
            <i data-lucide="folder-kanban"></i>
            <span>Projects</span>
          </a>
          <a href="tasks.html" class="nav-item ${activePage === 'tasks' ? 'active' : ''}">
            <i data-lucide="check-square"></i>
            <span>Tasks</span>
          </a>
          <a href="deliverables.html" class="nav-item ${activePage === 'deliverables' ? 'active' : ''}">
            <i data-lucide="layers"></i>
            <span>Deliverables</span>
          </a>
          <a href="approvals.html" class="nav-item ${activePage === 'approvals' ? 'active' : ''}">
            <i data-lucide="stamp"></i>
            <span>Approvals</span>
            <span class="nav-badge" id="sidebarApprovalBadge" style="display: none;">0</span>
          </a>

          ${!isClient ? `
          <div class="nav-section-title">Client Relations</div>
          <a href="clients.html" class="nav-item ${activePage === 'clients' ? 'active' : ''}">
            <i data-lucide="users"></i>
            <span>Clients</span>
          </a>
          <a href="invoices.html" class="nav-item ${activePage === 'invoices' ? 'active' : ''}">
            <i data-lucide="receipt"></i>
            <span>Invoices</span>
          </a>
          <a href="reports.html" class="nav-item ${activePage === 'reports' ? 'active' : ''}">
            <i data-lucide="bar-chart-3"></i>
            <span>Reports</span>
          </a>
          ` : `
          <div class="nav-section-title">Billing</div>
          <a href="invoices.html" class="nav-item ${activePage === 'invoices' ? 'active' : ''}">
            <i data-lucide="receipt"></i>
            <span>My Invoices</span>
          </a>
          `}

          ${isSuperAdmin ? `
          <div class="nav-section-title">System Administration</div>
          <a href="admin.html" class="nav-item ${activePage === 'admin' ? 'active' : ''}">
            <i data-lucide="shield-alert"></i>
            <span>Admin Console</span>
          </a>
          ` : ''}

          <div class="nav-section-title">Preferences</div>
          <a href="notifications.html" class="nav-item ${activePage === 'notifications' ? 'active' : ''}">
            <i data-lucide="bell"></i>
            <span>Notifications</span>
            <span class="nav-badge" id="sidebarNotificationBadge" style="display: none;">0</span>
          </a>
          <a href="settings.html" class="nav-item ${activePage === 'settings' ? 'active' : ''}">
            <i data-lucide="settings"></i>
            <span>Settings</span>
          </a>
        </nav>

        <div class="sidebar-footer">
          <div class="user-profile-menu" onclick="window.location.href='settings.html'">
            <div class="avatar">${(user.full_name || 'U').charAt(0).toUpperCase()}</div>
            <div class="user-details">
              <div class="user-name">${user.full_name || 'Member'}</div>
              <div class="user-role">${userRole.replace('_', ' ')}</div>
            </div>
          </div>
          <button class="btn btn-icon btn-outline btn-sm" onclick="Auth.logout()" title="Sign Out">
            <i data-lucide="log-out" style="width: 16px; height: 16px;"></i>
          </button>
        </div>
      </aside>
      <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="UI.closeSidebar()"></div>
    `;

    const topbarHtml = `
      <header class="app-topbar">
        <div class="topbar-left">
          <button class="btn btn-icon btn-outline btn-sm mobile-menu-btn" onclick="UI.toggleSidebar()" title="Toggle Navigation">
            <i data-lucide="menu" style="width: 18px; height: 18px;"></i>
          </button>
          <div class="page-title" id="pageTitleHeading">ProofDesk</div>
        </div>
        <div class="topbar-right">
          <button class="btn btn-icon btn-outline btn-sm" onclick="UI.toggleTheme()" title="Toggle Theme">
            <i data-lucide="moon" style="width: 16px; height: 16px;"></i>
          </button>
          <a href="notifications.html" class="btn btn-icon btn-outline btn-sm" title="Notifications" style="position: relative;">
            <i data-lucide="bell" style="width: 16px; height: 16px;"></i>
            <span id="topbarNotifDot" style="display: none; position: absolute; top: 6px; right: 6px; width: 8px; height: 8px; background-color: var(--danger); border-radius: 50%;"></span>
          </a>
          <button class="btn btn-outline btn-sm topbar-logout-btn" onclick="Auth.logout()" title="Logout">
            <i data-lucide="log-out" style="width: 14px; height: 14px;"></i>
            <span class="logout-text">Logout</span>
          </button>
        </div>
      </header>
    `;

    const sidebarContainer = document.getElementById('sidebar-mount');
    if (sidebarContainer) {
      sidebarContainer.innerHTML = sidebarHtml;
    }

    const topbarContainer = document.getElementById('topbar-mount');
    if (topbarContainer) {
      topbarContainer.innerHTML = topbarHtml;
    }

    if (window.lucide) {
      window.lucide.createIcons();
    }

    // Auto-close sidebar on mobile navigation
    document.querySelectorAll('.app-sidebar .nav-item').forEach(link => {
      link.addEventListener('click', () => {
        if (window.innerWidth <= 1024) {
          this.closeSidebar();
        }
      });
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        this.closeSidebar();
      }
    });

    this.fetchNotificationBadges();
  },

  toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar) {
      const isOpen = sidebar.classList.toggle('open');
      if (backdrop) {
        if (isOpen) {
          backdrop.classList.add('active');
          document.body.classList.add('sidebar-open');
        } else {
          backdrop.classList.remove('active');
          document.body.classList.remove('sidebar-open');
        }
      }
    }
  },

  closeSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar) sidebar.classList.remove('open');
    if (backdrop) backdrop.classList.remove('active');
    document.body.classList.remove('sidebar-open');
  },

  async switchWorkspacePrompt() {
    try {
      const res = await API.get('/auth/workspaces');
      if (res.data && res.data.length > 0) {
        const currentWs = Auth.getActiveWorkspace();
        const currentId = currentWs ? (currentWs.workspace_id || currentWs.id) : null;
        
        let promptText = "Select workspace by number:\n\n";
        res.data.forEach((w, index) => {
          const wsInfo = w.workspaces || w;
          const mark = (wsInfo.id === currentId) ? ' [ACTIVE]' : '';
          promptText += `${index + 1}. ${wsInfo.name} (${w.role})${mark}\n`;
        });

        const choice = prompt(promptText);
        if (choice && !isNaN(choice)) {
          const idx = parseInt(choice, 10) - 1;
          if (res.data[idx]) {
            Auth.setActiveWorkspace(res.data[idx]);
            UI.toast('Workspace switched. Reloading...', 'success');
            setTimeout(() => window.location.reload(), 400);
          }
        }
      }
    } catch (err) {
      console.error('Failed to load workspaces:', err);
    }
  },

  async fetchNotificationBadges() {
    if (!Auth.isAuthenticated()) return;
    try {
      const res = await API.get('/notifications');
      if (res.data && res.data.unread_count > 0) {
        const topbarDot = document.getElementById('topbarNotifDot');
        if (topbarDot) topbarDot.style.display = 'block';

        const sidebarBadge = document.getElementById('sidebarNotificationBadge');
        if (sidebarBadge) {
          sidebarBadge.textContent = res.data.unread_count;
          sidebarBadge.style.display = 'inline-block';
        }
      }
    } catch {
      // Ignore background notification check errors
    }
  }
};

document.addEventListener('DOMContentLoaded', () => {
  UI.initTheme();
});

window.UI = UI;
