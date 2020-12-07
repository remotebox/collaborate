/**
 * @file
 * Contains js for the accordion example.
 */

(function ($) {

  'use strict';

  $(function () {
    $('#showAvailable').on('click', function () {
      let text = $('#showAvailable').html();
      if (text === "Show available tutorials <span class='badge badge-aso-accent text-dark'>beta</span>") {
        $(this).html("Hide available tutorials <span class='badge badge-aso-accent text-dark'>beta</span>");
      } else {
        $(this).html("Show available tutorials <span class='badge badge-aso-accent text-dark'>beta</span>");
      }
    });
  });

})(jQuery);
