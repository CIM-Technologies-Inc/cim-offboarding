<div
  @click="$store.sidebar.setMobileOpen(false)"
  :class="$store.sidebar.isMobileOpen ? 'block xl:hidden' : 'hidden'"
  class="fixed z-50 h-screen w-full bg-gray-900/50"
></div>
