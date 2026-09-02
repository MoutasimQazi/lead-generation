let users = [];

(async () => {
  await requireSession({ page: 'users', adminOnly: true });
  await load();
})();

async function load() {
  const status = $('status');
  status.innerHTML = '<div class="loading"><span class="pulse"></span>Loading people...</div>';

  try {
    ({ users } = await apiGet('api/users'));
    render();
  } catch (err) {
    showError(status, 'Could not load users', esc(err.message));
  }
}

function render() {
  const active = users.filter(u => u.is_active);
  const inactive = users.filter(u => !u.is_active);

  $('status').innerHTML =
    section('Active', active, true) +
    (inactive.length ? section('Deactivated', inactive, false) : '');

  $$('[data-role]').forEach(s =>
    s.addEventListener('change', () => changeRole(Number(s.dataset.role), s.value)));

  $$('[data-toggle]').forEach(b =>
    b.addEventListener('click', () => setActive(Number(b.dataset.toggle), b.dataset.to === '1')));

  $$('[data-reset]').forEach(b =>
    b.addEventListener('click', () => resetPassword(Number(b.dataset.reset))));
}

function section(title, list, isActive) {
  if (!list.length) return '';

  return '<div class="sechead" style="margin-top:22px"><h2>' + title + '</h2>' +
      '<span class="cbadge">' + list.length + '</span></div>' +
    '<div class="tablecard"><div class="scroll"><table><thead><tr>' +
      '<th>Name</th><th>Email</th><th>Role</th><th>Last signed in</th><th></th>' +
    '</tr></thead><tbody>' +
    list.map(u => row(u, isActive)).join('') +
    '</tbody></table></div></div>';
}

function row(u, isActive) {
  const me = u.id === session.user.id;

  const role = isActive
    ? '<select data-role="' + u.id + '"' + (me ? ' disabled title="You cannot change your own role"' : '') + '>' +
        '<option value="employee"' + (u.role === 'employee' ? ' selected' : '') + '>Employee</option>' +
        '<option value="admin"' + (u.role === 'admin' ? ' selected' : '') + '>Admin</option>' +
      '</select>'
    : '<span class="tag">' + esc(u.role) + '</span>';

  const actions = isActive
    ? '<button class="btn btn-sm" data-reset="' + u.id + '">Reset password</button> ' +
      (me ? '' : '<button class="btn btn-sm btn-danger" data-toggle="' + u.id + '" data-to="0">Deactivate</button>')
    : '<button class="btn btn-sm" data-toggle="' + u.id + '" data-to="1">Reactivate</button>';

  return '<tr>' +
    '<td class="name">' + esc(u.full_name) + (me ? ' <span class="tag">you</span>' : '') + '</td>' +
    '<td class="st">' + esc(u.email) + '</td>' +
    '<td>' + role + '</td>' +
    '<td class="st mono" style="font-size:12px">' +
      (u.last_login_at ? esc(u.last_login_at.slice(0, 16)) : '<span class="blank">never</span>') + '</td>' +
    '<td style="white-space:nowrap">' + actions + '</td>' +
  '</tr>';
}

function randomPassword() {
  const alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const bytes = new Uint32Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, b => alphabet[b % alphabet.length]).join('');
}

$('newUser').addEventListener('click', () => {
  $('uName').value = '';
  $('uEmail').value = '';
  $('uRole').value = 'employee';
  $('uPass').value = randomPassword();
  openModal('userModal');
});

$('uGen').addEventListener('click', () => { $('uPass').value = randomPassword(); });

$('uSave').addEventListener('click', async () => {
  const body = {
    full_name: $('uName').value.trim(),
    email: $('uEmail').value.trim(),
    role: $('uRole').value,
    password: $('uPass').value,
  };

  if (!body.full_name || !body.email) return toast('Name and email are both needed.', true);
  if (body.password.length < 12) return toast('The password must be at least 12 characters.', true);

  try {
    await apiPost('api/users', body);
    closeModal('userModal');
    toast('Account created for ' + body.email + '.');
    await load();
  } catch (err) {
    toast(err.message, true);
  }
});

async function changeRole(userId, role) {
  try {
    await apiPatch('api/users/' + userId, { role });
    toast('Role updated.');
    await load();
  } catch (err) {
    toast(err.message, true);
    await load();
  }
}

async function setActive(userId, active) {
  const u = users.find(x => x.id === userId);

  if (!active && !confirm('Deactivate ' + u.full_name + '?\n\nThey are signed out immediately and cannot sign back in.')) {
    return;
  }

  try {
    await apiPatch('api/users/' + userId, { is_active: active });
    toast(active ? 'Account reactivated.' : 'Account deactivated.');
    await load();
  } catch (err) {
    toast(err.message, true);
  }
}

async function resetPassword(userId) {
  const u = users.find(x => x.id === userId);
  const next = randomPassword();

  if (!confirm('Set a new password for ' + u.full_name + '?\n\n' + next +
               '\n\nCopy it now — it is not shown again.')) return;

  try {
    await apiPatch('api/users/' + userId, { password: next });
    toast('Password reset. Send it to ' + u.email + '.');
  } catch (err) {
    toast(err.message, true);
  }
}
