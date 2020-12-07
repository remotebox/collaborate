(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.changeStatus = {
    attach: function attach(context) {
      document.querySelectorAll('.collaborate-launch').forEach(
          item => {
            if (item.dataset.status === 'attending') {
              item.addEventListener('click', event => {
                updateStatus(item);
              })
            }
      })
      function updateStatus(item) {
        $.ajax({
          type: 'POST',
          cache: false,
          url: '/collaborate/status-update',
          data: {
            sid: item.dataset.sid,
            uid: drupalSettings.user.uid
          },
          dataType: 'json',
        });
      }
    }
  };
})(jQuery, Drupal, drupalSettings);
