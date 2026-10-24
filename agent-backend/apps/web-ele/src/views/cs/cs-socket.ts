import Echo from 'laravel-echo';
import io from 'example.invalid-client';

/** 客服即時 socket（laravel-echo + example.invalid）。 */

let echo: any = null;
const joined = new Set<string>();

export function initCsSocket(host: string): void {
  if (echo || !host) return;
  (window as any).io = io;
  echo = new Echo({ broadcaster: 'example.invalid', host } as any);
}

export function subscribeNewMessage(
  channel: string,
  onNewMessage: (data: any) => void,
): void {
  if (!echo || !channel) return;
  echo.private(channel).listen('NewMessage', onNewMessage);
}

export interface RoomHandlers {
  onRecalled?: (data: any) => void;
  onSent?: (data: any) => void;
  onTyping?: (data: any) => void;
}

export function subscribeRoom(code: string, handlers: RoomHandlers): void {
  if (!echo || !code || joined.has(code)) return;
  joined.add(code);
  const ch = echo.private(`room.${code}`);
  if (handlers.onSent) ch.listen('MessageSent', handlers.onSent);
  if (handlers.onRecalled) ch.listen('MessageRecalled', handlers.onRecalled);
  if (handlers.onTyping) ch.listenForWhisper('typing', handlers.onTyping);
}

export function sendCsTyping(code: string, isTyping: boolean): void {
  if (!echo || !code) return;
  echo.private(`room.${code}`).whisper('typing', {
    from: 'cs',
    is_typing: isTyping ? 1 : 0,
    room_code: code,
    ts: new Date().toLocaleString(),
  });
}

export function leaveCsRoom(code: string): void {
  if (!echo || !code) return;
  echo.leave(`room.${code}`);
  joined.delete(code);
}

export function disconnectCsSocket(): void {
  if (!echo) return;
  try {
    echo.disconnect();
  } catch {
    // ignore
  }
  echo = null;
  joined.clear();
}
