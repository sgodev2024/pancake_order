export const getUserPermissions = (user) => user?.role?.permissions ?? []

export const getPermissionSlugs = (user) =>
  getUserPermissions(user)
    .map((permission) => permission?.slug)
    .filter(Boolean)

export const isAdmin = (user) => user?.role?.slug === 'admin'

export const isDirector = (user) => user?.role?.slug === 'director'

export const canAccessReports = (user) => isAdmin(user) || isDirector(user)

const MANAGER_ROLE_SLUGS = ['manager', 'manager-sale', 'manager-cskh']

export const isManager = (user) => MANAGER_ROLE_SLUGS.includes(user?.role?.slug)

export const hasPermission = (user, permissionSlug) =>
  !permissionSlug || isAdmin(user) || getPermissionSlugs(user).includes(permissionSlug)

export const hasAnyPermission = (user, permissionSlugs = []) =>
  isAdmin(user) || permissionSlugs.some((slug) => hasPermission(user, slug))

export const canUsePos = (user) =>
  (['admin', 'staff-sale', 'manager-sale'].includes(user?.role?.slug)
    || getPermissionSlugs(user).includes('pos-create'))
  && ['list-product', 'list-customer'].every((slug) => getPermissionSlugs(user).includes(slug))

// POS staff receive product read access without catalog administration.
export const canManageProducts = (user) =>
  !['staff-sale', 'staff-cskh'].includes(user?.role?.slug) && hasPermission(user, 'list-product')

// Warehouse access is explicitly granted, including for admins; no new implicit privilege.
export const canViewWarehouse = (user) => getPermissionSlugs(user).includes('warehouse-view')
export const canPackOrders = (user) => canViewWarehouse(user) && getPermissionSlugs(user).includes('warehouse-pack')

export const filterMenuByPermissions = (items, user) =>
  items.reduce((visibleItems, item) => {
    if (item.posOnly && !canUsePos(user)) return visibleItems
    if (item.warehouseOnly && !canViewWarehouse(user)) return visibleItems
    if (item.adminOnly && !isAdmin(user)) return visibleItems
    if (item.reportAccess && !canAccessReports(user)) return visibleItems
    if (!hasPermission(user, item.permission)) return visibleItems

    const children = item.children ? filterMenuByPermissions(item.children, user) : undefined
    if (item.children && children.length === 0) return visibleItems

    visibleItems.push({ ...item, children })
    return visibleItems
  }, [])


export const canDeletePosOrders = (user) =>
  ['admin', 'staff-cskh', 'manager-cskh'].includes(user?.role?.slug)
