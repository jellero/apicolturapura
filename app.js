const menuButton = document.querySelector('.menu-toggle');
const nav = document.querySelector('.main-nav');

if (menuButton && nav) {
  menuButton.addEventListener('click', () => {
    const open = menuButton.getAttribute('aria-expanded') === 'true';
    menuButton.setAttribute('aria-expanded', String(!open));
    nav.classList.toggle('is-open', !open);
    document.body.style.overflow = open ? '' : 'hidden';
  });

  nav.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      menuButton.setAttribute('aria-expanded', 'false');
      nav.classList.remove('is-open');
      document.body.style.overflow = '';
    });
  });
}

// Il logo originale è nero: sulle superfici verdi viene mostrato in bianco
// senza alterare il file sorgente del marchio.
const brandLogo = document.querySelector('.brand img');
if (brandLogo) {
  brandLogo.style.filter = 'brightness(0) invert(1) drop-shadow(0 3px 12px rgba(0,0,0,.18))';
}

// La card territorio deve comunicare il luogo, non il CAP.
const territoryCard = document.querySelector('.altitude-card');
if (territoryCard) {
  const title = territoryCard.querySelector('strong');
  const labels = territoryCard.querySelectorAll(':scope > span');
  const description = territoryCard.querySelector('p');

  if (title) title.textContent = 'Lauco';
  if (labels.length > 1) labels[1].textContent = 'Carnia · Friuli Venezia Giulia';
  if (description) description.textContent = 'Il paese da cui parte Bioapicoltura Pura, tra boschi, prati e fioriture di montagna.';
}

// Rimuove il CAP anche dall'indirizzo visibile nel footer.
document.querySelectorAll('.site-footer span').forEach((item) => {
  item.textContent = item.textContent.replace('33029 ', '');
});

const revealItems = document.querySelectorAll('.reveal');

if ('IntersectionObserver' in window) {
  const observer = new IntersectionObserver((entries, obs) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -30px' });

  revealItems.forEach((item) => observer.observe(item));
} else {
  revealItems.forEach((item) => item.classList.add('is-visible'));
}
