(() => {
  'use strict';
  const modalInstances = new WeakMap();

  class Modal {
    constructor(element) { this.element = element; this.backdrop = null; }
    static getOrCreateInstance(element) {
      if (!modalInstances.has(element)) modalInstances.set(element, new Modal(element));
      return modalInstances.get(element);
    }
    show() {
      this.backdrop = document.createElement('div');
      this.backdrop.className = 'modal-backdrop show';
      this.backdrop.addEventListener('click', () => this.hide());
      document.body.append(this.backdrop);
      document.body.classList.add('modal-open');
      this.element.style.display = 'block';
      this.element.removeAttribute('aria-hidden');
      this.element.classList.add('show');
      this.element.querySelector('button, [href], input, select, textarea')?.focus();
    }
    hide() {
      this.element.classList.remove('show');
      this.element.style.display = 'none';
      this.element.setAttribute('aria-hidden', 'true');
      this.backdrop?.remove();
      this.backdrop = null;
      document.body.classList.remove('modal-open');
      this.element.dispatchEvent(new CustomEvent('hidden.bs.modal'));
    }
  }

  class Toast {
    constructor(element, options = {}) { this.element = element; this.delay = options.delay || 5000; }
    show() {
      this.element.classList.add('show');
      window.setTimeout(() => this.hide(), this.delay);
    }
    hide() {
      this.element.classList.remove('show');
      this.element.dispatchEvent(new CustomEvent('hidden.bs.toast'));
    }
  }

  window.bootstrap = {Modal, Toast};
  document.addEventListener('click', event => {
    const dismiss = event.target.closest('[data-bs-dismiss="modal"]');
    if (dismiss) Modal.getOrCreateInstance(dismiss.closest('.modal')).hide();
    const closeToast = event.target.closest('[data-bs-dismiss="toast"]');
    if (closeToast) closeToast.closest('.toast')?.remove();
  });
})();
