// Only loopback development APIs are routed through Vite; deployed API URLs stay intact.
export const developmentApi = (configuredUrl) => {
  if (!configuredUrl) return null
  try {
    const url = new URL(configuredUrl)
    if (!['http:', 'https:'].includes(url.protocol) || !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)) return null
    return { baseURL: url.pathname.replace(/\/$/, ''), target: url.origin }
  } catch {
    return null
  }
}
