(function () {
  'use strict';
  document.querySelectorAll('.beltoft-domain-search').forEach(function (root) {
    const input = root.querySelector('input[type="search"]');
    const results = root.querySelector('.beltoft-domain-results');
    const searchButton = root.querySelector('button');

    async function add(domain, mode, authCode) {
      const response = await fetch(root.dataset.cart, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WC-Nonce': root.dataset.nonce },
        body: JSON.stringify({ domain: domain, mode: mode, auth_code: authCode || '' })
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not add this domain.');
      window.location.assign(data.cart_url);
    }

    function action(label, domain, mode) {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
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
          results.textContent = error.message;
        } finally {
          button.disabled = false;
        }
      });
      return button;
    }

    async function search() {
      results.textContent = 'Searching…';
      try {
        const response = await fetch(root.dataset.check + '?name=' + encodeURIComponent(input.value.trim()), { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'The search could not be completed.');
        results.replaceChildren();
        data.results.forEach(function (item) {
          const row = document.createElement('div');
          row.className = 'beltoft-domain-result';
          const name = document.createElement('span');
          name.textContent = item.domain + ' · ' + item.status + (item.price === null ? '' : ' · €' + item.price + '/year');
          row.appendChild(name);
          if (item.status === 'free') row.appendChild(action('Register', item.domain, 'register'));
          if (item.status === 'taken') {
            row.appendChild(action('Transfer here', item.domain, 'transfer'));
            row.appendChild(action('Host its DNS here', item.domain, 'dns'));
          }
          results.appendChild(row);
        });
      } catch (error) {
        results.textContent = error.message;
      }
    }

    searchButton.addEventListener('click', search);
    input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); search(); } });
  });
}());
