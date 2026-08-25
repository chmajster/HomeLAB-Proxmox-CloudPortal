(() => {
  'use strict';

  if (document.body.dataset.page !== 'templates') return;

  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const descriptions = {
    templateConnection: {
      pl: 'Połączenie/klaster Proxmox, z którego wykryto template. Pole jest tylko do odczytu.',
      en: 'The Proxmox connection/cluster where the template was discovered. This field is read-only.'
    },
    templateNode: {
      pl: 'Węzeł Proxmox, na którym znajduje się template. Portal użyje go jako źródła klonowania.',
      en: 'The Proxmox node that contains the template. The portal uses it as the clone source.'
    },
    templateVmid: {
      pl: 'Unikalny identyfikator template w Proxmox. Portal używa go jako source VMID podczas klonowania.',
      en: 'The template\'s unique Proxmox identifier. The portal uses it as the source VMID when cloning.'
    },
    templateName: {
      pl: 'Nazwa wyświetlana użytkownikom w katalogu portalu. Nie zmienia nazwy template w Proxmox.',
      en: 'The name displayed to users in the portal catalog. It does not rename the template in Proxmox.'
    },
    templateOs: {
      pl: 'Opis systemu w obrazie, np. Ubuntu 24.04 LTS. To metadane katalogu i nie modyfikują template.',
      en: 'A description of the operating system in the image, for example Ubuntu 24.04 LTS. This is catalog metadata and does not modify the template.'
    },
    templateDescription: {
      pl: 'Opcjonalny opis przeznaczenia lub zawartości template. Jest zapisywany tylko w katalogu portalu.',
      en: 'An optional description of the template purpose or contents. It is stored only in the portal catalog.'
    }
  };

  function decorate() {
    const form = document.getElementById('templateProfileForm');
    if (!form) return;

    Object.entries(descriptions).forEach(([fieldId, translations]) => {
      const label = form.querySelector(`label[for="${fieldId}"]`);
      if (!label || label.querySelector('[data-template-field-help]')) return;

      const text = translations[locale];
      const help = document.createElement('span');
      help.className = 'field-help';
      help.tabIndex = 0;
      help.setAttribute('role', 'note');
      help.setAttribute('aria-label', text);
      help.setAttribute('data-help', text);
      help.setAttribute('data-template-field-help', fieldId);
      help.textContent = '?';
      label.append(help);
    });
  }

  const content = document.getElementById('appContent');
  if (!content) return;

  const observer = new MutationObserver(decorate);
  observer.observe(content, {childList: true, subtree: true});
  decorate();
})();
