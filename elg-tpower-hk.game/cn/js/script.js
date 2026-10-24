const lang = navigator.language || navigator.userLanguage;
$(function () {
  var scrollTop = $(".scrollTop");

  $(window).scroll(function () {
    var topPos = $(this).scrollTop();
    if (topPos > 100) {
      $(scrollTop).css("opacity", "1");

    } else {
      $(scrollTop).css("opacity", "0");
    }
  });
  $(scrollTop).click(function () {
    $('html, body').animate({
      scrollTop: 0
    }, 800);
    return false;

  });
  $('.mobile__button').on('click', function (e) {
    $('html').toggleClass('offcanvas-open');
    return false;
  });
  $('.nav_bar ul li a').on('click', function (e) {
    $('html').removeClass('offcanvas-open');
  });
  $('.language').on('click', function (e) {
    $('html').toggleClass('language-open');
    return false;
  });
  $('.language-close').on('click', function (e) {
    $('html').removeClass('language-open');
  });
  ////////////////////////////////////////////////////
  $('.stepSlider').slick({
    arrows: true,
    infinite: true,
  });
  $('.stepSlider').slick('setPosition');
  $(window).on('resize', function () {
    $('.stepSlider').slick('resize');
  });
});
////////////////////////////////////////////////////
$(document).ready(function () {
  $('a[data-scroll]').click(function (e) {
    e.preventDefault();

    var navHeight = $('#nav').outerHeight();
    var targetId = $(this).attr('href');
    var targetOffset = $(targetId).offset().top;

    $('html, body').animate({
      scrollTop: targetOffset - navHeight
    }, 1000);
  });
});
