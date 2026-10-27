(() => {
  'use strict';
  let messages = {};
  try { messages = JSON.parse(document.querySelector('#ui-messages')?.textContent || '{}'); } catch { /* English remains available. */ }
  const text = (source, values = {}) => {
    let value = messages[source] ?? source;
    for (const [key, replacement] of Object.entries(values)) value = value.replaceAll(`{${key}}`, String(replacement));
    return value;
  };
  const announce = message => { const node = document.querySelector('#ui-announcement'); if (node) node.textContent = message; };
  const menu = document.querySelector('.nav-toggle');
  const backdrop = document.querySelector('.nav-backdrop');
  const navigation = document.querySelector('.sidebar');
  const workspace = document.querySelector('.app-workspace');
  const mobileNavigation = window.matchMedia('(max-width:900px)');
  if (navigation) navigation.inert = mobileNavigation.matches;
  const setMenu = open => {
    if (navigation) navigation.inert = mobileNavigation.matches && !open;
    if (workspace) workspace.inert = mobileNavigation.matches && open;
    document.body.classList.toggle('nav-open', open);
    menu?.setAttribute('aria-expanded', String(open));
    menu?.setAttribute('aria-label', text(open ? 'Close navigation' : 'Open navigation'));
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
      if (workspace) workspace.inert = false;
    }
  });

  document.querySelectorAll('input[type="password"]').forEach(input => {
    let wrapper = input.closest('.password-field');
    if (!wrapper) { wrapper = document.createElement('div'); wrapper.className = 'password-field'; input.before(wrapper); wrapper.append(input); }
    if (wrapper.querySelector('.password-toggle')) return;
    const toggle = document.createElement('button');
    toggle.type = 'button'; toggle.className = 'password-toggle'; toggle.textContent = text('Show');
    const field = document.querySelector(`label[for="${CSS.escape(input.id)}"]`)?.textContent?.replace(':', '').trim() || text('Password');
    toggle.setAttribute('aria-label', text('Show {field}', {field}));
    toggle.setAttribute('aria-pressed', 'false');
    toggle.addEventListener('click', () => {
      const show = input.type === 'password'; input.type = show ? 'text' : 'password';
      toggle.textContent = text(show ? 'Hide' : 'Show'); toggle.setAttribute('aria-pressed', String(show));
      toggle.setAttribute('aria-label', text(show ? 'Hide {field}' : 'Show {field}', {field}));
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
      if (!a || !b) { output.textContent = text('Choose your leave type and dates to preview the number of calendar days.'); return; }
      if (b < a) { output.textContent = text('The end date must be on or after the start date.'); end.setCustomValidity(text('Choose an end date on or after the start date.')); return; }
      const days = Math.round((b - a) / 86400000) + 1;
      const raw = type.selectedOptions[0]?.dataset.balance;
      const balance = raw === undefined ? NaN : Number(raw);
      if (!Number.isNaN(balance) && days > balance) {
        output.textContent = text('{days} calendar {unit} requested. Your {type} balance is {balance} days; choose a shorter date range.', {days, unit: text(days === 1 ? 'day' : 'days'), type: text(type.value.toLowerCase()), balance});
        end.setCustomValidity(text('The requested dates exceed your available leave balance.'));
      } else {
        output.textContent = text('{days} calendar {unit}, including both dates and weekends.', {days, unit: text(days === 1 ? 'day' : 'days')})
          + (Number.isNaN(balance) ? '' : ' ' + text('{remaining} days would remain after approval.', {remaining: balance - days}));
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
    wrapper.setAttribute('aria-label', wrapper.querySelector('caption')?.textContent?.trim() || text('Scrollable data table'));
    const hint = document.createElement('p'); hint.className = 'table-hint'; hint.hidden = true;
    hint.textContent = text('Scroll horizontally to view all columns.'); wrapper.before(hint);
    const update = () => { hint.hidden = wrapper.scrollWidth <= wrapper.clientWidth + 1; };
    window.addEventListener('resize', update); requestAnimationFrame(update);
  });

  document.querySelectorAll('form').forEach(form => {
    const nextPassword = form.querySelector('[name=new_password]');
    const confirmation = form.querySelector('[name=confirm_password]');
    if (nextPassword && confirmation) {
      const matchPasswords = () => confirmation.setCustomValidity(confirmation.value && confirmation.value !== nextPassword.value ? text('Enter the same new password in both fields.') : '');
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
      const input = event.target;
      if (!input.validity.customError) {
        const message = input.validity.valueMissing ? (input.tagName === 'SELECT' ? 'Choose an option.' : 'Complete this required field.')
          : input.validity.rangeUnderflow || input.validity.rangeOverflow ? 'Choose a date or number within the allowed range.'
            : 'Check the format shown below this field.';
        input.setCustomValidity(text(message)); input.dataset.uiValidation = 'true';
      }
    }, true);
    form.addEventListener('input', event => {
      if (event.target.dataset.uiValidation) { event.target.setCustomValidity(''); delete event.target.dataset.uiValidation; }
      if (event.target.validity?.valid) event.target.removeAttribute('aria-invalid');
    }, true);
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
        if (button === submitter && button.tagName === 'BUTTON') { button.dataset.originalLabel = button.textContent; button.textContent = text('Working…'); }
      });
      announce(text('Your request is being processed.'));
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
  const initialValues = new WeakMap();
  document.querySelectorAll('form input,form textarea,form select').forEach(input => {
    initialValues.set(input, ['checkbox', 'radio'].includes(input.type) ? String(input.checked) : input.value);
  });
  document.querySelectorAll('[data-language-switch]').forEach(link => {
    link.addEventListener('click', event => {
      const changed = [...document.querySelectorAll('form input,form textarea,form select')].some(input => {
        if (input.type === 'hidden' || input.type === 'submit' || input.disabled) return false;
        const current = ['checkbox', 'radio'].includes(input.type) ? String(input.checked) : input.value;
        return initialValues.has(input) && current !== initialValues.get(input);
      });
      if (changed && !window.confirm(text('You have unsaved entries. Change the display language and reload this page?'))) event.preventDefault();
    });
  });
})();
