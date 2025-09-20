(function(){
  window.ItemDrops = window.ItemDrops || {};
  const UI = { block: null, list: null };
  const valOr = (value, fallback) => (value === undefined || value === null) ? fallback : value;

  const FALLBACK_LABELS = {
    entity: '動畫生物',
    animal: '生物',
    decor: '裝飾',
    interactive: '可互動',
    building: '建材',
    resource: '素材',
    consumable: '消耗品',
    crop: '農作物',
    mineral: '礦物',
    tree: '樹木',
    material: '素材',
    weapon: '武器',
    armor: '防具'
  };

  const FALLBACK_SOURCES = [
    { id: 'entity', label: FALLBACK_LABELS.entity },
    { id: 'animal', label: FALLBACK_LABELS.animal },
    { id: 'decor', label: FALLBACK_LABELS.decor },
    { id: 'interactive', label: FALLBACK_LABELS.interactive },
    { id: 'building', label: FALLBACK_LABELS.building },
    { id: 'resource', label: FALLBACK_LABELS.resource },
    { id: 'consumable', label: FALLBACK_LABELS.consumable },
    { id: 'crop', label: FALLBACK_LABELS.crop },
    { id: 'mineral', label: FALLBACK_LABELS.mineral },
    { id: 'tree', label: FALLBACK_LABELS.tree },
    { id: 'material', label: FALLBACK_LABELS.material },
    { id: 'weapon', label: FALLBACK_LABELS.weapon },
    { id: 'armor', label: FALLBACK_LABELS.armor }
  ];

  let labelFallbacks = { ...FALLBACK_LABELS };
  let sourceResolver = null;

  function ensureDropsArray(item) { if (!item.drops || !Array.isArray(item.drops)) item.drops = []; }

  function mergeLabelFallbacks(map) {
    labelFallbacks = { ...FALLBACK_LABELS };
    if (map && typeof map === 'object') {
      Object.keys(map).forEach(key => {
        const label = map[key];
        if (label === undefined || label === null || label === '') return;
        labelFallbacks[key] = String(label);
      });
    }
  }

  function normaliseOptions(rawOptions) {
    const seen = new Set();
    const normalised = [];
    const list = Array.isArray(rawOptions) ? rawOptions : [];
    list.forEach(opt => {
      if (!opt) return;
      const id = String(valOr(opt.id, '')).trim();
      if (!id || seen.has(id)) return;
      const label = String(valOr(opt.label, labelFallbacks[id] || id)).trim() || id;
      seen.add(id);
      normalised.push({ id, label });
    });
    if (normalised.length === 0) {
      FALLBACK_SOURCES.forEach(opt => {
        if (!seen.has(opt.id)) {
          seen.add(opt.id);
          normalised.push({ id: opt.id, label: opt.label });
        }
      });
    }
    return normalised;
  }

  function getSourceOptions() {
    let resolved = null;
    if (typeof sourceResolver === 'function') {
      try { resolved = sourceResolver(); }
      catch (err) { console.warn('[drops] source resolver error:', err); }
    } else if (Array.isArray(window.ItemDrops.sourceOptions)) {
      resolved = window.ItemDrops.sourceOptions;
    }
    return normaliseOptions(resolved);
  }

  function getBaseType(options) {
    return (options && options[0] && options[0].id) ? options[0].id : 'entity';
  }

  function clampChance(value) {
    const num = parseFloat(value);
    if (!Number.isFinite(num) || isNaN(num)) return 0;
    if (num < 0) return 0;
    if (num > 1) return 1;
    return num;
  }

  function sanitiseDrop(raw, baseType, optionsOverride) {
    const options = normaliseOptions(optionsOverride && optionsOverride.length ? optionsOverride : getSourceOptions());
    const fallbackType = baseType && typeof baseType === 'string' && baseType.trim() ? baseType.trim() : getBaseType(options);
    const chance = clampChance(valOr(raw && raw.chance, 0));
    let min = parseInt(valOr(raw && raw.min, 0), 10);
    if (!Number.isFinite(min) || isNaN(min) || min < 0) min = 0;
    let max = parseInt(valOr(raw && raw.max, min), 10);
    if (!Number.isFinite(max) || isNaN(max) || max < min) max = min;
    let sourceType = String(valOr(raw && raw.sourceType, '')).trim();
    const validTypes = new Set(options.map(opt => opt.id));
    if (!sourceType || !validTypes.has(sourceType)) {
      sourceType = fallbackType;
    }
    const sourceId = String(valOr(raw && raw.sourceId, '')).trim();
    return { chance, min, max, sourceType, sourceId };
  }

  function normaliseDrops(drops, optionsOverride) {
    if (!Array.isArray(drops) || drops.length === 0) return [];
    const options = normaliseOptions(optionsOverride && optionsOverride.length ? optionsOverride : getSourceOptions());
    const baseType = getBaseType(options);
    return drops.map(drop => sanitiseDrop(drop, baseType, options));
  }

  function labelFor(type, options) {
    if (!type) return '';
    const option = Array.isArray(options) ? options.find(opt => opt.id === type) : null;
    if (option && option.label) return option.label;
    if (labelFallbacks[type]) return labelFallbacks[type];
    return type;
  }

  function formatDrops(drops, sourceOptions) {
    if (!Array.isArray(drops) || drops.length === 0) return '—';
    const options = normaliseOptions(sourceOptions && sourceOptions.length ? sourceOptions : getSourceOptions());
    const baseType = getBaseType(options);
    return drops.map(drop => {
      const normalised = sanitiseDrop(drop, baseType, options);
      const qty = normalised.min === normalised.max ? String(normalised.min) : `${normalised.min}-${normalised.max}`;
      const chance = Math.round(normalised.chance * 100);
      const typeLabel = normalised.sourceType ? labelFor(normalised.sourceType, options) : '';
      const sourceText = normalised.sourceType ? `${typeLabel}${normalised.sourceId ? `:${normalised.sourceId}` : ''}` : '';
      return `${chance}% × ${qty}${sourceText ? `（${sourceText}）` : ''}`;
    }).join('；');
  }

  function makeSelect(opts, value) {
    const select = document.createElement('select');
    const fallbackValue = getBaseType(opts);
    let hasValue = false;
    opts.forEach(opt => {
      const option = document.createElement('option');
      option.value = opt.id;
      option.textContent = opt.label;
      if (opt.id === value) hasValue = true;
      select.appendChild(option);
    });
    if (value && !hasValue) {
      const extra = document.createElement('option');
      extra.value = value;
      extra.textContent = labelFor(value, opts);
      select.appendChild(extra);
    }
    select.value = hasValue ? value : fallbackValue;
    return select;
  }

  function createRow(item, idx, options) {
    const baseType = getBaseType(options);
    const drop = sanitiseDrop(item.drops[idx], baseType, options);
    item.drops[idx] = drop;
    const row = document.createElement('div'); row.className = 'drop-row'; row.dataset.idx = String(idx);
    const inChance = document.createElement('input'); inChance.type = 'number'; inChance.step = '0.01'; inChance.min = '0'; inChance.max = '1'; inChance.placeholder = '機率(0~1)'; inChance.value = String(drop.chance);
    const inMin = document.createElement('input'); inMin.type = 'number'; inMin.step = '1'; inMin.min = '0'; inMin.placeholder = '最少'; inMin.value = String(drop.min);
    const inMax = document.createElement('input'); inMax.type = 'number'; inMax.step = '1'; inMax.min = '0'; inMax.placeholder = '最多'; inMax.value = String(drop.max);
    const srcType = makeSelect(options, drop.sourceType || baseType);
    const srcId = document.createElement('input'); srcId.type = 'text'; srcId.placeholder = '來源 ID（例如 forest_wolf / wheat / iron_ore / oak）'; srcId.value = drop.sourceId || '';
    const del = document.createElement('button'); del.type = 'button'; del.className = 'danger'; del.textContent = '刪除';

    del.addEventListener('click', () => {
      const i = +row.dataset.idx;
      item.drops.splice(i, 1);
      render(item);
    });

    inChance.addEventListener('change', () => {
      const i = +row.dataset.idx;
      const next = sanitiseDrop({ ...item.drops[i], chance: inChance.value }, baseType, options);
      item.drops[i] = next;
      inChance.value = String(next.chance);
    });

    inMin.addEventListener('change', () => {
      const i = +row.dataset.idx;
      const next = sanitiseDrop({ ...item.drops[i], min: inMin.value }, baseType, options);
      item.drops[i] = next;
      inMin.value = String(next.min);
      inMax.value = String(next.max);
    });

    inMax.addEventListener('change', () => {
      const i = +row.dataset.idx;
      const next = sanitiseDrop({ ...item.drops[i], max: inMax.value }, baseType, options);
      item.drops[i] = next;
      inMin.value = String(next.min);
      inMax.value = String(next.max);
    });

    srcType.addEventListener('change', () => {
      const i = +row.dataset.idx;
      const next = sanitiseDrop({ ...item.drops[i], sourceType: srcType.value }, baseType, options);
      item.drops[i] = next;
      srcType.value = next.sourceType;
    });

    srcId.addEventListener('input', () => {
      const i = +row.dataset.idx;
      item.drops[i].sourceId = srcId.value;
    });

    srcId.addEventListener('blur', () => {
      const i = +row.dataset.idx;
      const next = sanitiseDrop({ ...item.drops[i], sourceId: srcId.value }, baseType, options);
      item.drops[i] = next;
      srcId.value = next.sourceId;
    });

    row.append(inChance, inMin, inMax, srcType, srcId, del);
    return row;
  }

  function render(item) {
    ensureDropsArray(item);
    const options = getSourceOptions();
    const normalised = normaliseDrops(item.drops, options);
    item.drops = normalised;
    UI.list.innerHTML = '';
    normalised.forEach((_, i) => UI.list.appendChild(createRow(item, i, options)));
  }

  function buildBlock() {
    const block = document.createElement('div'); block.className = 'field-group';
    block.innerHTML = `<style>.drop-row{display:grid;grid-template-columns:120px 90px 90px 140px 1fr 80px;gap:8px;align-items:center;padding:6px 8px;margin:6px 0;border:1px dashed #ddd;border-radius:8px}.drop-row input,.drop-row select{width:100%}.drop-row .danger{color:#b00}</style><h4 style="margin:12px 0 6px;">掉落</h4><div id="item-drops-list"></div><button type="button" id="btn-add-drop">＋新增掉落規則</button><small>機率 0–1；來源可選 動畫生物或其他分類（不含掉落物），ID 自訂。</small>`;
    block.querySelector('#btn-add-drop').addEventListener('click', () => {
      if (!window.ItemDrops.currentItem) return;
      ensureDropsArray(window.ItemDrops.currentItem);
      const options = getSourceOptions();
      const baseType = getBaseType(options);
      window.ItemDrops.currentItem.drops.push(sanitiseDrop({ chance: 1, min: 1, max: 1, sourceType: baseType, sourceId: '' }, baseType, options));
      render(window.ItemDrops.currentItem);
    });
    UI.block = block; UI.list = block.querySelector('#item-drops-list'); return block;
  }

  function mountBlock(mountEl) {
    if (!UI.block) {
      const block = buildBlock();
      (mountEl || document.body).appendChild(block);
    } else if (mountEl && UI.block.parentNode !== mountEl) {
      mountEl.appendChild(UI.block);
    }
  }

  window.ItemDrops.onOpen = function(item, mountEl) {
    window.ItemDrops.currentItem = item;
    mountBlock(mountEl);
    render(item);
  };

  window.ItemDrops.onCollect = function(fd) {
    const item = window.ItemDrops.currentItem;
    const drops = (item && Array.isArray(item.drops)) ? item.drops : [];
    const normalised = normaliseDrops(drops);
    try { if (typeof fd.delete === 'function') fd.delete('drops'); } catch (err) {}
    fd.append('drops', JSON.stringify(normalised));
  };

  window.ItemDrops.formatDrops = function(drops, sourceOptions) {
    return formatDrops(drops, sourceOptions);
  };

  window.ItemDrops.formatBrief = function(drops, sourceOptions) {
    return formatDrops(drops, sourceOptions);
  };

  window.ItemDrops.getSourceOptions = function() {
    return getSourceOptions();
  };

  window.ItemDrops.setSourceResolver = function(resolver) {
    sourceResolver = (typeof resolver === 'function') ? resolver : null;
    if (window.ItemDrops.currentItem && UI.block) {
      render(window.ItemDrops.currentItem);
    }
  };

  window.ItemDrops.registerLabelFallbacks = function(map) {
    mergeLabelFallbacks(map);
    if (window.ItemDrops.currentItem && UI.block) {
      render(window.ItemDrops.currentItem);
    }
  };

  window.ItemDrops.getLabelFallbacks = function() {
    return { ...labelFallbacks };
  };

  window.ItemDrops.sanitiseDrop = function(raw, baseType, optionsOverride) {
    return sanitiseDrop(raw, baseType, optionsOverride);
  };

  window.ItemDrops.normaliseDrops = function(drops, optionsOverride) {
    return normaliseDrops(drops, optionsOverride);
  };
})();
