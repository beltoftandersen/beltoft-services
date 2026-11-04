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
        window.setTimeout(function () { window.beltoftStatus.fail(null, beltoftServicesDiskField.failed, select.get(0)); }, 0); // after the field is enabled again, so it can take the focus
      }
    }).fail(function () {
      window.setTimeout(function () { window.beltoftStatus.fail(null, beltoftServicesDiskField.failed, select.get(0)); }, 0); // after the field is enabled again, so it can take the focus
    }).always(function () {
      select.prop('disabled', false);
    });
  });
})(jQuery);
