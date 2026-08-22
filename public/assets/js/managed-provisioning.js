(() => {
  'use strict';

  const body = document.body;
  if (body.dataset.page !== 'create-vm' || body.dataset.managedProvisioning !== '1') return;

  const content = document.getElementById('appContent');
  if (!content) return;

  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const pattern = body.dataset.hostnamePattern || 'vm-{project}-{counter}';

  const configure = () => {
    const form = document.getElementById('vmWizard');
    const input = document.getElementById('vmName');
    if (!form || !input || input.dataset.managedProvisioning === '1') return;

    input.dataset.managedProvisioning = '1';
    input.required = false;
    input.removeAttribute('pattern');
    input.disabled = true;
    input.value = pattern;
    input.setAttribute('aria-describedby', 'managedHostnameHelp');

    const label = form.querySelector('label[for="vmName"]');
    if (label) label.textContent = locale === 'pl' ? 'Hostname VM' : 'VM hostname';

    const help = document.createElement('div');
    help.id = 'managedHostnameHelp';
    help.className = 'form-text';
    help.textContent = locale === 'pl'
      ? 'Hostname zostanie wygenerowany automatycznie podczas tworzenia VM. Wzorzec: ' + pattern
      : 'The hostname will be generated automatically during VM creation. Pattern: ' + pattern;
    input.insertAdjacentElement('afterend', help);

    if (!form.elements.namedItem('managed_provisioning')) {
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'managed_provisioning';
      hidden.value = 'true';
      form.append(hidden);
    }
  };

  const observer = new MutationObserver(configure);
  observer.observe(content, {childList: true, subtree: true});
  configure();
})();
