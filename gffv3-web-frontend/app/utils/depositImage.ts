import { RECEIPT_IMAGE_JPEG_QUALITY, RECEIPT_IMAGE_MAX_WIDTH } from '#shared/utils/deposit'

/** 讀成 data URL（只在瀏覽器用）。 */
export function readReceiptFileAsDataUrl(file: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '')
    reader.onerror = () => reject(reader.error ?? new Error('read failed'))
    reader.readAsDataURL(file)
  })
}

/** 上傳前縮到最寬 1200（等比）並重編成 jpeg 0.7，控制 payload 大小。只在瀏覽器用。 */
export function downscaleReceiptImage(
  dataUrl: string,
  maxWidth = RECEIPT_IMAGE_MAX_WIDTH,
  quality = RECEIPT_IMAGE_JPEG_QUALITY,
): Promise<string> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => {
      const scale = img.width > maxWidth ? maxWidth / img.width : 1
      const canvas = document.createElement('canvas')
      canvas.width = Math.max(1, Math.round(img.width * scale))
      canvas.height = Math.max(1, Math.round(img.height * scale))
      const ctx = canvas.getContext('2d')
      if (!ctx) {
        reject(new Error('canvas unavailable'))
        return
      }
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
      resolve(canvas.toDataURL('image/jpeg', quality))
    }
    img.onerror = () => reject(new Error('image decode failed'))
    img.src = dataUrl
  })
}
