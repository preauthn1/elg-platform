import { tl } from '#/locales';

/** 伺服器收的上限（解碼後） */
export const IMAGE_MAX_BYTES = 500 * 1024;

const ALLOWED_EXTENSIONS = new Set([
  'gif',
  'heic',
  'heif',
  'jpeg',
  'jpg',
  'png',
  'webp',
]);

const ALLOWED_TYPES = new Set([
  'image/gif',
  'image/heic',
  'image/heif',
  'image/jpeg',
  'image/png',
  'image/webp',
]);

type ImageUploadTextKey =
  | 'image_upload_hint'
  | 'image_upload_invalid_format'
  | 'image_upload_too_large';

/** 圖片上傳相關文字，代入 :formats（允許格式）與 :max（大小上限） */
export function imageUploadText(key: ImageUploadTextKey) {
  return tl(key)
    .replaceAll(':formats', 'jpg/png/gif/webp/heic')
    .replaceAll(':max', `${IMAGE_MAX_BYTES / 1024}KB`);
}

/** 檔案選擇器的 accept：只列允許的格式，瀏覽器會先幫忙過濾 */
export const IMAGE_ACCEPT = [
  ...[...ALLOWED_EXTENSIONS].map((ext) => `.${ext}`),
  ...ALLOWED_TYPES,
].join(',');

/** 格式或大小不符，message 已是給使用者看的文字 */
export class ImageUploadError extends Error {}

function extensionOf(name: string) {
  const dot = name.lastIndexOf('.');
  return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
}

function isHeif(file: File) {
  const ext = extensionOf(file.name);
  return (
    ext === 'heic' ||
    ext === 'heif' ||
    file.type === 'image/heic' ||
    file.type === 'image/heif'
  );
}

/** 副檔名與 MIME 都要在白名單內（MIME 空白時只看副檔名，部分瀏覽器對 heic 不給 type） */
export function isAllowedImage(file: File) {
  if (!ALLOWED_EXTENSIONS.has(extensionOf(file.name))) return false;
  return !file.type || ALLOWED_TYPES.has(file.type);
}

function readAsDataUrl(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.addEventListener('load', () => resolve(String(reader.result)));
    reader.addEventListener('error', () => reject(reader.error));
    reader.readAsDataURL(blob);
  });
}

function loadImage(file: File): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.addEventListener('load', () => {
      URL.revokeObjectURL(url);
      resolve(img);
    });
    img.addEventListener('error', () => {
      URL.revokeObjectURL(url);
      reject(
        new ImageUploadError(imageUploadText('image_upload_invalid_format')),
      );
    });
    img.src = url;
  });
}

function canvasToBlob(
  canvas: HTMLCanvasElement,
  type: string,
  quality: number,
): Promise<Blob | null> {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * 壓縮到上限內：先降品質，還不夠再縮尺寸。
 * 輸出 webp 保留透明；瀏覽器不支援 webp 時改 jpeg 並鋪白底。
 */
async function compress(file: File): Promise<Blob> {
  const img = await loadImage(file);
  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d');
  if (!ctx)
    throw new ImageUploadError(imageUploadText('image_upload_too_large'));

  canvas.width = 1;
  canvas.height = 1;
  const probe = await canvasToBlob(canvas, 'image/webp', 0.9);
  const type = probe?.type === 'image/webp' ? 'image/webp' : 'image/jpeg';

  let width = img.naturalWidth;
  let height = img.naturalHeight;

  for (let round = 0; round < 12; round++) {
    canvas.width = Math.max(1, Math.round(width));
    canvas.height = Math.max(1, Math.round(height));
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (type === 'image/jpeg') {
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
    }
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

    for (const quality of [0.9, 0.8, 0.7, 0.6, 0.5]) {
      const blob = await canvasToBlob(canvas, type, quality);
      if (blob && blob.size <= IMAGE_MAX_BYTES) return blob;
    }

    width *= 0.8;
    height *= 0.8;
  }

  throw new ImageUploadError(imageUploadText('image_upload_too_large'));
}

/**
 * 上傳前處理圖片，回傳 data URI。
 *
 * 格式不在白名單直接擋；超過 500KB 自動壓縮。heic／heif 瀏覽器多半無法解碼，
 * 超過上限時無法壓縮，直接提示大小限制。失敗時丟 ImageUploadError。
 */
export async function readImageAsDataUrl(file: File): Promise<string> {
  if (!isAllowedImage(file)) {
    throw new ImageUploadError(imageUploadText('image_upload_invalid_format'));
  }
  if (file.size <= IMAGE_MAX_BYTES) return readAsDataUrl(file);
  if (isHeif(file))
    throw new ImageUploadError(imageUploadText('image_upload_too_large'));
  return readAsDataUrl(await compress(file));
}
