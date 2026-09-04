const id = Number(new URLSearchParams(location.search).get('id') || 0);
let ds = null;
let columns = [];
let page = 1;
let per = 50;
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
    page, per, q: term, sort, dir,
    filters: JSON.stringify(filters),
  });

  try {
    const data = await apiGet('api/datasets/' + id + '/rows?' + params);
    renderRows(data);
  } catch (err) {
    showError($('status'), 'Could not load rows', esc(err.message));
  }
}

let currentRows = null;

function renderRows(data) {
  currentRows = data;

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
          'placeholder="Filter… (Enter)" value="' + esc(filters[c.name] || '') + '" ' +
          'aria-label="Filter ' + esc(c.label || c.name) + '">';

    return '<th>' +
      '<div class="th-row">' +
        '<button class="linkbtn" data-sort="' + esc(c.name) + '" ' +
          'style="color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit">' +
          esc(c.label || c.name) +
          (sort === c.name ? (dir === 'asc' ? ' ▲' : ' ▼') : '') +
        '</button>' +
        '<button type="button" class="colcopy" data-copy-col="' + esc(c.name) + '" ' +
          'title="Copy this column" aria-label="Copy ' + esc(c.label || c.name) + ' column">' + COPY_ICON + '</button>' +
      '</div>' +
      control +
    '</th>';
  }).join('');

  $('status').innerHTML =
    '<div class="sechead"><h2>Rows</h2><span class="cbadge">' + fmt(data.total) + '</span></div>' +
    flagLegend() +
    '<div class="tablecard">' +
      '<div class="pager pager-top" id="pagerTop"></div>' +
      '<div class="scroll"><table class="rowtable">' +
        '<thead><tr><th class="statuscol">Status</th>' + head + '</tr></thead>' +
        '<tbody>' + data.rows.map(rowHtml).join('') + '</tbody>' +
      '</table></div>' +
      '<div class="pager" id="pager"></div>' +
    '</div>';

  renderPager(data);
  $$('[data-sort]').forEach(button => button.addEventListener('click', () => {
    const column = button.dataset.sort;
    if (sort === column) dir = dir === 'asc' ? 'desc' : 'asc';
    else { sort = column; dir = 'asc'; }
    page = 1;
    loadRows();
  }));

  $$('[data-filter]').forEach(input => {
    if (input.tagName === 'SELECT') {
      input.addEventListener('change', () => applyFilter(input));
    } else {
      input.addEventListener('keydown', event => { if (event.key === 'Enter') applyFilter(input); });
    }
  });

  $$('[data-copy-col]').forEach(button =>
    button.addEventListener('click', () => copyColumn(button.dataset.copyCol)));

  $$('[data-flag-row]').forEach(select => select.addEventListener('change', event => {
    setRowFlag(Number(event.target.dataset.flagRow), event.target.value);
  }));
}

function applyFilter(input) {
  const column = input.dataset.filter;
  const value = input.value.trim();
  if (value) filters[column] = value;
  else delete filters[column];
  page = 1;
  loadRows();
}

/* ── copy a column (current page) to the clipboard ────────────────────── */

const COPY_ICON =
  '<svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" focusable="false">' +
    '<rect x="8" y="8" width="12" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/>' +
    '<path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>' +
  '</svg>';

async function copyColumn(columnName) {
  if (!currentRows || !currentRows.rows.length) return toast('Nothing to copy in this column.', true);

  const column = columns.find(c => c.name === columnName);
  // Blank cells copy as empty lines rather than being skipped, so row N in
  // the copied list still lines up with row N on the page — matters when
  // pasting this column alongside others that don't have the same gaps.
  const values = currentRows.rows.map(r => {
    const v = r[columnName];
    return v === null || v === undefined ? '' : String(v);
  });
  const filled = values.filter(v => v.trim() !== '').length;

  try {
    await navigator.clipboard.writeText(values.join('\n'));
    toast('Copied ' + fmt(values.length) + ' row' + (values.length === 1 ? '' : 's') +
      ' (' + fmt(filled) + ' with a value) from "' + (column ? (column.label || column.name) : columnName) + '".');
  } catch (err) {
    toast('Could not copy — the browser blocked clipboard access.', true);
  }
}

