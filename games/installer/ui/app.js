// The installer's wizard. Everything it shows comes from PHP (jumphp.invoke('load')), everything it does is PHP
// (install, uninstall, browse, check, finish); PHP tells how far it got with 'progress' events.
// The server's editor shows this same page in its preview, with a window.jumphp of its own.
(() => {
  const $ = (id) => document.getElementById(id);
  const el = (tag, props = {}, ...children) => {
    const e = Object.assign(document.createElement(tag), props);
    for (const c of children) e.append(c);
    return e;
  };

  // --- the installer's own words (the script's texts are its author's) ---------------------------------------
  const WORDS = {
    en: {
      next: 'Next ›', back: '‹ Back', cancel: 'Cancel', close: 'Close', finish: 'Finish', install: 'Install', uninstall: 'Uninstall', retry: 'Try again',
      loading: 'Loading…', welcome: 'Welcome', license: 'License agreement', licenseSub: 'Please read the terms below.',
      accept: 'I accept the terms of the license', folder: 'Install folder', folderSub: 'Where should {app} be installed?',
      browse: 'Browse…', space: 'Space needed: {size}', free: 'Free on this disk: {free}', options: 'Options', optionsSub: 'Choose what to add.',
      installing: 'Installing', installingSub: 'Please wait while {app} is installed.', details: 'Details', finished: 'Completed',
      finishedSub: '{app} is ready.', run: 'Start {app} now', failed: 'The installation failed', notEmpty: 'This folder is not empty: its files may be replaced. Next again to go on.',
      already: '{app} is already installed there: it will be updated. Next again to go on.', error: 'Something went wrong',
      uninstallTitle: 'Uninstall {app}', uninstallSub: 'Remove {app} from this computer.', uninstallText: '{app} {version} will be removed from:',
      removing: 'Removing', removingSub: 'Please wait while {app} is removed.', removed: '{app} was removed from this computer.',
      ready: 'Ready to install', readySub: '{app} {version} will be installed into:', by: 'by {publisher}',
    },
    fr: {
      next: 'Suivant ›', back: '‹ Précédent', cancel: 'Annuler', close: 'Fermer', finish: 'Terminer', install: 'Installer', uninstall: 'Désinstaller', retry: 'Réessayer',
      loading: 'Chargement…', welcome: 'Bienvenue', license: 'Contrat de licence', licenseSub: 'Lisez les conditions ci-dessous.',
      accept: "J'accepte les termes de la licence", folder: "Dossier d'installation", folderSub: 'Où installer {app} ?',
      browse: 'Parcourir…', space: 'Espace nécessaire : {size}', free: 'Libre sur ce disque : {free}', options: 'Options', optionsSub: 'Choisissez ce qui sera ajouté.',
      installing: 'Installation', installingSub: "Patientez pendant l'installation de {app}.", details: 'Détails', finished: 'Terminé',
      finishedSub: '{app} est prêt.', run: 'Lancer {app} maintenant', failed: "L'installation a échoué", notEmpty: "Ce dossier n'est pas vide : ses fichiers peuvent être remplacés. Suivant à nouveau pour continuer.",
      already: '{app} est déjà installé ici : il sera mis à jour. Suivant à nouveau pour continuer.', error: 'Un problème est survenu',
      uninstallTitle: 'Désinstaller {app}', uninstallSub: 'Retirer {app} de cet ordinateur.', uninstallText: '{app} {version} sera retiré de :',
      removing: 'Suppression', removingSub: 'Patientez pendant la suppression de {app}.', removed: "{app} a été retiré de cet ordinateur.",
      ready: 'Prêt à installer', readySub: '{app} {version} sera installé dans :', by: 'par {publisher}',
    },
  };
  const lang = (navigator.language || 'en').slice(0, 2).toLowerCase();
  const words = WORDS[lang] || WORDS.en;
  let appVars = {};
  const t = (key, more = {}) => (words[key] ?? WORDS.en[key] ?? key).replace(/\{(\w+)\}/g, (m, k) => (more[k] ?? appVars[k] ?? m));

  // --- state ------------------------------------------------------------------------------------------------
  const state = { view: null, pages: [], index: 0, folder: '', options: {}, accepted: false, confirmed: '', busy: false, done: false, error: null, log: [], run: true };

  const buttons = { back: $('back'), next: $('next'), cancel: $('cancel') };
  const setButtons = ({ back = false, next = t('next'), nextOn = true, cancel = t('cancel'), cancelOn = true }) => {
    buttons.back.hidden = !back;
    buttons.back.textContent = t('back');
    buttons.next.hidden = next === null;
    buttons.next.textContent = next ?? '';
    buttons.next.disabled = !nextOn;
    buttons.cancel.hidden = cancel === null;
    buttons.cancel.textContent = cancel ?? '';
    buttons.cancel.disabled = !cancelOn;
  };
  const head = (title, subtitle = '') => {
    $('title').textContent = title;
    $('subtitle').textContent = subtitle;
  };
  const content = (...children) => {
    const c = $('content');
    c.replaceChildren(...children);
    c.scrollTop = 0;
  };

  // --- the pages --------------------------------------------------------------------------------------------
  const page = () => state.pages[state.index];

  const show = () => {
    const p = page();
    // PHP counts the pages seen (the installer's statistics: where people stop)
    if (state.told !== state.index) {
      state.told = state.index;
      try { jumphp.send && jumphp.send('wizard', { page: p.type }); } catch (e) { /* the server's preview */ }
    }
    const first = state.index === 0;
    const beforeInstall = state.pages[state.index + 1]?.type === 'install';
    const nextLabel = beforeInstall ? t('install') : t('next');
    switch (p.type) {
      case 'welcome':
        head(p.title || t('welcome'), state.view.app.publisher ? t('by', { publisher: state.view.app.publisher }) : '');
        content(...p.text.split(/\n{2,}/).map((para) => el('p', { textContent: para })));
        setButtons({ back: !first, next: nextLabel });
        break;
      case 'license': {
        head(t('license'), t('licenseSub'));
        const box = el('input', { type: 'checkbox', checked: state.accepted, id: 'accept' });
        box.onchange = () => { state.accepted = box.checked; buttons.next.disabled = !box.checked; };
        content(el('div', { className: 'license', textContent: p.text }), el('label', { className: 'check' }, box, el('span', { textContent: t('accept') })));
        setButtons({ back: !first, next: nextLabel, nextOn: state.accepted });
        break;
      }
      case 'folder': {
        head(t('folder'), t('folderSub'));
        const input = el('input', { type: 'text', value: state.folder, id: 'folder', spellcheck: false });
        input.oninput = () => { state.folder = input.value; state.confirmed = ''; note.textContent = ''; };
        const browse = el('button', { className: 'secondary', textContent: t('browse') });
        browse.onclick = async () => {
          const dir = await jumphp.invoke('browse', { folder: state.folder });
          if (dir) {
            // the chosen folder + the app's own folder name, like the default
            const name = state.view.folder.split(/[\\/]/).pop();
            const sep = state.view.os === 'windows' ? '\\' : '/';
            state.folder = input.value = String(dir).replace(/[\\/]+$/, '') + sep + name;
            state.confirmed = '';
          }
        };
        const note = el('div', { className: 'warn', id: 'folder-note' });
        const info = el('div', { className: 'note', id: 'folder-info' });
        content(el('div', { className: 'row' }, input, browse), info, note);
        jumphp.invoke('check', { folder: state.folder }).then((r) => {
          const parts = [];
          if (state.view.app.size) parts.push(t('space', { size: state.view.app.size }));
          if (r && r.free) parts.push(t('free', { free: r.free }));
          info.textContent = parts.join(' · ');
        }).catch(() => {});
        setButtons({ back: !first, next: nextLabel });
        input.focus();
        break;
      }
      case 'options':
        head(t('options'), t('optionsSub'));
        content(...p.items.map((item) => {
          const box = el('input', { type: 'checkbox', checked: state.options[item.var] });
          box.onchange = () => { state.options[item.var] = box.checked; };
          return el('label', { className: 'check' }, box, el('span', { textContent: item.label }));
        }));
        setButtons({ back: !first, next: nextLabel });
        break;
      case 'install':
        showProgress(t('installing'), t('installingSub'));
        break;
      case 'finish': {
        head(t('finished'), t('finishedSub'));
        const children = [el('p', { className: 'done', textContent: p.text })];
        if (p.run) {
          const box = el('input', { type: 'checkbox', checked: state.run });
          box.onchange = () => { state.run = box.checked; };
          children.push(el('label', { className: 'check' }, box, el('span', { textContent: p.label })));
        }
        content(...children);
        setButtons({ next: t('finish'), cancel: null });
        break;
      }
    }
  };

  const showProgress = (title, subtitle) => {
    head(title, subtitle);
    content(
      el('div', { className: 'bar' }, el('div', { id: 'bar' })),
      el('div', { className: 'status', id: 'status', textContent: '…' }),
      el('details', {}, el('summary', { textContent: t('details') }), el('div', { className: 'log', id: 'log' })),
    );
    setButtons({ next: t('next'), nextOn: false, cancel: t('cancel') });
  };

  const showError = (message, retry = false) => {
    head(t('error'), '');
    content(el('p', { className: 'error', textContent: message }));
    setButtons({ next: retry ? t('retry') : null, cancel: t('close') });
    buttons.next.onclick = async () => {
      await jumphp.invoke('retry');
      start();
    };
  };

  // --- moving -----------------------------------------------------------------------------------------------
  const go = async (delta) => {
    if (state.busy) return;
    const p = page();
    if (delta > 0 && p.type === 'folder') {
      const r = await jumphp.invoke('check', { folder: state.folder });
      const note = document.getElementById('folder-note');
      if (!r.ok) {
        note.textContent = r.message;
        return;
      }
      if (r.warning && state.confirmed !== state.folder) {
        state.confirmed = state.folder;
        note.textContent = t(r.warning === 'already-installed' ? 'already' : 'notEmpty');
        return;
      }
    }
    const to = state.index + delta;
    if (to < 0 || to >= state.pages.length) return;
    state.index = to;
    if (page().type === 'install') {
      show();
      state.busy = true;
      await jumphp.invoke('install', { folder: state.folder, options: state.options });
      return;
    }
    show();
  };

  buttons.back.onclick = () => go(-1);
  buttons.cancel.onclick = () => jumphp.invoke('cancel');
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !buttons.next.disabled && !buttons.next.hidden && !(e.target instanceof HTMLButtonElement)) buttons.next.click();
    if (e.key === 'Escape' && !buttons.cancel.hidden && !buttons.cancel.disabled) buttons.cancel.click();
  });

  jumphp.on('progress', (p) => {
    const bar = document.getElementById('bar');
    if (!bar) return;
    bar.style.width = (p.progress * 100).toFixed(1) + '%';
    document.getElementById('status').textContent = p.label || '…';
    const log = document.getElementById('log');
    log.textContent = (p.log || []).join('\n');
    log.scrollTop = log.scrollHeight;
    if (!p.done) return;
    state.busy = false;
    state.done = true;
    if (p.error) {
      head(state.mode === 'uninstall' ? t('error') : t('failed'), '');
      document.getElementById('status').replaceWith(el('p', { className: 'error', textContent: p.error }));
      log.closest('details').open = true;
      setButtons({ next: null, cancel: t('close') });
      return;
    }
    if (state.mode === 'uninstall') {
      head(t('finished'), '');
      content(el('p', { className: 'done', textContent: t('removed') }));
      setButtons({ next: t('close'), cancel: null });
      buttons.next.onclick = () => jumphp.invoke('finish', { run: false });
      return;
    }
    const next = state.pages[state.index + 1];
    if (next) {
      state.index++;
      show();
    } else {
      bar.style.width = '100%';
      setButtons({ next: t('close'), cancel: null });
    }
  });

  buttons.next.onclick = () => {
    if (state.mode === 'install' && page()?.type === 'finish') {
      jumphp.invoke('finish', { run: state.run && !!page().run });
      return;
    }
    if (state.done) {
      jumphp.invoke('finish', { run: false });
      return;
    }
    go(1);
  };

  // --- start ------------------------------------------------------------------------------------------------
  const sideAndHead = (app, images) => {
    document.documentElement.style.setProperty('--accent', app.accent || '#2f6fed');
    $('side-name').textContent = app.name || '';
    $('side-version').textContent = app.version || '';
    if (images?.banner) {
      $('banner').src = images.banner;
      $('banner').hidden = false;
      $('side').classList.add('has-banner');
    }
    if (images?.icon) {
      for (const id of ['icon', 'side-icon']) {
        $(id).src = images.icon;
        $(id).hidden = false;
      }
    }
    document.title = (app.name || '') + ' Setup';
  };

  const start = async () => {
    head('…', '');
    content(el('div', { className: 'loading' }, el('span', { className: 'spinner' }), el('span', { textContent: t('loading') })));
    setButtons({ next: t('next'), nextOn: false });
    let r;
    try {
      r = await jumphp.invoke('load');
    } catch (e) {
      showError(String(e?.message || e));
      return;
    }
    state.mode = r.mode;
    if (r.mode === 'error') {
      showError(r.error, !!r.retry);
      return;
    }
    if (r.mode === 'uninstall') {
      appVars = { app: r.app?.name || '', version: r.app?.version || '' };
      sideAndHead({ name: r.app?.name, version: r.app?.version }, {});
      head(t('uninstallTitle'), t('uninstallSub'));
      content(el('p', { textContent: t('uninstallText') }), el('p', { className: 'license', style: 'height:auto', textContent: r.folder }));
      setButtons({ next: t('uninstall') });
      buttons.next.onclick = async () => {
        if (state.busy || state.done) return jumphp.invoke('finish', { run: false });
        showProgress(t('removing'), t('removingSub'));
        state.busy = true;
        await jumphp.invoke('uninstall');
      };
      return;
    }
    state.view = r;
    state.pages = r.pages;
    state.folder = r.folder;
    state.index = 0;
    appVars = { app: r.app.name, version: r.app.version, publisher: r.app.publisher };
    for (const p of r.pages) {
      if (p.type === 'options') for (const item of p.items) state.options[item.var] = item.default;
    }
    sideAndHead(r.app, r.images);
    // #page=N: open on that page (screenshots of the wizard, the server's preview)
    const asked = /page=(\d+)/.exec(location.hash);
    if (asked && r.pages[+asked[1]] && r.pages[+asked[1]].type !== 'install') state.index = +asked[1];
    show();
    buttons.next.focus();
  };

  if (document.readyState === 'loading') window.addEventListener('DOMContentLoaded', start);
  else start();
})();
