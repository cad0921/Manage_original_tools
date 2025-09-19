(function(){
  window.ItemDrops = window.ItemDrops || {};
  const UI = { block:null, list:null };
  const SOURCE_TYPES = [
    {id:'entity',label:'動畫生物'},
    {id:'animal',label:'生物'},
    {id:'decor',label:'裝飾'},
    {id:'interactive',label:'可互動'},
    {id:'building',label:'建材'},
    {id:'resource',label:'素材'},
    {id:'consumable',label:'消耗品'},
    {id:'crop',label:'農作物'},
    {id:'mineral',label:'礦物'},
    {id:'tree',label:'樹木'},
    {id:'material',label:'素材'},
    {id:'weapon',label:'武器'},
    {id:'armor',label:'防具'}
  ];
  function ensureDropsArray(item){ if(!item.drops||!Array.isArray(item.drops)) item.drops=[]; }
  function clamp01(v){ v=parseFloat(v); if(isNaN(v)) return 0; return Math.max(0, Math.min(1, v)); }
  function makeSelect(opts,value){ const s=document.createElement('select'); opts.forEach(o=>{const op=document.createElement('option'); op.value=o.id; op.textContent=o.label; if(o.id===value) op.selected=true; s.appendChild(op);}); return s; }
  function createRow(item, idx){
    const d=item.drops[idx]; const row=document.createElement('div'); row.className='drop-row'; row.dataset.idx=String(idx);
    const inChance=document.createElement('input'); inChance.type='number'; inChance.step='0.01'; inChance.min='0'; inChance.max='1'; inChance.placeholder='機率(0~1)'; inChance.value=d.chance??1;
    const inMin=document.createElement('input'); inMin.type='number'; inMin.step='1'; inMin.min='0'; inMin.placeholder='最少'; inMin.value=d.min??1;
    const inMax=document.createElement('input'); inMax.type='number'; inMax.step='1'; inMax.min='0'; inMax.placeholder='最多'; inMax.value=d.max??d.min??1;
    const srcType=makeSelect(SOURCE_TYPES, d.sourceType||'entity');
    const srcId=document.createElement('input'); srcId.type='text'; srcId.placeholder='來源 ID（例如 forest_wolf / wheat / iron_ore / oak）'; srcId.value=d.sourceId||'';
    const del=document.createElement('button'); del.type='button'; del.className='danger'; del.textContent='刪除';
    del.addEventListener('click', ()=>{ const i=+row.dataset.idx; item.drops.splice(i,1); render(item); });
    inChance.addEventListener('input', ()=>{ const i=+row.dataset.idx; item.drops[i].chance=clamp01(inChance.value||'0'); inChance.value=item.drops[i].chance; });
    inMin.addEventListener('input', ()=>{ const i=+row.dataset.idx; const v=Math.max(0, parseInt(inMin.value||'0',10)); item.drops[i].min=v; if((item.drops[i].max??v)<v){ item.drops[i].max=v; inMax.value=v; } inMin.value=v; });
    inMax.addEventListener('input', ()=>{ const i=+row.dataset.idx; const v=Math.max(0, parseInt(inMax.value||'0',10)); const mn=item.drops[i].min??0; item.drops[i].max=Math.max(v,mn); inMax.value=item.drops[i].max; });
    srcType.addEventListener('change', ()=>{ const i=+row.dataset.idx; item.drops[i].sourceType=srcType.value; });
    srcId.addEventListener('input', ()=>{ const i=+row.dataset.idx; item.drops[i].sourceId=srcId.value.trim(); });
    row.append(inChance,inMin,inMax,srcType,srcId,del); return row;
  }
  function render(item){ ensureDropsArray(item); UI.list.innerHTML=''; item.drops.forEach((_,i)=>UI.list.appendChild(createRow(item,i))); }
  function buildBlock(){
    const block=document.createElement('div'); block.className='field-group';
    block.innerHTML=`<style>.drop-row{display:grid;grid-template-columns:120px 90px 90px 140px 1fr 80px;gap:8px;align-items:center;padding:6px 8px;margin:6px 0;border:1px dashed #ddd;border-radius:8px}.drop-row input,.drop-row select{width:100%}.drop-row .danger{color:#b00}</style><h4 style="margin:12px 0 6px;">掉落</h4><div id="item-drops-list"></div><button type="button" id="btn-add-drop">＋新增掉落規則</button><small>機率 0–1；來源可選 動畫生物/農作物/礦物/樹木，ID 自訂。</small>`;
    block.querySelector('#btn-add-drop').addEventListener('click', ()=>{ if(!window.ItemDrops.currentItem) return; ensureDropsArray(window.ItemDrops.currentItem); window.ItemDrops.currentItem.drops.push({chance:1,min:1,max:1,sourceType:'entity',sourceId:''}); render(window.ItemDrops.currentItem); });
    UI.block=block; UI.list=block.querySelector('#item-drops-list'); return block;
  }
  window.ItemDrops.onOpen=function(item, mountEl){ window.ItemDrops.currentItem=item; if(!UI.block) mountEl.appendChild(buildBlock()); render(item); };
  window.ItemDrops.onCollect=function(fd){ const item=window.ItemDrops.currentItem; const drops=(item&&Array.isArray(item.drops))?item.drops:[]; fd.append('drops', JSON.stringify(drops)); };
  window.ItemDrops.formatBrief=function(drops){ if(!Array.isArray(drops)||drops.length===0) return '—'; return drops.map(d=>{ const pct=Math.round((d.chance??0)*100); const mn=d.min??1, mx=d.max??mn; const src=(d.sourceType&&d.sourceId)?`（${d.sourceType}:${d.sourceId}）`:''; return `${pct}% × ${mn}${mx!==mn?`–${mx}`:''}${src}`; }).join('；'); };
})();