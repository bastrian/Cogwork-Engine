(() => {
  const i18n = window.ModrightI18n || {};
  const databaseForm = document.querySelector('[data-database-form]');
  if (databaseForm) {
    const driver = databaseForm.querySelector('[data-database-driver]');
    const sqlite = databaseForm.querySelector('[data-sqlite-settings]');
    const mysql = databaseForm.querySelector('[data-mysql-settings]');
    const updateDatabaseFields = () => {
      const usesMysql = driver.value === 'mysql';
      sqlite.hidden = usesMysql;
      mysql.hidden = !usesMysql;
      mysql.querySelectorAll('input').forEach(input => { input.disabled = !usesMysql; });
      sqlite.querySelectorAll('input').forEach(input => { input.disabled = usesMysql; });
    };
    driver.addEventListener('change', updateDatabaseFields);
    updateDatabaseFields();
  }

  const statusBadge = document.querySelector('[data-modrinth-status]');
  if (statusBadge) {
    fetch(statusBadge.dataset.statusUrl, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
      .then(response => {
        if (!response.ok) throw new Error('Status request failed');
        return response.json();
      })
      .then(status => {
        statusBadge.classList.remove('unknown', 'up', 'issues');
        statusBadge.classList.add(['up', 'issues'].includes(status.state) ? status.state : 'unknown');
        statusBadge.querySelector('[data-status-label]').textContent = status.label;
        statusBadge.title = `Checked ${new Date(status.checked_at).toLocaleTimeString()}`;
      })
      .catch(() => {
        statusBadge.querySelector('[data-status-label]').textContent = i18n.status_unknown || 'Status unknown';
        statusBadge.title = i18n.status_failed || 'Could not reach the status service';
      });
  }

  const packForm = document.querySelector('[data-pack-create]');
  if (packForm) {
    const minecraft = packForm.querySelector('[data-minecraft-version]');
    const loader = packForm.querySelector('[data-loader]');
    const loaderVersion = packForm.querySelector('[data-loader-version]');
    const message = packForm.querySelector('[data-version-message]');
    const selectedMinecraft = packForm.dataset.selectedMinecraft;
    const selectedLoaderVersion = packForm.dataset.selectedLoaderVersion;
    let requestNumber = 0;

    const getVersions = async url => {
      const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || `Catalog request failed (${response.status})`);
      return data;
    };
    const fill = (select, versions, selected, placeholder) => {
      select.replaceChildren(new Option(placeholder, ''));
      versions.forEach(version => select.add(new Option(version, version, false, version === selected)));
    };
    const loadLoaderVersions = async () => {
      const currentRequest = ++requestNumber;
      loaderVersion.disabled = true;
      fill(loaderVersion, [], '', minecraft.value ? 'Loading compatible versions…' : 'Select Minecraft and loader…');
      if (!minecraft.value) return;
      message.textContent = 'Loading compatible loader versions…';
      try {
        const url = new URL(loaderVersion.dataset.catalogUrl, location.href);
        url.searchParams.set('loader', loader.value);
        url.searchParams.set('minecraft', minecraft.value);
        const result = await getVersions(url);
        const versions = result.versions;
        if (currentRequest !== requestNumber) return;
        fill(loaderVersion, versions, selectedLoaderVersion, versions.length ? 'Choose a loader version' : 'No compatible versions found');
        loaderVersion.disabled = versions.length === 0;
        message.textContent = result.notice || (versions.length ? `${versions.length} compatible ${loader.value} version${versions.length === 1 ? '' : 's'} available.` : 'No compatible loader versions are available for this Minecraft release.');
      } catch (error) {
        if (currentRequest !== requestNumber) return;
        message.textContent = error.message;
      }
    };
    minecraft.addEventListener('change', loadLoaderVersions);
    loader.addEventListener('change', loadLoaderVersions);
    getVersions(minecraft.dataset.catalogUrl)
      .then(result => {
        const versions = result.versions;
        fill(minecraft, versions, selectedMinecraft, 'Choose a Minecraft release');
        minecraft.disabled = false;
        if (result.notice) message.textContent = result.notice;
        return loadLoaderVersions();
      })
      .catch(error => {
        fill(minecraft, [], '', 'Could not load releases');
        minecraft.disabled = true;
        message.textContent = error.message;
      });
  }

  const modTable = document.querySelector('[data-mod-table]');
  if (modTable) {
    const rows = [...modTable.querySelectorAll('[data-mod-row]')];
    const search = modTable.querySelector('[data-mod-search]');
    const filter = modTable.querySelector('[data-mod-filter]');
    const count = modTable.querySelector('[data-mod-count]');
    const previous = modTable.querySelector('[data-page-prev]');
    const next = modTable.querySelector('[data-page-next]');
    const label = modTable.querySelector('[data-page-label]');
    const pageSize = 25;
    let page = 1;
    const render = () => {
      const term = search.value.trim().toLowerCase();
      const matches = rows.filter(row => {
        const environment = filter.value;
        const environmentMatch = environment === 'all' ||
          (environment === 'server' && row.dataset.server !== 'unsupported') ||
          (environment === 'client-only' && row.dataset.server === 'unsupported' && row.dataset.client !== 'unsupported') ||
          (environment === 'server-only' && row.dataset.client === 'unsupported' && row.dataset.server !== 'unsupported');
        return environmentMatch && (!term || row.dataset.search.includes(term));
      });
      const pages = Math.max(1, Math.ceil(matches.length / pageSize));
      page = Math.min(page, pages);
      const visible = new Set(matches.slice((page - 1) * pageSize, page * pageSize));
      rows.forEach(row => { row.hidden = !visible.has(row); });
      count.textContent = `${matches.length} / ${rows.length} ${rows.length === 1 ? (i18n.mods_one || 'mod').replace(/^\d+\s*/, '') : (i18n.mods_many || 'mods').replace(/^\d+\s*/, '')}`;
      label.textContent = `Page ${page} of ${pages}`;
      previous.disabled = page <= 1;
      next.disabled = page >= pages;
    };
    search.addEventListener('input', () => { page = 1; render(); });
    filter.addEventListener('change', () => { page = 1; render(); });
    previous.addEventListener('click', () => { page--; render(); });
    next.addEventListener('click', () => { page++; render(); });
    render();
  }

  const job = window.ModrightJob;
  if (!job) return;
  const formatElapsed = seconds => {
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;
    return minutes ? `${minutes}m ${remainder}s` : `${remainder}s`;
  };
  const renderJob = data => {
    const current = Number(data.progress_current);
    const total = Number(data.progress_total);
    const percent = total ? Math.floor(current / total * 100) : 0;
    document.querySelector('#status').textContent = data.status;
    const progress = document.querySelector('#progress');
    progress.max = Math.max(1, total); progress.value = current;
    document.querySelector('#job-percent').textContent = `${percent}%`;
    document.querySelector('#job-count').textContent = `${current} / ${total}`;
    document.querySelector('#job-remaining').textContent = Math.max(0, total - current);
    document.querySelector('#job-elapsed').textContent = formatElapsed(Math.max(0, Math.floor((Date.now() - new Date(job.createdAt).getTime()) / 1000)));
    const details = data.result_data || {};
    document.querySelector('#job-action').textContent = details.next_file ? (i18n.processing_next || 'Processing next') : (details.current_action || i18n.preparing || 'Preparing…');
    document.querySelector('#job-file').textContent = details.next_file || details.current_file || '';
    const activity = document.querySelector('#job-activity');
    if (Array.isArray(details.activity) && details.activity.length) {
      activity.replaceChildren(...details.activity.map(item => {
        const li = document.createElement('li');
        const name = document.createElement('strong'); name.textContent = item.file || '';
        const action = document.createElement('span'); action.textContent = (item.action_code && i18n[item.action_code]) || item.action || i18n.processed || 'Processed';
        li.append(name, action); return li;
      }));
    }
    document.querySelector('#error').textContent = data.error || '';
  };
  const elapsedTimer = setInterval(() => {
    const target = document.querySelector('#job-elapsed');
    if (target) target.textContent = formatElapsed(Math.max(0, Math.floor((Date.now() - new Date(job.createdAt).getTime()) / 1000)));
  }, 1000);
  const run = async () => {
    try {
      const body = new URLSearchParams({id: job.id, _csrf: job.csrf});
      const response = await fetch(job.url, {method: 'POST', body, credentials: 'same-origin', headers: {'Accept':'application/json'}});
      if (!response.ok) throw new Error(`Request failed (${response.status})`);
      const data = await response.json();
      renderJob(data);
      if (data.status === 'queued' || data.status === 'running') setTimeout(run, 350);
      else { clearInterval(elapsedTimer); location.reload(); }
    } catch (error) {
      document.querySelector('#error').textContent = `${error.message}. Retrying…`;
      setTimeout(run, 3000);
    }
  };
  run();
})();
