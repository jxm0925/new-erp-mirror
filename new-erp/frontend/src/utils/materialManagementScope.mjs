export const materialScopes = [
  { value: 'factory', label: '工厂物料' },
  { value: 'office', label: '办公用品' }
]

export const materialScopeLabel = scope => scope === 'office' ? '办公用品' : scope === 'factory' ? '工厂物料' : '全部物料'
export const routeMaterialScope = route => {
  const scope = route?.query?.management_scope || route?.meta?.materialScope
  return ['factory', 'office'].includes(scope) ? scope : ''
}
export const materialRecordScope = item => item?.management_scope || (item?.item_type === 'office_consumable' ? 'office' : 'factory')
export const materialListPath = () => '/master/items'
export const materialCategoryPath = () => '/master/categories'

export const materialTypesForScope = scope => !scope
  ? [...materialTypesForScope('factory'), { value: 'office_consumable', label: '办公用品' }]
  : scope === 'office'
  ? [{ value: 'office_consumable', label: '办公用品' }, { value: 'service', label: '服务' }]
  : [
      { value: 'finished_product', label: '成品' },
      { value: 'semi_finished', label: '半成品' },
      { value: 'raw_material', label: '原材料' },
      { value: 'packaging', label: '包装物' },
      { value: 'service', label: '服务' }
    ]
