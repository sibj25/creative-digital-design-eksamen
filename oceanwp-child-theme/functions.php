<?php
// Kør funktionen, når WordPress indlæser CSS og JavaScript
add_action( 'wp_enqueue_scripts', 'my_theme_enqueue_styles' );
function my_theme_enqueue_styles() {
	$parenthandle = 'parent-style';
	$theme = wp_get_theme();

	// 1. Først mother themets style.css
	wp_enqueue_style( $parenthandle,
		get_template_directory_uri() . '/style.css',
		array(),
		$theme->parent()->get( 'Version' )
	);

	// 2. Derefter child themets style.css
	//    filemtime = versionsnummeret skifter automatisk, hver gang du gemmer filen,
	//    så browseren altid henter den nyeste udgave
	wp_enqueue_style( 'child-style',
		get_stylesheet_uri(),
		array( $parenthandle ),
		filemtime( get_stylesheet_directory() . '/style.css' )
	);

	// 3. Leaflet (kortbiblioteket)
	wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
	wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
}


// ---------- Boarding pass-slider ----------
add_action('wp_footer', function () { ?>
<script>
/* Mikkeller Airlines – boarding pass slider (karrusel) */
(function () {
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const cssTime = (el, name, fallback) => {
    const v = getComputedStyle(el).getPropertyValue(name).trim();
    if (!v) return fallback;
    return v.endsWith('ms') ? parseFloat(v) : parseFloat(v) * 1000;
  };

  function init(root) {
    if (root.dataset.mkReady) return;
    const slides = [...root.querySelectorAll('.mk-slide')];
    if (!slides.length) return;
    root.dataset.mkReady = '1';

    let index = slides.findIndex(s => s.classList.contains('is-active'));
    if (index < 0) index = 0;
    slides.forEach((s, n) => s.classList.toggle('is-active', n === index));

    let busy = false;

    // prikker
    const dots = document.createElement('div');
    dots.className = 'mk-dots';
    slides.forEach((s, n) => {
      const d = document.createElement('button');
      d.type = 'button';
      d.className = 'mk-dot';
      d.setAttribute('aria-label', 'Øl ' + (n + 1));
      d.addEventListener('click', () => goTo(n, n > index ? 1 : -1));
      dots.appendChild(d);
    });
    root.appendChild(dots);
    const markDots = () =>
      dots.querySelectorAll('.mk-dot').forEach((d, n) => d.classList.toggle('is-active', n === index));
    markDots();

    async function goTo(target, dir) {
      if (busy || target === index) return;
      busy = true;
      const cur = slides[index];
      const next = slides[target];
      const speed = cssTime(root, '--mk-slide-speed', 1000);

      // kortbilledet glider ind bag kortet, mens karrusellen kører
      root.classList.remove('is-open');

      // det gamle kort bliver liggende præcis hvor det er, mens det glider ud
      cur.style.top = cur.offsetTop + 'px';
      cur.style.left = cur.offsetLeft + 'px';
      cur.style.width = cur.offsetWidth + 'px';
      cur.classList.add('is-leaving', dir > 0 ? 'out-left' : 'out-right');

      // det nye kort glider ind fra den anden side
      index = target;
      next.classList.add('is-active', dir > 0 ? 'in-right' : 'in-left');
      markDots();

      await wait(speed + 50);

      cur.classList.remove('is-active', 'is-leaving', 'out-left', 'out-right');
      cur.style.top = cur.style.left = cur.style.width = '';
      next.classList.remove('in-right', 'in-left');

      // ... og glider frem igen, når den nye øl er på plads
      await wait(200);
      root.classList.add('is-open');
      await wait(cssTime(root, '--mk-speed', 1200));
      busy = false;
    }

    const go = dir => goTo((index + dir + slides.length) % slides.length, dir);

    const bind = (sel, dir) => {
      const el = root.querySelector(sel);
      if (el) el.addEventListener('click', e => { e.preventDefault(); go(dir); });
    };
    bind('.mk-next', 1);
    bind('.mk-prev', -1);

    // swipe på mobil
    let x0 = null;
    root.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
    root.addEventListener('touchend', e => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 50) go(dx < 0 ? 1 : -1);
      x0 = null;
    });

    // start: kortet står i midten, og panelet glider frem bagved når sektionen kommer i syne
    const open = () => setTimeout(() => root.classList.add('is-open'), 600);
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) { open(); io.disconnect(); }
      }, { threshold: 0.35 });
      io.observe(root);
    } else {
      open();
    }
  }

  const start = () => document.querySelectorAll('.mk-slider').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
