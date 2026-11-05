import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { getMe } from '../api/auth.api.js'
import { configureApiAuth } from '../api/client.js'
import { queryClient } from '../app/queryClient.js'
import { useShopStore } from '../stores/shop.store.js'

let initializationPromise = null
let sessionGeneration = 0

const supersededSession = () => Object.assign(new Error('Authentication attempt was superseded.'), { code: 'AUTH_SESSION_SUPERSEDED' })
export const isSessionSuperseded = (error) => error?.code === 'AUTH_SESSION_SUPERSEDED'

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
      beginSessionAttempt: () => ++sessionGeneration,
      isCurrentSessionAttempt: (attempt) => attempt === sessionGeneration,
      setSession: ({ token, user = null, requirePasswordChange = false }) => {
        sessionGeneration++
        initializationPromise = null
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
          isInitializing: false,
          requirePasswordChange,
        })
      },
      completeSession: async (result, { attempt = get().beginSessionAttempt() } = {}) => {
        if (attempt !== sessionGeneration) throw supersededSession()
        if (!result.access_token) throw new Error('Missing access token')
        const requirePasswordChange = result.require_password_change === true
        // Keep the new token private until the full role/permissions have arrived.
        let user
        try {
          user = requirePasswordChange ? result.user : await getMe({ token: result.access_token })
        } catch (error) {
          if (attempt !== sessionGeneration) throw supersededSession()
          throw error
        }
        if (attempt !== sessionGeneration) throw supersededSession()
        get().setSession({ token: result.access_token, user, requirePasswordChange })
        return user
      },
      clearSession: () => {
        sessionGeneration++
        initializationPromise = null
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

        const generation = sessionGeneration
        const pending = (async () => {
          const token = get().token

          if (!token) {
            set({ isInitializing: false, isAuthenticated: false, user: null })
            return
          }

          try {
            const user = await getMe({ token })
            if (generation !== sessionGeneration || get().token !== token) return
            get().setSession({ token, user, requirePasswordChange: get().requirePasswordChange })
          } catch {
            if (generation === sessionGeneration && get().token === token) get().clearSession()
          }
        })()
        initializationPromise = pending

        try {
          await pending
        } finally {
          if (initializationPromise === pending) initializationPromise = null
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
