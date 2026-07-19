(() => {
  const i18n = window.ModrightI18n || {};
  const jsonResponse = async response => {
    const text = await response.text();
    try { return JSON.parse(text); }
    catch { throw new Error(response.ok ? 'The server returned an invalid response.' : `The request failed (${response.status}). Please reload the page and try again.`); }
  };
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

  document.querySelectorAll('[data-feature-search]').forEach(featureSearch => featureSearch.addEventListener('input', () => {
    const query = featureSearch.value.trim().toLowerCase();
    const scope = featureSearch.closest('details') || document;
    scope.querySelectorAll('[data-feature-reference]').forEach(item => { item.hidden = query !== '' && !item.dataset.search.includes(query); });
  }));

  const accountMenu = document.querySelector('[data-account-menu]');
  if (accountMenu) {
    document.addEventListener('click', event => { if (!accountMenu.contains(event.target)) accountMenu.open = false; });
    accountMenu.addEventListener('keydown', event => {
      if (event.key === 'Escape') { accountMenu.open = false; accountMenu.querySelector('summary').focus(); }
      if (!['ArrowDown', 'ArrowUp'].includes(event.key) || !accountMenu.open) return;
      event.preventDefault(); const items = [...accountMenu.querySelectorAll('a, button, select')]; const index = items.indexOf(document.activeElement); items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
    });
  }

  document.querySelectorAll('[data-confirm]').forEach(control => control.addEventListener('click', event => {
    if (!window.confirm(control.dataset.confirm)) event.preventDefault();
  }));
  document.querySelectorAll('[data-print]').forEach(control => control.addEventListener('click', () => window.print()));

  document.querySelectorAll('[data-recaptcha-form]').forEach(form => form.addEventListener('submit', event => {
    if (form.dataset.recaptchaSubmitted === '1') return;
    event.preventDefault(); const button = form.querySelector('button[type="submit"], button:not([type])'); if (button) button.disabled = true;
    if (!window.grecaptcha) { if (button) button.disabled = false; return; }
    grecaptcha.ready(() => grecaptcha.execute(form.dataset.recaptchaSiteKey, {action: form.dataset.recaptchaAction}).then(token => { form.querySelector('[data-recaptcha-token]').value = token; form.dataset.recaptchaSubmitted = '1'; form.requestSubmit(); }).catch(() => { if (button) button.disabled = false; }));
  }));

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

  const passkeyButton = document.querySelector('[data-passkey-register]');
  if (passkeyButton) {
    const message = document.querySelector('[data-passkey-message]');
    const labelInput = document.querySelector('[data-passkey-label]');
    const decode = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4)), character => character.charCodeAt(0));
    const encode = value => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    passkeyButton.addEventListener('click', async () => {
      if (!window.PublicKeyCredential || !navigator.credentials) { message.textContent = 'This browser does not support passkeys.'; return; }
      passkeyButton.disabled = true; message.textContent = 'Waiting for your authenticator…';
      try {
        const beginBody = new URLSearchParams({_csrf: passkeyButton.dataset.csrf});
        const beginResponse = await fetch(passkeyButton.dataset.beginUrl, {method: 'POST', body: beginBody, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const request = await jsonResponse(beginResponse); if (!beginResponse.ok) throw new Error(request.error || 'Could not start passkey registration.');
        const options = request.publicKey; options.challenge = decode(options.challenge); options.user.id = decode(options.user.id); options.excludeCredentials = (options.excludeCredentials || []).map(item => ({...item, id: decode(item.id)}));
        const credential = await navigator.credentials.create({publicKey: options}); if (!credential) throw new Error('Registration was cancelled.');
        const response = {id: credential.id, type: credential.type, rawId: encode(credential.rawId), response: {clientDataJSON: encode(credential.response.clientDataJSON), attestationObject: encode(credential.response.attestationObject), transports: credential.response.getTransports ? credential.response.getTransports() : []}, clientExtensionResults: credential.getClientExtensionResults()};
        const label = (labelInput && labelInput.value.trim()) || 'Passkey';
        const finishBody = new URLSearchParams({_csrf: passkeyButton.dataset.csrf, challenge_id: request.id, credential: JSON.stringify(response), label});
        const finishResponse = await fetch(passkeyButton.dataset.finishUrl, {method: 'POST', body: finishBody, credentials: 'same-origin', headers: {'Accept': 'application/json'}}); const result = await jsonResponse(finishResponse); if (!finishResponse.ok || !result.ok) throw new Error(result.error || 'Passkey registration failed.');
        location.href = result.redirect || location.href;
      } catch (error) { message.textContent = error.name === 'NotAllowedError' ? 'Passkey registration was cancelled or timed out.' : error.message; passkeyButton.disabled = false; }
    });
  }

  const passkeyAuth = document.querySelector('[data-passkey-auth]');
  if (passkeyAuth) {
    const message = document.querySelector('[data-passkey-auth-message]'); const decode = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4)), character => character.charCodeAt(0)); const encode = value => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    passkeyAuth.addEventListener('click', async () => { passkeyAuth.disabled = true; message.textContent = 'Waiting for your authenticator…'; try { const beginResponse = await fetch(passkeyAuth.dataset.beginUrl, {method:'POST',body:new URLSearchParams({_csrf:passkeyAuth.dataset.csrf}),credentials:'same-origin',headers:{Accept:'application/json'}}); const request=await jsonResponse(beginResponse); if(!beginResponse.ok)throw new Error(request.error||'Could not start passkey authentication.'); const options=request.publicKey;options.challenge=decode(options.challenge);options.allowCredentials=(options.allowCredentials||[]).map(item=>({...item,id:decode(item.id)}));const credential=await navigator.credentials.get({publicKey:options});const response={id:credential.id,type:credential.type,rawId:encode(credential.rawId),response:{clientDataJSON:encode(credential.response.clientDataJSON),authenticatorData:encode(credential.response.authenticatorData),signature:encode(credential.response.signature),userHandle:credential.response.userHandle?encode(credential.response.userHandle):null},clientExtensionResults:credential.getClientExtensionResults()};const finishResponse=await fetch(passkeyAuth.dataset.finishUrl,{method:'POST',body:new URLSearchParams({_csrf:passkeyAuth.dataset.csrf,challenge_id:request.id,credential:JSON.stringify(response)}),credentials:'same-origin',headers:{Accept:'application/json'}});const result=await jsonResponse(finishResponse);if(!finishResponse.ok||!result.ok)throw new Error(result.error||'Passkey authentication failed.');location.href=result.redirect;}catch(error){message.textContent=error.name==='NotAllowedError'?'Passkey authentication was cancelled or timed out.':error.message;passkeyAuth.disabled=false;} });
  }

  const tour = i18n.tour;
  if (tour) {
    const target = document.querySelector(tour.selector);
    const labels = tour.labels || {};
    const backdrop = document.createElement('div'); backdrop.className = 'tour-backdrop'; backdrop.setAttribute('aria-hidden', 'true');
    const panel = document.createElement('section'); panel.className = 'tour-panel'; panel.tabIndex = -1; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'false'); panel.setAttribute('aria-labelledby', 'tour-title'); panel.setAttribute('aria-describedby', 'tour-description');
    const progress = document.createElement('span'); progress.className = 'tour-progress'; progress.textContent = (labels.step || 'Step {current} of {total}').replace('{current}', String(Number(tour.step) + 1)).replace('{total}', String(tour.total));
    const title = document.createElement('h2'); title.id = 'tour-title'; title.textContent = tour.title;
    const description = document.createElement('p'); description.id = 'tour-description'; description.textContent = target ? tour.text : `${tour.text} ${labels.missing || ''}`;
    const actions = document.createElement('div'); actions.className = 'tour-actions';
    const skip = document.createElement('button'); skip.type = 'button'; skip.className = 'link'; skip.textContent = labels.skip || 'Skip tutorial';
    const previous = document.createElement('button'); previous.type = 'button'; previous.className = 'secondary'; previous.textContent = labels.previous || 'Previous'; previous.hidden = Number(tour.step) === 0;
    const next = document.createElement('button'); next.type = 'button'; next.textContent = tour.last ? (labels.finish || 'Finish') : (labels.next || 'Next');
    actions.append(skip, previous, next); panel.append(progress, title, description, actions); document.body.append(backdrop, panel);

    const update = async action => {
      panel.classList.add('is-busy'); panel.querySelectorAll('button').forEach(button => { button.disabled = true; });
      const response = await fetch(tour.update_url, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json'}, body: new URLSearchParams({_csrf: tour.csrf, step: tour.step, action, json: '1'})});
      if (!response.ok) throw new Error(`Tutorial update failed (${response.status})`);
    };
    const move = async (action, url) => { try { await update(action); location.href = url; } catch (error) { description.textContent = error.message; panel.classList.remove('is-busy'); panel.querySelectorAll('button').forEach(button => { button.disabled = false; }); } };
    skip.addEventListener('click', () => move('skip', tour.return_url));
    previous.addEventListener('click', () => move('previous', tour.previous_url));
    next.addEventListener('click', () => move(tour.last ? 'complete' : 'next', tour.last ? tour.return_url : tour.next_url));

    if (target) {
      target.classList.add('tour-target'); target.scrollIntoView({behavior: 'smooth', block: 'center'});
      if (!target.hasAttribute('tabindex') && !target.matches('a,button,input,select,textarea,summary')) target.tabIndex = 0;
      if (tour.interactive && target.matches('a')) target.addEventListener('click', event => { event.preventDefault(); move(tour.last ? 'complete' : 'next', tour.next_url); });
    }
    panel.focus({preventScroll: true});
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); skip.focus(); }
      if (event.key === 'ArrowRight' && !event.altKey && !event.ctrlKey && !event.metaKey) next.focus();
      if (event.key === 'ArrowLeft' && !previous.hidden && !event.altKey && !event.ctrlKey && !event.metaKey) previous.focus();
    });
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
