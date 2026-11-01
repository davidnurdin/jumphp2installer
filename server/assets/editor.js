// The package editor: the install script (CodeMirror, YAML, problems as one types), its files, the preview (the
// installer's own page, public/installer-ui, with a pretend window.jumphp: public/preview.js), publishing.
import { EditorState } from '@codemirror/state';
import { EditorView, keymap, lineNumbers, highlightActiveLine, drawSelection } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { syntaxHighlighting, defaultHighlightStyle, indentOnInput, bracketMatching } from '@codemirror/language';
import { linter, lintGutter } from '@codemirror/lint';
import { yaml } from '@codemirror/lang-yaml';

const root = document.getElementById('editor-app');
const urls = root.dataset;
const $ = (id) => document.getElementById(id);
const call = async (url, body, json = true) => {
  const r = await fetch(url, {
    method: 'POST',
    headers: json ? { 'Content-Type': 'application/json', 'X-Requested-With': 'jumphp2installer' } : { 'X-Requested-With': 'jumphp2installer' },
    body: json ? JSON.stringify(body) : body,
  });
  const data = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
  if (!r.ok && !data.error) data.error = `HTTP ${r.status}`;
  return data;
};

// --- the script --------------------------------------------------------------------------------------------
let saved = $('script').value;
let lastProblems = [];
const showProblems = (problems, missing = []) => {
  lastProblems = problems;
  const box = $('problems');
  box.replaceChildren(...problems.map((p) => {
    const div = document.createElement('div');
    div.className = 'problem';
    div.textContent = (p.line ? `line ${p.line}: ` : '') + (p.path ? `${p.path}: ` : '') + p.message;
    if (p.line) div.onclick = () => goToLine(p.line);
    return div;
  }));
  if (!problems.length) {
    const ok = document.createElement('div');
    ok.className = 'ok';
    ok.textContent = '✓ The script is right';
    box.append(ok);
  }
  $('missing').textContent = missing.length ? 'Not in the files yet: ' + missing.join(', ') : '';
};

const scriptLinter = linter(async (view) => {
  const r = await call(urls.check, { script: view.state.doc.toString() });
  showProblems(r.problems || [], r.missing || []);
  return (r.problems || []).map((p) => {
    const line = view.state.doc.line(Math.min(Math.max(p.line || 1, 1), view.state.doc.lines));
    return { from: line.from, to: line.to, severity: 'error', message: (p.path ? p.path + ': ' : '') + p.message };
  });
}, { delay: 400 });

const theme = EditorView.theme({
  '&': { height: '100%', fontSize: '13px' },
  '.cm-scroller': { fontFamily: 'ui-monospace, "Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace' },
});

const view = new EditorView({
  parent: $('editor'),
  state: EditorState.create({
    doc: saved,
    extensions: [
      lineNumbers(), history(), drawSelection(), highlightActiveLine(), indentOnInput(), bracketMatching(),
      syntaxHighlighting(defaultHighlightStyle, { fallback: true }), yaml(), lintGutter(), scriptLinter, theme,
      keymap.of([{ key: 'Mod-s', preventDefault: true, run: () => { save(); return true; } }, indentWithTab, ...defaultKeymap, ...historyKeymap]),
      EditorView.updateListener.of((u) => { if (u.docChanged) changed(); }),
      EditorState.tabSize.of(2),
    ],
  }),
});
const text = () => view.state.doc.toString();
const goToLine = (n) => {
  const line = view.state.doc.line(Math.min(n, view.state.doc.lines));
  view.dispatch({ selection: { anchor: line.from }, scrollIntoView: true });
  view.focus();
};

let previewTimer = null;
const changed = () => {
  $('save-state').textContent = text() === saved ? 'saved' : 'not saved';
  clearTimeout(previewTimer);
  previewTimer = setTimeout(refreshPreview, 1200);
};
window.addEventListener('beforeunload', (e) => { if (text() !== saved) e.preventDefault(); });

const save = async (extra = {}) => {
  $('save-state').textContent = 'saving…';
  const r = await call(urls.save, { script: text(), ...extra });
  if (r.error) {
    $('save-state').textContent = r.error;
    return r;
  }
  saved = r.saved ? text() : saved;
  $('save-state').textContent = 'saved';
  $('package-name').textContent = r.name;
  document.title = r.name + ' · jumphp2installer';
  return r;
};
$('save').onclick = () => save();

$('published').onchange = async (e) => {
  const r = await save({ published: e.target.checked });
  if (r.error) {
    e.target.checked = false;
    alert(r.error);
  }
};

// --- files -------------------------------------------------------------------------------------------------
const listFiles = (files) => {
  $('files').replaceChildren(...files.map((f) => {
    const li = document.createElement('li');
    const name = document.createElement('code');
    name.textContent = f.path;
    name.title = 'Click: copy the name';
    name.onclick = () => navigator.clipboard?.writeText(f.path);
    const size = document.createElement('span');
    size.className = 'muted';
    size.textContent = ` ${(f.size / 1024).toFixed(1)} KB `;
    const open = document.createElement('a');
    open.href = urls.file.replace('__PATH__', f.path.split('/').map(encodeURIComponent).join('/'));
    open.target = '_blank';
    open.textContent = f.image ? 'view' : 'open';
    const del = document.createElement('button');
    del.className = 'small';
    del.textContent = '✕';
    del.title = 'Remove';
    del.onclick = async () => {
      if (!confirm(`Remove ${f.path}?`)) return;
      listFiles((await call(urls.remove, { path: f.path })).files || []);
      scriptLinter && view.dispatch({});
    };
    li.append(name, size, open, ' ', del);
    return li;
  }));
};
const upload = async (fileList) => {
  const form = new FormData();
  for (const f of fileList) form.append('files[]', f);
  const r = await call(urls.files, form, false);
  if (r.error) alert(r.error);
  if (r.files) listFiles(r.files);
  refreshPreview();
// the installer's window (660 x 470) scaled to the width of the pane
const frameBox = document.querySelector('.preview-frame');
new ResizeObserver(() => { $('preview').style.transform = `scale(${frameBox.clientWidth / 660})`; }).observe(frameBox);
};
$('file-input').onchange = (e) => upload(e.target.files);
const drop = $('drop');
drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
drop.addEventListener('dragleave', () => drop.classList.remove('over'));
drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('over'); upload(e.dataTransfer.files); });

// --- preview -----------------------------------------------------------------------------------------------
const refreshPreview = async () => {
  await call(urls.draft, { script: text() });
  $('preview').src = urls.preview + '?os=' + $('preview-os').value + '&t=' + Date.now();
};
$('preview-os').onchange = refreshPreview;
$('preview-reload').onclick = refreshPreview;
refreshPreview();
// the installer's window (660 x 470) scaled to the width of the pane
const frameBox = document.querySelector('.preview-frame');
new ResizeObserver(() => { $('preview').style.transform = `scale(${frameBox.clientWidth / 660})`; }).observe(frameBox);
