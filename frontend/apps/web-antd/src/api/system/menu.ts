import type { Recordable } from '@vben/types';

import { requestClient } from '#/api/request';

export namespace SystemMenuApi {
  /** 徽标颜色集合 */
  export const BadgeVariants = [
    'default',
    'destructive',
    'primary',
    'success',
    'warning',
  ] as const;

  /** 徽标类型集合 */
  export const BadgeTypes = ['dot', 'normal'] as const;

  /** 菜单类型集合 */
  export const MenuTypes = [
    'catalog',
    'menu',
    'embedded',
    'link',
    'button',
  ] as const;

  /** 菜单类型 */
  export type MenuType = (typeof MenuTypes)[number];

  /** 菜单状态类型 */
  export type MenuStatus = 0 | 1;

  /** 菜单元数据 */
  export interface MenuMeta {
    /** 激活时显示的图标 */
    activeIcon?: string;
    /** 作为路由时，需要激活的菜单的Path */
    activePath?: string;
    /** 固定在标签栏 */
    affixTab?: boolean;
    /** 在标签栏固定的顺序 */
    affixTabOrder?: number;
    /** 徽标内容(当徽标类型为normal时有效) */
    badge?: string;
    /** 徽标类型 */
    badgeType?: (typeof BadgeTypes)[number];
    /** 徽标颜色 */
    badgeVariants?: (typeof BadgeVariants)[number];
    /** 在菜单中隐藏下级 */
    hideChildrenInMenu?: boolean;
    /** 在面包屑中隐藏 */
    hideInBreadcrumb?: boolean;
    /** 在菜单中隐藏 */
    hideInMenu?: boolean;
    /** 在标签栏中隐藏 */
    hideInTab?: boolean;
    /** 菜单图标 */
    icon?: string;
    /** 内嵌Iframe的URL */
    iframeSrc?: string;
    /** 是否缓存页面 */
    keepAlive?: boolean;
    /** 外链页面的URL */
    link?: string;
    /** 同一个路由最大打开的标签数 */
    maxNumOfOpenTab?: number;
    /** 无需基础布局 */
    noBasicLayout?: boolean;
    /** 是否在新窗口打开 */
    openInNewWindow?: boolean;
    /** 菜单排序 */
    order?: number;
    /** 额外的路由参数 */
    query?: Recordable<any>;
    /** 菜单标题 */
    title?: string;
  }

  /** 系统菜单 */
  export interface SystemMenu {
    [key: string]: any;
    /** 菜单ID */
    id: number;
    /** 菜单名称 */
    name: string;
    /** 父级ID */
    pid?: number;
    /** 路由路径 */
    path?: string;
    /** 组件路径 */
    component?: string;
    /** 重定向 */
    redirect?: string;
    /** 菜单类型 */
    type: MenuType;
    /** 后端权限标识 */
    authCode?: string;
    /** 菜单元数据 */
    meta?: MenuMeta;
    /** 状态 */
    status: MenuStatus;
    /** 排序 */
    sort?: number;
    /** 子级 */
    children?: SystemMenu[];
    /** 链接地址（用于表单） */
    linkSrc?: string;
  }

  /**
   * Create menu request | 创建菜单请求
   */
  export interface CreateMenuRequest {
    name: string;
    pid?: number;
    path?: string;
    component?: string;
    redirect?: string;
    type: MenuType;
    authCode?: string;
    meta?: MenuMeta;
    status?: MenuStatus;
    sort?: number;
  }

  /**
   * Update menu request | 更新菜单请求
   */
  export interface UpdateMenuRequest {
    name?: string;
    pid?: number;
    path?: string;
    component?: string;
    redirect?: string;
    type?: MenuType;
    authCode?: string;
    meta?: MenuMeta;
    status?: MenuStatus;
    sort?: number;
  }
}

/**
 * Get menu tree list | 获取菜单树列表
 */
async function getMenuList() {
  return requestClient.get<SystemMenuApi.SystemMenu[]>('/v1/admin/menus');
}

/**
 * Check if menu name exists | 检查菜单名称是否存在
 * @param name Menu name
 * @param id Exclude this menu ID when checking
 */
async function isMenuNameExists(name: string, id?: number) {
  return requestClient.get<boolean>('/v1/admin/menus/name-exists', {
    params: { id, name },
  });
}

/**
 * Check if menu path exists | 检查菜单路径是否存在
 * @param path Menu path
 * @param id Exclude this menu ID when checking
 */
async function isMenuPathExists(path: string, id?: number) {
  return requestClient.get<boolean>('/v1/admin/menus/path-exists', {
    params: { id, path },
  });
}

/**
 * Create menu | 创建菜单
 * @param data Menu data
 */
async function createMenu(
  data: Omit<SystemMenuApi.SystemMenu, 'children' | 'id'>,
) {
  return requestClient.post<{ id: number }>('/v1/admin/menus', data);
}

/**
 * Update menu | 更新菜单
 * @param id Menu ID
 * @param data Menu data
 */
async function updateMenu(
  id: number,
  data: Omit<SystemMenuApi.SystemMenu, 'children' | 'id'>,
) {
  return requestClient.put(`/v1/admin/menus/${id}`, data);
}

/**
 * Delete menu | 删除菜单
 * @param id Menu ID
 */
async function deleteMenu(id: number) {
  return requestClient.delete(`/v1/admin/menus/${id}`);
}

export {
  createMenu,
  deleteMenu,
  getMenuList,
  isMenuNameExists,
  isMenuPathExists,
  updateMenu,
};
