/**
 * @file
 * Global utilities.
 *
 */
 (function($, Drupal, drupalSettings) {

    'use strict';
  
    Drupal.behaviors.l1Interactions = {
      attach: function(context, settings) {

        $('.l1-toc-item a').on('click', function() {
          var targetposter = $(this).data('for');

          $('.l1-poster-body').each(function() {
            $(this).removeClass('show').addClass('hidden');
          });
  
          $('#'+targetposter).addClass('show').removeClass('hidden');
        });

      }
    };
  
  }(jQuery, Drupal, drupalSettings));