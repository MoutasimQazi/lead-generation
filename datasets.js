let datasets = [], folders = [];

// Dataset ids the user has checked, for the bulk "move to folder" action.
// Kept independent of the current filter, so selecting some cards, then
// narrowing the search, doesn't silently drop them from the selection.
let selected = new Set();

(async () => {
  const user = await requireSession({ page: 'datasets' });

  if (user.is_admin) {
    $('newFolder').hidden = false;
    $('uploadLink').hidden = false;
    $('selectAllWrap').hidden = false;
  }

  await load();
})();

async function load() {
  const status = $('status');
  status.innerHTML = '<div class="loading"><span class="pulse"></span>Loading datasets...</div>';

  try {
    const [d, f] = await Promise.all([apiGet('api/datasets'), apiGet('api/folders')]);
    datasets = d.datasets;
    folders = f.folders;

    const ids = new Set(datasets.map(d2 => d2.id));
    selected.forEach(id => { if (!ids.has(id)) selected.delete(id); });

    render();
  } catch (err) {
    showError(status, 'Could not load datasets', esc(err.message));
  }
}

/** A dataset qualifies for bulk move when it's not the protected master
 *  table — moving that one is blocked server-side regardless. */
function movable(d) {
  return !d.is_protected;
}

function visibleDatasets() {
  const term = $('filter').value.trim().toLowerCase();
  return term
    ? datasets.filter(d => d.display_name.toLowerCase().includes(term)
                        || (d.folder_name || '').toLowerCase().includes(term))
    : datasets;
}

function render() {
  const status = $('status');
  const term = $('filter').value.trim();
  const visible = visibleDatasets();

  renderBulkBar();

  if (!datasets.length) {
    status.innerHTML =
      '<div class="empty"><h3>No datasets yet</h3><p>' +
      (session.user.is_admin
        ? 'Upload a CSV or spreadsheet to create your first table.'
        : 'An administrator needs to upload data before it appears here.') +
      '</p></div>';
    updateSelectAll([]);
    return;
  }

  if (!visible.length) {
    status.innerHTML = '<div class="empty"><h3>Nothing matches "' + esc(term) + '"</h3>' +
                       '<p>Try a shorter search.</p></div>';
    updateSelectAll([]);
    return;
  }

  // Folders sort alphabetically (not DB insertion order) so the section list
  // is stable and predictable; Unfiled always trails as the catch-all.
  const sortedFolders = [...folders].sort((a, b) =>
    a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));

  const groups = new Map();
  sortedFolders.forEach(f => groups.set(f.id, { name: f.name, id: f.id, items: [] }));
  groups.set(null, { name: 'Unfiled', id: null, items: [] });

  visible.forEach(d => {
    const key = groups.has(d.folder_id) ? d.folder_id : null;
    groups.get(key).items.push(d);
  });

  // Datasets within each folder sort alphabetically too, same rule.
  groups.forEach(g => g.items.sort((a, b) =>
    a.display_name.localeCompare(b.display_name, undefined, { sensitivity: 'base' })));

  let html = '';

  for (const g of groups.values()) {
    if (!g.items.length) continue;

    const rows = g.items.reduce((n, d) => n + d.row_count, 0);

    html +=
      '<div class="sechead" style="margin-top:22px">' +
        '<h2>' + esc(g.name) + '</h2>' +
        '<span class="cbadge">' + g.items.length + '</span>' +
        '<span class="timing">' + fmt(rows) + ' rows</span>' +
        '<span class="spacer"></span>' +
        (g.id !== null && session.user.is_admin
          ? '<button class="linkbtn" data-delfolder="' + g.id + '">Delete folder</button>' : '') +
      '</div>' +
      g.items.map(card).join('');
  }

  status.innerHTML = html;

  $$('[data-delfolder]').forEach(b =>
    b.addEventListener('click', () => deleteFolder(Number(b.dataset.delfolder))));

  $$('.dscard .switch').forEach(label =>
    label.addEventListener('click', event => event.stopPropagation()));

  $$('[data-searchable]').forEach(el =>
    el.addEventListener('change', () => toggleSearchable(Number(el.dataset.searchable), el.checked)));

  $$('[data-user-search]').forEach(el => {
    el.addEventListener('click', event => event.stopPropagation());
    el.addEventListener('change', () =>
      togglePersonalSearch(Number(el.dataset.userSearch), el.checked));
  });

  $$('.dscard [data-select]').forEach(cb => {
    cb.addEventListener('click', event => event.stopPropagation());
    cb.addEventListener('change', () => toggleSelect(Number(cb.dataset.select), cb.checked));
  });

  updateSelectAll(visible.filter(movable));
}

function card(d) {
  const meta = [
    fmt(d.row_count) + ' rows',
    d.column_count + ' columns',
    '<span class="mono">' + esc(d.table_name) + '</span>',
  ].join(' · ');

  const toggle = session.user.is_admin
    ? '<label class="switch" title="Let the AI search query this table">' +
        '<input type="checkbox" data-searchable="' + d.id + '"' + (d.is_searchable ? ' checked' : '') +
        (d.status !== 'ready' ? ' disabled' : '') + '>' +
        '<span class="track"></span>Searchable</label>'
    : '<label class="switch" title="Include this assigned dataset in your AI searches">' +
        '<input type="checkbox" data-user-search="' + d.id + '"' +
          (d.user_search_enabled ? ' checked' : '') +
          (!d.is_searchable || d.status !== 'ready' ? ' disabled' : '') + '>' +
        '<span class="track"></span>' +
        (d.is_searchable ? 'Use in my search' : 'Admin disabled') + '</label>';

  const assigned = session.user.is_admin
    ? '<div class="meta-line assigned">' +
        (d.assigned_names && d.assigned_names.length
          ? 'Assigned: ' + d.assigned_names.map(esc).join(', ')
          : '<span class="blank">Unassigned</span>') +
      '</div>'
    : '';

  const check = session.user.is_admin && movable(d)
    ? '<label class="check" title="Select for bulk move" aria-label="Select ' + esc(d.display_name) + '">' +
        '<input type="checkbox" data-select="' + d.id + '"' + (selected.has(d.id) ? ' checked' : '') + '>' +
      '</label>'
    : '';

  return '<a class="dscard' + (d.is_protected ? ' locked' : '') + '" href="dataset.html?id=' + d.id + '">' +
    check +
    '<span>' +
      '<span class="title">' + esc(d.display_name) + '</span>' +
      '<div class="meta-line">' + meta + '</div>' +
      assigned +
    '</span>' +
    '<span class="spacer"></span>' +
    statusTag(d) +
    toggle +
  '</a>';
}

