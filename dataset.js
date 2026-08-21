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
    if (ds.status === 'ready') {
      await loadFilterOptions();
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
  $('exportBtn').href = 'api/datasets/' + id + '/export';

  if (ds.is_protected) {
    $('banner').innerHTML =
      '<div class="notice"><strong>This is the master leads table.</strong> ' +
      'It can be searched and exported, but not edited or deleted here.</div>';
  }
}

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
    '<button class="pg" id="prev"' + (data.page <= 1 ? ' disabled' : '') + '>Previous</button>' +
    '<span class="range mono">' + data.page + ' / ' + data.pages + '</span>' +
    '<button class="pg" id="next"' + (data.page >= data.pages ? ' disabled' : '') + '>Next</button>';

  $('prev').addEventListener('click', () => { if (page > 1) { page--; loadRows(); } });
  $('next').addEventListener('click', () => { if (page < data.pages) { page++; loadRows(); } });
}

$('q').addEventListener('input', event => {
  clearTimeout(window.datasetSearchTimer);
  window.datasetSearchTimer = setTimeout(() => {
    term = event.target.value.trim();
    page = 1;
    loadRows();
  }, 300);
});

$('per').addEventListener('change', event => {
  per = Number(event.target.value);
  page = 1;
  loadRows();
});
