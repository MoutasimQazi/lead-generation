const id = Number(new URLSearchParams(location.search).get('id') || 0);
let ds = null;
let columns = [];
let page = 1;
let per = 50;
let rowCursors = [0];
let cursorIndex = 0;
let term = '';
let sort = '';
let dir = 'asc';
let filters = {};
let filterOptions = {};
let assignmentEmployees = [];

(async () => {
  await requireSession({ page: 'datasets' });

  if (!id) {
    showError($('status'), 'No dataset selected', 'Go back to <a href="datasets.html">Datasets</a>.');
    return;
  }

  await loadDataset();
})();

async function loadDataset() {
  try {
    const data = await apiGet('api/datasets/' + id);
    ds = data.dataset;
    columns = ds.columns || [];
    renderHead();
    if (session.user.is_admin) await loadAssignments();
    if (ds.status === 'ready') {
      await loadRows();
    }
  } catch (err) {
    showError($('status'), 'Could not open that dataset', esc(err.message));
  }
}

async function loadFilterOptions() {
  try {
    const data = await apiGet('api/datasets/' + id + '/filter-options');
    filterOptions = data.options || {};
  } catch (err) {
    filterOptions = {};
  }
}

function renderHead() {
  $('head').innerHTML =
    '<h1>' + esc(ds.display_name) + '</h1>' +
    '<p><a href="datasets.html">&larr; All datasets</a>' +
      (ds.folder_id ? ' · in a folder' : '') +
      ' · <span class="mono">' + esc(ds.table_name) + '</span></p>' +
    '<dl class="kv" style="margin-top:18px">' +
      '<div><dt>Rows</dt><dd>' + fmt(ds.row_count) + '</dd></div>' +
      '<div><dt>Columns</dt><dd>' + columns.length + '</dd></div>' +
      '<div><dt>Status</dt><dd style="font-size:13px;padding-top:4px">' + statusTag(ds) + '</dd></div>' +
      '<div><dt>AI search</dt><dd style="font-size:13px;padding-top:4px">' +
        (ds.is_searchable ? '<span class="tag ok">on</span>' : '<span class="tag">off</span>') +
      '</dd></div>' +
    '</dl>';

  $('tools').hidden = false;
  if (session.user.is_admin) {
    $('exportBtn').hidden = false;
    $('exportBtn').href = 'api/datasets/' + id + '/export';
  }

  $('settingsBtn').hidden = false;

  if (session.user.is_admin) {
    $('assignmentPanel').hidden = false;
    $('settingsSub').textContent = ds.is_protected
      ? 'This dataset is protected and cannot be deleted.'
      : 'Deleting removes this dataset and all of its rows permanently.';
    $('delConfirm').disabled = ds.is_protected;
    $('delBtn').disabled = ds.is_protected;
  }

  if (ds.is_protected) {
    $('banner').innerHTML =
      '<div class="notice"><strong>This is the master leads table.</strong> ' +
      (session.user.is_admin
        ? 'It can be searched and exported, but not edited or deleted here.'
        : 'It can be viewed and searched, but not edited or exported.') + '</div>';
  }
}

async function loadAssignments() {
  $('assignmentList').innerHTML = '<p class="muted" style="margin:6px">Loading employees…</p>';
  try {
    const data = await apiGet('api/datasets/' + id + '/assignments');
    assignmentEmployees = data.employees || [];
    $('assignmentList').innerHTML = assignmentEmployees.length
      ? assignmentEmployees.map(employee =>
          '<label class="assignment-person">' +
            '<input type="checkbox" data-assignee="' + employee.id + '"' +
              (employee.assigned ? ' checked' : '') +
              (!employee.is_active ? ' disabled' : '') + '>' +
            '<span><strong>' + esc(employee.full_name) + '</strong>' +
            '<small>' + esc(employee.email) +
              (!employee.is_active ? ' · inactive' : '') + '</small></span>' +
          '</label>'
        ).join('')
      : '<p class="muted" style="margin:6px">No employee accounts yet.</p>';
  } catch (err) {
    $('assignmentList').innerHTML = '<p class="muted" style="margin:6px">Could not load employees.</p>';
  }
}

$('assignmentSave').addEventListener('click', async () => {
  const button = $('assignmentSave');
  const userIds = $$('[data-assignee]:checked').map(input => Number(input.dataset.assignee));
  try {
    button.disabled = true;
    button.textContent = 'Saving…';
    await apiPatch('api/datasets/' + id + '/assignments', { user_ids: userIds });
    toast('Employee assignments saved.');
  } catch (err) {
    toast(err.message, true);
  } finally {
    button.disabled = false;
    button.textContent = 'Save assignments';
  }
});

