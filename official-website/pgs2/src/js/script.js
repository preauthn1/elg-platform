const menu = document.querySelector("#menu");
const menuIcon = document.querySelector("#menu-icon");

AOS.init();

const toggleMenu = () => {
  menu.classList.toggle("hidden");
  menuIcon.innerHTML = menu.classList.contains("hidden") ? "menu" : "close";
};

const closeMenu = () => {
  menu.classList.add("hidden");
  menuIcon.innerHTML = "menu";
};

const postForm = (e) => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const action = e.target.action;
  fetch(action, {
    method: "POST",
    body: formData,
  }).then(() => {
    alert(
      "Thank you!\nwe have received your application and will get back to you soon!"
    );
    e.target.reset();
  });
};

const showAgeModal = () => {
  document.querySelector("#age-modal").classList.remove("d-none");
  document.body.style.overflowY = "hidden";
};

const hideAgeModal = () => {
  document.querySelector("#age-modal").classList.add("d-none");
  localStorage.setItem("is18", true);
  document.body.style.overflowY = "auto";
};

const goBackOrDefault = () => {
  if (document.referrer === "") {
    window.location.href = "https://example.invalid";
  } else {
    window.history.back();
  }
};

document.addEventListener("DOMContentLoaded", () => {
  document.querySelector("#form").addEventListener("submit", postForm);

  const is18 = localStorage.getItem("is18");
  if (!is18) {
    showAgeModal();
  }
});
