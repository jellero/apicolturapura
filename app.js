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

// Mantiene le fotografie fornite entro la loro risoluzione utile: niente upscale
// aggressivo dei JPEG e niente crop sul vasetto prodotto.
const photoStyle = document.createElement('style');
photoStyle.textContent = `
  .brand-photos { align-items: center; }
  .brand-photos .people-photo img { width: min(100%, 516px); margin-inline: auto; }
  .brand-photos .jar-photo img { width: min(100%, 460px); margin-inline: auto; object-fit: contain; background: #f7f1e7; }

  .company-gallery { background: #0d3025; color: #fff; padding: 82px 0; overflow: hidden; }
  .company-gallery__head { max-width: 720px; margin-bottom: 34px; }
  .company-gallery__head h2 { margin-bottom: 14px; }
  .company-gallery__head p { margin: 0; color: rgba(255,255,255,.72); }
  .company-gallery__grid { display: grid; grid-template-columns: 1.1fr .9fr .9fr; gap: 16px; align-items: stretch; }
  .company-gallery__item { margin: 0; min-width: 0; border-radius: 20px; overflow: hidden; background: rgba(255,255,255,.06); }
  .company-gallery__item img { width: 100%; height: 100%; min-height: 300px; object-fit: cover; }
  .company-gallery__item--range img { object-fit: contain; background: #e9dfcf; }

  @media (max-width: 900px) {
    .company-gallery__grid { grid-template-columns: 1fr 1fr; }
    .company-gallery__item:first-child { grid-column: 1 / -1; }
  }

  @media (max-width: 640px) {
    .brand-photos .people-photo img,
    .brand-photos .jar-photo img { width: 100%; }
    .company-gallery { padding: 64px 0; }
    .company-gallery__grid { grid-template-columns: 1fr; }
    .company-gallery__item:first-child { grid-column: auto; }
    .company-gallery__item img { min-height: 0; height: auto; object-fit: contain; }
  }
`;
document.head.appendChild(photoStyle);

// Le cinque fotografie fornite fanno parte della presentazione del marchio.
// Le due principali sono già nella sezione Mieli Pura; le altre tre completano
// il racconto con azienda, api e assortimento.
const realCompanySection = document.querySelector('.real-company');
if (realCompanySection && !document.querySelector('.company-gallery')) {
  const gallery = document.createElement('section');
  gallery.className = 'company-gallery';
  gallery.setAttribute('aria-label', 'Bioapicoltura Pura in azienda e agli eventi');
  gallery.innerHTML = `
    <div class="container">
      <div class="company-gallery__head reveal">
        <div class="eyebrow light">Bioapicoltura Pura</div>
        <h2>Persone, api, miele.</h2>
        <p>Il lavoro quotidiano e la presenza sul territorio fanno parte della stessa storia dei mieli.</p>
      </div>
      <div class="company-gallery__grid">
        <figure class="company-gallery__item reveal">
          <img src="assets/photos/stand-bioapicoltura-pura.jpg" alt="Stand di Bioapicoltura Pura con i mieli esposti" loading="lazy">
        </figure>
        <figure class="company-gallery__item reveal">
          <img src="assets/photos/arnia-didattica-api.jpg" alt="Arnia didattica con api allo stand di Bioapicoltura Pura" loading="lazy">
        </figure>
        <figure class="company-gallery__item company-gallery__item--range reveal">
          <img src="assets/photos/gamma-mieli-pura.jpg" alt="Vasetti Mieli Pura esposti insieme" loading="lazy">
        </figure>
      </div>
    </div>
  `;
  realCompanySection.insertAdjacentElement('afterend', gallery);
}

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
