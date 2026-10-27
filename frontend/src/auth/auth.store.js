import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { getMe } from '../api/auth.api.js'
import { configureApiAuth } from '../api/client.js'
import { queryClient } from '../app/queryClient.js'

let initializationPromise = null

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
        if (get().token !== token || (get().user?.id != null && user?.id != null && get().user.id !== user.id)) {
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
      clearSession: () => {
        // Also cancel pending queries before a different actor can sign in.
        queryClient.clear()
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
