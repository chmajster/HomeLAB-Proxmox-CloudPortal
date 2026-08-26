(() => {
  if (document.body?.dataset.page !== 'security') return;
  const api = window.CloudPortal?.api;
  const root = document.getElementById('appContent');
  const title = document.getElementById('pageTitle');
  if (!api || !root) return;
  if (title) title.textContent = 'Bezpieczeństwo konta';

  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const alertBox = (message, kind = 'success') => `<div class="alert alert-${kind}" role="alert">${esc(message)}</div>`;
  let state = {security: {}, tokens: [], scopes: [], sessions: []};

  async function load() {
    try {
      const [security, tokens, sessions] = await Promise.all([
        api.request('/api/v1/me/security'),
        api.request('/api/v1/me/api-tokens'),
        api.request('/api/v1/me/sessions'),
      ]);
      state.security = security.data || {};
      state.tokens = tokens.data?.tokens || [];
      state.scopes = tokens.data?.available_scopes || [];
      state.sessions = sessions.data || [];
      render();
    } catch (error) {
      root.innerHTML = alertBox(error.message || 'Nie udało się załadować ustawień bezpieczeństwa.', 'danger');
    }
  }

  function render() {
    const mfa = state.security.mfa_enabled === true;
    root.innerHTML = `
      <div id="securityMessage"></div>
      <div class="row g-4">
        <div class="col-xl-6">
          <section class="portal-card p-4 h-100">
            <h2 class="h5">Hasło</h2>
            <p class="text-secondary small">Zmiana hasła unieważnia wszystkie wcześniejsze sesje.</p>
            <form id="passwordForm" class="vstack gap-3">
              <input class="form-control" type="password" name="current_password" autocomplete="current-password" placeholder="Aktualne hasło" required>
              <input class="form-control" type="password" name="new_password" autocomplete="new-password" placeholder="Nowe hasło (min. 12 znaków)" required>
              <button class="btn btn-primary" type="submit">Zmień hasło</button>
            </form>
          </section>
        </div>
        <div class="col-xl-6">
          <section class="portal-card p-4 h-100">
            <div class="d-flex align-items-start justify-content-between gap-3"><div><h2 class="h5">MFA / TOTP</h2><p class="text-secondary small">Drugi składnik logowania i jednorazowe kody odzyskiwania.</p></div><span class="badge ${mfa ? 'text-bg-success' : 'text-bg-secondary'}">${mfa ? 'Włączone' : 'Wyłączone'}</span></div>
            ${mfa ? `<p>Pozostałe kody odzyskiwania: <strong>${Number(state.security.recovery_codes_remaining || 0)}</strong></p>
              <form id="mfaDisableForm" class="vstack gap-2"><input class="form-control" type="password" name="current_password" placeholder="Aktualne hasło" required><input class="form-control" name="code" placeholder="Kod TOTP lub recovery code" required><button class="btn btn-outline-danger" type="submit">Wyłącz MFA</button></form>`
            : `<form id="mfaSetupForm" class="vstack gap-2"><input class="form-control" type="password" name="current_password" placeholder="Aktualne hasło" required><button class="btn btn-primary" type="submit">Rozpocznij konfigurację MFA</button></form><div id="mfaSetupResult" class="mt-3"></div>`}
          </section>
        </div>
        <div class="col-12">
          <section class="portal-card p-4">
            <h2 class="h5">Tokeny API</h2>
            <p class="text-secondary small">Token nigdy nie ma większych praw niż konto. Wartość tokenu jest wyświetlana tylko raz.</p>
            <form id="tokenForm" class="row g-2 align-items-end mb-4">
              <div class="col-md-3"><label class="form-label">Nazwa</label><input class="form-control" name="name" maxlength="100" required></div>
              <div class="col-md-5"><label class="form-label">Scope</label><div class="d-flex flex-wrap gap-2">${state.scopes.map(scope => `<label class="form-check"><input class="form-check-input" type="checkbox" name="scopes" value="${esc(scope)}"><span class="form-check-label"><code>${esc(scope)}</code></span></label>`).join('')}</div></div>
              <div class="col-md-2"><label class="form-label">Wygasa</label><input class="form-control" type="datetime-local" name="expires_at"></div>
              <div class="col-md-2"><label class="form-label">Hasło</label><input class="form-control" type="password" name="current_password" required></div>
              <div class="col-12"><button class="btn btn-primary" type="submit">Utwórz token</button></div>
            </form>
            <div id="newToken"></div>
            <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Nazwa</th><th>Prefix</th><th>Scope</th><th>Ostatnie użycie</th><th>Status</th><th></th></tr></thead><tbody>${state.tokens.map(tokenRow).join('') || '<tr><td colspan="6" class="text-secondary">Brak tokenów API.</td></tr>'}</tbody></table></div>
          </section>
        </div>
        <div class="col-12">
          <section class="portal-card p-4">
            <div class="d-flex justify-content-between align-items-center gap-3"><div><h2 class="h5">Aktywne sesje</h2><p class="text-secondary small mb-0">Sesje są weryfikowane po stronie serwera i mogą zostać natychmiast unieważnione.</p></div><button class="btn btn-outline-danger" id="revokeOthers" type="button">Wyloguj inne sesje</button></div>
            <div class="table-responsive mt-3"><table class="table align-middle"><thead><tr><th>Urządzenie</th><th>IP</th><th>Ostatnia aktywność</th><th>Status</th><th></th></tr></thead><tbody>${state.sessions.map(sessionRow).join('') || '<tr><td colspan="5" class="text-secondary">Brak zapisanych sesji.</td></tr>'}</tbody></table></div>
          </section>
        </div>
      </div>`;
    bind();
  }

  const tokenRow = token => `<tr><td>${esc(token.name)}</td><td><code>${esc(token.token_prefix)}</code></td><td>${(token.scopes || []).map(s => `<code class="me-1">${esc(s)}</code>`).join('')}</td><td>${esc(token.last_used_at || '—')}</td><td>${esc(token.status)}</td><td>${token.status === 'active' ? `<button class="btn btn-sm btn-outline-danger" data-revoke-token="${Number(token.id)}">Unieważnij</button>` : ''}</td></tr>`;
  const sessionRow = session => `<tr><td>${esc(session.user_agent || 'Nieznane urządzenie')}${session.current ? ' <span class="badge text-bg-primary">ta sesja</span>' : ''}</td><td><code>${esc(session.ip_address)}</code></td><td>${esc(session.last_seen_at)}</td><td>${session.active ? '<span class="badge text-bg-success">aktywna</span>' : '<span class="badge text-bg-secondary">nieaktywna</span>'}</td><td>${session.active ? `<button class="btn btn-sm btn-outline-danger" data-revoke-session="${Number(session.id)}">Wyloguj</button>` : ''}</td></tr>`;

  function message(text, kind = 'success') {
    const box = document.getElementById('securityMessage');
    if (box) box.innerHTML = alertBox(text, kind);
  }
  const formData = form => Object.fromEntries(new FormData(form).entries());

  function bind() {
    document.getElementById('passwordForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      try { await api.request('/api/v1/me/password', {method:'POST', body:JSON.stringify(formData(event.currentTarget))}); location.href = `${api.basePath}/login`; }
      catch (error) { message(error.message, 'danger'); }
    });

    document.getElementById('mfaSetupForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      try {
        const result = await api.request('/api/v1/me/mfa/setup', {method:'POST', body:JSON.stringify(formData(event.currentTarget))});
        const data = result.data || {};
        document.getElementById('mfaSetupResult').innerHTML = `<div class="alert alert-warning"><strong>Zapisz kody odzyskiwania przed włączeniem MFA.</strong><br><code class="user-select-all">${esc(data.secret)}</code><pre class="mt-2 mb-0 user-select-all">${esc((data.recovery_codes || []).join('\n'))}</pre></div><form id="mfaEnableForm" class="d-flex gap-2"><input class="form-control" name="code" placeholder="6-cyfrowy kod z aplikacji" required><button class="btn btn-success" type="submit">Potwierdź i włącz</button></form>`;
        document.getElementById('mfaEnableForm')?.addEventListener('submit', async enableEvent => {
          enableEvent.preventDefault();
          try { await api.request('/api/v1/me/mfa/enable', {method:'POST', body:JSON.stringify(formData(enableEvent.currentTarget))}); await load(); message('MFA zostało włączone.'); }
          catch (error) { message(error.message, 'danger'); }
        });
      } catch (error) { message(error.message, 'danger'); }
    });

    document.getElementById('mfaDisableForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      try { await api.request('/api/v1/me/mfa', {method:'DELETE', body:JSON.stringify(formData(event.currentTarget))}); location.href = `${api.basePath}/login`; }
      catch (error) { message(error.message, 'danger'); }
    });

    document.getElementById('tokenForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      const data = formData(event.currentTarget);
      data.scopes = [...event.currentTarget.querySelectorAll('input[name="scopes"]:checked')].map(el => el.value);
      if (!data.scopes.length) { message('Wybierz co najmniej jeden scope.', 'danger'); return; }
      if (!data.expires_at) delete data.expires_at;
      try {
        const result = await api.request('/api/v1/me/api-tokens', {method:'POST', body:JSON.stringify(data)});
        document.getElementById('newToken').innerHTML = `<div class="alert alert-warning"><strong>Skopiuj token teraz. Nie będzie ponownie wyświetlony.</strong><pre class="mt-2 mb-0 user-select-all">${esc(result.data.token)}</pre></div>`;
        const refreshed = await api.request('/api/v1/me/api-tokens'); state.tokens = refreshed.data.tokens || []; render();
        document.getElementById('newToken').innerHTML = `<div class="alert alert-warning"><strong>Skopiuj token teraz. Nie będzie ponownie wyświetlony.</strong><pre class="mt-2 mb-0 user-select-all">${esc(result.data.token)}</pre></div>`;
      } catch (error) { message(error.message, 'danger'); }
    });

    root.querySelectorAll('[data-revoke-token]').forEach(button => button.addEventListener('click', async () => {
      const password = window.prompt('Podaj aktualne hasło, aby unieważnić token:');
      if (!password) return;
      try { await api.request(`/api/v1/me/api-tokens/${button.dataset.revokeToken}`, {method:'DELETE', body:JSON.stringify({current_password:password})}); await load(); message('Token został unieważniony.'); }
      catch (error) { message(error.message, 'danger'); }
    }));

    root.querySelectorAll('[data-revoke-session]').forEach(button => button.addEventListener('click', async () => {
      try { const result = await api.request(`/api/v1/me/sessions/${button.dataset.revokeSession}`, {method:'DELETE'}); if (result.data.reauthentication_required) location.href = `${api.basePath}/login`; else { await load(); message('Sesja została unieważniona.'); } }
      catch (error) { message(error.message, 'danger'); }
    }));

    document.getElementById('revokeOthers')?.addEventListener('click', async () => {
      try { const result = await api.request('/api/v1/me/sessions/revoke-others', {method:'POST', body:'{}'}); await load(); message(`Unieważniono sesje: ${Number(result.data.revoked_count || 0)}.`); }
      catch (error) { message(error.message, 'danger'); }
    });
  }

  load();
})();
