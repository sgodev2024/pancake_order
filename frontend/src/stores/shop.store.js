import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export const useShopStore = create(
  persist(
    (set) => ({
      selectedShopId: null,
      setSelectedShopId: (shopId) => set({ selectedShopId: shopId ? String(shopId) : null }),
      clearSelectedShop: () => set({ selectedShopId: null }),
    }),
    {
      name: 'pancake-v2-shop-scope',
      partialize: (state) => ({ selectedShopId: state.selectedShopId }),
    },
  ),
)
