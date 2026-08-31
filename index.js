/* Search page behavior. External so the site's CSP can block inline scripts. */
const EXAMPLES = [
  'Roofers in Oklahoma with mobile numbers and no email',
  'How many HVAC companies are in Texas',
  'Plumbers in Dallas',
  'Which cities have the most home builders',
  'Owners at Flintco'
];

const qEl = $('q'), statusEl = $('status');
let rows = [], cols = [], page = 0, busy = false, searchableRows = 0;
const PER_PAGE = 50;

const LABELS = {
  business_name:'Company name', company_name:'Company name',
  company:'Company name', name:'Company name', legal_name:'Company name',
  dba_name:'Trading name',
  primary_sector:'Trade', sectors:'All trades',
  sectors_norm:'All trades', size_band:'Size', num_employees:'Employees',
  number_of_employees:'Employees', contact_person:'Contacts',
  corporate_email:'Corporate email', generic_email:'Generic email',
  has_email:'Email', phone:'Phone', phone_type:'Line',
  street_address:'Address', city:'City', state:'State', website:'Website', id:'ID'
};
const label = c => LABELS[c] || String(c).replace(/_/g,' ').replace(/\b\w/g, m => m.toUpperCase());

/* Columns that hold the company's name — datasets spell it differently. */
const NAME_COLS = ['business_name','company_name','company','name','legal_name','dba_name'];

(async () => {
  await requireSession({ page: 'search' });

  EXAMPLES.forEach(text => {
    const b = document.createElement('button');
    b.className = 'chip';
    b.textContent = text;
    b.addEventListener('click', () => { qEl.value = text; run(); });
    $('chips').appendChild(b);
  });

  try {
    const { datasets } = await apiGet('api/datasets');
    const searchable = datasets.filter(d => d.is_searchable && d.status === 'ready');
    searchableRows = searchable.reduce((n, d) => n + d.row_count, 0);

    if (searchable.length) {
      qEl.placeholder = 'Search ' + fmt(searchableRows) + ' records — plain English';
    } else {
      statusEl.innerHTML =
        '<div class="notice"><strong>Nothing is searchable yet.</strong> ' +
        'An administrator needs to mark at least one dataset as searchable on the ' +
        '<a href="datasets.html">Datasets</a> page.</div>';
    }
  } catch (e) { /* search still works; the count is cosmetic */ }
})();

$('go').addEventListener('click', run);
qEl.addEventListener('keydown', e => { if (e.key === 'Enter') run(); });

async function run(){
  const question = qEl.value.trim();
  if (!question || busy) return;

  busy = true; $('go').disabled = true;
  const started = performance.now();

  statusEl.innerHTML =
    '<div class="loading"><span class="pulse"></span>Writing the query and searching' +
    (searchableRows ? ' ' + fmt(searchableRows) + ' records' : '') + '…</div>';

  let body;

  try {
    body = await apiPost('api/search', { question });
  } catch (err) {
    busy = false; $('go').disabled = false;
    return showError(statusEl, 'The search failed', esc(err.message));
  }

  busy = false; $('go').disabled = false;

  rows = Array.isArray(body) ? body : (body.data || []);
  cols = rows.length ? Object.keys(rows[0]) : [];
  page = 0;

  render(body, Math.round(performance.now() - started));
}

function render(body, ms){
  if (!rows.length) {
    statusEl.innerHTML =
      '<div class="empty"><h3>No businesses matched</h3>' +
      '<p>Try a wider area, or drop one of the filters — asking for a state instead of a city usually helps.</p></div>' +
      sqlBlock(body);
    wireSql();
    return;
  }

  statusEl.innerHTML =
    '<div class="sechead">' +
      '<h2>' + (rows.length === 1 ? 'Business' : 'Businesses') + '</h2>' +
      '<span class="cbadge">' + fmt(rows.length) + '</span>' +
      '<span class="timing">' + (ms/1000).toFixed(1) + 's</span>' +
      '<span class="spacer"></span>' +
      (body.sql ? '<button class="linkbtn" id="sqlToggle">Show query</button>' : '') +
      '<button class="linkbtn" id="dl">Download CSV</button>' +
    '</div>' +
    (body.sql ? '<div class="sqlbox mono" id="sqlbox">' + esc(body.sql) + '</div>' : '') +
    '<div class="tablecard"><div class="scroll"><table>' +
      '<thead><tr>' + cols.map(c => '<th>' + esc(label(c)) + '</th>').join('') + '</tr></thead>' +
      '<tbody id="tbody"></tbody>' +
    '</table></div><div class="pager" id="pager"></div></div>';

  wireSql();
  $('dl').addEventListener('click', downloadCsv);
  paint();
}