/* ── click a single cell to copy it (delegated, wired once) ──────────────── */

async function copyCellValue(el) {
  const value = el.dataset.copyValue;
  if (!value) return;

  try {
    await navigator.clipboard.writeText(value);
    toast('Copied "' + value + '".');
  } catch (err) {
    toast('Could not copy — the browser blocked clipboard access.', true);
  }
}

document.addEventListener('click', event => {
  const el = event.target.closest('.copytext');
  if (el) copyCellValue(el);
});
document.addEventListener('keydown', event => {
  if (event.key !== 'Enter' && event.key !== ' ') return;
  const el = event.target.closest('.copytext');
  if (el) { event.preventDefault(); copyCellValue(el); }
});

function rowHtml(row) {
  const flagClass = row.flag ? ' flag-row-' + row.flag.status : '';
  return '<tr class="lead-row' + flagClass + '" data-row-id="' + row._row_id + '">' +
    flagCellHtml(row._row_id, row.flag) +
    columns.map(column => cellHtml(column, row[column.name])).join('') +
  '</tr>';
}

/** Renders phone numbers, email addresses, and anything URL-shaped as
 *  clickable links — by column name where the dataset labels it (phone,
 *  email, website, linkedin, ...url/...link), and by the value's own shape
 *  otherwise, so a column an admin named something unexpected still works. */
function cellHtml(column, value) {
  const v = (value === null || value === undefined) ? '' : String(value);
  if (!v.trim()) return '<td><span class="blank">—</span></td>';

  const name = column.name.toLowerCase();

  if (name.includes('phone')) {
    const digits = v.replace(/\D/g, '');
    if (digits.length >= 7) {
      return '<td class="tel"><a href="tel:' + digits + '" class="mono">' + esc(v) + '</a></td>';
    }
  }

  if (name.includes('email') && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim())) {
    // Plain text, not a mailto: link — clicking a row shouldn't pop open the
    // browser's mail client. Click-to-copy instead, same as the column-copy
    // button above it.
    return '<td><span class="copytext" data-copy-value="' + esc(v.trim()) +
      '" title="Click to copy" tabindex="0" role="button">' + esc(v) + '</span></td>';
  }

  // A column named for a link (website, linkedin, ...url, ...link) is treated
  // as one even without a scheme; any other column only qualifies when the
  // value itself is clearly a URL, so plain text never gets linkified.
  const urlColumn = /website|linkedin|(^|_)url$|(^|_)link$/.test(name);
  const trimmed = v.trim();
  if (urlColumn || /^(https?:\/\/|www\.)/i.test(trimmed)) {
    const href = /^https?:\/\//i.test(trimmed) ? trimmed : 'https://' + trimmed;
    const shown = trimmed.replace(/^https?:\/\/(www\.)?/i, '').split('/')[0];
    return '<td class="web"><a href="' + esc(href) + '" target="_blank" rel="noopener">' + esc(shown) + '</a></td>';
  }

  return '<td>' + esc(v) + '</td>';
}

async function setRowFlag(rowId, status) {
  const select = document.querySelector('[data-flag-row="' + rowId + '"]');
  const tr = select ? select.closest('tr') : null;

  try {
    if (select) select.disabled = true;
    const result = await patchRowFlag(id, rowId, status);

    if (currentRows) {
      const row = currentRows.rows.find(r => Number(r._row_id) === rowId);
      if (row) row.flag = result.flag;
    }

    if (tr) {
      tr.className = 'lead-row' + (result.flag ? ' flag-row-' + result.flag.status : '');
      const cell = tr.querySelector('.flagcell');
      if (cell) cell.outerHTML = flagCellHtml(rowId, result.flag);
      const newSelect = tr.querySelector('[data-flag-row]');
      if (newSelect) newSelect.addEventListener('change', event =>
        setRowFlag(Number(event.target.dataset.flagRow), event.target.value));
    }

    toast(status ? 'Marked as ' + FLAG_LABELS[status].toLowerCase() + '.' : 'Status cleared.');
  } catch (err) {
    toast(err.message, true);
    if (select) select.value = (currentRows && currentRows.rows.find(r => Number(r._row_id) === rowId) || {}).flag?.status || '';
  } finally {
    if (select && select.isConnected) select.disabled = false;
  }
}

