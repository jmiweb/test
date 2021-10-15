/**
 * @file
 * Global utilities.
 *
 */
(function($, Drupal, drupalSettings) {

  'use strict';

  Drupal.behaviors.hamburgerMenu = {
    attach: function(context) {
      $('.hamburger').on('click', function() {
        if ($(this).attr('aria-expanded') == 'true' ) {
          $(this).removeClass('is-active');
        } else {
          $(this).addClass('is-active');
        }
      });
    }
  };

  Drupal.behaviors.faqToggle = {
    attach: function(context) {
      $('.faq-item--question', context).each(function () {
        $(this).on('click', function() {
          if($(this).attr('aria-expanded') == 'false') {
            $(this).attr('aria-expanded', 'true');
            $(this).siblings('.faq-item--answer').slideToggle();
          } else {
            $(this).attr('aria-expanded', 'false');
            $(this).siblings('.faq-item--answer').slideToggle();
          }
        });
      });
    }
  };

  Drupal.behaviors.stickyElement = {
    attach: function(context) {
      var footerOffset = $('.site-footer').outerHeight();

      // Check every time the user scrolls
      $(window).scroll(function (event) {
        var y = ($(this).scrollTop() + $(window).height());
        var y2 = ($(document).height() - footerOffset);

        if ( y >= y2 ) {
          $('.sticky-element').addClass('bottom');          
        } else if ( y < y2 ) {
          $('.sticky-element').removeClass('bottom');
        }
      });
    }
  };

  Drupal.behaviors.productPage = {
    attach: function(context) {
      // Assign data attribute to each field item
      $.each($(".field--name-field-product-page-design-images > .field__item"), function(ind) {
        $(this).attr("data-img-id","img-" + parseInt(ind +1));
      });

      // Move full images to a display container and assign id to each image
      $.each($(".field--name-field-product-page-design-images > .field__item"), function(ind) {
        var fullImg = $(".field--name-field-prod-design-img-main", this);
        fullImg.attr("id","img-" + parseInt(ind +1));
        $(".product--full-image").append(fullImg);
      });

      // Set default active states
      $(".field--name-field-product-page-design-images .field__item:first-child").addClass("active");
      $(".product--full-image > div:first-child").addClass("active");
      $(".variation-labels .field--name-variations > .field__item:first-child").addClass("active");
      $(".variation-links .field--name-variations > .field__item:first-child").addClass("active");

      // On click toggle active thumb with matching full image
      $(".field--name-field-product-page-design-images > .field__item").on("click", function() {
        var dataId = $(this).data("img-id");

        if($(this).hasClass("active")) {
          // do nothing
        } else {
          $(".product--full-image > div").removeClass("active");
          $(this).siblings().removeClass("active");
          $(this).addClass("active");
          $("#"+dataId).addClass("active");
        }        
      });

      $.each($(".variation-labels .field--name-variations > .field__item"), function(ind) {
        $(this).attr("data-var-id","var-" + parseInt(ind +1));
      });

      $.each($(".variation-links .field--name-variations > .field__item"), function(ind) {
        $(this).addClass("var-" + parseInt(ind +1));
      });

      $(".variation-labels .field--name-variations > .field__item").on("click", function() {
        var varId = $(this).data("var-id");

        if($(this).hasClass("active")) {

        } else {
          $(".variation-links .field--name-variations > .field__item").removeClass("active");
          $(this).siblings().removeClass("active");
          $(this).addClass("active");
          $("."+varId).addClass("active");
        }
      });

      // L2 Pages
      $('.product--variant-nav a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        var activeId = $(e.target).attr("href");
        var img = $(activeId).find('.field--name-field-media-image > .field__item > img').clone();
        $('#product-image').html(img).zoom({url: $(this).find('img').attr('data-zoom')});
      });

      $('.product--variant-nav li:first-child a[data-toggle="tab"]').tab('show');

      $('.product--variant-pager').click(function(){
        if ($('.product--variant-nav li:last-child a').hasClass('active')) {
          $('.product--variant-nav li:first-child').find('a').trigger('click');
        } else {
          $('.product--variant-nav li .active').parent().next('li').find('a').trigger('click');
        }
      });

      $('.product--variant-pager .previous').click(function(){
       $('.product--variant-nav li .active').parent().prev('li').find('a').trigger('click');
      });
    }
  };

}(jQuery, Drupal, drupalSettings));