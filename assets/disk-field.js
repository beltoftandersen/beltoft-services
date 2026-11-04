(function ($) {
  'use strict';
  // The choice goes through the shared queue (cart-swap.js), together with the backups box.
  $(document.body).on('change', '#beltoft_services_disk_size', function () {
    window.beltoftCartSwap.changed(this);
  });
})(jQuery);