/** Which page-number buttons to show: all of them under 8 pages, otherwise
 *  first, last, and a window around the current page, with gaps elided. */
function pageButtons(cur, total) {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

  const keep = new Set([1, 2, total - 1, total, cur - 1, cur, cur + 1]);
  const nums = [...keep].filter(n => n >= 1 && n <= total).sort((a, b) => a - b);

  const out = [];
  let prev = 0;
  for (const n of nums) {
    if (prev && n - prev > 1) out.push('…');
    out.push(n);
    prev = n;
  }
  return out;
}

function pagerHtml(data, pages) {
  return (
    '<span class="range mono">' + fmt((data.page - 1) * data.per + 1) + '–' +
      fmt(Math.min(data.page * data.per, data.total)) + ' of ' + fmt(data.total) + '</span>' +
    '<span class="spacer"></span>' +
    '<div class="pagenums">' +
      '<button class="pg" data-pg-prev' + (data.page <= 1 ? ' disabled' : '') + '>Previous</button>' +
      pageButtons(data.page, pages).map(p => p === '…'
        ? '<span class="pg-ellipsis">…</span>'
        : '<button class="pg pg-num' + (p === data.page ? ' active' : '') + '" data-page="' + p + '"' +
            (p === data.page ? ' disabled' : '') + '>' + p + '</button>'
      ).join('') +
      '<button class="pg" data-pg-next' + (data.page >= pages ? ' disabled' : '') + '>Next</button>' +
      (pages > 1
        ? '<span class="pg-jump">' +
            '<input type="number" min="1" max="' + pages + '" class="pg-jump-input" ' +
              'placeholder="#" aria-label="Go to page (1–' + pages + ')">' +
            '<button class="pg" data-pg-go>Go</button>' +
          '</span>'
        : '') +
    '</div>'
  );
}

/** Wires one rendering of the pager (there are two — top and bottom — so
 *  every control is looked up inside its own container, never by page-wide id). */
function wirePager(container, pages) {
  const prevBtn = container.querySelector('[data-pg-prev]');
  const nextBtn = container.querySelector('[data-pg-next]');
  if (prevBtn) prevBtn.addEventListener('click', () => { if (page > 1) { page--; loadRows(); scrollTop(); } });
  if (nextBtn) nextBtn.addEventListener('click', () => { if (page < pages) { page++; loadRows(); scrollTop(); } });

  container.querySelectorAll('[data-page]').forEach(button => button.addEventListener('click', () => {
    page = Number(button.dataset.page);
    loadRows();
    scrollTop();
  }));

  const jumpInput = container.querySelector('.pg-jump-input');
  const goBtn = container.querySelector('[data-pg-go]');
  if (!jumpInput || !goBtn) return;

  const jump = () => {
    const n = Math.round(Number(jumpInput.value));
    if (!n || n < 1 || n > pages) {
      toast('Enter a page between 1 and ' + fmt(pages) + '.', true);
      return;
    }
    page = n;
    loadRows();
    scrollTop();
  };
  goBtn.addEventListener('click', jump);
  jumpInput.addEventListener('keydown', event => { if (event.key === 'Enter') jump(); });
}

function renderPager(data) {
  const pages = data.pages;
  const html = pagerHtml(data, pages);

  const top = $('pagerTop');
  const bottom = $('pager');
  if (top) { top.innerHTML = html; wirePager(top, pages); }
  if (bottom) { bottom.innerHTML = html; wirePager(bottom, pages); }
}

function scrollTop() {
  const card = document.querySelector('.tablecard');
  if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

$('q').addEventListener('keydown', event => {
  if (event.key !== 'Enter') return;
  term = event.target.value.trim();
  page = 1;
  loadRows();
});
