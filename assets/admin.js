(function () {
  const startBtn = document.getElementById('hostfully-bulk-start');
  const stopBtn = document.getElementById('hostfully-bulk-stop');
  const wrap = document.getElementById('hostfully-progress');
  const statusEl = document.getElementById('hostfully-status');
  const logEl = document.getElementById('hostfully-log');
  const summaryEl = document.getElementById('hostfully-summary');
  const jsIndicator = document.getElementById('hostfully-js-indicator');
  const spinner = document.getElementById('hostfully-spinner');
  const counterEl = document.getElementById('hostfully-counter');

  if (!startBtn || !stopBtn || !wrap || !statusEl || !logEl) {
    return;
  }

  let stopped = false;
  let lastErrorSeen = null;


  const bulkUpdateExisting = document.getElementById('hostfully-bulk-update-existing');
  const bulkUpdateSlugs = document.getElementById('hostfully-bulk-update-slugs');
  const updateExistingOne = document.querySelector('input[name="update_existing"]');
  const propertySelect = document.querySelector('select[name="property_uid"]');
  const propertySelectEl = document.getElementById('hostfully-property-select');
  const loadPropertiesBtn = document.getElementById('hostfully-load-properties');
  const refreshPropertiesBtn = document.getElementById('hostfully-refresh-properties');
  const loadPropertiesStatus = document.getElementById('hostfully-load-properties-status');
  const loadPropertiesSpinner = document.getElementById('hostfully-load-properties-spinner');
  const importOneForm = document.getElementById('hostfully-import-one-form');
  const importOneBtn = importOneForm
    ? importOneForm.querySelector('button[name="hostfully_import_one"]')
    : null;
  const importOneStatus = document.getElementById('hostfully-import-one-status');
  const importOneSpinner = document.getElementById('hostfully-import-one-spinner');
  const uidForm = document.getElementById('hostfully-uid-import-form');
  const uidTextarea = document.getElementById('hostfully_uid_list');
  const uidMissingTextarea = document.getElementById('hostfully_uid_missing');
  const uidStartBtn = document.getElementById('hostfully-uid-start');
  const uidUpdateExisting = document.getElementById('hostfully-uid-update-existing');
  const uidCompareBtn = document.getElementById('hostfully-uid-compare');
  const uidUseMissingBtn = document.getElementById('hostfully-uid-use-missing');
  const icalReportBtn = document.getElementById('hostfully-ical-report-run');
  const icalReportCsvBtn = document.getElementById('hostfully-ical-report-csv');
  const icalReportLimit = document.getElementById('hostfully-ical-report-limit');
  const icalReportStatus = document.getElementById('hostfully-ical-report-status');
  const icalReportSpinner = document.getElementById('hostfully-ical-report-spinner');
  const icalReportTable = document.getElementById('hostfully-ical-report-table');
  const icalReportLog = document.getElementById('hostfully-ical-report-log');
  const icalLinkBtn = document.getElementById('hostfully-ical-link-run');
  const icalLinkLimit = document.getElementById('hostfully-ical-link-limit');
  const icalLinkReplace = document.getElementById('hostfully-ical-link-replace');
  const icalLinkStatus = document.getElementById('hostfully-ical-link-status');
  const icalLinkSpinner = document.getElementById('hostfully-ical-link-spinner');
  const icalLinkTable = document.getElementById('hostfully-ical-link-table');
  const icalLinkLog = document.getElementById('hostfully-ical-link-log');
  const migrateBtn = document.getElementById('hostfully-migrate-start');
  const cleanupBtn = document.getElementById('hostfully-cleanup-terms');
  const wrapEl = document.querySelector('.wrap[data-next-action]');
  const nextActionStep = wrapEl ? wrapEl.getAttribute('data-next-action') : '';
  const detailEls = document.querySelectorAll('details[data-step]');
  const detailStorageKey = 'hostfully_mphb_details';

  function refreshImportedOptions() {
    if (!propertySelect) return;
    const allowUpdate = updateExistingOne && updateExistingOne.checked;
    [...propertySelect.options].forEach((opt) => {
      if (opt.getAttribute('data-imported') === '1') {
        opt.disabled = !allowUpdate;
      }
    });
  }

  if (updateExistingOne) {
    updateExistingOne.addEventListener('change', refreshImportedOptions);
  }
  refreshImportedOptions();

  let propertiesLoaded = false;
  let loadingProperties = false;
  let toastTimer = null;
  let lastIcalReportItems = [];
  function ensureToast() {
    let toast = document.getElementById('hostfully-toast');
    if (toast) return toast;

    toast = document.createElement('div');
    toast.id = 'hostfully-toast';
    toast.className = 'notice is-dismissible';
    toast.style.position = 'fixed';
    toast.style.right = '20px';
    toast.style.bottom = '20px';
    toast.style.zIndex = '100000';
    toast.style.maxWidth = '360px';
    toast.style.boxShadow = '0 2px 8px rgba(0, 0, 0, 0.15)';
    toast.style.display = 'none';

    const msg = document.createElement('p');
    msg.className = 'hostfully-toast-message';
    msg.style.margin = '0.5em 1em 0.5em 0.75em';
    toast.appendChild(msg);

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'notice-dismiss';
    dismiss.addEventListener('click', function () {
      toast.style.display = 'none';
    });
    const dismissText = document.createElement('span');
    dismissText.className = 'screen-reader-text';
    dismissText.textContent = 'Dismiss this notice.';
    dismiss.appendChild(dismissText);
    toast.appendChild(dismiss);

    document.body.appendChild(toast);
    return toast;
  }

  function showToast(message, type = 'success') {
    const toast = ensureToast();
    const msg = toast.querySelector('.hostfully-toast-message');
    if (msg) msg.textContent = message;
    toast.className = `notice notice-${type} is-dismissible`;
    toast.style.display = 'block';
    if (toastTimer) window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () {
      toast.style.display = 'none';
    }, 3500);
  }

  function setLoadPropertiesBusy(isBusy) {
    if (!loadPropertiesSpinner) return;
    if (isBusy) {
      loadPropertiesSpinner.classList.add('is-active');
    } else {
      loadPropertiesSpinner.classList.remove('is-active');
    }
  }

  function setIcalReportBusy(isBusy) {
    if (!icalReportSpinner) return;
    if (isBusy) {
      icalReportSpinner.classList.add('is-active');
    } else {
      icalReportSpinner.classList.remove('is-active');
    }
  }

  function setIcalLinkBusy(isBusy) {
    if (!icalLinkSpinner) return;
    if (isBusy) {
      icalLinkSpinner.classList.add('is-active');
    } else {
      icalLinkSpinner.classList.remove('is-active');
    }
  }

  function renderIcalReport(items) {
    if (!icalReportTable) return;
    icalReportTable.innerHTML = '';

    const table = document.createElement('table');
    table.className = 'widefat fixed striped';
    table.style.maxWidth = '900px';

    const thead = document.createElement('thead');
    thead.innerHTML =
      '<tr>' +
      '<th style="width:240px;">Property</th>' +
      '<th style="width:280px;">UID</th>' +
      '<th>Channels</th>' +
      '<th style="width:90px;">iCal count</th>' +
      '<th style="width:110px;">Needs setup</th>' +
      '</tr>';
    table.appendChild(thead);

    const tbody = document.createElement('tbody');

    if (!items || !items.length) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 5;
      cell.textContent = 'No properties found.';
      row.appendChild(cell);
      tbody.appendChild(row);
    } else {
      items.forEach((item) => {
        const row = document.createElement('tr');
        const channels = (item.channels || []).join(', ');
        const needsSetup = item.needs_setup ? 'Yes' : 'No';

        row.innerHTML =
          '<td>' + escapeHtml(item.name || 'Unnamed') + '</td>' +
          '<td>' + escapeHtml(item.uid || '') + '</td>' +
          '<td>' + escapeHtml(channels || '-') + '</td>' +
          '<td>' + escapeHtml(String(item.ical_count || 0)) + '</td>' +
          '<td>' + escapeHtml(needsSetup) + '</td>';

        tbody.appendChild(row);
      });
    }

    table.appendChild(tbody);
    icalReportTable.appendChild(table);
  }

  function buildIcalCsv(items) {
    const header = ['Property', 'UID', 'Channels', 'iCal count', 'Needs setup'];
    const rows = [header.join(',')];
    items.forEach((item) => {
      const channels = (item.channels || []).join(' | ');
      const row = [
        csvEscape(item.name || ''),
        csvEscape(item.uid || ''),
        csvEscape(channels),
        csvEscape(String(item.ical_count || 0)),
        csvEscape(item.needs_setup ? 'Yes' : 'No'),
      ];
      rows.push(row.join(','));
    });
    return rows.join('\n');
  }

  function csvEscape(value) {
    const text = value == null ? '' : String(value);
    if (/[",\n]/.test(text)) {
      return '"' + text.replace(/"/g, '""') + '"';
    }
    return text;
  }

  function downloadCsv(filename, csvText) {
    const blob = new Blob([csvText], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  function appendIcalLog(lines) {
    if (!icalReportLog || !lines || !lines.length) return;
    const text = lines.join('\n');
    if (icalReportLog.textContent) {
      icalReportLog.textContent += '\n' + text;
    } else {
      icalReportLog.textContent = text;
    }
    icalReportLog.style.display = 'block';
  }

  function appendIcalLinkLog(lines) {
    if (!icalLinkLog || !lines || !lines.length) return;
    const text = lines.join('\n');
    if (icalLinkLog.textContent) {
      icalLinkLog.textContent += '\n' + text;
    } else {
      icalLinkLog.textContent = text;
    }
    icalLinkLog.style.display = 'block';
  }

  function renderIcalLinkReport(items) {
    if (!icalLinkTable) return;
    icalLinkTable.innerHTML = '';

    const table = document.createElement('table');
    table.className = 'widefat striped';
    table.style.width = '100%';
    table.style.maxWidth = '900px';
    table.style.tableLayout = 'auto';

    const thead = document.createElement('thead');
    thead.innerHTML =
      '<tr>' +
      '<th style="width:220px;">Property</th>' +
      '<th style="width:240px;">UID</th>' +
      '<th style="width:70px;">Room ID</th>' +
      '<th style="width:90px;">iCal count</th>' +
      '<th style="width:90px;">Linked</th>' +
      '<th>Status</th>' +
      '</tr>';
    table.appendChild(thead);

    const tbody = document.createElement('tbody');

    if (!items || !items.length) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 6;
      cell.textContent = 'No properties processed.';
      row.appendChild(cell);
      tbody.appendChild(row);
    } else {
      items.forEach((item) => {
        const row = document.createElement('tr');
        row.innerHTML =
          '<td style="white-space:normal; word-break:break-word;">' +
          escapeHtml(item.name || 'Unnamed') +
          '</td>' +
          '<td style="white-space:normal; word-break:break-word;">' +
          escapeHtml(item.uid || '') +
          '</td>' +
          '<td>' + escapeHtml(item.room_id ? String(item.room_id) : '-') + '</td>' +
          '<td>' + escapeHtml(String(item.ical_count || 0)) + '</td>' +
          '<td>' + escapeHtml(String(item.linked_count || 0)) + '</td>' +
          '<td style="white-space:normal; word-break:break-word;">' +
          escapeHtml(item.status || '-') +
          '</td>';
        tbody.appendChild(row);
      });
    }

    table.appendChild(tbody);
    icalLinkTable.appendChild(table);
  }

  function escapeHtml(value) {
    const text = value == null ? '' : String(value);
    return text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderPropertyOptions(items) {
    if (!propertySelectEl) return;
    propertySelectEl.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '-- Select a property --';
    propertySelectEl.appendChild(placeholder);

    items.forEach((p) => {
      const opt = document.createElement('option');
      opt.value = p.uid;
      opt.textContent = p.name + (p.imported ? ' (imported)' : '');
      if (p.imported) opt.setAttribute('data-imported', '1');
      propertySelectEl.appendChild(opt);
    });

    propertiesLoaded = true;
    refreshImportedOptions();
  }

  async function loadProperties(forceRefresh = false) {
    if (loadingProperties) return;
    loadingProperties = true;
    setLoadPropertiesBusy(true);
    if (loadPropertiesStatus) {
      loadPropertiesStatus.textContent = forceRefresh ? 'Refreshing…' : 'Loading…';
    }
    if (loadPropertiesBtn) loadPropertiesBtn.disabled = true;
    if (refreshPropertiesBtn) refreshPropertiesBtn.disabled = true;

    let r = null;
    try {
      r = await post('hostfully_mphb_fetch_properties', {
        force_refresh: forceRefresh ? '1' : '',
      });
      if (r && r.success) markJsOk();
    } catch (err) {
      showError('Load properties failed', err);
      showToast('Failed to load properties.', 'error');
      if (loadPropertiesStatus) loadPropertiesStatus.textContent = 'Failed';
      if (loadPropertiesBtn) loadPropertiesBtn.disabled = false;
      if (refreshPropertiesBtn) refreshPropertiesBtn.disabled = false;
      setLoadPropertiesBusy(false);
      loadingProperties = false;
      return;
    }

    if (!r || !r.success) {
      showError('Load properties failed', new Error('Unexpected response.'));
      showToast('Failed to load properties.', 'error');
      if (loadPropertiesStatus) loadPropertiesStatus.textContent = 'Failed';
      if (loadPropertiesBtn) loadPropertiesBtn.disabled = false;
      if (refreshPropertiesBtn) refreshPropertiesBtn.disabled = false;
      setLoadPropertiesBusy(false);
      loadingProperties = false;
      return;
    }

    const items = (r.data && r.data.properties) || [];
    renderPropertyOptions(items);
    if (loadPropertiesStatus) {
      loadPropertiesStatus.textContent = `${forceRefresh ? 'Refreshed' : 'Loaded'} ${items.length}`;
    }
    showToast(`Properties ${forceRefresh ? 'refreshed' : 'loaded'} (${items.length}).`, 'success');
    if (loadPropertiesBtn) loadPropertiesBtn.disabled = false;
    if (refreshPropertiesBtn) refreshPropertiesBtn.disabled = false;
    setLoadPropertiesBusy(false);
    loadingProperties = false;
  }

  if (loadPropertiesBtn) {
    loadPropertiesBtn.addEventListener('click', function (e) {
      e.preventDefault();
      loadProperties();
    });
  }

  if (refreshPropertiesBtn) {
    refreshPropertiesBtn.addEventListener('click', function (e) {
      e.preventDefault();
      loadProperties(true);
    });
  }

  if (icalReportBtn) {
    icalReportBtn.addEventListener('click', async function (e) {
      e.preventDefault();
      const limit = icalReportLimit ? parseInt(icalReportLimit.value, 10) : 50;
      const batchSize = 10;
      let offset = 0;
      let allItems = [];
      if (icalReportStatus) icalReportStatus.textContent = 'Running…';
      if (icalReportLog) {
        icalReportLog.textContent = '';
        icalReportLog.style.display = 'none';
      }
      if (icalReportTable) icalReportTable.innerHTML = '';
      setIcalReportBusy(true);
      icalReportBtn.disabled = true;

      let done = false;
      let total = Number.isFinite(limit) ? limit : 50;
      while (!done) {
        let r = null;
        try {
          r = await post('hostfully_mphb_ical_report', {
            limit: Number.isFinite(limit) ? String(limit) : '50',
            offset: String(offset),
            batch_size: String(batchSize),
          });
          if (r && r.success) markJsOk();
        } catch (err) {
          showError('iCal audit failed', err);
          if (icalReportStatus) icalReportStatus.textContent = 'Failed';
          setIcalReportBusy(false);
          icalReportBtn.disabled = false;
          return;
        }

        if (!r || !r.success) {
          showError('iCal audit failed', new Error('Unexpected response.'));
          if (icalReportStatus) icalReportStatus.textContent = 'Failed';
          setIcalReportBusy(false);
          icalReportBtn.disabled = false;
          return;
        }

        const data = r.data || {};
        const items = data.items || [];
        total = data.total || total;
        allItems = allItems.concat(items);
        if (data.log && data.log.length) appendIcalLog(data.log);

        offset = Number.isFinite(data.next_offset) ? data.next_offset : offset + items.length;
        done = !!data.done || offset >= total;

        if (icalReportStatus) {
          const checked = Math.min(offset, total);
          icalReportStatus.textContent = `Checked ${checked} / ${total}`;
        }

        if (!done) await sleep(200);
      }

      renderIcalReport(allItems);
      lastIcalReportItems = allItems;
      setIcalReportBusy(false);
      icalReportBtn.disabled = false;
    });
  }

  if (icalLinkBtn) {
    icalLinkBtn.addEventListener('click', async function (e) {
      e.preventDefault();
      const limit = icalLinkLimit ? parseInt(icalLinkLimit.value, 10) : 50;
      const batchSize = 10;
      let offset = 0;
      let allItems = [];
      let totals = { linked: 0, skipped: 0, missing_room: 0, no_icals: 0, errors: 0 };

      if (icalLinkStatus) icalLinkStatus.textContent = 'Running…';
      if (icalLinkLog) {
        icalLinkLog.textContent = '';
        icalLinkLog.style.display = 'none';
      }
      if (icalLinkTable) icalLinkTable.innerHTML = '';
      setIcalLinkBusy(true);
      icalLinkBtn.disabled = true;

      let done = false;
      let total = Number.isFinite(limit) ? limit : 50;
      while (!done) {
        let r = null;
        try {
          r = await post('hostfully_mphb_link_icals', {
            limit: Number.isFinite(limit) ? String(limit) : '50',
            offset: String(offset),
            batch_size: String(batchSize),
            replace_existing: icalLinkReplace && icalLinkReplace.checked ? '1' : '',
          });
          if (r && r.success) markJsOk();
        } catch (err) {
          showError('iCal link failed', err);
          if (icalLinkStatus) icalLinkStatus.textContent = 'Failed';
          setIcalLinkBusy(false);
          icalLinkBtn.disabled = false;
          return;
        }

        if (!r || !r.success) {
          showError('iCal link failed', new Error('Unexpected response.'));
          if (icalLinkStatus) icalLinkStatus.textContent = 'Failed';
          setIcalLinkBusy(false);
          icalLinkBtn.disabled = false;
          return;
        }

        const data = r.data || {};
        const items = data.items || [];
        total = data.total || total;
        allItems = allItems.concat(items);
        if (data.log && data.log.length) appendIcalLinkLog(data.log);

        totals.linked += data.linked || 0;
        totals.skipped += data.skipped || 0;
        totals.missing_room += data.missing_room || 0;
        totals.no_icals += data.no_icals || 0;
        totals.errors += data.errors || 0;

        offset = Number.isFinite(data.next_offset) ? data.next_offset : offset + items.length;
        done = !!data.done || offset >= total;

        if (icalLinkStatus) {
          const checked = Math.min(offset, total);
          icalLinkStatus.textContent =
            `Linked ${totals.linked} | Checked ${checked} / ${total}`;
        }

        if (!done) await sleep(200);
      }

      renderIcalLinkReport(allItems);
      setIcalLinkBusy(false);
      icalLinkBtn.disabled = false;
      showToast(`Linked ${totals.linked} rooms.`, 'success');
    });
  }

  if (icalReportCsvBtn) {
    icalReportCsvBtn.addEventListener('click', function (e) {
      e.preventDefault();
      if (!lastIcalReportItems.length) {
        showToast('Run the iCal audit first.', 'error');
        return;
      }
      const needsSetup = lastIcalReportItems.filter((item) => item.needs_setup);
      const csv = buildIcalCsv(needsSetup);
      const stamp = new Date().toISOString().slice(0, 10);
      downloadCsv(`hostfully-ical-needs-setup-${stamp}.csv`, csv);
      showToast(`Downloaded ${needsSetup.length} rows.`, 'success');
    });
  }

  const importOneDetails = document.getElementById('hostfully-step-one');
  if (importOneDetails) {
    importOneDetails.addEventListener('toggle', function () {
      if (importOneDetails.open && !propertiesLoaded) {
        loadProperties();
      }
    });
  }

  function loadDetailState() {
    if (!detailEls.length) return;
    let stored = null;
    try {
      stored = JSON.parse(localStorage.getItem(detailStorageKey) || 'null');
    } catch (err) {
      stored = null;
    }

    detailEls.forEach((el) => {
      const key = el.getAttribute('data-step') || el.id;
      if (stored && Object.prototype.hasOwnProperty.call(stored, key)) {
        el.open = !!stored[key];
        return;
      }
      el.open = nextActionStep && key === nextActionStep;
    });
  }

  function saveDetailState() {
    if (!detailEls.length) return;
    const state = {};
    detailEls.forEach((el) => {
      const key = el.getAttribute('data-step') || el.id;
      state[key] = !!el.open;
    });
    localStorage.setItem(detailStorageKey, JSON.stringify(state));
  }

  detailEls.forEach((el) => {
    el.addEventListener('toggle', saveDetailState);
  });

  loadDetailState();


  function appendLog(lines) {
    if (!Array.isArray(lines)) lines = [String(lines)];
    logEl.textContent += (logEl.textContent ? '\n' : '') + lines.join('\n');
    logEl.scrollTop = logEl.scrollHeight;
  }

  function formatDuration(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) return '';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    if (mins > 0) return `${mins}m ${secs}s`;
    return `${secs}s`;
  }

  function renderSummary(progress) {
    if (!summaryEl || !progress) return;
    const total = progress.total ?? '?';
    const done = progress.done ?? 0;
    const created = progress.created ?? 0;
    const updated = progress.updated ?? 0;
    const errors = progress.errors ?? 0;
    let duration = '';
    if (progress.started_at) {
      const now = Math.floor(Date.now() / 1000);
      duration = formatDuration(Math.max(0, now - progress.started_at));
      if (duration) duration = ` | Duration: ${duration}`;
    }
    summaryEl.textContent = `Summary: total ${total}, done ${done}, created ${created}, updated ${updated}, errors ${errors}${duration}`;
    summaryEl.style.display = 'block';
    if (counterEl) {
      counterEl.textContent = `(${done} / ${total})`;
    }
  }

  function resetCounter() {
    if (counterEl) counterEl.textContent = '';
  }

  function setBusy(isBusy) {
    if (!spinner) return;
    if (isBusy) {
      spinner.classList.add('is-active');
    } else {
      spinner.classList.remove('is-active');
    }
  }

  function markJsOk() {
    if (!jsIndicator) return;
    jsIndicator.textContent = 'JS status: ok';
    jsIndicator.style.display = 'none';
  }

  function logLastError(err) {
    if (!err || typeof err !== 'string') return;
    if (err === lastErrorSeen) return;
    lastErrorSeen = err;
    appendLog([`Last error: ${err}`]);
  }

  async function copyText(value) {
    if (!value) return false;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      try {
        await navigator.clipboard.writeText(value);
        return true;
      } catch (err) {
        // fall through
      }
    }

    const el = document.createElement('textarea');
    el.value = value;
    el.setAttribute('readonly', 'readonly');
    el.style.position = 'absolute';
    el.style.left = '-9999px';
    document.body.appendChild(el);
    el.select();
    try {
      const ok = document.execCommand('copy');
      document.body.removeChild(el);
      return ok;
    } catch (err) {
      document.body.removeChild(el);
      return false;
    }
  }

  async function post(action, payload = {}) {
    if (!window.HOSTFULLY_MPHB || !HOSTFULLY_MPHB.ajax_url) {
      throw new Error('HOSTFULLY_MPHB settings are missing. Script localization failed.');
    }

    const res = await fetch(HOSTFULLY_MPHB.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      credentials: 'same-origin',
      cache: 'no-store',
      body: new URLSearchParams({
        action,
        nonce: HOSTFULLY_MPHB.nonce,
        ...payload,
      }),
    });

    const text = await res.text();
    let json = null;
    try {
      json = text ? JSON.parse(text) : null;
    } catch (err) {
      const snippet = text ? text.slice(0, 300) : '(empty response)';
      throw new Error(`Non-JSON response (HTTP ${res.status}). ${snippet}`);
    }

    if (!res.ok) {
      json = json || {};
      json._http_status = res.status;
      json._http_text = text ? text.slice(0, 300) : '';
    }

    return json;
  }

  async function fetchLastError() {
    try {
      const r = await post('hostfully_mphb_get_last_error');
      if (r && r.success && r.data && r.data.last_error) {
        markJsOk();
        logLastError(r.data.last_error);
      }
    } catch (err) {
      // swallow
    }
  }

  function showError(context, err) {
    wrap.style.display = 'block';
    statusEl.textContent = 'Error';
    setBusy(false);
    resetCounter();
    const msg = err && err.message ? err.message : String(err);
    appendLog(['—', `${context}: ${msg}`]);
    if (err && err.stack) appendLog([err.stack]);
    fetchLastError();
  }

  async function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  async function tickLoop() {
    while (!stopped) {
      statusEl.textContent = 'Importing next property…';
      setBusy(true);

      let r = null;
      try {
        r = await post('hostfully_mphb_bulk_tick');
      } catch (err) {
        showError('Bulk tick failed', err);
        stopBtn.disabled = true;
        startBtn.disabled = false;
        return;
      }

      if (!r || !r.success) {
        statusEl.textContent = 'Error';
        setBusy(false);
        appendLog(['Bulk tick failed.', JSON.stringify(r)]);
        stopBtn.disabled = true;
        startBtn.disabled = false;
        return;
      }

      const d = r.data || {};
      if (d.progress) renderSummary(d.progress);
      if (r && r.success) markJsOk();
      appendLog([
        '—',
        `Imported UID: ${d.uid}`,
        `Room Type ID: ${d.post_id || '(failed)'}`,
        `Remaining: ${d.remaining ?? '?'}`,
        ...(d.log || []),
      ]);
      if (d.last_error) logLastError(d.last_error);

      if (d.done) {
        statusEl.textContent = 'Bulk import complete ✅';
        setBusy(false);
        if (d.progress) renderSummary(d.progress);
        stopBtn.disabled = true;
        startBtn.disabled = false;
        return;
      }

      // don’t DDOS your own admin-ajax
      await sleep(250);
    }

    statusEl.textContent = 'Stopped.';
    setBusy(false);
    stopBtn.disabled = true;
    startBtn.disabled = false;
  }

  startBtn.addEventListener('click', async function () {
    stopped = false;
    wrap.style.display = 'block';
    logEl.textContent = '';
    setBusy(true);
    resetCounter();
    if (summaryEl) {
      summaryEl.textContent = '';
      summaryEl.style.display = 'none';
    }
    statusEl.textContent = 'Preparing queue…';

    startBtn.disabled = true;
    stopBtn.disabled = false;

    let r = null;
    try {
      r = await post('hostfully_mphb_bulk_start', {
        update_existing: bulkUpdateExisting && bulkUpdateExisting.checked ? '1' : '0',
        update_slugs: bulkUpdateSlugs && bulkUpdateSlugs.checked ? '1' : '0',
      });
      if (r && r.success) markJsOk();
    } catch (err) {
      showError('Bulk start failed', err);
      stopBtn.disabled = true;
      startBtn.disabled = false;
      return;
    }

    if (!r || !r.success) {
      statusEl.textContent = 'Error';
      setBusy(false);
      resetCounter();
      appendLog(['Bulk start failed.', JSON.stringify(r)]);
      stopBtn.disabled = true;
      startBtn.disabled = false;
      return;
    }

    const total = (r.data && r.data.total) || 0;
    const propertiesTotal = (r.data && r.data.properties_total) || 0;
    if (r.data && Array.isArray(r.data.log) && r.data.log.length) {
      appendLog(r.data.log);
    }
    appendLog([
      `Properties fetched: ${propertiesTotal}`,
      `Queue prepared. Total to import: ${total}`,
    ]);
    if (r.data && r.data.last_error) logLastError(r.data.last_error);

    if (total === 0) {
      statusEl.textContent = 'Nothing to import.';
      setBusy(false);
      resetCounter();
      stopBtn.disabled = true;
      startBtn.disabled = false;
      return;
    }

    await tickLoop();
  });

  if (migrateBtn) {
    migrateBtn.addEventListener('click', async function (e) {
      e.preventDefault();
      const ok = window.confirm('Migration will update all imported properties to apply the latest mapping rules. Continue?');
      if (!ok) return;
      const updateSlugsOpt = document.getElementById('hostfully-migrate-update-slugs');
      const updateSlugs = updateSlugsOpt && updateSlugsOpt.checked ? '1' : '0';
      stopped = false;
      wrap.style.display = 'block';
      logEl.textContent = '';
      setBusy(true);
      resetCounter();
      if (summaryEl) {
        summaryEl.textContent = '';
        summaryEl.style.display = 'none';
      }
      statusEl.textContent = 'Preparing migration queue…';

      migrateBtn.disabled = true;
      stopBtn.disabled = false;
      startBtn.disabled = true;
      if (uidStartBtn) uidStartBtn.disabled = true;

      let r = null;
      try {
        r = await post('hostfully_mphb_migrate_start', {
          update_slugs: updateSlugs,
        });
        if (r && r.success) markJsOk();
      } catch (err) {
        showError('Migration start failed', err);
        stopBtn.disabled = true;
        startBtn.disabled = false;
        migrateBtn.disabled = false;
        if (uidStartBtn) uidStartBtn.disabled = false;
        return;
      }

      if (!r || !r.success) {
        statusEl.textContent = 'Error';
        setBusy(false);
        resetCounter();
        appendLog(['Migration start failed.', JSON.stringify(r)]);
        stopBtn.disabled = true;
        startBtn.disabled = false;
        migrateBtn.disabled = false;
        if (uidStartBtn) uidStartBtn.disabled = false;
        return;
      }

      const total = (r.data && r.data.total) || 0;
      if (r.data && Array.isArray(r.data.log) && r.data.log.length) {
        appendLog(r.data.log);
      }
      appendLog([`Migration queue prepared. Total to update: ${total}`]);
      if (r.data && r.data.last_error) logLastError(r.data.last_error);

      if (total === 0) {
        statusEl.textContent = 'Nothing to migrate.';
        setBusy(false);
        resetCounter();
        stopBtn.disabled = true;
        startBtn.disabled = false;
        migrateBtn.disabled = false;
        if (uidStartBtn) uidStartBtn.disabled = false;
        return;
      }

      await tickLoop();
      migrateBtn.disabled = false;
      if (uidStartBtn) uidStartBtn.disabled = false;
    });
  }

  if (cleanupBtn) {
    cleanupBtn.addEventListener('click', async function (e) {
      e.preventDefault();
      const payload = {
        cleanup_terms: '1',
        cleanup_orphan_rates: '1',
        cleanup_orphan_rooms: '1',
        cleanup_orphan_services: '1',
        cleanup_orphan_media: '1',
        cleanup_attr_reg: '1',
        unpublish_missing: '0',
      };

      const ok = window.confirm('Cleanup will remove or unpublish data based on the selected options. Continue?');
      if (!ok) return;
      wrap.style.display = 'block';
      logEl.textContent = '';
      setBusy(true);
      resetCounter();
      statusEl.textContent = 'Cleaning unused terms…';

      cleanupBtn.disabled = true;

      let r = null;
      try {
        r = await post('hostfully_mphb_cleanup_terms', payload);
        if (r && r.success) markJsOk();
      } catch (err) {
        showError('Cleanup failed', err);
        cleanupBtn.disabled = false;
        return;
      }

      if (!r || !r.success) {
        statusEl.textContent = 'Cleanup error';
        setBusy(false);
        appendLog(['Cleanup failed.', JSON.stringify(r)]);
        cleanupBtn.disabled = false;
        return;
      }

      const d = r.data || {};
      appendLog([...(d.log || [])]);
      statusEl.textContent = 'Cleanup complete ✅';
      setBusy(false);
      cleanupBtn.disabled = false;
    });
  }


  const syncAmenitiesBtn = document.getElementById('hostfully-sync-amenities');
  const syncPropertyFeesBtn = document.getElementById('hostfully-sync-property-fees');
  const syncGuestCapacityBtn = document.getElementById('hostfully-sync-guest-capacity');
  if (syncAmenitiesBtn) {
    syncAmenitiesBtn.addEventListener('click', async function (e) {
      e.preventDefault();
      wrap.style.display = 'block';
      statusEl.textContent = 'Syncing amenities catalog…';
      appendLog(['—', 'Starting amenities catalog sync…']);
      setBusy(true);

      syncAmenitiesBtn.disabled = true;

      let r = null;
      try {
        r = await post('hostfully_mphb_sync_amenities');
        if (r && r.success) markJsOk();
      } catch (err) {
        showError('Amenity sync failed', err);
        syncAmenitiesBtn.disabled = false;
        return;
      }
      if (!r || !r.success) {
        statusEl.textContent = 'Amenity sync error';
        setBusy(false);
        appendLog(['Amenity sync failed.', JSON.stringify(r)]);
        syncAmenitiesBtn.disabled = false;
        return;
      }

      const d = r.data || {};
      appendLog([...(d.log || [])]);
      const total = (d.result && d.result.total) ? d.result.total : 0;
      statusEl.textContent = `Amenities synced ✅ (processed: ${total})`;
      setBusy(false);
      syncAmenitiesBtn.disabled = false;
    });
  }

  if (syncPropertyFeesBtn) {
    syncPropertyFeesBtn.addEventListener('click', async function (e) {
      e.preventDefault();
      wrap.style.display = 'block';
      statusEl.textContent = 'Syncing property fees…';
      appendLog(['—', 'Starting property fee sync…']);
      setBusy(true);

      syncPropertyFeesBtn.disabled = true;

      const batchSize = 10;
      let offset = 0;
      let total = 0;
      let updated = 0;
      let skipped = 0;
      let errors = 0;
      let done = false;

      while (!done) {
        let r = null;
        try {
          r = await post('hostfully_mphb_sync_property_fees', {
            offset: String(offset),
            batch_size: String(batchSize),
          });
          if (r && r.success) markJsOk();
        } catch (err) {
          showError('Property fee sync failed', err);
          syncPropertyFeesBtn.disabled = false;
          return;
        }
        if (!r || !r.success) {
          statusEl.textContent = 'Property fee sync error';
          setBusy(false);
          appendLog(['Property fee sync failed.', JSON.stringify(r)]);
          syncPropertyFeesBtn.disabled = false;
          return;
        }

        const d = r.data || {};
        const result = d.result || {};
        appendLog([...(d.log || [])]);

        total = Number.isFinite(result.total) ? result.total : total;
        updated += Number.isFinite(result.updated) ? result.updated : 0;
        skipped += Number.isFinite(result.skipped) ? result.skipped : 0;
        errors += Number.isFinite(result.errors) ? result.errors : 0;
        offset = Number.isFinite(result.next_offset) ? result.next_offset : offset + batchSize;
        done = !!result.done || (total > 0 && offset >= total);

        if (statusEl) {
          const checked = total > 0 ? Math.min(offset, total) : offset;
          statusEl.textContent = `Syncing property fees… ${checked}${total ? ` / ${total}` : ''}`;
        }

        if (!done) await sleep(200);
      }

      statusEl.textContent = `Property fees synced ✅ (${updated} updated, ${skipped} skipped, ${errors} errors)`;
      setBusy(false);
      syncPropertyFeesBtn.disabled = false;
    });
  }

  if (syncGuestCapacityBtn) {
    syncGuestCapacityBtn.addEventListener('click', async function (e) {
      e.preventDefault();
      wrap.style.display = 'block';
      statusEl.textContent = 'Syncing guest capacity…';
      appendLog(['—', 'Starting guest capacity sync…']);
      setBusy(true);

      syncGuestCapacityBtn.disabled = true;

      let r = null;
      try {
        r = await post('hostfully_mphb_sync_guest_capacity');
        if (r && r.success) markJsOk();
      } catch (err) {
        showError('Guest capacity sync failed', err);
        syncGuestCapacityBtn.disabled = false;
        return;
      }
      if (!r || !r.success) {
        statusEl.textContent = 'Guest capacity sync error';
        setBusy(false);
        appendLog(['Guest capacity sync failed.', JSON.stringify(r)]);
        syncGuestCapacityBtn.disabled = false;
        return;
      }

      const d = r.data || {};
      appendLog([...(d.log || [])]);
      const updated = (d.result && Number.isFinite(d.result.updated)) ? d.result.updated : 0;
      const scanned = (d.result && Number.isFinite(d.result.scanned)) ? d.result.scanned : 0;
      statusEl.textContent = `Guest capacity synced ✅ (${updated}/${scanned} updated)`;
      setBusy(false);
      syncGuestCapacityBtn.disabled = false;
    });
  }

  document.addEventListener('click', async function (e) {
    const btn = e.target && e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!btn) return;
    const val = btn.getAttribute('data-copy') || '';
    const ok = await copyText(val);
    const prev = btn.textContent;
    btn.textContent = ok ? 'Copied' : 'Failed';
    btn.disabled = true;
    setTimeout(() => {
      btn.textContent = prev;
      btn.disabled = false;
    }, 1200);
  });


  if (importOneForm) {
    importOneForm.addEventListener('submit', async function (e) {
      e.preventDefault();

      if (!propertySelect || !propertySelect.value) {
        showError('Single import', new Error('Please select a property to import.'));
        return;
      }

      wrap.style.display = 'block';
      logEl.textContent = '';
      setBusy(true);
      resetCounter();
      if (importOneStatus) importOneStatus.textContent = 'Importing…';
      if (importOneSpinner) importOneSpinner.classList.add('is-active');
      if (summaryEl) {
        summaryEl.textContent = '';
        summaryEl.style.display = 'none';
      }
      statusEl.textContent = 'Importing selected property…';

      if (importOneBtn) importOneBtn.disabled = true;

      let r = null;
      try {
        r = await post('hostfully_mphb_import_one', {
          property_uid: propertySelect.value,
          update_existing: updateExistingOne && updateExistingOne.checked ? '1' : '0',
        });
        if (r && r.success) markJsOk();
      } catch (err) {
        showError('Single import failed', err);
        if (importOneStatus) importOneStatus.textContent = '';
        if (importOneSpinner) importOneSpinner.classList.remove('is-active');
        if (importOneBtn) importOneBtn.disabled = false;
        return;
      }

      if (!r || !r.success) {
        statusEl.textContent = 'Single import failed';
        setBusy(false);
        resetCounter();
        appendLog(['Single import failed.', JSON.stringify(r)]);
        if (importOneStatus) importOneStatus.textContent = '';
        if (importOneSpinner) importOneSpinner.classList.remove('is-active');
        if (importOneBtn) importOneBtn.disabled = false;
        return;
      }

      const d = r.data || {};
      appendLog([
        '—',
        `Imported UID: ${d.uid}`,
        `Room Type ID: ${d.post_id || '(failed)'}`,
        ...(d.log || []),
      ]);

      if (d.progress) renderSummary(d.progress);
      if (d.last_error) logLastError(d.last_error);

      statusEl.textContent = d.post_id ? 'Single import complete ✅' : 'Single import failed';
      setBusy(false);
      if (importOneStatus) {
        importOneStatus.textContent = d.post_id ? 'Done ✅' : 'Failed';
      }
      if (importOneSpinner) importOneSpinner.classList.remove('is-active');
      if (importOneBtn) importOneBtn.disabled = false;
    });
  }

  if (uidForm && uidStartBtn && uidTextarea) {
    if (uidCompareBtn) {
      uidCompareBtn.addEventListener('click', async function (e) {
        e.preventDefault();
        const raw = uidTextarea.value || '';
        if (!raw.trim()) {
          showError('UID compare', new Error('Please paste at least one Hostfully property UID.'));
          return;
        }
        let r = null;
        try {
          r = await post('hostfully_mphb_get_imported_uids');
          if (r && r.success) markJsOk();
        } catch (err) {
          showError('UID compare failed', err);
          return;
        }
        if (!r || !r.success) {
          appendLog(['UID compare failed.', JSON.stringify(r)]);
          return;
        }
        const imported = new Set((r.data && r.data.uids) || []);
        const pasted = [];
        const re = /[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/g;
        const matches = raw.match(re) || [];
        const seen = new Set();
        matches.forEach((uid) => {
          const lower = uid.toLowerCase();
          if (!seen.has(lower)) {
            seen.add(lower);
            pasted.push(lower);
          }
        });

        const missing = pasted.filter((uid) => !imported.has(uid));
        if (uidMissingTextarea) {
          uidMissingTextarea.value = missing.join('\n');
        }
        appendLog([
          `UID compare: pasted ${pasted.length}, imported ${imported.size}, missing ${missing.length}`,
        ]);
      });
    }

    if (uidUseMissingBtn && uidMissingTextarea) {
      uidUseMissingBtn.addEventListener('click', function (e) {
        e.preventDefault();
        const missing = uidMissingTextarea.value || '';
        if (missing.trim()) {
          uidTextarea.value = missing.trim();
        }
      });
    }

    uidForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      const raw = uidTextarea.value || '';
      if (!raw.trim()) {
        showError('UID import', new Error('Please paste at least one Hostfully property UID.'));
        return;
      }

      stopped = false;
      wrap.style.display = 'block';
      logEl.textContent = '';
      setBusy(true);
      resetCounter();
      if (summaryEl) {
        summaryEl.textContent = '';
        summaryEl.style.display = 'none';
      }
      statusEl.textContent = 'Preparing UID queue…';

      uidStartBtn.disabled = true;
      stopBtn.disabled = false;
      startBtn.disabled = true;

      let r = null;
      try {
        r = await post('hostfully_mphb_uid_queue_start', {
          uids_raw: raw,
          update_existing: uidUpdateExisting && uidUpdateExisting.checked ? '1' : '0',
        });
        if (r && r.success) markJsOk();
      } catch (err) {
        showError('UID queue start failed', err);
        uidStartBtn.disabled = false;
        stopBtn.disabled = true;
        startBtn.disabled = false;
        return;
      }

      if (!r || !r.success) {
        statusEl.textContent = 'Error';
        setBusy(false);
        resetCounter();
        appendLog(['UID queue start failed.', JSON.stringify(r)]);
        uidStartBtn.disabled = false;
        stopBtn.disabled = true;
        startBtn.disabled = false;
        return;
      }

      const total = (r.data && r.data.total) || 0;
      if (r.data && Array.isArray(r.data.log) && r.data.log.length) {
        appendLog(r.data.log);
      }
      appendLog([`Queue prepared. Total to import: ${total}`]);
      if (r.data && r.data.last_error) logLastError(r.data.last_error);

      if (total === 0) {
        statusEl.textContent = 'Nothing to import.';
        setBusy(false);
        resetCounter();
        stopBtn.disabled = true;
        startBtn.disabled = false;
        uidStartBtn.disabled = false;
        return;
      }

      await tickLoop();
      uidStartBtn.disabled = false;
    });
  }


  stopBtn.addEventListener('click', function () {
    stopped = true;
    setBusy(false);
    resetCounter();
    stopBtn.disabled = true;
    startBtn.disabled = false;
  });
})();