$('settingsBtn').addEventListener('click', async () => {
  if (!ds) return;

  $('settingsSub').textContent = !session.user.is_admin
    ? 'Choose whether this assigned dataset is included in your own AI searches.'
    : (ds.is_protected
      ? 'This dataset is protected, so only its global search setting and assignments can be changed.'
      : 'Renaming affects only the label — the underlying table keeps its name.');
  $('setName').value = ds.display_name;
  $('setName').disabled = !session.user.is_admin || ds.is_protected;
  $('setSearchable').checked = session.user.is_admin
    ? ds.is_searchable
    : ds.user_search_enabled;
  $('setSearchable').disabled = !session.user.is_admin && !ds.is_searchable;
  $('searchableLabel').textContent = session.user.is_admin
    ? 'Make this dataset available for employee AI searches'
    : (ds.is_searchable
      ? 'Include this dataset in my AI searches'
      : 'AI search is disabled by an administrator');
  $('setFolder').disabled = !session.user.is_admin || ds.is_protected;
  $('delConfirm').value = '';
  $('delConfirm').disabled = !session.user.is_admin || ds.is_protected;
  $('delBtn').disabled = !session.user.is_admin || ds.is_protected;
  $('deleteSection').hidden = !session.user.is_admin;

  if (session.user.is_admin) {
    try {
      const folderData = await apiGet('api/folders');

      $('setFolder').innerHTML = '<option value="">No folder</option>' +
        folderData.folders.map(folder => '<option value="' + folder.id + '"' +
          (folder.id === ds.folder_id ? ' selected' : '') + '>' +
          esc(folder.name) + '</option>').join('');

    } catch (err) {
      $('setFolder').innerHTML = '<option value="">No folder</option>';
    }
  }

  openModal('settingsModal');
});

$('settingsSave').addEventListener('click', async () => {
  if (!ds) return;

  const button = $('settingsSave');
  const body = {};

  if (session.user.is_admin) {
    body.is_searchable = $('setSearchable').checked;
  }

  if (session.user.is_admin && !ds.is_protected) {
    body.display_name = $('setName').value.trim();
    body.folder_id = $('setFolder').value ? Number($('setFolder').value) : null;

    if (!body.display_name) {
      $('setName').focus();
      return toast('The dataset needs a name.', true);
    }
  }

  try {
    button.disabled = true;
    button.textContent = 'Saving…';

    let result;

    if (session.user.is_admin) {
      result = await apiPatch('api/datasets/' + id, body);
    } else {
      result = await apiPatch('api/datasets/' + id + '/search-preference', {
        enabled: $('setSearchable').checked,
      });
      ds.user_search_enabled = result.enabled;
    }

    if (result.dataset) {
      ds = result.dataset;
      columns = ds.columns || columns;
      renderHead();
    } else {
      // Compatibility with an older backend response during rolling deploys.
      ds.display_name = body.display_name ?? ds.display_name;
      ds.folder_id = body.folder_id ?? ds.folder_id;
      if (body.is_searchable !== undefined) ds.is_searchable = body.is_searchable;
      renderHead();
    }

    closeModal('settingsModal');
    toast('Dataset settings saved.');
  } catch (err) {
    toast(err.message, true);
  } finally {
    button.disabled = false;
    button.textContent = 'Save changes';
  }
});

$('delBtn').addEventListener('click', async () => {
  if (!ds || ds.is_protected) return;

  const typed = $('delConfirm').value.trim();
  if (typed !== ds.display_name) {
    return toast('Type the dataset name exactly to confirm.', true);
  }

  if (!confirm('Delete "' + ds.display_name + '" and all of its rows? This cannot be undone.')) {
    return;
  }

  try {
    await apiDelete('api/datasets/' + id, { confirm: typed });
    location.href = 'datasets.html';
  } catch (err) {
    toast(err.message, true);
  }
});

async function loadRows() {
  if (!columns.length) {
    $('status').innerHTML = '<div class="empty"><h3>This dataset has no columns</h3></div>';
    return;
  }

  $('status').innerHTML = '<div class="loading"><span class="pulse"></span>Loading rows...</div>';
  const params = new URLSearchParams({
    page, per, after: rowCursors[cursorIndex] || 0, q: term, sort, dir,
    filters: JSON.stringify(filters),
  });

  try {
    const data = await apiGet('api/datasets/' + id + '/rows?' + params);
    renderRows(data);
  } catch (err) {
    showError($('status'), 'Could not load rows', esc(err.message));
  }
}

