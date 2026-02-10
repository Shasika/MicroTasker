(function () {
  const openers = document.querySelectorAll('[data-open]');
  const closers = document.querySelectorAll('[data-close]');

  function openDrawer(id) {
    const el = document.getElementById(id);
    if (el) {
      el.classList.add('open');
    }
  }

  function closeDrawer(id) {
    const el = document.getElementById(id);
    if (el) {
      el.classList.remove('open');
    }
  }

  openers.forEach((btn) => {
    btn.addEventListener('click', () => openDrawer(btn.dataset.open));
  });

  closers.forEach((btn) => {
    btn.addEventListener('click', () => closeDrawer(btn.dataset.close));
  });
})();
