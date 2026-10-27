(function ($) {
  'use strict';
  $(document.body).on('change', '#beltoft_services_disk_size', function () {
    var select = $(this);
    select.prop('disabled', true);
    $.post(beltoftServicesDiskField.url, {
      nonce: select.data('nonce'),
      variation_id: select.val()
    }).done(function (reply) {
      if (reply && reply.ok) {
        $(document.body).trigger('update_checkout');
      } else {
        window.alert(beltoftServicesDiskField.failed);
      }
    }).fail(function () {
      window.alert(beltoftServicesDiskField.failed);
    }).always(function () {
      select.prop('disabled', false);
    });
  });
})(jQuery);
