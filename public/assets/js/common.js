/*
 * 前端通用工具
 * 说明：统一处理 token、本地存储、API 请求、WebSocket 通信
 */

const API_BASE = '/api';

function getToken() {
  return localStorage.getItem('mc_token') || '';
}

function setToken(token) {
  localStorage.setItem('mc_token', token || '');
}

function clearToken() {
  localStorage.removeItem('mc_token');
  localStorage.removeItem('mc_user');
}

function getUser() {
  try {
    return JSON.parse(localStorage.getItem('mc_user') || 'null');
  } catch (e) {
    return null;
  }
}

function setUser(user) {
  localStorage.setItem('mc_user', JSON.stringify(user || null));
}

async function api(path, { method = 'GET', body = null, form = null } = {}) {
  const headers = {};
  if (!form) {
    headers['Content-Type'] = 'application/json';
  }
  const token = getToken();
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const res = await fetch(`${API_BASE}/${path}`, {
    method,
    headers,
    body: form ? form : (body ? JSON.stringify(body) : null),
  });

  const data = await res.json().catch(() => ({ ok: false, message: '响应解析失败' }));
  if (!data.ok) {
    if (res.status === 401) {
      clearToken();
      if (!location.pathname.includes('auth.html')) {
        location.href = '/auth.html';
      }
    }
    throw new Error(data.message || '请求失败');
  }
  return data.data;
}

function toast(text) {
  alert(text);
}

function ensureLogin() {
  if (!getToken()) {
    location.href = '/auth.html';
    return false;
  }
  return true;
}

function wsConnect({ onEvent, onOnline } = {}) {
  const token = getToken();
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const wsPort = localStorage.getItem('mc_ws_port') || '2346';
  const wsHost = localStorage.getItem('mc_ws_host') || location.hostname;
  const ws = new WebSocket(`${proto}://${wsHost}:${wsPort}`);

  ws.onopen = () => {
    ws.send(JSON.stringify({ action: 'auth', token }));
    const chatId = Number(localStorage.getItem('mc_current_chat_id') || 0);
    if (chatId > 0) {
      ws.send(JSON.stringify({ action: 'subscribe', chat_id: chatId }));
    }
    setInterval(() => {
      if (ws.readyState === 1) {
        ws.send(JSON.stringify({ action: 'ping' }));
      }
    }, 20000);
  };

  ws.onmessage = (evt) => {
    const data = JSON.parse(evt.data || '{}');
    if (data.type === 'event' && onEvent) {
      onEvent(data.event);
    }
    if (data.type === 'online' && onOnline) {
      onOnline(data.online || 0);
    }
  };

  return ws;
}

/*
 * 页面加载时尝试应用后台自定义 CSS
 */
(async function applyCustomCss() {
  try {
    const res = await fetch('/api/public/settings');
    const json = await res.json();
    const cssFile = json?.data?.settings?.custom_css_file;
    if (cssFile) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = cssFile;
      document.head.appendChild(link);
    }
  } catch (_) {}
})();