</script>
<?php });


// ---------- Kort med flag ----------
add_action('wp_footer', function () { ?>
<script>
/* Mikkeller Airlines – kort med flag (Leaflet)
   Bygger hele indholdet ind i alle elementer med klassen .mkf */
(function () {

  /* =========================================================
     RET HER
     ========================================================= */
  const START_VIEW = [56.1555, 10.2065];   // kortets midte
  const START_ZOOM = 14;

  const FLAG_NORMAL  = 'https://mikkeller-project-airlines.simonhbjerborg.dk/wp-content/uploads/2026/11/Layer_2.png';
  const FLAG_CLICKED = 'https://mikkeller-project-airlines.simonhbjerborg.dk/wp-content/uploads/2026/11/selected-flag.png';

  const LOCATIONS = [
    { name: 'LATINERKVARTERET', coords: [56.15826, 10.20798], destination: 'BEIJING, CN', time: '13:00' },
    { name: 'STREETFOOD',       coords: [56.15143, 10.20945], destination: 'LONDON, UK',  time: '13:00' },
    { name: 'DOKK1',            coords: [56.15329, 10.21440], destination: 'TOKYO, JP',   time: '13:30', selected: true },
    { name: 'MØLLEPARKEN',      coords: [56.15670, 10.19970], destination: 'CPH, DK',     time: '13:00' },
    { name: 'MUSIKHUSET',       coords: [56.15366, 10.19752], destination: 'PARIS, FR',   time: '14:30' }
  ];

  /* ---------- herunder skal intet rettes ---------- */

  const TEMPLATE = `
    <div class="mkf-logo">
      <div class="mkf-logo-name">Mikkeller</div>
      <div class="mkf-logo-sub">AIRLINES</div>
    </div>
    <div class="mkf-details">
      <div class="mkf-item"><span class="mkf-label">DESTINATION</span><span class="mkf-value" data-mkf="destination"></span></div>
      <div class="mkf-item"><span class="mkf-label">TIME</span><span class="mkf-value" data-mkf="time"></span></div>
      <div class="mkf-item"><span class="mkf-label">ADDRESS</span><span class="mkf-value" data-mkf="address"></span></div>
    </div>
    <div class="mkf-map"></div>
  `;

  function createFlag(image) {
    return L.divIcon({
      className: 'mkf-flag',
      html: '<div class="mkf-flag-marker"><img src="' + image + '" alt="Mikkeller location"></div>',
      iconSize: [45, 55],
      iconAnchor: [10, 55]
    });
  }

  function build(box) {
    if (box.dataset.mkfReady) return;
    box.dataset.mkfReady = '1';
    box.innerHTML = TEMPLATE;

    const field = k => box.querySelector('[data-mkf="' + k + '"]');
    const map = L.map(box.querySelector('.mkf-map')).setView(START_VIEW, START_ZOOM);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    let selected = null;
    function select(marker) {
      if (selected === marker) return;
      if (selected) selected.setIcon(createFlag(FLAG_NORMAL));
      marker.setIcon(createFlag(FLAG_CLICKED));
      selected = marker;
      field('destination').textContent = marker.mkf.destination;
      field('time').textContent        = marker.mkf.time;
      field('address').textContent     = marker.mkf.name;
    }

    const markers = LOCATIONS.map(loc => {
      const m = L.marker(loc.coords, { icon: createFlag(FLAG_NORMAL) }).addTo(map);
      m.mkf = loc;
      m.on('click', () => select(m));
      return m;
    });
    const startMarker = markers.find(m => m.mkf.selected) || markers[0];
    if (startMarker) select(startMarker);

    setTimeout(() => map.invalidateSize(), 500);
    window.addEventListener('resize', () => map.invalidateSize());
  }

  function start() {
    const boxes = document.querySelectorAll('.mkf');
    if (!boxes.length) return;
    if (!window.L) { setTimeout(start, 300); return; }   // vent på Leaflet
    boxes.forEach(build);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
</script>
<?php }, 100);

// ---------- Merch-karrusel ----------
add_action('wp_footer', function () { ?>
<script>
/* Mikkeller Airlines – merch-karrusel
   Du giver kun dine slides klassen  mkm-slide  i Elementor.
   Scriptet pakker dem selv ind og laver pile og prikker. */
(function () {
	
  function build(slides) {
    const first = slides[0];

    // lav indpakningen og sæt den ind, hvor den første slide står
    const root = document.createElement('div');
    root.className = 'mkm';
    const viewport = document.createElement('div');
    viewport.className = 'mkm-viewport';
    const track = document.createElement('div');
    track.className = 'mkm-track';
    first.parentNode.insertBefore(root, first);
    root.appendChild(viewport);
    viewport.appendChild(track);
    slides.forEach(s => track.appendChild(s));

    // pile
    const makeArrow = (cls, label, sign) => {
      const wrap = document.createElement('div');
      wrap.className = cls;
      wrap.innerHTML = '<button type="button" aria-label="' + label + '">' + sign + '</button>';
      return wrap;
    };
    const prev = makeArrow('mkm-prev', 'Forrige', '&larr;');
    const next = makeArrow('mkm-next', 'Næste', '&rarr;');
    root.appendChild(prev);
    root.appendChild(next);

    // prikker
    const dots = document.createElement('div');
    dots.className = 'mkm-dots';
    slides.forEach((s, n) => {
      const d = document.createElement('button');
      d.type = 'button';
      d.className = 'mkm-dot';
      d.setAttribute('aria-label', 'Vis merch ' + (n + 1));
      d.addEventListener('click', () => goTo(n));
      dots.appendChild(d);
    });
    root.appendChild(dots);

    let index = 0;
    function goTo(n) {
      index = (n + slides.length) % slides.length;
      track.style.transform = 'translateX(' + (-index * 100) + '%)';
      dots.querySelectorAll('.mkm-dot').forEach((d, i) => d.classList.toggle('is-active', i === index));
      slides.forEach((s, i) => s.setAttribute('aria-hidden', i === index ? 'false' : 'true'));
    }

    prev.addEventListener('click', () => goTo(index - 1));
    next.addEventListener('click', () => goTo(index + 1));

    // swipe på mobil
    let x0 = null;
    root.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
    root.addEventListener('touchend', e => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 50) goTo(index + (dx < 0 ? 1 : -1));
      x0 = null;
    });

    goTo(0);
  }

  function start() {
    if (document.body.classList.contains('elementor-editor-active')) return; // ikke i editoren

    // find slides, der står lige efter hinanden, og lav én karrusel pr. gruppe
    const all = [...document.querySelectorAll('.mkm-slide')].filter(s => !s.closest('.mkm'));
    const groups = [];
    all.forEach(s => {
      const last = groups[groups.length - 1];
      const prevEl = s.previousElementSibling;
      if (last && prevEl === last[last.length - 1]) last.push(s);
      else groups.push([s]);
    });
    groups.forEach(build);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();

</script>
<?php });

// ---------- Burgermenu ----------
add_action('wp_footer', function () { ?>
<script>
/* Mikkeller Airlines – burgermenu på mobil */
(function () {
  function init(nav) {
    if (nav.dataset.mkNavReady) return;
    nav.dataset.mkNavReady = '1';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mk-burger';
    btn.setAttribute('aria-label', 'Åbn menu');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span></span><span></span><span></span>';
    nav.prepend(btn);

    const setOpen = open => {
      nav.classList.toggle('is-open', open);
      document.body.classList.toggle('mk-nav-lock', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.setAttribute('aria-label', open ? 'Luk menu' : 'Åbn menu');
    };

    btn.addEventListener('click', () => setOpen(!nav.classList.contains('is-open')));
    nav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });
    window.addEventListener('resize', () => { if (window.innerWidth > 1024) setOpen(false); });
  }

  const start = () => document.querySelectorAll('.mk-nav').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
</script>
<?php });