(function () {
  var cfg = window.HP || {};

  function post(path) {
    var h = { 'Content-Type': 'application/json' };
    if (cfg.nonce) h['X-WP-Nonce'] = cfg.nonce;
    return fetch(cfg.api + path, { method: 'POST', headers: h, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return {}; }); });
  }
  function el(tag, attrs, html) {
    var e = document.createElement(tag);
    for (var k in attrs || {}) e.setAttribute(k, attrs[k]);
    if (html != null) e.innerHTML = html;
    return e;
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  /* ---------- Ask form ---------- */
  var form = document.querySelector('[data-hp-ask]');
  if (form) {
    var note = form.querySelector('[data-hp-urgent-note]');
    var label = form.querySelector('[data-hp-text-label]');
    var optional = form.querySelector('[data-hp-optional]');
    var contact = form.querySelector('#hp-contact');
    var contactMe = form.querySelector('[data-hp-contact-me]');
    var radios = form.querySelectorAll('.hp-radio');

    var helpBox = form.querySelector('[data-hp-help]');
    function sync() {
      var kind = (form.querySelector('input[name=kind]:checked') || {}).value;
      var urgent = kind === 'URGENT';
      note.hidden = !urgent;
      if (helpBox) helpBox.hidden = kind !== 'HELP';
      label.textContent = kind === 'PRAYER' ? '2. What can we pray for?' : '2. How can we help?';
      if (urgent) form.querySelector('input[name=visibility][value=PRIVATE]').checked = true;
      radios.forEach(function (r) {
        var input = r.querySelector('input');
        input.disabled = urgent && input.value !== 'PRIVATE';
        r.classList.toggle('hp-off', input.disabled);
        r.classList.toggle('hp-on', input.checked);
      });
      if (kind !== 'PRAYER') { contactMe.checked = true; contactMe.disabled = true; }
      else contactMe.disabled = false;
      var needs = kind !== 'PRAYER' || contactMe.checked;
      contact.required = needs;
      optional.hidden = needs;
    }
    form.addEventListener('change', sync);
    form.addEventListener('submit', function () {
      contactMe.disabled = false; // disabled boxes aren't sent
      form.querySelectorAll('input[name=visibility]').forEach(function (i) { i.disabled = false; });
      var b = form.querySelector('button[type=submit]');
      b.disabled = true; b.textContent = 'Sending…';
    });
    sync();
  }

  /* ---------- Pray for others ---------- */
  var deck = document.querySelector('[data-hp-deck]');
  if (deck) {
    var KEY = 'hp_prayed', WEEK = 7 * 864e5;
    var pos = document.querySelector('[data-hp-pos]');
    var bar = document.querySelector('[data-hp-progress]');
    var queue = [], i = 0, all = 0;

    function prayed() {
      try {
        var m = JSON.parse(localStorage.getItem(KEY) || '{}'), now = Date.now(), out = {};
        for (var k in m) if (now - m[k] < WEEK) out[k] = m[k];
        return out;
      } catch (e) { return {}; }
    }
    function mark(id) {
      try { var m = prayed(); m[id] = Date.now(); localStorage.setItem(KEY, JSON.stringify(m)); } catch (e) {}
    }
    function countText(n) {
      return n === 0 ? 'Be the first to pray' : n + (n === 1 ? ' person from Hillside has prayed' : ' people from Hillside have prayed');
    }

    function render() {
      var cur = queue[i];
      if (!cur) {
        pos.textContent = ''; bar.style.width = '100%';
        deck.innerHTML =
          '<div class="hp-pad hp-stack hp-center" style="padding-top:48px"><h2 class="hp-big">Thank you for praying.</h2>' +
          '<p class="hp-lead">' + (all === 0 ? 'There are no requests to pray for right now.' : "You've prayed for every request for now. Check back soon.") + '</p></div>' +
          '<div class="hp-pad hp-stack"><a class="hp-btn hp-primary" href="' + esc(cfg.ask) + '">Ask for prayer</a>' +
          '<a class="hp-btn hp-secondary" href="' + esc(cfg.home) + '">Done</a></div>';
        return;
      }
      pos.textContent = (i + 1) + ' of ' + queue.length;
      bar.style.width = Math.round((i + 1) / queue.length * 100) + '%';
      var who = cur.name === 'Someone from Hillside' ? 'they need' : esc(cur.name) + ' needs';
      deck.innerHTML =
        '<article class="hp-card" aria-live="polite"><div class="hp-who"><span>' + esc(cur.name) + '</span><small>' + esc(cur.ago) + '</small></div>' +
        '<p>' + esc(cur.text) + '</p><div class="hp-note" data-c>' + countText(cur.count) + '</div></article>' +
        '<div class="hp-prompt"><b>Not sure what to say?</b><br>&ldquo;Lord, you know what ' + who + '. Bring your help, your peace, and your presence. Amen.&rdquo;</div>' +
        '<div class="hp-actions"><div data-slot><button type="button" class="hp-btn hp-primary" data-pray>I prayed</button></div>' +
        '<div class="hp-row"><button type="button" class="hp-link" data-next>Next request</button>' +
        '<button type="button" class="hp-link hp-quiet" data-report>Report</button></div></div>';

      var slot = deck.querySelector('[data-slot]');
      deck.querySelector('[data-next]').onclick = function () { i++; render(); };
      deck.querySelector('[data-pray]').onclick = function () {
        this.disabled = true;
        post('pray/' + cur.id).then(function (r) {
          mark(cur.id);
          if (r && r.count) deck.querySelector('[data-c]').textContent = countText(r.count);
          slot.innerHTML = '<div class="hp-alert hp-blue" role="status">Amen. Thank you for praying.</div>';
          setTimeout(function () { i++; render(); }, 1400);
        });
      };
      deck.querySelector('[data-report]').onclick = function () {
        this.disabled = true;
        post('report/' + cur.id).then(function () {
          slot.innerHTML = '<div class="hp-alert hp-blue" role="status">Thanks. We\'ve hidden it and Care will take a look.</div>';
          setTimeout(function () { i++; render(); }, 1400);
        });
      };
    }

    fetch(cfg.api + 'public', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (list) {
        var done = prayed();
        all = list.length;
        queue = list.filter(function (x) { return !done[x.id]; });
        render();
      })
      .catch(function () { deck.innerHTML = '<p class="hp-pad hp-note">Something went wrong. Please refresh the page.</p>'; });
  }

  /* ---------- Prayer team list ---------- */
  document.querySelectorAll('[data-hp-team-pray]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      post('pray/' + b.getAttribute('data-hp-team-pray')).then(function (r) {
        if (r && r.count) b.parentNode.querySelector('[data-hp-count]').textContent = r.count + ' prayed';
        b.textContent = 'Prayed';
      });
    });
  });
})();
