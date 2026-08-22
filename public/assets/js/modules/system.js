(() => {
  window.CloudPortal = window.CloudPortal || {};
  window.CloudPortal.system = {
    health() { return window.CloudPortal.api.request('/api/v1/admin/system/health'); },
    placement(connectionId, excludeNode = '') {
      const query = excludeNode ? `?exclude_node=${encodeURIComponent(excludeNode)}` : '';
      return window.CloudPortal.api.request(`/api/v1/admin/proxmox/${encodeURIComponent(connectionId)}/placement${query}`);
    }
  };
})();
