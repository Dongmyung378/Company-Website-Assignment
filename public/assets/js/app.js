(() => {
  'use strict';
  const announce = message => { const node = document.querySelector('#ui-announcement'); if (node) node.textContent = message; };
  const menu = document.querySelector('.nav-toggle');
  const backdrop = document.querySelector('.nav-backdrop');
  const navigation = document.querySelector('.sidebar');
  const mobileNavigation = window.matchMedia('(max-width:900px)');
  if (navigation) navigation.inert = mobileNavigation.matches;
  const setMenu = open => {
    if (navigation) navigation.inert = mobileNavigation.matches && !open;
    document.body.classList.toggle('nav-open', open);
    menu?.setAttribute('aria-expanded', String(open));
    menu?.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    if (backdrop) backdrop.hidden = !open;
    if (open) navigation?.querySelector('a')?.focus();
    else menu?.focus();
  };
  menu?.addEventListener('click', () => setMenu(menu.getAttribute('aria-expanded') !== 'true'));
  backdrop?.addEventListener('click', () => setMenu(false));
  document.addEventListener('keydown', event => {
    if (!document.body.classList.contains('nav-open')) return;
    if (event.key === 'Escape') { event.preventDefault(); setMenu(false); }
    if (event.key === 'Tab' && navigation) {
      const nodes = [...navigation.querySelectorAll('a,button')].filter(node => !node.hidden);
      const first = nodes[0], last = nodes.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
  });
  mobileNavigation.addEventListener('change', event => {
    if (navigation) navigation.inert = event.matches && !document.body.classList.contains('nav-open');
    if (!event.matches && document.body.classList.contains('nav-open')) {
      document.body.classList.remove('nav-open');
      menu?.setAttribute('aria-expanded', 'false');
      if (backdrop) backdrop.hidden = true;
    }
  });

  document.querySelectorAll('input[type="password"]').forEach(input => {
    let wrapper = input.closest('.password-field');
    if (!wrapper) { wrapper = document.createElement('div'); wrapper.className = 'password-field'; input.before(wrapper); wrapper.append(input); }
    if (wrapper.querySelector('.password-toggle')) return;
    const toggle = document.createElement('button');
    toggle.type = 'button'; toggle.className = 'password-toggle'; toggle.textContent = 'Show';
    toggle.setAttribute('aria-label', 'Show ' + (document.querySelector(`label[for="${CSS.escape(input.id)}"]`)?.textContent?.replace(':', '').trim().toLowerCase() || 'password'));
    toggle.setAttribute('aria-pressed', 'false');
    toggle.addEventListener('click', () => {
      const show = input.type === 'password'; input.type = show ? 'text' : 'password';
      toggle.textContent = show ? 'Hide' : 'Show'; toggle.setAttribute('aria-pressed', String(show));
      toggle.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} password`);
    });
    wrapper.append(toggle);
  });

  document.querySelectorAll('select[data-department-select]').forEach(select => {
    const department = document.getElementById(select.dataset.departmentSelect);
    if (!department) return;
    const apply = () => {
      const id = department.value;
      [...select.options].forEach(option => {
        const match = !option.value || (id !== '' && option.dataset.departmentId === id);
        option.hidden = !match; option.disabled = !match;
      });
      if (select.selectedOptions[0]?.disabled) select.value = '';
      select.disabled = id === '';
      if (!id) select.value = '';
    };
    department.addEventListener('change', apply); apply();
  });

  const calendarDate = text => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) return null;
    const [year, month, day] = text.split('-').map(Number);
    const date = new Date(0); date.setUTCHours(0, 0, 0, 0); date.setUTCFullYear(year, month - 1, day);
    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? date : null;
  };
  document.querySelectorAll('form[data-leave-form]').forEach(form => {
    const start = form.querySelector('[name=start_date]'), end = form.querySelector('[name=end_date]');
    const type = form.querySelector('[name=leave_type]'), output = form.querySelector('[data-leave-preview]');
    if (!start || !end || !type || !output) return;
    output.setAttribute('aria-live', 'polite');
    const update = () => {
      end.min = start.value || '';
      end.setCustomValidity('');
      const a = calendarDate(start.value), b = calendarDate(end.value);
      if (!a || !b) { output.textContent = 'Choose your leave type and dates to preview the number of calendar days.'; return; }
      if (b < a) { output.textContent = 'The end date must be on or after the start date.'; end.setCustomValidity('Choose an end date on or after the start date.'); return; }
      const days = Math.round((b - a) / 86400000) + 1;
      const raw = type.selectedOptions[0]?.dataset.balance;
      const balance = raw === undefined ? NaN : Number(raw);
      if (!Number.isNaN(balance) && days > balance) {
        output.textContent = `${days} calendar ${days === 1 ? 'day' : 'days'} requested. Your ${type.value.toLowerCase()} balance is ${balance} days; choose a shorter date range.`;
        end.setCustomValidity('The requested dates exceed your available leave balance.');
      } else {
        output.textContent = `${days} calendar ${days === 1 ? 'day' : 'days'}, including both dates and weekends.${Number.isNaN(balance) ? '' : ` ${balance - days} days would remain after approval.`}`;
      }
    };
    [start, end, type].forEach(input => input.addEventListener('input', update)); update();
  });

  document.querySelectorAll('table').forEach(table => {
    if (!table.closest('.table-scroll')) { const wrapper = document.createElement('div'); wrapper.className = 'table-scroll'; table.before(wrapper); wrapper.append(table); }
  });
  document.querySelectorAll('.table-scroll').forEach(wrapper => {
    wrapper.tabIndex = 0;
    wrapper.setAttribute('role', 'region');
    wrapper.setAttribute('aria-label', wrapper.querySelector('caption')?.textContent?.trim() || 'Scrollable data table');
  });

  document.querySelectorAll('form').forEach(form => {
    const nextPassword = form.querySelector('[name=new_password]');
    const confirmation = form.querySelector('[name=confirm_password]');
    if (nextPassword && confirmation) {
      const matchPasswords = () => confirmation.setCustomValidity(confirmation.value && confirmation.value !== nextPassword.value ? 'Enter the same new password in both fields.' : '');
      nextPassword.addEventListener('input', matchPasswords);
      confirmation.addEventListener('input', matchPasswords);
    }
    const birthDate = form.querySelector('[name=dob]');
    const hireDate = form.querySelector('[name=hired_date]');
    if (birthDate && hireDate) {
      const updateHireDate = () => { hireDate.min = birthDate.value || '1000-01-01'; };
      birthDate.addEventListener('input', updateHireDate); updateHireDate();
    }
    form.addEventListener('invalid', event => {
      form.classList.add('was-validated'); event.target.setAttribute('aria-invalid', 'true');
    }, true);
    form.addEventListener('input', event => {
      if (event.target.validity?.valid) event.target.removeAttribute('aria-invalid');
    });
    form.addEventListener('submit', event => {
      if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
      const submitter = event.submitter;
      const confirmation = submitter?.dataset.confirm || form.dataset.confirm;
      if (confirmation && !window.confirm(confirmation)) { event.preventDefault(); return; }
      if ((form.method || 'get').toLowerCase() !== 'post') return;
      // Disabled controls are omitted by browsers: preserve the clicked action name explicitly.
      if (submitter?.name) {
        const action = document.createElement('input'); action.type = 'hidden';
        action.name = submitter.name; action.value = submitter.value; action.dataset.submitAction = 'true'; form.append(action);
      }
      form.dataset.submitting = 'true'; form.setAttribute('aria-busy', 'true');
      form.querySelectorAll('button[type=submit],input[type=submit]').forEach(button => {
        button.disabled = true;
        if (button === submitter && button.tagName === 'BUTTON') { button.dataset.originalLabel = button.textContent; button.textContent = 'Working…'; }
      });
      announce('Your request is being processed.');
    });
  });
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting]').forEach(form => {
      delete form.dataset.submitting; form.removeAttribute('aria-busy');
      form.querySelectorAll('[data-submit-action]').forEach(input => input.remove());
      form.querySelectorAll('button[type=submit],input[type=submit]').forEach(button => {
        button.disabled = false;
        if (button.dataset.originalLabel) { button.textContent = button.dataset.originalLabel; delete button.dataset.originalLabel; }
      });
    });
  });
})();
