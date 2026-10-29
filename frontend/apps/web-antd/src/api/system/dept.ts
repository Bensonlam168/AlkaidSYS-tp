import { requestClient } from '#/api/request';

export namespace SystemDeptApi {
  /**
   * Department status type | 部门状态类型
   */
  export type DeptStatus = 0 | 1;

  /**
   * Department interface | 部门接口
   */
  export interface SystemDept {
    [key: string]: any;
    /** 部门ID */
    id: number;
    /** 部门名称 */
    name: string;
    /** 父部门ID */
    pid?: number;
    /** 状态：0=禁用, 1=启用 */
    status: DeptStatus;
    /** 排序 */
    sort?: number;
    /** 备注 */
    remark?: string;
    /** 子部门 */
    children?: SystemDept[];
    /** 创建时间 */
    created_at?: string;
    /** 更新时间 */
    updated_at?: string;
  }

  /**
   * Create department request | 创建部门请求
   */
  export interface CreateDeptRequest {
    name: string;
    pid?: number;
    status?: DeptStatus;
    sort?: number;
    remark?: string;
  }

  /**
   * Update department request | 更新部门请求
   */
  export interface UpdateDeptRequest {
    name?: string;
    pid?: number;
    status?: DeptStatus;
    sort?: number;
    remark?: string;
  }
}

/**
 * Get department tree list | 获取部门树列表
 */
async function getDeptList() {
  return requestClient.get<SystemDeptApi.SystemDept[]>('/v1/admin/depts');
}

/**
 * Get single department | 获取单个部门
 * @param id Department ID
 */
async function getDept(id: number) {
  return requestClient.get<SystemDeptApi.SystemDept>(`/v1/admin/depts/${id}`);
}

/**
 * Create department | 创建部门
 * @param data Department data
 */
async function createDept(
  data: Omit<SystemDeptApi.SystemDept, 'children' | 'id'>,
) {
  return requestClient.post<{ id: number }>('/v1/admin/depts', data);
}

/**
 * Update department | 更新部门
 * @param id Department ID
 * @param data Department data
 */
async function updateDept(
  id: number,
  data: Omit<SystemDeptApi.SystemDept, 'children' | 'id'>,
) {
  return requestClient.put(`/v1/admin/depts/${id}`, data);
}

/**
 * Delete department | 删除部门
 * @param id Department ID
 */
async function deleteDept(id: number) {
  return requestClient.delete(`/v1/admin/depts/${id}`);
}

export { createDept, deleteDept, getDept, getDeptList, updateDept };
