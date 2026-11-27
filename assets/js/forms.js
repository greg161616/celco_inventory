(() => {
  const form = document.querySelector('[data-lines-form]');
  if (!form) return;
  const lines = form.querySelector('[data-lines]');
  let next = 1;
  const template = lines.querySelector('[data-line]').cloneNode(true);
  form.querySelector('[data-add-line]').addEventListener('click', () => {
    const line = template.cloneNode(true);
    line.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace('[0]', '[' + next + ']'); });
    next++;
    lines.append(line);
    line.querySelector('input,select')?.focus();
  });
  form.addEventListener('click', event => {
    if (event.target.closest('[data-remove-line]') && lines.children.length > 1) event.target.closest('[data-line]').remove();
    const serial = event.target.closest('[data-add-serial]');
    if (serial) {
      const target = serial.closest('[data-line]').querySelector('[name$="[serials]"]');
      const current = target.value.split(/[\n,]+/).map(s => s.trim()).filter(Boolean);
      if (!current.includes(serial.dataset.addSerial)) target.value = [...current, serial.dataset.addSerial].join('\n');
    }
  });
  async function lookup(params) {
    const url = new URL(form.dataset.lookup, location.href);
    Object.entries(params).forEach(([k,v]) => url.searchParams.set(k,v));
    const response = await fetch(url, {headers:{Accept:'application/json'}});
    const body = await response.json();
    if(!response.ok || !body.success) throw Error(body.message || 'Search unavailable.');
    return body.data;
  }
  async function loadRequest() {
    if (!form.dataset.requestId) return;
    const response = await fetch(form.dataset.requestUrl + '?id=' + encodeURIComponent(form.dataset.requestId));
    const result = await response.json();
    if (!response.ok || !result.success) throw Error(result.message);
    const {header,lines: requested} = result.data;
    for(const key of ['department_id','project_id']) form.elements[key].value=header[key] ?? '';
    requested.forEach((item,index)=>{
      if(index) form.querySelector('[data-add-line]').click();
      const line=lines.lastElementChild;
      const material=line.querySelector('[name$="[material_id]"]');
      if(![...material.options].some(o=>o.value===String(item.material_id))) material.add(new Option(item.material,item.material_id));
      material.value=item.material_id;
      line.querySelector('[name$="[requisition_item_id]"]').value=item.requisition_item_id;
      line.querySelector('[name$="[from_warehouse_id]"]').value=header.warehouse_id;
      line.querySelector('[name$="[quantity]"]').value=item.remaining_qty;
      material.dispatchEvent(new Event('change',{bubbles:true}));
    });
  }
  loadRequest().catch(error=>{form.querySelector('[data-stock-info]').textContent=error.message;});
  form.addEventListener('input', async event => {
    if(!event.target.matches('[data-material-search]')) return;
    const field=event.target, line=field.closest('[data-line]'), q=field.value;
    const select=line.querySelector('[name$="[material_id]"]');
    try {
      const options=await lookup({kind:'material', q});
      if(field.value!==q) return;
      select.replaceChildren(new Option('Select material…',''),...options.map(v=>new Option(v.label,v.id)));
    } catch(e){line.querySelector('[data-stock-info]').textContent=e.message;}
  });
  form.addEventListener('change', async event => {
    const line = event.target.closest('[data-line]');
    if(!line) return;
    const material=line.querySelector('[name$="[material_id]"]').value;
    const warehouse=line.querySelector('[name$="[from_warehouse_id]"]')?.value || '';
    const info=line.querySelector('[data-stock-info]');
    if(!info || !material) return;
    try {
      const [stock,serials]=await Promise.all([lookup({kind:'stock',material,warehouse}),lookup({kind:'serial',material,warehouse})]);
      if(material!==line.querySelector('[name$="[material_id]"]').value) return;
      info.textContent=stock.map(s=>s.warehouse+' · '+s.stock_condition+' '+s.quantity+' '+s.unit).join(' | ') || 'No recorded stock.';
      if(line.querySelector('[name$="[serials]"]')) for(const s of serials){
        const btn=document.createElement('button');btn.type='button';btn.className='btn btn-light';btn.dataset.addSerial=s.id;btn.textContent=s.label;info.append(' ',btn);
      }
    }catch(e){info.textContent=e.message;}
  });
})();
