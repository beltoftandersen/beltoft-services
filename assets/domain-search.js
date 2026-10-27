(function () {
  'use strict';

  document.querySelectorAll('.beltoft-domain-search').forEach(function (root) {
    const input = root.querySelector('input[type="search"]');
    const results = root.querySelector('.beltoft-domain-results');
    const searchButton = root.querySelector('button');
    if (!input.placeholder) input.placeholder = 'example.com';

    function element(tag, className, content) {
      const node = document.createElement(tag);
      node.className = className;
      node.textContent = content;
      return node;
    }

    function showError(message) {
      const error = element('p', 'beltoft-domain-error', message);
      error.setAttribute('role', 'alert');
      results.prepend(error);
    }

    async function add(domain, mode, authCode) {
      const response = await fetch(root.dataset.cart, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WC-Nonce': root.dataset.nonce, 'X-WP-Nonce': root.dataset.restNonce },
        body: JSON.stringify({ domain: domain, mode: mode, auth_code: authCode || '' })
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not add this domain.');
      window.location.assign(data.checkout_url);
    }

    function transferForm(domain, actions) {
      const form = document.createElement('form');
      form.className = 'beltoft-domain-transfer';
      form.appendChild(element('p', 'beltoft-domain-transfer__help', 'Unlock ' + domain + ' at your current registrar and enter the transfer code (also called auth or EPP code). The transfer can take several days.'));
      const field = document.createElement('input');
      field.type = 'text';
      field.autocomplete = 'off';
      field.required = true;
      field.setAttribute('aria-label', 'Transfer code for ' + domain);
      field.placeholder = 'Transfer code';
      const submit = element('button', 'beltoft-domain-action', 'Add transfer to order');
      submit.type = 'submit';
      form.append(field, submit);
      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const code = field.value.trim();
        if (!code) return;
        submit.disabled = true;
        try {
          await add(domain, 'transfer', code);
        } catch (error) {
          showError(error.message);
          submit.disabled = false;
        }
      });
      actions.replaceChildren(form);
      field.focus();
    }

    function action(label, domain, mode, secondary) {
      const button = element('button', 'beltoft-domain-action' + (secondary ? ' beltoft-domain-action--secondary' : ''), label);
      button.type = 'button';
      button.addEventListener('click', async function () {
        if (mode === 'transfer') {
          transferForm(domain, button.closest('.beltoft-domain-result__actions'));
          return;
        }
        button.disabled = true;
        try {
          await add(domain, mode, '');
        } catch (error) {
          showError(error.message);
          button.disabled = false;
        }
      });
      return button;
    }

    function result(item, primary) {
      const row = document.createElement('div');
      row.className = 'beltoft-domain-result beltoft-domain-result--' + item.status + (primary ? ' beltoft-domain-result--primary' : '');
      const information = document.createElement('div');
      information.className = 'beltoft-domain-result__information';
      information.appendChild(element('strong', 'beltoft-domain-result__name', item.domain));

      const descriptions = {
        free: 'Available to register',
        taken: 'Already registered',
        premium: 'Premium domain. Online ordering is unavailable.',
        not_sold: 'This domain extension is not available to order here.'
      };
      information.appendChild(element('span', 'beltoft-domain-result__status', descriptions[item.status] || 'Availability could not be confirmed.'));
      if (item.note && item.status === 'free') information.appendChild(element('span', 'beltoft-domain-result__note', item.note));
      row.appendChild(information);

      if (item.price !== null && item.price !== undefined) {
        const price = element('div', 'beltoft-domain-result__price', '€' + item.price + ' / year');
        // The renewal price is the same as the first year's; saying so under the price answers the question before it is asked.
        if (item.status === 'free') price.appendChild(element('span', 'beltoft-domain-result__renewal', 'Renews at €' + item.price + ' / year'));
        row.appendChild(price);
      }

      const actions = document.createElement('div');
      actions.className = 'beltoft-domain-result__actions';
      if (item.status === 'free') actions.appendChild(action('Register domain', item.domain, 'register', false));
      if (item.status === 'taken') {
        actions.appendChild(action('Transfer domain', item.domain, 'transfer', false));
        actions.appendChild(action('Use DNS hosting', item.domain, 'dns', true));
      }
      if (actions.children.length) row.appendChild(actions);
      return row;
    }

    // A visible waiting state: a registrar answer can take a few seconds.
    function loader() {
      const box = element('div', 'beltoft-domain-loading', '');
      box.setAttribute('role', 'status');
      const bar = element('span', 'beltoft-domain-loading__bar', '');
      bar.setAttribute('aria-hidden', 'true');
      box.appendChild(bar);
      box.appendChild(element('span', 'beltoft-domain-loading__text', 'Searching for domains…'));
      box.appendChild(element('span', 'beltoft-domain-loading__note', 'This takes a few seconds.'));
      return box;
    }

    // Draws a search answer; also used to bring back the last search when the page is opened again.
    function render(data, typed) {
      results.replaceChildren();
      if (!data.results.length) {
        results.textContent = 'No results found.';
        return;
      }
      // An extension we do not sell is noise, unless the customer typed that exact extension.
      const typedExtension = typed.indexOf('.') > 0;
      const shown = data.results.filter(function (item, index) { return item.status !== 'not_sold' || (typedExtension && index === 0); });
      if (shown.some(function (item) { return item.price !== null && item.price !== undefined; })) {
        results.appendChild(element('p', 'beltoft-domain-note', 'Prices are per year, excluding VAT. The renewal price is the same as the first year. DNS hosting and the transfer lock are included.'));
      }
      // The first result is what the customer typed (or the first extension): it leads, the rest are alternatives.
      shown.forEach(function (item, index) { results.appendChild(result(item, index === 0)); });
    }

    async function search() {
      results.replaceChildren(loader());
      results.setAttribute('aria-busy', 'true');
      searchButton.disabled = true;
      try {
        const response = await fetch(root.dataset.check + '?name=' + encodeURIComponent(input.value.trim()), { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'The search could not be completed.');
        render(data, input.value.trim());
        remember(input.value.trim(), data);
      } catch (error) {
        results.textContent = error.message;
      } finally {
        results.removeAttribute('aria-busy');
        searchButton.disabled = false;
      }
    }

    // The last search is kept for this tab (30 minutes), so going back from checkout shows it again without a new lookup.
    const STORE = 'beltoftDomainSearch';
    function remember(typed, data) {
      try { window.sessionStorage.setItem(STORE, JSON.stringify({ q: typed, data: data, t: Date.now() })); } catch (error) { /* storage can be blocked */ }
    }
    function restore() {
      try {
        const saved = JSON.parse(window.sessionStorage.getItem(STORE) || 'null');
        if (!saved || !saved.q || !saved.data || Date.now() - saved.t > 30 * 60 * 1000) return;
        input.value = saved.q;
        render(saved.data, saved.q);
      } catch (error) { /* nothing to restore */ }
    }

    searchButton.addEventListener('click', search);
    restore();
    input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); search(); } });
  });
}());
