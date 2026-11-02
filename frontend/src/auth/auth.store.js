import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { getMe } from '../api/auth.api.js'
import { configureApiAuth } from '../api/client.js'
import { queryClient } from '../app/queryClient.js'
import { useShopStore } from '../stores/shop.store.js'

let initializationPromise = null

const authorizationScope = (user) => JSON.stringify([
  user?.role_id ?? user?.role?.id ?? null,
  user?.role?.slug ?? null,
  (user?.role?.permissions ?? []).map((permission) => permission.slug ?? permission.id).sort(),
])

const initialSession = {
  token: null,
  user: null,
  isAuthenticated: false,
  isInitializing: true,
  requirePasswordChange: false,
}

export const useAuthStore = create(
  persist(
    (set, get) => ({
      ...initialSession,
      setSession: ({ token, user = null, requirePasswordChange = false }) => {
        if (get().user?.id != null && user?.id != null && get().user.id !== user.id) {
          useShopStore.getState().clearSelectedShop()
        }
        if (get().token !== token || (get().user?.id != null && user?.id != null && get().user.id !== user.id) || authorizationScope(get().user) !== authorizationScope(user)) {
          // Never reuse another actor's shop, Order or page options cache.
          queryClient.clear()
        }
        set({
          token,
          user,
          isAuthenticated: Boolean(token),
          requirePasswordChange,
        })
      },
      completeSession: async (result) => {
        if (!result.access_token) throw new Error('Missing access token')
        const requirePasswordChange = result.require_password_change === true
        // Keep the new token private until the full role/permissions have arrived.
        const user = requirePasswordChange ? result.user : await getMe({ token: result.access_token })
        get().setSession({ token: result.access_token, user, requirePasswordChange })
        return user
      },
      clearSession: () => {
        // Also cancel pending queries before a different actor can sign in.
        queryClient.clear()
        useShopStore.getState().clearSelectedShop()
        set({
          token: null,
          user: null,
          isAuthenticated: false,
          isInitializing: false,
          requirePasswordChange: false,
        })
      },
      setRequirePasswordChange: (required) => set({ requirePasswordChange: required }),
      initializeAuth: async () => {
        if (!get().isInitializing) return
        if (initializationPromise) return initializationPromise

        initializationPromise = (async () => {
          const token = get().token

          if (!token) {
            set({ isInitializing: false, isAuthenticated: false, user: null })
            return
          }

          try {
            const user = await getMe()
            set({ user, isAuthenticated: true, isInitializing: false })
          } catch {
            get().clearSession()
          }
        })()

        try {
          await initializationPromise
        } finally {
          initializationPromise = null
        }
      },
    }),
    {
      name: 'pancake-v2-auth',
      partialize: (state) => ({
        token: state.token,
        requirePasswordChange: state.requirePasswordChange,
      }),
    },
  ),
)

configureApiAuth({
  getToken: () => useAuthStore.getState().token,
  onUnauthorized: () => useAuthStore.getState().clearSession(),
})
