(function () {
  'use strict';
  const fields = ['billing_first_name', 'billing_last_name', 'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_city', 'billing_country', 'billing_phone', 'billing_email'];
  function value(index) {
    const input = document.getElementById(fields[index]);
    return input ? input.value.trim() : '';
  }
  function update() {
    const box = document.querySelector('.beltoft-dk-agreement');
    if (!box) return;
    box.querySelector('[data-dk-applicant]').textContent = [value(0), value(1)].filter(Boolean).join(' ') || 'Enter the billing name above';
    box.querySelector('[data-dk-address]').textContent = [value(2), value(3), value(4), value(5), value(6)].filter(Boolean).join(', ') || 'Enter the billing address above';
    const phone = box.querySelector('[data-dk-phone]');
    const email = box.querySelector('[data-dk-email]');
    if (phone) phone.textContent = value(7);
    if (email) email.textContent = value(8);
  }
  document.addEventListener('input', update);
  document.addEventListener('change', update);
  if (window.jQuery) window.jQuery(document.body).on('updated_checkout', update);
  update();
}());