function renderRows(data) {
  if (!data.rows.length) {
    $('status').innerHTML = '<div class="empty"><h3>' +
      (term ? 'Nothing matches "' + esc(term) + '"' : 'No rows yet') + '</h3>' +
      '<p>' + (term ? 'Try a shorter search.' : 'This table is empty.') + '</p></div>';
    return;
  }

  const head = columns.map(c => {
    const option = filterOptions[c.name];
    const control = option && !option.limited
      ? '<select class="column-filter" data-filter="' + esc(c.name) + '" aria-label="Filter ' +
          esc(c.label || c.name) + '">' +
          '<option value="">All</option>' +
          option.values.map(value => '<option value="' + esc(value) + '"' +
            (filters[c.name] === value ? ' selected' : '') + '>' + esc(value) + '</option>').join('') +
        '</select>'
      : '<input class="column-filter" type="search" data-filter="' + esc(c.name) + '" ' +
          'placeholder="Filter..." value="' + esc(filters[c.name] || '') + '" ' +
          'aria-label="Filter ' + esc(c.label || c.name) + '">';

    return '<th>' +
      '<button class="linkbtn" data-sort="' + esc(c.name) + '" ' +
        'style="color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit">' +
        esc(c.label || c.name) +
        (sort === c.name ? (dir === 'asc' ? ' ▲' : ' ▼') : '') +
      '</button>' +
      control +
    '</th>';
  }).join('');

  $('status').innerHTML =
    '<div class="sechead"><h2>Rows</h2><span class="cbadge">' + fmt(data.total) + '</span></div>' +
    '<div class="tablecard"><div class="scroll"><table>' +
      '<thead><tr>' + head + '</tr></thead>' +
      '<tbody>' + data.rows.map(rowHtml).join('') + '</tbody>' +
    '</table></div><div class="pager" id="pager"></div></div>';

  renderPager(data);
  $$('[data-sort]').forEach(button => button.addEventListener('click', () => {
    const column = button.dataset.sort;
    if (sort === column) dir = dir === 'asc' ? 'desc' : 'asc';
    else { sort = column; dir = 'asc'; }
    page = 1;
    rowCursors = [0]; cursorIndex = 0;
    loadRows();
  }));

  $$('[data-filter]').forEach(input => input.addEventListener('input', event => {
    const column = event.target.dataset.filter;
    clearTimeout(window.datasetFilterTimer);
    window.datasetFilterTimer = setTimeout(() => {
      const value = event.target.value.trim();
      if (value) filters[column] = value;
      else delete filters[column];
      page = 1;
      rowCursors = [0]; cursorIndex = 0;
      loadRows();
    }, 300);
  }));
}

function rowHtml(row) {
  return '<tr>' + columns.map(column => {
    const value = row[column.name];
    return '<td>' + (value === null || value === undefined || value === ''
      ? '<span class="blank">—</span>' : esc(value)) + '</td>';
  }).join('') + '</tr>';
}

function renderPager(data) {
  $('pager').innerHTML =
    '<span class="range mono">' + fmt((data.page - 1) * data.per + 1) + '–' +
      fmt(Math.min(data.page * data.per, data.total)) + ' of ' + fmt(data.total) + '</span>' +
    '<span class="spacer"></span>' +
    '<button class="pg" id="prev"' + ((data.cursor_mode ? cursorIndex <= 0 : data.page <= 1) ? ' disabled' : '') + '>Previous</button>' +
    '<span class="range mono">Page ' + (data.cursor_mode ? cursorIndex + 1 : data.page) + '</span>' +
    '<button class="pg" id="next"' + (!data.has_more ? ' disabled' : '') + '>Next</button>';

  $('prev').addEventListener('click', () => {
    if (data.cursor_mode && cursorIndex > 0) { cursorIndex--; page = cursorIndex + 1; loadRows(); }
    else if (!data.cursor_mode && page > 1) { page--; loadRows(); }
  });
  $('next').addEventListener('click', () => {
    if (data.cursor_mode && data.has_more && data.next_cursor) {
      rowCursors[cursorIndex + 1] = data.next_cursor;
      cursorIndex++;
      page = cursorIndex + 1;
      loadRows();
    } else if (!data.cursor_mode && data.has_more) { page++; loadRows(); }
  });
}

$('q').addEventListener('input', event => {
  clearTimeout(window.datasetSearchTimer);
  window.datasetSearchTimer = setTimeout(() => {
    term = event.target.value.trim();
    page = 1;
    rowCursors = [0]; cursorIndex = 0;
    loadRows();
  }, 300);
});
