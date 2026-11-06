/**
 * ProofDesk Authentication & Session Manager
 */

const Auth = {
  getToken() {
    return localStorage.getItem('proofdesk_token');
  },

  getUser() {
    try {
      const u = localStorage.getItem('proofdesk_user');
      return u ? JSON.parse(u) : null;
    } catch {
      return null;
    }
  },

  getActiveWorkspace() {
    try {
      const ws = localStorage.getItem('proofdesk_workspace');
      return ws ? JSON.parse(ws) : null;
    } catch {
      return null;
    }
  },

  saveSession(loginData) {
    if (loginData.session && loginData.session.access_token) {
      localStorage.setItem('proofdesk_token', loginData.session.access_token);
    }
    if (loginData.user) {
      localStorage.setItem('proofdesk_user', JSON.stringify(loginData.user));
    }
    if (loginData.active_workspace) {
      this.setActiveWorkspace(loginData.active_workspace);
    } else if (loginData.workspace) {
      this.setActiveWorkspace(loginData.workspace);
    } else if (loginData.workspaces && loginData.workspaces.length > 0) {
      this.setActiveWorkspace(loginData.workspaces[0]);
    }
  },

  setActiveWorkspace(ws) {
    if (!ws) return;
    const wsId = ws.workspace_id || ws.id;
    localStorage.setItem('proofdesk_workspace_id', wsId);
    localStorage.setItem('proofdesk_workspace', JSON.stringify(ws));
  },

  isAuthenticated() {
    return !!this.getToken();
  },

  requireAuth() {
    if (!this.isAuthenticated()) {
      try {
        if (window.stop) window.stop();
        document.documentElement.style.display = 'none';
      } catch (e) {}
      window.location.replace('login.html');
      return false;
    }
    return true;
  },

  requireRole(...allowedRoles) {
    if (!this.requireAuth()) return false;
    const user = this.getUser();
    if (!user) {
      try {
        if (window.stop) window.stop();
        document.documentElement.style.display = 'none';
      } catch (e) {}
      window.location.replace('login.html');
      return false;
    }

    if (user.role === 'super_admin') {
      return true;
    }

    const ws = this.getActiveWorkspace();
    const currentRole = (ws && ws.role) ? ws.role : user.role;

    if (!allowedRoles.includes(currentRole) && !allowedRoles.includes(user.role)) {
      alert('Access restricted: You do not have permission to view this page.');
      window.location.replace('dashboard.html');
      return false;
    }
    return true;
  },

  logout() {
    localStorage.removeItem('proofdesk_token');
    localStorage.removeItem('proofdesk_user');
    localStorage.removeItem('proofdesk_workspace');
    localStorage.removeItem('proofdesk_workspace_id');
    window.location.href = 'login.html';
  }
};

window.Auth = Auth;
