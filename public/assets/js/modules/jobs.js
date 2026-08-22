(() => {
  window.CloudPortal = window.CloudPortal || {};
  window.CloudPortal.jobs = {
    async wait(jobId, {interval = 1500, timeout = 900000} = {}) {
      const started = Date.now();
      while (Date.now() - started < timeout) {
        const response = await window.CloudPortal.api.request(`/api/v1/jobs/${encodeURIComponent(jobId)}`);
        const job = response.data || response;
        if (['completed', 'failed', 'dead_letter'].includes(job.status)) return job;
        await new Promise(resolve => setTimeout(resolve, interval));
      }
      throw new Error('Timed out waiting for job completion.');
    },
    async retry(jobId) {
      return window.CloudPortal.api.request(`/api/v1/admin/jobs/${encodeURIComponent(jobId)}/retry`, {method: 'POST', body: '{}'});
    }
  };
})();