function sqlBlock(body){
  if (!body || !body.sql) return '';
  return '<div class="sechead" style="margin-top:16px"><h2>Query</h2><span class="spacer"></span>' +
         '<button class="linkbtn" id="sqlToggle">Show query</button></div>' +
         '<div class="sqlbox mono" id="sqlbox">' + esc(body.sql) + '</div>';
}

function wireSql(){
  const t = $('sqlToggle');
  if (!t) return;
  t.addEventListener('click', () => {
    const box = $('sqlbox');
    box.classList.toggle('open');
    t.textContent = box.classList.contains('open') ? 'Hide query' : 'Show query';
  });
}

function paint(){
  const start = page * PER_PAGE;
  const slice = rows.slice(start, start + PER_PAGE);

  $('tbody').innerHTML = slice.map(r =>
    '<tr>' + cols.map(c => cell(c, r[c], r)).join('') + '</tr>'
  ).join('');

  const pages = Math.ceil(rows.length / PER_PAGE);

  $('pager').innerHTML =
    '<span class="range mono">' + fmt(start + 1) + '–' +
      fmt(Math.min(start + PER_PAGE, rows.length)) + ' of ' + fmt(rows.length) + '</span>' +
    '<span class="spacer"></span>' +
    '<button class="pg" id="prev"' + (page === 0 ? ' disabled' : '') + '>Previous</button>' +
    '<button class="pg" id="next"' + (page >= pages - 1 ? ' disabled' : '') + '>Next</button>';

  $('prev').addEventListener('click', () => { if (page > 0) { page--; paint(); scrollTop(); } });
  $('next').addEventListener('click', () => { if (page < pages - 1) { page++; paint(); scrollTop(); } });
}

function scrollTop(){
  document.querySelector('.tablecard').scrollIntoView({ behavior:'smooth', block:'start' });
}

function cell(col, val, row){
  const v = (val === null || val === undefined) ? '' : String(val);
  if (!v.trim()) return '<td><span class="blank">—</span></td>';

  if (col === 'phone') {
    const digits = v.replace(/\D/g,'');
    return '<td class="tel"><a href="tel:' + digits + '" class="mono">' + esc(v) + '</a></td>';
  }
  if (col === 'phone_type') {
    const k = v.toLowerCase().includes('mobile') ? 'mobile'
            : v.toLowerCase().includes('voip')   ? 'voip' : 'fixed';
    return '<td><span class="line"><span class="dot ' + k + '"></span>' + esc(v) + '</span></td>';
  }
  if (col === 'website') {
    const href = /^https?:\/\//i.test(v) ? v : 'https://' + v;
    const shown = v.replace(/^https?:\/\/(www\.)?/i,'').split('/')[0];
    return '<td class="web"><a href="' + esc(href) + '" target="_blank" rel="noopener">' + esc(shown) + '</a></td>';
  }
  if (col === 'state') return '<td class="st mono">' + esc(v) + '</td>';
  if (NAME_COLS.includes(col)) {
    return '<td class="name"><span class="namewrap">' + esc(v) + googleBtn(v, row) + '</span></td>';
  }
  if (col === 'id') return '<td class="mono st">' + esc(v) + '</td>';
  return '<td>' + esc(v) + '</td>';
}

/* ── google lookup ──────────────────────────────────────────────────────
   A one-click Google search for the business, next to its name. The city
   and state are folded into the query when the row carries them, so
   common names ("Apex Roofing") land on the right company.               */

const GOOGLE_ICON =
  '<svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" focusable="false">' +
    '<path fill="currentColor" d="M11 4a7 7 0 1 0 4.24 12.56l3.6 3.6a1 1 0 0 0 1.42-1.42l-3.6-3.6A7 7 0 0 0 11 4Zm0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10Z"/>' +
  '</svg>';

function googleBtn(name, row){
  const parts = [name];
  if (row) {
    if (row.city)  parts.push(String(row.city));
    if (row.state) parts.push(String(row.state));
  }
  const url = 'https://www.google.com/search?q=' + encodeURIComponent(parts.join(' ').trim());
  return '<a class="gbtn" href="' + esc(url) + '" target="_blank" rel="noopener noreferrer"' +
         ' title="Search Google for ' + esc(name) + '"' +
         ' aria-label="Search Google for ' + esc(name) + '">' + GOOGLE_ICON + '<span>Google</span></a>';
}

function downloadCsv(){
  const q = s => '"' + String(s ?? '').replace(/"/g,'""') + '"';
  const csv = [cols.map(q).join(',')]
    .concat(rows.map(r => cols.map(c => q(r[c])).join(',')))
    .join('\r\n');

  const blob = new Blob(['﻿' + csv], { type:'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'leads-' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
