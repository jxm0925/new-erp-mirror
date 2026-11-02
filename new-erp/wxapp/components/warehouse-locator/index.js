const warehouse = require('../../services/warehouse');
const page = require('../../utils/warehouse-page');
Component({
  properties: { open: Boolean, value: { type: Object, value: {} }, locationPurpose: { type: String, value: '' }, locationPurposes: { type: Array, value: [] }, allowWarehouseOnly: Boolean, warehouseId: Number, warehouseName: String },
  data: { warehouses: [], locations: [], warehousePage: 1, locationPage: 1, warehouseLast: 1, locationLast: 1,
    warehouseTotal: 0, locationTotal: 0, warehouseKeyword: '', locationKeyword: '', selected: {}, error: '', warehouseLoading: false, locationLoading: false },
  observers: { open(value) { if (value) this.reset(); else { this.warehouseSequence = (this.warehouseSequence || 0) + 1; this.locationSequence = (this.locationSequence || 0) + 1; } } },
  methods: {
    reset() {
      this.setData({ warehouses: [], locations: [], warehousePage: 1, locationPage: 1, warehouseKeyword: '', locationKeyword: '', selected: Object.assign({}, this.properties.value || {}), error: '' });
      if (this.properties.warehouseId) this.setData({ selected: Object.assign({}, this.data.selected, { warehouse_id: this.properties.warehouseId, warehouse_name: this.properties.warehouseName }) });
      this.loadWarehouses(); if (this.data.selected.warehouse_id) this.loadLocations();
    },
    loadWarehouses() {
      const sequence = this.warehouseSequence = (this.warehouseSequence || 0) + 1;
      this.setData({ warehouseLoading: true, error: '' });
      return warehouse.locators({ mode: 'warehouse', id: this.properties.warehouseId || undefined, keyword: this.data.warehouseKeyword, page: this.data.warehousePage, per_page: 10 }).then(response => {
        if (sequence !== this.warehouseSequence) return;
        const result = page.rows(response); this.setData({ warehouses: result.rows, warehousePage: result.page, warehouseLast: result.lastPage, warehouseTotal: result.total, warehouseLoading: false });
      }).catch(error => { if (sequence === this.warehouseSequence) this.setData({ warehouseLoading: false, error: page.errorText(error) }); });
    },
    loadLocations() {
      const sequence = this.locationSequence = (this.locationSequence || 0) + 1;
      this.setData({ locationLoading: true, error: '' });
      return warehouse.locators({ mode: 'location', warehouse_id: this.data.selected.warehouse_id, location_purposes: this.properties.locationPurposes, location_purpose: this.properties.locationPurposes.length ? undefined : this.properties.locationPurpose || undefined, keyword: this.data.locationKeyword, page: this.data.locationPage, per_page: 10 }).then(response => {
        if (sequence !== this.locationSequence) return;
        const result = page.rows(response); this.setData({ locations: result.rows.map(row => Object.assign({}, row, { areaName: ({ raw: '原料区', finished: '成品区' })[row.area] || row.area || '' })),
          locationPage: result.page, locationLast: result.lastPage, locationTotal: result.total, locationLoading: false });
      }).catch(error => { if (sequence === this.locationSequence) this.setData({ locationLoading: false, error: page.errorText(error) }); });
    },
    input(event) { this.setData({ [event.currentTarget.dataset.field]: event.detail.value }); },
    searchWarehouses() { this.setData({ warehousePage: 1 }); this.loadWarehouses(); },
    searchLocations() { if (this.data.selected.warehouse_id) { this.setData({ locationPage: 1 }); this.loadLocations(); } },
    async scanLocation(event) {
      const resolved = event.detail.result;
      if (!this.properties.open || resolved.type !== 'location') return;
      if (this.properties.warehouseId && Number(resolved.warehouse_id) !== Number(this.properties.warehouseId)) return this.setData({ error: '请在原目标仓库内选择接收库位' });
      const sequence = this.locationSequence = (this.locationSequence || 0) + 1;
      this.setData({ locationLoading: true, error: '' });
      try {
        const response = await warehouse.locators({ mode: 'location', warehouse_id: resolved.warehouse_id, id: resolved.location_id,
          location_purposes: this.properties.locationPurposes, location_purpose: this.properties.locationPurposes.length ? undefined : this.properties.locationPurpose || undefined, page: 1, per_page: 10 });
        if (sequence !== this.locationSequence || !this.properties.open) return;
        const row = page.rows(response).rows.find(item => Number(item.id) === Number(resolved.location_id));
        if (!row) throw new Error('该库位不能用于当前操作，请重新选择');
        const selected = { warehouse_id: resolved.warehouse_id, warehouse_name: resolved.warehouse_name,
          location_id: row.id, location_name: row.name, location_code: row.code };
        this.setData({ selected, locationKeyword: row.code });
        this.triggerEvent('confirm', selected);
      } catch (error) {
        if (sequence === this.locationSequence && this.properties.open) this.setData({ error: page.errorText(error) });
      } finally {
        if (sequence === this.locationSequence) this.setData({ locationLoading: false });
      }
    },
    chooseWarehouse(event) {
      if (this.properties.warehouseId && Number(event.currentTarget.dataset.id) !== Number(this.properties.warehouseId)) return;
      const row = this.data.warehouses.find(row => Number(row.id) === Number(event.currentTarget.dataset.id));
      if (!row || Number(row.id) === Number(this.data.selected.warehouse_id)) return;
      this.setData({ selected: { warehouse_id: row.id, warehouse_name: row.name }, locations: [], locationPage: 1, locationKeyword: '', locationTotal: 0 }); this.loadLocations();
    },
    chooseLocation(event) {
      const row = this.data.locations.find(row => Number(row.id) === Number(event.currentTarget.dataset.id));
      if (row && Number(row.warehouse_id) === Number(this.data.selected.warehouse_id)) this.setData({ selected: Object.assign({}, this.data.selected, { location_id: row.id, location_name: row.name, location_code: row.code }) });
    },
    turnPage(event) {
      const { pane, delta } = event.currentTarget.dataset; const field = `${pane}Page`; const last = `${pane}Last`;
      const target = this.data[field] + Number(delta); if (target < 1 || target > this.data[last] || this.data[`${pane}Loading`]) return;
      this.setData({ [field]: target }); pane === 'warehouse' ? this.loadWarehouses() : this.loadLocations();
    },
    confirm() { if (this.data.selected.warehouse_id && (this.properties.allowWarehouseOnly || this.data.selected.location_id)) this.triggerEvent('confirm', Object.assign({}, this.data.selected)); },
    cancel() { this.triggerEvent('cancel'); }, stop() {},
  },
});
