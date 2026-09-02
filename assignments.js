let employees = [];

(async () => {
  await requireSession({ page: 'assignments', adminOnly: true });
  await load();
})();

async function load() {
  const status = $('status');
  status.innerHTML = '<div class="loading"><span class="pulse"></span>Loading employees…</div>';

  try {
    const { users } = await apiGet('api/users');
    // Admins already see every dataset; only employee access is worth curating
    // here, and a deactivated account cannot sign in to use it anyway.
    employees = users.filter(u => u.role === 'employee' && u.is_active);
    render();
  } catch (err) {
    showError(status, 'Could not load employees', esc(err.message));
  }
}

function render() {
  const status = $('status');

  if (!employees.length) {
    status.innerHTML =
      '<div class="empty"><h3>No employee accounts yet</h3>' +
      '<p>Create one on <a href="users.html">Users</a>, then come back here to choose what they can see.</p></div>';
    return;
  }

  status.innerHTML =
    '<div class="sechead"><h2>Employees</h2><span class="cbadge">' + employees.length + '</span></div>' +
    '<div class="tablecard"><div class="scroll"><table><thead><tr>' +
      '<th>Employee</th><th>Assigned datasets</th><th></th>' +
    '</tr></thead><tbody>' +
    employees.map(row).join('') +
    '</tbody></table></div></div>';

  $$('[data-assign]').forEach(b =>
    b.addEventListener('click', () => openAssignModal(Number(b.dataset.assign))));
}

function row(u) {
  const chips = u.assigned_datasets && u.assigned_datasets.length
    ? '<div class="chiplist">' +
        u.assigned_datasets.map(d => '<span class="dchip">' + esc(d.display_name) + '</span>').join('') +
      '</div>'
    : '<span class="blank">Not assigned to any dataset yet</span>';

  return '<tr>' +
    '<td class="name">' + esc(u.full_name) +
      '<div class="muted" style="font-weight:400;font-size:12px;margin-top:2px">' + esc(u.email) + '</div>' +
    '</td>' +
    '<td>' + chips + '</td>' +
    '<td style="white-space:nowrap"><button class="btn btn-sm" data-assign="' + u.id + '">Edit assignments</button></td>' +
  '</tr>';
}

/* ── assign datasets ─────────────────────────────────────────────────── */

let assignUserId = null;

async function openAssignModal(userId) {
  assignUserId = userId;
  const u = employees.find(x => x.id === userId);
  $('assignName').textContent = u ? u.full_name : '';
  $('assignList').innerHTML = '<p class="muted" style="margin:6px">Loading datasets…</p>';
  openModal('assignModal');

  try {
    const data = await apiGet('api/users/' + userId + '/assignments');
    const datasets = data.datasets || [];
    $('assignList').innerHTML = datasets.length
      ? datasets.map(d =>
          '<label class="assignment-person">' +
            '<input type="checkbox" data-assignds="' + d.id + '"' +
              (d.assigned ? ' checked' : '') + '>' +
            '<span><strong>' + esc(d.display_name) + (d.is_protected ? ' <span class="tag locked">protected</span>' : '') + '</strong>' +
            '<small>' + esc(d.folder_name || 'Unfiled') +
              (d.status !== 'ready' ? ' · ' + esc(d.status) : '') + '</small></span>' +
          '</label>'
        ).join('')
      : '<p class="muted" style="margin:6px">No datasets exist yet.</p>';
  } catch (err) {
    $('assignList').innerHTML = '<p class="muted" style="margin:6px">Could not load datasets.</p>';
  }
}

$('assignSave').addEventListener('click', async () => {
  if (!assignUserId) return;
  const button = $('assignSave');
  const datasetIds = $$('[data-assignds]:checked').map(input => Number(input.dataset.assignds));

  try {
    button.disabled = true;
    button.textContent = 'Saving…';
    await apiPatch('api/users/' + assignUserId + '/assignments', { dataset_ids: datasetIds });
    toast('Dataset assignments saved.');
    closeModal('assignModal');
    await load();
  } catch (err) {
    toast(err.message, true);
  } finally {
    button.disabled = false;
    button.textContent = 'Save assignments';
  }
});
