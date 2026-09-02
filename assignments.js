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

/** How many employees share each dataset, and who — so a dataset given to
 *  more than one person (two people potentially working the same leads) can
 *  be called out, and the chip can say who else has it. Grouped by display
 *  name rather than dataset id: the same file re-uploaded twice creates two
 *  separate dataset rows, but to a reader they're still "the same list"
 *  showing up under two people, which is exactly the duplication this warns
 *  about. The master leads table is deliberately exempt: sharing it with the
 *  whole team is normal, not a duplication risk, so flagging it would just
 *  be noise on every row. */
function sharedAssignments() {
  const holders = new Map();
  employees.forEach(u => (u.assigned_datasets || []).forEach(d => {
    if (d.is_protected) return;
    const key = d.display_name;
    if (!holders.has(key)) holders.set(key, []);
    // Keyed by id, not just name — two employees can share a display name
    // (e.g. two "Moutasim Qazi" accounts), and a name-only comparison would
    // wrongly treat them as the same person and hide a real duplicate.
    holders.get(key).push({ id: u.id, name: u.full_name });
  }));
  return holders;
}

function render() {
  const status = $('status');

  if (!employees.length) {
    status.innerHTML =
      '<div class="empty"><h3>No employee accounts yet</h3>' +
      '<p>Create one on <a href="users.html">Users</a>, then come back here to choose what they can see.</p></div>';
    return;
  }

  const holders = sharedAssignments();

  status.innerHTML =
    '<div class="sechead"><h2>Employees</h2><span class="cbadge">' + employees.length + '</span></div>' +
    (Array.from(holders.values()).some(entries => entries.length > 1)
      ? '<p class="muted" style="margin:0 0 12px;font-size:12.5px">' +
          '<span class="dchip shared" style="margin-right:6px">example</span>' +
          'A dataset assigned to more than one person — check nobody is duplicating outreach.</p>'
      : '') +
    '<div class="tablecard"><div class="scroll"><table><thead><tr>' +
      '<th>Employee</th><th>Assigned datasets</th><th></th>' +
    '</tr></thead><tbody>' +
    employees.map(u => row(u, holders)).join('') +
    '</tbody></table></div></div>';

  $$('[data-assign]').forEach(b =>
    b.addEventListener('click', () => openAssignModal(Number(b.dataset.assign))));
}

function row(u, holders) {
  const chips = u.assigned_datasets && u.assigned_datasets.length
    ? '<div class="chiplist">' +
        u.assigned_datasets.map(d => {
          const others = (holders.get(d.display_name) || []).filter(entry => entry.id !== u.id);
          return others.length
            ? '<span class="dchip shared" title="Also assigned to ' + esc(others.map(entry => entry.name).join(', ')) + '">' + esc(d.display_name) + '</span>'
            : '<span class="dchip">' + esc(d.display_name) + '</span>';
        }).join('') +
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
