/**
 * ProofDesk Centralized API Client (Fetch API ES6+)
 */

const API = {
  baseUrl: '/api',

  getHeaders(isMultipart = false) {
    const headers = {};
    const token = localStorage.getItem('proofdesk_token');
    const wsId = localStorage.getItem('proofdesk_workspace_id');

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }
    if (wsId) {
      headers['X-Workspace-ID'] = wsId;
    }
    if (!isMultipart) {
      headers['Content-Type'] = 'application/json';
      headers['Accept'] = 'application/json';
    }

    return headers;
  },

  async request(endpoint, options = {}) {
    const isMultipart = options.body instanceof FormData;
    const url = `${this.baseUrl}${endpoint.startsWith('/') ? endpoint : '/' + endpoint}`;
    
    const fetchOptions = {
      method: options.method || 'GET',
      headers: {
        ...this.getHeaders(isMultipart),
        ...(options.headers || {})
      }
    };

    if (options.body) {
      fetchOptions.body = isMultipart ? options.body : JSON.stringify(options.body);
    }

    try {
      const response = await fetch(url, fetchOptions);
      const data = await response.json().catch(() => ({
        success: false,
        message: 'Invalid server response'
      }));

      if (response.status === 401) {
        // Expired session or invalid token
        localStorage.removeItem('proofdesk_token');
        localStorage.removeItem('proofdesk_user');
        if (!window.location.pathname.includes('login.html') && !window.location.pathname.includes('register.html') && window.location.pathname !== '/' && !window.location.pathname.endsWith('index.html')) {
          window.location.href = 'login.html';
        }
        throw new Error(data.message || 'Session expired. Please log in.');
      }

      if (!response.ok || data.success === false) {
        const errorMsg = data.message || `Request failed with status ${response.status}`;
        const err = new Error(errorMsg);
        err.data = data;
        err.status = response.status;
        throw err;
      }

      return data;
    } catch (err) {
      console.error(`[API Error] ${options.method || 'GET'} ${endpoint}:`, err);
      throw err;
    }
  },

  get(endpoint, queryParams = {}) {
    const query = new URLSearchParams(queryParams).toString();
    const url = query ? `${endpoint}?${query}` : endpoint;
    return this.request(url, { method: 'GET' });
  },

  post(endpoint, body) {
    return this.request(endpoint, { method: 'POST', body });
  },

  put(endpoint, body) {
    return this.request(endpoint, { method: 'PUT', body });
  },

  patch(endpoint, body) {
    return this.request(endpoint, { method: 'PATCH', body });
  },

  delete(endpoint) {
    return this.request(endpoint, { method: 'DELETE' });
  },

  upload(endpoint, formData) {
    return this.request(endpoint, { method: 'POST', body: formData });
  }
};

window.API = API;
