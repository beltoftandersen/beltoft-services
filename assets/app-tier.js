(function ($) {
  'use strict';

  // Shows the tiers of the chosen paid app only (the others are disabled, so they are not posted), and at
  // checkout keeps the tier's cart line in step with the choice.
  var root = document.querySelector('[data-app-field]');
  if (!root) {
    return;
  }

  function chosenApp() {
    var checked = root.querySelector('input[name="beltoft_services_app"]:checked');
    return checked ? checked.value : '';
  }

  function show() {
    var app = chosenApp();
    root.querySelectorAll('[data-app-tiers]').forEach(function (group) {
      var on = group.getAttribute('data-app-tiers') === app;
      group.hidden = !on;
      group.querySelectorAll('input').forEach(function (input) {
        input.disabled = !on;
        if (!on) {
          input.checked = false;
        }
      });
    });
  }

  function sync() {
    var config = window.beltoftServicesAppTier;
    if (!config) {
      return;
    }
    var tier = root.querySelector('[data-app-tiers]:not([hidden]) input:checked');
    var app = chosenApp();
    var busy = $(root).find('input');
    busy.prop('disabled', true);
    $.post(config.url, { nonce: config.nonce, app: app, tier: tier ? tier.value : '' })
      .done(function (reply) {
        if (reply && reply.ok) {
          $(document.body).trigger('update_checkout');
        } else if (app) {
          window.alert(config.failed);
        }
      })
      .fail(function () {
        window.alert(config.failed);
      })
      .always(function () {
        busy.prop('disabled', false);
        show();
      });
  }

  // The billing choice of the server (pay as you go or monthly) changes which price a tier shows. The server's cart
  // line has been swapped by then and its tier line follows (the server side matches it), so the cart is refreshed.
  function prices() {
    var billing = document.querySelector('input[name="beltoft_services_billing"]:checked');
    var usage = !!billing && billing.value === 'usage';
    root.querySelectorAll('[data-price-monthly]').forEach(function (el) {
      el.hidden = usage;
    });
    root.querySelectorAll('[data-price-usage]').forEach(function (el) {
      el.hidden = !usage;
    });
    root.querySelectorAll('input[data-no-usage]').forEach(function (input) {
      input.disabled = usage || input.closest('[data-app-tiers]').hidden;
      if (usage) {
        input.checked = false;
      }
    });
  }

  $(document.body).on('change', 'input[name="beltoft_services_billing"]', function () {
    prices();
    // Wait for the billing swap (its own request) before the cart is read again.
    window.setTimeout(sync, 800);
  });

  root.addEventListener('change', function (event) {
    var target = event.target;
    if (!target || !target.name) {
      return;
    }
    if (target.name === 'beltoft_services_app') {
      show();
      // A paid app has no tier yet; the cart line follows the tier. Choosing another app drops the old line.
      sync();
    } else if (target.hasAttribute('data-app-tier')) {
      sync();
    }
  });

  show();
  prices();
})(jQuery);
