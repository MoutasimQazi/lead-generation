/* Login page behavior. Kept in an external file so the site's CSP can block inline scripts. */
function safeNext() {
  const raw = new URLSearchParams(location.search).get('next') || '';

  let target;
  try {
    target = decodeURIComponent(raw);
  } catch (e) {
    return 'index.html';
  }

  target = target.replace(/^\/+/, '');

  const usable =
    target !== '' &&
    /^[A-Za-z0-9._\-/?=&%]+$/.test(target) &&
    !target.includes(':') &&
    !target.startsWith('//') &&
    !target.split('?')[0].endsWith('login.html');

  return usable ? target : 'index.html';
}

(async () => {
  try {
    const me = await apiGet('api/auth/me', { noRedirect: true, timeoutMs: 15000 });
    if (me.authenticated) location.replace(safeNext());
  } catch (e) {
    /* Stay on the form so the sign-in request can show its error. */
  }
})();

$('form').addEventListener('submit', async (e) => {
  e.preventDefault();

  const btn = $('submit');
  const status = $('status');

  btn.disabled = true;
  btn.textContent = 'Signing in...';
  status.innerHTML = '';

  try {
    await apiPost('api/auth/login', {
      email: $('email').value.trim(),
      password: $('password').value,
    }, { noRedirect: true, timeoutMs: 15000 });

    location.href = safeNext();
  } catch (err) {
    if (err.status === 401) {
      status.innerHTML =
        '<div class="err">' +
          '<h3>Sign-in failed</h3>' +
          '<p>The email or password is incorrect. Please check both and try again.</p>' +
          '<p class="login-help">Need access? Contact the site manager at ' +
            '<a href="mailto:moutasim.qazi@moveneticsdigital.com">' +
              'moutasim.qazi@moveneticsdigital.com</a>.</p>' +
        '</div>';
    } else {
      showError(status, 'Sign-in failed', esc(err.message));
    }
    btn.disabled = false;
    btn.textContent = 'Sign in';
    $('password').value = '';
    $('password').focus();
  }
});
