import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export const useUiStore = create(
  persist(
    (set) => ({
      sidebarCollapsed: false,
      mobileSidebarOpen: false,
      toggleSidebar: () => set((state) => ({ sidebarCollapsed: !state.sidebarCollapsed })),
      setMobileSidebarOpen: (open) => set({ mobileSidebarOpen: open }),
    }),
    {
      name: 'pancake-v2-ui',
      partialize: (state) => ({ sidebarCollapsed: state.sidebarCollapsed }),
    },
  ),
)
