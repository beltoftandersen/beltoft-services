(function () {
  'use strict';

  const __ = wp.i18n.__;
  const sprintf = wp.i18n.sprintf;

  function element(tag, className, content) {
    const node = document.createElement(tag);
    node.className = className;
    node.textContent = content;
    return node;
  }

  function showError(container, message) {
    const error = element('p', 'beltoft-domain-error', message);
    error.setAttribute('role', 'alert');
    container.prepend(error);
  }

  // Arrived from the email page of an account: the domain is for that email plan. The server checks the plan again.
  function mailFor() {
    const id = parseInt(new URLSearchParams(window.location.search).get('mail_for') || '', 10);
    return id > 0 ? id : 0;
  }

  // Adds a domain to the order and goes on to the checkout. The root holds the endpoints and nonces.
  async function add(root, domain, mode, authCode, dns) {
    const response = await fetch(root.dataset.cart, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WC-Nonce': root.dataset.nonce, 'X-WP-Nonce': root.dataset.restNonce },
      body: JSON.stringify(Object.assign({ domain: domain, mode: mode, auth_code: authCode || '', dns: dns || '' }, mailFor() ? { mail_for: mailFor() } : {}))
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || __('Could not add this domain.', 'beltoft-services'));
    window.location.assign(data.checkout_url);
  }

  // The same moving bar as the search, on the button that was clicked: the page goes on to the checkout when the add is done.
  function working(button, on, text) {
    if (on) button.dataset.label = button.textContent;
    button.disabled = on;
    button.classList.toggle('is-working', on);
    button.textContent = text;
  }

  // A visible waiting state: a registrar answer can take a few seconds.
  function loader(text) {
    const box = element('div', 'beltoft-domain-loading', '');
    box.setAttribute('role', 'status');
    const bar = element('span', 'beltoft-domain-loading__bar', '');
    bar.setAttribute('aria-hidden', 'true');
    box.appendChild(bar);
    box.appendChild(element('span', 'beltoft-domain-loading__text', text));
    box.appendChild(element('span', 'beltoft-domain-loading__note', __('This takes a few seconds.', 'beltoft-services')));
    return box;
  }

  // One availability lookup through the public check route.
  async function check(root, name) {
    const response = await fetch(root.dataset.check + '?name=' + encodeURIComponent(name), { credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || __('The search could not be completed.', 'beltoft-services'));
    return data;
  }

  // A button that registers a free domain.
  function registerButton(root, domain, errors) {
    const button = element('button', 'beltoft-domain-action', __('Register domain', 'beltoft-services'));
    button.type = 'button';
    button.addEventListener('click', async function () {
      working(button, true, __('Adding to order…', 'beltoft-services'));
      try {
        await add(root, domain, 'register', '');
      } catch (error) {
        showError(errors, error.message);
        working(button, false, __('Register domain', 'beltoft-services'));
      }
    });
    return button;
  }

  /* ---------- the search: free domains to register; a taken one is shown greyed out ---------- */

  function initSearch(root) {
    const input = root.querySelector('input[type="search"]');
    const results = root.querySelector('.beltoft-domain-results');
    const offer = root.querySelector('.beltoft-domain-offer');
    const searchButton = root.querySelector(':scope > button');
    const transferLink = root.querySelector('.beltoft-domain-search__transfer a');
    if (!input.placeholder) input.placeholder = 'example.com';

    // Where "Transfer this domain" goes: the transfer section, with the domain filled in.
    function transferHref(domain) {
      const url = new URL(transferLink.getAttribute('href'), window.location.href);
      url.searchParams.set('transfer', domain);
      if (mailFor() && !url.searchParams.has('mail_for')) url.searchParams.set('mail_for', String(mailFor()));
      return url.toString();
    }

    function result(item, primary) {
      const row = document.createElement('div');
      // A taken domain is never highlighted, even when it is the one typed: only what can be registered stands out.
      row.className = 'beltoft-domain-result beltoft-domain-result--' + item.status + (primary && item.status !== 'taken' ? ' beltoft-domain-result--primary' : '');
      const information = document.createElement('div');
      information.className = 'beltoft-domain-result__information';
      information.appendChild(element('strong', 'beltoft-domain-result__name', item.domain));

      const descriptions = {
        free: __('Available to register', 'beltoft-services'),
        taken: __('Taken', 'beltoft-services'),
        premium: __('Premium domain. Online ordering is unavailable.', 'beltoft-services'),
        not_sold: __('This domain extension is not available to order here.', 'beltoft-services')
      };
      information.appendChild(element('span', 'beltoft-domain-result__status', descriptions[item.status] || __('Availability could not be confirmed.', 'beltoft-services')));
      if (item.note && item.status === 'free') information.appendChild(element('span', 'beltoft-domain-result__note', item.note));
      row.appendChild(information);

      if (item.status === 'free' && item.price !== null && item.price !== undefined) {
        const price = element('div', 'beltoft-domain-result__price', sprintf(/* translators: %s: price in euro */ __('€%s / year', 'beltoft-services'), item.price));
        // The renewal price is the same as the first year's; saying so under the price answers the question before it is asked.
        price.appendChild(element('span', 'beltoft-domain-result__renewal', sprintf(/* translators: %s: price in euro */ __('Renews at €%s / year', 'beltoft-services'), item.price)));
        row.appendChild(price);
      }

      const actions = document.createElement('div');
      actions.className = 'beltoft-domain-result__actions';
      if (item.status === 'free') actions.appendChild(registerButton(root, item.domain, results));
      if (item.status === 'taken' && transferLink) {
        const link = element('a', 'beltoft-domain-result__transfer', __('Transfer this domain', 'beltoft-services'));
        link.href = transferHref(item.domain);
        link.addEventListener('click', function (event) {
          // The transfer section is on this page: fill it in and go there without a reload.
          const box = document.querySelector('.beltoft-domain-transfer-box');
          if (!box || !box.beltoftStart) return;
          event.preventDefault();
          box.beltoftStart(item.domain);
        });
        actions.appendChild(link);
      }
      if (actions.children.length) row.appendChild(actions);
      return row;
    }

    // Draws a search answer; also used to bring back the last search when the page is opened again.
    function render(data, typed) {
      results.replaceChildren();
      if (offer) offer.hidden = true;
      if (!data.results.length) {
        results.textContent = __('No results found.', 'beltoft-services');
        return;
      }
      // An extension we do not sell is noise, unless the customer typed that exact extension.
      const typedExtension = typed.indexOf('.') > 0;
      const shown = data.results.filter(function (item, index) { return item.status !== 'not_sold' || (typedExtension && index === 0); });
      if (shown.some(function (item) { return item.status === 'free'; })) {
        results.appendChild(element('p', 'beltoft-domain-note', __('Prices are per year, excluding VAT. The renewal price is the same as the first year. DNS hosting and the transfer lock are included.', 'beltoft-services')));
      }
      // The first result is what the customer typed (or the first extension): it leads, the rest are alternatives.
      shown.forEach(function (item, index) { results.appendChild(result(item, index === 0)); });
      // The offer under the results (for example hosting for the new domain) only makes sense once something can be registered.
      if (offer) offer.hidden = !shown.some(function (item) { return item.status === 'free'; });
    }

    const param = root.dataset.param || 'domain';

    // The search page is elsewhere (a search box on the front page, for example): go there, and that page searches.
    function handOff() {
      if (!root.dataset.searchUrl) return false;
      const target = new URL(root.dataset.searchUrl, window.location.href);
      if (target.pathname.replace(/\/+$/, '') === window.location.pathname.replace(/\/+$/, '')) return false;
      const typed = input.value.trim();
      if (!typed) { input.focus(); return true; }
      target.searchParams.set(param, typed);
      remember(typed, null); // going back to this page puts the term in the box again
      window.location.assign(target.toString());
      return true;
    }

    async function search() {
      if (handOff()) return;
      if (offer) offer.hidden = true;
      results.replaceChildren(loader(__('Searching for domains…', 'beltoft-services')));
      results.setAttribute('aria-busy', 'true');
      searchButton.disabled = true;
      try {
        const data = await check(root, input.value.trim());
        render(data, input.value.trim());
        remember(input.value.trim(), data);
        keepInAddress(input.value.trim());
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
        if (!saved || !saved.q || Date.now() - saved.t > 30 * 60 * 1000) return;
        input.value = saved.q;
        // A form that sends its searches to another page only gets the term back, not the results.
        if (saved.data && !root.dataset.searchUrl) render(saved.data, saved.q);
      } catch (error) { /* nothing to restore */ }
    }
    // The term goes in the address of the results page, so the back button and a reload show the same search.
    function keepInAddress(typed) {
      try {
        const url = new URL(window.location.href);
        if (url.searchParams.get(param) === typed) return;
        url.searchParams.set(param, typed);
        window.history.replaceState(window.history.state, '', url.toString());
      } catch (error) { /* the address stays as it was */ }
    }

    root.beltoftSearch = search;
    searchButton.addEventListener('click', search);
    // Arrived from a search box on another page: the term is in the address, so search for it now.
    const asked = root.dataset.searchUrl ? '' : (new URLSearchParams(window.location.search).get(param) || '').trim();
    if (asked) {
      input.value = asked;
      root.scrollIntoView({ behavior: 'auto', block: 'start' }); // the search starts where the visitor can see it
      search();
    } else {
      restore();
    }
    input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); search(); } });
  }

  /* ---------- the transfer section: a registered domain moved here with its transfer code ---------- */

  function initTransfer(root) {
    const input = root.querySelector(':scope > input');
    const button = root.querySelector(':scope > button');
    const out = root.querySelector('.beltoft-domain-transfer-result');
    if (!input.placeholder) input.placeholder = 'example.com';

    // What the public data says about the domain now: its current registrar, whether it is locked, whether its DNS is run by that registrar.
    async function recommendation(domain) {
      if (!root.dataset.transferDns) return null;
      try {
        const response = await fetch(root.dataset.transferDns + '?domain=' + encodeURIComponent(domain), {
          credentials: 'same-origin',
          headers: { 'X-WC-Nonce': root.dataset.nonce, 'X-WP-Nonce': root.dataset.restNonce }
        });
        return response.ok ? await response.json() : null;
      } catch (error) {
        return null;
      }
    }

    function codeForm(domain) {
      const form = document.createElement('form');
      form.className = 'beltoft-domain-transfer';
      form.appendChild(element('p', 'beltoft-domain-transfer__help', sprintf(/* translators: %s: domain name */ __('Unlock %s at your current registrar and copy its transfer code, also called EPP or auth code. You find it in their domain settings. Turn off DNSSEC at your current registrar first.', 'beltoft-services'), domain)));
      const field = document.createElement('input');
      field.type = 'text';
      field.autocomplete = 'off';
      field.required = true;
      field.setAttribute('aria-label', sprintf(/* translators: %s: domain name */ __('Transfer code for %s', 'beltoft-services'), domain));
      field.placeholder = __('Transfer code', 'beltoft-services');
      const submit = element('button', 'beltoft-domain-action', __('Add transfer to order', 'beltoft-services'));
      submit.type = 'submit';
      const notice = element('p', 'beltoft-domain-transfer__help', '');
      notice.setAttribute('aria-live', 'polite');
      const row = element('div', 'beltoft-domain-transfer__row', '');
      row.append(field, submit);
      form.append(notice, row, element('p', 'beltoft-domain-transfer__help', __('The transfer takes up to 5 days and adds one year to the registration.', 'beltoft-services')));
      recommendation(domain).then(function (advice) {
        if (!advice) return;
        const who = advice.registrar ? String(advice.registrar).replace(/\.+$/, '') : __('your current registrar', 'beltoft-services');
        if (advice.locked) {
          notice.textContent = sprintf(/* translators: 1: domain name, 2: registrar name */ __('%1$s is locked at %2$s. Unlock it there, then come back.', 'beltoft-services'), domain, who);
          submit.disabled = true;
          field.disabled = true;
        } else if (advice.at_risk === true) {
          notice.textContent = sprintf(/* translators: %s: registrar name */ __('Your DNS is run by %s and stops when the domain moves. After you order, you import your records here before the transfer starts.', 'beltoft-services'), who);
        }
      });
      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const code = field.value.trim();
        if (!code) return;
        working(submit, true, __('Adding to order…', 'beltoft-services'));
        try {
          await add(root, domain, 'transfer', code, '');
        } catch (error) {
          showError(out, error.message);
          working(submit, false, __('Add transfer to order', 'beltoft-services'));
        }
      });
      return { form: form, field: field };
    }

    function render(item) {
      out.replaceChildren();
      const card = element('div', 'beltoft-domain-result beltoft-domain-result--primary beltoft-domain-result--' + item.status, '');
      const information = element('div', 'beltoft-domain-result__information', '');
      information.appendChild(element('strong', 'beltoft-domain-result__name', item.domain));
      card.appendChild(information);
      const actions = element('div', 'beltoft-domain-result__actions', '');
      card.appendChild(actions);
      out.appendChild(card);
      const hasPrice = item.price !== null && item.price !== undefined;

      if (item.status === 'taken') {
        information.appendChild(element('span', 'beltoft-domain-result__status', __('Registered, can be transferred', 'beltoft-services')));
        if (hasPrice) {
          card.insertBefore(element('div', 'beltoft-domain-result__price', sprintf(/* translators: %s: price in euro */ __('€%s / year', 'beltoft-services'), item.price)), actions);
          information.appendChild(element('span', 'beltoft-domain-result__note', __('The transfer is charged as one year and adds that year to the registration. Price excluding VAT.', 'beltoft-services')));
        }
        const code = codeForm(item.domain);
        actions.appendChild(code.form);
        code.field.focus();
        return;
      }
      if (item.status === 'free') {
        information.appendChild(element('span', 'beltoft-domain-result__status', __('Not registered, so there is nothing to transfer. You can register it instead.', 'beltoft-services')));
        if (hasPrice) card.insertBefore(element('div', 'beltoft-domain-result__price', sprintf(/* translators: %s: price in euro */ __('€%s / year', 'beltoft-services'), item.price)), actions);
        actions.appendChild(registerButton(root, item.domain, out));
        return;
      }
      const reasons = {
        premium: __('Premium domain. It cannot be transferred online.', 'beltoft-services'),
        not_sold: __('This domain extension is not available here.', 'beltoft-services')
      };
      information.appendChild(element('span', 'beltoft-domain-result__status', reasons[item.status] || __('The domain could not be checked. Try again in a minute.', 'beltoft-services')));
    }

    async function start(domain) {
      if (domain) input.value = domain;
      const wanted = input.value.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/[/?#].*$/, '');
      if (!wanted) { input.focus(); return; }
      if (wanted.indexOf('.') < 1) {
        out.textContent = __('Enter the full domain, for example example.com.', 'beltoft-services');
        return;
      }
      root.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
      out.replaceChildren(loader(__('Checking the domain…', 'beltoft-services')));
      out.setAttribute('aria-busy', 'true');
      button.disabled = true;
      try {
        const data = await check(root, wanted);
        const item = data.results.find(function (row) { return row.domain === wanted; }) || data.results[0];
        if (!item) {
          out.textContent = __('The domain could not be checked. Try again in a minute.', 'beltoft-services');
          return;
        }
        render(item);
      } catch (error) {
        out.textContent = error.message;
      } finally {
        out.removeAttribute('aria-busy');
        button.disabled = false;
      }
    }

    root.beltoftStart = start;
    button.addEventListener('click', function () { start(''); });
    input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); start(''); } });
    // Arrived from a search result on another page: the domain is in the address.
    const asked = new URLSearchParams(window.location.search).get('transfer');
    if (asked) start(asked);
  }

  // Sets up every form that is not set up yet. Bricks calls this again when the element is rendered in the builder.
  // A link or card with data-beltoft-domain-ext="dk" puts that extension behind the typed name and searches for it.
  document.addEventListener('click', function (event) {
    const link = event.target.closest('[data-beltoft-domain-ext]');
    if (!link) return;
    const roots = Array.prototype.slice.call(document.querySelectorAll('.beltoft-domain-search'));
    const root = roots.find(function (r) { return !r.dataset.searchUrl; }) || roots[0];
    if (!root || !root.beltoftSearch) return;
    event.preventDefault();
    const input = root.querySelector(':scope > input');
    const extension = String(link.dataset.beltoftDomainExt).replace(/^\.+/, '').toLowerCase();
    const name = input.value.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/^www\./, '').split(/[./?#]/)[0];
    root.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    if (!name) {
      input.value = '.' + extension;
      input.focus();
      input.setSelectionRange(0, 0);
      return;
    }
    input.value = name + '.' + extension;
    root.beltoftSearch();
  });

  function init() {
    document.querySelectorAll('.beltoft-domain-search').forEach(function (root) {
      if (root.dataset.bdsReady) return;
      root.dataset.bdsReady = '1';
      initSearch(root);
    });
    document.querySelectorAll('.beltoft-domain-transfer-box').forEach(function (root) {
      if (root.dataset.bdsReady) return;
      root.dataset.bdsReady = '1';
      initTransfer(root);
    });
  }

  window.beltoftDomainSearchInit = init;
  init();
}());

// Back from checkout or a login restores this page from the browser cache, with the nonces it had
// then (the visitor may have logged in since): take fresh ones from the page, without a reload.
window.addEventListener('pageshow', function (event) {
  if (!event.persisted) return;
  fetch(window.location.href, { credentials: 'same-origin', cache: 'reload' })
    .then(function (response) { return response.text(); })
    .then(function (html) {
      const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('.beltoft-domain-search, .beltoft-domain-transfer-box');
      if (!fresh) return;
      document.querySelectorAll('.beltoft-domain-search, .beltoft-domain-transfer-box').forEach(function (root) {
        root.dataset.nonce = fresh.dataset.nonce;
        root.dataset.restNonce = fresh.dataset.restNonce;
      });
    })
    .catch(function () { /* the add reports its own error */ });
});

// The browser keeps a snapshot of the page when it is left: make it a clean one, not the waiting state.
window.addEventListener('pagehide', function () {
  document.querySelectorAll('.beltoft-domain-action.is-working').forEach(function (button) {
    button.disabled = false;
    button.classList.remove('is-working');
    button.textContent = button.dataset.label || button.textContent;
  });
});
