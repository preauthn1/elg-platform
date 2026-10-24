window.switchLanguage = function (url) {
  window.location.href = url;
};

// 取得語言
window.getLangFromUrl = function () {
  let path = window.location.pathname;
  if (path.includes("/zh-CN")) return "cn";
  if (path.includes("/ms")) return "ms";
  return "en";
};

// Active Button
window.setActiveButton = function () {
  let lang = window.getLangFromUrl();
  let buttons = document.querySelectorAll(".language-btn");

  buttons.forEach(function (btn) {
    btn.classList.remove("active");
  });

  if (lang == "en") document.getElementById("lang-en").classList.add("active");
  else if (lang == "cn")
    document.getElementById("lang-cn").classList.add("active");
  else if (lang == "ms")
    document.getElementById("lang-ms").classList.add("active");
};

// Banner 切換
window.updateLanguage = function (language) {
  const banners = {
    en: document.getElementById("en-banner"),
    cn: document.getElementById("cn-banner"),
    ms: document.getElementById("ms-banner"),
  };

  Object.values(banners).forEach((b) => (b.style.display = "none"));

  (banners[language] || banners.en).style.display = "block";
};

// Modal
let slideIndex = 1;

window.openModal = function () {
  document.getElementById("redeemModal").style.display = "block";
  slideIndex = 1;
  window.showSlides(slideIndex);
};

window.closeModal = function () {
  document.getElementById("redeemModal").style.display = "none";
};

window.addEventListener("click", function (event) {
  const modal = document.getElementById("redeemModal");
  if (event.target === modal) {
    window.closeModal();
  }
});

window.plusSlides = function (n) {
  window.showSlides((slideIndex += n));
};

window.currentSlide = function (n) {
  window.showSlides((slideIndex = n));
};

window.showSlides = function (n) {
  let i;
  let slides = document.getElementsByClassName("slides");
  let dots = document.getElementsByClassName("dot");

  if (n > slides.length) slideIndex = 1;
  if (n < 1) slideIndex = slides.length;

  for (i = 0; i < slides.length; i++) {
    slides[i].style.display = "none";
  }

  for (i = 0; i < dots.length; i++) {
    dots[i].className = dots[i].className.replace(" active", "");
  }

  slides[slideIndex - 1].style.display = "block";
  dots[slideIndex - 1].className += " active";
};

document.addEventListener("DOMContentLoaded", function () {
  window.setActiveButton();

  let currentLang = window.getLangFromUrl();
  window.updateLanguage(currentLang);

  document.querySelectorAll("p, li, div, span").forEach(function (el) {
    if (el.innerHTML.includes("&lt;b")) {
      el.innerHTML = el.innerHTML
        .replace(/&lt;b&gt;/g, "<b>")
        .replace(/&lt;\/b&gt;/g, "</b>");
    }
  });
});
