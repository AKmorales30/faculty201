/**
 * 201 File Assistant chat panel (includes/chatbot_widget.php).
 * Talks to api/chatbot.php with fetch (POST + CSRF token). The user's own
 * text is shown with textContent; the assistant's answers arrive as HTML
 * the server already escaped (ai_format_html()).
 */
(function () {
  var root = document.getElementById('chatbot');
  if (!root) return;
  var panel = root.querySelector('.chatbot-panel');
  var toggle = root.querySelector('.chatbot-toggle');
  var list = root.querySelector('.chatbot-messages');
  var typing = root.querySelector('.chatbot-typing');
  var form = root.querySelector('.chatbot-form');
  var input = form.querySelector('textarea');
  var sendBtn = form.querySelector('button');
  var loaded = false, busy = false;

  function post(data) {
    var body = new FormData();
    body.append('csrf_token', root.dataset.csrf);
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch(root.dataset.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Something went wrong. Please try again.' }; }); })
      .catch(function () { return { ok: false, error: 'Could not reach the server. Please check your connection.' }; });
  }

  function add(role, html, isText) {
    var div = document.createElement('div');
    div.className = 'chatbot-msg chatbot-msg-' + role;
    if (isText) { div.textContent = html; } else { div.innerHTML = html; }
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
    return div;
  }

  function showWelcome() {
    list.innerHTML = '';
    add('assistant', 'Hi, ' + root.dataset.firstName + "! I can answer questions about your 201 file and how to use this system. What would you like to know?", true);
    var chips = document.createElement('div');
    chips.className = 'chatbot-suggestions';
    JSON.parse(root.dataset.suggestions || '[]').forEach(function (s) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn btn-sm btn-outline-brand';
      b.textContent = s;
      b.addEventListener('click', function () { send(s); });
      chips.appendChild(b);
    });
    list.appendChild(chips);
  }

  function loadHistory() {
    loaded = true;
    post({ action: 'history' }).then(function (res) {
      if (!res.ok || !res.messages || !res.messages.length) { showWelcome(); return; }
      list.innerHTML = '';
      res.messages.forEach(function (m) { add(m.role, m.html); });
    });
  }

  function setBusy(on) {
    busy = on;
    typing.hidden = !on;
    sendBtn.disabled = on;
    if (on) list.scrollTop = list.scrollHeight;
  }

  function send(text) {
    text = (text || '').trim();
    if (!text || busy) return;
    var chips = list.querySelector('.chatbot-suggestions');
    if (chips) chips.remove();
    add('user', text, true);
    input.value = '';
    autosize();
    setBusy(true);
    post({ action: 'send', message: text }).then(function (res) {
      setBusy(false);
      if (res.ok) { add('assistant', res.html); }
      else { add('error', res.error || 'Something went wrong. Please try again.', true); }
      input.focus();
    });
  }

  function open(show) {
    panel.hidden = !show;
    toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
    root.classList.toggle('chatbot-open', show);
    if (show) {
      if (!loaded) loadHistory();
      input.focus();
    }
  }

  function autosize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }

  toggle.addEventListener('click', function () { open(panel.hidden); });
  root.querySelector('.chatbot-close').addEventListener('click', function () { open(false); toggle.focus(); });
  root.querySelector('.chatbot-clear').addEventListener('click', function () {
    if (busy || !confirm('Clear your chat history? This cannot be undone.')) return;
    post({ action: 'clear' }).then(function (res) {
      if (res.ok) { showWelcome(); } else { add('error', res.error || 'Could not clear the chat.', true); }
    });
  });
  form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
  input.addEventListener('keydown', function (e) {   // Enter sends, Shift+Enter adds a line
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(input.value); }
  });
  input.addEventListener('input', autosize);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) { open(false); toggle.focus(); }
  });
})();
