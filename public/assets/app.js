// show/hide blocks [data-for=value] depending on a select[data-toggle-target] in the same form
document.querySelectorAll('select[data-toggle-target]').forEach(sel => {
  const form = sel.closest('form');
  const upd = () => form.querySelectorAll('[data-for]').forEach(el => {
    const on = el.dataset.for === sel.value;
    el.style.display = on ? '' : 'none';
    el.querySelectorAll('input,select,textarea').forEach(i => i.disabled = !on);
  });
  sel.addEventListener('change', upd); upd();
});
// type-to-filter for long selects
document.querySelectorAll('select[data-search]').forEach(sel => {
  if (sel.options.length < 25) return;
  const inp = document.createElement('input');
  inp.className = 'form-control form-control-sm mb-1'; inp.placeholder = 'suchen …';
  sel.parentNode.insertBefore(inp, sel);
  const all = [...sel.options].map(o => [o, o.text.toLowerCase()]);
  inp.addEventListener('input', () => { const q = inp.value.toLowerCase(); all.forEach(([o, t]) => o.hidden = q && !t.includes(q) && o.value !== ''); });
});
