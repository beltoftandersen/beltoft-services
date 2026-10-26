(function () {
  'use strict';

  document.querySelectorAll('.beltoft-domain-search').forEach(function (root) {
    const input = root.querySelector('input[type="search"]');
    const results = root.querySelector('.beltoft-domain-results');
    const searchButton = root.querySelector('button');

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
        headers: { 'Content-Type': 'application/json', 'X-WC-Nonce': root.dataset.nonce },
        body: JSON.stringify({ domain: domain, mode: mode, auth_code: authCode || '' })
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not add this domain.');
      window.location.assign(data.checkout_url);
    }

    function action(label, domain, mode, secondary) {
      const button = element('button', 'beltoft-domain-action' + (secondary ? ' beltoft-domain-action--secondary' : ''), label);
      button.type = 'button';
      button.addEventListener('click', async function () {
        button.disabled = true;
        try {
          let code = '';
          if (mode === 'transfer') {
            code = window.prompt('Enter the transfer code for ' + domain + ':') || '';
            if (!code) return;
          }
          await add(domain, mode, code);
        } catch (error) {
          showError(error.message);
        } finally {
          button.disabled = false;
        }
      });
      return button;
    }

    function result(item) {
      const row = document.createElement('div');
      row.className = 'beltoft-domain-result beltoft-domain-result--' + item.status;
      const information = document.createElement('div');
      information.className = 'beltoft-domain-result__information';
      information.appendChild(element('strong', 'beltoft-domain-result__name', item.domain));

      const descriptions = {
        free: 'Available to register',
        taken: 'Already registered. Transfer it if you own it, or use DNS hosting.',
        premium: 'Premium domain. Online ordering is unavailable.',
        not_sold: 'This domain extension is not available to order here.'
      };
      information.appendChild(element('span', 'beltoft-domain-result__status', descriptions[item.status] || 'Availability could not be confirmed.'));

      if (item.price !== null && item.price !== undefined) {
        const price = document.createElement('div');
        price.className = 'beltoft-domain-result__price';
        price.appendChild(element('strong', 'beltoft-domain-result__amount', '€' + item.price + ' per year'));
        price.appendChild(element('span', 'beltoft-domain-result__vat', 'Excluding VAT'));
        information.appendChild(price);
      }
      row.appendChild(information);

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

    async function search() {
      results.textContent = 'Searching…';
      searchButton.disabled = true;
      try {
        const response = await fetch(root.dataset.check + '?name=' + encodeURIComponent(input.value.trim()), { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'The search could not be completed.');
        results.replaceChildren();
        if (!data.results.length) {
          results.textContent = 'No results found.';
          return;
        }
        data.results.forEach(function (item) { results.appendChild(result(item)); });
      } catch (error) {
        results.textContent = error.message;
      } finally {
        searchButton.disabled = false;
      }
    }

    searchButton.addEventListener('click', search);
    input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); search(); } });
  });
}());
