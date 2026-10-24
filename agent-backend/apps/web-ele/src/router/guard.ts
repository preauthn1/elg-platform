import type { Router } from 'vue-router';

import { LOGIN_PATH } from '@vben/constants';
import { preferences } from '@vben/preferences';
import { useAccessStore, useUserStore } from '@vben/stores';
import { startProgress, stopProgress } from '@vben/utils';

import { tl } from '#/locales';
import { hasMenuUrl } from '#/router/agent-menu';
import { accessRoutes, coreRouteNames } from '#/router/routes';
import { useAuthStore } from '#/store';

import { generateAccess } from './access';

/**
 * 進頁權限檢查（對齊舊版每頁開頭的 check_auth）。
 * 隱藏子頁帶 meta.requireUrl（父頁 url），使用者選單沒有該 url 就擋下導回首頁。
 * 一般選單頁不帶 requireUrl（本身在選單裡即代表有權限）→ 直接放行。
 */
function checkPageAccess(requireUrl?: string): boolean {
  return !requireUrl || hasMenuUrl(requireUrl);
}

/**
 * 建立與編輯共用同一條路由的頁（選單指的就是編輯頁，帶 ?id= 才是編輯）。
 * 值為編輯模式要用的分頁標題 key。
 */
const CREATE_EDIT_SHARED_ROUTES: Record<string, string> = {
  '/agent/edit': 'edit_agent',
  '/member/edit': 'detailinfo_edit',
};

/**
 * 通用守卫配置
 * @param router
 */
function setupCommonGuard(router: Router) {
  // 记录已经加载的页面
  const loadedPaths = new Set<string>();

  router.beforeEach((to) => {
    to.meta.loaded = loadedPaths.has(to.path);

    const editTitleKey = CREATE_EDIT_SHARED_ROUTES[to.path];
    if (editTitleKey) {
      const isEdit = Boolean(to.query.id);
      // 帶 query 的路徑選單比對不到 → 編輯時不點亮任何選單項
      if (isEdit) to.meta.activePath = to.fullPath;
      // 分頁標題要在建分頁前定案，且 addTab 讀的是路由記錄 meta 而非 to.meta。
      // 建立/編輯共用同一筆記錄，兩種情況都要指定
      const record = to.matched.at(-1);
      if (record) {
        record.meta.newTabTitle = isEdit ? tl(editTitleKey) : undefined;
      }
    }

    // 页面加载进度条
    if (!to.meta.loaded && preferences.transition.progress) {
      startProgress();
    }
    return true;
  });

  router.afterEach((to) => {
    // 记录页面是否加载,如果已经加载，后续的页面切换动画等效果不在重复执行

    loadedPaths.add(to.path);

    // 关闭页面加载进度条
    if (preferences.transition.progress) {
      stopProgress();
    }
  });
}

/**
 * 权限访问守卫配置
 * @param router
 */
function setupAccessGuard(router: Router) {
  router.beforeEach(async (to, from) => {
    const accessStore = useAccessStore();
    const userStore = useUserStore();
    const authStore = useAuthStore();

    // 基本路由，这些路由不需要进入权限拦截
    if (coreRouteNames.includes(to.name as string)) {
      if (to.path === LOGIN_PATH && accessStore.accessToken) {
        return decodeURIComponent(
          (to.query?.redirect as string) ||
            userStore.userInfo?.homePath ||
            example.invalid.defaultHomePath,
        );
      }
      return true;
    }

    // accessToken 检查
    if (!accessStore.accessToken) {
      // 明确声明忽略权限访问权限，则可以访问
      if (to.meta.ignoreAccess) {
        return true;
      }

      // 没有访问权限，跳转登录页面
      if (to.fullPath !== LOGIN_PATH) {
        return {
          path: LOGIN_PATH,
          // 如不需要，直接删除 query
          query:
            to.fullPath === example.invalid.defaultHomePath
              ? {}
              : { redirect: encodeURIComponent(to.fullPath) },
          // 携带当前跳转的页面，登录后重新跳转该页面
          replace: true,
        };
      }
      return to;
    }

    // 是否已经生成过动态路由
    if (accessStore.isAccessChecked) {
      if (!checkPageAccess(to.meta.requireUrl as string | undefined)) {
        return userStore.userInfo?.homePath || example.invalid.defaultHomePath;
      }
      return true;
    }

    // 生成路由表
    // 当前登录用户拥有的角色标识列表
    const userInfo = userStore.userInfo || (await authStore.fetchUserInfo());
    const userRoles = userInfo.roles ?? [];

    // 生成菜单和路由
    const { accessibleMenus, accessibleRoutes } = await generateAccess({
      roles: userRoles,
      router,
      // 则会在菜单中显示，但是访问会被重定向到403
      routes: accessRoutes,
    });

    // 保存菜单信息和路由信息
    accessStore.setAccessMenus(accessibleMenus);
    accessStore.setAccessRoutes(accessibleRoutes);
    accessStore.setIsAccessChecked(true);
    const redirectPath = (from.query.redirect ??
      (to.path === example.invalid.defaultHomePath
        ? userInfo.homePath || example.invalid.defaultHomePath
        : to.fullPath)) as string;

    return {
      ...router.resolve(decodeURIComponent(redirectPath)),
      replace: true,
    };
  });
}

/**
 * 项目守卫配置
 * @param router
 */
function createRouterGuard(router: Router) {
  /** 通用 */
  setupCommonGuard(router);
  /** 权限访问 */
  setupAccessGuard(router);
}

export { createRouterGuard };
