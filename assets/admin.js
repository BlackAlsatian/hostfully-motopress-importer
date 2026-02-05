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

  if (jsIndicator) {
    jsIndicator.textContent = 'JS status: loaded';
  }

  if (!startBtn || !stopBtn || !wrap || !statusEl || !logEl) {
    if (jsIndicator) {
      jsIndicator.textContent = 'JS status: loaded, but UI elements missing.';
    }
    return;
  }

  let stopped = false;
  let lastErrorSeen = null;


  const bulkUpdateExisting = document.getElementById('hostfully-bulk-update-existing');
  const updateExistingOne = document.querySelector('input[name="update_existing"]');
  const propertySelect = document.querySelector('select[name="property_uid"]');
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


  const syncAmenitiesBtn = document.getElementById('hostfully-sync-amenities');
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