$('filter').addEventListener('input', render);

$('newFolder').addEventListener('click', () => {
  $('folderName').value = '';
  openModal('folderModal');
});

$('folderSave').addEventListener('click', async () => {
  const name = $('folderName').value.trim();
  if (!name) return toast('Give the folder a name.', true);

  try {
    await apiPost('api/folders', { name });
    closeModal('folderModal');
    toast('Folder created.');
    await load();
  } catch (err) {
    toast(err.message, true);
  }
});

$('folderName').addEventListener('keydown', e => {
  if (e.key === 'Enter') $('folderSave').click();
});

$('selectAll').addEventListener('change', () => {
  const ids = visibleDatasets().filter(movable).map(d => d.id);
  if ($('selectAll').checked) ids.forEach(id => selected.add(id));
  else ids.forEach(id => selected.delete(id));
  render();
});

function toggleSelect(id, on) {
  if (on) selected.add(id); else selected.delete(id);
  updateSelectAll(visibleDatasets().filter(movable));
  renderBulkBar();
}

/** Reflects the current selection on the toolbar "select all" checkbox,
 *  including the tri-state (indeterminate) case. */
function updateSelectAll(movableVisible) {
  const box = $('selectAll');
  if (!movableVisible.length) {
    box.checked = false;
    box.indeterminate = false;
    return;
  }

  const selectedCount = movableVisible.filter(d => selected.has(d.id)).length;
  box.checked = selectedCount === movableVisible.length;
  box.indeterminate = selectedCount > 0 && !box.checked;
}

function renderBulkBar() {
  const bar = $('bulkbar');

  if (!session.user.is_admin || selected.size === 0) {
    bar.hidden = true;
    bar.innerHTML = '';
    return;
  }

  bar.hidden = false;
  bar.innerHTML =
    '<strong>' + selected.size + ' selected</strong>' +
    '<span class="spacer"></span>' +
    '<select id="bulkFolder">' +
      '<option value="" disabled selected>Move to folder…</option>' +
      '<option value="0">No folder (Unfiled)</option>' +
      folders.map(f => '<option value="' + f.id + '">' + esc(f.name) + '</option>').join('') +
    '</select>' +
    '<button class="btn btn-primary" id="bulkMove">Move</button>' +
    '<button class="linkbtn" id="bulkClear">Clear selection</button>';

  $('bulkMove').addEventListener('click', () => {
    const v = $('bulkFolder').value;
    if (!v) return toast('Choose a folder first.', true);
    bulkMoveSelected(v === '0' ? null : Number(v));
  });

  $('bulkClear').addEventListener('click', () => {
    selected.clear();
    render();
  });
}

async function bulkMoveSelected(folderId) {
  const ids = [...selected];
  if (!ids.length) return;

  const btn = $('bulkMove');
  if (btn) btn.disabled = true;

  try {
    const results = await Promise.allSettled(
      ids.map(id => apiPatch('api/datasets/' + id, { folder_id: folderId }))
    );
    const failed = results.filter(r => r.status === 'rejected').length;
    const ok = results.length - failed;

    selected.clear();
    await load();

    if (failed) {
      toast('Moved ' + ok + ', but ' + failed + ' failed.', true);
    } else {
      toast('Moved ' + ok + ' dataset' + (ok === 1 ? '' : 's') + '.');
    }
  } catch (err) {
    toast(err.message, true);
  } finally {
    if (btn && btn.isConnected) btn.disabled = false;
  }
}

async function toggleSearchable(id, on) {
  try {
    await apiPatch('api/datasets/' + id, { is_searchable: on });
    const d = datasets.find(x => x.id === id);
    if (d) d.is_searchable = on;
    toast(on ? 'Now searchable by the AI.' : 'Removed from AI search.');
  } catch (err) {
    toast(err.message, true);
    await load();
  }
}

async function togglePersonalSearch(id, on) {
  try {
    await apiPatch('api/datasets/' + id + '/search-preference', { enabled: on });
    const dataset = datasets.find(item => item.id === id);
    if (dataset) dataset.user_search_enabled = on;
    toast(on ? 'Included in your AI searches.' : 'Excluded from your AI searches.');
  } catch (err) {
    toast(err.message, true);
    await load();
  }
}

async function deleteFolder(id) {
  const folder = folders.find(f => f.id === id);
  if (!folder) return;

  const inside = datasets.filter(d => d.folder_id === id).length;
  const warning = inside
    ? '\n\n' + inside + ' dataset' + (inside === 1 ? '' : 's') +
      ' will become unfiled. The data itself is not deleted.'
    : '';

  if (!confirm('Delete the folder "' + folder.name + '"?' + warning)) return;

  try {
    await apiDelete('api/folders/' + id, {});
    toast('Folder deleted.');
    await load();
  } catch (err) {
    toast(err.message, true);
  }
}
