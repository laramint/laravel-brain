declare global {
  interface Window {
    __BRAIN_BASE__?: string
  }
}

export const BASE: string = window.__BRAIN_BASE__ ?? import.meta.env.BASE_URL
