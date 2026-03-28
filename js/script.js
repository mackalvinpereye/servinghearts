//for header navbar
const menuIcon = document.getElementById('menu-icon');
const navbar = document.getElementById('navbar');

menuIcon.addEventListener('click', () => {
    navbar.classList.toggle('show');
});

const currentPage = window.location.pathname.split('/').pop();
const navLinks = document.querySelectorAll('#navbar a');

navLinks.forEach(link => {
    const linkPage = link.getAttribute('href').split('/').pop(); // Normalize the href
    if (linkPage === currentPage) {
        link.classList.add('active'); // Add the 'active' class to the matching link
    }
});


// section mission vision
window.addEventListener('scroll', function() {
const missionVisionSection = document.querySelector('.mission-vision-section');
const overlay = document.querySelector('.mission-vision-overlay');

// Get the position of the mission-vision-section relative to the viewport
const sectionPosition = missionVisionSection.getBoundingClientRect().top;
const screenHeight = window.innerHeight;

// If the section is visible in the viewport, add the animation class
if (sectionPosition < screenHeight - 100) { // Adjust the -100 for when you want the effect to trigger
    overlay.classList.add('visible');
} else {
    overlay.classList.remove('visible');
}
});


//for services
const track = document.querySelector('.carousel-track');
const prevButton = document.querySelector('.carousel-prev');
const nextButton = document.querySelector('.carousel-next');

prevButton.addEventListener('click', () => {
    track.scrollBy({ left: -300, behavior: 'smooth' }); // Scroll left by 300px
});

nextButton.addEventListener('click', () => {
    track.scrollBy({ left: 300, behavior: 'smooth' }); // Scroll right by 300px
});

//for contactus
var swiper = new Swiper(".home-slider", {
  loop: true,
  grabCursor: true,
  navigation: {
    nextEl: ".swiper-button-next",
    prevEl: ".swiper-button-prev",
  },
  autoplay: {
    delay: 2500,
    disableOnInteraction: false,
  },
});

document.addEventListener("DOMContentLoaded", () => {
const services = document.querySelectorAll(".service");

const observer = new IntersectionObserver(entries => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add("visible");
      observer.unobserve(entry.target);  // Stop observing once it has become visible
    }
  });
}, {
  threshold: 0.2
});

services.forEach(service => {
  observer.observe(service);
});
});

