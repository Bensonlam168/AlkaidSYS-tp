export const createPinia = () => ({});
export const setActivePinia = () => {};
export const defineStore = (id: string, options: any) => {
  const state = typeof options.state === 'function' ? options.state() : {};
  const actions = options.actions || {};
  return () => ({ ...state, ...actions });
};
export const acceptHMRUpdate = () => () => {};
