// A pretend window.jumphp for the installer's page in the editor's preview: 'load' asks the server what the installer
// would show (the draft script), 'install' plays the steps on a progress bar. Nothing is installed.
(() => {
  const params = new URLSearchParams(location.search);
  const listeners = {};
  const emit = (name, payload) => (listeners[name] || []).forEach((fn) => fn(payload));
  let view = null;
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  window.jumphp = {
    on(name, fn) { (listeners[name] ||= []).push(fn); },
    send() {},
    async invoke(name, payload = {}) {
      switch (name) {
        case 'load': {
          const r = await fetch(window.J2I_VIEW + '?os=' + (params.get('os') || 'windows'));
          view = await r.json();
          return view;
        }
        case 'retry': return true;
        case 'browse': return null;
        case 'check': return { ok: true, free: '120 GB', warning: null };
        case 'install': {
          const steps = (view.steps || []).filter((s) => !s.if || (s.if.startsWith('!') ? !payload.options?.[s.if.slice(1)] : payload.options?.[s.if]))
            .filter((s) => !s.os || s.os.includes(view.os));
          const log = [];
          (async () => {
            for (let i = 0; i < steps.length; i++) {
              log.push(steps[i].label);
              for (let f = 0; f <= 10; f++) {
                emit('progress', { progress: (i + f / 10) / steps.length, label: steps[i].label, log, done: false, error: null });
                await wait(60);
              }
            }
            log.push('(preview: nothing was installed)');
            emit('progress', { progress: 1, label: 'Done', log, done: true, error: null });
          })();
          return true;
        }
        case 'cancel':
        case 'finish':
          location.reload();
          return true;
        default: return null;
      }
    },
  };
})();
