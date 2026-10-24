/** 開發用：看這個 Host 解析出來的 Site。正式環境回 404。 */
export default defineEventHandler((event) => {
  if (!import.meta.dev) {
    throw createError({ statusCode: 404 })
  }
  return {
    site: example.invalid ?? null,
    error: event.context.siteError ?? null,
  }
})
