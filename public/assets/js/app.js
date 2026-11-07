// FixMate: shared behaviours (vanilla JS)
(() => {
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const ready = fn => (document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn));

  // Mobile navigation
  function navToggle() {
    const toggle = document.querySelector('.nav-toggle');
    const links = document.querySelector('.nav-links');
    if (!toggle || !links) return;
    toggle.addEventListener('click', () => {
      const open = links.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Toast messages: close button, success/info fade out on their own
  function toasts() {
    $$('.toasts .alert').forEach(el => {
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'alert-close no-loading';
      close.setAttribute('aria-label', 'Dismiss message');
      close.textContent = '\u00d7';
      close.addEventListener('click', () => el.remove());
      el.appendChild(close);

      if (el.classList.contains('alert-success') || el.classList.contains('alert-info')) {
        setTimeout(() => {
          el.style.transition = 'opacity .4s, transform .4s';
          el.style.opacity = '0';
          el.style.transform = 'translateX(24px)';
          setTimeout(() => el.remove(), 400);
        }, 6000);
      }
    });
  }

  // Confirmation dialogs: <form data-confirm="..."> and <a data-confirm="...">
  function confirms() {
    $$('form[data-confirm]').forEach(form => {
      form.addEventListener('submit', ev => { if (!confirm(form.dataset.confirm)) ev.preventDefault(); });
    });
    $$('a[data-confirm]').forEach(a => {
      a.addEventListener('click', ev => { if (!confirm(a.dataset.confirm)) ev.preventDefault(); });
    });
  }

  // Loading state on submit buttons (prevents double submits)
  function loadingButtons() {
    $$('form').forEach(form => {
      form.addEventListener('submit', () => {
        if (form.dataset.confirm) return;
        requestAnimationFrame(() => {
          const btn = form.querySelector('button[type=submit], button:not([type])');
          if (btn && !btn.classList.contains('no-loading')) btn.classList.add('loading');
        });
      });
    });
  }

  // Show / hide password
  function passwordToggles() {
    $$('input[type=password]').forEach(input => {
      const wrap = document.createElement('div');
      wrap.className = 'pw-wrap';
      input.before(wrap);
      wrap.appendChild(input);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pw-toggle no-loading';
      btn.textContent = 'Show';
      btn.setAttribute('aria-label', 'Show password');
      btn.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? 'Hide' : 'Show';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      });
      wrap.appendChild(btn);
    });
  }

  // Character counters for textareas with a maxlength
  function charCounters() {
    $$('textarea[maxlength]').forEach(area => {
      const max = parseInt(area.getAttribute('maxlength'), 10);
      const out = document.createElement('div');
      out.className = 'char-count';
      area.after(out);
      const update = () => {
        out.textContent = area.value.length + ' / ' + max;
        out.classList.toggle('near', area.value.length > max * 0.9);
      };
      area.addEventListener('input', update);
      update();
    });
  }

  // Drag-and-drop image pickers with previews (the server still validates everything)
  function dropzones() {
    const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
    const MAX_BYTES = 2 * 1024 * 1024;
    const uploadIcon = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V5"/><path d="m7 9 5-5 5 5"/><path d="M5 19h14"/></svg>';

    $$('input[type=file]').forEach(input => {
      if (!(input.getAttribute('accept') || '').includes('image')) return;
      const multi = input.multiple;
      const limit = multi ? 5 : 1;
      let files = [];

      const zone = document.createElement('div');
      zone.className = 'dropzone' + (input.classList.contains('input-error') ? ' has-error' : '');
      zone.innerHTML =
        '<div class="dz-body"><span class="dz-icon" aria-hidden="true">' + uploadIcon + '</span>' +
        '<p><strong>' + (multi ? 'Drop images here' : 'Drop an image here') + '</strong><br>' +
        '<span>or click to browse. JPG, PNG or WebP, 2 MB each' + (multi ? ', up to 5' : '') + '.</span></p></div>' +
        '<ul class="dz-previews"></ul><p class="dz-error" role="alert"></p>';
      input.before(zone);
      zone.prepend(input);

      const list = zone.querySelector('.dz-previews');
      const err = zone.querySelector('.dz-error');

      const sync = () => {
        const dt = new DataTransfer();
        files.forEach(f => dt.items.add(f));
        input.files = dt.files;
      };
      const render = () => {
        list.querySelectorAll('img').forEach(i => URL.revokeObjectURL(i.src));
        list.innerHTML = '';
        files.forEach((file, index) => {
          const li = document.createElement('li');
          li.className = 'dz-thumb';
          const img = document.createElement('img');
          img.src = URL.createObjectURL(file);
          img.alt = file.name;
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'dz-remove no-loading';
          remove.setAttribute('aria-label', 'Remove ' + file.name);
          remove.textContent = '\u00d7';
          remove.addEventListener('click', () => { files.splice(index, 1); sync(); render(); err.textContent = ''; });
          li.append(img, remove);
          list.appendChild(li);
        });
      };

      input.addEventListener('change', () => {
        const problems = [];
        Array.from(input.files).forEach(file => {
          if (!ALLOWED.includes(file.type)) { problems.push(file.name + ' is not a JPG, PNG or WebP image.'); return; }
          if (file.size > MAX_BYTES) { problems.push(file.name + ' is larger than 2 MB.'); return; }
          if (!multi) { files = [file]; return; }
          if (files.some(f => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified)) return;
          if (files.length >= limit) {
            if (!problems.some(p => p.startsWith('You can'))) problems.push('You can add up to ' + limit + ' images.');
            return;
          }
          files.push(file);
        });
        sync();
        render();
        err.textContent = problems.join(' ');
      });

      ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, () => zone.classList.add('is-over')));
      ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, () => zone.classList.remove('is-over')));
    });
  }

  // Rating label next to the star input
  function starLabels() {
    const names = ['Poor', 'Fair', 'Good', 'Very good', 'Excellent'];
    $$('.star-input').forEach(wrap => {
      const out = document.createElement('span');
      out.className = 'star-label';
      wrap.after(out);
      const update = () => {
        const checked = wrap.querySelector('input:checked');
        out.textContent = checked ? names[parseInt(checked.value, 10) - 1] : 'Tap a star';
      };
      wrap.addEventListener('change', update);
      update();
    });
  }

  // Landing page: pick a category on the job ticket
  function heroTicket() {
    const ticket = document.getElementById('heroTicket');
    if (!ticket) return;
    let jobs = {};
    try { jobs = JSON.parse(ticket.dataset.jobs || '{}'); } catch (e) { jobs = {}; }
    const base = ticket.dataset.base || '';
    const cta = ticket.querySelector('[data-cta]');
    const selected = ticket.querySelector('[data-selected]');
    const jobsLine = ticket.querySelector('[data-jobs-line]');
    const picks = $$('.pick', ticket);

    picks.forEach(pick => {
      pick.addEventListener('click', () => {
        picks.forEach(p => p.setAttribute('aria-checked', p === pick ? 'true' : 'false'));
        selected.textContent = pick.dataset.name;
        const names = jobs[pick.dataset.id] || [];
        jobsLine.textContent = names.length
          ? 'Typical jobs: ' + names.join(', ') + '.'
          : 'You will describe the exact job on the next screen.';
        jobsLine.classList.remove('swap');
        void jobsLine.offsetWidth;
        jobsLine.classList.add('swap');
        if (cta && base) cta.href = base + '?category=' + encodeURIComponent(pick.dataset.id);
      });
    });
  }

  // Services page: live filter
  function serviceFilter() {
    const input = document.getElementById('svcSearch');
    if (!input) return;
    const rows = $$('.dir-row');
    const empty = document.getElementById('svcEmpty');
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach(row => {
        const catMatch = row.dataset.cat.includes(q);
        let anyChip = false;
        $$('.chip', row).forEach(chip => {
          const match = !q || catMatch || chip.textContent.toLowerCase().includes(q);
          chip.hidden = !match;
          if (match) anyChip = true;
        });
        const show = !q || catMatch || anyChip;
        row.hidden = !show;
        if (show) shown++;
      });
      if (empty) empty.hidden = shown > 0;
    });
  }

  ready(() => {
    navToggle(); toasts(); confirms(); loadingButtons(); passwordToggles();
    charCounters(); dropzones(); starLabels(); heroTicket(); serviceFilter();
  });
})();
